<?php

/*
 * Push Notifications addon for Bear Framework
 * https://github.com/ivopetkov/push-notifications-bearframework-addon
 * Copyright (c) Ivo Petkov
 * Free to use under the MIT license.
 */

namespace IvoPetkov\BearFrameworkAddons;

use BearFramework\App;
use IvoPetkov\BearFrameworkAddons\PushNotifications\PushNotification;
use IvoPetkov\HTML5DOMDocument;

/**
 *
 */
class PushNotifications
{

    private $subscriberID = null;
    private $verifyOwnershipBeforeShow = false;
    private $config = [];

    /**
     *
     */
    private static $newPushNotificationCache = null;

    /**
     * 
     * @param array $config
     * @return void
     */
    public function initialize(array $config): void
    {
        $this->config = $config;

        $app = App::get();
        $app->routes
            ->add('/ivopetkov-push-notifications-data', function () use ($app) {
                $endpoint = (string)$app->request->query->getValue('endpoint');
                $result = [];
                if (strlen($endpoint) > 0) {
                    $result = $app->pushNotifications->getPendingEndpointData($this->subscriberID, $endpoint);
                }
                return new App\Response\JSON(json_encode($result));
            })
            ->add('/ivopetkov-push-notifications-manifest.json', function () use ($app) {
                return new App\Response\JSON(json_encode([
                    'gcm_sender_id' => (isset($this->config['googleCloudMessagingSenderID']) ? $this->config['googleCloudMessagingSenderID'] : ''),
                    'gcm_user_visible_only' => true
                ]));
            })
            ->add('/ivopetkov-push-notifications-service-worker.js', function () use ($app) {
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

        if (isset($this->config['subscriberID'])) {
            $this->subscriberID = $this->config['subscriberID'];
        }
        if (isset($this->config['verifyOwnershipBeforeShow'])) {
            $this->verifyOwnershipBeforeShow = (int) $this->config['verifyOwnershipBeforeShow'] > 0;
        }
    }

    /**
     * Constructs a new push notification and returns it.
     * 
     * @param ?string $title The notification title.
     * @param ?string $body The notification body.
     * @return \BearFramework\PushNotifications\PushNotification
     */
    public function make(string $title = null, string $body = null): PushNotification
    {
        if (self::$newPushNotificationCache === null) {
            self::$newPushNotificationCache = new PushNotification();
        }
        $pushNotification = clone (self::$newPushNotificationCache);
        if ($title !== null) {
            $pushNotification->title = $title;
        }
        if ($body !== null) {
            $pushNotification->body = $body;
        }
        return $pushNotification;
    }

    /**
     * Registers a new subscription for the subscriber specified.
     * 
     * @param string $subscriberID The subscriber ID. The subscriber ID.
     * @param array $subscription The subscription data. The subscription data.
     * @param string|null $vapidPublicKey
     * @param string|null $vapidPrivateKey
     * @return string Returns the subscription ID.
     */
    public function subscribe(string $subscriberID, array $subscription, string $vapidPublicKey = null, string $vapidPrivateKey = null): string
    {
        $app = App::get();
        $lockKey = 'notifications-subscriber-' . $subscriberID;
        $app->locks->acquire($lockKey);
        $data = $this->getSubscriberData($subscriberID);
        ksort($subscription);
        $subscriptionID = md5(json_encode($subscription));
        if (!isset($data['subscriptions'][$subscriptionID])) {
            // $data['subscriptions'][$subscriptionID] = $subscription; // v1
            $data['subscriptions'][$subscriptionID] = [2, $subscription, $vapidPublicKey, $vapidPrivateKey]; // v2
            $this->setSubscriberData($subscriberID, $data);
        }
        $app->locks->release($lockKey);
        return $subscriptionID;
    }

    /**
     * Deletes the subscription specified.
     * 
     * @param string $subscriberID The subscriber ID.
     * @param string $subscriptionID The subscription ID to delete.
     */
    public function unsubscribe(string $subscriberID, string $subscriptionID): void
    {
        $app = App::get();
        $lockKey = 'notifications-subscriber-' . $subscriberID;
        $app->locks->acquire($lockKey);
        $data = $this->getSubscriberData($subscriberID);
        if (isset($data['subscriptions'][$subscriptionID])) {
            unset($data['subscriptions'][$subscriptionID]);
            $this->setSubscriberData($subscriberID, $data);
        }
        $app->locks->release($lockKey);
    }

    /**
     * Returns the subscription ID for the subscription specified.
     * 
     * @param string $subscriberID The subscriber ID.
     * @param array $subscription The subscription data.
     * @return ?string Returns the subscription ID or null if not found.
     */
    public function getSubscriptionID(string $subscriberID, array $subscription): ?string
    {
        $data = $this->getSubscriberData($subscriberID);
        ksort($subscription);
        $subscriptionJSON = json_encode($subscription);
        foreach ($data['subscriptions'] as $subscriptionID => $otherSubscription) {
            if (is_array($otherSubscription) && isset($otherSubscription[0]) && $otherSubscription[0] === 2) { // v2
                if (json_encode($otherSubscription[1]) === $subscriptionJSON) {
                    return $subscriptionID;
                }
            } else {
                if (json_encode($otherSubscription) === $subscriptionJSON) {
                    return $subscriptionID;
                }
                if (
                    isset($otherSubscription['endpoint']) && isset($subscription['endpoint']) && $otherSubscription['endpoint'] === $subscription['endpoint'] &&
                    isset($otherSubscription['key']) && isset($subscription['key']) && $otherSubscription['key'] === $subscription['key']
                ) {
                    return $subscriptionID;
                }
            }
        }
        return null;
    }

    /**
     * Sets a new subscriber for the current request.
     * 
     * @param string $subscriberID The subscriber ID.
     */
    public function setSubscriberID(string $subscriberID): void
    {
        $this->subscriberID = $subscriberID;
    }

    /**
     * Applies the push notifications HTML to the response specified.
     * 
     * @param \BearFramework\App\Response\HTML $response The response object.
     * @param string $onLoad Code to execute on library initialize.
     */
    public function apply(\BearFramework\App\Response\HTML $response, string $onLoad = ''): void
    {
        $app = App::get();
        $context = $app->contexts->get(__DIR__);
        $dom = new HTML5DOMDocument();
        $dom->loadHTML($response->content, HTML5DOMDocument::ALLOW_DUPLICATE_IDS);
        $initializeData = [];
        $initializeData[] = strlen((string)$this->subscriberID) > 0 ? base64_encode($app->encryption->encrypt(json_encode(['ivopetkov-push-notifications-subscriber-id', $this->subscriberID]))) : '';
        $initializeData[] = $app->urls->get('/ivopetkov-push-notifications-service-worker.js');
        // dev code        
        //$jsCode = file_get_contents(__DIR__ . '/../assets/pushNotifications.js') . "ivoPetkov.bearFrameworkAddons.pushNotifications.initialize(" . json_encode($initializeData) . ");" . $onLoad;
        $jsCode = "var script=document.createElement('script');script.src='" . $context->assets->getURL('assets/pushNotifications.min.js', ['cacheMaxAge' => 999999999, 'version' => 8]) . "';script.onload=function(){ivoPetkov.bearFrameworkAddons.pushNotifications.initialize(" . json_encode($initializeData) . ");" . $onLoad . "};document.head.appendChild(script);";
        $scriptHTML = "<html>"
            . "<body><script>" . $jsCode . "</script></body>"
            . "</html>";
        $manifestHTML = '<html><head><link rel="client-packages"><link rel="manifest" href="' . $app->urls->get('/ivopetkov-push-notifications-manifest.json') . '"></head></html>';
        $dom->insertHTMLMulti([
            ['source' => $scriptHTML],
            ['source' => $manifestHTML]
        ]);
        $response->content = $dom->saveHTML();
    }

    /**
     * Sends a new push notification to the subscriber specified.
     * 
     * @param string $subscriberID The subscriber ID.
     * @param \IvoPetkov\BearFrameworkAddons\PushNotifications\PushNotification $notification The push notification to send.
     * @param array $options Available values: subscriptionIDs=>[]
     */
    public function send(string $subscriberID, PushNotification $notification, $options = []): void
    {
        if (isset($options['subscriptionIDs']) && !is_array($options['subscriptionIDs'])) {
            throw new \Exception('The subscriptionIDs option must be of type array.');
        }
        $subscriptionIDs = isset($options['subscriptionIDs']) ? $options['subscriptionIDs'] : null;
        $data = $this->getSubscriberData($subscriberID);
        $subscriptionsIDsToDelete = [];
        foreach ($data['subscriptions'] as $subscriptionID => $subscriptionData) {
            if ($subscriptionIDs !== null) {
                if (array_search($subscriptionID, $subscriptionIDs) === false) {
                    continue;
                }
            }
            if (is_array($subscriptionData) && isset($subscriptionData[0]) && $subscriptionData[0] === 2) { // v2
                $subscription = $subscriptionData[1];
                $vapidPublicKey = $subscriptionData[2];
                $vapidPrivateKey = $subscriptionData[3];
            } else {
                $subscription = $subscriptionData;
                $vapidPublicKey = null;
                $vapidPrivateKey = null;
            }
            $result = $this->sendNotification($subscriberID, $notification, $subscription, $vapidPublicKey, $vapidPrivateKey);
            if ($result === 'delete') {
                $subscriptionsIDsToDelete[] = $subscriptionID;
            }
        }
        foreach ($subscriptionsIDsToDelete as $subscriptionID) {
            $this->unsubscribe($subscriberID, $subscriptionID);
        }
    }

    /**
     * Sends a push notification to a specific subscription.
     * 
     * @param string $subscriberID The subscriber ID.
     * @param array \IvoPetkov\BearFrameworkAddons\PushNotifications\PushNotification $notification The push notification to send.
     * @param array $subscription The subscription data.
     * @param array $vapidPublicKey The VAPID private key.
     * @param array $vapidPrivateKey The VAPID private key.
     * @return mixed
     * @throws \Exception
     */
    private function sendNotification(string $subscriberID, PushNotification $notification, array $subscription, string $vapidPublicKey = null, string $vapidPrivateKey = null)
    {
        $app = App::get();

        $endpoint = $subscription['endpoint'];

        $notificationData = [];
        $notificationData['title'] = (string) $notification->title;
        if (strlen((string)$notification->body) > 0) {
            $notificationData['body'] = (string) $notification->body;
        }
        if (strlen((string)$notification->icon) > 0) {
            $notificationData['icon'] = (string) $notification->icon;
        }
        if (strlen((string)$notification->badge) > 0) {
            $notificationData['badge'] = (string) $notification->badge;
        }
        if (strlen((string)$notification->tag) > 0) {
            $notificationData['tag'] = (string) $notification->tag;
        }
        if (strlen((string)$notification->clickUrl) > 0) {
            $notificationData['clickUrl'] = (string) $notification->clickUrl;
        }
        $notificationData['requireInteraction'] = $notification->requireInteraction;

        $endpointDataKey = $this->getEndpointDataKey($endpoint);
        $data = (string)$app->data->getValue($endpointDataKey);
        $data = strlen($data) > 0 ? json_decode($data, true) : [];
        if (!isset($data[0])) { // notifications
            $data[0] = [];
        }
        if (!isset($data[1])) { // subscriber
            $data[0] = [];
            $data[1] = $subscriberID;
        }
        $data[0][] = $notificationData;
        $app->data->set($app->data->make($endpointDataKey, json_encode($data)));

        if ($vapidPublicKey !== null && $vapidPrivateKey !== null) {
            $webPush = new \Minishlink\WebPush\WebPush([
                'VAPID' => [
                    'subject' => 'ntf',
                    'publicKey' => $vapidPublicKey,
                    'privateKey' => $vapidPrivateKey
                ]
            ]);
            $result = $webPush->sendOneNotification(\Minishlink\WebPush\Subscription::create($subscription), null);
            if ($result->isSubscriptionExpired()) {
                return 'delete';
            }
        } else {
            $urlParts = parse_url($endpoint);
            if (isset($urlParts['host'], $urlParts['path'])) {
                $host = $urlParts['host'];

                $ch = curl_init();
                if ($host === 'updates.push.services.mozilla.com') {
                    curl_setopt($ch, CURLOPT_URL, $endpoint);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, [
                        "TTL:86400"
                    ]);
                } elseif ($host === 'android.googleapis.com' || $host === 'fcm.googleapis.com') {
                    if (!isset($this->config['googleCloudMessagingAPIKey'])) {
                        throw new \Exception('invalidGCMAPIKey');
                    }
                    $gcmUrl = $host === 'android.googleapis.com' ? 'https://android.googleapis.com/gcm/send/' : 'https://fcm.googleapis.com/fcm/send/';
                    curl_setopt($ch, CURLOPT_URL, trim($gcmUrl, '/'));
                    if (strpos($endpoint, $gcmUrl) === 0) {
                        $messageData = [
                            "registration_ids" => [substr($endpoint, strlen($gcmUrl))],
                        ];
                        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($messageData));
                    } else {
                        throw new \Exception('Invalid endpoint (' . $endpoint . ')');
                    }
                    curl_setopt($ch, CURLOPT_HTTPHEADER, [
                        "Authorization:key=" . $this->config['googleCloudMessagingAPIKey'],
                        "Content-Type:application/json"
                    ]);
                } else {
                    throw new \Exception('Invalid endpoint (' . $endpoint . ')');
                }
                curl_setopt($ch, CURLOPT_HEADER, 1);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($ch, CURLOPT_POST, true);

                $response = curl_exec($ch);
                $curlInfo = curl_getinfo($ch);
                curl_close($ch);
                //$app->logger->log('push-notifications-response-error', print_r($subscription, true) . "\n" . $response);
                $statusCode = (int) $curlInfo['http_code'];
                if ($host === 'updates.push.services.mozilla.com') {
                    if ($statusCode === 201) {
                        return true;
                    } elseif ($statusCode === 410) {
                        return 'delete';
                    }
                } elseif (($host === 'android.googleapis.com' || $host === 'fcm.googleapis.com') && $statusCode === 200) {
                    $body = substr($response, $curlInfo['header_size']);
                    $resultData = json_decode($body, true);
                    if (isset($resultData['results'], $resultData['results'][0], $resultData['results'][0]['error']) && $resultData['results'][0]['error'] === 'NotRegistered') {
                        return 'delete';
                    }
                    return isset($resultData['success']) && (int) $resultData['success'] === 1;
                }
                return false;
            } else {
                return 'delete';
            }
        }
    }

