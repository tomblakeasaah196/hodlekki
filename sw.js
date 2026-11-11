// /sw.js
// ===========================================================================
// HOD Lekki — service worker
// ---------------------------------------------------------------------------
// Registered at the root so its scope is the whole site. Four jobs:
//
//   1. Receive push messages and show them, even with the app closed.
//   2. Make the shell instant: the front end under /assets/ui/ is content-
//      hashed (?v=…), so it is served from the cache first and fetched once.
//   3. Keep a READ-ONLY copy of the few pages a member most recently opened,
//      so they can still be read with no signal (owner's decision, Nov 2026).
//   4. Show a branded offline page instead of the browser's dinosaur.
//
// WHAT THIS NEVER CACHES, AND HOW THE PAGE COPIES ARE KEPT SAFE
// -------------------------------------------------------------
// Pages are rendered against a PHP session and full of member data, and
// Cache Storage outlives sign-out. So a page is kept ONLY when the server
// marked it with `X-HOD-Offline: snapshot; u=<session key>` (includes/ui/
// shell.php: rebuilt pages only, never Security, Roles, Finance, Alerts,
// Requisitions, SMS Studio). The copies:
//   * live in one cache per signed-in session (hod-pages-<key>); a different
//     session's key wipes the old cache before anything is stored;
//   * are capped at PAGES_MAX pages and PAGES_MAX_AGE_MS old;
//   * are wiped on every navigation to /auth/ (sign-in, sign-out, a revoked
//     or expired session lands there) and when a page is answered with a
//     redirect to /auth/. (Not Clear-Site-Data: its "storage" type, the only
//     one that reaches Cache Storage, also unregisters this worker and with
//     it every push subscription.)
//   * are served ONLY when the network fails or hangs, stamped with
//     data-ui-snapshot so the page shows the offline banner and refuses
//     every action (assets/ui/js/ui.js).
//
// Passed straight through, untouched: /api/, /live/, /e/, /webhook/,
// /auth/ and /uploads/. Special Events polls /live/*.json for real-time
// state and serves /e/ under its own strict CSP; a cache in front of either
// is a bug, not a feature.
// ===========================================================================

const VERSION = 'v2.0.0';
const STATIC_CACHE = `hod-static-${VERSION}`;
const VENDOR_CACHE = `hod-vendor-${VERSION}`;
// Content-hashed files: one entry per path (the newest), kept across versions.
const UI_CACHE = 'hod-ui-v1';
const PAGES_PREFIX = 'hod-pages-';
const PAGES_MAX = 12;
const PAGES_MAX_AGE_MS = 72 * 60 * 60 * 1000;
const PAGE_TIMEOUT_MS = 8000;
const OFFLINE_URL = '/offline.html';

