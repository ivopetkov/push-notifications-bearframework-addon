<?php

/*
 * Push Notifications addon for Bear Framework
 * https://github.com/ivopetkov/push-notifications-bearframework-addon
 * Copyright (c) 2018 Ivo Petkov
 * Free to use under the MIT license.
 */

use BearFramework\App;

$app = App::get();
$context = $app->context->get(__FILE__);

$context->classes
        ->add('IvoPetkov\BearFrameworkAddons\PushNotifications', 'classes/PushNotifications.php');

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
                                var promise = self.registration.showNotification(notificationData.title, {
                                    "body": notificationData.message,
                                    "icon": notificationData.icon,
                                    "tag": notificationData.tag,
                                    "data": notificationData
                                });
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
    if (typeof event.notification.data.onClickUrl !== "undefined") {
        if (clients.openWindow) {
            return clients.openWindow(event.notification.data.onClickUrl);
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

$app->serverRequests
        ->add('ivopetkov-push-notifications-subscribe', function($data) use ($app) {
            if (isset($data['endpoint'], $data['key'], $data['authSecret'])) {
                $subscriptionData = [];
                $subscriptionData['endpoint'] = $data['endpoint'];
                $subscriptionData['key'] = $data['key'];
                $subscriptionData['authSecret'] = $data['authSecret'];
                $subscriberKey = isset($data['subscriberKey']) ? base64_decode($data['subscriberKey']) : '';
                $subscriberIDData = $app->encryption->decrypt((string) $subscriberKey);
                if (strlen($subscriberIDData) > 0) {
                    $subscriberIDData = json_decode($subscriberIDData, true);
                    if (is_array($subscriberIDData) && isset($subscriberIDData[0], $subscriberIDData[1]) && $subscriberIDData[0] === 'ivopetkov-push-notifications-subscriber-id') {
                        $subscriberID = (string) $subscriberIDData[1];
                        $app->pushNotifications->subscribe($subscriberID, $subscriptionData);
                        return '1';
                    }
                }
            }
            return '0';
        });
