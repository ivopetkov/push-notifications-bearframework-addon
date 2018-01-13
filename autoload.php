<?php

/*
 * Push Notifications addon for Bear Framework
 * https://github.com/ivopetkov/push-notifications-bearframework-addon
 * Copyright (c) 2018 Ivo Petkov
 * Free to use under the MIT license.
 */

BearFramework\Addons::register('ivopetkov/push-notifications-bearframework-addon', __DIR__, [
    'require' => [
        'ivopetkov/encryption-bearframework-addon',
        'ivopetkov/server-requests-bearframework-addon'
    ]
]);
