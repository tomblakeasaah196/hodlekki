/* /assets/js/pwa.js
 * ===========================================================================
 * HOD Lekki — app runtime
 * ---------------------------------------------------------------------------
 * Vanilla JS, no dependencies: this file loads on public pages that have no
 * jQuery and inside the ERP shell that does, so it can assume neither.
 *
 * What it does
 *   · takes the launch screen away once the page is usable
 *   · registers the service worker and offers the update when one lands
 *   · Android/desktop: catches beforeinstallprompt and offers a real install
 *   · iPhone/iPad: there is no install event, so it walks the member through
 *     Share -> Add to Home Screen, with an arrow pointing at the right button
 *   · asks for notification permission at a moment that makes sense, then
 *     subscribes the device to Web Push and registers it with the server
 *   · an alerts sheet: on/off per device, a test push, quiet hours, devices
 *   · the small things that make a multi-page PHP app feel like an app —
 *     a route progress bar, pull to refresh, safe areas, the icon badge
 *
 * Public surface (for other scripts and for onclick= attributes):
 *   window.hodApp.openAlerts()     open the alert settings sheet
 *   window.hodApp.promptInstall()  install, or the iOS guide, as appropriate
 *   window.hodApp.toast(msg, kind) a branded toast
 *   window.hodApp.refreshBadge()   re-read the unread count
 * Any element with data-hod-alerts or data-hod-install is wired automatically.
 * ===========================================================================
 */

