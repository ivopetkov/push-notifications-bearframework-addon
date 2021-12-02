<?php

/*
 * Push Notifications addon for Bear Framework
 * https://github.com/ivopetkov/push-notifications-bearframework-addon
 * Copyright (c) Ivo Petkov
 * Free to use under the MIT license.
 */

use IvoPetkov\BearFrameworkAddons\PushNotifications\PushNotification;

/**
 * @runTestsInSeparateProcesses
 */
class PushNotificationsTest extends BearFramework\AddonTests\PHPUnitTestCase
{

    /**
     * 
     */
    public function testBasics()
    {
        $app = $this->getApp();
        $this->assertTrue($app->pushNotifications->make() instanceof PushNotification);
    }
}
