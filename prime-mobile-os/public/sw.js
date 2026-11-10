/*
 * Prime Mobile — service worker of the ERP app on iPhone / laptop (TZ 03 §8.3: nothing with data is stored on the device).
 * Caches ONLY static files (/build, fonts, icons) and the «Нет связи» page. ERP pages and API answers are never cached:
 * they go to the network; when there is no network, a navigation shows /offline.html.
 */
const VERSION = 'pm-static-v1';
const OFFLINE = '/offline.html';

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(VERSION).then((c) => c.addAll([OFFLINE, '/icons/icon-192.png', '/icons/favicon.svg'])).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
    event.waitUntil(caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== VERSION).map((k) => caches.delete(k)))).then(() => self.clients.claim()));
});

self.addEventListener('fetch', (event) => {
    const req = event.request;
    if (req.method !== 'GET') return;
    const url = new URL(req.url);
    if (url.origin !== self.location.origin) return;

    if (req.mode === 'navigate') {
        event.respondWith(fetch(req).catch(() => caches.match(OFFLINE)));
        return;
    }
    if (url.pathname.startsWith('/build/') || url.pathname.startsWith('/icons/') || url.pathname.startsWith('/fonts/')) {
        event.respondWith(caches.match(req).then((hit) => hit || fetch(req).then((res) => {
            if (res.ok) { const copy = res.clone(); caches.open(VERSION).then((c) => c.put(req, copy)); }
            return res;
        })));
    }
});