    /**
     * 
     * @param string $endpoint
     * @return string
     */
    private function getEndpointDataKey(string $endpoint): string
    {
        $endpointMD5 = md5($endpoint);
        return '.temp/push-notifications/endpoints/' . substr($endpointMD5, 0, 2) . '/' . substr($endpointMD5, 2, 2) . '/' . $endpointMD5 . '.2.json';
    }

    /**
     * 
     * @param string $subscriberID The subscriber ID.
     * @return string
     */
    private function getSubscriberDataKey(string $subscriberID): string
    {
        $subscriberIDMD5 = md5($subscriberID);
        return 'push-notifications/subscribers/subscriber/' . substr($subscriberIDMD5, 0, 2) . '/' . substr($subscriberIDMD5, 2, 2) . '/' . $subscriberIDMD5 . '.json';
    }

    /**
     * 
     * @param string $subscriberID
     * @return array
     */
    private function getSubscriberData(string $subscriberID): array
    {
        $app = App::get();
        $subscriberDataKey = $this->getSubscriberDataKey($subscriberID);
        $data = (string)$app->data->getValue($subscriberDataKey);
        $data = strlen($data) > 0 ? json_decode($data, true) : [];
        $data['id'] = $subscriberID;
        if (!isset($data['subscriptions'])) {
            $data['subscriptions'] = [];
        }
        return $data;
    }

