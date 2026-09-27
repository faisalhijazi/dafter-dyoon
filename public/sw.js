/*
 * حلول — service worker for offline entry.
 *
 * - Built assets / brand images: cache-first (their URLs are content-hashed or static).
 * - /app/offline: network-first, refreshed on every online visit so its account list stays current.
 * - Any page navigation that fails (no connection) falls back to the cached /app/offline page,
 *   where entries are queued on the device and synced once back online.
 *
 * Only GET requests are touched; Livewire updates, form posts and the sync call go to the network.
 */
const VERSION = 'dd-offline-v1';
const OFFLINE_URL = '/app/offline';
const STATIC = /^\/(build|brand|vendor\/fonts)\//;

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(VERSION)
            .then((cache) => fetch(OFFLINE_URL, { credentials: 'include' })
                .then((response) => (response.ok && !response.redirected ? cache.put(OFFLINE_URL, response) : null)))
            .catch(() => null)
            .then(() => self.skipWaiting()),
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((k) => k !== VERSION).map((k) => caches.delete(k))))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    const url = new URL(request.url);

    if (request.method !== 'GET' || url.origin !== self.location.origin) {
        return;
    }

    // Static assets: cache-first.
    if (STATIC.test(url.pathname)) {
        event.respondWith(
            caches.match(request).then((cached) => cached || fetch(request).then((response) => {
                if (response.ok) {
                    const copy = response.clone();
                    caches.open(VERSION).then((cache) => cache.put(request, copy));
                }
                return response;
            })),
        );
        return;
    }

    // The offline page itself: network-first, keep the freshest copy.
    if (url.pathname === OFFLINE_URL) {
        event.respondWith(
            fetch(request).then((response) => {
                if (response.ok && !response.redirected) {
                    const copy = response.clone();
                    caches.open(VERSION).then((cache) => cache.put(OFFLINE_URL, copy));
                }
                return response;
            }).catch(() => caches.match(OFFLINE_URL)),
        );
        return;
    }

    // Other page navigations: when the network fails, show the offline entry page.
    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request).catch(() => caches.match(OFFLINE_URL).then((cached) => cached || new Response(
                '<meta charset="utf-8"><body dir="rtl" style="font-family:sans-serif;padding:2rem">لا يوجد اتصال بالإنترنت. افتح التطبيق مرة واحدة وأنت متصل لتفعيل الإدخال بدون إنترنت.</body>',
                { headers: { 'Content-Type': 'text/html; charset=utf-8' } },
            ))),
        );
    }
});
