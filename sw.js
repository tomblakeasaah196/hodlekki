// /sw.js
// ===========================================================================
// HOD Lekki — service worker
// ---------------------------------------------------------------------------
// Registered at the root so its scope is the whole site. Three jobs:
//
//   1. Receive push messages and show them, even with the app closed.
//   2. Make the shell feel instant by caching STATIC assets only.
//   3. Show a branded offline page instead of the browser's dinosaur.
//
// WHAT THIS DELIBERATELY NEVER CACHES
// -----------------------------------
// HTML. Every page on this site is rendered against a PHP session and is full
// of member data — names, phone numbers, finance figures, follow-up notes.
// Cache Storage is readable by anything else running on the origin and
// survives sign-out, so a cached dashboard is a data leak with a long tail.
// Navigations go to the network, every time, and fall back to a static
// offline page that contains nothing.
//
// Also passed straight through, untouched: /api/, /live/, /e/, /webhook/,
// /auth/ and /uploads/. The Special Events module polls /live/*.json for
// real-time state and serves /e/ under its own strict CSP; a well-meaning
// cache in front of either is a bug, not a feature.
// ===========================================================================

const VERSION = 'v1.0.0';
const STATIC_CACHE = `hod-static-${VERSION}`;
const VENDOR_CACHE = `hod-vendor-${VERSION}`;
const OFFLINE_URL = '/offline.html';

// The few files the offline page itself needs. Kept small on purpose: a
// precache that fails to fetch one entry fails the whole install.
const PRECACHE = [
    OFFLINE_URL,
    '/assets/pwa/icon-192.png',
    '/assets/pwa/badge-96.png',
];

// Same-origin prefixes that are safe to serve from cache.
const CACHEABLE_PREFIXES = [
    '/assets/pwa/',
    '/assets/images/',
    '/assets/css/',
    '/assets/js/',
    '/assets/se/',
    '/favicon.ico',
];

// Never touch these, whatever else the rules say.
const BYPASS_PREFIXES = [
    '/api/',
    '/auth/',
    '/cron/',
    '/e/',
    '/live/',
    '/uploads/',
    '/webhook/',
    '/sw.js',
    '/manifest.php',
];

// Third-party libraries the pages load from a CDN. Caching these is the
// single biggest perceived-speed win on a Nigerian mobile connection —
// Tailwind alone is several hundred KB on every page load.
const VENDOR_HOSTS = [
    'cdn.tailwindcss.com',
    'code.jquery.com',
    'cdn.jsdelivr.net',
    'fonts.googleapis.com',
    'fonts.gstatic.com',
];

/* ------------------------------------------------------------------ *
 * Lifecycle
 * ------------------------------------------------------------------ */

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches
            .open(STATIC_CACHE)
            .then((cache) => cache.addAll(PRECACHE))
            // A missing precache entry must not stop the worker installing;
            // without this, one renamed icon takes push notifications down.
            .catch(() => undefined)
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches
            .keys()
            .then((names) =>
                Promise.all(
                    names
                        .filter((name) => name.startsWith('hod-') && name !== STATIC_CACHE && name !== VENDOR_CACHE)
                        .map((name) => caches.delete(name))
                )
            )
            .then(() => self.clients.claim())
    );
});

self.addEventListener('message', (event) => {
    if (event.data && event.data.type === 'SKIP_WAITING') {
        self.skipWaiting();
    }
});

/* ------------------------------------------------------------------ *
 * Fetch
 * ------------------------------------------------------------------ */

function isBypassed(url) {
    return BYPASS_PREFIXES.some((prefix) => url.pathname.startsWith(prefix));
}

function isCacheableAsset(url) {
    return CACHEABLE_PREFIXES.some((prefix) => url.pathname.startsWith(prefix));
}

/**
 * Serve from cache, refresh in the background. The member sees the cached
 * copy immediately and gets the new one on their next visit.
 */
async function staleWhileRevalidate(request, cacheName) {
    const cache = await caches.open(cacheName);
    const cached = await cache.match(request);

    const network = fetch(request)
        .then((response) => {
            // Opaque (no-cors) responses have status 0 and cannot be checked;
            // storing one would poison the cache with a possible error page.
            if (response && response.status === 200 && response.type !== 'opaque') {
                cache.put(request, response.clone()).catch(() => undefined);
            }
            return response;
        })
        .catch(() => undefined);

    return cached || network || fetch(request);
}

