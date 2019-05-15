/*
 * Push Notifications addon for Bear Framework
 * https://github.com/ivopetkov/push-notifications-bearframework-addon
 * Copyright (c) Ivo Petkov
 * Free to use under the MIT license.
 */

/* global clientPackages */

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

    var getSubscription = function (mode) {
        if (typeof Promise === 'undefined') {
            return {
                'then': function (resolve) {
                    var status = {
                        'code': 'NOT_SUPPORTED',
                        'message': 'This browser does not support promises (and other notifications related technologies).'
                    };
                    resolve(status);
                },
                'catch': function () {}
            };
        }
        return new Promise(function (resolve, reject) {
            var respond = function (code, message, data) {
                var status = {
                    'code': code,
                    'message': message
                };
                if (typeof data !== 'undefined') {
                    for (var k in data) {
                        status[k] = data[k];
                    }
                }
                resolve(status);
            };
            if (subscriberKey === null || subscriberKey.length === 0) {
                respond('NO_SUBSCRIBER', 'No subscriber set on the server!');
            }
            if ('serviceWorker' in navigator) {
                var interval = window.setInterval(function () { // Wait for document to load. In Chrome the following code works inconsistently while the document is loading.
                    if (document.readyState !== 'complete') {
                        return;
                    }
                    window.clearInterval(interval);
                    navigator.serviceWorker.register(serviceWorkerFilePath)
                            .then(function () {
                                if (!('showNotification' in ServiceWorkerRegistration.prototype)) {
                                    respond('NOT_SUPPORTED', 'Notifications aren\'t supported in this browser!');
                                    return;
                                }
                                if (Notification.permission === 'denied') {
                                    respond('DENIED', 'The user has blocked notifications.');
                                    return;
                                }
                                if (!('PushManager' in window)) {
                                    respond('NOT_SUPPORTED', 'Push messaging isn\'t supported in this browser!.');
                                    return;
                                }
                                navigator.serviceWorker.ready.then(function (serviceWorkerRegistration) {
                                    serviceWorkerRegistration.pushManager.getSubscription()
                                            .then(function (subscription) {
                                                var getSubscriptionServerData = function (subscription) {
                                                    var endpoint = subscription.endpoint;
                                                    var rawKey = subscription.getKey ? subscription.getKey('p256dh') : '';
                                                    var key = rawKey ? btoa(String.fromCharCode.apply(null, new Uint8Array(rawKey))) : '';
                                                    var rawAuthSecret = subscription.getKey ? subscription.getKey('auth') : '';
                                                    var authSecret = rawAuthSecret ? btoa(String.fromCharCode.apply(null, new Uint8Array(rawAuthSecret))) : '';
                                                    var data = {
                                                        'endpoint': subscription.endpoint,
                                                        'key': key,
                                                        'authSecret': authSecret
                                                    };
                                                    return JSON.stringify(data);
                                                };
                                                var onDone = function (subscription) {
                                                    clientPackages.get('serverRequests').then(function (serverRequests) {
                                                        serverRequests.send('ivopetkov-push-notifications-subscribe', {
                                                            'subscription': getSubscriptionServerData(subscription),
                                                            'subscriberKey': subscriberKey
                                                        }).then(function (responseText) {
                                                            response = JSON.parse(responseText);
                                                            if (typeof response.status !== 'undefined' && response.status === 'ok') {
                                                                respond('SUBSCRIBED', 'The subscription is OK!', {'subscriptionID': response.subscriptionID});
                                                            } else {
                                                                respond('UNKNOWN', 'Cannot subsribe on the server!');
                                                            }
                                                        });
                                                    });
                                                };
                                                if (subscription) {
                                                    if (mode === 'getStatus' || mode === 'subscribe') {
                                                        onDone(subscription);
                                                    } else if (mode === 'unsubscribe') {
                                                        var subscriptionServerData = getSubscriptionServerData(subscription);
                                                        subscription.unsubscribe().then(function (successful) {
                                                            if (successful) {
                                                                clientPackages.get('serverRequests').then(function (serverRequests) {
                                                                    serverRequests.send('ivopetkov-push-notifications-unsubscribe', {
                                                                        'subscription': subscriptionServerData,
                                                                        'subscriberKey': subscriberKey
                                                                    }).then(function (responseText) {
                                                                        response = JSON.parse(responseText);
                                                                        if (typeof response.status !== 'undefined' && response.status === 'ok') {
                                                                            respond('UNSUBSCRIBED', 'Unsubscribe successful!');
                                                                        } else {
                                                                            respond('UNKNOWN', 'Cannot unsubsribe from the server!');
                                                                        }
                                                                    });
                                                                });
                                                            } else {
                                                                respond('UNKNOWN', 'Unknown unsubscribe error!');
                                                            }
                                                        }).catch(function (e) {
                                                            respond('UNKNOWN', 'Unknown unsubscribe error!');
                                                        })
                                                    }
                                                } else {
                                                    if (mode === 'getStatus') {
                                                        if (Notification.permission === 'denied') {
                                                            respond('DENIED', 'The user has blocked notifications!');
                                                        } else if (Notification.permission === 'default' || Notification.permission === 'granted') {
                                                            respond('NOT_SUBSCRIBED', 'Not subscribed yet!');
                                                        } else {
                                                            respond('UNKNOWN', 'Unknown error (unknown status)!');
                                                        }
                                                    } else if (mode === 'subscribe') {
                                                        serviceWorkerRegistration.pushManager.subscribe({userVisibleOnly: true})
                                                                .then(function (subscription) {
                                                                    onDone(subscription);
                                                                })
                                                                .catch(function (error) {
                                                                    if (Notification.permission === 'denied') {
                                                                        respond('DENIED', 'The user has blocked notifications!');
                                                                    } else if (Notification.permission === 'default') {
                                                                        respond('NOT_SUBSCRIBED', 'Not subscribed yet!');
                                                                    } else {
                                                                        respond('UNKNOWN', 'Unknown error (details: ' + error + ')!');
                                                                    }
                                                                });
                                                    } else if (mode === 'unsubscribe') {
                                                        respond('UNKNOWN', 'Subscription not found!');
                                                    }
                                                }
                                            })
                                            .catch(function (error) {
                                                respond('UNKNOWN', 'Unknown error (details: ' + JSON.stringify(error) + ')!');
                                            });
                                })
                                        .catch(function (error) {
                                            respond('UNKNOWN', 'Unknown error (details: ' + JSON.stringify(error) + ')!');
                                        });
                            })
                            .catch(function (error) {
                                respond('UNKNOWN', 'Unknown error (details: ' + JSON.stringify(error) + ')!');
                            });
                }, 100);
            } else {
                respond('NOT_SUPPORTED', 'Service workers aren\'t supported in this browser!');
            }
        });
    };

    var getStatus = function () {
        return getSubscription('getStatus');
    };

    var subscribe = function () {
        return getSubscription('subscribe');
    };

    var unsubscribe = function () {
        return getSubscription('unsubscribe');
    };

    return {
        'initialize': initialize,
        'getStatus': getStatus,
        'subscribe': subscribe,
        'unsubscribe': unsubscribe,
    };

}());