    /**
     * 
     * @param string $subscriberID
     * @param data $data
     */
    private function setSubscriberData(string $subscriberID, array $data): void
    {
        $app = App::get();
        $subscriberDataKey = $this->getSubscriberDataKey($subscriberID);
        if (empty($data['subscriptions'])) {
            $app->data->delete($subscriberDataKey);
        } else {
            $app->data->set($app->data->make($subscriberDataKey, json_encode($data)));
        }
    }

    /**
     * @internal
     * @param string $subscriberID
     * @param string $endpoint
     * @return string
     */
    public function getPendingEndpointData(string $subscriberID, string $endpoint): array
    {
        $result = [];
        $app = App::get();
        $endpointDataKey = $this->getEndpointDataKey($endpoint);
        $data = (string)$app->data->getValue($endpointDataKey);
        $data = strlen($data) > 0 ? json_decode($data, true) : [];
        if (isset($data[0])) { // notifications
            if ($this->verifyOwnershipBeforeShow) {
                if (isset($data[1])) {
                    if ($data[1] === $subscriberID) {
                        $result = $data[0];
                    } else { // delete subscription
                        $subscriberIDToUnsubscribeFrom = $data[1];
                        $data = $this->getSubscriberData($subscriberIDToUnsubscribeFrom);
                        foreach ($data['subscriptions'] as $subscriptionID => $subscription) {
                            if (isset($subscription['endpoint']) && $subscription['endpoint'] === $endpoint) {
                                $this->unsubscribe($subscriberIDToUnsubscribeFrom, $subscriptionID);
                                break;
                            }
                        }
                    }
                }
            } else {
                $result = $data[0];
            }
        }
        $app->data->delete($endpointDataKey);
        return $result;
    }
}
