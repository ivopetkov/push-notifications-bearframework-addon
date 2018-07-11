<?php

/*
 * Push Notifications addon for Bear Framework
 * https://github.com/ivopetkov/push-notifications-bearframework-addon
 * Copyright (c) Ivo Petkov
 * Free to use under the MIT license.
 */

namespace IvoPetkov\BearFrameworkAddons\PushNotifications;

/**
 * @property string|null $title
 * @property string|null $body
 * @property string|null $icon
 * @property string|null $badge
 * @property string|null $tag
 * @property string|null $clickUrl
 * @property bool $requireInteraction
 */
class PushNotification
{

    use \IvoPetkov\DataObjectTrait;
    use \IvoPetkov\DataObjectToArrayTrait;
    use \IvoPetkov\DataObjectToJSONTrait;

    function __construct()
    {
        $this->defineProperty('title', [
            'type' => '?string'
        ]);
        $this->defineProperty('body', [
            'type' => '?string'
        ]);
        $this->defineProperty('icon', [
            'type' => '?string'
        ]);
        $this->defineProperty('badge', [
            'type' => '?string'
        ]);
        $this->defineProperty('tag', [
            'type' => '?string'
        ]);
        $this->defineProperty('clickUrl', [
            'type' => '?string'
        ]);
        $this->defineProperty('requireInteraction', [
            'type' => 'bool',
            'init' => function() {
                return false;
            }
        ]);
    }

}