(function () {
    'use strict';

    var CFG = window.HOD_PWA || {};
    var API_PUSH = CFG.pushApi || '/api/push_api.php';

    /*
     * "Quiet" pages are the ones doing something time-critical — a live event
     * console under /e/, a check-in desk. They still install, still register
     * the service worker, still RECEIVE push. What they do not do is
     * interrupt: no install banner, no opt-in sheet, and no pull-to-refresh,
     * because a downward swipe mid-game must not reload the page out from
     * under a crew member. Set from includes/pwa_head.php ($pwaQuiet).
     */
    var QUIET = CFG.quiet === true ||
        document.documentElement.getAttribute('data-hod-quiet') === '1';

    /* ================================================================== *
     * Environment
     * ================================================================== */

    var ua = navigator.userAgent || '';

    var env = {
        iOS: /iPad|iPhone|iPod/.test(ua) ||
            // iPadOS 13+ reports itself as a Mac; the touch points give it away.
            (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1),
        android: /Android/.test(ua),
        standalone: window.matchMedia('(display-mode: standalone)').matches ||
            window.navigator.standalone === true,
        // Facebook / Instagram / TikTok in-app browsers cannot install anything,
        // so nagging someone there is pure noise.
        inAppBrowser: /FBAN|FBAV|Instagram|Line\/|Twitter|TikTok|WebView/i.test(ua),
        supportsSW: 'serviceWorker' in navigator,
        supportsPush: 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window
    };

    env.iPad = env.iOS && (/iPad/.test(ua) || navigator.maxTouchPoints > 1 && navigator.platform === 'MacIntel');

    /* ================================================================== *
     * Small helpers
     * ================================================================== */

    /** localStorage that cannot throw. Private mode and locked-down WebViews
     *  both make plain access a runtime error. */
    var store = {
        get: function (key) { try { return localStorage.getItem('hod:' + key); } catch (e) { return null; } },
        set: function (key, value) { try { localStorage.setItem('hod:' + key, String(value)); } catch (e) {} },
        drop: function (key) { try { localStorage.removeItem('hod:' + key); } catch (e) {} },
        /** Has this prompt been dismissed within the last `days`? */
        snoozed: function (key, days) {
            var at = parseInt(store.get(key) || '0', 10);
            return at > 0 && (Date.now() - at) < days * 86400000;
        },
        snooze: function (key) { store.set(key, Date.now()); }
    };

    function el(tag, attrs, html) {
        var node = document.createElement(tag);
        if (attrs) {
            Object.keys(attrs).forEach(function (key) { node.setAttribute(key, attrs[key]); });
        }
        if (html != null) node.innerHTML = html;
        return node;
    }

    function post(action, data) {
        var body = new URLSearchParams();
        body.set('action', action);
        Object.keys(data || {}).forEach(function (key) {
            if (data[key] !== undefined && data[key] !== null) body.set(key, data[key]);
        });

        return fetch(API_PUSH, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: body.toString()
        }).then(function (response) { return response.json(); });
    }

    function haptic(pattern) {
        try { if (navigator.vibrate) navigator.vibrate(pattern); } catch (e) {}
    }

    var ICON = {
        tick: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>',
        warn: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16h.01"/></svg>',
        bell: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 1 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/></svg>',
        share: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 16V3"/><path d="m8 7 4-4 4 4"/><path d="M5 12v7a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-7"/></svg>',
        plus: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="4"/><path d="M12 8v8M8 12h8"/></svg>',
        down: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14"/><path d="m6 13 6 6 6-6"/></svg>',
        up: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 19V5"/><path d="m6 11 6-6 6 6"/></svg>',
        spin: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round"><path d="M12 3a9 9 0 1 0 9 9" /></svg>',
        down2: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 4v15"/><path d="m5 13 7 7 7-7"/></svg>'
    };

    /* ================================================================== *
     * Toasts
     * ================================================================== */

    function toast(message, kind, action) {
        var host = document.getElementById('hodToasts');
        if (!host) {
            host = el('div', { id: 'hodToasts' });
            document.body.appendChild(host);
        }

        var node = el('div', { class: 'hod-toast ' + (kind === 'bad' ? 'hod-bad' : 'hod-ok') },
            (kind === 'bad' ? ICON.warn : ICON.tick) + '<span></span>');
        node.querySelector('span').textContent = message;

        if (action) {
            var button = el('button', { type: 'button' });
            button.textContent = action.label;
            button.addEventListener('click', function () {
                action.run();
                close();
            });
            node.appendChild(button);
        }

        host.appendChild(node);
        requestAnimationFrame(function () { node.classList.add('hod-open'); });

        var timer = setTimeout(close, action ? 12000 : 4600);

        function close() {
            clearTimeout(timer);
            node.classList.remove('hod-open');
            setTimeout(function () { if (node.parentNode) node.parentNode.removeChild(node); }, 320);
        }

        return close;
    }

    /* ================================================================== *
     * Bottom sheets
     * ================================================================== */

    var openSheet = null;

    function sheet(id, html) {
        closeSheet();

        var backdrop = el('div', { class: 'hod-sheet-backdrop' });
        var panel = el('div', { class: 'hod-sheet', id: id, role: 'dialog', 'aria-modal': 'true' },
            '<div class="hod-grip"></div>' + html);

        document.body.appendChild(backdrop);
        document.body.appendChild(panel);

        requestAnimationFrame(function () {
            backdrop.classList.add('hod-open');
            panel.classList.add('hod-open');
        });

        backdrop.addEventListener('click', closeSheet);
        panel.addEventListener('click', function (event) {
            if (event.target.closest('[data-close]')) closeSheet();
        });

        // Swipe the sheet away, the way a native one behaves.
        var startY = null;
        panel.addEventListener('touchstart', function (event) {
            startY = panel.scrollTop <= 0 ? event.touches[0].clientY : null;
        }, { passive: true });
        panel.addEventListener('touchmove', function (event) {
            if (startY === null) return;
            var dy = event.touches[0].clientY - startY;
            if (dy > 0) panel.style.transform = 'translateY(' + dy + 'px)';
        }, { passive: true });
        panel.addEventListener('touchend', function () {
            if (startY === null) return;
            var shifted = parseFloat((panel.style.transform.match(/translateY\((\d+(?:\.\d+)?)px\)/) || [0, 0])[1]);
            panel.style.transform = '';
            if (shifted > 90) closeSheet();
            startY = null;
        });

        openSheet = { backdrop: backdrop, panel: panel };
        return panel;
    }

    function closeSheet() {
        if (!openSheet) return;

        var current = openSheet;
        openSheet = null;
        current.backdrop.classList.remove('hod-open');
        current.panel.classList.remove('hod-open');

        setTimeout(function () {
            [current.backdrop, current.panel].forEach(function (node) {
                if (node.parentNode) node.parentNode.removeChild(node);
            });
        }, 420);
    }

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') closeSheet();
    });

    /* ================================================================== *
     * Launch screen
     * ================================================================== */

    function dismissSplash() {
        var root = document.documentElement;
        if (!root.classList.contains('hod-booting')) return;

        var splash = document.getElementById('hodSplash');
        root.classList.remove('hod-booting');

        if (!splash) return;
        splash.classList.add('hod-splash-out');
        setTimeout(function () { splash.style.display = 'none'; }, 460);
    }

    // Hold it just long enough for the entrance animation to read as intent
    // rather than a flicker, and never longer than 3.2s however slow the
    // Tailwind CDN is being today.
    var splashShownAt = Date.now();
    function finishSplash() {
        setTimeout(dismissSplash, Math.max(0, 620 - (Date.now() - splashShownAt)));
    }

    if (document.readyState === 'complete') {
        finishSplash();
    } else {
        window.addEventListener('load', finishSplash);
    }
    setTimeout(dismissSplash, 3200);

    /* ================================================================== *
     * Route progress bar
     * ================================================================== */

    var progress = (function () {
        var bar = null;
        var timer = null;
        var width = 0;

        function node() {
            if (!bar) {
                bar = el('div', { id: 'hodProgress' });
                document.body.appendChild(bar);
            }
            return bar;
        }

        return {
            start: function () {
                var element = node();
                width = 8;
                element.classList.add('hod-on');
                element.style.width = width + '%';

                clearInterval(timer);
                timer = setInterval(function () {
                    // Ease towards 90% and wait there: the page load itself
                    // finishes the bar by replacing the document.
                    width += Math.max(0.4, (90 - width) * 0.11);
                    element.style.width = Math.min(width, 90) + '%';
                }, 180);
            },
            done: function () {
                if (!bar) return;
                clearInterval(timer);
                bar.style.width = '100%';
                setTimeout(function () {
                    bar.classList.remove('hod-on');
                    setTimeout(function () { bar.style.width = '0'; }, 320);
                }, 180);
            }
        };
    })();

    function isInternalLink(anchor) {
        if (!anchor || !anchor.getAttribute) return false;

        var href = anchor.getAttribute('href') || '';
        if (!href || href.charAt(0) === '#' || /^(mailto:|tel:|sms:|javascript:|blob:|data:)/i.test(href)) return false;
        if (anchor.target && anchor.target !== '_self') return false;
        if (anchor.hasAttribute('download')) return false;

        try {
            var url = new URL(anchor.href, location.href);
            if (url.origin !== location.origin) return false;
            // A pure hash change on the same page is not a navigation.
            return !(url.pathname === location.pathname && url.search === location.search && url.hash);
        } catch (e) {
            return false;
        }
    }

    // Bubble phase on purpose. A link whose own handler calls preventDefault()
    // — a modal opener, the command palette — must be able to win, and on the
    // capture phase this would navigate out from under it.
    document.addEventListener('click', function (event) {
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey) return;

        var anchor = event.target.closest && event.target.closest('a[href]');
        if (!isInternalLink(anchor)) return;

        progress.start();

        // Older iOS throws a same-origin link out of the installed app and
        // into Safari, which looks exactly like the app crashing. Taking the
        // navigation over keeps it inside.
        if (env.standalone && env.iOS) {
            event.preventDefault();
            location.href = anchor.href;
        }
    }, false);

    document.addEventListener('submit', function (event) {
        if (event.defaultPrevented) return;

        var form = event.target;
        if (form && form.method && form.method.toLowerCase() === 'post' && !form.hasAttribute('data-no-progress')) {
            progress.start();
        }
    }, false);

    // Coming back via bfcache must not leave a half-drawn bar on screen.
    window.addEventListener('pageshow', function () { progress.done(); });
    window.addEventListener('pagehide', function () { progress.done(); });

    /* ================================================================== *
     * Service worker
     * ================================================================== */

    var swReady = null;

    function registerServiceWorker() {
        if (!env.supportsSW || location.protocol !== 'https:' && location.hostname !== 'localhost') {
            return Promise.resolve(null);
        }

        swReady = navigator.serviceWorker.register('/sw.js', { scope: '/' })
            .then(function (registration) {
                // A worker already waiting means the member is looking at the
                // previous version right now.
                if (registration.waiting && navigator.serviceWorker.controller) {
                    offerUpdate(registration);
                }

                registration.addEventListener('updatefound', function () {
                    var incoming = registration.installing;
                    if (!incoming) return;

                    incoming.addEventListener('statechange', function () {
                        if (incoming.state === 'installed' && navigator.serviceWorker.controller) {
                            offerUpdate(registration);
                        }
                    });
                });

                return registration;
            })
            .catch(function (error) {
                console.warn('[hod] service worker registration failed', error);
                return null;
            });

        return swReady;
    }

    // A page's first service worker also "takes control" of it. Nothing on
    // the page is stale then, and a reload would wipe whatever the visitor is
    // typing (a phone number at the check-in door), so only an UPDATE to an
    // already-controlling worker reloads.
    var hadController = !!(navigator.serviceWorker && navigator.serviceWorker.controller);
    var reloading = false;
    navigator.serviceWorker && navigator.serviceWorker.addEventListener('controllerchange', function () {
        if (!hadController) {
            hadController = true;
            return;
        }
        if (reloading) return;
        reloading = true;
        location.reload();
    });

    function offerUpdate(registration) {
        toast('A new version of HOD Lekki is ready.', 'ok', {
            label: 'Refresh',
            run: function () {
                if (registration.waiting) registration.waiting.postMessage({ type: 'SKIP_WAITING' });
            }
        });
    }

    navigator.serviceWorker && navigator.serviceWorker.addEventListener('message', function (event) {
        var data = event.data || {};

        if (data.type === 'NAVIGATE' && data.url) {
            location.href = data.url;
            return;
        }

        if (data.type === 'PUSH_RECEIVED') {
            setBadge(data.badge || 0);
            // The bell in includes/header.php owns the in-page list.
            if (typeof window.fetchNotifications === 'function') {
                try { window.fetchNotifications(); } catch (e) {}
            }
        }
    });

    /* ================================================================== *
     * App icon badge
     * ================================================================== */

    function setBadge(count) {
        if (!('setAppBadge' in navigator)) return;
        try {
            if (count > 0) navigator.setAppBadge(count);
            else navigator.clearAppBadge();
        } catch (e) {}
    }

    function refreshBadge() {
        if (!CFG.signedIn || !('setAppBadge' in navigator)) return;

        // On the ERP shell the notification bell has just fetched the same
        // number; let it set the badge rather than asking twice.
        if (typeof window.fetchNotifications === 'function') return;

        fetch(API_PUSH + '?action=badge', { credentials: 'same-origin' })
            .then(function (response) { return response.json(); })
            .then(function (result) {
                if (result && result.status === 'success') setBadge(result.count || 0);
            })
            .catch(function () {});
    }

    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') refreshBadge();
    });

    /* ================================================================== *
     * Install — Android, desktop Chromium
     * ================================================================== */

    var deferredPrompt = null;

    window.addEventListener('beforeinstallprompt', function (event) {
        // Chrome's own mini-infobar is replaced by our banner, which can
        // explain what the app is for instead of just saying "install".
        event.preventDefault();
        deferredPrompt = event;

        if (!QUIET && !store.snoozed('install-dismissed', 14)) {
            setTimeout(showInstallBanner, 2600);
        }
    });

    window.addEventListener('appinstalled', function () {
        deferredPrompt = null;
        hideInstallBanner();
        store.drop('install-dismissed');
        toast('HOD Lekki is on your home screen.');
        haptic([30, 40, 30]);

        // Installed is the moment push becomes worth asking about — on iOS it
        // is the moment it becomes possible at all.
        if (CFG.signedIn) setTimeout(maybeAskForPush, 1800);
    });

    function showInstallBanner() {
        if (document.getElementById('hodInstall') || env.standalone) return;

        var banner = el('div', { id: 'hodInstall', role: 'region', 'aria-label': 'Install HOD Lekki' },
            '<div class="hod-mark"><img src="/assets/pwa/icon-192.png" alt="" width="36" height="36"></div>' +
            '<div class="hod-install-copy">' +
                '<strong>Install HOD Lekki</strong>' +
                '<span>Full screen, works offline, and alerts reach you straight away.</span>' +
            '</div>' +
            '<div class="hod-install-actions">' +
                '<button type="button" class="hod-x" aria-label="Not now">&times;</button>' +
                '<button type="button" class="hod-btn hod-btn-primary" data-install>Install</button>' +
            '</div>');

        document.body.appendChild(banner);
        requestAnimationFrame(function () { banner.classList.add('hod-open'); });

        banner.querySelector('.hod-x').addEventListener('click', function () {
            store.snooze('install-dismissed');
            hideInstallBanner();
        });
        banner.querySelector('[data-install]').addEventListener('click', runInstall);
    }

    function hideInstallBanner() {
        var banner = document.getElementById('hodInstall');
        if (!banner) return;
        banner.classList.remove('hod-open');
        setTimeout(function () { if (banner.parentNode) banner.parentNode.removeChild(banner); }, 480);
    }

    function runInstall() {
        if (!deferredPrompt) {
            promptInstall();
            return;
        }

        haptic(18);

        var event = deferredPrompt;
        deferredPrompt = null;
        event.prompt();

        event.userChoice.then(function (choice) {
            if (choice.outcome !== 'accepted') {
                store.snooze('install-dismissed');
            }
            hideInstallBanner();
        }).catch(function () { hideInstallBanner(); });
    }

    /* ================================================================== *
     * Install — iPhone / iPad
     * ------------------------------------------------------------------
     * Safari fires no install event and exposes no API, so the only honest
     * thing to do is show the member exactly which buttons to press. The
     * share button is at the BOTTOM of the screen on iPhone and at the TOP
     * on iPad, so the arrow has to know the difference.
     * ================================================================== */

    function showIosGuide() {
        if (env.standalone) {
            toast('HOD Lekki is already installed on this device.');
            return;
        }

        if (env.inAppBrowser) {
            sheet('hodIosSheet',
                '<h2>Open this in Safari first</h2>' +
                '<p>You are inside another app\'s browser, which cannot add anything to the home screen. ' +
                'Tap the <strong>&hellip;</strong> menu and choose <strong>Open in Safari</strong>, then try again.</p>' +
                '<button type="button" class="hod-btn hod-btn-ghost" data-close>Got it</button>');
            return;
        }

        var shareWord = env.iPad ? 'top' : 'bottom';

        var panel = sheet('hodIosSheet',
            '<div style="display:flex;align-items:center;gap:13px;margin-bottom:16px">' +
                '<div class="hod-mark"><img src="/assets/pwa/icon-192.png" alt="" width="36" height="36"></div>' +
                '<div><h2 style="margin:0">Add HOD Lekki to your Home Screen</h2>' +
                '<p style="margin:3px 0 0">Three taps, and it opens like any other app.</p></div>' +
            '</div>' +
            '<ul class="hod-steps">' +
                '<li><span class="hod-step-n"></span><span>Tap the <span class="hod-chip">' + ICON.share + ' Share</span> button at the ' + shareWord + ' of Safari.</span></li>' +
                '<li><span class="hod-step-n"></span><span>Scroll down and choose <span class="hod-chip">' + ICON.plus + ' Add to Home&nbsp;Screen</span>.</span></li>' +
                '<li><span class="hod-step-n"></span><span>Tap <span class="hod-chip">Add</span> in the top right. Done.</span></li>' +
            '</ul>' +
            '<p style="font-size:.78rem;margin-bottom:14px">Notifications on iPhone only work once the app is on your home screen &mdash; ' +
                'that is an Apple rule, not ours. Open it from the home screen afterwards and we will ask you to turn alerts on.</p>' +
            '<button type="button" class="hod-btn hod-btn-navy" data-close>Show me</button>');

        // Point at the share button for as long as the sheet is open.
        var arrow = el('div', { class: 'hod-ios-arrow' }, env.iPad ? ICON.up : ICON.down2);
        if (env.iPad) {
            arrow.style.top = 'calc(var(--hod-safe-top) + 8px)';
            arrow.style.right = '92px';
        } else {
            arrow.style.bottom = 'calc(var(--hod-safe-bottom) + 6px)';
            arrow.style.left = '50%';
            arrow.style.marginLeft = '-17px';
        }
        document.body.appendChild(arrow);

        var remove = function () { if (arrow.parentNode) arrow.parentNode.removeChild(arrow); };
        panel.addEventListener('click', function (event) {
            if (event.target.closest('[data-close]')) remove();
        });
        document.addEventListener('keydown', function once(event) {
            if (event.key === 'Escape') { remove(); document.removeEventListener('keydown', once); }
        });
        setTimeout(remove, 30000);

        store.snooze('ios-guide-shown');
    }

    function promptInstall() {
        if (env.standalone) {
            toast('HOD Lekki is already installed on this device.');
            return;
        }
        if (deferredPrompt) {
            runInstall();
            return;
        }
        if (env.iOS) {
            showIosGuide();
            return;
        }

        toast('Use your browser menu and choose "Install app" or "Add to Home screen".', 'ok');
    }

    /* ================================================================== *
     * Push notifications
     * ================================================================== */

    /** The VAPID public key arrives as base64url; subscribe() wants bytes. */
    function urlB64ToUint8Array(base64) {
        var padded = (base64 + '='.repeat((4 - base64.length % 4) % 4)).replace(/-/g, '+').replace(/_/g, '/');
        var raw = atob(padded);
        var bytes = new Uint8Array(raw.length);
        for (var i = 0; i < raw.length; i++) bytes[i] = raw.charCodeAt(i);
        return bytes;
    }

    function bufferToB64Url(buffer) {
        var bytes = new Uint8Array(buffer);
        var binary = '';
        for (var i = 0; i < bytes.length; i++) binary += String.fromCharCode(bytes[i]);
        return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    }

    var pushConfig = null;

    function loadPushConfig(force) {
        if (pushConfig && !force) return Promise.resolve(pushConfig);

        return fetch(API_PUSH + '?action=config', { credentials: 'same-origin' })
            .then(function (response) { return response.json(); })
            .then(function (result) {
                pushConfig = (result && result.status === 'success') ? result.data : null;
                return pushConfig;
            })
            .catch(function () { return null; });
    }

    /**
     * Turn alerts on for THIS device.
     * Must be called from inside a click handler: iOS refuses a permission
     * request that is not tied to a gesture.
     */
    function enablePush() {
        if (!env.supportsPush) {
            return Promise.reject(new Error(env.iOS && !env.standalone
                ? 'Add HOD Lekki to your Home Screen first — iPhone only allows notifications in an installed app.'
                : 'This browser cannot receive notifications.'));
        }

        return Promise.resolve()
            .then(function () { return Notification.requestPermission(); })
            .then(function (permission) {
                if (permission !== 'granted') {
                    throw new Error(permission === 'denied'
                        ? 'Notifications are blocked for this site. Turn them back on in your browser settings.'
                        : 'Notifications were not turned on.');
                }
                return Promise.all([loadPushConfig(true), navigator.serviceWorker.ready]);
            })
            .then(function (both) {
                var config = both[0];
                var registration = both[1];

                if (!config || !config.available || !config.public_key) {
                    throw new Error('Notifications are not set up on the server yet.');
                }

                return registration.pushManager.getSubscription().then(function (existing) {
                    if (!existing) return subscribeFresh(registration, config.public_key);

                    // A subscription made with a different application server
                    // key is dead to us — drop it and make a new one.
                    var currentKey = existing.options && existing.options.applicationServerKey;
                    if (currentKey && bufferToB64Url(currentKey) !== config.public_key) {
                        return existing.unsubscribe().then(function () {
                            return subscribeFresh(registration, config.public_key);
                        });
                    }
                    return existing;
                });
            })
            .then(function (subscription) {
                var json = subscription.toJSON();
                return post('subscribe', {
                    endpoint: subscription.endpoint,
                    p256dh: json.keys && json.keys.p256dh,
                    auth: json.keys && json.keys.auth
                });
            })
            .then(function (result) {
                if (!result || result.status !== 'success') {
                    throw new Error((result && result.message) || 'Could not register this device.');
                }
                store.drop('push-dismissed');
                return result;
            });
    }

    function subscribeFresh(registration, publicKey) {
        return registration.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: urlB64ToUint8Array(publicKey)
        });
    }

    function disablePush() {
        return navigator.serviceWorker.ready
            .then(function (registration) { return registration.pushManager.getSubscription(); })
            .then(function (subscription) {
                if (!subscription) return { status: 'success' };
                var endpoint = subscription.endpoint;
                return subscription.unsubscribe().then(function () {
                    return post('unsubscribe', { endpoint: endpoint });
                });
            });
    }

    /** Is this device currently subscribed? */
    function pushState() {
        if (!env.supportsPush) {
            return Promise.resolve({ supported: false, permission: 'unsupported', subscribed: false });
        }

        return navigator.serviceWorker.ready
            .then(function (registration) { return registration.pushManager.getSubscription(); })
            .then(function (subscription) {
                return {
                    supported: true,
                    permission: Notification.permission,
                    subscribed: !!subscription
                };
            })
            .catch(function () {
                return { supported: true, permission: Notification.permission, subscribed: false };
            });
    }

    /* ------------------------------------------------------------------ *
     * The opt-in card
     * ------------------------------------------------------------------ */

    function showPushCard() {
        var iosNeedsInstall = env.iOS && !env.standalone;

        var body = iosNeedsInstall
            ? '<p>On iPhone and iPad, notifications only work once an app is on the Home Screen. ' +
              'It takes three taps &mdash; then we can let you know the moment something needs you.</p>'
            : '<p>We will only send things that matter: assignments, approvals, first-timer follow-ups ' +
              'and announcements from the pastors.</p>' +
              '<ul class="hod-benefits">' +
                  '<li>' + ICON.tick + '<span>Follow-ups and tasks the moment they are assigned to you</span></li>' +
                  '<li>' + ICON.tick + '<span>Approvals waiting on you, before they go stale</span></li>' +
                  '<li>' + ICON.tick + '<span>Service and department announcements</span></li>' +
              '</ul>' +
              '<p style="font-size:.76rem">You can switch them off, or set quiet hours, at any time.</p>';

        var panel = sheet('hodPushCard',
            '<div class="hod-centered">' +
                '<div class="hod-bell">' + ICON.bell + '</div>' +
                '<h2>' + (CFG.firstName ? 'Don\'t miss anything, ' + escapeHtml(CFG.firstName) : 'Don\'t miss anything') + '</h2>' +
            '</div>' +
            body +
            '<button type="button" class="hod-btn hod-btn-primary" data-enable>' +
                (iosNeedsInstall ? 'Show me how' : 'Turn on alerts') +
            '</button>' +
            '<button type="button" class="hod-btn hod-btn-ghost" style="margin-top:9px" data-later>Not now</button>');

        panel.querySelector('[data-later]').addEventListener('click', function () {
            store.snooze('push-dismissed');
            closeSheet();
        });

        panel.querySelector('[data-enable]').addEventListener('click', function () {
            if (iosNeedsInstall) {
                closeSheet();
                setTimeout(showIosGuide, 300);
                return;
            }

            var button = this;
            button.disabled = true;
            button.textContent = 'Turning on…';

            enablePush()
                .then(function () {
                    closeSheet();
                    haptic([25, 35, 25]);
                    toast('Alerts are on. We will reach you here.');
                    refreshBadge();
                })
                .catch(function (error) {
                    button.disabled = false;
                    button.textContent = 'Turn on alerts';
                    toast(error.message || 'Could not turn alerts on.', 'bad');
                });
        });
    }

    function escapeHtml(text) {
        var node = document.createElement('div');
        node.textContent = String(text);
        return node.innerHTML;
    }

    /**
     * Ask at a moment that earns a yes: not on the first page a member ever
     * sees, not twice in a fortnight, and never when the browser has already
     * answered for them.
     */
    function maybeAskForPush() {
        if (QUIET || !CFG.signedIn || store.snoozed('push-dismissed', 12)) return;

        // iOS in a browser tab cannot subscribe at all — the install guide is
        // the useful prompt there, and that has its own pacing.
        if (env.iOS && !env.standalone) return;
        if (!env.supportsPush || Notification.permission !== 'default') return;

        var views = parseInt(store.get('views') || '0', 10);
        if (views < 2) return;

        pushState().then(function (state) {
            if (!state.subscribed) showPushCard();
        });
    }

    /* ------------------------------------------------------------------ *
     * Alert settings sheet
     * ------------------------------------------------------------------ */

    var HOURS = (function () {
        var options = ['<option value="">—</option>'];
        for (var hour = 0; hour < 24; hour++) {
            var label = (hour % 12 === 0 ? 12 : hour % 12) + (hour < 12 ? ' am' : ' pm');
            options.push('<option value="' + hour + '">' + label + '</option>');
        }
        return options.join('');
    })();

    function openAlerts() {
        var panel = sheet('hodAlerts',
            '<h2>Alerts</h2>' +
            '<p>Push notifications reach you even when HOD Lekki is closed.</p>' +
            '<div data-body><div class="hod-row"><div class="hod-row-main"><strong>Loading…</strong></div></div></div>');

        var body = panel.querySelector('[data-body]');

        Promise.all([pushState(), loadPushConfig(true)]).then(function (both) {
            var state = both[0];
            var config = both[1] || {};
            var prefs = config.preferences || {};

            if (!config.available) {
                body.innerHTML = '<div class="hod-row"><div class="hod-row-main">' +
                    '<strong>Not available yet</strong>' +
                    '<small>Push notifications have not been set up on the server. Your notification bell still works.</small>' +
                    '</div></div>';
                return;
            }

            if (env.iOS && !env.standalone) {
                body.innerHTML = '<div class="hod-row"><div class="hod-row-main">' +
                    '<strong>Add to Home Screen first</strong>' +
                    '<small>iPhone and iPad only deliver notifications to an installed app.</small>' +
                    '</div></div>' +
                    '<button type="button" class="hod-btn hod-btn-navy" style="margin-top:14px" data-ios>Show me how</button>';
                body.querySelector('[data-ios]').addEventListener('click', function () {
                    closeSheet();
                    setTimeout(showIosGuide, 300);
                });
                return;
            }

            var blocked = state.permission === 'denied';

            body.innerHTML =
                '<div class="hod-row">' +
                    '<div class="hod-row-main">' +
                        '<strong>Alerts on this device</strong>' +
                        '<small data-device-note></small>' +
                    '</div>' +
                    '<button type="button" class="hod-switch" role="switch" data-device aria-checked="' + (state.subscribed ? 'true' : 'false') + '"></button>' +
                '</div>' +
                '<div class="hod-row">' +
                    '<div class="hod-row-main">' +
                        '<strong>Quiet hours</strong>' +
                        '<small>Hold non-urgent alerts overnight. Security alerts always come through.</small>' +
                    '</div>' +
                '</div>' +
                '<div class="hod-row" style="border-top:0;padding-top:0;gap:9px">' +
                    '<select class="hod-select" data-from aria-label="Quiet hours start">' + HOURS + '</select>' +
                    '<span style="font-size:.8rem;color:var(--hod-muted)">to</span>' +
                    '<select class="hod-select" data-to aria-label="Quiet hours end">' + HOURS + '</select>' +
                '</div>' +
                '<button type="button" class="hod-btn hod-btn-ghost" style="margin-top:14px" data-test>Send me a test alert</button>' +
                '<p data-devices style="margin:14px 0 0;font-size:.74rem;text-align:center"></p>';

            var toggle = body.querySelector('[data-device]');
            var note = body.querySelector('[data-device-note]');
            var from = body.querySelector('[data-from]');
            var to = body.querySelector('[data-to]');

            from.value = prefs.quiet_from === null || prefs.quiet_from === undefined ? '' : String(prefs.quiet_from);
            to.value = prefs.quiet_to === null || prefs.quiet_to === undefined ? '' : String(prefs.quiet_to);

            function paint(subscribed) {
                toggle.setAttribute('aria-checked', subscribed ? 'true' : 'false');
                note.textContent = blocked
                    ? 'Blocked in your browser settings for this site.'
                    : (subscribed ? 'This phone or computer will buzz.' : 'Off — you will only see the bell.');
            }
            paint(state.subscribed);

            body.querySelector('[data-devices]').textContent =
                (config.devices || 0) + ' device' + ((config.devices || 0) === 1 ? '' : 's') + ' registered to your account.';

            toggle.addEventListener('click', function () {
                var turningOn = toggle.getAttribute('aria-checked') !== 'true';
                toggle.disabled = true;

                (turningOn ? enablePush() : disablePush())
                    .then(function () {
                        toggle.disabled = false;
                        paint(turningOn);
                        haptic(18);
                        toast(turningOn ? 'Alerts are on for this device.' : 'Alerts are off for this device.');
                        loadPushConfig(true);
                    })
                    .catch(function (error) {
                        toggle.disabled = false;
                        paint(!turningOn);
                        toast(error.message || 'Could not change that.', 'bad');
                    });
            });

            function saveQuietHours() {
                post('save_prefs', {
                    push_enabled: '1',
                    quiet_from: from.value,
                    quiet_to: to.value
                }).then(function (result) {
                    if (result && result.status === 'success') {
                        pushConfig = null;
                        toast(from.value === '' || to.value === '' ? 'Quiet hours cleared.' : 'Quiet hours saved.');
                    }
                }).catch(function () {});
            }
            from.addEventListener('change', saveQuietHours);
            to.addEventListener('change', saveQuietHours);

            body.querySelector('[data-test]').addEventListener('click', function () {
                var button = this;
                button.disabled = true;
                button.textContent = 'Sending…';

                post('test', {})
                    .then(function (result) {
                        button.disabled = false;
                        button.textContent = 'Send me a test alert';
                        toast(result.message || 'Sent.', result.status === 'success' ? 'ok' : 'bad');
                    })
                    .catch(function () {
                        button.disabled = false;
                        button.textContent = 'Send me a test alert';
                        toast('Could not send the test.', 'bad');
                    });
            });
        });
    }

    /* ================================================================== *
     * Pull to refresh (installed app only)
     * ================================================================== */

    function initPullToRefresh() {
        if (QUIET || !env.standalone || !('ontouchstart' in window)) return;

        var indicator = el('div', { id: 'hodPull' }, ICON.spin);
        document.body.appendChild(indicator);

        var startX = 0;
        var startY = 0;
        var pulling = false;
        var decided = false;

        // True only when the page and EVERY scroll container between the
        // finger and <body> is already at its top. Checking just the nearest
        // one let a table's overflow-x wrapper (whose overflow-y computes to
        // `auto`, and whose scrollHeight can sit a rounding pixel above its
        // clientHeight) report "at top" while <main> was scrolled halfway
        // down — so dragging back up a long list was swallowed as a pull,
        // and a long drag reloaded the page.
        function everythingAtTop(node) {
            while (node && node !== document.body && node.nodeType === 1) {
                var style = getComputedStyle(node);
                if (/(auto|scroll)/.test(style.overflowY) && node.scrollTop > 0) return false;
                node = node.parentNode;
            }
            return (window.scrollY || document.documentElement.scrollTop) <= 0;
        }

        function reset() {
            pulling = false;
            indicator.style.opacity = '0';
            indicator.style.transform = 'translateY(-60px)';
        }

        document.addEventListener('touchstart', function (event) {
            pulling = false;
            // Never fight a sheet, a modal or an open keyboard.
            if (openSheet || document.querySelector('.app-modal-overlay:not(.hidden):not([hidden])')) return;
            if (event.touches.length !== 1) return;
            // Any surface can opt out of the gesture for its own subtree —
            // a canvas, a signature pad, a horizontal carousel.
            if (event.target.closest && event.target.closest('[data-no-pull]')) return;
            if (!everythingAtTop(event.target)) return;

            startX = event.touches[0].clientX;
            startY = event.touches[0].clientY;
            pulling = true;
            decided = false;
        }, { passive: true });

        document.addEventListener('touchmove', function (event) {
            if (!pulling) return;

            var dx = event.touches[0].clientX - startX;
            var dy = event.touches[0].clientY - startY;

            // Decide once, on the first clear movement, whether this is a
            // pull at all. A sideways swipe across a wide table drifts a few
            // pixels down and used to be cancelled as a pull, which froze
            // horizontal scrolling whenever the page sat at the top.
            if (!decided) {
                if (Math.abs(dx) < 8 && Math.abs(dy) < 8) return;
                decided = true;
                if (dy <= 0 || Math.abs(dx) > dy) { reset(); return; }
            }

            if (dy <= 0) { reset(); return; }

            var pulled = Math.min(dy, 140);
            indicator.style.opacity = String(Math.min(pulled / 80, 1));
            indicator.style.transform = 'translateY(' + (pulled * 0.6 - 20) + 'px) rotate(' + pulled * 2.4 + 'deg)';

            if (dy > 12 && event.cancelable) event.preventDefault();
        }, { passive: false });

        document.addEventListener('touchend', function (event) {
            if (!pulling) return;
            pulling = false;

            var dy = (event.changedTouches[0] || {}).clientY - startY;

            if (dy > 95) {
                indicator.classList.add('hod-spin');
                indicator.style.opacity = '1';
                indicator.style.transform = 'translateY(16px)';
                haptic(14);
                setTimeout(function () { location.reload(); }, 160);
                return;
            }

            indicator.style.opacity = '0';
            indicator.style.transform = 'translateY(-60px)';
        }, { passive: true });
    }

    /* ================================================================== *
     * Boot
     * ================================================================== */

    function boot() {
        try {
            store.set('views', parseInt(store.get('views') || '0', 10) + 1);
        } catch (e) {}

        registerServiceWorker();
        initPullToRefresh();
        refreshBadge();

        // Anything on the page can open these.
        document.addEventListener('click', function (event) {
            var alerts = event.target.closest && event.target.closest('[data-hod-alerts]');
            if (alerts) { event.preventDefault(); openAlerts(); return; }

            var install = event.target.closest && event.target.closest('[data-hod-install]');
            if (install) { event.preventDefault(); promptInstall(); }
        });

        // Hide anything that only makes sense before installing.
        if (env.standalone) {
            document.documentElement.classList.add('hod-installed');
        }

        if (CFG.signedIn) {
            setTimeout(maybeAskForPush, 7000);

            // A device whose subscription was dropped server-side (key rotated,
            // endpoint expired) still has permission granted, so it never asks
            // again and silently receives nothing. Re-register on every load:
            // the endpoint is unchanged, so this is an idempotent upsert.
            if (env.supportsPush && Notification.permission === 'granted') {
                navigator.serviceWorker.ready
                    .then(function (registration) { return registration.pushManager.getSubscription(); })
                    .then(function (subscription) {
                        if (!subscription) return;
                        var json = subscription.toJSON();
                        return post('subscribe', {
                            endpoint: subscription.endpoint,
                            p256dh: json.keys && json.keys.p256dh,
                            auth: json.keys && json.keys.auth
                        });
                    })
                    .catch(function () {});
            }
        }

        // iOS gets the install nudge instead of the push card, paced slowly:
        // once a month is plenty for something they can also reach from the
        // notification bell whenever they want it.
        if (!QUIET && env.iOS && !env.standalone && !env.inAppBrowser && CFG.signedIn &&
            !store.snoozed('ios-guide-shown', 30)) {
            var views = parseInt(store.get('views') || '0', 10);
            if (views >= 3) setTimeout(showIosGuide, 9000);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }

    /* ================================================================== *
     * Public surface
     * ================================================================== */

    window.hodApp = {
        env: env,
        toast: toast,
        setBadge: setBadge,
        openAlerts: openAlerts,
        promptInstall: promptInstall,
        enablePush: enablePush,
        disablePush: disablePush,
        pushState: pushState,
        refreshBadge: refreshBadge,
        closeSheet: closeSheet
    };
})();
