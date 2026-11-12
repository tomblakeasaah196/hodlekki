// /assets/ui/js/pages/mail-compose.js
// The composer (includes/mail/mounts/composer.php, docs/MAIL.md §7d), ported from Courrier.
//
// Opens on the browser event mail:compose {mode: new|reply|reply_all|forward|draft, accountId, messageId?, outboxId?,
// to?}. Everything is saved as the person types (api/mail_compose_api.php, one request at a time, the latest text
// wins); the server also keeps a copy in the mailbox's Drafts. Send waits 10 seconds for Undo (the sending bar), then
// asks the server to send; if the page is left before that, cron/mail_minute.php sends it within a minute.
//
// Raises mail:compose-opened / mail:compose-closed {accountId, mode, messageId, outboxId}, mail:sent {accountId,
// threadId, outboxId}, mail:composing {threadId, state} (answers; PR 4's "someone is replying"). Provides
// window.HODMailComposer (current(), replaceBody(html), insertHtml(html), open(detail)) for PR 4's writing help.
//
// What the editor produces is cleaned here on paste and again on the server (HOD\Mail\Compose\HtmlCleaner).
(function () {
    'use strict';
    if (window.HODMailComposer) return;
    var dlg = document.getElementById('mail-composer');
    if (!dlg) return;
    var CFG = HOD.data('mail-compose') || {};
    var API = CFG.api || '/api/mail_compose_api.php';
    var EMAIL = /^[^\s@<>(),;:"]+@[^\s@<>(),;:"]+\.[^\s@<>(),;:"]{2,}$/;
    var $ = function (sel, root) { return (root || dlg).querySelector(sel); };
    var $$ = function (sel, root) { return Array.prototype.slice.call((root || dlg).querySelectorAll(sel)); };

    var form = $('[data-mc-form]');
    var titleEl = $('#mc-title');
    var savedEl = $('[data-mc-saved]');
    var fromSel = $('[data-mc-from]');
    var subj = $('[data-mc-subject]');
    var editor = $('[data-mc-editor]');
    var errorEl = $('[data-mc-error]');
    var quoteBox = $('[data-mc-quote]');
    var quoteBody = $('[data-mc-quote-body]');
    var quoteOn = $('[data-mc-quote-on]');
    var sigSel = $('[data-mc-sig-style]');
    var sigPreview = $('[data-mc-sig-preview]');
    var filesEl = $('[data-mc-files]');
    var fileInput = $('[data-mc-file]');
    var linkbar = $('[data-mc-linkbar]');
    var linkInput = $('#mc-link-url');
    var schedule = $('[data-mc-schedule]');
    var scheduleAt = $('[data-mc-schedule-at]');
    var confirmBox = $('[data-mc-confirm]');
    var bar = document.querySelector('[data-mc-bar]');
    var barText = bar && bar.querySelector('[data-mc-bar-text]');
    var barAction = bar && bar.querySelector('[data-mc-bar-action]');

    var state = null;        // the draft as the server last described it
    var mode = 'new';
    var opener = null;
    var queue = Promise.resolve();
    var saveTimer = null;
    var dirty = false;
    var uploads = 0;
    var range = null;
    var barTimer = null;
    var barTick = null;
    var barRun = null;
    var suppressCancel = false;

    function emit(name, detail) {
        document.dispatchEvent(new CustomEvent(name, { detail: detail }));
    }
    function api(body, query) {
        if (!body) return HOD.api(API + '?' + query, { method: 'GET', quiet: true });
        return HOD.api(API, { data: body, json: true, quiet: true });
    }
    /** One request at a time for this composer, in order */
    function serial(fn) {
        var p = queue.then(fn, fn);
        queue = p.catch(function () {});
        return p;
    }
    var esc = HOD.esc;
    /**
     * Server-built HTML with inline styles (the signature, the quoted original) shown on this strict-CSP page: the
     * page refuses style attributes from markup, so each one moves to the element's CSSOM style, which the policy
     * allows. The HTML itself comes from escaped values (HOD\Mail\Compose\Signatures, MessageBuilder::quote).
     */
    function paint(target, html) {
        target.textContent = '';
        if (!html) return;
        var doc = new DOMParser().parseFromString('<div>' + html + '</div>', 'text/html');
        var styled = [];
        doc.body.querySelectorAll('[style]').forEach(function (n) {
            styled.push([n, n.getAttribute('style')]);
        });
        styled.forEach(function (pair) { pair[0].removeAttribute('style'); });
        var root = document.importNode(doc.body.firstChild, true);
        var copies = root.querySelectorAll('*');
        var originals = doc.body.firstChild.querySelectorAll('*');
        var index = new Map();
        Array.prototype.forEach.call(originals, function (n, i) { index.set(n, copies[i]); });
        styled.forEach(function (pair) {
            var copy = index.get(pair[0]);
            if (copy) copy.style.cssText = pair[1];
        });
        while (root.firstChild) target.appendChild(root.firstChild);
    }
    function initials(a) {
        var base = (a.name || a.email || '').replace(/@.*$/, '').trim();
        var parts = base.split(/[\s._-]+/).filter(Boolean);
        return ((parts[0] || '@').charAt(0) + (parts[1] ? parts[1].charAt(0) : '')).toUpperCase();
    }
    /** Pasted or given HTML keeps its paragraphs, lists and links, not the page's look (the server cleans again) */
    function cleanHtml(html) {
        var doc = new DOMParser().parseFromString('<div>' + String(html || '') + '</div>', 'text/html');
        var root = doc.body.firstChild;
        root.querySelectorAll('script,style,meta,link,img,svg,iframe,object,embed,form,input,button,select,textarea,video,audio,template,noscript').forEach(function (n) { n.remove(); });
        root.querySelectorAll('*').forEach(function (n) {
            Array.prototype.slice.call(n.attributes).forEach(function (a) {
                var keep = n.tagName === 'A' && a.name === 'href' && /^(https?:|mailto:|tel:)/i.test(a.value.trim());
                if (!keep) n.removeAttribute(a.name);
            });
        });
        return root.innerHTML;
    }

    // ---- the sending bar ------------------------------------------------------------------------------------
    function showBar(text, opts) {
        if (!bar) return;
        opts = opts || {};
        clearTimeout(barTimer);
        clearInterval(barTick);
        bar.classList.toggle('mc-bar--error', !!opts.error);
        barText.textContent = text;
        barAction.hidden = !opts.action;
        barAction.textContent = opts.action || '';
        barRun = opts.onAction || null;
        bar.hidden = false;
        if (opts.timeout) barTimer = setTimeout(hideBar, opts.timeout);
    }
    function hideBar() {
        if (!bar) return;
        clearTimeout(barTimer);
        clearInterval(barTick);
        bar.hidden = true;
        barRun = null;
    }
    if (bar) {
        bar.querySelector('[data-mc-bar-close]').addEventListener('click', hideBar);
        barAction.addEventListener('click', function () { if (barRun) barRun(); });
    }

    // ---- errors ---------------------------------------------------------------------------------------------
    function clearErrors() {
        errorEl.hidden = true;
        errorEl.textContent = '';
        $$('.mc-field-error').forEach(function (p) { p.hidden = true; p.textContent = ''; });
        $$('[aria-invalid="true"]').forEach(function (i) { i.removeAttribute('aria-invalid'); });
    }
    function showError(message, field) {
        var slot = field ? $('[data-error-for="' + field + '"]') : null;
        if (slot) {
            if (field === 'send_at') schedule.hidden = false;
            if (field === 'cc' || field === 'bcc') showCc(true);
            slot.textContent = message;
            slot.hidden = false;
            var input = field === 'send_at' ? scheduleAt : (chips[field] ? chips[field].input : null);
            if (input) {
                input.setAttribute('aria-invalid', 'true');
                input.focus();
            }
            return;
        }
        errorEl.textContent = message;
        errorEl.hidden = false;
    }
    function notAddress(text) {
        return '“' + text + '” is not an email address. Check it, then try again.';
    }

    // ---- recipients as chips, with suggestions --------------------------------------------------------------
    function Chips(field) {
        var row = $('[data-chips="' + field + '"]');
        var self = this;
        this.field = field;
        this.list = [];
        this.items = [];
        this.active = -1;
        this.timer = null;
        this.ul = row.querySelector('[data-chip-list]');
        this.input = row.querySelector('.mc-chip-input');
        this.suggest = row.querySelector('.mc-suggest');
        this.error = $('[data-error-for="' + field + '"]');
        row.addEventListener('click', function (e) { if (e.target === row) self.input.focus(); });
        this.input.addEventListener('input', function () { self.typed(); });
        this.input.addEventListener('keydown', function (e) { self.key(e); });
        this.input.addEventListener('blur', function () {
            setTimeout(function () {
                if (self.input.value.trim()) self.add(self.input.value);
                self.close();
            }, 150);
        });
        this.input.addEventListener('paste', function (e) {
            var text = (e.clipboardData || window.clipboardData).getData('text');
            if (/[,;\n]/.test(text)) { e.preventDefault(); self.add(self.input.value + text); }
        });
        this.ul.addEventListener('click', function (e) {
            var b = e.target.closest('button[data-remove]');
            if (b) { self.remove(Number(b.getAttribute('data-remove'))); self.input.focus(); }
        });
        this.suggest.addEventListener('mousedown', function (e) { e.preventDefault(); });
        this.suggest.addEventListener('click', function (e) {
            var li = e.target.closest('li[data-i]');
            if (li) self.pick(self.items[Number(li.getAttribute('data-i'))]);
        });
    }
    Chips.prototype.set = function (list) {
        this.list = (list || []).slice();
        this.input.value = '';
        this.render();
    };
    Chips.prototype.render = function () {
        this.ul.innerHTML = this.list.map(function (a, i) {
            var label = a.name ? a.name : a.email;
            return '<li class="mc-chip" title="' + esc(a.name ? a.name + ' <' + a.email + '>' : a.email) + '">'
                + '<span class="mc-chip-text">' + esc(label) + '</span>'
                + '<button type="button" data-remove="' + i + '" aria-label="Remove ' + esc(label) + '">' + HOD.icon('x', 'ui-icon--xs') + '</button></li>';
        }).join('');
    };
    Chips.prototype.changed = function () {
        this.render();
        if (this.error && !this.error.hidden && this.list.length) { this.error.hidden = true; this.input.removeAttribute('aria-invalid'); }
        touch();
    };
    Chips.prototype.parse = function (text) {
        text = String(text || '').trim().replace(/^[,;]+|[,;]+$/g, '').trim();
        if (!text) return null;
        var m = text.match(/^\s*"?([^"<]*?)"?\s*<([^<>]+)>\s*$/);
        var name = m ? m[1].trim() : null;
        var email = (m ? m[2] : text).trim().toLowerCase();
        return EMAIL.test(email) ? { name: name || null, email: email } : null;
    };
    /** Add what was typed or pasted: good addresses become chips, the rest stays in the field with a message */
    Chips.prototype.add = function (text) {
        var parts = String(text || '').split(/[,;\n]+(?=(?:[^"]*"[^"]*")*[^"]*$)/);
        var left = [];
        var added = false;
        for (var i = 0; i < parts.length; i++) {
            if (!parts[i].trim()) continue;
            var a = this.parse(parts[i]);
            if (!a) { left.push(parts[i].trim()); continue; }
            if (!this.list.some(function (x) { return x.email === a.email; })) this.list.push(a);
            added = true;
        }
        this.input.value = left.join(', ');
        this.close();
        if (left.length && this.error) {
            this.error.textContent = notAddress(left[0]);
            this.error.hidden = false;
            this.input.setAttribute('aria-invalid', 'true');
        } else if (this.error) {
            this.error.hidden = true;
            this.input.removeAttribute('aria-invalid');
        }
        if (added) this.changed();
        return added;
    };
    Chips.prototype.pick = function (item) {
        if (!item) return;
        if (!this.list.some(function (x) { return x.email === item.email; })) this.list.push({ name: item.name || null, email: item.email });
        this.input.value = '';
        this.close();
        this.changed();
        this.input.focus();
    };
    Chips.prototype.remove = function (i) {
        this.list.splice(i, 1);
        this.changed();
    };
    Chips.prototype.typed = function () {
        var self = this;
        if (this.error && !this.error.hidden) { this.error.hidden = true; this.input.removeAttribute('aria-invalid'); }
        if (/[,;]\s*$/.test(this.input.value)) { this.add(this.input.value); return; }
        clearTimeout(this.timer);
        var q = this.input.value.trim();
        if (q.length < 2) { this.close(); return; }
        this.timer = setTimeout(function () {
            api(null, 'action=suggest&q=' + encodeURIComponent(q)).then(function (r) {
                if (self.input.value.trim() !== q) return;
                var taken = self.list.map(function (x) { return x.email; });
                self.items = (r.data || []).filter(function (s) { return taken.indexOf(s.email) === -1; });
                self.active = self.items.length ? 0 : -1;
                self.open();
            }).catch(function () { self.close(); });
        }, 200);
    };
    Chips.prototype.open = function () {
        var self = this;
        if (!this.items.length) { this.close(); return; }
        var kinds = { mailbox: 'Mailbox', person: 'Church', contact: '' };
        this.suggest.innerHTML = this.items.map(function (s, i) {
            return '<li role="option" id="mc-' + self.field + '-opt-' + i + '" data-i="' + i + '" aria-selected="' + (i === self.active) + '">'
                + '<span class="mc-suggest-avatar mc-suggest-avatar--' + esc(s.kind) + '" aria-hidden="true">' + esc(initials(s)) + '</span>'
                + '<span class="mc-suggest-who"><span class="mc-suggest-name">' + esc(s.name || s.email) + '</span>'
                + '<span class="mc-suggest-mail">' + esc(s.email) + (kinds[s.kind] ? ' · ' + kinds[s.kind] : '') + '</span></span></li>';
        }).join('');
        this.suggest.hidden = false;
        this.input.setAttribute('aria-expanded', 'true');
        this.highlight();
    };
    Chips.prototype.close = function () {
        this.items = [];
        this.active = -1;
        this.suggest.hidden = true;
        this.suggest.innerHTML = '';
        this.input.setAttribute('aria-expanded', 'false');
        this.input.removeAttribute('aria-activedescendant');
    };
    Chips.prototype.highlight = function () {
        var self = this;
        this.suggest.querySelectorAll('li').forEach(function (li, i) { li.setAttribute('aria-selected', String(i === self.active)); });
        if (this.active >= 0) {
            this.input.setAttribute('aria-activedescendant', 'mc-' + this.field + '-opt-' + this.active);
            var li = this.suggest.querySelector('[data-i="' + this.active + '"]');
            if (li && li.scrollIntoView) li.scrollIntoView({ block: 'nearest' });
        }
    };
    Chips.prototype.key = function (e) {
        var open = !this.suggest.hidden && this.items.length;
        if (e.key === 'ArrowDown' && open) { e.preventDefault(); this.active = (this.active + 1) % this.items.length; this.highlight(); }
        else if (e.key === 'ArrowUp' && open) { e.preventDefault(); this.active = (this.active - 1 + this.items.length) % this.items.length; this.highlight(); }
        else if (e.key === 'Enter' || (e.key === 'Tab' && this.input.value.trim())) {
            if (open && this.active >= 0) { e.preventDefault(); this.pick(this.items[this.active]); }
            else if (this.input.value.trim()) { e.preventDefault(); this.add(this.input.value); }
            else if (e.key === 'Enter') { e.preventDefault(); }
        }
        else if (e.key === 'Escape' && open) { e.preventDefault(); e.stopPropagation(); this.close(); suppressCancel = true; }
        else if (e.key === 'Backspace' && !this.input.value && this.list.length) { this.remove(this.list.length - 1); }
    };
    /** Typed text not yet turned into a chip becomes one before anything is saved or sent */
    Chips.prototype.commit = function () {
        if (this.input.value.trim()) this.add(this.input.value);
        return !this.input.value.trim();
    };
    var chips = { to: new Chips('to'), cc: new Chips('cc'), bcc: new Chips('bcc') };

    function showCc(on) {
        $('[data-row="cc"]').hidden = !on;
        $('[data-row="bcc"]').hidden = !on;
        var b = $('[data-mc="show-cc"]');
        b.setAttribute('aria-expanded', String(on));
        b.hidden = on;
    }

    // ---- saving ---------------------------------------------------------------------------------------------
    function fields() {
        return {
            to: chips.to.list, cc: chips.cc.list, bcc: chips.bcc.list,
            subject: subj.value, html: editor.innerHTML, signature: sigSel.value,
            quote: !quoteBox.hidden && quoteOn.checked
        };
    }
    function touch() {
        if (!state) return;
        dirty = true;
        savedEl.textContent = '';
        clearTimeout(saveTimer);
        saveTimer = setTimeout(save, 1200);
    }
    function save(extra) {
        clearTimeout(saveTimer);
        if (!state || (!dirty && !extra)) return Promise.resolve();
        var id = state.id;
        dirty = false;
        var body = { action: 'save', outboxId: id, fields: Object.assign(fields(), extra || {}) };
        savedEl.textContent = 'Saving…';
        return serial(function () {
            return api(body).then(function (r) {
                if (!state || state.id !== id) return r;
                savedEl.textContent = dirty ? '' : 'Saved ' + r.savedAt;
                Object.keys(r.invalid || {}).forEach(function (f) { showError(notAddress(r.invalid[f][0]), f); });
                if (r.draft) applyAccount(r.draft);
                return r;
            }, function (err) {
                if (state && state.id === id) {
                    savedEl.textContent = '';
                    showError(err.message, err.field === 'account_id' ? 'account_id' : null);
                    dirty = true;
                }
                throw err;
            });
        });
    }

    // ---- filling the dialog ---------------------------------------------------------------------------------
    function fill(d) {
        state = d;
        dirty = false;
        clearErrors();
        titleEl.textContent = d.title;
        fromSel.innerHTML = (d.accounts || []).map(function (a) {
            return '<option value="' + a.id + '"' + (a.id === d.accountId ? ' selected' : '') + '>' + esc(a.name ? a.name + ' <' + a.email + '>' : a.email) + '</option>';
        }).join('');
        fromSel.disabled = (d.accounts || []).length < 2;
        chips.to.set(d.to);
        chips.cc.set(d.cc);
        chips.bcc.set(d.bcc);
        showCc(!!((d.cc || []).length || (d.bcc || []).length));
        subj.value = d.subject || '';
        editor.innerHTML = d.html || '';            // cleaned on the server when saved
        sigSel.value = d.signature || 'full';
        quoteBox.hidden = !d.quoteHtml;
        paint(quoteBody, d.quoteHtml || '');        // built on the server from escaped text
        quoteBody.hidden = true;
        quoteOn.checked = !!d.quote;
        quoteBox.classList.toggle('is-off', !d.quote);
        var toggle = $('[data-mc="toggle-quote"]');
        toggle.setAttribute('aria-expanded', 'false');
        toggle.textContent = 'Show original';
        renderSignature();
        renderFiles(d.attachments || []);
        savedEl.textContent = d.savedAt ? 'Saved ' + d.savedAt : '';
        if (d.lastError) showError(d.lastError);
        schedule.hidden = true;
        confirmBox.hidden = true;
        linkbar.hidden = true;
    }
    function applyAccount(d) {
        state.accountId = d.accountId;
        state.signatures = d.signatures;
        state.accounts = d.accounts;
        renderSignature();
    }
    function renderSignature() {
        var s = sigSel.value;
        // The church's design, built on the server from escaped values
        paint(sigPreview, s === 'none' ? '' : ((state && state.signatures && state.signatures[s]) || ''));
    }
    function focusFirst() {
        if (!chips.to.list.length) chips.to.input.focus();
        else if (!subj.value.trim()) subj.focus();
        else {
            editor.focus();
            var sel = window.getSelection();
            var r = document.createRange();
            r.selectNodeContents(editor);
            r.collapse(true);
            sel.removeAllRanges();
            sel.addRange(r);
        }
    }
    function disable(on) {
        form.querySelectorAll('input, select, button, [contenteditable]').forEach(function (el) {
            if (el.hasAttribute('contenteditable')) el.setAttribute('contenteditable', on ? 'false' : 'true');
            else if (!el.matches('[data-mc="close"]')) el.disabled = on;
        });
        if (!on) fromSel.disabled = !state || (state.accounts || []).length < 2;
    }
    function info(detail) {
        return { accountId: state ? state.accountId : (detail && detail.accountId), mode: mode, messageId: state ? state.messageId : null, outboxId: state ? state.id : null };
    }
    function replying(on) {
        if (state && state.threadId && (state.kind === 'reply' || state.kind === 'reply_all' || state.kind === 'forward')) {
            emit('mail:composing', { threadId: state.threadId, state: on ? 'replying' : 'stopped' });
        }
    }

    // ---- opening and closing --------------------------------------------------------------------------------
    function open(detail) {
        detail = detail || {};
        var start = function () {
            mode = detail.mode || 'new';
            if (!dlg.open) opener = document.activeElement;
            state = null;
            fill({ title: { reply: 'Reply', reply_all: 'Reply all', forward: 'Forward', draft: 'Draft' }[mode] || 'New message',
                to: [], cc: [], bcc: [], accounts: [], attachments: [], signatures: {} });
            state = null;
            savedEl.textContent = 'Opening…';
            disable(true);
            if (!dlg.open) dlg.showModal();
            hideBar();
            var body = { action: 'open', mode: mode, accountId: detail.accountId || CFG.accountId || null, messageId: detail.messageId || null, outboxId: detail.outboxId || null, to: detail.to || null };
            return serial(function () { return api(body); }).then(function (r) {
                disable(false);
                fill(r.draft);
                focusFirst();
                replying(true);
                emit('mail:compose-opened', info());
            }, function (err) {
                disable(false);
                state = null;
                dlg.close();
                showBar(err.message, { error: true, timeout: 9000 });
                restoreFocus();
            });
        };
        if (dlg.open && state) return leave('close').then(start, start);
        return start();
    }
    function restoreFocus() {
        if (opener && opener.focus && document.contains(opener)) {
            try { opener.focus(); } catch (e) { /* gone */ }
        }
        opener = null;
    }
    /** Close the composer: 'close' keeps a draft worth keeping, 'none' just closes */
    function leave(action) {
        var d = state;
        if (!d) { if (dlg.open) dlg.close(); return Promise.resolve(); }
        Object.keys(chips).forEach(function (k) { chips[k].commit(); });
        clearTimeout(saveTimer);
        var p = action === 'close'
            ? serial(function () { return api({ action: 'close', outboxId: d.id, fields: fields() }); })
            : Promise.resolve({});
        replying(false);
        var closed = info();
        state = null;
        dirty = false;
        if (dlg.open) dlg.close();
        emit('mail:compose-closed', closed);
        restoreFocus();
        return p.then(function (r) {
            if (r && r.kept) showBar(r.message || 'Draft saved.', { timeout: 4000 });
            return r;
        }, function (err) {
            showBar('Draft not saved: ' + err.message, { error: true, action: 'Reopen', onAction: function () { open({ mode: 'draft', outboxId: d.id }); } });
        });
    }

    // ---- sending --------------------------------------------------------------------------------------------
    function checkBeforeSend() {
        var ok = true;
        Object.keys(chips).forEach(function (k) { if (!chips[k].commit()) ok = false; });
        if (!ok) return false;
        if (!chips.to.list.length && !chips.cc.list.length && !chips.bcc.list.length) {
            showError('Add at least one recipient.', 'to');
            return false;
        }
        if (uploads > 0) {
            showError('A file is still being added. Wait a moment, then send.');
            return false;
        }
        return true;
    }
    function closedAfter(r) {
        replying(false);
        var closed = info();
        state = null;
        dirty = false;
        dlg.close();
        emit('mail:compose-closed', closed);
        emit('mail:sent', { accountId: r.accountId, threadId: r.threadId, outboxId: r.outboxId });
        restoreFocus();
    }
    function send() {
        if (!state) return;
        clearErrors();
        if (!checkBeforeSend()) return;
        var d = state;
        clearTimeout(saveTimer);
        var sendBtn = $('[data-mc="send"]');
        sendBtn.disabled = true;
        serial(function () { return api({ action: 'send', outboxId: d.id, fields: fields() }); }).then(function (r) {
            sendBtn.disabled = false;
            closedAfter(r);
            countdown(r);
        }, function (err) {
            sendBtn.disabled = false;
            showError(err.message, err.field);
        });
    }
    function countdown(r) {
        var end = Date.now() + (r.seconds || CFG.undoSeconds || 10) * 1000;
        var tick = function () {
            var left = Math.max(0, Math.ceil((end - Date.now()) / 1000));
            barText.textContent = left > 0 ? 'Sending in ' + left + ' s' : 'Sending…';
            if (left === 0) {
                clearInterval(barTick);
                barAction.hidden = true;
                deliver(r);
            }
        };
        showBar('', { action: 'Undo', onAction: function () { undo(r.outboxId); } });
        tick();
        barTick = setInterval(tick, 250);
    }
    function deliver(r) {
        api({ action: 'deliver', outboxId: r.outboxId }).then(function (res) {
            emit('mail:sent', { accountId: res.accountId, threadId: res.threadId, outboxId: res.outboxId });
            if (res.state === 'failed') {
                showBar(res.message, { error: true, action: 'Open', onAction: function () { open({ mode: 'draft', outboxId: r.outboxId }); } });
            } else {
                showBar(res.message, { timeout: res.state === 'retry' ? 9000 : 5000 });
            }
        }, function () {
            showBar('It leaves within a minute.', { timeout: 6000 });
        });
    }
    function undo(outboxId) {
        clearInterval(barTick);
        barAction.hidden = true;
        barText.textContent = 'Undoing…';
        api({ action: 'undo', outboxId: outboxId }).then(function (r) {
            if (!r.undone) { showBar(r.message, { timeout: 6000 }); return; }
            hideBar();
            emit('mail:sent', { accountId: r.draft.accountId, threadId: r.draft.threadId, outboxId: outboxId });
            mode = 'draft';
            opener = document.activeElement;
            fill(r.draft);
            disable(false);
            if (!dlg.open) dlg.showModal();
            focusFirst();
            replying(true);
            emit('mail:compose-opened', info());
        }, function (err) {
            showBar(err.message, { error: true, timeout: 9000 });
        });
    }
    function scheduleSend(quick) {
        if (!state) return;
        clearErrors();
        if (!checkBeforeSend()) return;
        var body = { action: 'schedule', outboxId: state.id, fields: fields() };
        if (quick) body.quick = quick;
        else {
            if (!scheduleAt.value) { showError('Pick a day and a time.', 'send_at'); return; }
            body.at = scheduleAt.value;
        }
        clearTimeout(saveTimer);
        serial(function () { return api(body); }).then(function (r) {
            closedAfter(r);
            showBar(r.message, { action: 'Undo', onAction: function () { undo(r.outboxId); }, timeout: 12000 });
        }, function (err) {
            showError(err.message, err.field);
        });
    }
    function discard() {
        var d = state;
        if (!d) return;
        clearTimeout(saveTimer);
        replying(false);
        var closed = info();
        state = null;
        dirty = false;
        dlg.close();
        emit('mail:compose-closed', closed);
        restoreFocus();
        serial(function () { return api({ action: 'discard', outboxId: d.id }); }).then(function (r) {
            showBar(r.message, { timeout: 4000 });
        }, function (err) {
            showBar(err.message, { error: true, timeout: 9000 });
        });
    }

    // ---- files ----------------------------------------------------------------------------------------------
    function renderFiles(list) {
        $$('[data-key]', filesEl).forEach(function (li) { li.remove(); });
        list.forEach(addFileChip);
    }
    function addFileChip(a) {
        var li = document.createElement('li');
        li.className = 'mc-file';
        li.setAttribute('data-key', a.key);
        li.setAttribute('data-size', a.size);
        li.innerHTML = HOD.icon('file-text', 'ui-icon--sm') + '<span class="mc-file-name" title="' + esc(a.name) + '">' + esc(a.name) + '</span>'
            + '<span class="mc-file-size">' + esc(a.sizeLabel) + '</span>'
            + (a.source === 'original' ? '<span class="mc-file-tag">original</span>' : '')
            + '<button type="button" data-remove-file aria-label="Remove ' + esc(a.name) + '">' + HOD.icon('x', 'ui-icon--xs') + '</button>';
        filesEl.appendChild(li);
        return li;
    }
    function filesBytes() {
        var n = 0;
        $$('[data-size]', filesEl).forEach(function (li) { n += Number(li.getAttribute('data-size')) || 0; });
        return n;
    }
    function upload(files) {
        if (!state) return;
        var slot = $('[data-error-for="attachments"]');
        slot.hidden = true;
        Array.prototype.forEach.call(files, function (file) {
            if (filesBytes() + file.size > (CFG.maxBytes || 0)) {
                slot.textContent = '“' + file.name + '” would pass ' + (CFG.maxLabel || 'the limit') + '. Share a link to large files.';
                slot.hidden = false;
                return;
            }
            var li = document.createElement('li');
            li.className = 'mc-file is-uploading';
            li.setAttribute('data-size', file.size);
            li.innerHTML = HOD.icon('file-text', 'ui-icon--sm') + '<span class="mc-file-name">' + esc(file.name) + '</span>'
                + '<progress class="mc-progress" max="100" value="0" aria-hidden="true"></progress><span class="mc-file-size">Adding…</span>';
            filesEl.appendChild(li);
            uploads++;
            var id = state.id;
            serial(function () {
                return new Promise(function (resolve) {
                    var fd = new FormData();
                    fd.append('action', 'upload');
                    fd.append('outboxId', id);
                    fd.append('file', file, file.name);
                    var xhr = new XMLHttpRequest();
                    xhr.open('POST', API);
                    xhr.setRequestHeader('X-CSRF-Token', HOD.csrf());
                    xhr.setRequestHeader('Accept', 'application/json');
                    xhr.upload.onprogress = function (e) {
                        if (e.lengthComputable) li.querySelector('progress').value = Math.round(e.loaded / e.total * 100);
                    };
                    xhr.onload = function () {
                        var json = {};
                        try { json = JSON.parse(xhr.responseText); } catch (e) { json = {}; }
                        uploads--;
                        li.remove();
                        if (xhr.status === 200 && json.status === 'success') {
                            if (state && state.id === id) addFileChip(json.attachment);
                        } else if (state && state.id === id) {
                            slot.textContent = '“' + file.name + '”: ' + (json.message || (xhr.status === 413 ? 'too large to attach.' : 'not added. Try again.'));
                            slot.hidden = false;
                        }
                        resolve();
                    };
                    xhr.onerror = function () {
                        uploads--;
                        li.remove();
                        slot.textContent = '“' + file.name + '” did not arrive. Check your connection, then try again.';
                        slot.hidden = false;
                        resolve();
                    };
                    xhr.send(fd);
                });
            });
        });
    }
    filesEl.addEventListener('click', function (e) {
        var b = e.target.closest('[data-remove-file]');
        if (!b || !state) return;
        var li = b.closest('[data-key]');
        var key = li.getAttribute('data-key');
        var id = state.id;
        li.remove();
        serial(function () { return api({ action: 'remove_attachment', outboxId: id, key: key }); }).catch(function (err) { showError(err.message); });
    });
    fileInput.addEventListener('change', function () {
        upload(fileInput.files);
        fileInput.value = '';
    });
    var dropZone = $('[data-mc-drop]');
    dropZone.addEventListener('dragover', function (e) {
        if (e.dataTransfer && Array.prototype.indexOf.call(e.dataTransfer.types || [], 'Files') !== -1) {
            e.preventDefault();
            dropZone.classList.add('is-drop');
        }
    });
    dropZone.addEventListener('dragleave', function (e) { if (!dropZone.contains(e.relatedTarget)) dropZone.classList.remove('is-drop'); });
    dropZone.addEventListener('drop', function (e) {
        dropZone.classList.remove('is-drop');
        if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length) {
            e.preventDefault();
            upload(e.dataTransfer.files);
        }
    });

    // ---- the editor -----------------------------------------------------------------------------------------
    try { document.execCommand('defaultParagraphSeparator', false, 'p'); } catch (e) { /* older browsers: <div> */ }
    function saveRange() {
        var sel = window.getSelection();
        if (sel && sel.rangeCount && editor.contains(sel.anchorNode)) range = sel.getRangeAt(0).cloneRange();
    }
    function restoreRange() {
        editor.focus();
        if (!range) return;
        var sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(range);
    }
    function cmd(name, value) {
        restoreRange();
        if (name === 'formatBlock') {
            var inQuote = String(document.queryCommandValue('formatBlock')).toLowerCase() === 'blockquote';
            document.execCommand('formatBlock', false, inQuote ? 'p' : value);
        } else {
            document.execCommand(name, false, value === undefined ? null : value);
        }
        saveRange();
        pressed();
        touch();
    }
    function pressed() {
        $$('[data-cmd]').forEach(function (b) {
            var c = b.getAttribute('data-cmd');
            if (['bold', 'italic', 'underline', 'insertUnorderedList', 'insertOrderedList'].indexOf(c) === -1) return;
            var on = false;
            try { on = document.queryCommandState(c); } catch (e) { on = false; }
            b.setAttribute('aria-pressed', String(!!on));
        });
    }
    editor.addEventListener('input', touch);
    editor.addEventListener('keyup', pressed);
    editor.addEventListener('mouseup', pressed);
    editor.addEventListener('blur', saveRange);
    editor.addEventListener('paste', function (e) {
        var data = e.clipboardData;
        if (!data) return;
        var html = data.getData('text/html');
        if (!html) return;   // plain text: the browser inserts it as text
        e.preventDefault();
        document.execCommand('insertHTML', false, cleanHtml(html));
    });
    function openLink() {
        saveRange();
        linkInput.value = '';
        linkbar.hidden = false;
        linkInput.focus();
    }
    function applyLink() {
        var url = linkInput.value.trim();
        linkbar.hidden = true;
        if (!url) { restoreRange(); return; }
        if (!/^(https?:|mailto:|tel:)/i.test(url)) url = (EMAIL.test(url) ? 'mailto:' : 'https://') + url;
        if (!range || range.collapsed) {
            cmd('insertHTML', '<a href="' + esc(url) + '">' + esc(url.replace(/^mailto:/i, '')) + '</a>&nbsp;');
        } else {
            cmd('createLink', url);
        }
    }
    linkInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); applyLink(); }
        if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); suppressCancel = true; linkbar.hidden = true; restoreRange(); }
    });
    $('[data-mc-toolbar]').addEventListener('mousedown', function (e) {
        if (e.target.closest('[data-cmd], [data-mc="link"]')) e.preventDefault();
    });

    // ---- buttons --------------------------------------------------------------------------------------------
    form.addEventListener('click', function (e) {
        var c = e.target.closest('[data-cmd]');
        if (c) { cmd(c.getAttribute('data-cmd'), c.getAttribute('data-value') || undefined); return; }
        var b = e.target.closest('[data-mc]');
        if (!b) {
            if (!e.target.closest('[data-mc-schedule]')) schedule.hidden = true;
            return;
        }
        var what = b.getAttribute('data-mc');
        if (what !== 'schedule-menu' && !b.closest('[data-mc-schedule]')) closeSchedule();
        switch (what) {
            case 'close': leave('close'); break;
            case 'send': send(); break;
            case 'show-cc': showCc(true); chips.cc.input.focus(); break;
            case 'link': openLink(); break;
            case 'link-apply': applyLink(); break;
            case 'link-cancel': linkbar.hidden = true; restoreRange(); break;
            case 'toggle-quote':
                quoteBody.hidden = !quoteBody.hidden;
                b.setAttribute('aria-expanded', String(!quoteBody.hidden));
                b.textContent = quoteBody.hidden ? 'Show original' : 'Hide original';
                break;
            case 'schedule-menu': toggleSchedule(); break;
            case 'schedule': scheduleSend(null); break;
            case 'discard': confirmBox.hidden = false; $('[data-mc="discard-cancel"]').focus(); break;
            case 'discard-cancel': confirmBox.hidden = true; $('[data-mc="discard"]').focus(); break;
            case 'discard-yes': discard(); break;
        }
    });
    schedule.addEventListener('click', function (e) {
        var q = e.target.closest('[data-quick]');
        if (q) scheduleSend(q.getAttribute('data-quick'));
    });
    function toggleSchedule() {
        var btn = $('[data-mc="schedule-menu"]');
        var show = schedule.hidden;
        schedule.hidden = !show;
        btn.setAttribute('aria-expanded', String(show));
        if (show) {
            var d = new Date(Date.now() + 86400000);
            d.setHours(8, 0, 0, 0);
            var pad = function (n) { return (n < 10 ? '0' : '') + n; };
            var local = function (x) { return x.getFullYear() + '-' + pad(x.getMonth() + 1) + '-' + pad(x.getDate()) + 'T' + pad(x.getHours()) + ':' + pad(x.getMinutes()); };
            if (!scheduleAt.value) scheduleAt.value = local(d);
            scheduleAt.min = local(new Date(Date.now() + 120000));
            schedule.querySelector('[data-quick]').focus();
        }
    }
    function closeSchedule() {
        schedule.hidden = true;
        $('[data-mc="schedule-menu"]').setAttribute('aria-expanded', 'false');
    }
    subj.addEventListener('input', touch);
    subj.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); editor.focus(); } });
    sigSel.addEventListener('change', function () { renderSignature(); touch(); });
    quoteOn.addEventListener('change', function () { quoteBox.classList.toggle('is-off', !quoteOn.checked); touch(); });
    fromSel.addEventListener('change', function () {
        if (!state) return;
        save({ account_id: Number(fromSel.value) }).catch(function () {
            fromSel.value = String(state ? state.accountId : '');
        });
    });

    // ---- keyboard -------------------------------------------------------------------------------------------
    dlg.addEventListener('keydown', function (e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') { e.preventDefault(); send(); return; }
        if ((e.ctrlKey || e.metaKey) && (e.key === 'k' || e.key === 'K') && editor.contains(document.activeElement)) { e.preventDefault(); openLink(); return; }
        if (e.key === 'Escape' && !schedule.hidden) { e.preventDefault(); suppressCancel = true; closeSchedule(); $('[data-mc="schedule-menu"]').focus(); return; }
        if (e.key === 'Escape' && !confirmBox.hidden) { e.preventDefault(); suppressCancel = true; confirmBox.hidden = true; }
    });
    dlg.addEventListener('cancel', function (e) {
        e.preventDefault();
        if (suppressCancel) { suppressCancel = false; return; }
        leave('close');
    });
    dlg.addEventListener('close', function () {
        if (state) leave('close');
    });
    window.addEventListener('pagehide', function () {
        if (!state || !dirty || !navigator.sendBeacon) return;
        // A beacon carries no header: the token travels in the body (includes/csrf.php reads _csrf there)
        var body = JSON.stringify({ action: 'save', outboxId: state.id, fields: fields(), _csrf: HOD.csrf() });
        navigator.sendBeacon(API, new Blob([body], { type: 'application/json' }));
    });

    document.addEventListener('mail:compose', function (e) { open(e.detail || {}); });

    window.HODMailComposer = {
        current: function () {
            if (!state || !dlg.open) return null;
            return {
                accountId: state.accountId, mode: mode, messageId: state.messageId, outboxId: state.id,
                subject: subj.value, html: editor.innerHTML, text: editor.innerText,   // the person's own words only
                to: chips.to.list.slice(), cc: chips.cc.list.slice()
            };
        },
        replaceBody: function (html) {
            if (!state) return;
            editor.innerHTML = cleanHtml(html);
            touch();
        },
        insertHtml: function (html) {
            if (!state) return;
            restoreRange();
            document.execCommand('insertHTML', false, cleanHtml(html));
            saveRange();
            touch();
        },
        open: open
    };
})();
