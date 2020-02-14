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
    use \IvoPetkov\DataObjectFromArrayTrait;
    use \IvoPetkov\DataObjectFromJSONTrait;

    function __construct()
    {
        $this
                ->defineProperty('title', [
                    'type' => '?string'
                ])
                ->defineProperty('body', [
                    'type' => '?string'
                ])
                ->defineProperty('icon', [
                    'type' => '?string'
                ])
                ->defineProperty('badge', [
                    'type' => '?string'
                ])
                ->defineProperty('tag', [
                    'type' => '?string'
                ])
                ->defineProperty('clickUrl', [
                    'type' => '?string'
                ])
                ->defineProperty('requireInteraction', [
                    'type' => 'bool',
                    'init' => function() {
                        return false;
                    }
                ])
        ;
    }

}
