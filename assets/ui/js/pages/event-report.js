// /assets/ui/js/pages/event-report.js
// The Event report (modules/event_report): the summary, the counts, two
// charts (HOD.chart), SMS (from SMS Studio, plus BulkSMS campaigns logged
// here) and the detail. Data: /api/event_report_api.php (report_csrf).
(function () {
    'use strict';

    var API = '/api/event_report_api.php';
    var PDF = '/api/event_report_pdf.php';
    var cfg = HOD.data('event-report-config') || {};
    var esc = HOD.esc;
    var eventId = '';
    var seq = 0;
    var logged = {};

    function $(s) { return document.querySelector(s); }
    function call(action, data, opts) {
        return HOD.api(API, Object.assign({ action: action, data: Object.assign({ _csrf: cfg.csrf }, data || {}) }, opts || {}));
    }
    function num(n) { return HOD.format.number(n || 0); }
    function naira(n) { return HOD.format.naira(n || 0); }
    function set(key, value) { var el = $('[data-er="' + key + '"]'); if (el) el.textContent = value; }

    function summary(res) {
        var d = res.data, n = d.narrative || {};
        var sources = d.registration_sources || {}, labels = d.source_labels || {};
        var top = Object.keys(sources).sort(function (a, b) { return sources[b] - sources[a]; })[0];
        var when = '<strong>' + esc(n.start_date) + '</strong>' + (n.end_date && n.end_date !== n.start_date ? ' to <strong>' + esc(n.end_date) + '</strong>' : '');
        var att = Number(d.attendance_total) || 0, days = Number(res.day_count) || 1;
        var html = '<p>On ' + when + ', Household of David Lekki Centre hosted <strong>' + esc(n.title) + '</strong>. ' +
            '<strong>' + num(att) + '</strong> ' + (att === 1 ? 'person' : 'people') + ' attended over ' + num(days) + ' day' + (days === 1 ? '' : 's') +
            ', <strong>' + esc(String(n.member_pct || 0)) + '%</strong> of them members, and <strong>' + num(d.registration_total) + '</strong> registered.</p>' +
            '<p><strong>' + num(d.sms.reach_total) + '</strong> unique numbers on file could be reached by SMS.' +
            // The most common channel, from what registrants actually chose.
            (top && sources[top] > 0 ? ' Most registrants heard about it through <strong>' + esc(labels[top] || top) + '</strong>.' : '') +
            (Number(d.idi_total) > 0 ? ' <strong>' + num(d.idi_attended) + '</strong> of the ' + num(d.idi_total) + ' on the IDI list came.' : '') + '</p>';
        $('#er-summary').innerHTML = html;
    }

    function smsRows(sys, manual) {
        var h = '';
        (sys || []).forEach(function (s) {
            h += '<tr><td data-label="Campaign"><span class="ui-table__primary">' + esc(s.title) + '</span> <span class="ui-badge ui-badge--info">SMS Studio</span>' +
                '<a class="ui-link mt-1 block text-xs" href="/modules/sms_studio/?campaign=' + encodeURIComponent(s.campaign_ids || '') + '">' + num(s.delivered) + ' delivered · open</a></td>' +
                '<td data-label="Sent" class="ui-num">' + esc(String(s.last_sent || '').slice(0, 10)) + '</td>' +
                '<td data-label="Recipients" class="ui-num">' + num(s.uniq) + '</td>' +
                '<td data-label="Pages" class="ui-num">1</td>' +
                '<td data-label="Cost" class="ui-num">' + naira(s.cost) + '</td><td data-label=""></td></tr>';
        });
        logged = {};
        (manual || []).forEach(function (m) {
            logged[m.id] = m;
            var count = m.effective_recipients != null ? m.effective_recipients : m.recipient_count;
            var cost = m.effective_cost != null ? m.effective_cost : m.total_cost;
            var asOf = !m.is_manual && m.computed_as_of ? ' <span class="ui-meta">as of ' + esc(m.computed_as_of) + '</span>' : '';
            h += '<tr><td data-label="Campaign"><span class="ui-table__primary">' + esc(m.campaign_label) + '</span></td>' +
                '<td data-label="Sent" class="ui-num">' + esc(m.sent_date || '—') + '</td>' +
                '<td data-label="Recipients" class="ui-num">' + num(count) + asOf + '</td>' +
                '<td data-label="Pages" class="ui-num">' + num(m.pages) + '</td>' +
                '<td data-label="Cost" class="ui-num">' + naira(cost) + '</td>' +
                '<td data-label=""><div class="flex justify-end gap-1">' +
                (cfg.canEdit ? '<button type="button" class="ui-btn ui-btn--ghost ui-btn--sm ui-btn--icon" data-action="er.sms-edit" data-id="' + Number(m.id) + '" aria-label="Edit ' + esc(m.campaign_label) + '" data-tooltip="Edit">' + HOD.icon('pencil') + '</button>' : '') +
                (cfg.canDelete ? '<button type="button" class="ui-btn ui-btn--danger-ghost ui-btn--sm ui-btn--icon" data-action="er.sms-delete" data-id="' + Number(m.id) + '" data-label="' + esc(m.campaign_label) + '" aria-label="Delete ' + esc(m.campaign_label) + '" data-tooltip="Delete">' + HOD.icon('trash-2') + '</button>' : '') +
                '</div></td></tr>';
        });
        $('#er-sms-rows').innerHTML = h || '<tr><td colspan="6"><div class="ui-empty ui-empty--compact"><p class="ui-empty__title">No SMS logged for this event</p></div></td></tr>';
    }

    function charts(d) {
        var days = d.attendance_by_day || [];
        HOD.chart($('#er-att-chart'), {
            type: 'bar',
            data: { labels: days.map(function (x) { return 'Day ' + x.day; }), datasets: [{ label: 'Attended', data: days.map(function (x) { return x.count; }), backgroundColor: HOD.palette[0], borderRadius: 6 }] },
            options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
        }).catch(function () {});
        var src = d.registration_sources || {}, labels = d.source_labels || {};
        var keys = Object.keys(src).filter(function (k) { return src[k] > 0; });
        HOD.chart($('#er-src-chart'), {
            type: 'doughnut',
            data: { labels: keys.map(function (k) { return labels[k] || k; }), datasets: [{ data: keys.map(function (k) { return src[k]; }), backgroundColor: HOD.palette, borderWidth: 0 }] },
            options: { cutout: '60%', plugins: { legend: { position: 'bottom' } } }
        }).catch(function () {});
    }

    function load() {
        if (!eventId) return;
        var mine = ++seq;
        call('report_data', { event_id: eventId }).then(function (res) {
            if (mine !== seq) return;
            var d = res.data;
            summary(res);
            set('cong', num(d.congregation));
            set('reg', num(d.registration_total));
            set('att', num(d.attendance_total));
            set('idi', num(d.idi_total));
            set('idiAtt', num(d.idi_attended));
            set('cost', naira(d.sms.total_cost));
            set('regBefore', num(d.registered_before_event));
            set('regOnDay', num(d.registered_on_event_days));
            set('attPrior', num(d.attended_from_prior_registration));
            set('attMem', num(d.attendance_members));
            set('attWalk', num(d.attendance_walkins));
            set('idiContacted', num(d.idi_contacted));
            $('#er-sms-kpis').innerHTML = [
                ['Reach', d.sms.reach_total, 'Unique numbers on file'],
                ['From SMS Studio', d.sms.system_count, 'Sends logged'],
                ['Logged here', (d.sms.manual || []).length, 'Campaigns'],
                ['Their recipients', d.sms.manual_total_recipients, 'Logged here']
            ].map(function (k) {
                return '<div><p class="ui-meta">' + esc(k[0]) + '</p><p class="ui-num text-xl font-semibold text-ink">' + num(k[1]) + '</p><p class="ui-meta">' + esc(k[2]) + '</p></div>';
            }).join('');
            smsRows(d.sms.system_campaigns, d.sms.manual);
            charts(d);
        }).catch(function () {});
    }

    HOD.actions.on('er.event', function (sel) {
        eventId = sel.value;
        $('#er-none').hidden = !!eventId;
        $('#er-body').hidden = !eventId;
        var pdf = $('#er-pdf');
        if (pdf) { pdf.hidden = !eventId; pdf.href = eventId ? PDF + '?event_id=' + encodeURIComponent(eventId) : '#'; }
        load();
    });

    function openLog(btn, row) {
        var form = $('#er-sms-form');
        form.reset();
        HOD.form.clearErrors(form);
        form.elements.id.value = row ? row.id : '';
        if (row) {
            ['campaign_label', 'sent_date', 'audience_label', 'recipient_count', 'characters', 'pages', 'unit_cost', 'notes'].forEach(function (k) {
                if (form.elements[k] && row[k] != null) form.elements[k].value = String(row[k]).slice(0, k === 'sent_date' ? 10 : 255);
            });
        }
        $('#er-sms .ui-dialog__title').textContent = row ? 'Edit SMS campaign' : 'Log SMS campaign';
        HOD.dialog.open('er-sms', btn);
    }
    HOD.actions.on('er.sms-add', function (btn) { openLog(btn, null); });
    HOD.actions.on('er.sms-edit', function (btn) { openLog(btn, logged[btn.getAttribute('data-id')] || null); });

    HOD.actions.on('er.sms-save', function (form) {
        var btn = $('#er-sms-go');
        var data = HOD.form.values(form);
        data.event_id = eventId;
        HOD.busy(btn, true);
        call('save_sms_log', data).then(function (res) {
            HOD.dialog.close('er-sms');
            HOD.toast(res.message || 'Campaign saved.', 'success');
            load();
        }).catch(function () {}).finally(function () { HOD.busy(btn, false); });
    });

    HOD.actions.on('er.sms-delete', function (btn) {
        HOD.confirm({ title: 'Delete this campaign?', message: btn.getAttribute('data-label'), confirmLabel: 'Delete', tone: 'danger' }).then(function (ok) {
            if (!ok) return;
            call('delete_sms_log', { id: btn.getAttribute('data-id') }).then(function (res) {
                HOD.toast(res.message || 'Campaign deleted.', 'success');
                load();
            }).catch(function () {});
        });
    });

    call('list_events').then(function (res) {
        $('#er-event').innerHTML = '<option value="">Choose an event</option>' + (res.data || []).map(function (e) {
            var d = e.event_date ? new Date(String(e.event_date).replace(' ', 'T')).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }) : '';
            return '<option value="' + Number(e.id) + '">' + esc(e.title + (d ? ' · ' + d : '')) + '</option>';
        }).join('');
    }).catch(function () {});
})();
