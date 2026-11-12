// /assets/ui/js/pages/sms-studio.js
// SMS Studio (modules/sms_studio/index.php): compose and send, campaigns with
// live progress, history with every status change, blocked numbers, and the
// BulkSMS settings. Every request goes to /api/sms_api.php with the module's
// own token (_csrf); the shapes of the requests are the ones the API has
// always taken. All times are Lagos (WAT).
//
// The clean-up and counting of the text mirror includes/sms_functions.php, so
// the preview, the units and the cost match what is sent.
(function () {
    'use strict';

    var HOD = window.HOD;
    var API = '/api/sms_api.php';
    var cfg = {};
    var esc = function (v) { return HOD.esc(v == null ? '' : String(v)); };
    var $ = function (sel, root) { return (root || document).querySelector(sel); };
    var $$ = function (sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); };

    /* ------------------------------------------------------------------ *
     * State
     * ------------------------------------------------------------------ */

    var recipients = [];                // contacts for the current filter
    var selected = new Map();           // phone -> recipient
    var lastHealth = null;
    var driving = false;
    var lockedUntil = 0;
    var composeToken = newToken();
    var currentTab = 'compose';
    var drawerStack = [];
    var timers = { fetch: null, analyse: null, hist: null, histPoll: null, health: null, camp: null };
    var hist = { page: 1, status: '', campaign: null, phone: '' };
    var campPage = 1;
    var recipSeq = 0;
    var webhookSuggestion = '';

    var ST = {
        queued: { label: 'Sending\u2026', tone: 'info' },
        sent: { label: 'Submitted · awaiting report', tone: 'warning' },
        pending: { label: 'In transit (carrier)', tone: 'warning' },
        delivered: { label: 'Delivered', tone: 'success' },
        failed: { label: 'Failed', tone: 'danger' },
        blocked: { label: 'Blocked · not sent (₦0)', tone: 'neutral' },
        unknown: { label: 'Outcome unknown', tone: 'primary' }
    };
    var SRC = { studio: 'SMS Studio (a user)', worker: 'Background sender', bulksms: 'BulkSMS reply', webhook: 'Delivery report · webhook', poll: 'Delivery report · check', system: 'System' };
    var KNOWN_TOKENS = ['first_name', 'last_name', 'full_name', 'guest_name', 'name', 'phone', 'email', 'event_title', 'blessing_ref'];
    var CAMP_STATE = { queued: ['Queued', 'info'], sending: ['Sending', 'warning'], sent: ['Finished', 'success'], cancelled: ['Cancelled', 'neutral'] };

    /* ------------------------------------------------------------------ *
     * Helpers
     * ------------------------------------------------------------------ */

    // raw: the API answers {status:'error'|'confirm'\u2026} as data; only a failed
    // request rejects (and HOD.api says so unless quiet).
    function call(action, data, opts) {
        opts = opts || {};
        return HOD.api(API, { data: Object.assign({ action: action, _csrf: cfg.csrf || '' }, data || {}), raw: true, quiet: opts.quiet });
    }
    function toast(msg, type) {
        HOD.toast(String(msg || ''), type === 'error' ? 'danger' : (type === 'info' ? 'info' : (type === 'warning' ? 'warning' : 'success')));
    }
    function newToken() { return 'c' + Date.now().toString(36) + Math.random().toString(36).slice(2, 10); }
    function naira(v) { return v == null || v === '' ? '\u2014' : '₦' + Number(v).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    function tLabel(t) { return t && t.label ? t.label : '\u2014'; }
    function ago(t) {
        if (!t || !t.iso) return '';
        var s = Math.round((Date.now() - new Date(t.iso).getTime()) / 1000);
        if (s < 60) return 'just now';
        if (s < 3600) return Math.floor(s / 60) + ' min ago';
        if (s < 86400) return Math.floor(s / 3600) + ' h ago';
        return Math.floor(s / 86400) + ' d ago';
    }
    function secsLabel(s) {
        if (s == null) return 'never';
        if (s < 60) return s + 's ago';
        if (s < 3600) return Math.floor(s / 60) + ' min ago';
        if (s < 86400) return Math.floor(s / 3600) + ' h ago';
        return Math.floor(s / 86400) + ' days ago';
    }
    function badge(text, tone, extra) {
        return '<span class="ui-badge' + (tone && tone !== 'neutral' ? ' ui-badge--' + tone : '') + (extra ? ' ' + extra : '') + '">' + esc(text) + '</span>';
    }
    function stChip(status, messageId) {
        var s = ST[status] || { label: status || '?', tone: 'neutral' };
        return badge(status === 'failed' && messageId === null ? 'Refused · not sent (₦0)' : s.label, s.tone, 'whitespace-nowrap');
    }
    function note(tone, html) {
        var cls = { danger: 'border-danger bg-danger-soft', warning: 'border-warning bg-warning-soft', success: 'border-success bg-success-soft', info: 'border-info bg-info-soft' }[tone] || 'border-line bg-surface-sunken';
        return '<div class="rounded-ui-lg border px-3 py-2 text-sm text-ink ' + cls + '">' + html + '</div>';
    }
    function kv(k, v) {
        return '<div class="rounded-ui-lg bg-surface-sunken px-3 py-2"><p class="ui-meta">' + esc(k) + '</p><p class="break-words font-medium text-ink">' + esc(v) + '</p></div>';
    }
    function empty(text) { return '<li class="ui-list__item justify-center ui-meta">' + esc(text) + '</li>'; }
    function cap(s) { s = String(s || ''); return s.charAt(0).toUpperCase() + s.slice(1); }

    /* ---------- the text: clean-up, pages, merge (as the server does) ---------- */

    var GSM_CLEAN = {
        '\u2018': "'", '\u2019': "'", '\u201a': "'", '\u201b': "'", '\u2032': "'", '\u00b4': "'",
        '\u201c': '"', '\u201d': '"', '\u201e': '"', '\u201f': '"', '\u2033': '"',
        '\u2010': '-', '\u2011': '-', '\u2012': '-', '\u2013': '-', '\u2014': '-', '\u2015': '-', '\u2212': '-',
        '\u2026': '...', '\u2022': '-', '\u00a0': ' ', '\u2002': ' ', '\u2003': ' ', '\u2009': ' ', '\u202f': ' ',
        '\u200b': '', '\ufeff': '', '\u00ad': ''
    };
    var GSM_CLEAN_RE = /[\u2018\u2019\u201a\u201b\u2032\u00b4\u201c\u201d\u201e\u201f\u2033\u2010-\u2015\u2212\u2026\u2022\u00a0\u2002\u2003\u2009\u202f\u200b\ufeff\u00ad]/g;
    function gsmClean(t) {
        return (t || '').replace(/\r\n/g, '\n').replace(/\t/g, ' ').replace(/[\u2028\u2029]/g, '\n')
            .replace(GSM_CLEAN_RE, function (c) { return GSM_CLEAN[c] != null ? GSM_CLEAN[c] : c; });
    }
    var GSM7 = '@£$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !"#¤%&\'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà';
    var GSM7_EXT = '^{}\\[~]|€';
    function smsPages(text) {
        var len = 0;
        var bad = new Set();
        Array.from(text).forEach(function (ch) {
            if (GSM7.indexOf(ch) !== -1) len += 1;
            else if (GSM7_EXT.indexOf(ch) !== -1) len += 2;
            else bad.add(ch);
        });
        if (bad.size) {
            var u = text.length;
            return { enc: 'Unicode', chars: u, pages: u <= 70 ? 1 : Math.ceil(u / 67), bad: Array.from(bad) };
        }
        return { enc: 'GSM-7', chars: len, pages: len <= 160 ? 1 : Math.ceil(len / 153), bad: [] };
    }
    function cleanName(s) { return (s || '').toString().replace(/\s+/g, ' ').trim(); }
    function render(tpl, d) {
        var first = cleanName(d.first_name);
        var last = cleanName(d.last_name);
        var full = cleanName(d.guest_name);
        if (full === '' && first !== '') full = (first + ' ' + last).trim();
        else if (full === '') full = cleanName(d.name);
        var map = {
            first_name: first, last_name: last, full_name: (first + ' ' + last).trim(), guest_name: full ? full.split(' ')[0] : '',
            name: full || cleanName(d.name), phone: (d.phone || '').trim(), email: (d.email || '').trim(),
            event_title: (d.event_title || '').trim(), blessing_ref: (d.blessing_ref || '').trim()
        };
        return gsmClean((tpl || '').replace(/{{([a-z_]+)}}/g, function (m, k) { return Object.prototype.hasOwnProperty.call(map, k) ? map[k] : m; })).trim();
    }
    var SAMPLE = { first_name: 'Ada', last_name: 'Example', name: 'Ada Example', phone: '2348031234567', email: 'ada@example.com', event_title: 'Sunday Service', blessing_ref: 'Numbers 6:24' };
    // The sender name the server writes at the start of every text (Settings),
    // mirrored so the preview, the units and the cost match (sms_sign_body()).
    function signature() { return lastHealth && lastHealth.signature ? String(lastHealth.signature) : ''; }
    function sign(text) {
        var s = signature();
        if (!s || !text) return text;
        var name = s.replace(/[:\s]+$/, '').replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        return new RegExp('^' + name + '(?![A-Za-z0-9])', 'i').test(text) ? text : s + text;
    }
    function unitPrice() { var p = lastHealth ? Number(lastHealth.unit_price) : 0; return p > 0 ? p : 6.49; }

    /* ------------------------------------------------------------------ *
     * Tabs
     * ------------------------------------------------------------------ */

    function onTab(tab) {
        currentTab = tab;
        if (tab === 'compose') { if (!recipients.length) fetchRecipients(); loadTemplates(); }
        if (tab === 'campaigns') loadCampaigns(1);
        if (tab === 'history') { loadHistory(1); startHistPoll(); } else stopHistPoll();
        if (tab === 'blocked') loadBlocked();
        if (tab === 'settings') { loadSettingsStatus(); renderChecklist(); }
    }
    // Selecting a tab fires 'ui:tab' (ui.js), which runs onTab: never twice.
    function goTab(tab) {
        var list = $('[data-ui-tabs]');
        if (!(list && HOD.tabs && HOD.tabs.select(list, tab))) onTab(tab);
    }

    /* ------------------------------------------------------------------ *
     * Health: can the whole pipeline deliver right now?
     * ------------------------------------------------------------------ */

    function loadHealth() {
        call('health', {}, { quiet: true }).then(function (res) {
            if (res.status !== 'success') return;
            lastHealth = res.health || {};
            lastHealth.my_phone = res.my_phone;
            var mine = $('[data-sms-my-phone]');
            if (mine) mine.textContent = res.my_phone || 'none on your profile';
            renderHealth();
            if (currentTab === 'settings') renderChecklist();
            var h = lastHealth;
            // While the cron job is down, keep sending from whichever tab is open.
            if (h.queued > 0 && h.configured && !(h.cron_age_sec != null && h.cron_age_sec <= 180)) driveQueue(false);
        }).catch(function () {});
    }

    function pill(tone, text, info) {
        return '<span class="ui-badge' + (tone ? ' ui-badge--' + tone : '') + '"' + (info ? ' title="' + esc(info) + '"' : '') + '>' + esc(text) + '</span>';
    }

    function renderHealth() {
        var h = lastHealth;
        if (!h) return;
        var out = '';
        if (!h.vault_ok) out += pill('danger', 'SMS_VAULT_KEY missing in .env: settings cannot be read');
        else if (h.decrypt_failed && h.decrypt_failed.length) out += pill('danger', 'Saved BulkSMS details cannot be read: enter them again in Settings');
        else if (!h.configured) out += pill('warning', 'BulkSMS not connected: open Settings');
        else out += pill('success', 'BulkSMS connected');
        if (h.vault_ok) {
            if (h.schema_ready === false) out += pill('warning', 'Database update pending: run db/migrate.php');
            else if (h.schema_latest === false) out += pill('warning', 'Database update pending: run db/migrate.php', 'Until it runs, a refused text is not retried and an empty wallet is not waited for.');
            var age = h.cron_age_sec;
            var seen = h.cron_seen_age_sec;
            var firing = seen != null && seen <= 180;
            if (age == null && !firing) out += pill('warning', 'Background sender never ran: add the cron job', 'Until it runs, campaigns are sent while this page is open.');
            else if ((age == null || age > 180) && firing) out += pill('danger', 'Background sender fails · last try ' + secsLabel(seen) + (h.last_message ? ' · ' + h.last_message : ''), 'The cron job runs every minute but each run fails or finds another run holding the lock. Read includes/error_log and the cron log. Campaigns are sent while this page is open meanwhile.');
            else if (age == null || age > 180) out += pill('danger', 'Background sender stopped · last run ' + secsLabel(age), 'The cron job is not firing (cPanel → Cron Jobs). Campaigns are sent while this page is open until it is fixed.');
            else out += pill('success', 'Background sender running · ' + secsLabel(age));
            if (h.queued > 0) {
                out += pill('info', h.queued + ' waiting to send' + (h.oldest_queued_at_t ? ' · since ' + h.oldest_queued_at_t.label : ''));
                if (h.last_message && /^(Paused|Waiting)/.test(h.last_message)) out += pill('warning', h.last_message.replace(/\s+\d+ sent, .*$/, ''), 'The background sender stopped on purpose. It tries again by itself.');
                if ((age == null || age > 180) && !driving) out += '<button type="button" class="ui-btn ui-btn--primary ui-btn--sm" data-action="sms.drive">Send queued now</button>';
                else if (driving) out += pill('info', 'Sending from this page\u2026');
            }
            if (h.webhook_secret_set && h.webhook_url_set) out += pill('success', 'Delivery webhook on' + (h.last_webhook_at_t ? ' · last report ' + ago(h.last_webhook_at_t) : ' · no report yet'));
            else out += pill('', 'Delivery reports by polling');
        }
        $('[data-sms-health]').innerHTML = out;
    }

    // Send from this page while the cron job is down (one run at a time).
    function driveQueue(loud) {
        if (driving) return;
        if (Date.now() < lockedUntil) {
            if (loud) toast('Another sender run holds the lock. Waiting.', 'info');
            return;
        }
        driving = true;
        if (loud) toast('Sending queued texts from this page. Keep it open.', 'info');
        call('process_queue', {}, { quiet: true }).then(function (res) {
            driving = false;
            if (res.status === 'success' && res.result && res.result.locked) {
                lockedUntil = Date.now() + 20000;
                if (loud) toast('Another sender run holds the lock. Waiting.', 'info');
            } else if (res.status === 'success' && loud) {
                toast(res.message, 'info');
            }
            loadHealth();
            if (res.status === 'success' && res.result && res.result.remaining > 0 && !res.result.locked && loud) driveQueue(true);
        }).catch(function () {
            driving = false;
            window.setTimeout(loadHealth, 8000);   // one failed request must not end the page-driven send
        });
    }

    /* ------------------------------------------------------------------ *
     * Audience
     * ------------------------------------------------------------------ */

    function currentAudience() { var r = $('input[name="audience"]:checked'); return r ? r.value : 'users'; }
    function currentStatuses() { return $$('input[name="statuses[]"]:checked').map(function (c) { return c.value; }); }
    function regScope() { var r = $('input[name="regscope"]:checked'); return r ? r.value : 'all'; }
    function currentEventId() {
        var aud = currentAudience();
        if (aud === 'checkins') return $('#sms-checkin-event').value;
        if (aud === 'registrations' && regScope() === 'event') return $('#sms-reg-event').value;
        return '';
    }

    function onAudience(el) {
        var aud = currentAudience();
        $$('[data-sms-filter]').forEach(function (f) { f.hidden = f.getAttribute('data-sms-filter') !== aud; });
        var regField = $('#sms-reg-event').closest('.ui-field');
        regField.classList.toggle('hidden', regScope() !== 'event');
        if (el && el.name === 'audience' && el.value === 'assimilation' && !aw.rule) openAssim();
        fetchRecipients();
    }

    function fetchRecipients() {
        var aud = currentAudience();
        var list = $('[data-sms-contacts]');
        var info = $('[data-sms-audience-info]');
        var data = { audience: aud, search: $('#sms-search').value || '', event_id: currentEventId() };
        if (aud === 'users') data.statuses = currentStatuses();
        var seq = ++recipSeq;
        if (aud === 'assimilation') {
            if (!aw.rule) {
                recipients = [];
                info.textContent = '';
                list.innerHTML = empty('Build a list to see who it catches.');
                return;
            }
            data.rule = JSON.stringify(aw.rule);
        }
        list.innerHTML = empty('Loading\u2026');
        call('fetch_recipients', data).then(function (res) {
            if (seq !== recipSeq) return;
            if (res.status !== 'success') { list.innerHTML = empty(res.message || 'Could not load the contacts.'); return; }
            recipients = res.data || [];
            var line = res.count + ' contact(s) with a valid Nigerian mobile.';
            if (res.invalid_count) line += ' ' + res.invalid_count + ' invalid number(s) left out.';
            if (res.shared_phones) line += ' ' + res.shared_phones + ' shared number(s) get one text each.';
            if (res.excluded_count) line += aud === 'assimilation'
                ? ' ' + res.excluded_count + ' left out: a follow-up recorded "no contact" or "not interested".'
                : ' ' + res.excluded_count + ' left out: opted out or marked invalid.';
            if (res.no_phone_count) line += ' ' + res.no_phone_count + ' on the list have no phone.';
            if (res.truncated) line += ' Showing the first ' + res.cap + ': narrow the filter to reach the rest.';
            info.textContent = line;
            info.classList.toggle('text-warning', !!res.truncated);
            renderContacts();
        }).catch(function () { if (seq === recipSeq) list.innerHTML = empty('Could not load the contacts.'); });
    }

    function contactSub(r) {
        if (r.sources) return r.sources.join(' · ');
        if (r.since_words) return [(r.status_label || '').replace(/_/g, ' '), r.since_words === 'never recorded' ? 'No attendance on record' : 'Last seen ' + r.since_words].filter(Boolean).join(' · ');
        if (r.source === 'checkin') return 'Check-in · ' + (r.blessing_ref || 'no verse');
        if (r.source === 'user') return r.status_label ? r.status_label.replace(/_/g, ' ') : 'Congregation';
        return r.event_title || 'Registration';
    }

    function renderContacts() {
        var list = $('[data-sms-contacts]');
        if (!recipients.length) { list.innerHTML = empty('No contacts found. Change the filters or search.'); return; }
        list.innerHTML = recipients.map(function (r, i) {
            var on = selected.has(r.phone);
            return '<li class="ui-list__item p-0"><label class="ui-check w-full px-3 py-2.5">'
                + '<input type="checkbox" data-action-change="sms.pick" data-i="' + i + '"' + (on ? ' checked' : '') + '>'
                + '<span class="min-w-0"><span class="block truncate font-medium text-ink">' + esc(r.name || 'Unnamed') + '</span>'
                + '<span class="ui-check__hint">' + esc(r.phone) + ' · ' + esc(contactSub(r)) + '</span></span></label></li>';
        }).join('');
    }

    function afterSelection() { renderSelected(); scheduleAnalyse(); }

    function renderSelected() {
        var arr = Array.from(selected.values());
        $('[data-sms-selected-count]').textContent = arr.length + ' selected';
        $('[data-sms-selected-summary]').textContent = arr.length + ' recipient' + (arr.length === 1 ? '' : 's');
        var box = $('[data-sms-selected]');
        if (!arr.length) { box.innerHTML = '<li class="ui-meta text-center">Nobody selected yet.</li>'; return; }
        box.innerHTML = arr.slice(0, 200).map(function (r) {
            return '<li class="flex items-center gap-2 rounded-ui-lg border border-line bg-surface-sunken px-3 py-2"><span class="min-w-0 flex-1">'
                + '<span class="block truncate font-medium text-ink">' + esc(r.name || 'Unnamed') + '</span><span class="ui-meta">' + esc(r.phone) + '</span></span>'
                + '<button type="button" class="ui-btn ui-btn--ghost ui-btn--icon ui-btn--sm" data-action="sms.unpick" data-phone="' + esc(r.phone) + '" aria-label="Remove ' + esc(r.name || r.phone) + '">' + HOD.icon('x') + '</button></li>';
        }).join('') + (arr.length > 200 ? '<li class="ui-meta text-center">\u2026and ' + (arr.length - 200) + ' more</li>' : '');
    }

    /* ------------------------------------------------------------------ *
     * Assimilation list builder (the same rule as Assimilation's)
     * ------------------------------------------------------------------ */

    var AW_AWAY = { nos: { max: 1, win: 1, unit: 'months' }, slip: { max: 3, win: 2, unit: 'months' }, gone: { max: 5, win: 3, unit: 'months' } };
    var AW_TITLES = ['How long away?', 'Who is on it?', 'Fine-tune it', 'Your list'];
    var aw = { step: 0, away: 'slip', custom: { max: 4, win: 4, unit: 'months' }, who: new Set(), seq: 0, summary: '', rule: null };

    function awRule() {
        var a = aw.away === 'custom' ? aw.custom : AW_AWAY[aw.away];
        return {
            max_services: a.max, window_value: a.win, window_unit: a.unit, spiritual_status: Array.from(aw.who),
            department_id: +($('#sms-aw-dept') || {}).value || 0, region_id: +($('#sms-aw-region') || {}).value || 0,
            gender: ($('#sms-aw-gender') || {}).value || '', age_band: ($('#sms-aw-age') || {}).value || '',
            ever_attended: $('#sms-aw-ever') && $('#sms-aw-ever').checked ? 1 : 0
        };
    }
    function awPaint() {
        $$('[data-action="sms.aw-away"]').forEach(function (b) {
            var on = b.getAttribute('data-away') === aw.away;
            b.setAttribute('aria-checked', on ? 'true' : 'false');
            b.classList.toggle('ui-btn--soft', on);
            b.classList.toggle('ui-btn--secondary', !on);
        });
        $('[data-sms-aw-custom]').hidden = aw.away !== 'custom';
        $('[data-sms-aw-max]').textContent = aw.custom.max;
        $('[data-sms-aw-win]').textContent = aw.custom.win;
        $('#sms-aw-unit').value = aw.custom.unit;
        var every = $('[data-action="sms.aw-everyone"]');
        every.setAttribute('aria-pressed', aw.who.size ? 'false' : 'true');
        every.classList.toggle('ui-btn--soft', !aw.who.size);
        every.classList.toggle('ui-btn--secondary', !!aw.who.size);
        $$('[data-action="sms.aw-status"]').forEach(function (b) {
            var on = aw.who.has(b.getAttribute('data-status'));
            b.setAttribute('aria-pressed', on ? 'true' : 'false');
            b.classList.toggle('ui-btn--soft', on);
            b.classList.toggle('ui-btn--secondary', !on);
        });
    }
    function awShow(step) {
        aw.step = Math.max(0, Math.min(3, step));
        $$('[data-sms-aw]').forEach(function (s) { s.hidden = +s.getAttribute('data-sms-aw') !== aw.step; });
        $('[data-sms-aw-title]').textContent = AW_TITLES[aw.step];
        $('[data-sms-aw-step]').textContent = (aw.step + 1) + '/4';
        $('[data-sms-aw-back]').hidden = aw.step === 0;
        var next = $('[data-sms-aw-next]');
        next.querySelector('span').textContent = aw.step === 3 ? 'Use this list' : 'Continue';
        next.disabled = false;
        if (aw.step === 3) awCount();
    }
    function awCount() {
        var seq = ++aw.seq;
        var next = $('[data-sms-aw-next]');
        $('[data-sms-aw-count]').textContent = '\u2026';
        $('[data-sms-aw-summary]').textContent = '';
        $('[data-sms-aw-breakdown]').innerHTML = '';
        next.disabled = true;
        call('assim_count', { rule: JSON.stringify(awRule()) }).then(function (res) {
            if (seq !== aw.seq) return;
            if (res.status !== 'success') {
                $('[data-sms-aw-count]').textContent = '\u2014';
                $('[data-sms-aw-summary]').textContent = res.message || 'Could not count this list.';
                return;
            }
            var d = res.data;
            aw.summary = d.summary || '';
            $('[data-sms-aw-count]').textContent = d.reachable;
            $('[data-sms-aw-summary]').textContent = cap(aw.summary);
            var rows = [];
            if (d.no_phone) rows.push(d.no_phone + ' have no phone number');
            if (d.invalid) rows.push(d.invalid + ' have a number that is not a Nigerian mobile');
            if (d.excluded) rows.push(d.excluded + ' left out: a follow-up recorded "no contact" or "not interested"');
            if (d.shared) rows.push(d.shared + ' share a number (one text each)');
            $('[data-sms-aw-breakdown]').innerHTML = rows.map(function (t) { return '<li>' + esc(t) + '</li>'; }).join('');
            next.disabled = !d.reachable;
        }).catch(function () {});
    }
    function openAssim() {
        if (!$('#sms-assim')) return;
        awPaint();
        awShow(0);
        HOD.dialog.open('sms-assim');
    }
    function awNext() {
        if (aw.step < 3) { awShow(aw.step + 1); return; }
        aw.rule = awRule();
        HOD.dialog.close('sms-assim');
        $('[data-sms-assim-summary]').textContent = cap(aw.summary);
        var build = $('[data-sms-assim-build]');
        if (build) build.querySelector('span').textContent = 'Change list';
        fetchRecipients();
    }

    /* ------------------------------------------------------------------ *
     * Message: live preview, units and warnings
     * ------------------------------------------------------------------ */

    function scheduleAnalyse() { window.clearTimeout(timers.analyse); timers.analyse = window.setTimeout(analyse, 150); }

    function analyse() {
        var tpl = $('#sms-body').value || '';
        var arr = Array.from(selected.values());
        var first = arr[0] || SAMPLE;
        var preview = sign(render(tpl, first));
        $('[data-sms-preview-who]').textContent = arr[0] ? (arr[0].name || arr[0].phone) : 'a sample contact';
        $('[data-sms-preview]').textContent = preview || 'Start typing to see it.';

        var p = smsPages(sign(gsmClean(tpl)));
        var sig = signature();
        $('[data-sms-chars]').textContent = tpl.length + ' characters · ' + p.enc + (sig ? ' · "' + sig.trim() + '" starts every text (+' + sig.length + ')' : '');
        var units = 0;
        var maxPages = 0;
        var pushed = 0;
        var missing = {};
        var used = Array.from(new Set((tpl.match(/{{([a-z_]+)}}/g) || []).map(function (t) { return t.slice(2, -2); })));
        arr.forEach(function (r) {
            var bare = render(tpl, r);
            var pg = smsPages(sign(bare)).pages;
            units += pg;
            if (pg > maxPages) maxPages = pg;
            if (sig && pg > smsPages(bare).pages) pushed++;
            used.forEach(function (k) {
                var v = k === 'full_name' ? (r.first_name || '') : (k === 'guest_name' ? (r.guest_name || r.first_name || r.name || '') : (r[k] || ''));
                if (!String(v).trim()) missing[k] = (missing[k] || 0) + 1;
            });
        });
        $('[data-sms-units]').textContent = arr.length
            ? units + ' unit(s) for ' + arr.length + ' recipient(s)' + (maxPages > 1 ? ' · longest ' + maxPages + ' pages' : '')
            : p.pages + ' unit(s) per recipient';

        var warn = [];
        var unknown = used.filter(function (k) { return KNOWN_TOKENS.indexOf(k) === -1; });
        if (unknown.length) warn.push(['danger', 'Unknown field ' + unknown.map(function (k) { return '{{' + k + '}}'; }).join(', ') + ' will be sent as typed. Check the spelling.']);
        if (/{{|}}/.test(tpl.replace(/{{[a-z_]+}}/g, ''))) warn.push(['danger', 'Broken field: braces that do not form {{field}}.']);
        Object.keys(missing).forEach(function (k) {
            if (KNOWN_TOKENS.indexOf(k) !== -1) warn.push(['warning', missing[k] + ' of ' + arr.length + ' have no ' + k.replace('_', ' ') + ': {{' + k + '}} will be blank for them.']);
        });
        var raw = smsPages(tpl);
        var fixButton = false;
        if (raw.enc === 'Unicode') {
            var fixable = raw.bad.filter(function (c) { return GSM_CLEAN[c] != null; });
            var stuck = raw.bad.filter(function (c) { return GSM_CLEAN[c] == null; });
            if (stuck.length) warn.push(['warning', 'These characters force Unicode (70 a text, not 160): ' + stuck.map(function (c) { return '"' + c + '"'; }).join(' ')]);
            else if (fixable.length) { warn.push(['info', 'Curly quotes and long dashes are made plain when sent, so the text stays cheap.']); fixButton = true; }
        }
        if (maxPages > 3) warn.push(['warning', 'Some texts run to ' + maxPages + ' pages; every page is billed.']);
        if (pushed) warn.push(['warning', 'The sender name pushes ' + pushed + ' of ' + arr.length + ' text(s) onto an extra page. Cut ' + sig.length + ' characters to stay on one.']);
        $('[data-sms-warnings]').innerHTML = warn.map(function (w, i) {
            return note(w[0], esc(w[1]) + (fixButton && w[0] === 'info' && i === warn.length - 1 ? ' <button type="button" class="ui-link" data-action="sms.fix-chars">Make them plain now</button>' : ''));
        }).join('');
        return { units: units, maxPages: maxPages, warn: warn, unknown: unknown };
    }

    function insertToken(btn) {
        var token = btn.getAttribute('data-token');
        var ta = $('#sms-body');
        var start = typeof ta.selectionStart === 'number' ? ta.selectionStart : ta.value.length;
        var end = typeof ta.selectionEnd === 'number' ? ta.selectionEnd : ta.value.length;
        ta.value = ta.value.slice(0, start) + token + ta.value.slice(end);
        ta.focus();
        ta.selectionStart = ta.selectionEnd = start + token.length;
        scheduleAnalyse();
    }

    /* ---------- templates ---------- */

    function loadTemplates() {
        call('list_templates', {}, { quiet: true }).then(function (res) {
            if (res.status !== 'success' || !res.data) return;
            $('[data-sms-templates]').innerHTML = '<option value="">Choose a template</option>'
                + res.data.map(function (t) { return '<option value="' + (+t.id) + '">' + esc(t.name) + '</option>'; }).join('');
        }).catch(function () {});
    }
    function tplLoad() {
        var id = $('[data-sms-templates]').value;
        if (!id) { toast('Choose a template first.', 'error'); return; }
        call('get_template', { id: id }).then(function (res) {
            if (res.status !== 'success') { toast(res.message, 'error'); return; }
            $('#sms-body').value = res.template.body_template || '';
            scheduleAnalyse();
            toast('Template loaded.');
        }).catch(function () {});
    }
    function tplSave(btn) {
        var name = $('#sms-template-name').value.trim();
        var body = $('#sms-body').value;
        if (!name) { toast('Give the template a name.', 'error'); return; }
        if (!body.trim()) { toast('Write a text first.', 'error'); return; }
        HOD.busy(btn, true);
        call('save_template', { name: name, body_template: body }).then(function (res) {
            toast(res.message, res.status);
            if (res.status === 'success') { $('#sms-template-name').value = ''; loadTemplates(); }
        }).catch(function () {}).finally(function () { HOD.busy(btn, false); });
    }
    function tplDelete() {
        var id = $('[data-sms-templates]').value;
        if (!id) { toast('Choose a template first.', 'error'); return; }
        HOD.confirm({ title: 'Delete this template?', confirmLabel: 'Delete', tone: 'danger' }).then(function (yes) {
            if (!yes) return;
            call('delete_template', { id: id }).then(function (res) { toast(res.message, res.status); if (res.status === 'success') loadTemplates(); }).catch(function () {});
        });
    }

    /* ------------------------------------------------------------------ *
     * Send
     * ------------------------------------------------------------------ */

    function sendTest(btn) {
        var tpl = $('#sms-body').value;
        if (!tpl.trim()) { toast('Write a text first.', 'error'); return; }
        var a = analyse();
        var phone = $('#sms-test-phone').value.trim();
        var ask = a.unknown.length
            ? HOD.confirm({ title: 'Send with an unknown field?', message: 'It will be sent as typed.', confirmLabel: 'Send anyway' })
            : Promise.resolve(true);
        ask.then(function (ok) {
            if (!ok) return false;
            return HOD.confirm({
                title: 'Send a real test?',
                message: 'A billed text to ' + (phone || 'your own number') + ' (about ₦' + unitPrice().toFixed(2) + ' a page).',
                confirmLabel: 'Send test'
            });
        }).then(function (yes) {
            if (!yes) return;
            HOD.busy(btn, true);
            call('send_test', { template: tpl, audience: currentAudience(), event_id: currentEventId(), test_phone: phone }).then(function (res) {
                toast(res.message, res.status);
                if (res.log_id) window.setTimeout(function () { openMessage(res.log_id); }, 400);
            }).catch(function () {}).finally(function () { HOD.busy(btn, false); });
        });
    }

    function confirmButton(text, disabled) {
        var b = $('[data-sms-confirm-btn]');
        b.querySelector('span').textContent = text;
        b.disabled = !!disabled;
    }

    function sendCampaign() {
        var arr = Array.from(selected.values());
        if (!arr.length) { toast('Select at least one recipient.', 'error'); return; }
        var tpl = $('#sms-body').value;
        if (!tpl.trim()) { toast('Write a text first.', 'error'); return; }
        var a = analyse();
        var h = lastHealth || {};
        var cronOk = h.cron_age_sec != null && h.cron_age_sec <= 180;
        var body = '<div class="grid grid-cols-2 gap-3">'
            + '<div class="rounded-ui-lg bg-info-soft p-3"><p class="ui-meta">Recipients</p><p class="text-2xl font-semibold text-ink">' + arr.length + '</p></div>'
            + '<div class="rounded-ui-lg bg-warning-soft p-3"><p class="ui-meta">Units (billed)</p><p class="text-2xl font-semibold text-ink">' + a.units + '</p>'
            + '<p class="ui-meta">≈ ' + esc(naira(a.units * unitPrice())) + ' at ₦' + unitPrice().toFixed(2) + '</p></div></div>'
            + '<div data-sms-wallet>' + note('', 'Checking the BulkSMS wallet\u2026') + '</div>'
            + '<div><p class="ui-label">What ' + esc(arr[0].name || arr[0].phone) + ' gets</p>'
            + '<div class="whitespace-pre-wrap rounded-ui-lg border border-line bg-surface-sunken p-3 text-sm text-ink">' + esc(sign(render(tpl, arr[0]))) + '</div></div>'
            + a.warn.map(function (w) { return note('warning', esc(w[1])); }).join('')
            + (cronOk ? '' : note('danger', 'The background sender is not running, so texts go from this page: keep it open until 100%.'));
        $('[data-sms-confirm-body]').innerHTML = body;
        confirmButton('Checking wallet\u2026', true);
        HOD.dialog.open('sms-confirm');
        // The server refuses a campaign the wallet cannot pay for; show that before the click.
        call('wallet', { phones: JSON.stringify(arr.map(function (r) { return r.phone; })) }, { quiet: true }).then(function (res) {
            var price = res.unit_price > 0 ? Number(res.unit_price) : unitPrice();
            var need = a.units * price;
            var box = $('[data-sms-wallet]');
            var extra = '';
            if (res.suppressed > 0) {
                extra = note('warning', esc(res.suppressed + ' selected number(s) are blocked and will be skipped (₦0): ' + (res.suppressed_sample || []).join(', ') + (res.suppressed > 5 ? '\u2026' : '') + '. Release them on the Blocked tab first if they should get this.'));
            }
            if (res.status === 'success' && res.ok && res.balance !== null) {
                var short = Number(res.balance) < need;
                box.innerHTML = extra + note(short ? 'danger' : 'success', 'Wallet: <b>' + esc(naira(res.balance)) + '</b> · this needs about <b>' + esc(naira(need)) + '</b> (' + a.units + ' × ₦' + price.toFixed(2) + ')'
                    + (short ? '. Fund the wallet first: a campaign it cannot pay for is not queued.' : ''));
                confirmButton(short ? 'Wallet too low' : 'Send it', short);
            } else {
                var why = res && res.error ? ' (' + esc(res.error) + ')' : (res && res.message ? ' (' + esc(res.message) + ')' : '');
                box.innerHTML = extra + note('danger', 'The wallet balance could not be read' + why + '. Close this and try again in a moment.'
                    + (cfg.superAdmin ? '<label class="ui-check mt-2"><input type="checkbox" data-action-change="sms.override"><span>Send anyway (Super Admin): I checked the wallet on the BulkSMS dashboard</span></label>' : ''));
                confirmButton('Wallet unreadable', true);
            }
        }).catch(function () {
            $('[data-sms-wallet]').innerHTML = note('danger', 'The server could not be reached to read the wallet. Close this and try again.');
            confirmButton('Wallet unreadable', true);
        });
    }

    function confirmSend(btn) {
        var arr = Array.from(selected.values());
        var tpl = $('#sms-body').value;
        var override = $('[data-action-change="sms.override"]');
        confirmButton('Queuing\u2026', true);
        call('send_campaign', {
            template: tpl, audience: currentAudience(), recipients: JSON.stringify(arr),
            title: $('#sms-title').value.trim(), event_id: currentEventId(), client_token: composeToken,
            wallet_override: override && override.checked ? 1 : '',
            filters: JSON.stringify({ audience: currentAudience(), statuses: currentStatuses(), event_id: currentEventId(), search: $('#sms-search').value || '', rule: currentAudience() === 'assimilation' ? aw.rule : undefined })
        }).then(function (res) {
            confirmButton('Send it', false);
            if (res.status !== 'success') { toast(res.message, 'error'); return; }
            HOD.dialog.close('sms-confirm');
            toast(res.message);
            composeToken = newToken();            // the next campaign gets a fresh token
            loadHealth();
            openCampaign(String(res.campaign_id));
        }).catch(function () { confirmButton('Send it', false); });
    }

    /* ------------------------------------------------------------------ *
     * Campaigns
     * ------------------------------------------------------------------ */

    // Segment widths are set through the DOM (the strict CSP refuses style="").
    function progressBar(c) {
        var total = Math.max(1, c.total);
        var log = c.log || {};
        var seg = function (n, cls) { return n > 0 ? '<div class="h-full ' + cls + '" data-w="' + (n / total * 100).toFixed(2) + '"></div>' : ''; };
        return '<div class="flex h-2.5 overflow-hidden rounded-full bg-surface-sunken" role="img" aria-label="' + esc(countsText(log)) + '">'
            + seg(log.delivered || 0, 'bg-success') + seg((log.sent || 0) + (log.pending || 0) + (log.queued || 0), 'bg-warning')
            + seg(log.failed || 0, 'bg-danger') + seg(log.blocked || 0, 'bg-ink-subtle') + seg(log.unknown || 0, 'bg-primary') + '</div>';
    }
    function sizeBars(root) {
        $$('[data-w]', root).forEach(function (el) { el.style.width = el.getAttribute('data-w') + '%'; });
    }
    function countsValues(log) {
        return [
            ['Delivered', log.delivered || 0, 'text-success'],
            ['Awaiting report', (log.sent || 0) + (log.pending || 0) + (log.queued || 0), 'text-warning'],
            ['Failed', log.failed || 0, 'text-danger'],
            ['Blocked', log.blocked || 0, 'text-ink-muted'],
            ['Unknown', log.unknown || 0, 'text-primary']
        ].filter(function (p) { return p[1] > 0; });
    }
    function countsText(log) { return countsValues(log).map(function (p) { return p[1] + ' ' + p[0]; }).join(', ') || 'Nothing sent yet'; }
    function countsLine(log) {
        var v = countsValues(log);
        return v.length ? v.map(function (p) { return '<span class="font-medium ' + p[2] + '">' + p[1] + ' ' + esc(p[0]) + '</span>'; }).join(' · ') : '<span class="ui-meta">Nothing sent yet</span>';
    }
    function pager(page, pages, action) {
        var p = '';
        if (page > 1) p += '<button type="button" class="ui-btn ui-btn--secondary ui-btn--sm" data-action="' + action + '" data-p="' + (page - 1) + '">Previous</button>';
        if (page < pages) p += '<button type="button" class="ui-btn ui-btn--secondary ui-btn--sm" data-action="' + action + '" data-p="' + (page + 1) + '">Next</button>';
        return p;
    }

    function loadCampaigns(page) {
        campPage = page;
        var list = $('[data-sms-campaigns]');
        call('list_campaigns', { page: page }).then(function (res) {
            if (res.status !== 'success') return;
            if (!res.data.length) {
                list.innerHTML = '<div class="ui-card"><div class="ui-card__body">' + '<p class="ui-meta text-center">No campaigns yet.</p></div></div>';
            } else {
                list.innerHTML = res.data.map(function (c) {
                    var st = CAMP_STATE[c.state] || [c.state, 'neutral'];
                    var left = (c.queue.queued || 0) + (c.queue.sending || 0);
                    return '<button type="button" class="ui-card ui-card--interactive block w-full p-4 text-left" data-action="sms.campaign" data-ids="' + esc(c.ids) + '">'
                        + '<div class="mb-2 flex flex-wrap items-start justify-between gap-2"><div class="min-w-0">'
                        + '<p class="flex flex-wrap items-center gap-2 font-semibold text-ink"><span class="truncate">' + esc(c.title) + '</span>' + badge(st[0], st[1]) + (c.parts > 1 ? '<span class="ui-meta">(' + c.parts + ' batches)</span>' : '') + '</p>'
                        + '<p class="ui-meta">' + esc(tLabel(c.created)) + (c.created_by_name ? ' · by ' + esc(c.created_by_name) : '') + ' · ' + esc((c.audience || '').replace('_', ' ')) + (c.event_title ? ' · ' + esc(c.event_title) : '') + '</p></div>'
                        + '<div class="text-right"><p class="font-semibold text-ink">' + c.total + ' recipients</p><p class="ui-meta">' + esc(naira(c.cost)) + (left ? ' · ' + left + ' still to send' : '') + '</p></div></div>'
                        + progressBar(c) + '<p class="mt-2 text-sm">' + countsLine(c.log) + '</p>'
                        + '<p class="ui-meta mt-1 truncate">"' + esc(c.body || '') + '"</p></button>';
                }).join('');
                sizeBars(list);
            }
            $('[data-sms-campaigns-info]').textContent = 'Page ' + res.page + ' of ' + res.pages + ' · ' + res.total + ' campaign(s)';
            $('[data-sms-campaigns-pages]').innerHTML = pager(res.page, res.pages, 'sms.campaigns-page');
        }).catch(function () {});
    }

    function openCampaign(ids) { drawerOpen('campaign', ids, true); }

    function renderCampaign(ids) {
        call('campaign_status', { ids: ids }, { quiet: true }).then(function (res) {
            if (!drawerIs('campaign', ids)) return;
            var body = $('[data-sms-drawer-body]');
            if (res.status !== 'success') { body.innerHTML = note('danger', esc(res.message)); return; }
            var c = res.campaign;
            var done = c.total - c.remaining;
            var pct = c.total ? Math.round(done / c.total * 100) : 100;
            var notSent = (c.log.failed || 0) + (c.log.blocked || 0) + (c.log.unknown || 0);
            drawerTitle(c.title);
            var html = '<div class="ui-stack ui-stack--sm"><div class="flex justify-between text-sm font-semibold text-ink"><span>' + done + ' of ' + c.total + ' processed'
                + (notSent ? ' · <span class="text-danger">' + notSent + ' not sent</span>' : '') + '</span><span>' + pct + '%</span></div>'
                + progressBar(c) + '<p class="text-sm">' + countsLine(c.log) + '</p></div>';
            if (c.remaining > 0 && !c.cancelled && res.worker_message && /^(Paused|Waiting)/.test(res.worker_message)) {
                html += note('warning', esc(res.worker_message.replace(/\s+\d+ sent, .*$/, '')));
            }
            if (c.remaining > 0 && !c.cancelled) {
                html += res.cron_ok
                    ? note('success', 'Sending in the background, about one text a second. You can close this.')
                    : note('danger', 'The background sender is not running (last run ' + esc(secsLabel(res.cron_age_sec)) + '). Sending from this page: keep it open until 100%.');
            }
            html += '<div class="grid grid-cols-2 gap-2">'
                + kv('Created', tLabel(c.created)) + kv('Created by', c.created_by_name || '\u2014')
                + kv('First sent', tLabel(c.first_sent)) + kv('Last sent', tLabel(c.last_sent))
                + kv('Audience', (c.audience || '').replace('_', ' ')) + kv('Event', c.event_title || '\u2014')
                + kv('Cost at submission', naira(c.cost)) + kv('Still to send', c.remaining) + '</div>'
                + '<div><p class="ui-label">Text</p><div class="whitespace-pre-wrap rounded-ui-lg border border-line bg-surface-sunken p-3 text-sm text-ink">' + esc(c.body) + '</div></div>'
                + '<div class="flex flex-wrap gap-2"><button type="button" class="ui-btn ui-btn--primary ui-btn--sm" data-action="sms.campaign-history" data-ids="' + esc(c.ids) + '"><span>Every recipient</span></button>'
                + (c.remaining > 0 && !c.cancelled ? '<button type="button" class="ui-btn ui-btn--danger-ghost ui-btn--sm" data-action="sms.campaign-cancel" data-ids="' + esc(c.ids) + '"><span>Stop it</span></button>' : '')
                + (c.cancelled ? badge('Cancelled', 'neutral') : '') + '</div>';
            body.innerHTML = html;
            sizeBars(body);
            window.clearTimeout(timers.camp);
            if (c.remaining > 0 && !c.cancelled && !res.cron_ok) driveQueue(false);
            timers.camp = window.setTimeout(function () { if (drawerIs('campaign', ids)) renderCampaign(ids); }, c.remaining > 0 ? 4000 : 20000);
        }).catch(function () {
            // One failed poll (a blip, a session refresh) must not end the live view.
            window.clearTimeout(timers.camp);
            timers.camp = window.setTimeout(function () { if (drawerIs('campaign', ids)) renderCampaign(ids); }, 6000);
        });
    }

    function campaignCancel(btn) {
        var ids = btn.getAttribute('data-ids');
        HOD.confirm({
            title: 'Stop this campaign?',
            message: 'Texts already with BulkSMS cannot be recalled; the rest are cancelled.',
            confirmLabel: 'Stop it', tone: 'danger'
        }).then(function (yes) {
            if (!yes) return;
            call('cancel_campaign', { ids: ids }).then(function (res) { toast(res.message, res.status); renderCampaign(ids); loadHealth(); }).catch(function () {});
        });
    }

    /* ------------------------------------------------------------------ *
     * History
     * ------------------------------------------------------------------ */

    function loadHistory(page, quiet) {
        hist.page = page;
        var data = { page: page, search: $('#sms-history-search').value || '', status: hist.status,
            date_from: $('#sms-history-from').value, date_to: $('#sms-history-to').value, phone: hist.phone };
        if (hist.campaign) data.campaign_ids = hist.campaign.ids;
        call('get_history', data, { quiet: quiet }).then(function (res) {
            if (res.status !== 'success') { if (!quiet) toast(res.message, 'error'); return; }
            renderHistChips(res.counts || {});
            var rows = res.data || [];
            $('[data-sms-history]').innerHTML = rows.length ? rows.map(function (l) {
                var why = l.error_message && ['failed', 'blocked', 'unknown', 'pending'].indexOf(l.status) !== -1 ? l.error_message : '';
                return '<tr class="cursor-pointer" data-action="sms.message-row" data-id="' + (+l.id) + '" data-status="' + esc(l.status) + '">'
                    + '<td data-label="Recipient"><p class="font-medium text-ink">' + esc(l.recipient_name || '\u2014') + '</p><p class="ui-meta">' + esc(l.campaign_title || 'Test or manual') + (l.sent_by ? ' · by ' + esc(l.sent_by) : '') + '</p></td>'
                    + '<td data-label="Phone"><button type="button" class="ui-link" data-action="sms.person" data-phone="' + esc(l.recipient_phone) + '">' + esc(l.recipient_phone) + '</button></td>'
                    + '<td data-label="Status">' + stChip(l.status, l.message_id) + (why ? '<p class="ui-meta mt-1 line-clamp-2">' + esc(why) + '</p>' : '') + '</td>'
                    + '<td data-label="Sent" class="whitespace-nowrap" title="' + esc(tLabel(l.created)) + '">' + esc(l.created ? l.created.short : '\u2014') + '</td>'
                    + '<td data-label="Delivered" class="whitespace-nowrap" title="' + esc(tLabel(l.final)) + '">' + (l.final ? esc(l.final.short) : '\u2014') + '</td>'
                    + '<td data-label="Cost" class="whitespace-nowrap">' + esc(naira(l.cost)) + '</td>'
                    + '<td class="text-right"><button type="button" class="ui-btn ui-btn--ghost ui-btn--sm" data-action="sms.message" data-id="' + (+l.id) + '">Details</button></td></tr>';
            }).join('') : '<tr><td colspan="7" class="ui-meta text-center">No texts match.</td></tr>';
            $('[data-sms-history-info]').textContent = 'Page ' + res.page + ' of ' + res.pages + ' · ' + res.total + ' text(s) · ' + naira(res.spend) + ' at submission';
            $('[data-sms-history-pages]').innerHTML = pager(res.page, res.pages, 'sms.history-page');
            renderHistFilters();
        }).catch(function () {});
    }

    function renderHistChips(c) {
        var chips = [['', 'All', c.all], ['delivered', 'Delivered', c.delivered], ['awaiting', 'Awaiting', c.awaiting], ['failed', 'Failed', c.failed], ['blocked', 'Blocked', c.blocked], ['unknown', 'Unknown', c.unknown]];
        $('[data-sms-history-chips]').innerHTML = chips.map(function (ch) {
            var on = hist.status === ch[0];
            return '<button type="button" class="ui-btn ui-btn--sm ' + (on ? 'ui-btn--primary' : 'ui-btn--secondary') + '" aria-pressed="' + (on ? 'true' : 'false') + '" data-action="sms.history-status" data-s="' + ch[0] + '">'
                + '<span>' + esc(ch[1]) + '</span><span class="ui-count">' + esc(ch[2] == null ? 0 : ch[2]) + '</span></button>';
        }).join('');
    }

    function renderHistFilters() {
        var f = '';
        if (hist.campaign) f += '<span class="ui-badge ui-badge--primary">Campaign: ' + esc(hist.campaign.title || '#' + hist.campaign.ids)
            + ' <button type="button" class="ml-1" data-action="sms.history-clear" data-f="campaign" aria-label="Clear the campaign filter">' + HOD.icon('x', 'ui-icon--sm') + '</button></span>';
        if (hist.phone) f += '<span class="ui-badge ui-badge--primary">Number: ' + esc(hist.phone)
            + ' <button type="button" class="ml-1" data-action="sms.history-clear" data-f="phone" aria-label="Clear the number filter">' + HOD.icon('x', 'ui-icon--sm') + '</button></span>';
        $('[data-sms-history-filters]').innerHTML = f;
    }

    // A quiet refresh: one batched delivery check for the rows awaiting a report.
    function startHistPoll() { stopHistPoll(); timers.histPoll = window.setInterval(pollHistory, 30000); }
    function stopHistPoll() { if (timers.histPoll) { window.clearInterval(timers.histPoll); timers.histPoll = null; } }
    function pollHistory() {
        if (currentTab !== 'history' || document.hidden) return;
        var ids = $$('[data-sms-history] tr[data-id]').filter(function (r) { return ['sent', 'pending'].indexOf(r.getAttribute('data-status')) !== -1; })
            .map(function (r) { return r.getAttribute('data-id'); }).slice(0, 20);
        var reload = function () { loadHistory(hist.page, true); };
        if (!ids.length) { reload(); return; }
        call('refresh_statuses', { ids: ids.join(',') }, { quiet: true }).then(reload, reload);
    }

    /* ------------------------------------------------------------------ *
     * The drawer: message, person and campaign, with Back
     * ------------------------------------------------------------------ */

    function drawerTitle(text) { var t = $('#sms-drawer-title'); if (t) t.textContent = text; }
    function drawerOpenNow() { var d = $('#sms-drawer'); return d && d.hasAttribute('open'); }
    function drawerOpen(kind, arg, reset) {
        if (reset || !drawerOpenNow()) drawerStack = [];
        drawerStack.push({ kind: kind, arg: arg });
        $('[data-sms-drawer-back]').hidden = drawerStack.length < 2;
        $('[data-sms-drawer-body]').innerHTML = '<p class="ui-meta">Loading\u2026</p>';
        drawerTitle('Details');
        if (!drawerOpenNow()) HOD.dialog.open('sms-drawer');
        if (kind === 'message') renderMessage(arg);
        else if (kind === 'person') renderPerson(arg);
        else renderCampaign(arg);
    }
    function drawerIs(kind, arg) {
        var top = drawerStack[drawerStack.length - 1];
        return !!top && top.kind === kind && String(top.arg) === String(arg) && drawerOpenNow();
    }
    function drawerBack() {
        drawerStack.pop();
        var prev = drawerStack.pop();
        if (prev) drawerOpen(prev.kind, prev.arg);
    }
    function openMessage(id) { drawerOpen('message', id, !drawerOpenNow()); }
    function openPerson(phone) { drawerOpen('person', phone, !drawerOpenNow()); }

    function renderMessage(id) {
        call('get_message', { id: id }, { quiet: true }).then(function (res) {
            if (!drawerIs('message', id)) return;
            var body = $('[data-sms-drawer-body]');
            if (res.status !== 'success') { body.innerHTML = note('danger', esc(res.message)); return; }
            var m = res.message;
            drawerTitle('Text #' + m.id + ' · ' + (m.recipient_name || m.recipient_phone));
            var tl = '<ol class="ui-stack ui-stack--sm border-l-2 border-line pl-4">' + (m.timeline || []).map(function (e) {
                var s = ST[e.to] || { label: e.to, tone: 'neutral' };
                var isNote = e.from !== null && e.from === e.to;
                return '<li><p class="flex flex-wrap items-center gap-2 font-medium text-ink">' + (isNote ? badge('Note', 'neutral') : badge(s.label, s.tone))
                    + (e.synthetic ? '<span class="ui-meta">(reconstructed)</span>' : '') + '</p>'
                    + '<p class="text-sm font-medium text-primary">' + esc(tLabel(e.at)) + '</p>'
                    + '<p class="ui-meta">' + esc(SRC[e.source] || e.source) + (e.by ? ' · ' + esc(e.by) : '') + (e.raw ? ' · carrier/API said: <b>' + esc(e.raw) + '</b>' : '') + (e.code ? ' · code ' + esc(e.code) : '') + '</p>'
                    + (e.provider_time ? '<p class="ui-meta">Time reported by BulkSMS/carrier: <b>' + esc(e.provider_time) + '</b></p>' : '')
                    + (e.detail ? '<p class="text-sm text-ink-muted">' + esc(e.detail) + '</p>' : '') + '</li>';
            }).join('') + '</ol>';
            var n = m.number || {};
            var sp = n.suppression;
            var byS = Object.keys(n.by_status || {}).map(function (k) { return n.by_status[k] + ' ' + ((ST[k] || { label: k }).label.split(' ·')[0].toLowerCase()); }).join(', ');
            var html = '<div class="flex flex-wrap items-center gap-2">' + stChip(m.status, m.message_id)
                + '<button type="button" class="ui-link" data-action="sms.person" data-phone="' + esc(m.recipient_phone) + '">' + esc(m.recipient_phone) + '</button>'
                + '<span class="ui-meta">' + esc(m.recipient_name || '') + '</span></div>'
                + (m.error_message && m.status !== 'delivered' ? note('', esc(m.error_message) + (m.api_code_text ? '<br><span class="ui-meta">' + esc(m.api_code) + ': ' + esc(m.api_code_text) + '</span>' : '')) : '')
                + '<div><p class="ui-label">Text sent</p><div class="whitespace-pre-wrap rounded-ui-lg border border-line bg-surface-sunken p-3 text-sm text-ink">' + esc(m.body || '') + '</div>'
                + '<p class="ui-meta mt-1">' + m.segments.chars + ' characters · ' + esc(m.segments.encoding) + ' · ' + m.segments.pages + ' page(s)</p></div>'
                + '<div><p class="ui-label">Status changes (WAT)</p>' + tl + '</div>'
                + '<div class="grid grid-cols-2 gap-2">'
                + kv('First recorded', tLabel(m.created)) + kv('Last change', tLabel(m.updated))
                + kv('Campaign', m.campaign ? m.campaign.title : 'Test or manual') + kv('Sent by', m.sent_by || (m.campaign ? 'Background sender' : '\u2014'))
                + kv('BulkSMS message ID', m.message_id || 'none (never reached BulkSMS)') + kv('Sender ID · route', (m.sender_id || '\u2014') + ' · ' + (m.gateway || '\u2014'))
                + kv('Cost at submission', naira(m.cost)) + kv('Last delivery check', tLabel(m.dlr_checked)) + '</div>'
                + '<div class="ui-card"><div class="ui-card__body ui-stack ui-stack--sm"><p class="ui-label">Who is this?</p>'
                + (m.person ? '<p class="text-sm"><b>' + esc(m.person.name || '\u2014') + '</b> · ' + esc(m.person.kind) + '</p><p class="ui-meta">' + esc(m.person.status || '')
                    + (m.person.email ? ' · ' + esc(m.person.email) : '') + (m.person.phone_on_file ? ' · on file as ' + esc(m.person.phone_on_file) : '') + '</p>'
                    : '<p class="ui-meta">No linked record (test or manual send).</p>')
                + '<p class="text-sm">This number got <b>' + (n.total || 0) + '</b> text(s)' + (byS ? ' (' + esc(byS) + ')' : '') + ' · ' + esc(naira(n.cost)) + ' in total.</p>'
                + (sp && !sp.released_at ? '<p class="text-sm font-medium text-danger">Blocked since ' + esc(tLabel(sp.suppressed)) + ': ' + esc(sp.reason || '') + '</p>' : '')
                + '<button type="button" class="ui-link self-start text-sm" data-action="sms.person" data-phone="' + esc(m.recipient_phone) + '">Every text to this number</button></div></div>'
                + '<div class="flex flex-wrap gap-2">'
                + (m.message_id && ['sent', 'pending', 'unknown', 'failed'].indexOf(m.status) !== -1 ? '<button type="button" class="ui-btn ui-btn--secondary ui-btn--sm" data-action="sms.check" data-id="' + m.id + '">' + HOD.icon('refresh-cw') + '<span>Check now</span></button>' : '')
                + (m.can_resend ? '<button type="button" class="ui-btn ui-btn--secondary ui-btn--sm" data-action="sms.resend" data-id="' + m.id + '" data-status="' + esc(m.status) + '">' + HOD.icon('send') + '<span>Resend</span></button>' : '')
                + (m.campaign ? '<button type="button" class="ui-btn ui-btn--ghost ui-btn--sm" data-action="sms.campaign" data-ids="' + esc(m.campaign.id) + '" data-keep="1"><span>Open campaign</span></button>' : '') + '</div>';
            if (m.webhooks && m.webhooks.length) {
                html += '<details class="rounded-ui-lg bg-surface-sunken p-3"><summary class="cursor-pointer text-sm font-medium">Delivery reports from BulkSMS (' + m.webhooks.length + ')</summary><div class="mt-2 ui-stack ui-stack--sm">'
                    + m.webhooks.map(function (w) {
                        return '<div class="rounded-ui border border-line bg-surface p-2 text-xs"><b>' + esc(tLabel(w.at)) + '</b> · said "' + esc(w.parsed_status || '') + '" → ' + esc(w.applied_status || '\u2014') + ' · from ' + esc(w.remote_ip || '?')
                            + '<pre class="mt-1 whitespace-pre-wrap break-all text-ink-muted">' + esc(w.raw_body || '') + '</pre></div>';
                    }).join('') + '</div></details>';
            }
            if (m.raw_response) html += '<details class="rounded-ui-lg bg-surface-sunken p-3"><summary class="cursor-pointer text-sm font-medium">BulkSMS reply to the send</summary><pre class="mt-2 whitespace-pre-wrap break-all text-xs text-ink-muted">' + esc(m.raw_response) + '</pre></details>';
            body.innerHTML = html;
        }).catch(function () {});
    }

    function checkDelivery(btn) {
        var id = +btn.getAttribute('data-id');
        HOD.busy(btn, true);
        call('check_delivery', { id: id }).then(function (res) {
            toast(res.message, res.status);
            renderMessage(id);
            if (currentTab === 'history') loadHistory(hist.page, true);
        }).catch(function () {}).finally(function () { HOD.busy(btn, false); });
    }

    function resendOne(id, status, confirmed) {
        var ask = confirmed ? Promise.resolve(true) : HOD.confirm({
            title: status === 'delivered' ? 'Send it again?' : 'Resend this text?',
            message: status === 'delivered' ? 'It was already delivered. This is another billed text.' : 'It is a real, billed text.',
            confirmLabel: 'Resend'
        });
        ask.then(function (yes) {
            if (!yes) return;
            call('resend_recipient', { id: id, confirm_unknown: confirmed ? 1 : '' }).then(function (res) {
                if (res.status === 'confirm') {
                    HOD.confirm({ title: 'Resend anyway?', message: res.message, confirmLabel: 'Resend' }).then(function (ok) { if (ok) resendOne(id, status, true); });
                    return;
                }
                toast(res.message, res.status);
                if (res.log_id) drawerOpen('message', res.log_id);
                if (currentTab === 'history') loadHistory(hist.page, true);
            }).catch(function () {});
        });
    }

    function renderPerson(phone) {
        call('get_person', { phone: phone }, { quiet: true }).then(function (res) {
            if (!drawerIs('person', phone)) return;
            var body = $('[data-sms-drawer-body]');
            if (res.status !== 'success') { body.innerHTML = note('danger', esc(res.message)); return; }
            var p = res.person;
            var sp = p.suppression;
            drawerTitle((p.names[0] || 'Number') + ' · ' + p.phone);
            var cnt = Object.keys(p.counts || {}).map(function (k) { var s = ST[k] || { label: k, tone: 'neutral' }; return badge(p.counts[k] + ' ' + s.label.split(' ·')[0], s.tone); }).join(' ');
            body.innerHTML = '<div class="grid grid-cols-2 gap-2">'
                + kv('Phone', p.phone) + kv('Texts', p.total + (p.total >= 200 ? ' (latest 200)' : ''))
                + kv('First text', tLabel(p.first)) + kv('Latest text', tLabel(p.last))
                + kv('Spent at submission', naira(p.cost)) + kv('Names used', p.names.join(', ') || '\u2014') + '</div>'
                + '<div class="flex flex-wrap gap-1.5">' + cnt + '</div>'
                + (p.people.length ? '<div class="ui-card"><div class="ui-card__body"><p class="ui-label">On file in the church database</p>' + p.people.map(function (x) {
                    return '<p class="text-sm"><b>' + esc(x.name) + '</b> · ' + esc((x.status || '').replace(/_/g, ' ')) + (x.email ? ' · ' + esc(x.email) : '') + '</p>';
                }).join('') + '</div></div>' : '')
                + (sp ? note(sp.released_at ? '' : 'danger', sp.released_at ? 'Was blocked; released ' + esc(tLabel(sp.released)) : 'Blocked since ' + esc(tLabel(sp.suppressed)) + ': ' + esc(sp.reason || '')) : '')
                + '<div class="flex flex-wrap gap-2"><button type="button" class="ui-btn ui-btn--primary ui-btn--sm" data-action="sms.person-history" data-phone="' + esc(p.phone) + '"><span>Filter History</span></button>'
                + (!sp || sp.released_at ? '<button type="button" class="ui-btn ui-btn--danger-ghost ui-btn--sm" data-action="sms.person-block" data-phone="' + esc(p.phone) + '"><span>Block number</span></button>' : '') + '</div>'
                + '<div><p class="ui-label">Every text, newest first</p><div class="ui-stack ui-stack--sm">' + (p.messages.map(function (x) {
                    return '<button type="button" class="ui-card ui-card--interactive block w-full p-3 text-left" data-action="sms.message" data-id="' + (+x.id) + '">'
                        + '<span class="flex items-start justify-between gap-2">' + stChip(x.status, x.message_id) + '<span class="ui-meta text-right">Sent ' + esc(tLabel(x.created)) + (x.final ? '<br>Final ' + esc(tLabel(x.final)) : '') + '</span></span>'
                        + '<span class="mt-1 block text-sm text-ink line-clamp-2">' + esc(x.body || '') + '</span>'
                        + '<span class="ui-meta mt-1 block">' + esc(x.campaign_title || 'Test or manual') + (x.sent_by ? ' · by ' + esc(x.sent_by) : '') + ' · ' + esc(naira(x.cost)) + '</span></button>';
                }).join('') || '<p class="ui-meta">No texts.</p>') + '</div></div>';
        }).catch(function () {});
    }

    function personBlock(btn) {
        var phone = btn.getAttribute('data-phone');
        HOD.prompt({ title: 'Block ' + phone + '?', label: 'Reason', value: 'Asked to stop', confirmLabel: 'Block' }).then(function (reason) {
            if (reason === null || reason === undefined) return;
            call('add_suppression', { phone: phone, reason: reason }).then(function (res) { toast(res.message, res.status); renderPerson(phone); }).catch(function () {});
        });
    }

    /* ------------------------------------------------------------------ *
     * Blocked numbers (suppression)
     * ------------------------------------------------------------------ */

    function loadBlocked() {
        var list = $('[data-sms-blocked]');
        call('list_suppression', {}).then(function (res) {
            if (res.status !== 'success') { list.innerHTML = empty(res.message || 'Could not load the list.'); return; }
            var rows = res.data || [];
            if (!rows.length) { list.innerHTML = empty('No blocked numbers.'); return; }
            list.innerHTML = rows.map(function (s) {
                var active = !s.released_at;
                return '<li class="ui-list__item items-start justify-between gap-3"><div class="min-w-0 flex-1">'
                    + '<p class="flex flex-wrap items-center gap-2 font-medium"><button type="button" class="ui-link" data-action="sms.person" data-phone="' + esc(s.phone) + '">' + esc(s.phone) + '</button>' + badge(active ? 'Active' : 'Released', active ? 'danger' : 'neutral') + '</p>'
                    + '<p class="truncate text-sm text-ink-muted">' + esc(s.reason || '') + (s.note ? ' · ' + esc(s.note) : '') + '</p>'
                    + '<p class="ui-meta">Blocked ' + esc(tLabel(s.suppressed)) + (+s.consecutive_failures ? ' · ' + (+s.consecutive_failures) + ' failures in a row, last ' + esc(tLabel(s.last_failed)) : '')
                    + (s.released ? ' · released ' + esc(tLabel(s.released)) + (s.released_by ? ' by ' + esc(s.released_by) : '') : '') + '</p></div>'
                    + (active ? '<button type="button" class="ui-btn ui-btn--secondary ui-btn--sm" data-action="sms.release" data-id="' + (+s.id) + '">Release</button>' : '') + '</li>';
            }).join('');
        }).catch(function () {});
    }

    function block(form) {
        var phone = form.querySelector('[name="phone"]').value.trim();
        if (!phone) { HOD.form.setErrors(form, { phone: 'Enter a phone number.' }); return; }
        HOD.confirm({ title: 'Block this number?', message: 'It gets no texts until it is released.', confirmLabel: 'Block', tone: 'danger' }).then(function (yes) {
            if (!yes) return;
            var btn = form.querySelector('[type="submit"]');
            HOD.busy(btn, true);
            call('add_suppression', { phone: phone, reason: form.querySelector('[name="reason"]').value.trim() }).then(function (res) {
                toast(res.message, res.status);
                if (res.status === 'success') form.reset();
                loadBlocked();
            }).catch(function () {}).finally(function () { HOD.busy(btn, false); });
        });
    }

    function release(btn) {
        HOD.confirm({ title: 'Release this number?', message: 'It can get texts again.', confirmLabel: 'Release' }).then(function (yes) {
            if (!yes) return;
            call('release_suppression', { id: +btn.getAttribute('data-id') }).then(function (res) { toast(res.message, res.status); loadBlocked(); }).catch(function () {});
        });
    }

    /* ------------------------------------------------------------------ *
     * Settings
     * ------------------------------------------------------------------ */

    function showLocked(locked) {
        $('[data-sms-settings-locked]').hidden = !locked;
        $('[data-sms-settings-unlocked]').hidden = locked;
    }

    function loadSettingsStatus() {
        call('settings_status', {}, { quiet: true }).then(function (res) {
            if (res.status !== 'success') return;
            var m = res.masked || {};
            var dflt = { api_token: 'not set', sender_id: 'not set' };
            $$('[data-sms-mask]').forEach(function (el) { var k = el.getAttribute('data-sms-mask'); el.textContent = m[k] || dflt[k] || '\u2014'; });
            showLocked(true);
        }).catch(function () {});
    }

    function renderChecklist() {
        var h = lastHealth || {};
        var cronFiring = h.cron_seen_age_sec != null && h.cron_seen_age_sec <= 180;
        var item = function (ok, text, fix) {
            return '<li class="flex gap-2">' + HOD.icon(ok ? 'circle-check' : 'circle-x', ok ? 'text-success' : 'text-danger') + '<span>' + esc(text)
                + (!ok && fix ? '<span class="ui-meta block">' + esc(fix) + '</span>' : '') + '</span></li>';
        };
        $('[data-sms-checklist]').innerHTML =
            item(h.vault_ok, 'SMS_VAULT_KEY is set in .env', 'Add a 64-hex-character SMS_VAULT_KEY to .env on the server.')
            + item(h.configured && !(h.decrypt_failed || []).length, 'BulkSMS token and sender ID saved', 'Unlock above and save the token and an approved sender ID.')
            + item(!!h.signature, 'Sender name starts every text' + (h.signature ? ' ("' + String(h.signature).trim() + '")' : ''), 'Unlock above and set "Sender name in text" back to the default.')
            + item(h.schema_ready, 'Database is up to date', 'Run php db/migrate.php (the deploy does this).')
            + item(h.cron_age_sec != null && h.cron_age_sec <= 180, 'Background sender is running' + (h.cron_age_sec != null ? ' (last run ' + secsLabel(h.cron_age_sec) + ')' : ''),
                cronFiring ? 'The cron job fires but its runs do not finish: ' + (h.last_message || 'see includes/error_log and the cron log') : 'Add the cron line below in cPanel → Cron Jobs.')
            + item(h.webhook_secret_set, 'SMS_WEBHOOK_SECRET is set in .env (optional)', 'Add SMS_WEBHOOK_SECRET to .env for instant delivery reports.')
            + item(h.webhook_url_set, 'Delivery webhook saved (optional)', 'Unlock above and press "Use this site". Without it the sender polls BulkSMS.');
    }

    function unlock(form) {
        var input = form.querySelector('[name="password"]');
        if (!input.value) { HOD.form.setErrors(form, { password: 'Enter your password.' }); return; }
        var btn = form.querySelector('[type="submit"]');
        HOD.busy(btn, true);
        call('unlock_settings', { password: input.value }).then(function (res) {
            if (res.status !== 'success') { HOD.form.setErrors(form, { password: res.message || 'That did not work.' }); return; }
            var s = res.settings || {};
            $('#sms-base').value = s.base_url || 'https://www.bulksmsnigeria.com/api/v2';
            $('#sms-token').value = s.api_token || '';
            $('#sms-sender').value = s.sender_id || '';
            $('#sms-gateway').value = s.gateway || '';
            $('#sms-webhook').value = s.webhook_url || '';
            $('#sms-sign').value = s.sign_mode || '';
            $('#sms-price').value = s.unit_price || '';
            webhookSuggestion = res.webhook_suggestion || '';
            input.value = '';
            showLocked(false);
            toast('Unlocked for 10 minutes.');
        }).catch(function () {}).finally(function () { HOD.busy(btn, false); });
    }

    function saveSettings(form) {
        var btn = form.querySelector('[type="submit"]');
        HOD.busy(btn, true);
        call('save_settings', {
            base_url: $('#sms-base').value, api_token: $('#sms-token').value, sender_id: $('#sms-sender').value, gateway: $('#sms-gateway').value,
            webhook_url: $('#sms-webhook').value, sign_mode: $('#sms-sign').value, unit_price: $('#sms-price').value.trim()
        }).then(function (res) {
            toast(res.message, res.status);
            if (res.status === 'success') { loadSettingsStatus(); loadHealth(); }
        }).catch(function () {}).finally(function () { HOD.busy(btn, false); });
    }

    function fetchSenders(btn) {
        HOD.busy(btn, true);
        call('list_sender_ids', {}).then(function (res) {
            var box = $('[data-sms-senders]');
            if (res.status !== 'success') { toast(res.message, 'error'); return; }
            var ids = res.data || [];
            if (!ids.length) { toast('No sender IDs on your BulkSMS account.', 'error'); box.hidden = true; return; }
            box.innerHTML = '<span class="ui-meta w-full">Only an approved one delivers:</span>' + ids.map(function (s) {
                var name = typeof s === 'string' ? s : (s.sender_id || s.name || '');
                var status = typeof s === 'object' ? (s.status || '') : '';
                return '<button type="button" class="ui-btn ui-btn--secondary ui-btn--sm" data-action="sms.pick-sender" data-v="' + esc(name) + '">' + esc(name) + (status ? ' (' + esc(status) + ')' : '') + '</button>';
            }).join('');
            box.hidden = false;
        }).catch(function () {}).finally(function () { HOD.busy(btn, false); });
    }

    /* ------------------------------------------------------------------ *
     * Wiring
     * ------------------------------------------------------------------ */

    HOD.ready(function () {
        if (!$('[data-sms-health]')) return;
        cfg = HOD.data('sms-config') || {};
        var A = HOD.actions;

        document.addEventListener('ui:tab', function (e) { if (e.detail && e.detail.tab) onTab(e.detail.tab); });
        document.addEventListener('visibilitychange', function () { if (!document.hidden) loadHealth(); });

        A.on('sms.drive', function () { driveQueue(true); });
        A.on('sms.audience', function (el) { onAudience(el); });
        A.on('sms.search', function () { window.clearTimeout(timers.fetch); timers.fetch = window.setTimeout(fetchRecipients, 250); });
        A.on('sms.pick', function (cb) {
            var r = recipients[+cb.getAttribute('data-i')];
            if (!r) return;
            if (cb.checked) selected.set(r.phone, r); else selected.delete(r.phone);
            afterSelection();
        });
        A.on('sms.unpick', function (btn) { selected.delete(btn.getAttribute('data-phone')); renderContacts(); afterSelection(); });
        A.on('sms.select-all', function () { recipients.forEach(function (r) { selected.set(r.phone, r); }); renderContacts(); afterSelection(); });
        A.on('sms.clear', function () { selected.clear(); renderContacts(); afterSelection(); });

        A.on('sms.assim-open', openAssim);
        A.on('sms.aw-away', function (b) { aw.away = b.getAttribute('data-away'); awPaint(); });
        A.on('sms.aw-step', function (b) {
            var f = b.getAttribute('data-field');
            var lim = f === 'max' ? [1, 200] : [1, 104];
            aw.custom[f] = Math.max(lim[0], Math.min(lim[1], aw.custom[f] + (+b.getAttribute('data-delta'))));
            awPaint();
        });
        A.on('sms.aw-unit', function (sel) { aw.custom.unit = sel.value; });
        A.on('sms.aw-everyone', function () { aw.who.clear(); awPaint(); });
        A.on('sms.aw-status', function (b) { var s = b.getAttribute('data-status'); if (aw.who.has(s)) aw.who.delete(s); else aw.who.add(s); awPaint(); });
        A.on('sms.aw-back', function () { awShow(aw.step - 1); });
        A.on('sms.aw-next', awNext);

        A.on('sms.token', insertToken);
        A.on('sms.body', scheduleAnalyse);
        A.on('sms.fix-chars', function () { var ta = $('#sms-body'); ta.value = gsmClean(ta.value); scheduleAnalyse(); });
        A.on('sms.tpl-load', tplLoad);
        A.on('sms.tpl-save', tplSave);
        A.on('sms.tpl-delete', tplDelete);
        A.on('sms.test', sendTest);
        A.on('sms.send', sendCampaign);
        A.on('sms.override', function (cb) { confirmButton(cb.checked ? 'Send it' : 'Wallet unreadable', !cb.checked); });
        A.on('sms.confirm', confirmSend);

        A.on('sms.campaigns-reload', function () { loadCampaigns(campPage); });
        A.on('sms.campaigns-page', function (b) { loadCampaigns(+b.getAttribute('data-p')); });
        A.on('sms.campaign', function (b) { drawerOpen('campaign', b.getAttribute('data-ids'), !b.hasAttribute('data-keep')); });
        A.on('sms.campaign-cancel', campaignCancel);
        A.on('sms.campaign-history', function (b) {
            hist.campaign = { ids: b.getAttribute('data-ids'), title: ($('#sms-drawer-title') || {}).textContent || '' };
            hist.phone = '';
            hist.status = '';
            HOD.dialog.close('sms-drawer');
            goTab('history');
        });

        A.on('sms.history-search', function () { window.clearTimeout(timers.hist); timers.hist = window.setTimeout(function () { loadHistory(1); }, 300); });
        A.on('sms.history-dates', function () { loadHistory(1); });
        A.on('sms.history-status', function (b) { hist.status = b.getAttribute('data-s'); loadHistory(1); });
        A.on('sms.history-clear', function (b) { if (b.getAttribute('data-f') === 'campaign') hist.campaign = null; else hist.phone = ''; loadHistory(1); });
        A.on('sms.history-page', function (b) { loadHistory(+b.getAttribute('data-p')); });
        A.on('sms.message-row', function (row, e) { if (e && e.target.closest && e.target.closest('button')) return; openMessage(+row.getAttribute('data-id')); });
        A.on('sms.message', function (b) { drawerOpen('message', +b.getAttribute('data-id')); });
        A.on('sms.person', function (b) { openPerson(b.getAttribute('data-phone')); });
        A.on('sms.person-history', function (b) { hist.phone = b.getAttribute('data-phone'); hist.campaign = null; hist.status = ''; HOD.dialog.close('sms-drawer'); goTab('history'); });
        A.on('sms.person-block', personBlock);
        A.on('sms.check', checkDelivery);
        A.on('sms.resend', function (b) { resendOne(+b.getAttribute('data-id'), b.getAttribute('data-status'), false); });
        A.on('sms.drawer-back', drawerBack);

        A.on('sms.blocked-reload', loadBlocked);
        A.on('sms.block', block);
        A.on('sms.release', release);

        A.on('sms.unlock', unlock);
        A.on('sms.settings-save', saveSettings);
        A.on('sms.lock', function () { call('lock_settings', {}).then(function () { toast('Settings locked.'); loadSettingsStatus(); }).catch(function () {}); });
        A.on('sms.test-connection', function (b) { HOD.busy(b, true); call('test_connection', {}).then(function (res) { toast(res.message, res.status); }).catch(function () {}).finally(function () { HOD.busy(b, false); }); });
        A.on('sms.senders', fetchSenders);
        A.on('sms.pick-sender', function (b) { $('#sms-sender').value = b.getAttribute('data-v'); });
        A.on('sms.webhook', function () {
            if (!webhookSuggestion) { toast('Set SMS_WEBHOOK_SECRET in the server .env first, then unlock again.', 'error'); return; }
            $('#sms-webhook').value = webhookSuggestion;
            toast('Filled in. Save, then paste the same URL in the BulkSMS dashboard.', 'info');
        });

        var drawer = $('#sms-drawer');
        if (drawer) drawer.addEventListener('ui:dialog-close', function () { drawerStack = []; window.clearTimeout(timers.camp); });

        call('fetch_events', {}, { quiet: true }).then(function (res) {
            if (res.status !== 'success' || !res.data) return;
            var opts = res.data.map(function (e) { return '<option value="' + (+e.id) + '">' + esc(e.title) + (e.date_label ? ' · ' + esc(e.date_label) : '') + '</option>'; }).join('');
            $('#sms-reg-event').innerHTML = '<option value="">Choose an event</option>' + opts;
            $('#sms-checkin-event').innerHTML = '<option value="">Every event</option>' + opts;
        }).catch(function () {});
        loadHealth();
        timers.health = window.setInterval(function () { if (!document.hidden) loadHealth(); }, 30000);

        // Deep links: ?tab=history&phone=\u2026 · ?msg=\u2026 · ?campaign=\u2026 (and #tab=\u2026).
        var q = new URLSearchParams(window.location.search);
        var legacy = { suppression: 'blocked' };
        var wanted = q.get('tab') ? (legacy[q.get('tab')] || q.get('tab')) : '';
        if (q.get('phone')) { hist.phone = q.get('phone'); goTab('history'); }
        else if (q.get('campaign')) { goTab('campaigns'); openCampaign(q.get('campaign')); }
        else if (['compose', 'campaigns', 'history', 'blocked', 'settings'].indexOf(wanted) !== -1) goTab(wanted);
        else {
            var active = $('[data-ui-tabs] [role="tab"][aria-selected="true"]');
            onTab(active ? active.getAttribute('data-tab') : 'compose');
        }
        if (q.get('msg')) openMessage(+q.get('msg'));
        analyse();
    });
})();
