// /assets/ui/js/shell.js
// ===========================================================================
// HOD Lekki — app shell behaviour (includes/ui/shell.php).
//
//   * Phone menu sheet (bottom bar "Menu"), with focus kept inside it.
//   * Desktop sidebar collapsed to an icon rail, remembered in a cookie so
//     the server renders it collapsed and nothing jumps on load.
//   * Notifications panel: loads, renders (escaped — a notification's text
//     is data, never markup), marks read, keeps the bell, the bottom-bar
//     count and the home-screen icon badge in step.
//   * The phone app bar title appears once the page's own title scrolls away.
//   * The "Install the app" entry only where installing is possible.
// ===========================================================================
(function () {
    'use strict';

    var HOD = window.HOD || {};
    var root = document.documentElement;
    var cfg = (HOD.data && HOD.data('hod-ui')) || {};
    var esc = HOD.esc || function (s) { return String(s); };
    var icon = HOD.icon || function () { return ''; };

    function ready(fn) {
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fn);
        else fn();
    }

    /* ------------------------------------------------------------------ *
     * Phone menu sheet
     * ------------------------------------------------------------------ */

    var sidebar, mainCol, bottombar, menuToggle, lastFocus = null;

    function setMenu(open) {
        if (!sidebar) return;
        var isOpen = root.classList.contains('ui-menu-open');
        if (open === isOpen) return;
        root.classList.toggle('ui-menu-open', open);
        if (menuToggle) menuToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (mainCol) mainCol.inert = open;
        if (bottombar) bottombar.inert = false;
        if (open) {
            lastFocus = document.activeElement;
            sidebar.setAttribute('role', 'dialog');
            sidebar.setAttribute('aria-modal', 'true');
            var current = sidebar.querySelector('[aria-current="page"]') || sidebar.querySelector('.ui-nav__item');
            setTimeout(function () {
                if (current) {
                    current.scrollIntoView({ block: 'center' });
                    current.focus({ preventScroll: true });
                }
            }, 60);
        } else {
            sidebar.removeAttribute('role');
            sidebar.removeAttribute('aria-modal');
            if (lastFocus && lastFocus.isConnected) lastFocus.focus({ preventScroll: true });
        }
    }

    function isPhone() {
        return window.matchMedia('(max-width: 1023px)').matches;
    }

    /* ------------------------------------------------------------------ *
     * Desktop rail
     * ------------------------------------------------------------------ */

    function setRail(on) {
        root.classList.toggle('ui-rail', on);
        var btn = document.querySelector('[data-ui-rail-toggle]');
        if (btn) {
            var label = on ? 'Expand menu' : 'Collapse menu';
            btn.setAttribute('aria-label', label);
            btn.setAttribute('data-tooltip', label);
            btn.setAttribute('aria-pressed', on ? 'true' : 'false');
            var use = btn.querySelector('use');
            if (use) use.setAttribute('href', use.getAttribute('href').replace(/#.*$/, on ? '#panel-left-open' : '#panel-left-close'));
        }
        var secure = window.location.protocol === 'https:' ? '; Secure' : '';
        document.cookie = 'hod_ui_rail=' + (on ? '1' : '0') + '; Path=/; Max-Age=31536000; SameSite=Lax' + secure;
    }

    /* ------------------------------------------------------------------ *
     * Notifications
     * ------------------------------------------------------------------ */

    var notifApi = cfg.notifications || '/api/notifications_api.php';
    var unread = 0;

    function setUnread(count) {
        unread = Math.max(0, count | 0);
        document.querySelectorAll('[data-ui-notif-dot]').forEach(function (el) { el.hidden = unread === 0; });
        document.querySelectorAll('[data-ui-notif-count]').forEach(function (el) {
            el.hidden = unread === 0;
            el.textContent = unread > 99 ? '99+' : String(unread);
        });
        var summary = document.querySelector('[data-ui-notif-summary]');
        if (summary) summary.textContent = unread === 0 ? "You're all caught up." : unread + ' unread';
        var footer = document.querySelector('[data-ui-notif-footer]');
        if (footer) footer.hidden = unread === 0;
        if (window.hodApp && typeof window.hodApp.setBadge === 'function') window.hodApp.setBadge(unread);
    }

    // A link may only lead somewhere on this site (the server enforces the
    // same rule when it writes link_url; this is belt and braces).
    function safeLink(url) {
        if (!url || typeof url !== 'string') return null;
        if (!/^\/(?!\/)/.test(url) || /\\/.test(url) || /^\/\s*javascript:/i.test(url)) return null;
        return url;
    }

    function renderNotifications(items) {
        var list = document.querySelector('[data-ui-notif-list]');
        if (!list) return;
        if (!items.length) {
            list.innerHTML =
                '<div class="ui-empty ui-empty--compact">' +
                    '<div class="ui-empty__icon">' + icon('inbox') + '</div>' +
                    '<p class="ui-empty__title">No new notifications</p>' +
                    '<p class="ui-empty__text">New follow-ups, approvals and messages for you will appear here.</p>' +
                '</div>';
            return;
        }
        list.innerHTML = '<ul class="ui-notif__list">' + items.map(function (n, i) {
            return '<li><button type="button" class="ui-notif__item" data-notif-index="' + i + '">' +
                '<span class="ui-dot ui-dot--danger ui-notif__unread" aria-hidden="true"></span>' +
                '<span class="ui-notif__main">' +
                    '<span class="ui-notif__title">' + esc(n.title) + '</span>' +
                    '<span class="ui-notif__text">' + esc(n.message) + '</span>' +
                '</span>' +
                '<span class="ui-notif__time">' + esc(n.time_ago || '') + '</span>' +
            '</button></li>';
        }).join('') + '</ul>';
        list.__items = items;
    }

    /**
     * mode: 'panel' (the bell was opened: everything listed counts as seen),
     * 'push' (a push just reached this device, which has already rung), or
     * anything else (a check while the app is open: toast and chime).
     */
    function loadNotifications(mode) {
        if (!HOD.api) return;
        var isCheck = mode !== 'panel';
        if (isCheck && checking) {
            // One is already on its way; a push may have landed after it left.
            if (mode === 'push') setTimeout(function () { loadNotifications('push'); }, 1500);
            return;
        }
        if (isCheck) {
            checking = true;
            lastCheck = Date.now();
        }
        HOD.api(notifApi, { action: 'fetch', quiet: true }).then(function (res) {
            if (isCheck) failures = 0;
            var items = Array.isArray(res.data) ? res.data : [];
            setUnread(typeof res.count === 'number' ? res.count : items.length);
            renderNotifications(items);
            if (mode === 'panel') markSeen(items);
            else announce(items, mode === 'push');
        }).catch(function (err) {
            if (isCheck) failures++;
            if (err && /unauthori[sz]ed|session has ended/i.test(String(err.message || ''))) signedOut = true;
            var list = document.querySelector('[data-ui-notif-list]');
            if (list && !list.__items) {
                list.innerHTML = '<div class="ui-empty ui-empty--compact"><div class="ui-empty__icon">' + icon('cloud-off') + '</div>' +
                    '<p class="ui-empty__title">Notifications are not loading</p>' +
                    '<p class="ui-empty__text">Check your connection, then open this panel again.</p></div>';
            }
        }).then(function () { if (isCheck) checking = false; });
    }

    /* ------------------------------------------------------------------ *
     * Live alerts: nothing arrives silently while the app is open
     * ------------------------------------------------------------------
     * Owner, 13 Nov 2026: "people should not miss anything". The bell is
     * checked every 30 seconds while the page is in view, on coming back to
     * it, and the moment a push reaches this device. Anything newer than the
     * last alert this browser has seen pops up as a toast with an Open button
     * and a soft chime, which also covers members with no phone alerts.
     *
     * "Seen" is the highest notification id, kept per member in localStorage
     * so a page change or a second tab does not announce the same alert
     * twice. A browser's first ever check only sets that mark: old unread
     * alerts are the bell's job, not a burst of toasts.
     * ------------------------------------------------------------------ */

    var POLL_MS = 30000;
    var checking = false, lastCheck = 0, failures = 0, signedOut = false;
    var seenKey = 'hod:alerts-seen:' + (cfg.user || 0);
    var memorySeen = null;

    function idOf(n) { return parseInt(n && n.id, 10) || 0; }
    function topId(items) { return items.reduce(function (m, n) { return Math.max(m, idOf(n)); }, 0); }

    function readSeen() {
        var stored = NaN;
        try { stored = parseInt(localStorage.getItem(seenKey) || '', 10); } catch (e) { /* private mode */ }
        if (isNaN(stored)) return memorySeen;
        return Math.max(stored, memorySeen || 0);
    }

    function writeSeen(id) {
        memorySeen = Math.max(memorySeen || 0, id);
        try { localStorage.setItem(seenKey, String(memorySeen)); } catch (e) { /* private mode */ }
    }

    function markSeen(items) { writeSeen(topId(items)); }

    function announce(items, fromPush) {
        // A page in a background tab says nothing: it would be news nobody
        // sees. The next check after it comes back into view announces it.
        if (document.visibilityState !== 'visible') return;

        var seen = readSeen();
        var top = topId(items);
        if (seen === null) { writeSeen(top); return; }

        var fresh = items.filter(function (n) { return idOf(n) > seen; })
            .sort(function (a, b) { return idOf(a) - idOf(b); });
        if (top > seen) writeSeen(top);
        if (!fresh.length) return;

        if (fresh.length <= 2) fresh.forEach(alertToast);
        else {
            HOD.toast(fresh.length + ' new notifications', {
                icon: 'bell', detail: 'Latest: ' + (fresh[fresh.length - 1].title || ''),
                duration: 15000, action: { label: 'View', run: openBell }
            });
        }
        // The phone has already rung for a push; a check found it on its own.
        if (!fromPush) chime();
    }

    function alertToast(n) {
        var link = safeLink(n.link_url);
        HOD.toast(n.title || 'New notification', {
            icon: 'bell', detail: n.message || '', duration: 15000,
            action: link
                ? { label: 'Open', run: function () { markRead(n.id).finally(function () { window.location.href = link; }); } }
                : { label: 'View', run: openBell }
        });
    }

    function openBell() {
        var trigger = document.querySelector('[data-dialog-open="ui-notifications"]');
        if (trigger) trigger.click();
    }

    function checkSoon(minGap) {
        if (signedOut || document.visibilityState !== 'visible' || !navigator.onLine) return;
        // After failures, back off: 30 s, 1, 2, 4 ... up to 8 minutes.
        var gap = Math.max(minGap, failures ? Math.min(POLL_MS * Math.pow(2, failures), 480000) : 0);
        if (Date.now() - lastCheck < gap) return;
        loadNotifications('check');
    }

    /* A soft two-note chime, made with Web Audio (no sound file, nothing for
     * the CSP to allow). Browsers only let a page make sound after a tap or a
     * key press, so the audio is unlocked on the first one. Pages with audio
     * or video of their own (a sermon) never get a chime: it would cut into
     * what the member is listening to. The toast still shows. */
    var audio = null;

    function unlockAudio() {
        if (audio || document.querySelector('audio, video')) return;
        var Ctx = window.AudioContext || window.webkitAudioContext;
        if (!Ctx) return;
        try {
            audio = new Ctx();
            if (audio.state === 'suspended' && audio.resume) audio.resume().catch(function () {});
        } catch (e) { audio = null; }
    }

    function chime() {
        if (!audio || document.querySelector('audio, video')) return;
        var play = function () {
            try {
                var t = audio.currentTime + 0.02;
                [[784, 0], [1175, 0.15]].forEach(function (note) {
                    var osc = audio.createOscillator();
                    var gain = audio.createGain();
                    osc.type = 'sine';
                    osc.frequency.value = note[0];
                    gain.gain.setValueAtTime(0.0001, t + note[1]);
                    gain.gain.exponentialRampToValueAtTime(0.14, t + note[1] + 0.02);
                    gain.gain.exponentialRampToValueAtTime(0.0001, t + note[1] + 0.6);
                    osc.connect(gain);
                    gain.connect(audio.destination);
                    osc.start(t + note[1]);
                    osc.stop(t + note[1] + 0.65);
                });
            } catch (e) { /* sound is a nicety */ }
        };
        if (audio.state === 'running') play();
        else if (audio.resume) audio.resume().then(function () { if (audio.state === 'running') play(); }).catch(function () {});
    }

    function markRead(id) {
        return HOD.api(notifApi, { action: 'mark_read', data: { id: id }, quiet: true });
    }

    function onNotificationClick(event) {
        var button = event.target.closest('[data-notif-index]');
        if (!button) return;
        var list = document.querySelector('[data-ui-notif-list]');
        var n = list && list.__items ? list.__items[parseInt(button.getAttribute('data-notif-index'), 10)] : null;
        if (!n) return;
        var link = safeLink(n.link_url);
        if (link) {
            markRead(n.id).finally(function () { window.location.href = link; });
            return;
        }
        // No link: open it out in place and mark it read once.
        button.classList.toggle('is-expanded');
        if (!button.__read) {
            button.__read = true;
            var dot = button.querySelector('.ui-notif__unread');
            markRead(n.id).then(function () {
                if (dot) dot.remove();
                setUnread(unread - 1);
            }).catch(function () {});
        }
    }

    /* ------------------------------------------------------------------ *
     * Page title in the phone app bar
     * ------------------------------------------------------------------ */

    function watchPageTitle() {
        var header = document.querySelector('[data-ui-page-header]');
        if (!header || !('IntersectionObserver' in window)) return;
        root.classList.add('ui-has-page-title');
        new IntersectionObserver(function (entries) {
            root.classList.toggle('ui-title-scrolled', !entries[0].isIntersecting);
        }, { root: document.getElementById('main'), threshold: 0, rootMargin: '-8px 0px 0px 0px' }).observe(header.querySelector('.ui-h1') || header);
    }

    /* ------------------------------------------------------------------ *
     * Boot
     * ------------------------------------------------------------------ */

    // "Alerts on this device", the alert settings gear and "Install the app"
    // live inside dialogs, but assets/js/pwa.js opens its sheets in <body>,
    // which is inert under a modal <dialog>. Close the dialog at once (capture
    // phase, before pwa.js's own handler runs) so its sheet is usable.
    document.addEventListener('click', function (event) {
        var trigger = event.target instanceof Element && event.target.closest('[data-hod-alerts], [data-hod-install]');
        if (!trigger) return;
        var dialog = trigger.closest('dialog.ui-dialog');
        if (!dialog || !dialog.hasAttribute('open')) return;
        dialog.classList.remove('is-open');
        if (typeof dialog.close === 'function') dialog.close('pwa');
        else dialog.removeAttribute('open');
        if (!document.querySelector('dialog[open]')) root.classList.remove('ui-dialog-open');
    }, true);

    ready(function () {
        sidebar = document.getElementById('ui-sidebar');
        mainCol = document.querySelector('.ui-main-col');
        bottombar = document.querySelector('.ui-bottombar');
        menuToggle = document.querySelector('[data-ui-menu-toggle]');

        if (menuToggle) menuToggle.addEventListener('click', function () { setMenu(!root.classList.contains('ui-menu-open')); });
        document.querySelectorAll('[data-ui-menu-close]').forEach(function (b) {
            b.addEventListener('click', function () { setMenu(false); });
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && root.classList.contains('ui-menu-open')) {
                event.preventDefault();
                setMenu(false);
            }
            // Keep Tab inside the open menu sheet.
            if (event.key === 'Tab' && root.classList.contains('ui-menu-open') && sidebar) {
                var f = sidebar.querySelectorAll('a[href], button:not([disabled])');
                if (!f.length) return;
                var first = f[0], last = f[f.length - 1];
                if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
                else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
            }
        });
        // Leaving the phone layout (rotation, resized window) closes the sheet.
        window.matchMedia('(min-width: 1024px)').addEventListener('change', function (e) { if (e.matches) setMenu(false); });
        // Opening anything else from the bottom bar closes the menu first.
        if (bottombar) {
            bottombar.addEventListener('click', function (event) {
                if (!event.target.closest('[data-ui-menu-toggle]')) setMenu(false);
            }, true);
        }

        var railBtn = document.querySelector('[data-ui-rail-toggle]');
        if (railBtn) {
            setRail(root.classList.contains('ui-rail'));
            railBtn.addEventListener('click', function () { setRail(!root.classList.contains('ui-rail')); });
        }

        // The active link in view on the desktop sidebar.
        var active = sidebar && sidebar.querySelector('[aria-current="page"]');
        if (active && !isPhone()) {
            var nav = sidebar.querySelector('.ui-nav');
            if (nav && active.offsetTop > nav.clientHeight - 80) nav.scrollTop = active.offsetTop - nav.clientHeight / 2;
        }

        var notifDialog = document.getElementById('ui-notifications');
        if (notifDialog) {
            notifDialog.addEventListener('ui:dialog-open', function () { loadNotifications('panel'); });
            notifDialog.addEventListener('click', onNotificationClick);
        }
        if (HOD.actions) {
            HOD.actions.on('ui.notifications.read-all', function (button) {
                HOD.busy(button, true);
                HOD.api(notifApi, { action: 'mark_all_read' }).then(function () {
                    setUnread(0);
                    renderNotifications([]);
                    HOD.toast('All notifications marked as read.', 'success');
                }).catch(function () {}).finally(function () { HOD.busy(button, false); });
            });
        }
        // The service worker tells open tabs when a push arrives.
        if (navigator.serviceWorker) {
            navigator.serviceWorker.addEventListener('message', function (event) {
                if (event.data && event.data.type === 'PUSH_RECEIVED') loadNotifications('push');
            });
        }
        // Count on load, without waiting for the panel to open. Deferred so
        // it never competes with the page's own first requests.
        setTimeout(function () { loadNotifications('check'); }, 800);

        // Live alerts: every 30 seconds in view, and straight away on coming
        // back to the app.
        setInterval(function () { checkSoon(POLL_MS - 1000); }, POLL_MS);
        document.addEventListener('visibilitychange', function () { checkSoon(5000); });
        window.addEventListener('focus', function () { checkSoon(5000); });
        window.addEventListener('online', function () { failures = 0; checkSoon(0); });
        ['pointerdown', 'keydown'].forEach(function (type) {
            document.addEventListener(type, unlockAudio, { capture: true, passive: true, once: true });
        });

        // Install entry: only where the browser can install, never inside the app.
        var install = document.querySelector('[data-ui-install]');
        if (install && !root.classList.contains('hod-standalone')) {
            window.addEventListener('beforeinstallprompt', function () { install.hidden = false; });
            if (/iphone|ipad|ipod/i.test(navigator.userAgent)) install.hidden = false;
            // Edge on Android installs from its own menu and sends no event;
            // pwa.js answers this entry with the guide to that menu.
            if (/Android/.test(navigator.userAgent) && /\bEdgA\//.test(navigator.userAgent)) install.hidden = false;
        }

        watchPageTitle();
    });
})();
