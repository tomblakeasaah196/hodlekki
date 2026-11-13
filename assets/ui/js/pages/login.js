// /assets/ui/js/pages/login.js
// Sign-in (auth/login.php).
//
//   - Phones and tablets: the pinned Sign in button slides the form up as a
//     sheet (a modal dialog while open: the welcome goes inert, Escape, the
//     scrim, the close button, the back button or a drag down close it, and
//     focus returns to the button). It starts open when the page has a notice
//     to show or was asked for the form (?signin=1). Wide screens keep the
//     form on its panel and none of this runs.
//   - The scripture under the title rotates (KJV, from the page's JSON block).
//   - The pinned button rises above the install banner while that is shown.
//   - The form posts to /api/auth_api.php exactly as before (email, password,
//     remember). On success the API says where to go; when it is returning
//     the member to the page they asked for, the #fragment the browser
//     carried here is put back (it never reaches the server).
(function () {
    'use strict';

    var HOD = window.HOD;
    var sheetMq = window.matchMedia('(max-width: 1023.98px)');
    var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var root, panel, openBtn;
    var pushed = false;
    var returnTo = null;

    function $(sel) { return document.querySelector(sel); }
    function isOpen() { return root.classList.contains('is-open'); }

    /* -------------------------------------------------------------- sheet */

    function setInert(on) {
        [$('[data-si-welcome]'), $('.si-dock')].forEach(function (el) {
            if (!el) return;
            el.inert = on;
            if (on) el.setAttribute('aria-hidden', 'true'); else el.removeAttribute('aria-hidden');
        });
    }

    function openSheet(o) {
        o = o || {};
        if (!sheetMq.matches || isOpen()) return;
        returnTo = document.activeElement;
        root.classList.add('is-open');
        openBtn.setAttribute('aria-expanded', 'true');
        panel.setAttribute('role', 'dialog');
        panel.setAttribute('aria-modal', 'true');
        setInert(true);
        if (!o.quiet) {
            try { history.pushState({ siSheet: true }, ''); pushed = true; } catch (e) { pushed = false; }
        }
        // In the tap's own handler, so phones raise the keyboard straight away.
        (o.quiet ? panel : document.getElementById('email')).focus({ preventScroll: true });
    }

    function closeSheet(fromHistory) {
        if (!isOpen()) return;
        root.classList.remove('is-open', 'is-dragging');
        root.style.removeProperty('--si-drag');
        openBtn.setAttribute('aria-expanded', 'false');
        panel.removeAttribute('role');
        panel.removeAttribute('aria-modal');
        setInert(false);
        if (pushed && !fromHistory) {
            pushed = false;
            history.back();
        }
        pushed = false;
        if (sheetMq.matches) {
            var back = returnTo && returnTo !== document.body && document.contains(returnTo) ? returnTo : openBtn;
            back.focus({ preventScroll: true });
        }
    }

    // Drag the grip down to close; a short drag springs back.
    function dragToClose() {
        var grip = $('[data-si-grip]');
        if (!grip) return;
        var startY = 0, startT = 0, dy = 0, id = null;
        grip.addEventListener('pointerdown', function (e) {
            if (!isOpen()) return;
            id = e.pointerId;
            startY = e.clientY;
            startT = e.timeStamp;
            dy = 0;
            grip.setPointerCapture(id);
            root.classList.add('is-dragging');
        });
        grip.addEventListener('pointermove', function (e) {
            if (e.pointerId !== id) return;
            dy = Math.max(0, e.clientY - startY);
            root.style.setProperty('--si-drag', dy + 'px');
        });
        function end(e) {
            if (e.pointerId !== id) return;
            id = null;
            root.classList.remove('is-dragging');
            var fast = dy > 30 && dy / Math.max(1, e.timeStamp - startT) > 0.6;
            if (dy > 96 || fast) closeSheet(false);
            else root.style.setProperty('--si-drag', '0px');
        }
        grip.addEventListener('pointerup', end);
        grip.addEventListener('pointercancel', end);
    }

    // The install banner (assets/js/pwa.js) sits at the foot of the screen:
    // lift the pinned button above it while it is there.
    function liftAboveInstall() {
        var watched = null;
        var classes = new MutationObserver(update);
        function update() {
            var banner = document.getElementById('hodInstall');
            if (banner !== watched) {
                classes.disconnect();
                watched = banner;
                if (banner) classes.observe(banner, { attributes: true, attributeFilter: ['class'] });
            }
            // Where it comes to rest (its bottom offset and height), not where
            // its slide-in is now.
            var h = banner && banner.classList.contains('hod-open')
                ? (parseFloat(getComputedStyle(banner).bottom) || 0) + banner.offsetHeight : 0;
            root.style.setProperty('--si-lift', Math.round(h) + 'px');
        }
        new MutationObserver(update).observe(document.body, { childList: true });
        update();
    }

    /* ---------------------------------------------------------- scripture */

    function rotateScripture() {
        var box = $('[data-login-quote]');
        var list = HOD.data('si-scripture') || [];
        if (!box || list.length < 2) return;
        var text = box.querySelector('[data-quote-text]');
        var ref = box.querySelector('[data-quote-ref]');
        var at = 0;
        function show() {
            text.textContent = list[at][0];
            ref.textContent = list[at][1];
            box.classList.remove('is-out');
        }
        setInterval(function () {
            if (document.hidden) return;
            at = (at + 1) % list.length;
            if (reduceMotion) { show(); return; }
            box.classList.add('is-out');
            setTimeout(show, 200);
        }, 9000);
    }

    /* --------------------------------------------------------------- form */

    function setLabel(btn, text) {
        var spans = btn.querySelectorAll('span:not(.ui-btn__spinner)');
        if (spans.length) spans[spans.length - 1].textContent = text;
    }

    function showAlert(tone, title, text) {
        var box = document.getElementById('login-alert');
        if (!title && !text) { box.innerHTML = ''; return; }
        var icons = { success: 'circle-check', danger: 'circle-alert', info: 'info', warning: 'triangle-alert' };
        box.innerHTML = '<div class="ui-banner ui-banner--' + tone + '" role="' + (tone === 'danger' ? 'alert' : 'status') + '">'
            + HOD.icon(icons[tone] || 'info') + '<div class="ui-banner__body">'
            + (title ? '<p class="ui-banner__title">' + HOD.esc(title) + '</p>' : '')
            + (text ? '<p class="ui-banner__text">' + HOD.esc(text) + '</p>' : '')
            + '</div></div>';
        box.scrollIntoView({ block: 'nearest', behavior: reduceMotion ? 'auto' : 'smooth' });
    }

    HOD.actions.on('login.submit', function (form, event) {
        var btn = (event && event.submitter) || document.getElementById('login-submit');
        var data = {
            email: form.elements.email.value.trim(),
            password: form.elements.password.value
        };
        if (form.elements.remember.checked) data.remember = '1';

        showAlert();
        HOD.busy(btn, true);
        setLabel(btn, 'Signing in…');

        HOD.api('/api/auth_api.php', { data: data, quiet: true }).then(function (res) {
            showAlert('success', null, 'Welcome back.');
            setLabel(btn, 'Signed in');
            var destination = res.redirect || '/';
            if (res.returning && window.location.hash && destination.indexOf('#') === -1) {
                destination += window.location.hash;
            }
            setTimeout(function () { window.location.href = destination; }, 400);
        }).catch(function (err) {
            var message = err.code === 'NETWORK' ? 'No connection. Check it and try again.' : err.message;
            if (err.code === 'MOVED') showAlert('info', 'Use your personal email', message);
            else showAlert('danger', null, message);
            HOD.busy(btn, false);
            setLabel(btn, 'Sign in');
            if (err.code === 'MOVED') form.elements.email.focus();
        });
    });

    HOD.actions.on('si.reveal', function (btn) {
        var input = document.getElementById('password');
        var show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        btn.setAttribute('aria-pressed', show ? 'true' : 'false');
        btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        btn.innerHTML = HOD.icon(show ? 'eye-off' : 'eye');
        input.focus();
    });

    HOD.actions.on('si.open', function () { openSheet(); });
    HOD.actions.on('si.close', function () { closeSheet(false); });

    function init() {
        root = $('[data-si]');
        if (!root) return;
        panel = $('[data-si-panel]');
        openBtn = $('.si-open');

        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape' || !isOpen()) return;
            try {
                if (document.querySelector('.ui-info__pop:popover-open')) return; // the (i) closes first
            } catch (err) { /* no :popover-open here */ }
            closeSheet(false);
        });
        window.addEventListener('popstate', function () { if (isOpen()) closeSheet(true); });
        var onChange = function () { if (!sheetMq.matches) closeSheet(false); };
        if (sheetMq.addEventListener) sheetMq.addEventListener('change', onChange);
        else if (sheetMq.addListener) sheetMq.addListener(onChange);

        dragToClose();
        liftAboveInstall();
        rotateScripture();

        if (root.hasAttribute('data-si-open')) openSheet({ quiet: true });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
