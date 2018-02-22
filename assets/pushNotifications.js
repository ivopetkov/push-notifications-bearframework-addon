/*
 * Push Notifications addon for Bear Framework
 * https://github.com/ivopetkov/push-notifications-bearframework-addon
 * Copyright (c) 2018 Ivo Petkov
 * Free to use under the MIT license.
 */

var ivoPetkov = ivoPetkov || {};
ivoPetkov.bearFrameworkAddons = ivoPetkov.bearFrameworkAddons || {};
ivoPetkov.bearFrameworkAddons.pushNotifications = (function () {

    var subscriberKey = null;
    var serviceWorkerFilePath = null;

    var initialize = function (data) {
        if (typeof data[0] !== 'undefined') {
            subscriberKey = data[0];
        }
        if (typeof data[1] !== 'undefined') {
            serviceWorkerFilePath = data[1];
        }
    };

    var getStatus = function () {
        return new Promise(function (resolve, reject) {
            var respondWithError = function (code, message) {
                var status = {
                    'code': code,
                    'message': message
                };
                resolve(status);
            };
            if ('serviceWorker' in navigator) {
                navigator.serviceWorker.register(serviceWorkerFilePath)
                        .then(function () {
                            if (!('showNotification' in ServiceWorkerRegistration.prototype)) {
                                respondWithError('NOT_SUPPORTED', 'Notifications aren\'t supported.');
                                return;
                            }
                            if (Notification.permission === 'denied') {
                                respondWithError('ACCESS_DENIED', 'The user has blocked notifications.');
                                return;
                            }
                            if (!('PushManager' in window)) {
                                respondWithError('NOT_SUPPORTED', 'Push messaging isn\'t supported.');
                                return;
                            }
                            navigator.serviceWorker.ready.then(function (serviceWorkerRegistration) {
                                var onDone = function (subscription) {
                                    endpoint = subscription.endpoint;
                                    var rawKey = subscription.getKey ? subscription.getKey('p256dh') : '';
                                    var key = rawKey ? btoa(String.fromCharCode.apply(null, new Uint8Array(rawKey))) : '';
                                    var rawAuthSecret = subscription.getKey ? subscription.getKey('auth') : '';
                                    var authSecret = rawAuthSecret ? btoa(String.fromCharCode.apply(null, new Uint8Array(rawAuthSecret))) : '';
                                    ivoPetkov.bearFrameworkAddons.serverRequests.send('ivopetkov-push-notifications-subscribe', {
                                        'subscriberKey': subscriberKey,
                                        'endpoint': subscription.endpoint,
                                        'key': key,
                                        'authSecret': authSecret
                                    }, function (response) {
                                        if (response === '1') {
                                            respondWithError('OK', 'Subscription OK');
                                        } else {
                                            respondWithError('UNKNOWN4', 'Cannot subsribe on server');
                                        }
                                    });
                                };
                                serviceWorkerRegistration.pushManager.getSubscription()
                                        .then(function (subscription) {
                                            if (subscription) {
                                                onDone(subscription);
                                            } else {
                                                serviceWorkerRegistration.pushManager.subscribe({userVisibleOnly: true})
                                                        .then(function (subscription) {
                                                            onDone(subscription);
                                                        })
                                                        .catch(function (error) {
                                                            if (Notification.permission === 'denied') {
                                                                respondWithError('ACCESS_DENIED', 'Permission for Notifications was denied');
                                                            } else if (Notification.permission === 'default') {
                                                                respondWithError('NOT_SUBSCRIBED', 'Not subscribed yet');
                                                            } else {
                                                                respondWithError('UNKNOWN5', error);
                                                            }
                                                        });
                                            }
                                        })
                                        .catch(function (error) {
                                            respondWithError('UNKNOWN1', JSON.stringify(error));
                                        });
                            })
                                    .catch(function (error) {
                                        respondWithError('UNKNOWN2', JSON.stringify(error));
                                    });
                        })
                        .catch(function (error) {
                            respondWithError('UNKNOWN3', JSON.stringify(error));
                        });
            } else {
                respondWithError('NOT_SUPPORTED', 'Service workers aren\'t supported in this browser.');
            }
        });
    };

    return {
        'initialize': initialize,
        'getStatus': getStatus
    };

}());