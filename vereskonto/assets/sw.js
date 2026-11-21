/* Vereskala service worker — notifications and install support.
 *
 * Push messages arrive with no payload on purpose: the server only wakes
 * the browser, and the text is collected from the REST route below. That
 * keeps message encryption out of the plugin entirely, and it also means a
 * notification can never be shown with stale text.
 */

var VKL_PENDING = '/wp-json/vereskonto/v1/push/pending';

self.addEventListener('install', function () {
    // Take over straight away; there is no cache to warm.
    self.skipWaiting();
});

self.addEventListener('activate', function (event) {
    event.waitUntil(self.clients.claim());
});

self.addEventListener('push', function (event) {
    event.waitUntil(
        fetch(VKL_PENDING, { credentials: 'include' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) {
                var items = (data && data.items) || [];

                if (!items.length) {
                    // Browsers require a visible notification for every push,
                    // so show something honest rather than nothing.
                    return self.registration.showNotification(
                        (data && data.fallback_title) || 'ورسکالا',
                        { body: (data && data.fallback_body) || '', icon: (data && data.icon) || undefined }
                    );
                }

                return Promise.all(items.map(function (item) {
                    return self.registration.showNotification(item.title, {
                        body: item.body || '',
                        icon: (data && data.icon) || undefined,
                        badge: (data && data.badge) || undefined,
                        dir: 'rtl',
                        lang: 'fa',
                        tag: 'vkl-' + (item.url || ''),
                        data: { url: item.url || '/' }
                    });
                }));
            })
            .catch(function () {
                return self.registration.showNotification('ورسکالا', { body: '' });
            })
    );
});

self.addEventListener('notificationclick', function (event) {
    event.notification.close();

    var target = (event.notification.data && event.notification.data.url) || '/';

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (list) {
            // Reuse an open tab when there is one, rather than piling up
            // duplicates every time a notification is tapped.
            for (var i = 0; i < list.length; i++) {
                if (list[i].url === target && 'focus' in list[i]) {
                    return list[i].focus();
                }
            }

            return self.clients.openWindow ? self.clients.openWindow(target) : undefined;
        })
    );
});
