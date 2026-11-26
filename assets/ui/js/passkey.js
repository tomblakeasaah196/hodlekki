// /assets/ui/js/passkey.js
// Passkeys in the browser (includes/passkeys.php, api/passkey_api.php).
//
//   HOD.passkey.supported()           can this browser use passkeys at all?
//   HOD.passkey.platform()            Promise<bool>: a fingerprint/face/screen lock here?
//   HOD.passkey.has() / mark() / forget()
//                                     "this device signed in with, or made, a HOD passkey"
//                                     (localStorage hod.passkey; the sign-in page reads it
//                                     before it paints, to show the big fingerprint)
//   HOD.passkey.register(options)     make one now (options from the server), save it
//   HOD.passkey.signIn(options, o)    sign in with one; o.mediation 'conditional' for autofill
//
// It also runs two small screens, when their markup is on the page:
//   - the one-tap offer after a password sign-in (includes/header.php,
//     [data-passkey-offer]); options come with the page so the tap goes
//     straight to the phone's own prompt (iPhone needs the call inside the tap);
//   - the list in Profile ([data-passkey-list]).
(function () {
    'use strict';

    var HOD = window.HOD = window.HOD || {};
    var FLAG = 'hod.passkey';
    var SKIP = 'hod.passkey.skip';
    var API = '/api/passkey_api.php';

    /* ------------------------------------------------------------ bytes */

    function toBuf(b64u) {
        var s = String(b64u).replace(/-/g, '+').replace(/_/g, '/');
        while (s.length % 4) s += '=';
        var bin = atob(s);
        var out = new Uint8Array(bin.length);
        for (var i = 0; i < bin.length; i++) out[i] = bin.charCodeAt(i);
        return out.buffer;
    }

    function toB64u(buf) {
        if (!buf) return '';
        var bytes = new Uint8Array(buf);
        var bin = '';
        for (var i = 0; i < bytes.length; i++) bin += String.fromCharCode(bytes[i]);
        return btoa(bin).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    }

    /* ------------------------------------------------------- capability */

    function supported() {
        return !!(window.PublicKeyCredential && navigator.credentials
            && typeof navigator.credentials.create === 'function' && typeof navigator.credentials.get === 'function');
    }

    function platform() {
        if (!supported() || typeof PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable !== 'function') {
            return Promise.resolve(false);
        }
        return PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable().catch(function () { return false; });
    }

    function conditional() {
        if (!supported() || typeof PublicKeyCredential.isConditionalMediationAvailable !== 'function') {
            return Promise.resolve(false);
        }
        return PublicKeyCredential.isConditionalMediationAvailable().catch(function () { return false; });
    }

    // iPhone, iPad and Safari on a Mac only open the prompt from a tap.
    function needsTap() {
        var ua = navigator.userAgent || '';
        var ios = /iP(hone|ad|od)/.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
        var safari = /^((?!chrome|chromium|android|crios|fxios|edg).)*safari/i.test(ua);
        return ios || safari;
    }

    /* ---------------------------------------------------- device memory */

    function store(key, value) {
        try {
            if (value === null) localStorage.removeItem(key); else localStorage.setItem(key, value);
        } catch (e) { /* private mode: the page still works, just without the shortcut */ }
    }

    function read(key) {
        try { return localStorage.getItem(key); } catch (e) { return null; }
    }

    function has() { return read(FLAG) === '1'; }
    function mark() { store(FLAG, '1'); store(SKIP, null); }
    function forget() { store(FLAG, null); }

    /* ------------------------------------------------------------- ceremonies */

    function createOptions(o) {
        var pk = JSON.parse(JSON.stringify(o));
        pk.challenge = toBuf(o.challenge);
        pk.user.id = toBuf(o.user.id);
        pk.excludeCredentials = (o.excludeCredentials || []).map(function (c) {
            return { type: 'public-key', id: toBuf(c.id), transports: c.transports };
        });
        return { publicKey: pk };
    }

    function getOptions(o) {
        var pk = JSON.parse(JSON.stringify(o));
        pk.challenge = toBuf(o.challenge);
        return { publicKey: pk };
    }

    /** Make a passkey (must be called inside a tap on iPhone) and save it. */
    function register(options) {
        return navigator.credentials.create(createOptions(options)).then(function (cred) {
            var r = cred.response;
            if (typeof r.getPublicKey !== 'function' || typeof r.getAuthenticatorData !== 'function' || !r.getPublicKey()) {
                var old = new Error('This browser is too old for passkeys. Update it and try again.');
                old.code = 'UNSUPPORTED';
                throw old;
            }
            return HOD.api(API, { action: 'register_finish', json: true, quiet: true, data: { credential: {
                id: cred.id,
                clientDataJSON: toB64u(r.clientDataJSON),
                authenticatorData: toB64u(r.getAuthenticatorData()),
                publicKey: toB64u(r.getPublicKey()),
                publicKeyAlgorithm: r.getPublicKeyAlgorithm(),
                transports: typeof r.getTransports === 'function' ? r.getTransports() : []
            } } });
        }).then(function (res) {
            mark();
            return res;
        }, function (err) {
            // Already made for this account (a synced passkey): treat as done.
            if (err && err.name === 'InvalidStateError') {
                mark();
                return { status: 'success', already: true };
            }
            throw err;
        });
    }

    /** Sign in. Resolves with the server's answer ({redirect, returning}). */
    function signIn(options, o) {
        o = o || {};
        var req = getOptions(options);
        if (o.mediation) req.mediation = o.mediation;
        if (o.signal) req.signal = o.signal;
        return navigator.credentials.get(req).then(function (cred) {
            var r = cred.response;
            return HOD.api(API, { action: 'login_finish', json: true, quiet: true, data: { credential: {
                id: cred.id,
                clientDataJSON: toB64u(r.clientDataJSON),
                authenticatorData: toB64u(r.authenticatorData),
                signature: toB64u(r.signature),
                userHandle: r.userHandle ? toB64u(r.userHandle) : ''
            } } });
        }).then(function (res) {
            mark();
            return res;
        }, function (err) {
            if (err && err.code === 'UNKNOWN') forget();
            throw err;
        });
    }

    /** "the person closed the prompt" (or the browser refused without a tap) */
    function cancelled(err) {
        return !!err && (err.name === 'NotAllowedError' || err.name === 'AbortError');
    }

    HOD.passkey = {
        supported: supported, platform: platform, conditional: conditional, needsTap: needsTap,
        has: has, mark: mark, forget: forget, register: register, signIn: signIn, cancelled: cancelled,
        api: API
    };

    /* ------------------------------------------------ offer after sign-in */

    var offerOptions = null;

    function offerShow(card) {
        card.hidden = false;
        requestAnimationFrame(function () { card.classList.add('is-in'); });
    }

    function offerHide(card) {
        card.classList.remove('is-in');
        setTimeout(function () { card.hidden = true; }, 250);
    }

    function initOffer() {
        var card = document.querySelector('[data-passkey-offer]');
        if (!card) return;
        offerOptions = HOD.data && HOD.data('passkey-offer-options');
        var skipUntil = parseInt(read(SKIP) || '0', 10);
        if (!offerOptions || !supported() || has() || skipUntil > Date.now()) return;
        platform().then(function (ok) { if (ok) offerShow(card); });
    }

    if (HOD.actions) {
        HOD.actions.on('passkey.offer-on', function (btn) {
            var card = btn.closest('[data-passkey-offer]');
            if (!offerOptions) return;
            HOD.busy(btn, true);
            register(offerOptions).then(function () {
                offerHide(card);
                HOD.toast('Fingerprint sign-in is on.', { tone: 'success', icon: 'fingerprint' });
            }, function (err) {
                HOD.busy(btn, false);
                if (cancelled(err)) return; // closed the prompt: the card stays for another try
                offerHide(card);
                HOD.toast(err.message || 'That did not work. Try again in Profile.', 'warning');
            });
            offerOptions = null; // a challenge works once; Profile fetches a fresh one
        });

        HOD.actions.on('passkey.offer-skip', function (btn) {
            store(SKIP, String(Date.now() + 30 * 86400 * 1000));
            offerHide(btn.closest('[data-passkey-offer]'));
        });
    }

    /* ------------------------------------------------------ Profile list */

    var profileOptions = null;

    function when(sql) {
        if (!sql) return '';
        var d = new Date(String(sql).replace(' ', 'T'));
        return isNaN(d) ? '' : d.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' });
    }

    function renderList(box, list) {
        var empty = box.parentElement.querySelector('[data-passkey-empty]');
        if (empty) empty.hidden = list.length > 0;
        box.hidden = list.length === 0;
        box.innerHTML = list.map(function (p) {
            var meta = 'Added ' + when(p.created_at) + (p.last_used_at ? ' · used ' + when(p.last_used_at) : '');
            return '<li class="ui-list__item flex items-center gap-3">'
                + '<span class="text-ink-muted">' + HOD.icon('fingerprint') + '</span>'
                + '<span class="min-w-0 flex-1"><span class="block truncate font-medium text-ink">' + HOD.esc(p.label) + '</span>'
                + '<span class="ui-meta">' + HOD.esc(meta) + '</span></span>'
                + '<button type="button" class="ui-btn ui-btn--ghost ui-btn--icon ui-btn--sm" data-action="passkey.remove" data-id="' + p.id + '"'
                + ' aria-label="Remove ' + HOD.esc(p.label) + '">' + HOD.icon('trash-2') + '</button></li>';
        }).join('');
    }

    function refreshProfileOptions() {
        return HOD.api(API, { action: 'register_options', quiet: true }).then(function (res) {
            profileOptions = res.options;
        }, function () { profileOptions = null; });
    }

    function initProfile() {
        var box = document.querySelector('[data-passkey-list]');
        if (!box) return;
        var add = document.querySelector('[data-action="passkey.add"]');
        if (!supported()) {
            var none = document.querySelector('[data-passkey-unsupported]');
            if (none) none.hidden = false;
            if (add) add.hidden = true;
        }
        profileOptions = HOD.data && HOD.data('passkey-profile-options');
        HOD.api(API, { action: 'list', quiet: true }).then(function (res) { renderList(box, res.passkeys || []); }, function () {});
    }

    if (HOD.actions) {
        HOD.actions.on('passkey.add', function (btn) {
            if (!profileOptions) {
                HOD.toast('One moment, then tap again.', 'info');
                refreshProfileOptions();
                return;
            }
            var options = profileOptions;
            profileOptions = null;
            HOD.busy(btn, true);
            register(options).then(function (res) {
                HOD.toast(res.already ? 'This device already has one.' : 'Passkey added.', 'success');
                return HOD.api(API, { action: 'list', quiet: true }).then(function (r) {
                    renderList(document.querySelector('[data-passkey-list]'), r.passkeys || []);
                });
            }, function (err) {
                if (!cancelled(err)) HOD.toast(err.message || 'That did not work. Try again.', 'warning');
            }).then(function () {
                HOD.busy(btn, false);
                refreshProfileOptions();
            });
        });

        HOD.actions.on('passkey.remove', function (btn) {
            var id = btn.getAttribute('data-id');
            HOD.confirm({
                title: 'Remove passkey?',
                message: 'You can still sign in with your password.',
                confirmLabel: 'Remove',
                tone: 'danger'
            }).then(function (yes) {
                if (!yes) return;
                return HOD.api(API, { action: 'delete', data: { id: id } }).then(function (res) {
                    renderList(document.querySelector('[data-passkey-list]'), res.passkeys || []);
                    HOD.toast('Passkey removed.', 'success');
                });
            }).catch(function () {});
        });
    }

    function init() {
        initOffer();
        initProfile();
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
