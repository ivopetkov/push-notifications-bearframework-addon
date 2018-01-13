<?php

/*
 * Push Notifications addon for Bear Framework
 * https://github.com/ivopetkov/push-notifications-bearframework-addon
 * Copyright (c) 2018 Ivo Petkov
 * Free to use under the MIT license.
 */

namespace IvoPetkov\BearFrameworkAddons;

use BearFramework\App;

/**
 *
 */
class PushNotifications
{

    private $subscriberID = null;

    private function getSubscriberDataKey(string $subscriberID)
    {
        $subscriberIDMD5 = md5($subscriberID);
        return 'ivopetkov-push-notifications/subscribers/subscriber/' . substr($subscriberIDMD5, 0, 2) . '/' . substr($subscriberIDMD5, 2, 2) . '/' . $subscriberIDMD5 . '.json';
    }

    public function subscribe(string $subscriberID, array $subscription)
    {
        $app = App::get();
        $dataKey = $this->getSubscriberDataKey($subscriberID);
        $data = $app->data->getValue($dataKey);
        $data = strlen($data) > 0 ? json_decode($data, true) : [];
        $data['id'] = $subscriberID;
        if (!isset($data['subscriptions'])) {
            $data['subscriptions'] = [];
        }
        $subscriptionID = md5(json_encode($subscription));
        if (!isset($data['subscriptions'][$subscriptionID])) {
            $data['subscriptions'][$subscriptionID] = $subscription;
            $app->data->set($app->data->make($dataKey, json_encode($data)));
        }
    }

    public function setSubscriberID(string $subscriberID)
    {
        $this->subscriberID = $subscriberID;
    }

    public function apply(\BearFramework\App\Response\HTML $response, string $onLoad = '')
    {
        $app = App::get();
        $context = $app->context->get(__FILE__);
        $dom = new \IvoPetkov\HTML5DOMDocument();
        $dom->loadHTML($response->content);
        $initializeData = [];
        $initializeData[] = strlen($this->subscriberID) > 0 ? base64_encode($app->encryption->encrypt(json_encode(['ivopetkov-push-notifications-subscriber-id', $this->subscriberID]))) : '';
        $initializeData[] = $app->urls->get('/ivopetkov-push-notifications-service-worker.js');
        $scriptHTML = "<script>var script=document.createElement('script');script.src='" . $context->assets->getUrl('assets/pushNotifications.js', ['cacheMaxAge' => 999999999, 'version' => 1]) . "';script.onload=function(){ivoPetkov.bearFrameworkAddons.pushNotifications.initialize(" . json_encode($initializeData) . ");" . $onLoad . "};document.head.appendChild(script);</script>";
        $manifestHTML = '<html><head><link rel="manifest" href="' . $app->urls->get('/ivopetkov-push-notifications-manifest.json') . '"></head></html>';
        $dom->insertHTMLMulti([
            ['source' => $scriptHTML],
            ['source' => $manifestHTML]
        ]);
        $response->content = $dom->saveHTML();
    }

    /**
     * 
     */
    public function send(string $subscriberID, array $notificationData)
    {
        $app = App::get();
        $dataKey = $this->getSubscriberDataKey($subscriberID);
        $data = $app->data->getValue($dataKey);
        $data = strlen($data) > 0 ? json_decode($data, true) : [];
        if (isset($data['subscriptions']) && is_array($data['subscriptions'])) {
            foreach ($data['subscriptions'] as $subscriptionData) {
                $this->sendNotification($subscriptionData, $notificationData);
            }
        }
    }

    private function getEndpointDataKey(string $endpoint)
    {
        $endpointMD5 = md5($endpoint);
        return '.temp/ivopetkov-push-notifications/endpoints/' . substr($endpointMD5, 0, 2) . '/' . substr($endpointMD5, 2, 2) . '/' . $endpointMD5 . '.json';
    }

    public function getPendingEndpointData($endpoint)
    {
        $app = App::get();
        $dataKey = $this->getEndpointDataKey($endpoint);
        $data = $app->data->getValue($dataKey);
        $data = strlen($data) > 0 ? json_decode($data, true) : [];
        $app->data->delete($dataKey);
        return $data;
    }

    /**
     * 
     */
    private function sendNotification(array $subscriptionData, array $notificationData)
    {
        $app = App::get();
        $options = $app->addons->get('ivopetkov/push-notifications-bearframework-addon')->options;

        $endpoint = $subscriptionData['endpoint'];

        $temp = [];
        $temp['title'] = isset($notificationData['title']) ? (string) $notificationData['title'] : '';
        $temp['icon'] = isset($notificationData['icon']) ? (string) $notificationData['icon'] : '';
        $temp['message'] = isset($notificationData['message']) ? (string) $notificationData['message'] : '';
        $temp['tag'] = isset($notificationData['tag']) ? (string) $notificationData['tag'] : '';
        $temp['onClickUrl'] = isset($notificationData['onClickUrl']) ? (string) $notificationData['onClickUrl'] : '';
        $notificationData = $temp;

        $dataKey = $this->getEndpointDataKey($endpoint);
        $data = $app->data->getValue($dataKey);
        $data = strlen($data) > 0 ? json_decode($data, true) : [];
        $data[] = $notificationData;
        $app->data->set($app->data->make($dataKey, json_encode($data)));

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
            $result = false;
            $statusCode = (int) $curlInfo['http_code'];
            if ($host === 'updates.push.services.mozilla.com' && $statusCode === 201) {
                $result = true;
            } elseif ($host === 'android.googleapis.com' && $statusCode === 200) {
                $body = substr($response, $curlInfo['header_size']);
                $resultData = json_decode($body, true);
                if (isset($resultData['results'], $resultData['results'][0], $resultData['results'][0]['error']) && $resultData['results'][0]['error'] === 'NotRegistered') {
                    // todo delete registration
                }
                $result = isset($resultData['success']) && (int) $resultData['success'] === 1;
            }
            $app->logger->log('push-notifications-response', $response);
            if ($result) {
                return true;
            } else {
                $app->logger->log('push-notifications-response-error', $response);
                return false;
            }
        } else {
            throw new \Exception('invalidData');
        }
    }

}
