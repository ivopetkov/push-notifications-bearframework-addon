/*
 * Push Notifications addon for Bear Framework
 * https://github.com/ivopetkov/push-notifications-bearframework-addon
 * Copyright (c) Ivo Petkov
 * Free to use under the MIT license.
 */

self.addEventListener("push", function (event) {
    event.waitUntil(
        self.registration.pushManager.getSubscription().then(
            function (subscription) {
                return fetch("URL_TO_REPLACE?endpoint=" + encodeURIComponent(subscription.endpoint)).then(function (response) {
                    if (response.status === 200) {
                        return response.json().then(function (data) {
                            var promises = [];
                            var notificationsCount = data.length;
                            for (var i = 0; i < notificationsCount; i++) {
                                var notificationData = data[i];
                                var options = {};
                                if (typeof notificationData.body !== "undefined" && notificationData.body !== null) {
                                    var body = notificationData.body.toString();
                                    if (body.length > 0) {
                                        options["body"] = body;
                                    }
                                }
                                if (typeof notificationData.icon !== "undefined" && notificationData.icon !== null) {
                                    var icon = notificationData.icon.toString();
                                    if (icon.length > 0) {
                                        options["icon"] = icon;
                                    }
                                }
                                if (typeof notificationData.badge !== "undefined" && notificationData.badge !== null) {
                                    var badge = notificationData.badge.toString();
                                    if (badge.length > 0) {
                                        options["badge"] = badge;
                                    }
                                }
                                if (typeof notificationData.tag !== "undefined" && notificationData.tag !== null) {
                                    var tag = notificationData.tag.toString();
                                    if (tag.length > 0) {
                                        options["tag"] = tag;
                                    }
                                }
                                if (typeof notificationData.requireInteraction !== "undefined") {
                                    if (notificationData.requireInteraction === true) {
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
        if (clickUrl.length > 0) {
            event.waitUntil((async () => {
                const clients = await self.clients.matchAll({
                    type: 'window',
                    includeUncontrolled: true
                });
                for (const client of clients) {
                    if (client.url === clickUrl) {
                        await client.focus();
                        return;
                    }
                }
                await self.clients.openWindow(clickUrl);
            })());
        }
    }
});