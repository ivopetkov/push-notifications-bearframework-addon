<?php

/*
 * Push Notifications addon for Bear Framework
 * https://github.com/ivopetkov/push-notifications-bearframework-addon
 * Copyright (c) Ivo Petkov
 * Free to use under the MIT license.
 */

use BearFramework\App;

$app = App::get();
$context = $app->contexts->get(__DIR__);

$context->classes
    ->add('IvoPetkov\BearFrameworkAddons\PushNotifications', 'classes/PushNotifications.php')
    ->add('IvoPetkov\BearFrameworkAddons\PushNotifications\*', 'classes/PushNotifications/*.php');

$context->assets
    ->addDir('assets/');

$app->shortcuts
    ->add('pushNotifications', function () {
        return new IvoPetkov\BearFrameworkAddons\PushNotifications();
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

$processServerRequestSubscription = function (array $data, int $action) use ($app) { // Actions: 1 - get, 2 - subscribe, 3 - unsubscribe
    if (isset($data['subscription'], $data['subscriberKey'])) {
        $subscription = json_decode($data['subscription'], true);
        if (is_array($subscription)) {
            $subscriberKey = base64_decode($data['subscriberKey']);
            $subscriberIDData = (string)$app->encryption->decrypt((string) $subscriberKey);
            if (strlen($subscriberIDData) > 0) {
                $subscriberIDData = json_decode($subscriberIDData, true);
                if (is_array($subscriberIDData) && isset($subscriberIDData[0], $subscriberIDData[1]) && $subscriberIDData[0] === 'ivopetkov-push-notifications-subscriber-id') {
                    $subscriberID = (string) $subscriberIDData[1];
                    if ($action === 1) { // get
                        $subscriptionData = $app->pushNotifications->getSubscriptionData($subscriberID, $subscription);
                        return json_encode([
                            'status' => 'ok',
                            'subscriptionID' => $subscriptionData !== null ? $subscriptionData['id'] : null,
                            'channels' => $subscriptionData !== null ? $subscriptionData['channels'] : [],
                        ]);
                    } else if ($action === 2) { // subscribe
                        $subscriptionID = $app->pushNotifications->subscribe($subscriberID, $subscription, isset($data['vapidPublicKey']) ? $data['vapidPublicKey'] : null, isset($data['vapidPrivateKey']) ? $data['vapidPrivateKey'] : null, isset($data['channel']) ? $data['channel'] : null);
                        $subscriptionData = $app->pushNotifications->getSubscriptionData($subscriberID, $subscription);
                        return json_encode([
                            'status' => 'ok',
                            'subscriptionID' => $subscriptionID,
                            'channels' => $subscriptionData !== null ? $subscriptionData['channels'] : [],
                        ]);
                    } else { // unsubscribe
                        $channel = isset($data['channel']) ? $data['channel'] : null;
                        $subscriptionID = $app->pushNotifications->getSubscriptionID($subscriberID, $subscription);
                        if ($subscriptionID !== null) {
                            $app->pushNotifications->unsubscribe($subscriberID, $subscriptionID, $channel);
                        }
                        if ($channel !== null) {
                            $subscriptionData = $app->pushNotifications->getSubscriptionData($subscriberID, $subscription);
                            if (!empty($subscriptionData['channels'])) {
                                return json_encode([
                                    'status' => 'keep',
                                    'subscriptionID' => $subscriptionID,
                                    'channels' => $subscriptionData['channels'],
                                ]);
                            }
                        }
                        return json_encode([
                            'status' => 'ok'
                        ]);
                    }
                }
            }
        }
    }
    return '{}';
};

$app->serverRequests
    ->add('ivopetkov-push-notifications-get', function ($data) use ($processServerRequestSubscription) {
        return $processServerRequestSubscription($data, 1);
    })
    ->add('ivopetkov-push-notifications-subscribe', function ($data) use ($processServerRequestSubscription) {
        return $processServerRequestSubscription($data, 2);
    })
    ->add('ivopetkov-push-notifications-unsubscribe', function ($data) use ($processServerRequestSubscription) {
        return $processServerRequestSubscription($data, 3);
    })
    ->add('ivopetkov-push-notifications-get-vapid', function () {
        $keys = \Minishlink\WebPush\VAPID::createVapidKeys();
        return json_encode([
            'vapidPublicKey' => $keys['publicKey'],
            'vapidPrivateKey' => $keys['privateKey'],
        ]);
    });
