// /assets/ui/js/pages/departments-messages.js
// The Messages tab of Departments (modules/departments/index.php), for SMS
// Studio users: one message to workers by text, email and app alert, now or at
// a set time, with a live count of who it reaches and what it costs, a test to
// yourself, templates and the list of messages sent and scheduled. After a
// send the form makes way for a result card whose counts update live; a row in
// the list opens the message's delivery, person by person. The same message
// never goes to the same group twice in a day (the server refuses it, no
// override). Data from
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
                  previewTimer: null, previewSeq: 0, token: newToken(), historyTimer: null,
                  lastId: null, liveTimer: null, liveTicks: 0, detail: null, detailId: null, detailTimer: null };

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
    var $done = document.getElementById('dmsg-done');
    var $doneProgress = document.getElementById('dmsg-done-progress');
    var $detailBody = document.getElementById('dmsg-detail-body');
    var $detailReuse = document.getElementById('dmsg-detail-reuse');

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
            // The whole row opens the message's delivery.
            var main = el('button', 'ui-list__main text-left');
            main.type = 'button';
            main.setAttribute('data-action', 'dmsg.detail');
            main.setAttribute('data-id', h.id);
            main.setAttribute('aria-label', 'Details of ' + h.subject);
            var top = el('span', 'flex flex-wrap items-center gap-2');
            top.appendChild(el('span', 'font-medium text-ink', h.subject));
            top.appendChild(statusBadge(h.status));
            main.appendChild(top);
            var meta = [h.audience_label, (h.status === 'scheduled' ? 'For ' : '') + when(h.status === 'sent' && h.sent_at ? h.sent_at : h.send_at)];
            if (h.by) meta.push('by ' + h.by);
            main.appendChild(el('span', 'block ui-meta', meta.join(' · ')));
            var ch = channelLine(h);
            if (ch) main.appendChild(el('span', 'block ui-meta ui-num', ch));
            if (h.error) main.appendChild(el('span', 'block text-sm text-danger', h.error));
            li.appendChild(main);
            var end = el('div', 'flex flex-wrap items-center gap-2');
            if (h.status === 'scheduled') {
                var cancel = el('button', 'ui-btn ui-btn--danger-ghost ui-btn--sm', 'Cancel');
                cancel.type = 'button';
                cancel.setAttribute('data-action', 'dmsg.cancel');
                cancel.setAttribute('data-id', h.id);
                end.appendChild(cancel);
            }
            var go = el('span', 'text-ink-subtle');
            go.innerHTML = HOD.icon('chevron-right');   // our own sprite name
            end.appendChild(go);
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
            if (ok) send(later);
        });
    });

    // One send. The same message to the same group on the same day comes back
    // as code 'repeat': it is refused, with no way round it but new words.
    function send(later) {
        HOD.busy($send, true);
        lockForm(true);
        var data = Object.assign(spec(), {
            subject: $subject.value, body: $body.value, when: later ? 'later' : 'now', send_at: $at.value, client_token: state.token
        });
        function release() { HOD.busy($send, false); lockForm(false); }
        call('msg_send', data, { quiet: true }).then(function (res) {
            release();
            state.token = newToken();
            done(later, res);
            refreshHistory();
        }, function (err) {
            release();
            // The same token stays, so a retry after a lost reply cannot send twice.
            HOD.toast((err && err.message) || 'It did not go out. Try again.', err && err.code === 'repeat' ? 'warning' : 'danger');
        });
    }

    function lockForm(on) {
        Array.prototype.forEach.call($form.querySelectorAll('input, select, textarea, button'), function (n) {
            if (n !== $send) n.disabled = on;
        });
    }

    // After a send: the form makes way for the result, so it cannot be sent
    // twice by a second tap. Its counts update live for a few minutes;
    // "New message" brings back an empty form.
    function done(later, res) {
        var c = res.counts || {};
        var title = later ? 'Message scheduled' : (res.duplicate ? 'Already sent' : 'Message sent');
        var text = res.message || (later ? 'Scheduled.' : 'Sent.');
        if (!later && c.people !== undefined) title = 'Sent to ' + plural(c.people, 'worker', 'workers');
        state.lastId = res.id || null;
        $done.querySelector('.ui-empty__title').textContent = title;
        $done.querySelector('.ui-empty__text').textContent = later ? text + ' Cancel it in the list below until it goes.' : text;
        $done.querySelector('[data-action="dmsg.view-done"]').hidden = !state.lastId;
        $doneProgress.textContent = '';
        $doneProgress.hidden = later || !state.lastId;
        $form.hidden = true;
        $done.hidden = false;
        $done.focus();
        $done.scrollIntoView({ block: 'center', behavior: 'smooth' });
        HOD.toast(title, { tone: 'success', detail: text, duration: 9000 });
        $subject.value = '';
        $body.value = '';
        var now = $form.querySelector('input[name="when"][value="now"]');
        if (now) now.checked = true;
        $at.value = '';
        $at.hidden = true;
        $send.querySelector('span').textContent = 'Send now';
        drawCounter();
        if (!later && state.lastId) startLive();
    }

    var SMS_STATE = {
        delivered: ['Delivered', 'success'], awaiting: ['Sent, awaiting report', 'warning'], waiting: ['Queued', 'info'],
        failed: ['Failed', 'danger'], blocked: ['Blocked (NGN 0)', ''], unknown: ['Unknown', ''], cancelled: ['Cancelled', '']
    };
    var EMAIL_STATE = { sent: ['Sent', 'success'], waiting: ['Waiting', 'info'], failed: ['Failed', 'danger'], not_sent: ['Not sent', ''] };

    function badge(pair) { return el('span', 'ui-badge self-start' + (pair[1] ? ' ui-badge--' + pair[1] : ''), pair[0]); }

    function parts(map, labels) {
        var out = [];
        Object.keys(labels).forEach(function (k) { if (map[k]) out.push(num(map[k]) + ' ' + labels[k][0].charAt(0).toLowerCase() + labels[k][0].slice(1)); });
        return out.join(' · ');
    }

    function tile(icon, label, total, meta) {
        var t = el('div', 'ui-stack ui-stack--sm');
        var top = el('div', 'flex items-center gap-2 text-sm text-ink-muted');
        var i = el('span', '');
        i.innerHTML = HOD.icon(icon);   // our own sprite name
        top.appendChild(i);
        top.appendChild(el('span', '', label));
        t.appendChild(top);
        t.appendChild(el('span', 'text-2xl font-semibold ui-num text-ink', num(total)));
        if (meta) t.appendChild(el('span', 'ui-meta', meta));
        return t;
    }

    function drawProgress(box, sum) {
        box.textContent = '';
        box.appendChild(tile('smartphone', 'Texts', sum.sms.total, parts(sum.sms, SMS_STATE) || 'None'));
        box.appendChild(tile('mail', 'Emails', sum.email.total, parts(sum.email, EMAIL_STATE) || 'None'));
        box.appendChild(tile('bell', 'App alerts', sum.push, 'In the bell and on phones'));
    }

    // The result card follows the message for three minutes (every 5 s).
    function startLive() {
        stopLive();
        state.liveTicks = 0;
        var tick = function () {
            if ($done.hidden || !state.lastId) { stopLive(); return; }
            if (++state.liveTicks > 36) { stopLive(); return; }
            if (document.hidden || panel.hidden) return;
            call('msg_detail', { id: state.lastId }, { quiet: true }).then(function (res) {
                if (!$done.hidden) drawProgress($doneProgress, res.summary);
            }).catch(function () { /* the next tick tries again */ });
        };
        tick();
        state.liveTimer = setInterval(tick, 5000);
    }

    function stopLive() {
        if (state.liveTimer) { clearInterval(state.liveTimer); state.liveTimer = null; }
    }

    HOD.actions.on('dmsg.new', function () {
        stopLive();
        $done.hidden = true;
        $form.hidden = false;
        $subject.focus();
        schedulePreview();
    });

    HOD.actions.on('dmsg.view-done', function () { if (state.lastId) openDetail(state.lastId); });

    /* ------------------------------------------------- delivery details */

    function openDetail(id) {
        state.detailId = String(id);
        state.detail = null;
        $detailBody.textContent = '';
        $detailBody.appendChild(el('div', 'ui-loading')).appendChild(el('span', 'ui-spinner'));
        $detailReuse.setAttribute('data-id', state.detailId);
        HOD.dialog.open('dmsg-detail');
        loadDetail();
        if (state.detailTimer) clearInterval(state.detailTimer);
        state.detailTimer = setInterval(function () {
            var dlg = document.getElementById('dmsg-detail');
            if (!dlg || !dlg.open) { clearInterval(state.detailTimer); state.detailTimer = null; return; }
            if (!document.hidden) loadDetail();
        }, 10000);
    }

    function loadDetail() {
        var id = state.detailId;
        if (!id) return;
        call('msg_detail', { id: id }, { quiet: true }).then(function (res) {
            if (id !== state.detailId) return;
            state.detail = res;
            drawDetail(res);
        }).catch(function (err) {
            if (id !== state.detailId || state.detail) return;
            $detailBody.textContent = '';
            $detailBody.appendChild(el('p', 'text-sm text-danger', err.message || 'Details did not load. Try Refresh.'));
        });
    }

    function drawDetail(d) {
        var m = d.message;
        $detailBody.textContent = '';
        var head = el('div', 'ui-stack ui-stack--sm');
        var top = el('div', 'flex flex-wrap items-center gap-2');
        top.appendChild(el('h3', 'ui-h3', m.subject));
        top.appendChild(statusBadge(m.status));
        head.appendChild(top);
        var meta = [m.audience_label, (m.status === 'scheduled' ? 'For ' : '') + when(m.status === 'sent' && m.sent_at ? m.sent_at : m.send_at)];
        if (m.by) meta.push('by ' + m.by);
        head.appendChild(el('p', 'ui-meta', meta.join(' · ')));
        $detailBody.appendChild(head);
        $detailBody.appendChild(el('p', 'whitespace-pre-line rounded-ui border border-line bg-surface-sunken p-3 text-sm text-ink', m.body));
        if (m.error) $detailBody.appendChild(el('p', 'text-sm text-danger', m.error));
        if (m.status === 'scheduled' || m.status === 'cancelled') return;

        var grid = el('div', 'grid gap-3 sm:grid-cols-3');
        drawProgress(grid, d.summary);
        $detailBody.appendChild(grid);

        var people = d.people || [];
        var problems = people.filter(function (p) {
            return ['failed', 'blocked', 'unknown'].indexOf(p.sms) > -1 || ['failed', 'not_sent'].indexOf(p.email_state) > -1 || (!p.sms && !p.email);
        });
        var bar = el('div', 'flex flex-wrap items-center justify-between gap-2');
        bar.appendChild(el('h4', 'text-sm font-semibold text-ink', plural(people.length, 'person', 'people')));
        var seg = el('div', 'ui-segmented');
        seg.setAttribute('role', 'group');
        seg.setAttribute('aria-label', 'Show');
        [['all', 'Everyone', people.length], ['problems', 'Needs a look', problems.length]].forEach(function (f) {
            var b = el('button', 'ui-segmented__item', f[1] + ' (' + num(f[2]) + ')');
            b.type = 'button';
            b.setAttribute('data-action', 'dmsg.detail-filter');
            b.setAttribute('data-filter', f[0]);
            b.setAttribute('aria-pressed', (state.detailFilter || 'all') === f[0] ? 'true' : 'false');
            seg.appendChild(b);
        });
        bar.appendChild(seg);
        $detailBody.appendChild(bar);

        var list = (state.detailFilter === 'problems') ? problems : people;
        var wrap = el('div', 'ui-table-wrap max-h-96 overflow-y-auto');
        var table = el('table', 'ui-table ui-table--stack');
        var thead = el('thead');
        var hr = el('tr');
        ['Worker', 'Text', 'Email'].forEach(function (h) { var th = el('th', '', h); th.scope = 'col'; hr.appendChild(th); });
        thead.appendChild(hr);
        table.appendChild(thead);
        var tbody = el('tbody');
        list.forEach(function (p) {
            var tr = el('tr');
            var who = el('td');
            who.setAttribute('data-label', 'Worker');
            var nameBox = el('div', 'min-w-0');
            nameBox.appendChild(el('span', 'block font-medium text-ink', p.name || 'Unknown'));
            if (p.phone) nameBox.appendChild(el('span', 'block ui-meta ui-num', p.phone));
            who.appendChild(nameBox);
            var tx = el('td');
            tx.setAttribute('data-label', 'Text');
            if (p.sms) {
                var sbox = el('div', 'ui-stack ui-stack--sm');
                sbox.appendChild(badge(SMS_STATE[p.sms] || [p.sms, '']));
                if (p.sms_note) sbox.appendChild(el('span', 'ui-meta', p.sms_note));
                tx.appendChild(sbox);
            } else {
                tx.appendChild(el('span', 'ui-meta', 'No number'));
            }
            var em = el('td');
            em.setAttribute('data-label', 'Email');
            if (p.email) {
                var ebox = el('div', 'ui-stack ui-stack--sm');
                ebox.appendChild(badge(EMAIL_STATE[p.email_state] || [p.email_state, '']));
                ebox.appendChild(el('span', 'ui-meta break-all', p.email));
                if (p.email_note) ebox.appendChild(el('span', 'ui-meta', p.email_note));
                em.appendChild(ebox);
            } else {
                em.appendChild(el('span', 'ui-meta', 'No email'));
            }
            tr.appendChild(who);
            tr.appendChild(tx);
            tr.appendChild(em);
            tbody.appendChild(tr);
        });
        if (!list.length) {
            var none = el('tr');
            var td = el('td', 'ui-meta', state.detailFilter === 'problems' ? 'Nothing needs a look.' : 'Nobody yet.');
            td.colSpan = 3;
            none.appendChild(td);
            tbody.appendChild(none);
        }
        table.appendChild(tbody);
        wrap.appendChild(table);
        $detailBody.appendChild(wrap);
    }

    HOD.actions.on('dmsg.detail', function (b) { state.detailFilter = 'all'; openDetail(b.getAttribute('data-id')); });
    HOD.actions.on('dmsg.detail-refresh', function () { loadDetail(); });
    HOD.actions.on('dmsg.detail-filter', function (b) {
        state.detailFilter = b.getAttribute('data-filter') || 'all';
        if (state.detail) drawDetail(state.detail);
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

    // "Use again" (in the details) copies the message into an empty form. The
    // same words to the same group cannot go again the same day.
    HOD.actions.on('dmsg.reuse', function (b) {
        var id = b.getAttribute('data-id');
        var h = (state.detail && String(state.detail.message.id) === String(id)) ? state.detail.message
            : state.history.filter(function (x) { return String(x.id) === String(id); })[0];
        if (!h) return;
        HOD.dialog.close('dmsg-detail');
        stopLive();
        $done.hidden = true;
        $form.hidden = false;
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
