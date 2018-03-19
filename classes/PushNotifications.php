<?php

/*
 * Push Notifications addon for Bear Framework
 * https://github.com/ivopetkov/push-notifications-bearframework-addon
 * Copyright (c) 2018 Ivo Petkov
 * Free to use under the MIT license.
 */

namespace IvoPetkov\BearFrameworkAddons;

use BearFramework\App;
use IvoPetkov\BearFrameworkAddons\PushNotifications\PushNotification;

/**
 *
 */
class PushNotifications
{

    private $subscriberID = null;

    /**
     *
     */
    private static $newPushNotificationCache = null;

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
        $pushNotification = clone(self::$newPushNotificationCache);
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
     * @return string Returns the subscription ID.
     */
    public function subscribe(string $subscriberID, array $subscription): string
    {
        $app = App::get();
        $lockKey = 'notifications-subscriber-' . $subscriberID;
        $app->locks->acquire($lockKey);
        $data = $this->getSubscriberData($subscriberID);
        ksort($subscription);
        $subscriptionID = md5(json_encode($subscription));
        if (!isset($data['subscriptions'][$subscriptionID])) {
            $data['subscriptions'][$subscriptionID] = $subscription;
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
    public function getSubscriptionID(string $subscriberID, array $subscription): string//?????
    {
        $data = $this->getSubscriberData($subscriberID);
        ksort($subscription);
        $subscriptionJSON = json_encode($subscription);
        foreach ($data['subscriptions'] as $subscriptionID => $otherSubscription) {
            if (json_encode($otherSubscription) === $subscriptionJSON) {
                return $subscriptionID;
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
        $context = $app->context->get(__FILE__);
        $dom = new \IvoPetkov\HTML5DOMDocument();
        $dom->loadHTML($response->content);
        $initializeData = [];
        $initializeData[] = strlen($this->subscriberID) > 0 ? base64_encode($app->encryption->encrypt(json_encode(['ivopetkov-push-notifications-subscriber-id', $this->subscriberID]))) : '';
        $initializeData[] = $app->urls->get('/ivopetkov-push-notifications-service-worker.js');
        $scriptHTML = "<script>var script=document.createElement('script');script.src='" . $context->assets->getUrl('assets/pushNotifications.min.js', ['cacheMaxAge' => 999999999, 'version' => 2]) . "';script.onload=function(){ivoPetkov.bearFrameworkAddons.pushNotifications.initialize(" . json_encode($initializeData) . ");" . $onLoad . "};document.head.appendChild(script);</script>";
        $manifestHTML = '<html><head><link rel="manifest" href="' . $app->urls->get('/ivopetkov-push-notifications-manifest.json') . '"></head></html>';
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
        foreach ($data['subscriptions'] as $subscriptionID => $subscription) {
            if ($subscriptionIDs !== null) {
                if (array_search($subscriptionID, $subscriptionIDs) === false) {
                    continue;
                }
            }
            $result = $this->sendNotification($subscription, $notification);
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
     * @param array $subscription The subscription data.
     * @param array \IvoPetkov\BearFrameworkAddons\PushNotifications\PushNotification $notification The push notification to send.
     * @return mixed
     * @throws \Exception
     */
    private function sendNotification(array $subscription, PushNotification $notification)
    {
        $app = App::get();
        $options = $app->addons->get('ivopetkov/push-notifications-bearframework-addon')->options;

        $endpoint = $subscription['endpoint'];

        $notificationData = [];
        $notificationData['title'] = $notification->title;
        $notificationData['body'] = $notification->body;
        $notificationData['icon'] = $notification->icon;
        $notificationData['badge'] = $notification->badge;
        $notificationData['tag'] = $notification->tag;
        $notificationData['clickUrl'] = $notification->clickUrl;
        $notificationData['requireInteraction'] = $notification->requireInteraction;

        $endpointDataKey = $this->getEndpointDataKey($endpoint);
        $data = $app->data->getValue($endpointDataKey);
        $data = strlen($data) > 0 ? json_decode($data, true) : [];
        $data[] = $notificationData;
        $app->data->set($app->data->make($endpointDataKey, json_encode($data)));

        $urlParts = parse_url($endpoint);
        if (isset($urlParts['host'], $urlParts['path'])) {
            $host = $urlParts['host'];

            $ch = curl_init();
            if ($host === 'updates.push.services.mozilla.com') {
                curl_setopt($ch, CURLOPT_URL, $endpoint);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    "TTL:86400"
                ]);
            } elseif ($host === 'android.googleapis.com') {
                if (!isset($options['googleCloudMessagingAPIKey'])) {
                    throw new \Exception('invalidGCMAPIKey');
                }
                $gcmUrl = 'https://android.googleapis.com/gcm/send/';
                curl_setopt($ch, CURLOPT_URL, trim($gcmUrl, '/'));
                if (strpos($endpoint, $gcmUrl) === 0) {
                    $messageData = [
                        "registration_ids" => [substr($endpoint, strlen($gcmUrl))],
                            //"data" => $temp,
                            //"notification" => $temp,
                            //"time_to_live"=>10 // seconds
                            //"priority"=>"high" // normal or high
                    ];
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($messageData));
                } else {
                    throw new \Exception('invalidEndpoint');
                }
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    "Authorization:key=" . $options['googleCloudMessagingAPIKey'],
                    "Content-Type:application/json"
                ]);
            } else {
                throw new \Exception('invalidEndpoint');
            }
            curl_setopt($ch, CURLOPT_HEADER, 1);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_POST, true);

            $response = curl_exec($ch);
            $curlInfo = curl_getinfo($ch);
            curl_close($ch);
            //$app->logger->log('push-notifications-response-error', print_r($subscription, true) . "\n" . $response);
            $statusCode = (int) $curlInfo['http_code'];
            if ($host === 'updates.push.services.mozilla.com' && $statusCode === 201) {
                return true;
            } elseif ($host === 'android.googleapis.com' && $statusCode === 200) {
                $body = substr($response, $curlInfo['header_size']);
                $resultData = json_decode($body, true);
                if (isset($resultData['results'], $resultData['results'][0], $resultData['results'][0]['error']) && $resultData['results'][0]['error'] === 'NotRegistered') {
                    return 'delete';
                }
                return isset($resultData['success']) && (int) $resultData['success'] === 1;
            }
        } else {
            return 'delete';
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
        return '.temp/push-notifications/endpoints/' . substr($endpointMD5, 0, 2) . '/' . substr($endpointMD5, 2, 2) . '/' . $endpointMD5 . '.json';
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
        $data = $app->data->getValue($subscriberDataKey);
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
     * 
     * @param string $endpoint
     * @return string
     */
    public function getPendingEndpointData(string $endpoint): array
    {
        $app = App::get();
        $endpointDataKey = $this->getEndpointDataKey($endpoint);
        $data = $app->data->getValue($endpointDataKey);
        $data = strlen($data) > 0 ? json_decode($data, true) : [];
        $app->data->delete($endpointDataKey);
        return $data;
    }

}
