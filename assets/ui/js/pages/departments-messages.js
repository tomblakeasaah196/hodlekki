// /assets/ui/js/pages/departments-messages.js
// The Messages tab of Departments (modules/departments/index.php), for SMS
// Studio users: one message to workers by text, email and app alert, now or at
// a set time, with a live count of who it reaches and what it costs, a test to
// yourself, templates and the list of messages sent and scheduled. Data from
// /api/department_api.php (msg_*). Who it reaches is worked out on the server;
// names come from people, so they go in as TEXT (DOM nodes).
(function () {
    'use strict';

    var HOD = window.HOD;
    if (!HOD || !HOD.actions) return;
    var boot = HOD.data('departments-boot');
    var panel = document.getElementById('panel-messages');
    if (!boot || !panel) return;

    var API = boot.api;
    var SKIP_LABELS = {
        no_email: 'no usable email', no_confirmed_email: 'no usable email', withdrawn: 'unsubscribed',
        suppressed: 'email blocked', no_consent: 'no consent', shared: 'shared address', not_ready: 'email not set up'
    };
    var SOURCE_LABELS = { confirmed: 'confirmed', personal: 'personal', profile: 'profile' };

    var state = { booted: false, audience: 'all', departments: [], events: [], history: [], preview: null,
                  previewTimer: null, previewSeq: 0, token: newToken(), historyTimer: null };

    var $form = document.getElementById('dmsg-form');
    var $main = document.getElementById('dmsg-main');
    var $loading = document.getElementById('dmsg-loading');
    var $depts = document.getElementById('dmsg-depts');
    var $deptsWrap = document.getElementById('dmsg-depts-wrap');
    var $eventWrap = document.getElementById('dmsg-event-wrap');
    var $event = document.getElementById('dmsg-event');
    var $subunits = document.getElementById('dmsg-subunits');
    var $subject = document.getElementById('dmsg-subject');
    var $body = document.getElementById('dmsg-body');
    var $counter = document.getElementById('dmsg-counter');
    var $at = document.getElementById('dmsg-at');
    var $send = document.getElementById('dmsg-send');
    var $summary = document.getElementById('dmsg-summary');
    var $template = document.getElementById('dmsg-template');
    var $history = document.getElementById('dmsg-history');
    var $historyCard = document.getElementById('dmsg-history-card');

    /* ------------------------------------------------------------- helpers */

    function newToken() { return 'm' + Date.now().toString(36) + Math.random().toString(36).slice(2, 10); }

    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text !== undefined && text !== null) n.textContent = String(text);
        return n;
    }

    function call(action, data, opts) {
        return HOD.api(API, Object.assign({ action: action, data: data || {} }, opts || {}));
    }

    function num(n) { return Number(n || 0).toLocaleString('en-NG'); }
    function plural(n, one, many) { return num(n) + ' ' + (Number(n) === 1 ? one : many); }

    function when(s) {
        if (!s) return '';
        var d = new Date(String(s).replace(' ', 'T'));
        return isNaN(d.getTime()) ? String(s) : d.toLocaleString('en-GB', { day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit', hour12: true });
    }

    function whenMode() {
        var r = $form.querySelector('input[name="when"]:checked');
        return r ? r.value : 'now';
    }

    function selectedDepts() {
        return Array.prototype.map.call($depts.querySelectorAll('input[type="checkbox"]:checked'), function (c) { return c.value; });
    }

    function spec() {
        return {
            audience: state.audience,
            department_ids: JSON.stringify(selectedDepts()),
            include_subunits: $subunits.checked ? '1' : '0',
            event_id: $event.value || ''
        };
    }

    /* ----------------------------------------------------------- counter */

    // A close estimate of one person's text (the server's preview is exact).
    var GSM = /^[A-Za-z0-9 @£$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ!"#¤%&'()*+,\-.\/:;<=>?¡ÄÖÑÜ§¿äöñüà^{}\\\[~\]|€]*$/;
    function drawCounter() {
        var text = 'HODLEKKI: ' + $body.value.replace(/\{\{\s*first_name\s*\}\}/g, 'Firstname').replace(/\{\{\s*(last_name|full_name)\s*\}\}/g, 'Firstname Lastname');
        var gsm = GSM.test(text);
        var len = text.length;
        var pages = gsm ? (len <= 160 ? 1 : Math.ceil(len / 153)) : (len <= 70 ? 1 : Math.ceil(len / 67));
        var p = state.preview && state.preview.sms && state.preview.sms.pages ? state.preview.sms.pages : pages;
        $counter.textContent = num($body.value.length) + ' characters · ' + plural(p, 'text', 'texts') + ' each' + (gsm ? '' : ' · special characters');
        $counter.classList.toggle('text-danger', p > 1 || !gsm);
    }

    /* ------------------------------------------------------------- boot */

    function load() {
        return call('msg_boot', {}, { quiet: true }).then(function (res) {
            state.booted = true;
            state.departments = res.departments || [];
            state.events = res.events || [];
            state.history = res.history || [];
            drawDepartments();
            drawEvents();
            drawTemplates(res.templates || []);
            $at.min = res.now || '';
            $loading.hidden = true;
            $main.hidden = false;
            $historyCard.hidden = false;
            drawHistory();
            drawCounter();
            schedulePreview();
        }).catch(function (err) {
            $loading.textContent = '';
            $loading.appendChild(el('p', 'text-sm text-danger', err.message || 'Messages did not load. Reload the page.'));
        });
    }

    function drawDepartments() {
        $depts.textContent = '';
        state.departments.forEach(function (d) {
            var label = el('label', 'ui-check py-1' + (d.parent_id ? ' pl-6' : ''));
            var box = el('input');
            box.type = 'checkbox';
            box.value = String(d.id);
            box.setAttribute('data-action-change', 'dmsg.changed');
            label.appendChild(box);
            label.appendChild(el('span', '', d.name + (d.parent ? ' · ' + d.parent : '')));
            $depts.appendChild(label);
        });
        if (!state.departments.length) $depts.appendChild(el('p', 'ui-meta', 'No departments yet.'));
    }

    function drawEvents() {
        $event.textContent = '';
        var first = el('option', '', 'Choose an event');
        first.value = '';
        $event.appendChild(first);
        state.events.forEach(function (e) {
            var o = el('option', '', e.title + (e.event_date ? ' · ' + HOD.format.date(e.event_date) : ''));
            o.value = String(e.id);
            $event.appendChild(o);
        });
    }

    function drawTemplates(list) {
        $template.textContent = '';
        var first = el('option', '', 'Templates');
        first.value = '';
        $template.appendChild(first);
        list.forEach(function (t) {
            var o = el('option', '', t.name);
            o.value = String(t.id);
            $template.appendChild(o);
        });
    }

    /* ----------------------------------------------------------- preview */

    function schedulePreview() {
        clearTimeout(state.previewTimer);
        state.previewTimer = setTimeout(preview, 350);
    }

    function audienceReady() {
        if (state.audience === 'departments') return selectedDepts().length > 0;
        if (state.audience === 'absent') return !!$event.value;
        return true;
    }

    function preview() {
        if (!state.booted) return;
        if (!audienceReady()) {
            state.preview = null;
            drawSummary(state.audience === 'absent' ? 'Choose the event.' : 'Tick at least one department.');
            return;
        }
        var seq = ++state.previewSeq;
        call('msg_preview', Object.assign(spec(), { body: $body.value }), { quiet: true }).then(function (res) {
            if (seq !== state.previewSeq) return;
            state.preview = res.preview;
            drawSummary();
            drawCounter();
        }).catch(function (err) {
            if (seq !== state.previewSeq) return;
            state.preview = null;
            drawSummary(err.message || 'Could not count. Try again.');
        });
    }

    function line(icon, label, value, meta) {
        var row = el('div', 'flex items-start gap-3');
        var i = el('span', 'text-ink-subtle');
        i.innerHTML = HOD.icon(icon);   // our own sprite name
        row.appendChild(i);
        var main = el('div', 'min-w-0 flex-1');
        var top = el('div', 'flex items-baseline justify-between gap-2');
        top.appendChild(el('span', 'text-sm text-ink-muted', label));
        top.appendChild(el('span', 'text-lg font-semibold ui-num', value));
        main.appendChild(top);
        if (meta) main.appendChild(el('p', 'ui-meta', meta));
        row.appendChild(main);
        return row;
    }

    function skippedText(skipped) {
        var parts = [];
        Object.keys(skipped || {}).forEach(function (k) {
            if (skipped[k]) parts.push(num(skipped[k]) + ' ' + (SKIP_LABELS[k] || k.replace(/_/g, ' ')));
        });
        return parts.length ? 'Not by email: ' + parts.join(', ') + '.' : '';
    }

    function drawSummary(note) {
        $summary.textContent = '';
        var p = state.preview;
        if (!p) {
            $summary.appendChild(el('p', 'ui-meta', note || 'Choose who gets it.'));
            $send.disabled = true;
            return;
        }
        $summary.appendChild(line('users', 'Workers', num(p.people)));
        var smsMeta = [];
        if (p.sms.cost !== null && p.sms.units) smsMeta.push('About NGN ' + num(Math.round(p.sms.cost)) + ' (' + plural(p.sms.units, 'unit', 'units') + ')');
        var lost = p.sms.no_phone + p.sms.invalid;
        if (lost) smsMeta.push(num(lost) + ' without a usable number');
        if (p.sms.duplicates) smsMeta.push(num(p.sms.duplicates) + ' sharing a number');
        $summary.appendChild(line('smartphone', 'Texts', num(p.sms.count), smsMeta.join(' · ')));
        var src = [];
        Object.keys(p.email.sources || {}).forEach(function (k) { src.push(num(p.email.sources[k]) + ' ' + (SOURCE_LABELS[k] || k)); });
        $summary.appendChild(line('mail', 'Emails', num(p.email.count), [src.length ? 'From ' + src.join(', ') : '', skippedText(p.email.skipped)].filter(Boolean).join(' · ')));
        $summary.appendChild(line('bell', 'App alerts', num(p.push.count), 'Free. In the app and the bell.'));
        if (p.sample && p.sample.length) {
            $summary.appendChild(el('p', 'ui-meta', p.sample.join(', ') + (p.people > p.sample.length ? ' and ' + num(p.people - p.sample.length) + ' more' : '')));
        }
        $send.disabled = p.people === 0;
    }

    /* ----------------------------------------------------------- history */

    function statusBadge(s) {
        var tone = { sent: 'success', scheduled: 'info', sending: 'warning', failed: 'danger', cancelled: '' }[s] || '';
        var label = { sent: 'Sent', scheduled: 'Scheduled', sending: 'Sending', failed: 'Not sent', cancelled: 'Cancelled' }[s] || s;
        return el('span', 'ui-badge' + (tone ? ' ui-badge--' + tone : ''), label);
    }

    function channelLine(h) {
        var c = h.counts || {};
        var parts = [];
        if (h.sms) parts.push('Texts ' + num(h.sms.sent) + '/' + num(h.sms.total) + (h.sms.failed ? ' (' + num(h.sms.failed) + ' failed)' : ''));
        else if (c.sms) parts.push('Texts ' + num(c.sms.queued || 0));
        if (h.email) {
            var e = h.email, total = 0;
            Object.keys(e).forEach(function (k) { total += e[k]; });
            parts.push('Emails ' + num(e.sent || 0) + '/' + num(total) + (e.queued ? ' (' + num(e.queued) + ' waiting)' : ''));
        } else if (c.email) parts.push('Emails ' + num(c.email.queued || 0));
        if (c.push) parts.push('Alerts ' + num(c.push.queued || 0));
        return parts.join(' · ');
    }

    function drawHistory() {
        $history.textContent = '';
        if (!state.history.length) {
            var empty = el('li', 'ui-list__item');
            empty.appendChild(el('span', 'ui-meta', 'Nothing sent yet.'));
            $history.appendChild(empty);
            return;
        }
        state.history.forEach(function (h) {
            var li = el('li', 'ui-list__item items-start');
            var main = el('div', 'ui-list__main');
            var top = el('div', 'flex flex-wrap items-center gap-2');
            top.appendChild(el('span', 'font-medium text-ink', h.subject));
            top.appendChild(statusBadge(h.status));
            main.appendChild(top);
            var meta = [h.audience_label, (h.status === 'scheduled' ? 'For ' : '') + when(h.status === 'sent' && h.sent_at ? h.sent_at : h.send_at)];
            if (h.by) meta.push('by ' + h.by);
            main.appendChild(el('p', 'ui-meta', meta.join(' · ')));
            var ch = channelLine(h);
            if (ch) main.appendChild(el('p', 'ui-meta ui-num', ch));
            if (h.error) main.appendChild(el('p', 'text-sm text-danger', h.error));
            li.appendChild(main);
            var end = el('div', 'flex flex-wrap items-center gap-2');
            var reuse = el('button', 'ui-btn ui-btn--ghost ui-btn--sm', 'Use again');
            reuse.type = 'button';
            reuse.setAttribute('data-action', 'dmsg.reuse');
            reuse.setAttribute('data-id', h.id);
            end.appendChild(reuse);
            if (h.status === 'scheduled') {
                var cancel = el('button', 'ui-btn ui-btn--danger-ghost ui-btn--sm', 'Cancel');
                cancel.type = 'button';
                cancel.setAttribute('data-action', 'dmsg.cancel');
                cancel.setAttribute('data-id', h.id);
                end.appendChild(cancel);
            }
            li.appendChild(end);
            $history.appendChild(li);
        });
    }

    function refreshHistory() {
        return call('msg_history', {}, { quiet: true }).then(function (res) {
            state.history = res.history || [];
            drawHistory();
        }).catch(function () { /* keep the list we have */ });
    }

    /* ----------------------------------------------------------- actions */

    function setAudience(a) {
        state.audience = a;
        panel.querySelectorAll('[data-action="dmsg.audience"]').forEach(function (b) {
            b.setAttribute('aria-pressed', b.getAttribute('data-audience') === a ? 'true' : 'false');
        });
        $deptsWrap.hidden = a !== 'departments';
        $eventWrap.hidden = a !== 'absent';
        schedulePreview();
    }

    HOD.actions.on('dmsg.audience', function (b) { setAudience(b.getAttribute('data-audience')); });
    HOD.actions.on('dmsg.changed', function () { drawCounter(); schedulePreview(); });

    HOD.actions.on('dmsg.when', function () {
        var later = whenMode() === 'later';
        $at.hidden = !later;
        $send.querySelector('span').textContent = later ? 'Schedule' : 'Send now';
        if (later && !$at.value) $at.focus();
    });

    HOD.actions.on('dmsg.merge', function (b) {
        var tag = '{{' + b.getAttribute('data-field') + '}}';
        var s = $body.selectionStart || $body.value.length, e = $body.selectionEnd || s;
        $body.value = $body.value.slice(0, s) + tag + $body.value.slice(e);
        $body.focus();
        $body.setSelectionRange(s + tag.length, s + tag.length);
        drawCounter();
        schedulePreview();
    });

    HOD.actions.on('dmsg.template', function (sel) {
        if (!sel.value) return;
        call('msg_template_get', { id: sel.value }).then(function (res) {
            $body.value = res.template.body_template || '';
            if (!$subject.value) $subject.value = res.template.name || '';
            sel.value = '';
            drawCounter();
            schedulePreview();
        });
    });

    HOD.actions.on('dmsg.save-template', function (b) {
        var name = $subject.value.trim();
        if (!name) { HOD.toast('Add a subject first. It names the template.', 'warning'); $subject.focus(); return; }
        HOD.busy(b, true);
        call('msg_template_save', { name: name, body: $body.value }).then(function (res) {
            HOD.toast('Saved as "' + name + '".', 'success');
            var o = el('option', '', name);
            o.value = String(res.id);
            $template.appendChild(o);
        }).catch(function () { /* toast shown */ }).then(function () { HOD.busy(b, false); });
    });

    HOD.actions.on('dmsg.test', function (b) {
        if (!$body.value.trim()) { HOD.toast('Write the message first.', 'warning'); $body.focus(); return; }
        HOD.busy(b, true);
        call('msg_test', { body: $body.value, subject: $subject.value }).then(function (res) {
            HOD.toast(res.message || 'Sent.', 'success');
        }).catch(function () { /* toast shown */ }).then(function () { HOD.busy(b, false); });
    });

    HOD.actions.on('dmsg.send', function () {
        if (!$subject.value.trim()) { HOD.toast('Add a subject.', 'warning'); $subject.focus(); return; }
        if (!$body.value.trim()) { HOD.toast('Write the message.', 'warning'); $body.focus(); return; }
        if (!audienceReady()) { HOD.toast(state.audience === 'absent' ? 'Choose the event.' : 'Tick at least one department.', 'warning'); return; }
        var later = whenMode() === 'later';
        if (later && !$at.value) { HOD.toast('Pick the time.', 'warning'); $at.focus(); return; }
        var p = state.preview;
        var reach = p ? (plural(p.sms.count, 'text', 'texts') + (p.sms.cost ? ' (about NGN ' + num(Math.round(p.sms.cost)) + ')' : '') + ', '
            + plural(p.email.count, 'email', 'emails') + ' and ' + plural(p.push.count, 'app alert', 'app alerts') + '.') : '';
        HOD.confirm({
            title: later ? 'Schedule for ' + when($at.value) + '?' : 'Send to ' + plural(p ? p.people : 0, 'worker', 'workers') + '?',
            message: later ? 'Who gets it is worked out at that time. ' + reach : reach,
            confirmLabel: later ? 'Schedule' : 'Send now'
        }).then(function (ok) {
            if (!ok) return;
            HOD.busy($send, true);
            call('msg_send', Object.assign(spec(), {
                subject: $subject.value, body: $body.value, when: later ? 'later' : 'now', send_at: $at.value, client_token: state.token
            })).then(function (res) {
                HOD.toast(res.message || 'Done.', 'success');
                state.token = newToken();
                refreshHistory();
            }).catch(function () { /* toast shown; the same token stops a double send on retry */ }).then(function () { HOD.busy($send, false); });
        });
    });

    HOD.actions.on('dmsg.cancel', function (b) {
        HOD.confirm({ title: 'Cancel this message?', message: 'Nothing has gone yet. Nothing will.', confirmLabel: 'Cancel message', tone: 'danger' })
            .then(function (ok) {
                if (!ok) return;
                call('msg_cancel', { id: b.getAttribute('data-id') }).then(function (res) {
                    HOD.toast(res.message || 'Cancelled.', 'success');
                    refreshHistory();
                }).catch(function () { refreshHistory(); });
            });
    });

    HOD.actions.on('dmsg.reuse', function (b) {
        var h = state.history.filter(function (x) { return String(x.id) === b.getAttribute('data-id'); })[0];
        if (!h) return;
        $subject.value = h.subject;
        $body.value = h.body;
        drawCounter();
        schedulePreview();
        $subject.focus();
    });

    HOD.actions.on('dmsg.refresh', function () { refreshHistory(); });

    /* ----------------------------------------------------------- the tab */

    function onTab(tab) {
        clearInterval(state.historyTimer);
        state.historyTimer = null;
        if (tab !== 'messages') return;
        var actions = document.querySelector('.ui-page-header__actions');
        if (actions) actions.hidden = true;
        if (!state.booted) load();
        else refreshHistory();
        state.historyTimer = setInterval(function () { if (!document.hidden && !panel.hidden) refreshHistory(); }, 30000);
    }

    document.addEventListener('ui:tab', function (e) { if (e.detail && e.detail.tab) onTab(e.detail.tab); });
    if (!panel.hidden) onTab('messages');
})();