// The few files the offline page and the first paint need. Kept small on
// purpose: a precache that fails to fetch one entry fails the whole install.
const PRECACHE = [
    OFFLINE_URL,
    '/assets/pwa/icon-192.png',
    '/assets/pwa/icon-96.png',
    '/assets/pwa/badge-96.png',
];
const PRECACHE_UI = [
    '/assets/ui/fonts/inter-latin.v1.woff2',
    '/assets/ui/fonts/montserrat-latin.v1.woff2',
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

// Third-party libraries the not-yet-rebuilt standalone pages still load
// from a CDN (the members' app itself loads nothing from these any more).
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
        Promise.all([
            caches.open(STATIC_CACHE).then((cache) => cache.addAll(PRECACHE)),
            caches.open(UI_CACHE).then((cache) => cache.addAll(PRECACHE_UI)),
        ])
            // A missing precache entry must not stop the worker installing;
            // without this, one renamed icon takes push notifications down.
            .catch(() => undefined)
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    const keep = [STATIC_CACHE, VENDOR_CACHE, UI_CACHE];
    event.waitUntil(
        caches
            .keys()
            .then((names) =>
                Promise.all(
                    names
                        .filter((name) => name.startsWith('hod-') && !keep.includes(name) && !name.startsWith(PAGES_PREFIX))
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
    if (event.data && event.data.type === 'HOD_WIPE_PAGES') {
        event.waitUntil(wipePages());
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

/**
 * /assets/ui/*: the URL changes whenever a byte does (?v=<hash>), so the
 * cached copy is always right. Older versions of the same file are dropped.
 */
async function cacheFirstVersioned(request) {
    const cache = await caches.open(UI_CACHE);
    const hit = await cache.match(request);
    if (hit) return hit;

    const response = await fetch(request);
    if (response && response.ok && response.type === 'basic') {
        const url = new URL(request.url);
        const keys = await cache.keys();
        await Promise.all(
            keys
                .filter((key) => {
                    const old = new URL(key.url);
                    return old.pathname === url.pathname && old.search !== url.search;
                })
                .map((key) => cache.delete(key))
        );
        cache.put(request, response.clone()).catch(() => undefined);
    }
    return response;
}

/* ---------- read-only page copies ---------- */

async function wipePages() {
    const names = await caches.keys();
    await Promise.all(names.filter((name) => name.startsWith(PAGES_PREFIX)).map((name) => caches.delete(name)));
}

function pageKey(request) {
    const url = new URL(request.url);
    url.hash = '';
    return url.href;
}

async function savePage(request, response, mark) {
    const match = /u=([A-Za-z0-9_-]{8,64})/.exec(mark);
    if (!match) return;
    const cacheName = PAGES_PREFIX + match[1];

    // Another session's copies never sit beside this one's.
    const names = await caches.keys();
    await Promise.all(
        names.filter((name) => name.startsWith(PAGES_PREFIX) && name !== cacheName).map((name) => caches.delete(name))
    );

    const savedAt = Date.now();
    let html = await response.text();
    html = html.replace(/<html\b/i, `<html data-ui-snapshot="${new Date(savedAt).toISOString()}"`);

    const cache = await caches.open(cacheName);
    await cache.put(
        pageKey(request),
        new Response(html, {
            status: 200,
            headers: { 'Content-Type': 'text/html; charset=utf-8', 'X-HOD-Saved-At': String(savedAt) },
        })
    );

    // Keep only the most recent PAGES_MAX.
    const keys = await cache.keys();
    if (keys.length > PAGES_MAX) {
        const dated = await Promise.all(
            keys.map(async (key) => {
                const r = await cache.match(key);
                return { key, at: Number((r && r.headers.get('X-HOD-Saved-At')) || 0) };
            })
        );
        dated.sort((a, b) => a.at - b.at);
        await Promise.all(dated.slice(0, dated.length - PAGES_MAX).map((d) => cache.delete(d.key)));
    }
}

async function readPage(request) {
    const names = (await caches.keys()).filter((name) => name.startsWith(PAGES_PREFIX));
    for (const name of names) {
        const cache = await caches.open(name);
        const hit = await cache.match(pageKey(request));
        if (!hit) continue;
        const age = Date.now() - Number(hit.headers.get('X-HOD-Saved-At') || 0);
        if (age > PAGES_MAX_AGE_MS) {
            await cache.delete(pageKey(request));
            return null;
        }
        return hit;
    }
    return null;
}

/**
 * Navigations go to the network first, every time. The saved copy is used
 * only when the network fails, or hangs past PAGE_TIMEOUT_MS on a weak
 * signal; otherwise the offline page.
 */
async function navigate(event) {
    const request = event.request;
    const network = fetch(request).then((response) => {
        const mark = response.headers.get('X-HOD-Offline') || '';
        if (response.ok && response.type === 'basic' && !response.redirected && mark.startsWith('snapshot')) {
            event.waitUntil(savePage(request, response.clone(), mark).catch(() => undefined));
        }
        // Sent to sign in: the session ended or was revoked. Its copies go.
        if (response.redirected && new URL(response.url).pathname.startsWith('/auth/')) {
            event.waitUntil(wipePages());
        }
        return response;
    });
    network.catch(() => undefined);

    try {
        const timeout = new Promise((resolve) => setTimeout(resolve, PAGE_TIMEOUT_MS, 'timeout'));
        const first = await Promise.race([network, timeout]);
        if (first !== 'timeout') return first;
        return (await readPage(request)) || (await network);
    } catch (error) {
        return (
            (await readPage(request)) ||
            (await caches.match(OFFLINE_URL)) ||
            new Response('Offline', { status: 503, headers: { 'Content-Type': 'text/plain' } })
        );
    }
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
        // Signing in or out (or being sent to sign in): no page copy survives.
        if (request.mode === 'navigate' && url.pathname.startsWith('/auth/')) {
            event.waitUntil(wipePages());
            return;
        }

        if (isBypassed(url)) return;

        // Navigations: network first; a saved read-only copy only offline.
        if (request.mode === 'navigate') {
            event.respondWith(navigate(event));
            return;
        }

        if (url.pathname.startsWith('/assets/ui/')) {
            event.respondWith(cacheFirstVersioned(request));
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
