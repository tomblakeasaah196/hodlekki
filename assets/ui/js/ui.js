// /assets/ui/js/ui.js
// ===========================================================================
// HOD Lekki — the shared component behaviours (docs/UI.md).
//
// Plain JavaScript, no dependencies, loaded `defer` on every page of the
// members' app. Everything hangs off window.HOD:
//
//   HOD.api(url, opts)          fetch + CSRF + JSON + friendly errors
//   HOD.toast(msg, opts)        a toast (success | danger | warning | info)
//   HOD.dialog.open/close(id)   native <dialog> with transitions + focus return
//   HOD.confirm(opts)           await a yes/no, named destructive verbs
//   HOD.prompt(opts)            await a line of text (replaces window.prompt)
//   HOD.actions.on(name, fn)    event delegation for data-action="name"
//   HOD.busy(button, on)        spinner on a button while work happens
//   HOD.form.validate/setErrors blur-first validation and server errors
//   HOD.format.*                numbers, naira, dates
//   HOD.icon(name)              an icon from the sprite, as markup
//   HOD.notes.open/close(id)    the floating notes panel (ui_notetaker())
//
// Also, with no code on the page: the CSRF token rides on every same-origin
// write (fetch, XMLHttpRequest — jQuery included — and POST forms); tabs
// ([data-ui-tabs]), menus ([data-menu-trigger]), searchable selects
// (select[data-ui-combobox]), dialogs ([data-dialog-open] / [data-dialog-close]),
// the on-screen keyboard fix and the offline state are wired automatically.
//
// No inline handlers anywhere: the CSP refuses them on rebuilt pages.
// ===========================================================================
(function () {
    'use strict';

    var HOD = window.HOD = window.HOD || {};
    var root = document.documentElement;
    var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    /* ------------------------------------------------------------------ *
     * Basics
     * ------------------------------------------------------------------ */

    function data(id) {
        var el = document.getElementById(id);
        if (!el) return null;
        try { return JSON.parse(el.textContent || 'null'); } catch (e) { return null; }
    }

    var config = data('hod-ui') || {};

    function esc(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }

    function spriteUrl() {
        return config.sprite || '/assets/ui/icons.svg';
    }

    function icon(name, cls) {
        return '<svg class="ui-icon' + (cls ? ' ' + esc(cls) : '') + '" aria-hidden="true" focusable="false">' +
            '<use href="' + esc(spriteUrl()) + '#' + esc(name) + '"></use></svg>';
    }

    function token() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') || '' : '';
    }

    function sameOrigin(url) {
        try { return new URL(url, window.location.href).origin === window.location.origin; } catch (e) { return false; }
    }

    function isWrite(method) {
        return !/^(GET|HEAD|OPTIONS)$/i.test(method || 'GET');
    }

    function debounce(fn, wait) {
        var timer = null;
        return function () {
            var self = this, args = arguments;
            clearTimeout(timer);
            timer = setTimeout(function () { fn.apply(self, args); }, wait);
        };
    }

    function ready(fn) {
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fn);
        else fn();
    }

    HOD.data = data;
    HOD.config = config;
    HOD.esc = esc;
    HOD.icon = icon;
    HOD.csrf = token;
    HOD.debounce = debounce;
    HOD.ready = ready;

    /* ------------------------------------------------------------------ *
     * CSRF on every same-origin write — fetch, XHR (and so jQuery), forms
     * ------------------------------------------------------------------ */

    if (window.fetch && !window.fetch.__hodCsrf) {
        var nativeFetch = window.fetch;
        var wrappedFetch = function (input, init) {
            try {
                var isRequest = typeof Request !== 'undefined' && input instanceof Request;
                var method = (init && init.method) || (isRequest ? input.method : 'GET');
                var url = isRequest ? input.url : String(input);
                if (isWrite(method) && sameOrigin(url) && token()) {
                    var headers = new Headers((init && init.headers) || (isRequest ? input.headers : undefined));
                    if (!headers.has('X-CSRF-Token')) headers.set('X-CSRF-Token', token());
                    init = Object.assign({}, init || {}, { headers: headers });
                }
            } catch (e) { /* never break a request over the token */ }
            return nativeFetch.call(this, input, init);
        };
        wrappedFetch.__hodCsrf = true;
        window.fetch = wrappedFetch;
    }

    if (window.XMLHttpRequest && !XMLHttpRequest.prototype.__hodCsrf) {
        var xhrOpen = XMLHttpRequest.prototype.open;
        var xhrSend = XMLHttpRequest.prototype.send;
        var xhrSet = XMLHttpRequest.prototype.setRequestHeader;
        XMLHttpRequest.prototype.open = function (method, url) {
            this.__hodMethod = method;
            this.__hodUrl = url;
            this.__hodHasCsrf = false;
            return xhrOpen.apply(this, arguments);
        };
        XMLHttpRequest.prototype.setRequestHeader = function (name) {
            if (String(name).toLowerCase() === 'x-csrf-token') this.__hodHasCsrf = true;
            return xhrSet.apply(this, arguments);
        };
        XMLHttpRequest.prototype.send = function () {
            try {
                if (!this.__hodHasCsrf && isWrite(this.__hodMethod) && sameOrigin(this.__hodUrl) && token()) {
                    xhrSet.call(this, 'X-CSRF-Token', token());
                }
            } catch (e) { /* see above */ }
            return xhrSend.apply(this, arguments);
        };
        XMLHttpRequest.prototype.__hodCsrf = true;
    }

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!(form instanceof HTMLFormElement)) return;
        if (String(form.getAttribute('method') || 'get').toLowerCase() !== 'post') return;
        if (!sameOrigin(form.getAttribute('action') || window.location.href)) return;
        if (form.querySelector('input[name="_csrf"]') || !token()) return;
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = '_csrf';
        input.value = token();
        form.appendChild(input);
    }, true);

    /* ------------------------------------------------------------------ *
     * Toasts
     * ------------------------------------------------------------------ */

    var TOAST_ICONS = { success: 'circle-check', danger: 'circle-alert', warning: 'triangle-alert', info: 'info' };
    var TOAST_CLASS = { success: 'ui-toast--success', danger: 'ui-toast--danger', warning: 'ui-toast--warning', info: '' };

    function toastHost() {
        var host = document.getElementById('ui-toasts');
        if (!host) {
            host = document.createElement('div');
            host.id = 'ui-toasts';
            host.className = 'ui-toasts';
            host.setAttribute('aria-live', 'polite');
            host.setAttribute('popover', 'manual');
            host.setAttribute('data-ui-keep-live', '');
            document.body.appendChild(host);
        }
        return host;
    }

    function topOpenDialog() {
        var open = document.querySelectorAll('dialog[open]');
        return open.length ? open[open.length - 1] : null;
    }

    // A toast must never sit under a dialog. Where the Popover API exists the
    // region is re-shown in the top layer, above any open <dialog>; elsewhere
    // it moves into the open dialog. (Legacy modals are plain elements: the
    // z-index in app.css clears them, and modal-manager.js leaves the region
    // live because of data-ui-keep-live.)
    function liftToasts(host) {
        if (!host.children.length) {
            if (typeof host.hidePopover === 'function') {
                try { host.hidePopover(); } catch (e) { /* not showing */ }
            }
            return;
        }
        if (typeof host.showPopover === 'function') {
            try {
                if (host.matches(':popover-open')) host.hidePopover();
                host.showPopover();
                return;
            } catch (e) { /* fall through */ }
        }
        var target = topOpenDialog() || document.body;
        if (host.parentNode !== target) target.appendChild(host);
    }

    document.addEventListener('ui:dialog-open', function () {
        var host = document.getElementById('ui-toasts');
        if (host) liftToasts(host);
    });

    /**
     * HOD.toast('Saved') · HOD.toast('Could not save', 'danger')
     * HOD.toast('Member archived', { tone: 'success', action: { label: 'Undo', run: fn } })
     * HOD.toast('New sermon: Ground Zero', { icon: 'bell', detail: 'Pastor Ebele · Sun 8 Nov' })
     *   (detail: a second, quieter line; icon: any sprite icon name)
     */
    function toast(message, opts) {
        if (typeof opts === 'string') opts = { tone: opts };
        opts = opts || {};
        var tone = opts.tone === 'error' ? 'danger' : (TOAST_ICONS[opts.tone] ? opts.tone : 'info');
        var host = toastHost();
        var glyph = (typeof opts.icon === 'string' && /^[a-z0-9-]+$/.test(opts.icon)) ? opts.icon : TOAST_ICONS[tone];
        var text = String(message == null ? '' : message);

        var node = document.createElement('div');
        node.className = 'ui-toast ' + TOAST_CLASS[tone];
        node.setAttribute('role', tone === 'danger' ? 'alert' : 'status');
        if (opts.detail) {
            node.innerHTML = icon(glyph) + '<div class="ui-toast__message"><strong class="ui-toast__title"></strong><span class="ui-toast__detail"></span></div>';
            node.querySelector('.ui-toast__title').textContent = text;
            node.querySelector('.ui-toast__detail').textContent = String(opts.detail);
        } else {
            node.innerHTML = icon(glyph) + '<p class="ui-toast__message"></p>';
            node.querySelector('.ui-toast__message').textContent = text;
        }

        if (opts.action && opts.action.label) {
            var act = document.createElement('button');
            act.type = 'button';
            act.className = 'ui-toast__action';
            act.textContent = opts.action.label;
            act.addEventListener('click', function () {
                try { opts.action.run(); } finally { close(); }
            });
            node.appendChild(act);
        }
        var x = document.createElement('button');
        x.type = 'button';
        x.className = 'ui-toast__close';
        x.setAttribute('aria-label', 'Dismiss');
        x.innerHTML = icon('x');
        x.addEventListener('click', close);
        node.appendChild(x);

        while (host.children.length >= 3) host.removeChild(host.firstChild);
        host.appendChild(node);
        liftToasts(host);
        requestAnimationFrame(function () { node.classList.add('is-open'); });

        var duration = opts.duration || (opts.action ? 9000 : (tone === 'danger' ? 7000 : 4500));
        var timer = setTimeout(close, duration);
        node.addEventListener('mouseenter', function () { clearTimeout(timer); });
        node.addEventListener('mouseleave', function () { timer = setTimeout(close, 2500); });
        node.addEventListener('focusin', function () { clearTimeout(timer); });

        var closed = false;
        function close() {
            if (closed) return;
            closed = true;
            clearTimeout(timer);
            node.classList.remove('is-open');
            setTimeout(function () {
                if (node.parentNode) node.parentNode.removeChild(node);
                liftToasts(host);
            }, reduceMotion ? 0 : 220);
        }
        return { close: close };
    }

    HOD.toast = toast;

    /* ------------------------------------------------------------------ *
     * API calls
     * ------------------------------------------------------------------ */

    /**
     * HOD.api('/api/congregation_api.php', { action: 'save_member', data: {...} })
     *   .then(function (res) { ... res.data ... })
     *
     * Resolves with the parsed JSON when status === 'success'. Rejects with an
     * Error carrying .code, .field and .response otherwise, and shows the
     * message as a toast unless { quiet: true }. Form-encoded by default (what
     * the endpoints read from $_POST); { json: true } sends JSON; a FormData
     * passes through untouched (uploads). { headers: {...} } adds request
     * headers (Charis sends its own CSRF header this way).
     */
    function api(url, opts) {
        opts = opts || {};
        var method = (opts.method || 'POST').toUpperCase();
        var headers = { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
        Object.keys(opts.headers || {}).forEach(function (key) { headers[key] = opts.headers[key]; });
        var body;
        var payload = opts.data || {};

        if (!navigator.onLine && isWrite(method)) {
            var off = new Error("You're offline. Try again when you're connected — nothing was sent.");
            off.code = 'OFFLINE';
            if (!opts.quiet) toast(off.message, 'warning');
            return Promise.reject(off);
        }

        if (method === 'GET') {
            var query = new URLSearchParams(payload);
            if (opts.action) query.set('action', opts.action);
            var qs = query.toString();
            if (qs) url += (url.indexOf('?') === -1 ? '?' : '&') + qs;
        } else if (payload instanceof FormData) {
            body = payload;
            if (opts.action && !body.has('action')) body.append('action', opts.action);
        } else if (opts.json) {
            headers['Content-Type'] = 'application/json';
            body = JSON.stringify(Object.assign(opts.action ? { action: opts.action } : {}, payload));
        } else {
            var form = new URLSearchParams();
            if (opts.action) form.set('action', opts.action);
            Object.keys(payload).forEach(function (key) {
                var value = payload[key];
                if (Array.isArray(value)) value.forEach(function (v) { form.append(key + '[]', v); });
                else if (value !== undefined && value !== null) form.append(key, value);
            });
            body = form;
        }

        return fetch(url, {
            method: method,
            headers: headers,
            body: body,
            credentials: 'same-origin',
            signal: opts.signal
        }).then(function (response) {
            return response.text().then(function (text) {
                var json = null;
                try { json = text ? JSON.parse(text) : null; } catch (e) { json = null; }
                if (response.ok && json && (json.status === 'success' || json.success === true || opts.raw)) return json;

                var err = new Error(
                    (json && json.message) ||
                    (response.status === 401 || response.status === 403 ? 'Your session has ended. Reload the page to sign in again.' :
                    'Something went wrong on our side. Please try again in a moment.')
                );
                err.code = (json && json.code) || ('HTTP_' + response.status);
                err.field = json && json.field;
                err.response = json;
                throw err;
            });
        }, function (networkError) {
            if (networkError && networkError.name === 'AbortError') throw networkError;
            var err = new Error("We couldn't reach the server. Check your connection and try again.");
            err.code = 'NETWORK';
            throw err;
        }).catch(function (err) {
            if (err && err.name !== 'AbortError') {
                // For the error and health monitor (assets/js/monitor.js may
                // listen): which endpoint failed and how. The path only — no
                // query string, no payload, no personal data.
                try {
                    window.dispatchEvent(new CustomEvent('hod:api-error', { detail: {
                        path: new URL(url, window.location.href).pathname,
                        action: opts.action || null,
                        code: err.code || 'UNKNOWN'
                    } }));
                } catch (e) { /* never mask the real error */ }
                if (!opts.quiet) toast(err.message, 'danger');
            }
            throw err;
        });
    }

    HOD.api = api;

    /* ------------------------------------------------------------------ *
     * Busy buttons
     * ------------------------------------------------------------------ */

    function busy(button, on) {
        if (!button) return;
        if (on) {
            if (button.getAttribute('aria-busy') === 'true') return;
            button.setAttribute('aria-busy', 'true');
            button.disabled = true;
            var spin = document.createElement('span');
            spin.className = 'ui-btn__spinner';
            spin.setAttribute('aria-hidden', 'true');
            button.appendChild(spin);
        } else {
            button.removeAttribute('aria-busy');
            button.disabled = false;
            var s = button.querySelector('.ui-btn__spinner');
            if (s) s.remove();
        }
    }

    HOD.busy = busy;

    /* ------------------------------------------------------------------ *
     * Dialogs (native <dialog>)
     * ------------------------------------------------------------------ */

    var openers = new WeakMap();

    function resolveDialog(target) {
        return typeof target === 'string' ? document.getElementById(target) : target;
    }

    function openDialog(target, opener) {
        var dialog = resolveDialog(target);
        if (!dialog || dialog.hasAttribute('open')) return dialog;
        openers.set(dialog, opener || document.activeElement);
        if (typeof dialog.showModal === 'function') {
            dialog.showModal();
        } else {
            // No <dialog> support (Safari before 15.4): a fixed panel over a scrim.
            dialog.setAttribute('open', '');
            dialog.classList.add('ui-dialog--fallback');
            var scrim = document.createElement('div');
            scrim.className = 'ui-dialog-scrim';
            scrim.addEventListener('click', function () { closeDialog(dialog, 'backdrop'); });
            dialog.parentNode.insertBefore(scrim, dialog);
            dialog.__scrim = scrim;
        }
        var auto = dialog.querySelector('[autofocus], [data-autofocus]');
        if (auto) auto.focus();
        requestAnimationFrame(function () { dialog.classList.add('is-open'); });
        root.classList.add('ui-dialog-open');
        dialog.dispatchEvent(new CustomEvent('ui:dialog-open', { bubbles: true }));
        return dialog;
    }

    function closeDialog(target, value, immediate) {
        var dialog = resolveDialog(target);
        if (!dialog || !dialog.hasAttribute('open') || dialog.__closing) return;
        dialog.__closing = true;
        dialog.classList.remove('is-open');
        setTimeout(function () {
            dialog.__closing = false;
            if (typeof dialog.close === 'function') dialog.close(value);
            else dialog.removeAttribute('open');
            if (dialog.__scrim) { dialog.__scrim.remove(); dialog.__scrim = null; }
            if (!document.querySelector('dialog[open]')) root.classList.remove('ui-dialog-open');
            var opener = openers.get(dialog);
            if (opener && opener.isConnected && typeof opener.focus === 'function') opener.focus({ preventScroll: true });
            dialog.dispatchEvent(new CustomEvent('ui:dialog-close', { bubbles: true, detail: { value: value } }));
        }, reduceMotion || immediate ? 0 : 180);
    }

    // Escape: animate out instead of the browser's instant close.
    document.addEventListener('cancel', function (event) {
        var dialog = event.target;
        if (!(dialog instanceof HTMLElement) || !dialog.classList.contains('ui-dialog')) return;
        event.preventDefault();
        if (dialog.hasAttribute('data-dialog-static')) return;
        closeDialog(dialog, 'cancel');
    }, true);

    // A tap on the backdrop closes a dialog that holds no form (a half-filled
    // form is never thrown away by a stray tap).
    document.addEventListener('click', function (event) {
        var dialog = event.target;
        if (!dialog || dialog.tagName !== 'DIALOG' || !dialog.classList.contains('ui-dialog')) return;
        if (dialog.hasAttribute('data-dialog-static') || dialog.querySelector('form')) return;
        var rect = dialog.getBoundingClientRect();
        var inside = event.clientX >= rect.left && event.clientX <= rect.right &&
            event.clientY >= rect.top && event.clientY <= rect.bottom;
        if (!inside) closeDialog(dialog, 'backdrop');
    });

    HOD.dialog = { open: openDialog, close: closeDialog };

    /* ------------------------------------------------------------------ *
     * Confirm / prompt / alert — awaitable replacements for the natives
     * ------------------------------------------------------------------ */

    var seq = 0;

    function buildDialog(opts, body) {
        var id = 'ui-ask-' + (++seq);
        var danger = opts.tone === 'danger';
        var dialog = document.createElement('dialog');
        dialog.className = 'ui-dialog ui-dialog--sm' + (danger ? ' ui-confirm--danger' : '');
        dialog.id = id;
        dialog.setAttribute('aria-labelledby', id + '-t');
        dialog.setAttribute('data-modal-ignore', '');
        if (opts.message) dialog.setAttribute('aria-describedby', id + '-d');
        dialog.innerHTML =
            '<div class="ui-dialog__header">' +
                '<span class="ui-confirm__icon">' + icon(opts.icon || (danger ? 'triangle-alert' : 'circle-help')) + '</span>' +
                '<div class="ui-dialog__heading">' +
                    '<h2 class="ui-dialog__title" id="' + id + '-t"></h2>' +
                    (opts.message ? '<p class="ui-dialog__description" id="' + id + '-d"></p>' : '') +
                '</div>' +
            '</div>' +
            (body ? '<div class="ui-dialog__body">' + body + '</div>' : '<div class="ui-dialog__body" hidden></div>') +
            '<div class="ui-dialog__footer">' +
                (opts.cancelLabel === false ? '' : '<button type="button" class="ui-btn ui-btn--secondary" data-ask="cancel"></button>') +
                '<button type="button" class="ui-btn ' + (danger ? 'ui-btn--danger' : 'ui-btn--primary') + '" data-ask="ok"></button>' +
            '</div>';
        dialog.querySelector('.ui-dialog__title').textContent = opts.title || 'Are you sure?';
        if (opts.message) dialog.querySelector('.ui-dialog__description').textContent = opts.message;
        var cancel = dialog.querySelector('[data-ask="cancel"]');
        if (cancel) cancel.textContent = opts.cancelLabel || 'Cancel';
        dialog.querySelector('[data-ask="ok"]').textContent = opts.confirmLabel || 'Continue';
        document.body.appendChild(dialog);
        return dialog;
    }

    function ask(dialog, onOk) {
        return new Promise(function (resolve) {
            var settled = false;
            function finish(value) {
                if (settled) return;
                settled = true;
                closeDialog(dialog, 'done');
                setTimeout(function () { dialog.remove(); }, 400);
                resolve(value);
            }
            dialog.addEventListener('click', function (event) {
                var b = event.target.closest('[data-ask]');
                if (!b) return;
                if (b.getAttribute('data-ask') === 'ok') {
                    var value = onOk();
                    if (value === undefined) return; // validation kept it open
                    finish(value);
                } else {
                    finish(null);
                }
            });
            dialog.addEventListener('ui:dialog-close', function () { finish(null); });
            openDialog(dialog);
        });
    }

    /**
     * if (await HOD.confirm({ title: 'Delete this member?', message: '…',
     *     confirmLabel: 'Delete member', tone: 'danger' })) { … }
     * requireText: 'DELETE' makes the person type it first (for the
     * irreversible ones).
     */
    function confirmAsk(opts) {
        opts = typeof opts === 'string' ? { title: opts } : (opts || {});
        var body = '';
        if (opts.requireText) {
            body = '<div class="ui-field"><label class="ui-label" for="ui-ask-req-' + (seq + 1) + '">Type <strong>' +
                esc(opts.requireText) + '</strong> to confirm</label>' +
                '<input class="ui-input" id="ui-ask-req-' + (seq + 1) + '" autocomplete="off" autocapitalize="off" spellcheck="false" data-autofocus></div>';
        }
        var dialog = buildDialog(opts, body);
        var ok = dialog.querySelector('[data-ask="ok"]');
        if (opts.requireText) {
            var field = dialog.querySelector('input');
            ok.disabled = true;
            field.addEventListener('input', function () { ok.disabled = field.value.trim() !== opts.requireText; });
        } else {
            ok.setAttribute('autofocus', '');
        }
        return ask(dialog, function () { return true; }).then(function (v) { return v === true; });
    }

    /** var reason = await HOD.prompt({ title: 'Reject requisition', label: 'Reason', required: true }) */
    function promptAsk(opts) {
        opts = opts || {};
        var fid = 'ui-ask-in-' + (seq + 1);
        var control = opts.multiline
            ? '<textarea class="ui-textarea" id="' + fid + '" data-autofocus></textarea>'
            : '<input class="ui-input" id="' + fid + '" autocomplete="off" data-autofocus>';
        var body = '<div class="ui-field"><label class="ui-label" for="' + fid + '"></label>' + control +
            '<p class="ui-field-error" aria-live="polite"></p></div>';
        var dialog = buildDialog(Object.assign({ icon: 'pencil', confirmLabel: 'Save' }, opts), body);
        var input = dialog.querySelector('#' + fid);
        dialog.querySelector('label').textContent = opts.label || 'Your answer';
        if (opts.placeholder) input.setAttribute('placeholder', opts.placeholder);
        if (opts.value) input.value = opts.value;
        return ask(dialog, function () {
            var value = input.value.trim();
            if (opts.required && !value) {
                input.setAttribute('aria-invalid', 'true');
                dialog.querySelector('.ui-field-error').textContent = (opts.label || 'This') + ' is needed to continue.';
                input.focus();
                return undefined;
            }
            return value;
        });
    }

    function alertAsk(opts) {
        opts = typeof opts === 'string' ? { title: opts } : (opts || {});
        var dialog = buildDialog(Object.assign({ icon: 'info', confirmLabel: 'Got it', cancelLabel: false }, opts), '');
        return ask(dialog, function () { return true; }).then(function () {});
    }

    HOD.confirm = confirmAsk;
    HOD.prompt = promptAsk;
    HOD.alert = alertAsk;

    /* ------------------------------------------------------------------ *
     * Delegated actions: data-action="name" + HOD.actions.on('name', fn)
     * ------------------------------------------------------------------ */

    var handlers = {};

    HOD.actions = {
        on: function (name, fn) { handlers[name] = fn; },
        run: function (name, el, event) { return handlers[name] ? handlers[name](el, event) : undefined; }
    };

    function snapshotGuard(event) {
        if (!root.classList.contains('ui-is-snapshot')) return false;
        event.preventDefault();
        toast("This is a saved copy for reading while you're offline. Reconnect to make changes.", 'warning');
        return true;
    }

    document.addEventListener('click', function (event) {
        var target = event.target instanceof Element ? event.target : null;
        if (!target) return;

        var opener = target.closest('[data-dialog-open]');
        if (opener) {
            event.preventDefault();
            openDialog(opener.getAttribute('data-dialog-open'), opener);
            return;
        }
        var closer = target.closest('[data-dialog-close]');
        if (closer) {
            event.preventDefault();
            closeDialog(closer.closest('dialog'), 'close');
            return;
        }

        var el = target.closest('[data-action]');
        if (!el || el.tagName === 'FORM') return;
        if (el.disabled || el.getAttribute('aria-disabled') === 'true') return;
        var name = el.getAttribute('data-action');
        if (!handlers[name]) return;
        if (snapshotGuard(event)) return;
        event.preventDefault();
        handlers[name](el, event);
    });

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!(form instanceof HTMLFormElement)) return;
        if (root.classList.contains('ui-is-snapshot')) { snapshotGuard(event); return; }
        if (form.hasAttribute('data-ui-validate') && !validateForm(form)) {
            event.preventDefault();
            return;
        }
        var name = form.getAttribute('data-action');
        if (name && handlers[name]) {
            event.preventDefault();
            handlers[name](form, event);
        }
    });

    document.addEventListener('change', function (event) {
        var el = event.target instanceof Element ? event.target.closest('[data-action-change]') : null;
        if (!el) return;
        var name = el.getAttribute('data-action-change');
        if (handlers[name]) handlers[name](el, event);
    });

    document.addEventListener('input', function (event) {
        var el = event.target instanceof Element ? event.target.closest('[data-action-input]') : null;
        if (!el) return;
        var name = el.getAttribute('data-action-input');
        if (!handlers[name]) return;
        clearTimeout(el.__hodInputTimer);
        el.__hodInputTimer = setTimeout(function () { handlers[name](el, event); }, 200);
    });

    /* ------------------------------------------------------------------ *
     * Forms: blur-first validation, server field errors
     * ------------------------------------------------------------------ */

    function fieldLabel(control) {
        var label = control.id ? document.querySelector('label[for="' + CSS.escape(control.id) + '"]') : null;
        var text = label ? label.childNodes[0] && label.childNodes[0].textContent : control.name;
        return String(text || 'This field').trim();
    }

    function errorSlot(control) {
        var ids = (control.getAttribute('aria-describedby') || '').split(/\s+/);
        for (var i = 0; i < ids.length; i++) {
            var el = ids[i] && document.getElementById(ids[i]);
            if (el && el.classList.contains('ui-field-error')) return el;
        }
        var field = control.closest('.ui-field');
        return field ? field.querySelector('.ui-field-error') : null;
    }

    function messageFor(control) {
        var v = control.validity;
        var label = fieldLabel(control);
        if (control.getAttribute('data-error')) return control.getAttribute('data-error');
        if (v.valueMissing) return (control.tagName === 'SELECT' ? 'Choose ' : 'Enter ') + label.charAt(0).toLowerCase() + label.slice(1) + '.';
        if (v.typeMismatch && control.type === 'email') return 'Enter an email address like name@example.com.';
        if (v.typeMismatch && control.type === 'url') return 'Enter a web address starting with https://.';
        if (v.tooShort) return label + ' needs at least ' + control.minLength + ' characters.';
        if (v.rangeUnderflow) return label + ' must be ' + control.min + ' or more.';
        if (v.rangeOverflow) return label + ' must be ' + control.max + ' or less.';
        if (v.patternMismatch) return 'Check the format of ' + label.toLowerCase() + '.';
        return control.validationMessage;
    }

    function setFieldError(control, message) {
        var slot = errorSlot(control);
        if (message) {
            control.setAttribute('aria-invalid', 'true');
            if (slot) slot.textContent = message;
        } else {
            control.removeAttribute('aria-invalid');
            if (slot) slot.textContent = '';
        }
    }

    function checkControl(control) {
        if (!control.willValidate) return true;
        var ok = control.checkValidity();
        setFieldError(control, ok ? '' : messageFor(control));
        return ok;
    }

    function validateForm(form) {
        var first = null;
        Array.prototype.forEach.call(form.elements, function (control) {
            if (!checkControl(control) && !first) first = control;
        });
        if (first) {
            first.focus();
            if (first.scrollIntoView) first.scrollIntoView({ block: 'center', behavior: reduceMotion ? 'auto' : 'smooth' });
        }
        return !first;
    }

    // Blur first: a field is judged when the person leaves it, and an error
    // clears the moment the value becomes valid.
    document.addEventListener('blur', function (event) {
        var control = event.target;
        if (!control || !control.form || !control.form.hasAttribute('data-ui-validate')) return;
        if (control.value !== '' || control.hasAttribute('aria-invalid')) checkControl(control);
    }, true);
    document.addEventListener('input', function (event) {
        var control = event.target;
        if (control && control.getAttribute && control.getAttribute('aria-invalid') === 'true' && control.checkValidity && control.checkValidity()) {
            setFieldError(control, '');
        }
    });

    HOD.form = {
        validate: validateForm,
        /** Show server-side errors: { phone: 'This number is already registered.' } */
        setErrors: function (form, errors) {
            var first = null;
            Object.keys(errors || {}).forEach(function (name) {
                var control = form.elements[name];
                if (!control) return;
                if (control.length && !control.tagName) control = control[0];
                setFieldError(control, errors[name]);
                if (!first) first = control;
            });
            if (first) first.focus();
        },
        clearErrors: function (form) {
            Array.prototype.forEach.call(form.elements, function (c) { if (c.removeAttribute) setFieldError(c, ''); });
        },
        /** The form's fields as a plain object (checkbox groups as arrays). */
        values: function (form) {
            var out = {};
            new FormData(form).forEach(function (value, key) {
                if (key.slice(-2) === '[]') {
                    key = key.slice(0, -2);
                    (out[key] = out[key] || []).push(value);
                } else {
                    out[key] = value;
                }
            });
            return out;
        }
    };

    /* ------------------------------------------------------------------ *
     * Tabs: [data-ui-tabs] with buttons [data-tab], panels [data-tab-panel]
     * ------------------------------------------------------------------ */

    function tabsIn(list) {
        return Array.prototype.slice.call(list.querySelectorAll('[role="tab"][data-tab]'));
    }

    function selectTab(list, id, focus) {
        var tabs = tabsIn(list);
        var found = tabs.some(function (t) { return t.getAttribute('data-tab') === id; });
        if (!found) return false;
        tabs.forEach(function (tab) {
            var on = tab.getAttribute('data-tab') === id;
            tab.setAttribute('aria-selected', on ? 'true' : 'false');
            tab.tabIndex = on ? 0 : -1;
            if (on && focus) tab.focus();
            // On a phone the tab bar scrolls sideways: bring the chosen tab in.
            if (on && list.scrollWidth > list.clientWidth) {
                var tr = tab.getBoundingClientRect(), lr = list.getBoundingClientRect();
                if (tr.left < lr.left || tr.right > lr.right) list.scrollLeft += (tr.left - lr.left) - 16;
            }
            var panel = document.getElementById(tab.getAttribute('aria-controls')) ||
                document.querySelector('[data-tab-panel="' + CSS.escape(tab.getAttribute('data-tab')) + '"]');
            if (panel) {
                panel.hidden = !on;
                panel.setAttribute('role', 'tabpanel');
                panel.setAttribute('aria-labelledby', tab.id);
                if (!panel.hasAttribute('tabindex')) panel.tabIndex = 0;
            }
        });
        list.dispatchEvent(new CustomEvent('ui:tab', { bubbles: true, detail: { tab: id } }));
        return true;
    }

    function hashTab() {
        var raw = (window.location.hash || '').replace(/^#/, '');
        try { return new URLSearchParams(raw).get('tab'); } catch (e) { return null; }
    }

    function initTabs(scope) {
        (scope || document).querySelectorAll('[data-ui-tabs]').forEach(function (list) {
            if (list.__hodTabs) return;
            list.__hodTabs = true;
            var tabs = tabsIn(list);
            if (!tabs.length) return;
            var wanted = hashTab();
            var current = tabs.filter(function (t) { return t.getAttribute('aria-selected') === 'true'; })[0] || tabs[0];
            var known = wanted && tabs.some(function (t) { return t.getAttribute('data-tab') === wanted; });
            selectTab(list, known ? wanted : current.getAttribute('data-tab'), false);

            list.addEventListener('click', function (event) {
                var tab = event.target.closest('[role="tab"][data-tab]');
                if (!tab) return;
                selectTab(list, tab.getAttribute('data-tab'), false);
                // The shared deep link: a reload, a bookmark or the search
                // palette lands on this tab again.
                if (!list.hasAttribute('data-ui-tabs-nohash')) {
                    history.replaceState(null, '', '#tab=' + encodeURIComponent(tab.getAttribute('data-tab')));
                }
            });
            list.addEventListener('keydown', function (event) {
                var tabs = tabsIn(list);
                var i = tabs.indexOf(document.activeElement);
                if (i === -1) return;
                var next = null;
                if (event.key === 'ArrowRight') next = tabs[(i + 1) % tabs.length];
                else if (event.key === 'ArrowLeft') next = tabs[(i - 1 + tabs.length) % tabs.length];
                else if (event.key === 'Home') next = tabs[0];
                else if (event.key === 'End') next = tabs[tabs.length - 1];
                if (!next) return;
                event.preventDefault();
                selectTab(list, next.getAttribute('data-tab'), true);
            });
        });
    }

    window.addEventListener('hashchange', function () {
        var wanted = hashTab();
        if (!wanted) return;
        document.querySelectorAll('[data-ui-tabs]').forEach(function (list) { selectTab(list, wanted, false); });
    });

    HOD.tabs = { init: initTabs, select: function (listOrId, id) {
        var list = typeof listOrId === 'string' ? document.getElementById(listOrId) : listOrId;
        return list ? selectTab(list, id, false) : false;
    } };

    /* ------------------------------------------------------------------ *
     * Menus: <button data-menu-trigger aria-controls="m1"> + <div class="ui-menu" id="m1" hidden>
     * ------------------------------------------------------------------ */

    var openMenu = null;

    function menuItems(menu) {
        return Array.prototype.slice.call(menu.querySelectorAll('.ui-menu__item:not([disabled]):not([hidden])'));
    }

    function placeMenu(menu, trigger) {
        var r = trigger.getBoundingClientRect();
        menu.style.visibility = 'hidden';
        menu.hidden = false;
        var w = menu.offsetWidth, h = menu.offsetHeight;
        var vw = document.documentElement.clientWidth, vh = window.innerHeight;
        var left = Math.min(Math.max(8, r.right - w), vw - w - 8);
        if (menu.getAttribute('data-align') === 'start') left = Math.min(Math.max(8, r.left), vw - w - 8);
        var top = r.bottom + 6;
        if (top + h > vh - 8 && r.top - h - 6 > 8) top = r.top - h - 6;
        menu.style.left = left + 'px';
        menu.style.top = top + 'px';
        menu.style.visibility = '';
    }

    function closeMenu(restoreFocus) {
        if (!openMenu) return;
        var m = openMenu;
        openMenu = null;
        m.menu.classList.remove('is-open');
        m.trigger.setAttribute('aria-expanded', 'false');
        m.menu.hidden = true;
        if (restoreFocus) m.trigger.focus();
    }

    function showMenu(trigger) {
        var menu = document.getElementById(trigger.getAttribute('aria-controls'));
        if (!menu) return;
        if (openMenu && openMenu.menu === menu) { closeMenu(true); return; }
        closeMenu(false);
        if (menu.parentNode !== document.body) document.body.appendChild(menu);
        menu.setAttribute('role', menu.getAttribute('role') || 'menu');
        placeMenu(menu, trigger);
        trigger.setAttribute('aria-expanded', 'true');
        openMenu = { menu: menu, trigger: trigger };
        requestAnimationFrame(function () { menu.classList.add('is-open'); });
        var items = menuItems(menu);
        items.forEach(function (item) { item.setAttribute('role', 'menuitem'); item.tabIndex = -1; });
        if (items[0]) items[0].focus({ preventScroll: true });
    }

    document.addEventListener('click', function (event) {
        var trigger = event.target instanceof Element && event.target.closest('[data-menu-trigger]');
        if (trigger) {
            event.preventDefault();
            showMenu(trigger);
            return;
        }
        if (openMenu) {
            var inside = event.target instanceof Element && openMenu.menu.contains(event.target);
            if (!inside || event.target.closest('.ui-menu__item')) closeMenu(false);
        }
    });
    document.addEventListener('keydown', function (event) {
        if (!openMenu) return;
        var items = menuItems(openMenu.menu);
        var i = items.indexOf(document.activeElement);
        if (event.key === 'Escape') { event.preventDefault(); closeMenu(true); }
        else if (event.key === 'ArrowDown') { event.preventDefault(); (items[i + 1] || items[0]).focus(); }
        else if (event.key === 'ArrowUp') { event.preventDefault(); (items[i - 1] || items[items.length - 1]).focus(); }
        else if (event.key === 'Tab') closeMenu(false);
    });
    window.addEventListener('resize', function () { closeMenu(false); });
    document.addEventListener('scroll', function () { closeMenu(false); }, true);

    /* ------------------------------------------------------------------ *
     * Searchable select: <select data-ui-combobox> (long lists)
     * ------------------------------------------------------------------ */

    function fold(s) {
        return String(s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
    }

    function enhanceSelect(select) {
        if (select.__hodCombo || select.multiple) return;
        select.__hodCombo = true;

        var wrap = document.createElement('div');
        wrap.className = 'ui-combobox';
        var input = document.createElement('input');
        input.type = 'text';
        input.className = 'ui-input ui-combobox__input';
        input.setAttribute('role', 'combobox');
        input.setAttribute('aria-autocomplete', 'list');
        input.setAttribute('aria-expanded', 'false');
        input.setAttribute('autocomplete', 'off');
        input.setAttribute('spellcheck', 'false');
        var listId = (select.id || ('combo' + (++seq))) + '-list';
        input.setAttribute('aria-controls', listId);
        if (select.id) {
            var label = document.querySelector('label[for="' + CSS.escape(select.id) + '"]');
            input.id = select.id + '-search';
            if (label) label.setAttribute('for', input.id);
        }
        ['aria-describedby', 'aria-invalid', 'placeholder'].forEach(function (a) {
            if (select.hasAttribute(a)) input.setAttribute(a, select.getAttribute(a));
        });
        if (!input.placeholder) input.placeholder = select.getAttribute('data-placeholder') || 'Type to search…';
        input.disabled = select.disabled;

        var list = document.createElement('ul');
        list.className = 'ui-combobox__list';
        list.id = listId;
        list.setAttribute('role', 'listbox');
        list.hidden = true;

        select.parentNode.insertBefore(wrap, select);
        wrap.appendChild(input);
        wrap.appendChild(list);
        wrap.appendChild(select);
        select.hidden = true;
        select.tabIndex = -1;

        var active = -1;
        var shown = [];

        function options() {
            return Array.prototype.filter.call(select.options, function (o) { return o.value !== '' || o.text.trim() !== ''; });
        }
        function syncInput() {
            var opt = select.options[select.selectedIndex];
            input.value = opt && opt.value !== '' ? opt.text : '';
        }
        function render() {
            var q = fold(input.value);
            var current = select.value;
            shown = options().filter(function (o) { return o.value !== '' && (!q || fold(o.text).indexOf(q) !== -1); }).slice(0, 100);
            if (!shown.length) {
                list.innerHTML = '<li class="ui-combobox__empty">No matches. Try fewer letters.</li>';
            } else {
                list.innerHTML = shown.map(function (o, i) {
                    return '<li class="ui-combobox__option' + (i === active ? ' is-active' : '') + '" role="option" id="' + listId + '-' + i + '"' +
                        ' aria-selected="' + (o.value === current) + '"' + (o.disabled ? ' aria-disabled="true"' : '') +
                        ' data-i="' + i + '">' + esc(o.text) + '</li>';
                }).join('');
            }
            input.setAttribute('aria-activedescendant', active >= 0 ? listId + '-' + active : '');
            var a = list.querySelector('.is-active');
            if (a) a.scrollIntoView({ block: 'nearest' });
        }
        function open() {
            if (!list.hidden) return;
            list.hidden = false;
            input.setAttribute('aria-expanded', 'true');
            active = -1;
            render();
        }
        function close(revert) {
            list.hidden = true;
            input.setAttribute('aria-expanded', 'false');
            input.removeAttribute('aria-activedescendant');
            if (revert) syncInput();
        }
        function choose(i) {
            var o = shown[i];
            if (!o || o.disabled) return;
            select.value = o.value;
            select.dispatchEvent(new Event('change', { bubbles: true }));
            syncInput();
            close(false);
            setFieldError(input, '');
        }

        input.addEventListener('focus', function () { input.select(); open(); });
        input.addEventListener('input', function () { active = 0; open(); render(); });
        input.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown') { e.preventDefault(); open(); active = Math.min(active + 1, shown.length - 1); render(); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); active = Math.max(active - 1, 0); render(); }
            else if (e.key === 'Enter') { if (!list.hidden && active >= 0) { e.preventDefault(); choose(active); } }
            else if (e.key === 'Escape') { if (!list.hidden) { e.preventDefault(); e.stopPropagation(); close(true); } }
            else if (e.key === 'Tab') { close(true); }
        });
        input.addEventListener('blur', function () { setTimeout(function () { if (!wrap.contains(document.activeElement)) close(true); }, 120); });
        list.addEventListener('pointerdown', function (e) { e.preventDefault(); });
        list.addEventListener('click', function (e) {
            var li = e.target.closest('[data-i]');
            if (li) choose(parseInt(li.getAttribute('data-i'), 10));
        });
        select.addEventListener('change', syncInput);
        select.addEventListener('invalid', function () { setFieldError(input, messageFor(select)); });
        syncInput();
        select.__hodRefresh = function () { syncInput(); if (!list.hidden) render(); input.disabled = select.disabled; };
    }

    HOD.combobox = {
        enhance: enhanceSelect,
        refresh: function (select) { if (select && select.__hodRefresh) select.__hodRefresh(); }
    };

    /* ------------------------------------------------------------------ *
     * Tooltips: any [data-tooltip] on a device with a pointer that hovers.
     * One floating element in <body>, so a scrolling sidebar or a dialog
     * never clips it. The rail shows its labels only while collapsed.
     * ------------------------------------------------------------------ */

    var tipEl = null, tipFor = null, tipTimer = null;
    var canHover = window.matchMedia && window.matchMedia('(hover: hover) and (pointer: fine)').matches;

    function hideTip() {
        clearTimeout(tipTimer);
        tipFor = null;
        if (tipEl) tipEl.classList.remove('is-open');
    }

    function showTip(target) {
        var text = target.getAttribute('data-tooltip');
        if (!text) return;
        // Nav labels are already visible unless the sidebar is a rail; an entry
        // with a description (data-tooltip-info) shows it on hover either way.
        var described = target.hasAttribute('data-tooltip-info');
        if (target.classList.contains('ui-nav__item') && !root.classList.contains('ui-rail') && !described) return;
        if (target.closest('.ui-sidebar') && !root.classList.contains('ui-rail') && target.getAttribute('data-tooltip-side') === 'right' && !described) return;
        if (!tipEl) {
            tipEl = document.createElement('div');
            tipEl.className = 'ui-tooltip';
            tipEl.setAttribute('aria-hidden', 'true');
            document.body.appendChild(tipEl);
        }
        tipEl.textContent = text;
        var r = target.getBoundingClientRect();
        var side = target.getAttribute('data-tooltip-side') || 'top';
        tipEl.style.left = '0px';
        tipEl.style.top = '0px';
        var w = tipEl.offsetWidth, h = tipEl.offsetHeight;
        var vw = document.documentElement.clientWidth, vh = window.innerHeight;
        var left, top;
        if (side === 'right') { left = r.right + 10; top = r.top + (r.height - h) / 2; }
        else if (side === 'bottom') { left = r.left + (r.width - w) / 2; top = r.bottom + 8; }
        else { left = r.left + (r.width - w) / 2; top = r.top - h - 8; if (top < 4) top = r.bottom + 8; }
        tipEl.style.left = Math.round(Math.min(Math.max(4, left), vw - w - 4)) + 'px';
        tipEl.style.top = Math.round(Math.min(Math.max(4, top), vh - h - 4)) + 'px';
        tipEl.classList.add('is-open');
    }

    if (canHover) {
        document.addEventListener('mouseover', function (event) {
            var target = event.target instanceof Element ? event.target.closest('[data-tooltip]') : null;
            if (target === tipFor) return;
            hideTip();
            if (!target) return;
            tipFor = target;
            tipTimer = setTimeout(function () { if (tipFor === target) showTip(target); }, 350);
        });
        document.addEventListener('focusin', function (event) {
            var target = event.target instanceof Element ? event.target.closest('[data-tooltip]') : null;
            hideTip();
            var keyboard = false;
            try { keyboard = !!target && target.matches(':focus-visible'); } catch (e) { keyboard = false; }
            if (keyboard) { tipFor = target; showTip(target); }
        });
        document.addEventListener('focusout', hideTip);
        document.addEventListener('scroll', hideTip, true);
        document.addEventListener('pointerdown', hideTip);
    }

    /* ------------------------------------------------------------------ *
     * Info (i) — ui_info(). The detail behind short copy. On a computer it
     * shows on hover or keyboard focus; a click (or a tap on a phone) pins
     * it open; Escape, a click elsewhere or the next (i) closes it. The text
     * sits in the top layer (Popover API) so an open dialog never covers
     * it; where that API is missing it is a fixed box, which still works.
     * The text is also the button's aria-describedby.
     * ------------------------------------------------------------------ */

    var info = { wrap: null, pinned: false, timer: null };

    function infoParts(wrap) {
        return { btn: wrap.querySelector('.ui-info__btn'), pop: wrap.querySelector('.ui-info__pop') };
    }

    function infoPlace(wrap) {
        var p = infoParts(wrap);
        var r = p.btn.getBoundingClientRect();
        if (r.bottom < 0 || r.top > window.innerHeight) { infoHide(); return; }
        p.pop.style.left = '0px';
        p.pop.style.top = '0px';
        var w = p.pop.offsetWidth, h = p.pop.offsetHeight;
        var vw = document.documentElement.clientWidth, vh = window.innerHeight;
        var left = Math.min(Math.max(8, r.left + r.width / 2 - 24), vw - w - 8);
        var top = r.bottom + 8;
        if (top + h > vh - 8 && r.top - h - 8 >= 8) top = r.top - h - 8;
        p.pop.style.left = Math.round(left) + 'px';
        p.pop.style.top = Math.round(Math.max(8, top)) + 'px';
    }

    function infoHide() {
        clearTimeout(info.timer);
        var wrap = info.wrap;
        info.wrap = null;
        info.pinned = false;
        if (!wrap) return;
        var p = infoParts(wrap);
        if (p.pop) {
            p.pop.classList.remove('is-open');
            if (typeof p.pop.hidePopover === 'function') { try { p.pop.hidePopover(); } catch (e) { /* not open */ } }
        }
        if (p.btn) p.btn.setAttribute('aria-expanded', 'false');
    }

    function infoShow(wrap, pin) {
        clearTimeout(info.timer);
        if (info.wrap && info.wrap !== wrap) infoHide();
        var p = infoParts(wrap);
        if (!p.btn || !p.pop) return;
        info.pinned = info.wrap === wrap ? (info.pinned || !!pin) : !!pin;
        info.wrap = wrap;
        p.pop.classList.add('is-open');
        if (typeof p.pop.showPopover === 'function') { try { p.pop.showPopover(); } catch (e) { /* already open */ } }
        p.btn.setAttribute('aria-expanded', 'true');
        infoPlace(wrap);
    }

    function infoFrom(node) {
        return node instanceof Element ? node.closest('[data-ui-info]') : null;
    }

    document.addEventListener('click', function (event) {
        var btn = event.target instanceof Element ? event.target.closest('.ui-info__btn') : null;
        if (btn) {
            event.preventDefault();
            var wrap = infoFrom(btn);
            if (info.wrap === wrap && info.pinned) infoHide(); else infoShow(wrap, true);
            return;
        }
        if (info.wrap && !info.wrap.contains(event.target)) infoHide();
    });
    if (canHover) {
        document.addEventListener('mouseover', function (event) {
            var wrap = infoFrom(event.target);
            if (!wrap) return;
            if (info.wrap === wrap) clearTimeout(info.timer);
            else infoShow(wrap, false);
        });
        document.addEventListener('mouseout', function (event) {
            var wrap = infoFrom(event.target);
            if (!wrap || wrap !== info.wrap || info.pinned || wrap.contains(event.relatedTarget)) return;
            info.timer = setTimeout(function () { if (info.wrap === wrap && !info.pinned) infoHide(); }, 150);
        });
    }
    document.addEventListener('focusin', function (event) {
        var btn = event.target instanceof Element ? event.target.closest('.ui-info__btn') : null;
        if (!btn) return;
        var keyboard = false;
        try { keyboard = btn.matches(':focus-visible'); } catch (e) { keyboard = false; }
        if (keyboard) infoShow(infoFrom(btn), false);
    });
    document.addEventListener('focusout', function (event) {
        var wrap = infoFrom(event.target);
        if (wrap && wrap === info.wrap && !info.pinned && !wrap.contains(event.relatedTarget)) infoHide();
    });
    // Escape closes the (i) first — and only it, not the dialog around it.
    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape' || !info.wrap) return;
        var btn = infoParts(info.wrap).btn;
        var hadFocus = info.wrap.contains(document.activeElement);
        event.preventDefault();
        event.stopPropagation();
        infoHide();
        if (hadFocus && btn) btn.focus({ preventScroll: true });
    }, true);
    document.addEventListener('scroll', function () { if (info.wrap) infoPlace(info.wrap); }, true);
    window.addEventListener('resize', function () { if (info.wrap) infoPlace(info.wrap); });
    document.addEventListener('ui:dialog-close', function (event) {
        if (info.wrap && event.target instanceof Element && event.target.contains(info.wrap)) infoHide();
    });

    HOD.info = { close: infoHide };

    /* ------------------------------------------------------------------ *
     * Libraries on demand: HOD.lib('chart' | 'cropper' | 'geoapify' |
     * 'html2canvas' | 'xlsx' | 'sortable') — vendored, hashed, loaded once,
     * only when a page actually needs them.
     * ------------------------------------------------------------------ */

    var loading = {};

    function load(src, isCss) {
        if (loading[src]) return loading[src];
        loading[src] = new Promise(function (resolve, reject) {
            var el = document.createElement(isCss ? 'link' : 'script');
            if (isCss) { el.rel = 'stylesheet'; el.href = src; } else { el.src = src; }
            el.onload = function () { resolve(); };
            el.onerror = function () { delete loading[src]; reject(new Error('Could not load ' + src)); };
            document.head.appendChild(el);
        });
        return loading[src];
    }

    HOD.load = load;
    HOD.lib = function (name) {
        var map = config.vendor || {};
        if (!map[name]) return Promise.reject(new Error('Unknown library ' + name));
        var css = map[name + '.css'];
        return Promise.all([load(map[name], false), css ? load(css, true) : null]);
    };

    /* ------------------------------------------------------------------ *
     * Charts: Chart.js with the brand's quiet defaults.
     *   HOD.chart(canvas, { type: 'line', data: …, options: … }).then(chart => …)
     * Colours in order: navy, red, blue, green, amber, then softer tints.
     * ------------------------------------------------------------------ */

    HOD.palette = ['#1D356A', '#D11920', '#4A6BB0', '#157A4A', '#D98A00', '#7690C6', '#E2777B', '#9AA2B2'];
    // For ordered steps (first timer → worker, a pipeline): one navy ramp,
    // darkest first, so no step reads as an alarm.
    HOD.ramp = ['#0A0E17', '#1D356A', '#33549A', '#4A6BB0', '#7690C6', '#A6B8DD', '#CCD7EE', '#E5EBF7'];
    var chartDefaultsSet = false;

    HOD.chart = function (canvas, cfg) {
        return HOD.lib('chart').then(function () {
            var Chart = window.Chart;
            if (!chartDefaultsSet) {
                chartDefaultsSet = true;
                var css = getComputedStyle(root);
                Chart.defaults.font.family = css.getPropertyValue('--ui-font-sans').trim() || 'Inter, sans-serif';
                Chart.defaults.font.size = 12;
                Chart.defaults.color = '#6E778A';
                Chart.defaults.borderColor = '#E7EAF0';
                Chart.defaults.maintainAspectRatio = false;
                Chart.defaults.animation.duration = reduceMotion ? 0 : 450;
                Chart.defaults.plugins.legend.labels.usePointStyle = true;
                Chart.defaults.plugins.legend.labels.boxWidth = 8;
                Chart.defaults.plugins.legend.labels.boxHeight = 8;
                Chart.defaults.plugins.legend.labels.padding = 16;
                Chart.defaults.plugins.tooltip.backgroundColor = '#0A0E17';
                Chart.defaults.plugins.tooltip.padding = 10;
                Chart.defaults.plugins.tooltip.cornerRadius = 8;
                Chart.defaults.plugins.tooltip.titleFont = { weight: '600' };
                Chart.defaults.plugins.tooltip.displayColors = false;
                Chart.defaults.elements.line.tension = 0.35;
                Chart.defaults.elements.line.borderWidth = 2;
                Chart.defaults.elements.point.radius = 0;
                Chart.defaults.elements.point.hoverRadius = 4;
                Chart.defaults.elements.bar.borderRadius = 6;
                Chart.defaults.scale.grid.drawTicks = false;
                Chart.defaults.scale.border.display = false;
            }
            var existing = Chart.getChart(canvas);
            if (existing) existing.destroy();
            return new Chart(canvas, cfg);
        });
    };

    /* ------------------------------------------------------------------ *
     * Formatting
     * ------------------------------------------------------------------ */

    var nf = new Intl.NumberFormat('en-NG');
    var money = new Intl.NumberFormat('en-NG', { style: 'currency', currency: 'NGN', maximumFractionDigits: 2 });
    HOD.format = {
        number: function (n) { return nf.format(Number(n) || 0); },
        naira: function (n) { return money.format(Number(n) || 0); },
        date: function (value, style) {
            var d = value instanceof Date ? value : new Date(String(value).replace(' ', 'T'));
            if (isNaN(d)) return '';
            return d.toLocaleDateString('en-GB', style === 'long'
                ? { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }
                : { day: 'numeric', month: 'short', year: 'numeric' });
        },
        time: function (value) {
            var d = value instanceof Date ? value : new Date(String(value).replace(' ', 'T'));
            return isNaN(d) ? '' : d.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' });
        }
    };

    /* ------------------------------------------------------------------ *
     * On-screen keyboard (phones): keep sheets above it, hide the bottom bar
     * ------------------------------------------------------------------ */

    if (window.visualViewport) {
        var vv = window.visualViewport;
        var syncViewport = function () {
            var focused = document.activeElement;
            var typing = focused && /^(INPUT|TEXTAREA|SELECT)$/.test(focused.tagName) && !/^(checkbox|radio|button|submit|range|color|file)$/.test(focused.type || '');
            var open = typing && (window.innerHeight - vv.height) > 120;
            root.classList.toggle('ui-kb-open', open);
            root.style.setProperty('--ui-vv-h', vv.height + 'px');
            root.style.setProperty('--ui-kb-inset', Math.max(0, window.innerHeight - vv.height - vv.offsetTop) + 'px');
        };
        vv.addEventListener('resize', syncViewport);
        vv.addEventListener('scroll', syncViewport);
        document.addEventListener('focusin', function () { setTimeout(syncViewport, 60); });
        document.addEventListener('focusout', function () { setTimeout(syncViewport, 60); });
    }

    /* ------------------------------------------------------------------ *
     * Online / offline / saved copy
     * ------------------------------------------------------------------ */

    function syncOnline() {
        root.classList.toggle('ui-is-offline', !navigator.onLine);
    }
    window.addEventListener('offline', function () {
        syncOnline();
    });
    window.addEventListener('online', function () {
        syncOnline();
        if (root.classList.contains('ui-is-snapshot')) {
            toast("You're back online.", { tone: 'success', action: { label: 'Reload', run: function () { window.location.reload(); } } });
        } else {
            toast("You're back online.", 'success');
        }
    });

    function syncSnapshot() {
        var saved = root.getAttribute('data-ui-snapshot');
        if (!saved) return;
        root.classList.add('ui-is-snapshot');
        var text = document.querySelector('[data-ui-offline-text]');
        var when = new Date(saved);
        if (text) {
            text.textContent = "You're offline. This is the copy saved " +
                (isNaN(when) ? 'earlier' : 'at ' + HOD.format.time(when) + ' on ' + HOD.format.date(when)) +
                ' — read-only until you reconnect.';
        }
    }

    /* ------------------------------------------------------------------ *
     * Notes — ui_notetaker(). A panel that floats over the page while the
     * person keeps working; not a dialog, so focus is never trapped and
     * nothing behind it is blocked. From 640 px it is dragged by its bar (or
     * moved with the arrow keys on Move notes) and stays inside the window;
     * on a phone it docks full width above the bottom bar, or under the app
     * bar with Move to top. Minimise keeps its bar; Close hides it. It loads
     * the note when first shown and saves it 0.8 s after typing pauses, on
     * Close, before Download, and as the page goes away (a reload after
     * Mark done included). With a 'remember' key, open / minimised / where
     * it sat survive a reload on this device (localStorage, never the text).
     * ------------------------------------------------------------------ */

    var NOTE_PAUSE = 800;
    var NOTE_GAP = 8;
    var NOTE_STEP = 24;
    var notesAll = {};

    function notesWide() {
        return !!(window.matchMedia && window.matchMedia('(min-width: 640px)').matches);
    }

    function Notes(el) {
        var cfg = {};
        try { cfg = JSON.parse(el.getAttribute('data-ui-notes') || '{}'); } catch (e) { cfg = {}; }
        var q = function (sel) { return el.querySelector(sel); };
        var self = this;
        this.el = el;
        this.cfg = cfg;
        this.text = q('[data-notes-text]');
        this.status = q('[data-notes-status]');
        this.count = q('[data-notes-count]');
        this.retryBtn = q('[data-notes-retry]');
        this.moveBtn = q('[data-notes-move]');
        this.minBtn = q('[data-notes-min]');
        this.bar = q('[data-notes-grip]');
        this.download = q('[data-notes-download]');
        this.loaded = !cfg.endpoint;
        this.loading = null;
        this.kept = '';
        this.failed = null;          // 'load' | 'save' | null
        this.timer = null;
        this.chain = Promise.resolve();
        this.opener = null;
        this.isOpen = false;
        this.small = false;
        this.top = false;
        this.at = null;              // {x, y} once moved on a wide screen
        this.grab = null;

        this.text.addEventListener('input', function () { self.typed(); });
        this.retryBtn.addEventListener('click', function () { self.retry(); });
        this.minBtn.addEventListener('click', function () { self.setSmall(!self.small, true); });
        q('[data-notes-close]').addEventListener('click', function () { return self.close(); });
        this.moveBtn.addEventListener('click', function () {
            if (notesWide()) return;
            self.top = !self.top;
            self.layout();
            self.remember();
        });
        this.moveBtn.addEventListener('keydown', function (event) { self.nudge(event); });
        this.bar.addEventListener('pointerdown', function (event) { self.dragStart(event); });
        this.bar.addEventListener('pointermove', function (event) { self.dragMove(event); });
        this.bar.addEventListener('pointerup', function (event) { self.dragEnd(event); });
        this.bar.addEventListener('pointercancel', function (event) { self.dragEnd(event); });
        if (this.download) this.download.addEventListener('click', function (event) { self.downloadNow(event); });
        if (!cfg.endpoint) this.setStatus('');
    }

    Notes.prototype.key = function () {
        return this.cfg.remember ? 'hod-notes:' + this.cfg.remember : '';
    };

    Notes.prototype.remember = function () {
        var key = this.key();
        if (!key) return;
        try {
            if (!this.isOpen) { window.localStorage.removeItem(key); return; }
            window.localStorage.setItem(key, JSON.stringify({ small: this.small, top: this.top, at: this.at }));
        } catch (e) { /* storage blocked: it simply does not reopen */ }
    };

    Notes.prototype.recall = function () {
        var key = this.key();
        if (!key) return null;
        try { return JSON.parse(window.localStorage.getItem(key) || 'null'); } catch (e) { return null; }
    };

    Notes.prototype.setStatus = function (word, failed) {
        this.status.textContent = word;
        this.status.classList.toggle('is-failed', !!failed);
        this.retryBtn.hidden = !failed;
    };

    Notes.prototype.dirty = function () {
        return this.loaded && !!this.cfg.endpoint && this.text.value !== this.kept;
    };

    Notes.prototype.counter = function () {
        var max = this.cfg.max || 0;
        var n = this.text.value.length;
        var near = max > 0 && n >= max * 0.9;
        this.count.textContent = near ? HOD.format.number(n) + ' / ' + HOD.format.number(max) : '';
        this.count.classList.toggle('is-near', near);
    };

    Notes.prototype.payload = function (base, extra) {
        var data = {};
        Object.keys(base || {}).forEach(function (k) { if (k !== 'action') data[k] = base[k]; });
        Object.keys(extra || {}).forEach(function (k) { data[k] = extra[k]; });
        return data;
    };

    Notes.prototype.load = function () {
        var self = this;
        if (this.loaded) return Promise.resolve();
        if (this.loading) return this.loading;
        this.text.readOnly = true;
        this.text.setAttribute('aria-busy', 'true');
        this.setStatus('Loading');
        this.loading = HOD.api(this.cfg.endpoint, { action: (this.cfg.load || {}).action, data: this.payload(this.cfg.load), quiet: true })
            .then(function (res) {
                self.kept = String(res.body || '');
                self.text.value = self.kept;
                self.loaded = true;
                self.failed = null;
                self.setStatus(res.updated_at ? 'Saved' : '');
                self.counter();
            }, function () {
                self.failed = 'load';
                self.setStatus('Not loaded', true);
            })
            .then(function () {
                self.loading = null;
                self.text.readOnly = !self.loaded;
                self.text.removeAttribute('aria-busy');
            });
        return this.loading;
    };

    Notes.prototype.typed = function () {
        var self = this;
        this.counter();
        if (!this.cfg.endpoint) return;
        this.setStatus('Saving');
        clearTimeout(this.timer);
        this.timer = setTimeout(function () { self.save(); }, NOTE_PAUSE);
    };

    /** Save what is typed, if it changed. Saves run one after another. */
    Notes.prototype.save = function () {
        var self = this;
        clearTimeout(this.timer);
        this.chain = this.chain.then(function () {
            if (!self.dirty()) return true;
            var body = self.text.value;
            return HOD.api(self.cfg.endpoint, { action: (self.cfg.save || {}).action, data: self.payload(self.cfg.save, { body: body }), quiet: true })
                .then(function () {
                    self.kept = body;
                    self.failed = null;
                    if (self.text.value === body) self.setStatus('Saved');
                    return true;
                }, function (err) {
                    self.failed = 'save';
                    self.setStatus('Not saved', true);
                    if (err && err.code === 'TOO_LONG') toast(err.message, 'warning');
                    return false;
                });
        });
        return this.chain;
    };

    /** The page is going away (a reload, a link): send what is unsaved anyway. */
    Notes.prototype.flush = function () {
        if (!this.dirty()) return;
        clearTimeout(this.timer);
        var form = new FormData();
        form.append('action', (this.cfg.save || {}).action || '');
        var data = this.payload(this.cfg.save, { body: this.text.value, _csrf: token() });
        Object.keys(data).forEach(function (k) { form.append(k, data[k]); });
        try {
            fetch(this.cfg.endpoint, { method: 'POST', body: form, credentials: 'same-origin', keepalive: true,
                headers: { 'X-CSRF-Token': token(), 'X-Requested-With': 'XMLHttpRequest' } });
            this.kept = this.text.value;
        } catch (e) { /* the browser refused: the next visit shows the last save */ }
    };

    Notes.prototype.retry = function () {
        return this.failed === 'load' ? this.load() : this.save();
    };

    Notes.prototype.setSmall = function (small, byPerson) {
        this.small = small;
        this.el.classList.toggle('is-small', small);
        this.el.classList.toggle('is-min', small);
        var label = small ? 'Open notes' : 'Minimise';
        this.minBtn.setAttribute('aria-label', label);
        this.minBtn.setAttribute('data-tooltip', label);
        this.minBtn.setAttribute('aria-expanded', small ? 'false' : 'true');
        this.minBtn.innerHTML = icon(small ? 'chevron-up' : 'minus');
        if (small && this.dirty()) this.save();
        this.layout();
        if (byPerson) {
            this.remember();
            if (!small) this.text.focus({ preventScroll: true });
        }
    };

    /** The window's edges the panel stays inside: above a phone's bottom bar too. */
    Notes.prototype.bounds = function () {
        var bottom = window.innerHeight;
        var barEl = document.querySelector('.ui-bottombar');
        if (barEl) {
            var r = barEl.getBoundingClientRect();
            if (r.height > 0 && r.top < bottom) bottom = r.top;
        }
        return { right: document.documentElement.clientWidth, bottom: bottom };
    };

    Notes.prototype.within = function (p) {
        var b = this.bounds();
        var w = this.el.offsetWidth, h = this.el.offsetHeight;
        return {
            x: Math.round(Math.min(Math.max(NOTE_GAP, p.x), Math.max(NOTE_GAP, b.right - w - NOTE_GAP))),
            y: Math.round(Math.min(Math.max(NOTE_GAP, p.y), Math.max(NOTE_GAP, b.bottom - h - NOTE_GAP)))
        };
    };

    /** Docked on a phone, floating (where it was put) on a wider screen. */
    Notes.prototype.layout = function () {
        var wide = notesWide();
        var label = wide ? 'Move notes' : (this.top ? 'Move to bottom' : 'Move to top');
        this.el.classList.toggle('is-top', !wide && this.top);
        this.moveBtn.setAttribute('aria-label', label);
        this.moveBtn.setAttribute('data-tooltip', label);
        this.moveBtn.innerHTML = icon(wide ? 'grip-vertical' : 'arrow-up-down');
        if (wide && this.at) {
            this.el.classList.add('is-placed');
            this.at = this.within(this.at);
            this.el.style.left = this.at.x + 'px';
            this.el.style.top = this.at.y + 'px';
        } else {
            this.el.classList.remove('is-placed');
            this.el.style.left = '';
            this.el.style.top = '';
        }
    };

    Notes.prototype.here = function () {
        var r = this.el.getBoundingClientRect();
        return { x: r.left, y: r.top };
    };

    Notes.prototype.dragStart = function (event) {
        if (!notesWide() || event.button !== 0) return;
        if (event.target instanceof Element && event.target.closest('button, a') && !event.target.closest('[data-notes-move]')) return;
        var p = this.here();
        this.grab = { x: event.clientX - p.x, y: event.clientY - p.y };
        this.el.classList.add('is-dragging');
        try { this.bar.setPointerCapture(event.pointerId); } catch (e) { /* older browsers */ }
        event.preventDefault();
    };

    Notes.prototype.dragMove = function (event) {
        if (!this.grab) return;
        this.at = { x: event.clientX - this.grab.x, y: event.clientY - this.grab.y };
        this.layout();
    };

    Notes.prototype.dragEnd = function () {
        if (!this.grab) return;
        this.grab = null;
        this.el.classList.remove('is-dragging');
        this.remember();
    };

    Notes.prototype.nudge = function (event) {
        var by = { ArrowLeft: [-NOTE_STEP, 0], ArrowRight: [NOTE_STEP, 0], ArrowUp: [0, -NOTE_STEP], ArrowDown: [0, NOTE_STEP] }[event.key];
        if (!by) return;
        event.preventDefault();
        if (!notesWide()) {
            this.top = event.key === 'ArrowUp' ? true : event.key === 'ArrowDown' ? false : this.top;
        } else {
            var p = this.here();
            this.at = { x: p.x + by[0], y: p.y + by[1] };
        }
        this.layout();
        this.remember();
    };

    Notes.prototype.open = function (opts) {
        var self = this;
        opts = opts || {};
        if (opts.opener) this.opener = opts.opener;
        var already = this.isOpen;
        this.isOpen = true;
        this.el.hidden = false;
        if (opts.small !== undefined) this.setSmall(!!opts.small, false);
        else if (this.small && opts.focus) this.setSmall(false, false);
        this.layout();
        if (!already) {
            requestAnimationFrame(function () { self.el.classList.add('is-open'); });
        }
        this.load().then(function () {
            if (opts.focus && !self.small && self.loaded) self.text.focus({ preventScroll: true });
        });
        if (opts.focus && !this.small) this.text.focus({ preventScroll: true });
        this.remember();
    };

    Notes.prototype.close = function () {
        var self = this;
        var hadFocus = this.el.contains(document.activeElement);
        return this.save().then(function () {
            self.isOpen = false;
            self.el.classList.remove('is-open');
            self.remember();
            var done = function () { if (!self.isOpen) self.el.hidden = true; };
            if (reduceMotion) done(); else setTimeout(done, 220);
            var back = self.opener && document.contains(self.opener) ? self.opener
                : document.querySelector('[data-notes-open="' + self.el.id + '"]');
            if (hadFocus && back) back.focus({ preventScroll: true });
        });
    };

    Notes.prototype.downloadNow = function (event) {
        var self = this;
        if (!this.cfg.endpoint) return;
        if (this.text.value.trim() === '') {
            event.preventDefault();
            toast('Write a note first.', 'info');
            return;
        }
        if (!this.dirty()) return;
        event.preventDefault();
        var href = this.download.getAttribute('href');
        this.save().then(function (ok) {
            if (ok) window.location.href = href;
            else toast('Saving failed. Try again, then download.', 'danger');
        });
    };

    function notesFor(target) {
        var el = typeof target === 'string' ? document.getElementById(target) : target;
        if (!el || !el.hasAttribute('data-ui-notes')) return null;
        if (!notesAll[el.id]) notesAll[el.id] = new Notes(el);
        return notesAll[el.id];
    }

    document.addEventListener('click', function (event) {
        var opener = event.target instanceof Element ? event.target.closest('[data-notes-open]') : null;
        if (!opener) return;
        event.preventDefault();
        if (root.classList.contains('ui-is-snapshot')) {
            toast("This is a saved copy for reading while you're offline. Reconnect to take notes.", 'warning');
            return;
        }
        var n = notesFor(opener.getAttribute('data-notes-open'));
        if (n) n.open({ focus: true, opener: opener });
    });

    window.addEventListener('resize', debounce(function () {
        Object.keys(notesAll).forEach(function (id) { if (notesAll[id].isOpen) notesAll[id].layout(); });
    }, 100));
    window.addEventListener('pagehide', function () {
        Object.keys(notesAll).forEach(function (id) { notesAll[id].flush(); });
    });
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState !== 'hidden') return;
        Object.keys(notesAll).forEach(function (id) { if (notesAll[id].dirty()) notesAll[id].save(); });
    });
    window.addEventListener('online', function () {
        Object.keys(notesAll).forEach(function (id) {
            var n = notesAll[id];
            if (n.isOpen && n.failed) n.retry();
        });
    });

    /** Panels that were open when the page last went away come back as they were. */
    function initNotes() {
        document.querySelectorAll('[data-ui-notes]').forEach(function (el) {
            var n = notesFor(el);
            var was = n && n.recall();
            if (!was || root.classList.contains('ui-is-snapshot')) return;
            n.top = !!was.top;
            n.at = was.at && typeof was.at.x === 'number' && typeof was.at.y === 'number' ? was.at : null;
            n.open({ focus: false, small: !!was.small });
        });
    }

    HOD.notes = {
        open: function (id) { var n = notesFor(id); if (n) n.open({ focus: true }); },
        close: function (id) { var n = notesFor(id); return n ? n.close() : Promise.resolve(); },
        save: function (id) { var n = notesFor(id); return n ? n.save() : Promise.resolve(true); }
    };

    /* ------------------------------------------------------------------ *
     * Boot
     * ------------------------------------------------------------------ */

    ready(function () {
        syncOnline();
        syncSnapshot();
        initTabs(document);
        document.querySelectorAll('select[data-ui-combobox]').forEach(enhanceSelect);
        initNotes();
    });
})();