self.addEventListener('fetch', (event) => {
    const request = event.request;

    if (request.method !== 'GET') return;

    let url;
    try {
        url = new URL(request.url);
    } catch (error) {
        return;
    }

    if (url.protocol !== 'https:' && url.protocol !== 'http:') return;

    if (url.origin === self.location.origin) {
        if (isBypassed(url)) return;

        // Navigations: network only. Nothing authenticated is ever stored.
        if (request.mode === 'navigate') {
            event.respondWith(
                fetch(request).catch(() =>
                    caches.match(OFFLINE_URL).then((cached) => cached || new Response('Offline', { status: 503 }))
                )
            );
            return;
        }

        if (isCacheableAsset(url)) {
            event.respondWith(staleWhileRevalidate(request, STATIC_CACHE));
        }
        return;
    }

    if (VENDOR_HOSTS.includes(url.hostname)) {
        event.respondWith(staleWhileRevalidate(request, VENDOR_CACHE));
    }
});

/* ------------------------------------------------------------------ *
 * Push
 * ------------------------------------------------------------------ */

function parsePush(event) {
    const fallback = {
        title: 'HOD Lekki',
        body: 'You have a new notification.',
        url: '/index.php',
        tag: 'hod-notif',
        badge: 0,
    };

    if (!event.data) return fallback;

    try {
        const data = event.data.json();
        return {
            title: data.title || fallback.title,
            body: data.body || '',
            url: data.url || fallback.url,
            tag: data.tag || fallback.tag,
            badge: typeof data.badge === 'number' ? data.badge : 0,
            nid: data.nid || null,
            urgent: data.urgent === true,
        };
    } catch (error) {
        // A payload we cannot read is still a signal that something happened.
        return { ...fallback, body: event.data.text() || fallback.body };
    }
}

self.addEventListener('push', (event) => {
    const message = parsePush(event);

    const options = {
        body: message.body,
        icon: '/assets/pwa/icon-192.png',
        badge: '/assets/pwa/badge-96.png',
        tag: message.tag,
        // Each notification is its own tag, so this only matters when the same
        // one is re-sent after a retry — alert them again rather than silently
        // swapping it out.
        renotify: true,
        requireInteraction: message.urgent === true,
        vibrate: message.urgent ? [60, 40, 60, 40, 120] : [40, 30, 40],
        timestamp: Date.now(),
        data: { url: message.url, nid: message.nid },
        actions: [{ action: 'open', title: 'Open' }, { action: 'dismiss', title: 'Dismiss' }],
    };

    event.waitUntil(
        (async () => {
            await self.registration.showNotification(message.title, options);

            // The unread count on the home-screen icon.
            if (self.navigator && 'setAppBadge' in self.navigator) {
                try {
                    if (message.badge > 0) {
                        await self.navigator.setAppBadge(message.badge);
                    } else {
                        await self.navigator.clearAppBadge();
                    }
                } catch (error) {
                    /* badging is a nicety, never a failure */
                }
            }

            // If a tab is open, let it refresh its bell without a round trip.
            const clients = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
            clients.forEach((client) => client.postMessage({ type: 'PUSH_RECEIVED', badge: message.badge }));
        })()
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    if (event.action === 'dismiss') return;

    const target = (event.notification.data && event.notification.data.url) || '/index.php';

    event.waitUntil(
        (async () => {
            const clients = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });

            // Reuse the app window if it is already open — opening a second
            // one is the clearest way to make a PWA feel like a web page.
            for (const client of clients) {
                if ('focus' in client) {
                    await client.focus();
                    if ('navigate' in client) {
                        try {
                            await client.navigate(target);
                            return;
                        } catch (error) {
                            /* fall through to openWindow */
                        }
                    }
                    client.postMessage({ type: 'NAVIGATE', url: target });
                    return;
                }
            }

            if (self.clients.openWindow) {
                await self.clients.openWindow(target);
            }
        })()
    );
});

/**
 * Push services rotate subscriptions on their own schedule. Without this the
 * member silently stops receiving anything and nobody finds out for weeks.
 */
self.addEventListener('pushsubscriptionchange', (event) => {
    event.waitUntil(
        (async () => {
            const old = event.oldSubscription || (await self.registration.pushManager.getSubscription());
            const applicationServerKey =
                (event.newSubscription && event.newSubscription.options.applicationServerKey) ||
                (old && old.options && old.options.applicationServerKey);

            if (!applicationServerKey) return;

            const fresh =
                event.newSubscription ||
                (await self.registration.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey,
                }));

            const json = fresh.toJSON();

            await fetch('/api/push_api.php', {
                method: 'POST',
                credentials: 'include',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'subscribe',
                    endpoint: fresh.endpoint,
                    p256dh: json.keys && json.keys.p256dh,
                    auth: json.keys && json.keys.auth,
                }),
            }).catch(() => undefined);
        })()
    );
});
