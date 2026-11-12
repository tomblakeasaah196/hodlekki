// /assets/ui/js/pages/connect.js
// The Connect card (connect.php): three steps, checked here exactly as
// api/embrace_public_api.php checks them, step 1 also on the server
// (check_existing_user), then the card is posted as new FormData(form) so the
// form guard's form_ts, the honeypot and Turnstile's cf-turnstile-response go
// with it. Every error reply carries a fresh form_ts and a Turnstile token is
// single-use: both are refreshed on every error, and the page never reloads.
(function () {
    'use strict';

    var HOD = window.HOD;
    var API = '/api/embrace_public_api.php';
    var cfg = HOD.data('connect-config') || {};
    var SYSTEM_DOMAIN = cfg.systemDomain || '@hodlc.com';
    var NAME_PATTERN = /^\p{L}[\p{L}\p{M} .'’-]*$/u;
    var EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;
    var TITLES = ['Let’s get acquainted', 'Getting to know you', 'Your spiritual journey'];
    var TOTAL = 3;
    // Which step each field lives on, so an error can open the right one.
    var FIELD_STEPS = {
        first_name: 1, last_name: 1, phone: 1, email: 1,
        gender: 2, marital_status: 2, dob: 2, physical_address: 2,
        invited_by: 3, wants_to_join: 3, wants_visitation: 3, prayer_requests: 3
    };

    var step = 1;
    var busy = false;
    var errorField = null;
    var form = document.getElementById('connectForm');
    if (!form) return;

    function $(id) { return document.getElementById(id); }
    function todayISO() {
        var d = new Date();
        return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
    }
    function input(name) {
        if (name === 'physical_address') return $('connect-address').querySelector('input');
        var el = form.elements[name];
        return el && el.length && !el.tagName ? el[0] : el;
    }
    function val(name) { var el = form.elements[name]; return el ? String(el.value || '').trim() : ''; }

    /* ----- Address: whatever is typed counts; a suggestion adds coordinates. */

    function setAddress(text, lat, lng) {
        $('connectAddress').value = text || '';
        $('connectLat').value = lat || '';
        $('connectLng').value = lng || '';
    }
    function plainAddress() {
        $('connect-address').innerHTML = '<input class="ui-input" type="text" id="connect-address-text" maxlength="255" placeholder="Your street, estate or area" autocomplete="street-address">';
    }
    function initAddress() {
        var box = $('connect-address');
        box.addEventListener('input', function (e) {
            var text = String(e.target.value || '').trim();
            if (text !== $('connectAddress').value) setAddress(text);
        });
        // Enter picks a suggestion; it must not submit or advance the wizard.
        box.addEventListener('keydown', function (e) { if (e.key === 'Enter') e.preventDefault(); });
        HOD.lib('geoapify').then(function () {
            if (!window.autocomplete) throw new Error('Geoapify did not load');
            var widget = new window.autocomplete.GeocoderAutocomplete(box, cfg.geoapifyKey, {
                placeholder: 'Start typing your street or estate…',
                filter: { countrycode: ['ng'] }
            });
            var field = box.querySelector('input');
            if (field) { field.id = 'connect-address-text'; field.setAttribute('autocomplete', 'off'); }
            widget.on('select', function (loc) {
                if (loc) setAddress(loc.properties.formatted, loc.properties.lat, loc.properties.lon);
                else setAddress('');
            });
        }).catch(function () {
            // Suggestions unavailable (blocked, offline): a plain box still works.
            plainAddress();
        });
    }

    /* ----- Errors -------------------------------------------------------- */

    function errorSlot(name) {
        if (name === 'physical_address') return $('connect-address-error');
        var el = input(name);
        return el && el.id ? $(el.id + '-error') : null;
    }
    function clearFieldError(name) {
        if (!name) return;
        var el = input(name);
        if (el) el.removeAttribute('aria-invalid');
        var slot = errorSlot(name);
        if (slot) slot.textContent = '';
        if (name === errorField) dismissError();
    }
    function dismissError() {
        $('connect-error').hidden = true;
        errorField = null;
    }
    function clearErrors() {
        Array.prototype.forEach.call(form.querySelectorAll('[aria-invalid="true"]'), function (el) { el.removeAttribute('aria-invalid'); });
        Array.prototype.forEach.call(form.querySelectorAll('.ui-field-error'), function (p) { p.textContent = ''; });
        dismissError();
    }
    // Opens the step holding the field, marks it, shows the message under it
    // and in the banner, and puts the cursor there so it can be fixed.
    function showError(message, name, opts) {
        opts = opts || {};
        clearErrors();
        if (name && FIELD_STEPS[name]) goTo(FIELD_STEPS[name]);
        errorField = name && FIELD_STEPS[name] ? name : null;
        $('connect-error-text').textContent = message;
        $('connect-error-login').hidden = !opts.exists;
        $('connect-error').hidden = false;
        if (!errorField) { $('connect-error').scrollIntoView({ behavior: 'smooth', block: 'center' }); return; }
        var el = input(errorField);
        var slot = errorSlot(errorField);
        if (slot) slot.textContent = message;
        if (el) {
            el.setAttribute('aria-invalid', 'true');
            setTimeout(function () { el.scrollIntoView({ behavior: 'smooth', block: 'center' }); el.focus(); }, 50);
        }
    }
    // A reply that isn't success: refresh form_ts and Turnstile, then show its message on its field.
    function showServerError(res) {
        if (res && typeof res === 'object' && res.form_ts) form.elements.form_ts.value = res.form_ts;
        if (window.turnstile && form.querySelector('.cf-turnstile')) { try { window.turnstile.reset(); } catch (e) { /* not rendered yet */ } }
        if (res && typeof res === 'object' && res.message) showError(res.message, res.field, { exists: !!res.exists });
        else showError('We got an unexpected reply from the server. Please try again, or ask an usher for help.');
    }
    // The request itself failed: say why instead of a blanket "connection failed".
    function showFailure(err) {
        if (err && err.response && err.response.message) { showServerError(err.response); return; }
        var message;
        if (!navigator.onLine || (err && err.code === 'OFFLINE')) {
            message = 'You seem to be offline. Please check your internet connection and try again. Nothing you typed has been lost.';
        } else if (err && err.name === 'AbortError') {
            message = 'The server is taking too long to respond. Please try again in a moment.';
        } else if (err && err.code === 'NETWORK') {
            message = 'We couldn’t reach the server. Please check your internet connection and try again.';
        } else if (err && /^HTTP_/.test(String(err.code || ''))) {
            message = 'The server ran into a problem (error ' + String(err.code).replace('HTTP_', '') + '). Please try again in a moment, or ask an usher for help.';
        } else {
            message = 'The server sent back an unexpected reply. Please try again, or ask an usher for help.';
        }
        // Even without a reply, a Turnstile token may have been spent.
        if (window.turnstile && form.querySelector('.cf-turnstile')) { try { window.turnstile.reset(); } catch (e) { /* not rendered */ } }
        showError(message);
    }

    // Editing a flagged field clears its error.
    function onEdit(e) {
        var t = e.target;
        if (!(t instanceof Element)) return;
        clearFieldError(t.closest('#connect-address') ? 'physical_address' : t.name);
    }
    form.addEventListener('input', onEdit);
    form.addEventListener('change', onEdit);

    /* ----- Validation (mirrors api/embrace_public_api.php) ----------------- */

    function validate(s) {
        if (s === 1) {
            var names = [['first_name', 'first name'], ['last_name', 'last name']];
            for (var i = 0; i < names.length; i++) {
                var v = val(names[i][0]), label = names[i][1];
                if (!v) return { name: names[i][0], message: 'Please enter your ' + label + '.' };
                if (v.length > 50) return { name: names[i][0], message: 'Your ' + label + ' is too long. Please keep it under 50 characters.' };
                if (!NAME_PATTERN.test(v)) return { name: names[i][0], message: 'Your ' + label + ' can only contain letters, spaces, hyphens and apostrophes.' };
            }
            var phone = val('phone');
            var digits = phone.replace(/\D/g, '');
            if (!phone) return { name: 'phone', message: 'Please enter your phone number so we can reach you.' };
            if (!/^\+?[0-9 ().-]+$/.test(phone)) return { name: 'phone', message: 'Please use only digits in your phone number, e.g. 0803 123 4567 or +234 803 123 4567.' };
            if (digits.length < 9 || digits.length > 15) return { name: 'phone', message: 'Please enter a valid phone number (9 to 15 digits), e.g. 0803 123 4567.' };
            var email = val('email').toLowerCase();
            if (email) {
                if (email.length > 100 || !EMAIL_PATTERN.test(email)) return { name: 'email', message: 'That email address doesn’t look right. Please check it (e.g. name@gmail.com), or leave it blank.' };
                if (email.slice(-SYSTEM_DOMAIN.length) === SYSTEM_DOMAIN) return { name: 'email', message: SYSTEM_DOMAIN + ' addresses are issued by the church. Please enter your personal email (e.g. name@gmail.com), or leave it blank.' };
            }
        }
        if (s === 2) {
            // A half-typed date reads as '' but is flagged by the browser as bad input.
            var dob = form.elements.dob;
            if (dob.validity && dob.validity.badInput) return { name: 'dob', message: 'Your date of birth is incomplete. Please finish it, or clear it.' };
            if (dob.value && dob.value > todayISO()) return { name: 'dob', message: 'Your date of birth can’t be in the future.' };
            if (dob.value && dob.value < '1900-01-01') return { name: 'dob', message: 'Please enter a valid date of birth, or leave it blank.' };
            if (val('physical_address').length > 255) return { name: 'physical_address', message: 'Your address is too long. Please shorten it (255 characters max).' };
        }
        if (s === 3) {
            if (val('invited_by').length > 150) return { name: 'invited_by', message: 'Your answer to “Who invited you?” is too long. Please shorten it (150 characters max).' };
            if (val('prayer_requests').length > 2000) return { name: 'prayer_requests', message: 'Your prayer request is too long. Please shorten it (2,000 characters max).' };
        }
        return null;
    }

    /* ----- Steps ---------------------------------------------------------- */

    function goTo(s) {
        step = s;
        Array.prototype.forEach.call(form.querySelectorAll('[data-step]'), function (el) { el.hidden = Number(el.getAttribute('data-step')) !== s; });
        $('connect-wizard-title').textContent = TITLES[s - 1];
        $('connect-step-label').textContent = 'Step ' + s + ' of ' + TOTAL;
        Array.prototype.forEach.call(document.querySelectorAll('[data-dot]'), function (d) {
            var on = Number(d.getAttribute('data-dot')) <= s;
            d.classList.toggle('bg-hodRed', on);
            d.classList.toggle('bg-line-strong', !on);
        });
        $('connect-prev').hidden = s === 1;
        $('connect-next').hidden = s === TOTAL;
        $('connect-submit').hidden = s !== TOTAL;
    }

    function withTimeout(ms) {
        var ctrl = window.AbortController ? new AbortController() : null;
        var timer = ctrl ? setTimeout(function () { ctrl.abort(); }, ms) : null;
        return { signal: ctrl ? ctrl.signal : undefined, done: function () { clearTimeout(timer); } };
    }

    // Step 1 is checked on the server (existing phone or email) before moving on.
    function checkIdentity() {
        var btn = $('connect-next');
        busy = true;
        HOD.busy(btn, true);
        var t = withTimeout(20000);
        HOD.api(API, {
            data: { action: 'check_existing_user', first_name: form.elements.first_name.value, last_name: form.elements.last_name.value,
                phone: form.elements.phone.value, email: form.elements.email.value },
            quiet: true, signal: t.signal
        }).then(function () {
            clearErrors();
            goTo(2);
        }).catch(showFailure).then(function () { t.done(); busy = false; HOD.busy(btn, false); });
    }

    function next() {
        if (busy) return;
        var problem = validate(step);
        if (problem) { showError(problem.message, problem.name); return; }
        if (step === 1) { checkIdentity(); return; }
        clearErrors();
        goTo(Math.min(TOTAL, step + 1));
    }

    HOD.actions.on('connect.start', function () {
        $('connect-landing').hidden = true;
        $('connect-wizard').hidden = false;
        window.scrollTo(0, 0);
        setTimeout(function () { form.elements.first_name.focus(); }, 50);
    });
    HOD.actions.on('connect.cancel', function () {
        clearErrors();
        goTo(1);
        $('connect-wizard').hidden = true;
        $('connect-landing').hidden = false;
    });
    HOD.actions.on('connect.next', next);
    HOD.actions.on('connect.back', function () {
        if (busy) return;
        clearErrors();
        goTo(Math.max(1, step - 1));
    });
    HOD.actions.on('connect.dismiss', dismissError);

    // Enter moves to the next step (or sends the card on the last one).
    form.addEventListener('keydown', function (e) {
        var t = e.target;
        if (e.key !== 'Enter' || !(t instanceof HTMLInputElement) || t.type === 'checkbox' || t.closest('#connect-address')) return;
        e.preventDefault();
        if (step < TOTAL) next();
        else form.requestSubmit ? form.requestSubmit($('connect-submit')) : $('connect-submit').click();
    });

    HOD.actions.on('connect.submit', function (f, event) {
        if (busy) return;
        if (step < TOTAL) { next(); return; }
        // Re-check every step: the first problem found opens its step.
        for (var s = 1; s <= TOTAL; s++) {
            var problem = validate(s);
            if (problem) { showError(problem.message, problem.name); return; }
        }
        var btn = (event && event.submitter) || $('connect-submit');
        busy = true;
        clearErrors();
        HOD.busy(btn, true);
        var t = withTimeout(30000);
        HOD.api(API, { data: new FormData(form), quiet: true, signal: t.signal }).then(function () {
            $('connect-wizard').hidden = true;
            $('connect-success').hidden = false;
            window.scrollTo(0, 0);
        }).catch(showFailure).then(function () { t.done(); busy = false; HOD.busy(btn, false); });
    });

    // A guest who just checked in at a service (checkin.js) arrives with their
    // name and phone kept on this device: fill them in and open the card.
    // Kept in sessionStorage for 30 minutes, used once, never in the address.
    function prefillFromCheckin() {
        var saved = null;
        try {
            saved = JSON.parse(sessionStorage.getItem('hod.connect.prefill') || 'null');
            sessionStorage.removeItem('hod.connect.prefill');
        } catch (e) { return; }
        if (!saved || typeof saved !== 'object' || Date.now() - Number(saved.at || 0) > 30 * 60 * 1000) return;
        ['first_name', 'last_name', 'phone'].forEach(function (name) {
            var el = form.elements[name];
            if (el && !el.value && typeof saved[name] === 'string') el.value = saved[name].slice(0, 50);
        });
        HOD.actions.run('connect.start');
    }

    function init() {
        form.elements.dob.setAttribute('max', todayISO());
        initAddress();
        goTo(1);
        prefillFromCheckin();
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
