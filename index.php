<?php

/*
 * Push Notifications addon for Bear Framework
 * https://github.com/ivopetkov/push-notifications-bearframework-addon
 * Copyright (c) Ivo Petkov
 * Free to use under the MIT license.
 */

use BearFramework\App;

$app = App::get();
$context = $app->context->get(__FILE__);

$context->classes
        ->add('IvoPetkov\BearFrameworkAddons\PushNotifications', 'classes/PushNotifications.php')
        ->add('IvoPetkov\BearFrameworkAddons\PushNotifications\PushNotification', 'classes/PushNotifications/PushNotification.php');

$context->assets
        ->addDir('assets/');

$app->shortcuts
        ->add('pushNotifications', function() {
            return new IvoPetkov\BearFrameworkAddons\PushNotifications();
        });

$app->routes
        ->add('/ivopetkov-push-notifications-data', function() use ($app) {
            $endpoint = $app->request->query->getValue('endpoint');
            $result = [];
            if (strlen($endpoint) > 0) {
                $result = $app->pushNotifications->getPendingEndpointData($endpoint);
            }
            return new App\Response\JSON(json_encode($result));
        })
        ->add('/ivopetkov-push-notifications-manifest.json', function() use ($app) {
            $options = $app->addons->get('ivopetkov/push-notifications-bearframework-addon')->options;
            return new App\Response\JSON(json_encode([
                        'gcm_sender_id' => (isset($options['googleCloudMessagingSenderID']) ? $options['googleCloudMessagingSenderID'] : ''),
                        'gcm_user_visible_only' => true
            ]));
        })
        ->add('/ivopetkov-push-notifications-service-worker.js', function() use ($app) {
            $response = new App\Response('self.addEventListener("push", function (event) {
    event.waitUntil(
        self.registration.pushManager.getSubscription().then(
            function (subscription) {
                return fetch("' . $app->urls->get('/ivopetkov-push-notifications-data') . '?endpoint=" + encodeURIComponent(subscription.endpoint)).then(function (response) {
                    if (response.status === 200) {
                        return response.json().then(function (data) {
                            var promises = [];
                            var notificationsCount = data.length;
                            for(var i = 0; i < notificationsCount; i++){
                                var notificationData = data[i];
                                var options = {};
                                if(typeof notificationData.body !== "undefined" && notificationData.body !== null){
                                    var body = notificationData.body.toString();
                                    if(body.length > 0){
                                        options["body"] = body;
                                    }
                                }
                                if(typeof notificationData.icon !== "undefined" && notificationData.icon !== null){
                                    var icon = notificationData.icon.toString();
                                    if(icon.length > 0){
                                        options["icon"] = icon;
                                    }
                                }
                                if(typeof notificationData.badge !== "undefined" && notificationData.badge !== null){
                                    var badge = notificationData.badge.toString();
                                    if(badge.length > 0){
                                        options["badge"] = badge;
                                    }
                                }
                                if(typeof notificationData.tag !== "undefined" && notificationData.tag !== null){
                                    var tag = notificationData.tag.toString();
                                    if(tag.length > 0){
                                        options["tag"] = tag;
                                    }
                                }
                                if(typeof notificationData.requireInteraction !== "undefined"){
                                    if(notificationData.requireInteraction === true){
                                        options["requireInteraction"] = true;
                                    }
                                }
                                options["data"] = notificationData;
                                var promise = self.registration.showNotification(notificationData.title, options);
                            }
                            promises.push(promise);
                            return Promise.all(promises);
                        });
                    }
                })
            })
        );
});

self.addEventListener("notificationclick", function (event) {
    event.notification.close();
    var notificationData = event.notification.data;
    if (typeof notificationData.clickUrl !== "undefined" && notificationData.clickUrl !== null) {
        var clickUrl = notificationData.clickUrl.toString();
        if(clickUrl.length > 0){
            if (clients.openWindow) {
                return clients.openWindow(clickUrl);
            }
        }
    }
});');
            $response->headers->set($response->headers->make('Content-Type', 'text/javascript'));
            return $response;
        });

//$app->hooks->add('responseCreated', function($response) use ($app, $context) {
//    if ((string) $app->request->path === '/' && $response instanceof App\Response\HTML) {
//        $dom = new \IvoPetkov\HTML5DOMDocument();
//        $dom->loadHTML($response->content);
//        $manifestHTML = '<html><head><link rel="manifest" href="' . $app->urls->get('/ivopetkov-push-notifications-manifest.json') . '"></head></html>';
//        $dom->insertHTML($manifestHTML);
//        $response->content = $dom->saveHTML();
//    }
//});

$updateServerRequestSubscription = function($data, $subscribe) use ($app) {
    if (isset($data['subscription'], $data['subscriberKey'])) {
        $subscription = json_decode($data['subscription'], true);
        if (is_array($subscription)) {
            $subscriberKey = base64_decode($data['subscriberKey']);
            $subscriberIDData = $app->encryption->decrypt((string) $subscriberKey);
            if (strlen($subscriberIDData) > 0) {
                $subscriberIDData = json_decode($subscriberIDData, true);
                if (is_array($subscriberIDData) && isset($subscriberIDData[0], $subscriberIDData[1]) && $subscriberIDData[0] === 'ivopetkov-push-notifications-subscriber-id') {
                    $subscriberID = (string) $subscriberIDData[1];
                    if ($subscribe) {
                        $subscriptionID = $app->pushNotifications->subscribe($subscriberID, $subscription);
                        return json_encode(['status' => 'ok', 'subscriptionID' => $subscriptionID]);
                    } else {
                        $subscriptionID = $app->pushNotifications->getSubscriptionID($subscriberID, $subscription);
                        if ($subscriptionID !== null) {
                            $app->pushNotifications->unsubscribe($subscriberID, $subscriptionID);
                        }
                        return json_encode(['status' => 'ok']);
                    }
                }
            }
        }
    }
    return '{}';
};

$app->serverRequests
        ->add('ivopetkov-push-notifications-subscribe', function($data) use ($updateServerRequestSubscription) {
            return $updateServerRequestSubscription($data, true);
        })
        ->add('ivopetkov-push-notifications-unsubscribe', function($data) use ($updateServerRequestSubscription) {
            return $updateServerRequestSubscription($data, false);
        });
