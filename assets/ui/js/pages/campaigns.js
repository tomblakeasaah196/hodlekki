// /assets/ui/js/pages/campaigns.js
// Email Campaigns (modules/campaigns/index.php). Every request goes to
// /api/campaigns_api.php as JSON; the server decides access and validates
// everything (includes/campaigns/campaign_actions.php). The email itself is
// edited in the block editor (assets/ui/js/email-editor.js) and previewed as
// the real server render in a sandboxed frame.
(function () {
    'use strict';

    var HOD = window.HOD;
    var API = '/api/campaigns_api.php';
    var STATUS = {
        draft: ['Draft', ''], scheduled: ['Scheduled', 'ui-badge--info'], queued: ['Starting', 'ui-badge--info'],
        sending: ['Sending', 'ui-badge--primary'], sent: ['Sent', 'ui-badge--success'],
        cancelled: ['Stopped', 'ui-badge--warning'], failed: ['Failed', 'ui-badge--danger']
    };
    var GSM = /^[A-Za-z0-9 \r\n@£$¥èéùìòÇØøÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ!"#¤%&'()*+,\-./:;<=>?¡ÄÖÑÜ§¿äöñüà^{}\\[~\]|€]*$/;
    var cfg = {};
    var state = { c: null, reasons: {}, people: [], editor: null, poll: null, dirty: false };

    function call(action, data, opts) {
        return HOD.api(API, Object.assign({ action: action, json: true, data: data || {} }, opts || {}));
    }

    function one(sel) { return document.querySelector(sel); }

    function when(at) {
        if (!at) return '';
        var d = new Date(String(at).replace(' ', 'T'));
        if (isNaN(d.getTime())) return '';
        return d.toLocaleString('en-GB', { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
    }

    function badge(status) {
        var s = STATUS[status] || [status, ''];
        return '<span class="ui-badge ' + s[1] + '">' + HOD.esc(s[0]) + '</span>';
    }

    /* ---------- the list page ---------- */

    function create(form) {
        var v = HOD.form.values(form);
        var dept = parseInt(v.department_id || '0', 10) || null;
        var button = document.querySelector('[form="cam-new-form"]');
        HOD.busy(button, true);
        call('save', {
            name: v.name || '',
            department_id: dept,
            audience: dept ? { kind: 'departments', ids: [dept] } : { kind: 'church' }
        }, { quiet: true }).then(function (res) {
            window.location.href = '/modules/campaigns/index.php?id=' + res.campaign.id;
        }).catch(function (err) {
            HOD.form.setErrors(form, { name: err.message });
        }).finally(function () { HOD.busy(button, false); });
    }

    function saveSettings(form) {
        var button = form.querySelector('[type="submit"]');
        HOD.busy(button, true);
        var v = HOD.form.values(form);
        v.paused = form.elements.paused.checked ? 1 : 0;
        call('settings_save', v).then(function (res) {
            HOD.toast(res.message || 'Saved.', 'success');
        }).catch(function () {}).finally(function () { HOD.busy(button, false); });
    }

    /* ---------- one campaign ---------- */

    function form() { return one('[data-cam-form]'); }

    function formData() {
        var f = form();
        var kindEl = f.querySelector('input[name="kind"]:checked');
        var kind = kindEl ? kindEl.value : '';
        var picked = function (name) {
            return Array.prototype.map.call(f.querySelectorAll('input[name="' + name + '"]:checked'), function (i) { return i.value; });
        };
        var audience = { kind: kind };
        if (kind === 'statuses') audience.statuses = picked('statuses');
        if (kind === 'departments') audience.ids = picked('departments').map(Number);
        if (kind === 'regions') audience.ids = picked('regions').map(Number);
        return {
            id: state.c.id,
            version: state.c.version,
            name: f.elements.name.value,
            department_id: parseInt(f.elements.department_id.value || '0', 10) || null,
            audience: audience,
            reply_to: f.elements.reply_to.value,
            sms_fallback: f.elements.sms_fallback.checked ? 1 : 0,
            sms_text: f.elements.sms_text.value,
            track_opens: f.elements.track_opens.checked ? 1 : 0
        };
    }

    function showPicker(kind) {
        document.querySelectorAll('[data-cam-pick]').forEach(function (fs) {
            fs.hidden = fs.getAttribute('data-cam-pick') !== kind;
        });
    }

    function fill(c) {
        var f = form();
        f.elements.name.value = c.name;
        f.elements.department_id.value = c.department_id ? String(c.department_id) : '';
        var aud = c.audience || {};
        f.querySelectorAll('input[name="kind"]').forEach(function (r) { r.checked = r.value === aud.kind; });
        ['statuses', 'departments', 'regions'].forEach(function (name) {
            var chosen = (name === 'statuses' ? (aud.statuses || []) : (aud.kind === name ? (aud.ids || []) : [])).map(String);
            f.querySelectorAll('input[name="' + name + '"]').forEach(function (b) { b.checked = chosen.indexOf(b.value) !== -1; });
        });
        showPicker(aud.kind);
        f.elements.reply_to.value = c.reply_to || '';
        f.elements.sms_fallback.checked = !!c.sms_fallback;
        f.elements.sms_text.value = c.sms_text || '';
        f.elements.track_opens.checked = !!c.track_opens;
        one('[data-cam-sms]').hidden = !c.sms_fallback;
        smsCount(f.elements.sms_text);
        state.dirty = false;
    }

    function render(c) {
        state.c = c;
        one('[data-cam-name]').textContent = c.name;
        one('[data-cam-status]').innerHTML = badge(c.status);
        var whenLine = c.status === 'scheduled' ? 'Set for ' + when(c.scheduled_at)
            : (c.queued_at ? 'Started ' + when(c.queued_at) : '');
        if (c.finished_at && (c.status === 'sent' || c.status === 'cancelled')) whenLine += ' · ended ' + when(c.finished_at);
        one('[data-cam-when]').textContent = whenLine;

        var banner = one('[data-cam-banner]');
        banner.innerHTML = c.status === 'failed'
            ? '<div class="ui-banner ui-banner--danger mb-4" role="alert">' + HOD.icon('circle-alert') + '<div class="ui-banner__body"><p class="ui-banner__title">It could not start</p><p class="ui-banner__text">Nothing was sent. Check the email and try again.</p></div></div>'
            : '';

        fill(c);
        var editable = !!c.can.edit;
        one('[data-cam-fields]').disabled = !editable;
        one('[data-cam-save-row]').hidden = !editable;
        one('[data-cam-edit]').hidden = !editable;

        var subject = c.subject || '';
        one('[data-cam-subject]').textContent = subject || 'No subject yet';
        one('[data-cam-preheader]').textContent = '';

        var can = {
            send: c.can.send,
            unschedule: c.can.edit && (c.status === 'scheduled' || c.status === 'failed'),
            cancel: c.can.cancel,
            copy: c.can.copy,
            delete: c.can.delete
        };
        document.querySelectorAll('[data-cam-can]').forEach(function (b) {
            b.hidden = !can[b.getAttribute('data-cam-can')];
        });
        var problems = one('[data-cam-problems]');
        problems.innerHTML = (c.problems || []).map(function (p) { return '<li>' + HOD.esc(p) + '</li>'; }).join('');

        var started = c.queued_at && (c.status === 'sending' || c.status === 'sent' || c.status === 'cancelled');
        var reportCard = one('[data-cam-report]');
        reportCard.hidden = !started;
        // Once it has gone out, the report matters more than the settings it went out with.
        if (started && reportCard.parentNode.firstElementChild !== reportCard) {
            reportCard.parentNode.insertBefore(reportCard, reportCard.parentNode.firstElementChild);
        }
        if (started) {
            countLine(c.counts);
            report();
        } else {
            count();
        }
        if (c.status === 'queued' || c.status === 'sending') {
            if (!state.poll) state.poll = window.setInterval(refresh, 20000);
        } else if (state.poll) {
            window.clearInterval(state.poll);
            state.poll = null;
        }
    }

    function load() {
        return call('get', { id: cfg.id }).then(function (res) { render(res.campaign); });
    }

    function refresh() {
        if (document.hidden) return;
        call('get', { id: cfg.id }, { quiet: true }).then(function (res) {
            var before = state.c.status;
            state.c = res.campaign;
            if (before !== res.campaign.status || !state.dirty) render(res.campaign);
        }).catch(function () {});
    }

    function countLine(n, eta) {
        var parts = [n.total + (n.total === 1 ? ' person' : ' people')];
        parts.push(n.email + ' by email');
        if (state.c && state.c.sms_fallback || n.sms) parts.push(n.sms + ' by text');
        parts.push(n.none + ' not reached');
        one('[data-cam-count]').textContent = parts.join(' · ') + (eta ? '. Leaves ' + eta + '.' : '');
    }

    var countTimer = null;
    function count() {
        window.clearTimeout(countTimer);
        countTimer = window.setTimeout(function () {
            var d = formData();
            call('count', d, { quiet: true }).then(function (res) {
                var reasons = one('[data-cam-reasons]');
                if (!res.summary) {
                    one('[data-cam-count]').textContent = (res.errors || ['Choose who gets it.'])[0];
                    reasons.innerHTML = '';
                    return;
                }
                state.reasons = res.reasons || {};
                countLine(res.summary, res.summary.eta);
                reasons.innerHTML = Object.keys(res.summary.reasons || {}).map(function (k) {
                    return '<li>' + HOD.esc((state.reasons[k] || k) + ': ' + res.summary.reasons[k]) + '</li>';
                }).join('');
            }).catch(function () {
                one('[data-cam-count]').textContent = 'Could not count. Try again.';
            });
        }, 350);
    }

    function changed() {
        state.dirty = true;
        one('[data-cam-saved]').textContent = 'Not saved';
        count();
    }

    function kind(el) {
        showPicker(el.value);
        changed();
    }

    function from(el) {
        changed();
        var dept = parseInt(el.value || '0', 10);
        var reply = form().elements.reply_to;
        if (!dept) { reply.placeholder = ''; return; }
        call('mailbox', { department_id: dept }, { quiet: true }).then(function (res) {
            reply.placeholder = res.mailbox || '';
        }).catch(function () {});
    }

    function sms(el) {
        one('[data-cam-sms]').hidden = !el.checked;
        changed();
    }

    function smsCount(field) {
        var text = field.value || '';
        var len = Array.from(text).length;
        var gsm = GSM.test(text);
        var single = gsm ? 160 : 70;
        var multi = gsm ? 153 : 67;
        var pages = len === 0 ? 0 : (len <= single ? 1 : Math.ceil(len / multi));
        one('[data-cam-sms-count]').textContent = len + ' characters · ' + pages + (pages === 1 ? ' text' : ' texts');
    }

    function smsInput(field) {
        smsCount(field);
        state.dirty = true;
        one('[data-cam-saved]').textContent = 'Not saved';
    }

    function save(f) {
        var button = f.querySelector('[type="submit"]');
        HOD.busy(button, true);
        return call('save', formData(), { quiet: true }).then(function (res) {
            render(res.campaign);
            one('[data-cam-saved]').textContent = 'Saved';
            return true;
        }).catch(function (err) {
            HOD.toast(err.message, 'danger');
            if (err.response && err.response.code === 'STALE') load();
            return false;
        }).finally(function () { HOD.busy(button, false); });
    }

    function edit(button) {
        HOD.busy(button, true);
        call('email_doc', { id: state.c.id }).then(function (res) {
            var root = one('[data-cam-editor-root]');
            root.innerHTML = '';
            state.editor = window.HODEmail.mount(root, {
                doc: res.doc,
                storageKey: 'hod-email-campaign-' + state.c.id,
                savedAt: null
            });
            HOD.dialog.open('cam-editor', button);
        }).catch(function () {}).finally(function () { HOD.busy(button, false); });
    }

    function saveEmail(button) {
        var ed = state.editor;
        if (!ed) return;
        ed.opts.onSave = function (doc) {
            return call('save_email', { id: state.c.id, version: state.c.version, doc: doc }, { quiet: true }).then(function (res) {
                HOD.dialog.close('cam-editor');
                state.editor = null;
                render(res.campaign);
                HOD.toast(res.message || 'Email saved.', 'success');
                return true;
            }).catch(function (err) {
                ed.showErrors(err.response && err.response.errors ? err.response.errors : [err.message]);
                return false;
            });
        };
        ed.save(button);
    }

    function preview(button) {
        HOD.busy(button, true);
        call('preview', { id: state.c.id }).then(function (res) {
            one('[data-cam-frame]').src = res.url;
            HOD.dialog.open('cam-preview', button);
        }).catch(function () {}).finally(function () { HOD.busy(button, false); });
    }

    function width(button) {
        var frame = one('[data-cam-frame]');
        var narrow = button.getAttribute('data-width') === '375';
        frame.classList.toggle('max-w-[375px]', narrow);
        frame.classList.toggle('max-w-[640px]', !narrow);
        button.closest('[role="group"]').querySelectorAll('[data-action="cam.width"]').forEach(function (b) {
            b.setAttribute('aria-pressed', b === button ? 'true' : 'false');
        });
    }

    function test(button) {
        HOD.busy(button, true);
        call('test', { id: state.c.id }).then(function (res) {
            HOD.toast(res.message, 'success');
        }).catch(function () {}).finally(function () { HOD.busy(button, false); });
    }

    function sendOpen(button) {
        var go = state.dirty ? save(form()) : Promise.resolve(true);
        go.then(function (ok) {
            if (!ok) return;
            if ((state.c.problems || []).length) {
                HOD.toast(state.c.problems[0], 'warning');
                return;
            }
            one('[data-cam-send-line]').textContent = one('[data-cam-count]').textContent;
            HOD.dialog.open('cam-send', button);
        });
    }

    function whenChange(el) {
        var later = el.value === 'later' && el.checked;
        one('[data-cam-later]').hidden = !later;
        one('[data-cam-send-btn] span').textContent = later ? 'Schedule' : 'Send';
    }

    function send(button) {
        var later = document.querySelector('input[name="cam-when"]:checked').value === 'later';
        var at = later ? document.getElementById('cam-at').value : '';
        if (later && !at) {
            HOD.toast('Pick a date and time.', 'warning');
            return;
        }
        HOD.busy(button, true);
        call('send', { id: state.c.id, version: state.c.version, at: at }).then(function (res) {
            HOD.dialog.close('cam-send');
            render(res.campaign);
            HOD.toast(res.message, 'success');
        }).catch(function () {}).finally(function () { HOD.busy(button, false); });
    }

    function unschedule(button) {
        HOD.busy(button, true);
        call('unschedule', { id: state.c.id }).then(function (res) {
            render(res.campaign);
            HOD.toast(res.message, 'success');
        }).catch(function () {}).finally(function () { HOD.busy(button, false); });
    }

    function cancel(button) {
        HOD.confirm({ title: 'Stop this campaign?', message: 'Nothing more will go out. What has left stays sent.', confirmLabel: 'Stop', tone: 'danger' }).then(function (yes) {
            if (!yes) return;
            HOD.busy(button, true);
            call('cancel', { id: state.c.id }).then(function (res) {
                render(res.campaign);
                HOD.toast(res.message, 'success');
            }).catch(function () {}).finally(function () { HOD.busy(button, false); });
        });
    }

    function copy(button) {
        HOD.busy(button, true);
        call('copy', { id: state.c.id }).then(function (res) {
            window.location.href = '/modules/campaigns/index.php?id=' + res.id;
        }).catch(function () { HOD.busy(button, false); });
    }

    function remove(button) {
        HOD.confirm({ title: 'Delete this campaign?', message: 'It has not gone out. This cannot be undone.', confirmLabel: 'Delete', tone: 'danger' }).then(function (yes) {
            if (!yes) return;
            HOD.busy(button, true);
            call('delete', { id: state.c.id }).then(function () {
                window.location.href = '/modules/campaigns/index.php';
            }).catch(function () { HOD.busy(button, false); });
        });
    }

    /* ---------- the report ---------- */

    function tile(label, value) {
        return '<div class="ui-card ui-stat"><span class="ui-stat__label"><span>' + HOD.esc(label) + '</span></span>' +
            '<span class="ui-stat__value">' + HOD.esc(String(value)) + '</span></div>';
    }

    function report(button) {
        if (button) HOD.busy(button, true);
        call('report', { id: state.c.id }, { quiet: !button }).then(function (res) {
            var e = res.report.email;
            var texts = 0;
            var sms = res.report.sms || {};
            Object.keys(sms).forEach(function (k) { texts += sms[k]; });
            one('[data-cam-tiles]').innerHTML =
                tile('Sent', e.sent) + tile('Waiting', e.waiting) + tile('Opened', e.opened) +
                tile('Clicked', e.clicked) + tile('Bounced', e.bounced) + tile('Unsubscribed', e.unsubscribed) +
                (texts ? tile('Texts sent', sms.sent || 0) : '');
            var links = res.report.links || [];
            one('[data-cam-links]').innerHTML = links.length
                ? '<div class="ui-table-wrap"><table class="ui-table ui-table--compact"><thead><tr><th scope="col">Link</th><th scope="col" class="text-right">People</th></tr></thead><tbody>' +
                  links.map(function (l) {
                      return '<tr><td class="break-all">' + HOD.esc(l.url) + '</td><td class="text-right tabular-nums">' + l.people + '</td></tr>';
                  }).join('') + '</tbody></table></div>'
                : '';
            state.people = res.report.people || [];
            people();
        }).catch(function () {}).finally(function () { if (button) HOD.busy(button, false); });
    }

    function people() {
        var q = (one('#cam-filter').value || '').trim().toLowerCase();
        var rows = state.people.filter(function (p) {
            return !q || p.name.toLowerCase().indexOf(q) !== -1 || p.result.toLowerCase().indexOf(q) !== -1;
        });
        var by = { email: 'Email', sms: 'Text', none: '—' };
        one('[data-cam-people]').innerHTML = rows.length
            ? '<div class="ui-table-wrap mt-2"><table class="ui-table ui-table--compact"><thead><tr><th scope="col">Person</th><th scope="col">By</th><th scope="col">Result</th></tr></thead><tbody>' +
              rows.slice(0, 500).map(function (p) {
                  return '<tr><td>' + HOD.esc(p.name) + '</td><td>' + HOD.esc(by[p.channel] || p.channel) + '</td><td>' + HOD.esc(p.result) + '</td></tr>';
              }).join('') + '</tbody></table></div>' +
              (rows.length > 500 ? '<p class="ui-meta mt-2">Showing 500 of ' + rows.length + '. Search to narrow it.</p>' : '')
            : '<p class="ui-meta mt-2">Nobody matches.</p>';
    }

    HOD.ready(function () {
        cfg = HOD.data('campaigns-config') || {};
        if (document.querySelector('[data-campaigns]')) {
            HOD.actions.on('cam.create', create);
            HOD.actions.on('cam.settings', saveSettings);
        }
        if (!document.querySelector('[data-campaign]') || !cfg.id) return;
        HOD.actions.on('cam.save', save);
        HOD.actions.on('cam.kind', kind);
        HOD.actions.on('cam.changed', changed);
        HOD.actions.on('cam.from', from);
        HOD.actions.on('cam.sms', sms);
        HOD.actions.on('cam.sms-count', smsInput);
        HOD.actions.on('cam.edit', edit);
        HOD.actions.on('cam.save-email', saveEmail);
        HOD.actions.on('cam.preview', preview);
        HOD.actions.on('cam.width', width);
        HOD.actions.on('cam.test', test);
        HOD.actions.on('cam.send-open', sendOpen);
        HOD.actions.on('cam.when', whenChange);
        HOD.actions.on('cam.send', send);
        HOD.actions.on('cam.unschedule', unschedule);
        HOD.actions.on('cam.cancel', cancel);
        HOD.actions.on('cam.copy', copy);
        HOD.actions.on('cam.delete', remove);
        HOD.actions.on('cam.report', report);
        HOD.actions.on('cam.filter', people);
        form().addEventListener('input', function (e) {
            if (e.target.name === 'name' || e.target.name === 'reply_to') {
                state.dirty = true;
                one('[data-cam-saved]').textContent = 'Not saved';
            }
        });
        load().catch(function () {});
    });
})();
