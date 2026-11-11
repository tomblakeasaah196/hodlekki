// /assets/ui/js/pages/idi.js
// Data Insights (modules/idi/index.php). One call to /api/idi_api.php
// (action fetch_all_insights, for the chosen dates) feeds the four headline
// numbers, every chart and the birthday list; Export turns the same data
// into a workbook with SheetJS, loaded only when asked for.
(function () {
    'use strict';

    var HOD = window.HOD;
    var API = '/api/idi_api.php';
    var data = null;
    var drawn = {};
    var P = HOD.palette;

    function esc(v) { return HOD.esc(v); }
    function label(s) { return String(s == null ? '' : s).replace(/_/g, ' '); }
    function sum(rows, key) { return (rows || []).reduce(function (a, r) { return a + (Number(r[key]) || 0); }, 0); }

    function kpis() {
        var el = document.querySelector('[data-idi-kpis]');
        var tiles = [
            ['People on the ladder', 'sprout', sum(data.congregation.funnel, 'total')],
            ['Serving in a department', 'building-2', sum(data.operations.department_health, 'total_workers')],
            ['Not reached (Unknown)', 'circle-alert', Number(data.charis.total_at_risk) || 0],
            ['Back after being away', 'circle-check', Number(data.charis.auto_detected_back) || 0]
        ];
        el.innerHTML = tiles.map(function (t) {
            return '<div class="ui-card ui-stat"><span class="ui-stat__label">' + HOD.icon(t[1]) + '<span>' + esc(t[0]) + '</span></span>' +
                '<span class="ui-stat__value">' + esc(HOD.format.number(t[2])) + '</span></div>';
        }).join('');
        el.removeAttribute('aria-busy');
    }

    function birthdays() {
        var list = (data.charis && data.charis.upcoming_birthdays) || [];
        var el = document.querySelector('[data-idi-bdays]');
        if (!list.length) {
            el.innerHTML = '<div class="ui-empty ui-empty--compact"><div class="ui-empty__icon">' + HOD.icon('calendar') + '</div>' +
                '<p class="ui-empty__title">No birthdays in the next 30 days</p>' +
                '<p class="ui-empty__text">Members with a date of birth on their profile appear here a month ahead.</p></div>';
            return;
        }
        el.innerHTML = '<div class="ui-table-wrap"><table class="ui-table ui-table--stack"><thead><tr>' +
            '<th scope="col">Member</th><th scope="col">Birthday</th><th scope="col">Phone</th></tr></thead><tbody>' +
            list.map(function (b) {
                var d = new Date(String(b.dob).replace(' ', 'T'));
                var day = isNaN(d) ? '' : d.toLocaleDateString('en-GB', { day: 'numeric', month: 'long' });
                return '<tr><td class="is-head"><span class="ui-table__primary">' + esc(b.first_name) + ' ' + esc(b.last_name) + '</span></td>' +
                    '<td data-label="Birthday">' + esc(day) + '</td>' +
                    '<td data-label="Phone" class="ui-num is-nowrap">' + esc(b.phone || '—') + '</td></tr>';
            }).join('') + '</tbody></table></div>';
    }

    function chart(id, cfg) {
        var canvas = document.getElementById(id);
        if (!canvas) return;
        HOD.chart(canvas, cfg).then(function (c) { drawn[id] = c; });
    }

    var noLegend = { plugins: { legend: { display: false } } };
    var valueAxis = { y: { beginAtZero: true, ticks: { precision: 0 } }, x: { grid: { display: false } } };

    function charts() {
        var funnel = data.congregation.funnel || [];
        chart('chart-cong-funnel', {
            type: 'doughnut',
            data: { labels: funnel.map(function (f) { return label(f.spiritual_status); }),
                datasets: [{ data: funnel.map(function (f) { return f.total; }), backgroundColor: HOD.ramp, borderWidth: 2, borderColor: '#FFFFFF' }] },
            options: { cutout: '64%', plugins: { legend: { position: 'right' } } }
        });

        var status = data.congregation.attendance_status || [];
        chart('chart-cong-status', {
            type: 'bar',
            data: { labels: status.map(function (s) { return label(s.attendance_status); }),
                datasets: [{ label: 'People', data: status.map(function (s) { return s.total; }), backgroundColor: P[0], maxBarThickness: 44 }] },
            options: Object.assign({ scales: valueAxis }, noLegend)
        });

        var trends = data.attendance.event_trends || [];
        chart('chart-att-trends', {
            type: 'bar',
            data: { labels: trends.map(function (e) { return e.title; }), datasets: [
                { label: 'Registered', data: trends.map(function (e) { return e.pre_registered; }), backgroundColor: '#CCD7EE', maxBarThickness: 28 },
                { label: 'Present', data: trends.map(function (e) { return e.actually_attended; }), backgroundColor: P[0], maxBarThickness: 28 }
            ] },
            options: { scales: valueAxis, interaction: { mode: 'index', intersect: false } }
        });

        var follow = data.embrace.followup_stats || [];
        var methods = [];
        follow.forEach(function (e) { if (methods.indexOf(e.followup_method) === -1) methods.push(e.followup_method); });
        function count(m, st) { var f = follow.filter(function (e) { return e.followup_method === m && e.status === st; })[0]; return f ? f.total : 0; }
        chart('chart-embrace-status', {
            type: 'bar',
            data: { labels: methods.map(label), datasets: [
                { label: 'Completed', data: methods.map(function (m) { return count(m, 'Completed'); }), backgroundColor: P[3], maxBarThickness: 44 },
                { label: 'Pending', data: methods.map(function (m) { return count(m, 'Pending'); }), backgroundColor: '#F5C5C7', maxBarThickness: 44 }
            ] },
            options: { scales: { x: { stacked: true, grid: { display: false } }, y: { stacked: true, beginAtZero: true, ticks: { precision: 0 } } } }
        });

        var depts = data.operations.department_health || [];
        chart('chart-ops-depts', {
            type: 'bar',
            data: { labels: depts.map(function (d) { return d.name; }),
                datasets: [{ label: 'Active workers', data: depts.map(function (d) { return d.total_workers; }), backgroundColor: P[0], maxBarThickness: 18 }] },
            options: Object.assign({ indexAxis: 'y', scales: { x: { beginAtZero: true, ticks: { precision: 0 } }, y: { grid: { display: false } } } }, noLegend)
        });

        var academy = data.academy.pipeline || [];
        chart('chart-ops-academy', {
            type: 'doughnut',
            data: { labels: academy.map(function (a) { return label(a.graduation_status); }),
                datasets: [{ data: academy.map(function (a) { return a.total; }), backgroundColor: HOD.ramp, borderWidth: 2, borderColor: '#FFFFFF' }] },
            options: { cutout: '64%', plugins: { legend: { position: 'bottom' } } }
        });

        var qa = data.pastoral.qa_stats || [];
        var sg = data.pastoral.suggestion_stats || [];
        function tot(rows, st) { return rows.filter(function (r) { return r.status === st; }).reduce(function (a, r) { return a + (Number(r.total) || 0); }, 0); }
        chart('chart-pastoral-status', {
            type: 'bar',
            data: { labels: ['Waiting', 'Answered or acted on'],
                datasets: [{ label: 'Items', data: [tot(qa, 'Pending') + tot(sg, 'New'), tot(qa, 'Answered') + tot(sg, 'Actioned')],
                    backgroundColor: [P[1], P[3]], maxBarThickness: 64 }] },
            options: Object.assign({ scales: valueAxis }, noLegend)
        });
    }

    function load(form, button) {
        var values = HOD.form.values(form);
        var errorEl = document.querySelector('[data-idi-error]');
        if (button) HOD.busy(button, true);
        HOD.api(API, { action: 'fetch_all_insights', data: values, quiet: true }).then(function (res) {
            data = res;
            errorEl.hidden = true;
            document.querySelector('[data-idi-range]').textContent =
                'Showing ' + HOD.format.date(values.start_date) + ' to ' + HOD.format.date(values.end_date);
            kpis();
            birthdays();
            charts();
        }).catch(function (err) {
            errorEl.hidden = false;
            errorEl.innerHTML = '<div class="ui-banner ui-banner--warning" role="alert">' + HOD.icon('cloud-off') +
                '<div class="ui-banner__body"><p class="ui-banner__title">The figures did not load</p>' +
                '<p class="ui-banner__text">' + esc(err && err.message ? err.message : 'Check your connection, then press Update again.') + '</p></div></div>';
        }).finally(function () { if (button) HOD.busy(button, false); });
    }

    function exportWorkbook(button) {
        if (!data || !data.export_data || !(data.export_data.master_roster || []).length) {
            HOD.toast('There is nothing to export for these dates yet.', 'warning');
            return;
        }
        HOD.busy(button, true);
        HOD.lib('xlsx').then(function () {
            var XLSX = window.XLSX;
            var wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, XLSX.utils.json_to_sheet(data.export_data.master_roster), 'Congregation Roster');
            XLSX.utils.book_append_sheet(wb, XLSX.utils.json_to_sheet(data.attendance.event_trends || []), 'Event Turnout');
            XLSX.utils.book_append_sheet(wb, XLSX.utils.json_to_sheet(data.charis.upcoming_birthdays || []), 'Upcoming Birthdays');
            XLSX.writeFile(wb, 'HOD_DataInsights_Master_' + new Date().toISOString().slice(0, 10) + '.xlsx');
            HOD.toast('The workbook has downloaded.', 'success');
        }).catch(function () {
            HOD.toast('The export could not start. Check your connection and try again.', 'danger');
        }).finally(function () { HOD.busy(button, false); });
    }

    HOD.ready(function () {
        var form = document.querySelector('form[data-action="idi.sync"]');
        if (!form) return;
        HOD.actions.on('idi.sync', function (f) { load(f, f.querySelector('[type="submit"]')); });
        HOD.actions.on('idi.export', exportWorkbook);
        // Chart.js sizes a canvas when it is drawn: one drawn in a hidden tab
        // has no size, so resize the charts of a tab when it is shown.
        document.addEventListener('ui:tab', function () {
            Object.keys(drawn).forEach(function (id) { if (drawn[id] && drawn[id].canvas.offsetParent) drawn[id].resize(); });
        });
        load(form, null);
    });
})();
