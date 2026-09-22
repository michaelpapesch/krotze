/* Krotze service worker: offline shell cache + notifications */
const CACHE = 'krotze-v3';

self.addEventListener('install', (e) => {
    self.skipWaiting();
});

self.addEventListener('activate', (e) => {
    e.waitUntil((async () => {
        const keys = await caches.keys();
        await Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k)));
        await self.clients.claim();
    })());
});

self.addEventListener('fetch', (e) => {
    const url = new URL(e.request.url);
    if (e.request.method !== 'GET' || url.origin !== location.origin) return;
    // Never cache API or uploads (uploads are ephemeral capability URLs)
    if (url.pathname.startsWith('/api/') || url.pathname.startsWith('/storage/')
        || url.pathname.startsWith('/u/')) return;

    // Static build assets + images: cache-first
    if (url.pathname.startsWith('/build/') || url.pathname.startsWith('/img/')) {
        e.respondWith((async () => {
            const cached = await caches.match(e.request);
            if (cached) return cached;
            const res = await fetch(e.request);
            if (res.ok) (await caches.open(CACHE)).put(e.request, res.clone());
            return res;
        })());
        return;
    }

    // Pages: network-first with cache fallback (offline support)
    e.respondWith((async () => {
        try {
            const res = await fetch(e.request);
            if (res.ok && e.request.mode === 'navigate') {
                (await caches.open(CACHE)).put(e.request, res.clone());
            }
            return res;
        } catch {
            const cached = await caches.match(e.request);
            return cached || caches.match('/app');
        }
    })());
});

/* A notification pushed by the server — arrives even with the app closed. The
   payload was encrypted for this device, so nothing along the way could read
   it. Tags match the ones the app uses for poll-raised notifications, so the
   two collapse into one card instead of stacking. */
self.addEventListener('push', (e) => {
    let data = {};
    try { data = e.data ? e.data.json() : {}; } catch { /* malformed payload */ }
    const title = data.title || 'Krotze';
    e.waitUntil(self.registration.showNotification(title, {
        body: data.body || '',
        icon: '/img/icon-192.png',
        // Android draws the status-bar badge from the alpha channel alone and
        // tints it, so this one is a monochrome silhouette of the same bubble.
        // Handing it the full-colour icon yields a featureless white blob.
        badge: '/img/badge-96.png',
        tag: data.tag,
        // `origin` is set when this server relayed the notification for
        // another Krotze server the app is also signed in to.
        data: { uuid: data.uuid || null, origin: data.origin || null },
    }));
});

/* Push services rotate endpoints on their own schedule. When that happens the
   old subscription stops working, so tell every open client to register the
   new one; if none is open, the app re-subscribes on its next launch. */
self.addEventListener('pushsubscriptionchange', (e) => {
    e.waitUntil((async () => {
        const clients = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
        for (const c of clients) c.postMessage({ type: 'push-resubscribe' });
    })());
});

self.addEventListener('notificationclick', (e) => {
    e.notification.close();
    // Message notifications name their channel, so the tap can land in it —
    // and the server it lives on, when that is not this one.
    const uuid = e.notification.data && e.notification.data.uuid;
    const origin = e.notification.data && e.notification.data.origin;
    const url = uuid ? `/app#c=${uuid}${origin ? '&s=' + encodeURIComponent(origin) : ''}` : '/app';
    e.waitUntil((async () => {
        const clients = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
        for (const c of clients) {
            if (!c.url.includes('/app')) continue;
            // Same document, only the hash changes — the app picks it up via
            // hashchange rather than reloading.
            const target = uuid && 'navigate' in c
                ? await c.navigate(url).catch(() => c) || c
                : c;
            await target.focus();
            return;
        }
        self.clients.openWindow(url);
    })());
});
