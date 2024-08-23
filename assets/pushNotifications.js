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

    var stringToArrayBuffer = function (string) {
        var length = string.length;
        var result = new Uint8Array(length);
        for (var i = 0; i < length; i++) {
            result[i] = string.charCodeAt(i);
        }
        return result.buffer;
    };

    var convertToBase64 = function (text) {
        var padding = '='.repeat((4 - text.length % 4) % 4);
        return (text + padding).replace(/\-/g, '+').replace(/_/g, '/');
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
                'catch': function () { }
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
            var respondWithError = function (error) {
                if (typeof error === "undefined") {
                    error = null;
                }
                respond('UNKNOWN', error === null ? 'Error' : 'Error (details: ' + error + ')');
            };
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
                                            return JSON.stringify(subscription);
                                        };
                                        var continueSubscribe = function () {
                                            clientPackages.get('serverRequests').then(function (serverRequests) {
                                                serverRequests.send('ivopetkov-push-notifications-get-vapid', {}).then(function (responseText) {
                                                    var response = JSON.parse(responseText);
                                                    serviceWorkerRegistration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: stringToArrayBuffer(atob(convertToBase64(response.vapidPublicKey))) })
                                                        .then(function (subscription) {
                                                            serverRequests.send('ivopetkov-push-notifications-subscribe', {
                                                                'subscription': getSubscriptionServerData(subscription),
                                                                'vapidPublicKey': response.vapidPublicKey,
                                                                'vapidPrivateKey': response.vapidPrivateKey,
                                                                'subscriberKey': subscriberKey
                                                            }).then(function (responseText) {
                                                                var response = JSON.parse(responseText);
                                                                if (typeof response.status !== 'undefined' && response.status === 'ok') {
                                                                    respond('SUBSCRIBED', 'The subscription is OK!', { 'subscriptionID': response.subscriptionID });
                                                                } else {
                                                                    respondWithError();
                                                                }
                                                            }).catch(function (error) { respondWithError(error); });
                                                        })
                                                        .catch(function (error) {
                                                            if (Notification.permission === 'denied') {
                                                                respond('DENIED', 'The user has blocked notifications!');
                                                            } else if (Notification.permission === 'default') {
                                                                respond('NOT_SUBSCRIBED', 'Not subscribed yet!');
                                                            } else {
                                                                respondWithError(error);
                                                            }
                                                        });
                                                }).catch(function (error) { respondWithError(error); });
                                            }).catch(function (error) { respondWithError(error); });
                                        };
                                        if (subscription) {
                                            if (mode === 'getStatus') {
                                                clientPackages.get('serverRequests').then(function (serverRequests) {
                                                    serverRequests.send('ivopetkov-push-notifications-getid', {
                                                        'subscription': getSubscriptionServerData(subscription),
                                                        'subscriberKey': subscriberKey
                                                    }).then(function (responseText) {
                                                        var response = JSON.parse(responseText);
                                                        if (typeof response.status !== 'undefined' && response.status === 'ok') {
                                                            if (response.subscriptionID === null) {
                                                                subscription.unsubscribe().then(function () {
                                                                    respond('NOT_SUBSCRIBED', 'Not subscribed yet!');
                                                                }).catch(function (error) { respondWithError(error); });
                                                            } else {
                                                                respond('SUBSCRIBED', 'The subscription is OK!', { 'subscriptionID': response.subscriptionID });
                                                            }
                                                        } else {
                                                            respondWithError();
                                                        }
                                                    }).catch(function (error) { respondWithError(error); });
                                                }).catch(function (error) { respondWithError(error); });
                                            } else if (mode === 'subscribe') {
                                                clientPackages.get('serverRequests').then(function (serverRequests) {
                                                    serverRequests.send('ivopetkov-push-notifications-getid', {
                                                        'subscription': getSubscriptionServerData(subscription),
                                                        'subscriberKey': subscriberKey
                                                    }).then(function (responseText) {
                                                        var response = JSON.parse(responseText);
                                                        if (typeof response.status !== 'undefined' && response.status === 'ok') {
                                                            if (response.subscriptionID === null) {
                                                                subscription.unsubscribe().then(function () {
                                                                    continueSubscribe();
                                                                }).catch(function (error) { respondWithError(error); });
                                                            } else {
                                                                respond('SUBSCRIBED', 'The subscription is OK!', { 'subscriptionID': response.subscriptionID });
                                                            }
                                                        } else {
                                                            respondWithError();
                                                        }
                                                    }).catch(function (error) { respondWithError(error); });
                                                }).catch(function (error) { respondWithError(error); });
                                            } else if (mode === 'unsubscribe') {
                                                subscription.unsubscribe().then(function (successful) {
                                                    if (successful) {
                                                        clientPackages.get('serverRequests').then(function (serverRequests) {
                                                            serverRequests.send('ivopetkov-push-notifications-unsubscribe', {
                                                                'subscription': getSubscriptionServerData(subscription),
                                                                'subscriberKey': subscriberKey
                                                            }).then(function (responseText) {
                                                                var response = JSON.parse(responseText);
                                                                if (typeof response.status !== 'undefined' && response.status === 'ok') {
                                                                    respond('UNSUBSCRIBED', 'Unsubscribe successful!');
                                                                } else {
                                                                    respondWithError();
                                                                }
                                                            }).catch(function (error) { respondWithError(error); });
                                                        }).catch(function (error) { respondWithError(error); });
                                                    } else {
                                                        respondWithError();
                                                    }
                                                }).catch(function (error) { respondWithError(error); });
                                            }
                                        } else {
                                            if (mode === 'getStatus') {
                                                if (Notification.permission === 'denied') {
                                                    respond('DENIED', 'The user has blocked notifications!');
                                                } else if (Notification.permission === 'default' || Notification.permission === 'granted') {
                                                    respond('NOT_SUBSCRIBED', 'Not subscribed yet!');
                                                } else {
                                                    respondWithError();
                                                }
                                            } else if (mode === 'subscribe') {
                                                continueSubscribe();
                                            } else if (mode === 'unsubscribe') {
                                                respondWithError();
                                            }
                                        }
                                    }).catch(function (error) { respondWithError(error); });
                            }).catch(function (error) { respondWithError(error); });
                        }).catch(function (error) { respondWithError(error); });
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