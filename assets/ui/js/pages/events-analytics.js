// /assets/ui/js/pages/events-analytics.js
// The Events page's Analytics tab: Sunday and Midweek service attendance over
// a period (counts, trend, gender, status, region, the services table), a
// service's own detail, comparing two periods, and a CSV of what is loaded.
// Data: /api/event_analytics_api.php (the attendance roster; never the
// registrations). Charts: HOD.chart (the vendored Chart.js).
(function () {
    'use strict';

    var EV = window.HODEvents;
    if (!EV) return;
    var $ = EV.$, $$ = EV.$$, esc = HOD.esc, num = EV.num;
    var API = '/api/event_analytics_api.php';
    var NAVY = HOD.palette[0], RED = HOD.palette[1], GREY = '#9AA2B2';
    var PER_PAGE = 15;

    var an = {
        started: false,
        category: 'Midweek_Service',
        period: { Midweek_Service: { preset: '12m', start: null, end: null }, Sunday_Service: { preset: '12m', start: null, end: null } },
        overview: null,
        view: { gender: 'totals', status: 'totals' },
        sort: { col: 'event_date', dir: 'desc' },
        page: 1,
        userGranularity: false,
        roster: [],
        seq: 0
    };

    function api(action, data, opts) { return HOD.api(API, Object.assign({ action: action, data: data || {} }, opts || {})); }
    function short(d) { return EV.parseDate(d).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }); }
    function full(d) { return EV.parseDate(d).toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' }); }
    function iso(d) { return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); }
    function trim(t) { return t && t.length > 30 ? t.slice(0, 28) + '…' : (t || ''); }
    function pct(from, to) { return from ? Math.round((to - from) / from * 1000) / 10 : null; }
    function monthsAgo(n) { var d = new Date(); d.setMonth(d.getMonth() - n); return iso(d); }
    function catName(c) { return c === 'Sunday_Service' ? 'Sunday Service' : 'Midweek Service'; }

    function legend() { return { position: 'bottom' }; }
    function countAxis(stacked) { return { beginAtZero: true, stacked: !!stacked, ticks: { precision: 0 } }; }

    /* ---------- Starting, the service and the period ---------- */

    document.addEventListener('ui:tab', function (e) {
        if (e.detail && e.detail.tab === 'analytics' && !an.started) {
            an.started = true;
            setCategory('Midweek_Service');
        }
    });
    if (!$('#panel-analytics').hidden) { an.started = true; setCategory('Midweek_Service'); }

    function setCategory(cat) {
        an.category = cat;
        $$('[data-action="an.category"]').forEach(function (b) { b.setAttribute('aria-pressed', b.getAttribute('data-category') === cat ? 'true' : 'false'); });
        var st = an.period[cat];
        $('#an-preset').value = st.preset;
        if (st.start) $('#an-start').value = st.start;
        if (st.end) $('#an-end').value = st.end;
        presetChanged(false);
        load();
    }
    HOD.actions.on('an.category', function (b) { setCategory(b.getAttribute('data-category')); });

    function presetChanged(reload) {
        var v = $('#an-preset').value;
        an.period[an.category].preset = v;
        var custom = v === 'custom';
        $('#an-custom').hidden = !custom;
        if (custom) {
            if (!$('#an-start').value) { $('#an-start').value = monthsAgo(12); $('#an-end').value = iso(new Date()); }
            return; // waits for Apply
        }
        if (reload !== false) load();
    }
    HOD.actions.on('an.preset', function () { presetChanged(true); });
    HOD.actions.on('an.apply', function () {
        var s = $('#an-start').value, e = $('#an-end').value;
        if (!s || !e) { HOD.toast('Pick a start and an end date.', 'warning'); return; }
        an.period[an.category].start = s;
        an.period[an.category].end = e;
        load();
    });
    HOD.actions.on('an.granularity', function () { an.userGranularity = true; load(); });

    function range() {
        var st = an.period[an.category];
        var out = { start: null, end: iso(new Date()), limit: 0 };
        switch (st.preset) {
            case '8': out.limit = 8; break;
            case '3m': out.start = monthsAgo(3); break;
            case '6m': out.start = monthsAgo(6); break;
            case 'ytd': out.start = new Date().getFullYear() + '-01-01'; break;
            case 'all': out.start = '2000-01-01'; break;
            case 'custom': out.start = st.start; out.end = st.end; break;
            default: out.start = monthsAgo(12);
        }
        if (!out.start && !out.limit) out.start = monthsAgo(12);
        return out;
    }

    function granularity(r) {
        var sel = $('#an-granularity');
        if (an.userGranularity) return sel.value;
        var g = r.limit === 8 ? 'service' : ((r.start ? (new Date(r.end) - new Date(r.start)) / 86400000 : 400) <= 120 ? 'week' : 'month');
        sel.value = g;
        return g;
    }

    function show(which) {
        ['loading', 'empty', 'error', 'content'].forEach(function (k) { $('#an-' + k).hidden = k !== which; });
    }

    function load() {
        var r = range();
        var payload = { category: an.category, granularity: granularity(r) };
        if (r.limit) payload.limit = r.limit; else { payload.start_date = r.start; payload.end_date = r.end; }
        var seq = ++an.seq;
        show('loading');
        api('overview', payload, { quiet: true }).then(function (res) {
            if (seq !== an.seq) return;
            an.overview = res;
            if (!res.events || !res.events.length) { show('empty'); $('#an-summary').textContent = ''; return; }
            show('content');
            $('#an-summary').textContent = 'Showing ' + EV.plural(res.events.length, 'service') + ', ' + short(res.range.start) + ' – ' + short(res.range.end) + '.';
            renderKpis(res);
            renderTrend(res);
            renderGender(res);
            renderStatus(res);
            renderRegion(res);
            an.page = 1;
            an.sort = { col: 'event_date', dir: 'desc' };
            $('#an-search').value = '';
            renderTable();
        }).catch(function (err) {
            if (seq !== an.seq) return;
            $('#an-error').innerHTML = '<div class="ui-banner ui-banner--danger" role="alert">' + HOD.icon('circle-alert') + '<div class="ui-banner__body"><p class="ui-banner__text">' +
                esc(err.message || 'Analytics did not load.') + '</p></div></div>';
            show('error');
        });
    }

    /* ---------- Counts ---------- */

    function stat(label, value, sub, badge, eventId) {
        var inner = '<span class="ui-stat__label">' + esc(label) + '</span><span class="ui-stat__value ui-num">' + value + '</span>' +
            '<span class="ui-stat__meta flex flex-wrap items-center gap-1">' + (sub || '') + (badge ? ' ' + badge : '') + '</span>';
        return eventId
            ? '<button type="button" class="ui-card ui-stat text-left" data-action="an.event" data-id="' + Number(eventId) + '" aria-haspopup="dialog">' + inner + '</button>'
            : '<div class="ui-card ui-stat">' + inner + '</div>';
    }

    function renderKpis(o) {
        var k = o.kpis;
        $('#an-kpis').innerHTML = [
            stat('Services held', num(k.services_count), esc(o.category_label)),
            stat('Total attendance', num(k.total_attendance), esc(EV.plural(k.services_count, 'service')), EV.delta(k.growth_pct)),
            stat('Average', num(k.avg_attendance), k.baseline_avg !== null ? 'Usual ' + num(k.baseline_avg) : 'No baseline yet', k.baseline_avg !== null ? EV.delta(k.avg_vs_baseline) : ''),
            stat('Highest', num(k.peak.total), esc(trim(k.peak.title) + ' · ' + short(k.peak.date)), null, k.peak.id),
            stat('Lowest', num(k.lowest.total), esc(trim(k.lowest.title) + ' · ' + short(k.lowest.date)), null, k.lowest.id),
            stat('First timers', num(k.first_timers_total), esc(k.first_timer_rate + '% of attendance'))
        ].join('');
    }

    /* ---------- Charts ---------- */

    function renderTrend(o) {
        var t = o.trend;
        var totals = t.map(function (b) { return b.total; });
        var avg = totals.length ? totals.reduce(function (a, b) { return a + b; }, 0) / totals.length : 0;
        $('#an-trend-caption').textContent = EV.plural(t.length, 'point') + ' · ' + (o.range.granularity === 'service' ? 'per service' : 'by ' + o.range.granularity);
        HOD.chart($('#an-trend'), {
            type: 'line',
            data: {
                labels: t.map(function (b) { return b.label; }),
                datasets: [
                    { label: 'Attendance', data: totals, borderColor: NAVY, backgroundColor: 'rgba(29,53,106,0.08)', fill: true, pointRadius: 4, pointHoverRadius: 6, pointBackgroundColor: NAVY },
                    { label: 'Average', data: totals.map(function () { return Math.round(avg * 10) / 10; }), borderColor: GREY, borderDash: [6, 6], borderWidth: 1.5, pointRadius: 0, fill: false, tension: 0 }
                ]
            },
            options: {
                plugins: { legend: { position: 'top' } },
                scales: { y: countAxis() },
                onClick: function (evt, els) {
                    if (!els.length) return;
                    var bucket = t[els[0].index];
                    if (o.range.granularity === 'service') openEvent(bucket.event_ids[0]);
                    else openPeriod(bucket);
                },
                onHover: function (evt, els) { if (evt.native && evt.native.target) evt.native.target.style.cursor = els.length ? 'pointer' : 'default'; }
            }
        }).catch(function () {});
    }

    function setView(kind, view) {
        an.view[kind] = view;
        $$('[data-action="an.view"][data-kind="' + kind + '"]').forEach(function (b) { b.setAttribute('aria-pressed', b.getAttribute('data-view') === view ? 'true' : 'false'); });
        if (!an.overview) return;
        if (kind === 'gender') renderGender(an.overview); else renderStatus(an.overview);
    }
    HOD.actions.on('an.view', function (b) { setView(b.getAttribute('data-kind'), b.getAttribute('data-view')); });

    function renderGender(o) {
        var cfg;
        if (an.view.gender === 'totals') {
            var g = o.gender_totals;
            var labels = ['Male', 'Female'], data = [g.Male, g.Female], colors = [NAVY, RED];
            if (g.Unknown > 0) { labels.push('Not on file'); data.push(g.Unknown); colors.push(GREY); }
            cfg = { type: 'doughnut', data: { labels: labels, datasets: [{ data: data, backgroundColor: colors, borderWidth: 0 }] }, options: { cutout: '62%', plugins: { legend: legend() } } };
        } else {
            cfg = {
                type: 'bar',
                data: { labels: o.trend.map(function (b) { return b.label; }), datasets: [
                    { label: 'Male', data: o.trend.map(function (b) { return b.male; }), backgroundColor: NAVY, borderRadius: 4 },
                    { label: 'Female', data: o.trend.map(function (b) { return b.female; }), backgroundColor: RED, borderRadius: 4 }
                ] },
                options: { scales: { x: { stacked: true }, y: countAxis(true) }, plugins: { legend: legend() } }
            };
        }
        HOD.chart($('#an-gender'), cfg).catch(function () {});
    }

    function renderStatus(o) {
        var keys = Object.keys(o.status_totals || {}).filter(function (k) { return o.status_totals[k] > 0; });
        if (!keys.length) {
            var old = window.Chart && window.Chart.getChart($('#an-status'));
            if (old) old.destroy();
            return;
        }
        var cfg = an.view.status === 'totals'
            ? { type: 'doughnut', data: { labels: keys.map(function (k) { return o.status_labels[k]; }), datasets: [{ data: keys.map(function (k) { return o.status_totals[k]; }), backgroundColor: keys.map(function (k) { return o.status_colors[k]; }), borderWidth: 0 }] }, options: { cutout: '62%', plugins: { legend: legend() } } }
            : {
                type: 'bar',
                data: { labels: o.trend.map(function (b) { return b.label; }), datasets: keys.map(function (k) {
                    return { label: o.status_labels[k], data: o.trend.map(function (b) { return (b.status && b.status[k]) || 0; }), backgroundColor: o.status_colors[k] };
                }) },
                options: { scales: { x: { stacked: true }, y: countAxis(true) }, plugins: { legend: legend() } }
            };
        HOD.chart($('#an-status'), cfg).catch(function () {});
    }

    function renderRegion(o) {
        var entries = Object.entries(o.region_totals || {}).slice(0, 10);
        $('#an-region-card').hidden = !entries.length;
        if (!entries.length) return;
        HOD.chart($('#an-region'), {
            type: 'bar',
            data: { labels: entries.map(function (e) { return e[0]; }), datasets: [{ data: entries.map(function (e) { return e[1]; }), backgroundColor: NAVY, borderRadius: 6 }] },
            options: { indexAxis: 'y', plugins: { legend: { display: false } }, scales: { x: countAxis() } }
        }).catch(function () {});
    }

    /* ---------- Services table ---------- */

    HOD.actions.on('an.sort', function (b) {
        var col = b.getAttribute('data-col');
        if (an.sort.col === col) an.sort.dir = an.sort.dir === 'asc' ? 'desc' : 'asc';
        else { an.sort.col = col; an.sort.dir = 'desc'; }
        an.page = 1;
        renderTable();
    });
    HOD.actions.on('an.search', function () { an.page = 1; renderTable(); });
    HOD.actions.on('an.page', function (b) { an.page = Number(b.getAttribute('data-page')); renderTable(); });

    function renderTable() {
        if (!an.overview) return;
        var q = ($('#an-search').value || '').toLowerCase();
        var rows = an.overview.events.filter(function (e) { return !q || String(e.title).toLowerCase().indexOf(q) > -1 || short(e.event_date).toLowerCase().indexOf(q) > -1; });
        var col = an.sort.col, dir = an.sort.dir === 'asc' ? 1 : -1;
        rows = rows.slice().sort(function (a, b) {
            var av = a[col], bv = b[col];
            if (col === 'event_date') { av = EV.parseDate(a.event_date).getTime(); bv = EV.parseDate(b.event_date).getTime(); }
            if (col === 'vs_previous') { av = av == null ? -Infinity : av; bv = bv == null ? -Infinity : bv; }
            return av < bv ? -dir : (av > bv ? dir : 0);
        });
        $$('[data-an-sort]').forEach(function (s) {
            var on = s.getAttribute('data-an-sort') === col;
            s.innerHTML = on ? HOD.icon(an.sort.dir === 'asc' ? 'chevron-up' : 'chevron-down', 'ui-icon--xs') : '';
            var th = s.closest('th');
            if (on) th.setAttribute('aria-sort', an.sort.dir === 'asc' ? 'ascending' : 'descending'); else th.removeAttribute('aria-sort');
        });

        var total = rows.length;
        var pages = Math.max(1, Math.ceil(total / PER_PAGE));
        if (an.page > pages) an.page = pages;
        var start = (an.page - 1) * PER_PAGE;
        var pageRows = rows.slice(start, start + PER_PAGE);

        $('#an-rows').innerHTML = pageRows.length ? pageRows.map(function (e) {
            return '<tr>' +
                '<td data-label="Date" class="ui-num whitespace-nowrap">' + esc(short(e.event_date)) + '</td>' +
                '<td data-label="Title"><span class="ui-table__primary">' + esc(e.title) + '</span></td>' +
                '<td data-label="Attendance" class="ui-num font-semibold">' + num(e.total) + '</td>' +
                '<td data-label="Male / female" class="ui-num"><span class="text-primary">' + num(e.male) + '</span> / <span class="text-brand-red">' + num(e.female) + '</span></td>' +
                '<td data-label="1st timers" class="ui-num">' + num(e.first_timers) + '</td>' +
                '<td data-label="Workers" class="ui-num">' + num(e.workers) + '</td>' +
                '<td data-label="vs previous">' + EV.delta(e.vs_previous) + '</td>' +
                '<td data-label="Details"><button type="button" class="ui-btn ui-btn--ghost ui-btn--sm ui-btn--icon" data-action="an.event" data-id="' + Number(e.id) + '" aria-label="Details of ' + esc(e.title) + '" data-tooltip="Details">' + HOD.icon('eye') + '</button></td>' +
            '</tr>';
        }).join('') : '<tr><td colspan="8">' + EV.emptyHtml('search', 'No matching services', '') + '</td></tr>';

        $('#an-page-info').textContent = 'Showing ' + (total ? start + 1 : 0) + '–' + Math.min(start + PER_PAGE, total) + ' of ' + total;
        var html = '';
        for (var p = 1; p <= pages; p++) {
            if (pages > 7 && Math.abs(p - an.page) > 2 && p !== 1 && p !== pages) {
                if (p === 2 || p === pages - 1) html += '<span class="px-1 text-ink-subtle" aria-hidden="true">…</span>';
                continue;
            }
            html += '<button type="button" class="ui-btn ui-btn--sm ' + (p === an.page ? 'ui-btn--primary' : 'ui-btn--secondary') + ' ui-num" data-action="an.page" data-page="' + p + '"' +
                (p === an.page ? ' aria-current="page"' : '') + ' aria-label="Page ' + p + '">' + p + '</button>';
        }
        $('#an-pages').innerHTML = pages > 1 ? html : '';
    }

    /* ---------- A period (a point on the trend) ---------- */

    function openPeriod(bucket) {
        var events = an.overview.events.filter(function (e) { return bucket.event_ids.indexOf(e.id) !== -1; });
        var total = events.reduce(function (s, e) { return s + e.total; }, 0);
        var avg = events.length ? Math.round(total / events.length * 10) / 10 : 0;
        $('#an-period-title').textContent = bucket.label;
        $('#an-period-desc').textContent = an.overview.category_label + ' · ' + EV.plural(events.length, 'service') + ' · ' + num(total) + ' in total · average ' + avg;
        $('#an-period-body').innerHTML = events.map(function (e) {
            return '<li><button type="button" class="ui-list__item w-full text-left hover:bg-surface-hover" data-action="an.period-event" data-id="' + Number(e.id) + '">' +
                '<span class="ui-list__main"><span class="ui-list__title">' + esc(e.title) + '</span><span class="ui-list__meta">' + esc(short(e.event_date) + (e.location ? ' · ' + e.location : '')) + '</span></span>' +
                '<span class="ui-list__end flex flex-col items-end"><span class="ui-num font-semibold text-ink">' + num(e.total) + '</span>' + EV.delta(e.vs_previous) + '</span></button></li>';
        }).join('');
        HOD.dialog.open('an-period');
    }
    HOD.actions.on('an.period-event', function (b) {
        HOD.dialog.close('an-period');
        openEvent(b.getAttribute('data-id'));
    });

    /* ---------- One service ---------- */

    HOD.actions.on('an.event', function (b) { openEvent(b.getAttribute('data-id'), b); });

    function openEvent(id, opener) {
        if (!id) return;
        $('#an-event-title').textContent = 'Service';
        $('#an-event-desc').textContent = 'Loading…';
        $('#an-event-body').innerHTML = '<div class="flex justify-center py-16"><span class="ui-spinner ui-spinner--lg" aria-hidden="true"></span></div>';
        if (!$('#an-event').open) HOD.dialog.open('an-event', opener);
        api('event_detail', { event_id: id }, { quiet: true }).then(renderEvent).catch(function (err) {
            $('#an-event-desc').textContent = '';
            $('#an-event-body').innerHTML = '<div class="ui-banner ui-banner--danger" role="alert">' + HOD.icon('circle-alert') + '<div class="ui-banner__body"><p class="ui-banner__text">' +
                esc(err.message || 'This service did not load.') + '</p></div></div>';
        });
    }

    function chartBox(id, title, infoText, hasData, emptyText, height) {
        return '<section class="rounded-ui border border-line p-3"><div class="mb-2 flex items-center gap-1"><h3 class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">' + esc(title) + '</h3>' +
            (infoText ? EV.info(infoText, 'About ' + title.toLowerCase()) : '') + '</div><div class="relative ' + (height || 'h-40') + '">' +
            (hasData ? '<canvas id="' + id + '" role="img" aria-label="' + esc(title) + ' chart"></canvas>' : '<p class="grid h-full place-items-center px-4 text-center text-sm text-ink-subtle">' + esc(emptyText) + '</p>') +
            '</div></section>';
    }

    function renderEvent(res) {
        var e = res.event, s = res.stats;
        $('#an-event-title').textContent = e.title;
        $('#an-event-desc').textContent = [e.category_label, full(e.event_date), e.location].filter(Boolean).join(' · ');
        var hasRegion = Object.keys(s.region || {}).length > 0;
        var prev = res.previous
            ? stat('vs previous', num(res.previous.total), esc(short(res.previous.date)), EV.delta(res.previous.delta_pct))
            : stat('vs previous', '—', 'No earlier service');
        var base = res.baseline_avg !== null ? stat('vs usual', num(res.baseline_avg), '12-service average', EV.delta(pct(res.baseline_avg, s.total))) : '';
        var notes = String(e.report_notes || '').replace(/<[^>]*>/g, '').trim();
        var notesHtml = '';
        if (notes) {
            var long = notes.length > 160;
            notesHtml = '<section class="rounded-ui border border-line bg-surface-sunken p-3"><h3 class="mb-1 text-xs font-semibold uppercase tracking-wide text-ink-subtle">Report notes</h3>' +
                '<p class="whitespace-pre-line text-sm text-ink" id="an-notes">' + esc(long ? notes.slice(0, 160) + '…' : notes) + '</p>' +
                (long ? '<button type="button" class="ui-link mt-1 text-sm" data-action="an.notes">Read all</button>' : '') + '</section>';
            an.notes = notes;
        }
        $('#an-event-body').innerHTML =
            (e.ministers && e.ministers.length ? '<div class="ui-chips flex-wrap">' + e.ministers.map(function (m) { return '<span class="ui-chip">' + esc(m.name || '') + '</span>'; }).join('') + '</div>' : '') +
            '<div class="grid grid-cols-2 gap-3 md:grid-cols-4">' +
                stat('Attendance', num(s.total)) +
                stat('Male / female', '<span class="text-primary">' + num(s.male) + '</span> / <span class="text-brand-red">' + num(s.female) + '</span>') +
                stat('1st timers', num(s.first_timers)) + stat('Workers', num(s.workers)) + prev + base +
            '</div>' +
            '<div class="grid gap-3 md:grid-cols-2">' +
                chartBox('an-ev-spark', 'Last 8 services', 'The last 8 services of this kind, with this one marked in red.', true) +
                chartBox('an-ev-pace', 'Check-in pace', 'How attendance built up hour by hour, from the time each person was marked present.', res.pace.has_data, 'No timed check-ins for this service.') +
            '</div><div class="grid gap-3 md:grid-cols-2">' +
                chartBox('an-ev-status', 'Who attended', '', true, '', 'h-48') +
                chartBox('an-ev-region', 'By region', '', hasRegion, 'No region data.', 'h-48') +
            '</div>' + notesHtml +
            '<section><button type="button" class="flex w-full items-center gap-2 rounded-ui border border-line bg-surface-sunken px-4 py-3 text-left" data-action="an.roster" aria-expanded="false" aria-controls="an-roster">' +
                '<span class="flex-1 text-sm font-semibold text-ink">Attendees</span><span class="ui-badge ui-num">' + num(res.roster.length) + '</span>' + HOD.icon('chevron-down', 'transition-transform') + '</button>' +
                '<div class="mt-3 space-y-2" id="an-roster" hidden><div class="ui-search">' + HOD.icon('search') + '<label class="sr-only" for="an-roster-q">Search attendees</label>' +
                '<input class="ui-input" type="search" id="an-roster-q" placeholder="Name or phone" autocomplete="off" data-action-input="an.roster-search"></div>' +
                '<ul class="ui-list max-h-72 overflow-y-auto rounded-ui border border-line" id="an-roster-list"></ul></div></section>';
        an.roster = res.roster || [];
        renderRoster();

        HOD.chart($('#an-ev-spark'), {
            type: 'line',
            data: { labels: res.sparkline.map(function (p) { return short(p.date); }), datasets: [{ data: res.sparkline.map(function (p) { return p.total; }), borderColor: NAVY, backgroundColor: 'rgba(29,53,106,0.08)', fill: true, pointRadius: 3,
                pointBackgroundColor: res.sparkline.map(function (p) { return p.id === e.id ? RED : NAVY; }) }] },
            options: { plugins: { legend: { display: false } }, scales: { y: countAxis() } }
        }).catch(function () {});
        if (res.pace.has_data) {
            HOD.chart($('#an-ev-pace'), {
                type: 'bar',
                data: { labels: res.pace.hours.map(function (h) { return h.label; }), datasets: [{ data: res.pace.hours.map(function (h) { return h.count; }), backgroundColor: RED, borderRadius: 4 }] },
                options: { plugins: { legend: { display: false } }, scales: { y: countAxis() } }
            }).catch(function () {});
        }
        var sk = Object.keys(s.status || {}).filter(function (k) { return s.status[k] > 0; });
        HOD.chart($('#an-ev-status'), {
            type: 'doughnut',
            data: { labels: sk.map(function (k) { return res.status_labels[k]; }), datasets: [{ data: sk.map(function (k) { return s.status[k]; }), backgroundColor: sk.map(function (k) { return res.status_colors[k]; }), borderWidth: 0 }] },
            options: { cutout: '60%', plugins: { legend: legend() } }
        }).catch(function () {});
        if (hasRegion) {
            var re = Object.entries(s.region).slice(0, 8);
            HOD.chart($('#an-ev-region'), {
                type: 'doughnut',
                data: { labels: re.map(function (x) { return x[0]; }), datasets: [{ data: re.map(function (x) { return x[1]; }), backgroundColor: HOD.palette, borderWidth: 0 }] },
                options: { cutout: '60%', plugins: { legend: legend() } }
            }).catch(function () {});
        }
    }

    HOD.actions.on('an.notes', function (b) { $('#an-notes').textContent = an.notes || ''; b.remove(); });
    HOD.actions.on('an.roster', function (b) {
        var open = b.getAttribute('aria-expanded') !== 'true';
        b.setAttribute('aria-expanded', open ? 'true' : 'false');
        $('#an-roster').hidden = !open;
        var chev = b.querySelector('.ui-icon:last-child');
        if (chev) chev.classList.toggle('rotate-180', open);
        if (open) $('#an-roster-q').focus();
    });
    HOD.actions.on('an.roster-search', renderRoster);

    var STATUS = { '1st_Timer': '1st Timer', '2nd_Timer': '2nd Timer', '3rd_Timer': '3rd Timer', Non_Member: 'Non-member', Not_On_File: 'Not on file' };
    function renderRoster() {
        var box = $('#an-roster-list');
        if (!box) return;
        var q = (($('#an-roster-q') || {}).value || '').toLowerCase();
        var rows = an.roster.filter(function (r) {
            return !q || ((r.first_name || '') + ' ' + (r.last_name || '')).toLowerCase().indexOf(q) > -1 || String(r.phone || '').indexOf(q) > -1;
        });
        box.innerHTML = rows.length ? rows.map(function (r) {
            return '<li class="ui-list__item"><span class="ui-list__main"><span class="ui-list__title">' + esc(((r.first_name || '') + ' ' + (r.last_name || '')).trim()) + '</span>' +
                '<span class="ui-list__meta">' + esc((r.phone || '—') + (r.region_name ? ' · ' + r.region_name : '')) + '</span></span><span class="ui-list__end flex gap-1">' +
                (r.gender ? '<span class="ui-badge ' + (r.gender === 'Male' ? 'ui-badge--info' : 'ui-badge--brand') + '">' + esc(r.gender) + '</span>' : '') +
                '<span class="ui-badge">' + esc(STATUS[r.spiritual_status] || EV.statusLabel(r.spiritual_status)) + '</span></span></li>';
        }).join('') : '<li class="ui-list__item"><span class="ui-meta">No matches.</span></li>';
    }

    /* ---------- Compare two periods ---------- */

    HOD.actions.on('an.compare', function (b) {
        $('#an-compare-title').textContent = 'Compare · ' + catName(an.category);
        $('#an-compare-results').hidden = true;
        $('#an-compare-results').innerHTML = '';
        HOD.dialog.open('an-compare', b);
    });

    function setCompare(aS, aE, bS, bE) { $('#an-a-start').value = aS; $('#an-a-end').value = aE; $('#an-b-start').value = bS; $('#an-b-end').value = bE; }

    HOD.actions.on('an.preset-compare', function (b) {
        var now = new Date(), y = now.getFullYear(), m = now.getMonth();
        switch (b.getAttribute('data-preset')) {
            case 'months': setCompare(iso(new Date(y, m, 1)), iso(now), iso(new Date(y, m - 1, 1)), iso(new Date(y, m, 0))); break;
            case 'quarters': var q = Math.floor(m / 3); setCompare(iso(new Date(y, q * 3, 1)), iso(now), iso(new Date(y, (q - 1) * 3, 1)), iso(new Date(y, q * 3, 0))); break;
            case 'years': setCompare(y + '-01-01', iso(now), (y - 1) + '-01-01', (y - 1) + '-12-31'); break;
            case 'last4':
                if (!an.overview || !an.overview.events.length) { HOD.toast('Load some services on this tab first.', 'warning'); return; }
                var all = an.overview.events.slice().sort(function (a, c) { return EV.parseDate(a.event_date) - EV.parseDate(c.event_date); });
                var last4 = all.slice(-4), prev4 = all.slice(-8, -4);
                var aS = last4[0].event_date.slice(0, 10), aE = last4[last4.length - 1].event_date.slice(0, 10);
                setCompare(aS, aE, prev4.length ? prev4[0].event_date.slice(0, 10) : aS, prev4.length ? prev4[prev4.length - 1].event_date.slice(0, 10) : aS);
                break;
        }
    });

    HOD.actions.on('an.run-compare', function (b) {
        var d = { category: an.category, a_start: $('#an-a-start').value, a_end: $('#an-a-end').value, b_start: $('#an-b-start').value, b_end: $('#an-b-end').value };
        if (!d.a_start || !d.a_end || !d.b_start || !d.b_end) { HOD.toast('Fill in both periods.', 'warning'); return; }
        HOD.busy(b, true);
        api('compare', d).then(renderCompare).catch(function () {}).finally(function () { HOD.busy(b, false); });
    });

    function renderCompare(res) {
        var A = res.a, B = res.b, D = res.deltas;
        var row = function (label, av, bv, dv) {
            return '<tr><td data-label="Measure">' + esc(label) + '</td><td data-label="A" class="ui-num font-semibold text-primary">' + av + '</td>' +
                '<td data-label="B" class="ui-num font-semibold text-brand-red">' + bv + '</td><td data-label="A to B">' + EV.delta(dv) + '</td></tr>';
        };
        var box = $('#an-compare-results');
        box.innerHTML =
            '<div class="grid grid-cols-2 gap-3 text-center">' +
                '<div class="rounded-ui bg-primary-soft p-3"><p class="text-xs font-semibold uppercase text-primary">Period A</p><p class="ui-meta">' + esc(short(A.label_start) + ' – ' + short(A.label_end)) + '</p></div>' +
                '<div class="rounded-ui bg-danger-soft p-3"><p class="text-xs font-semibold uppercase text-brand-red">Period B</p><p class="ui-meta">' + esc(short(B.label_start) + ' – ' + short(B.label_end)) + '</p></div>' +
            '</div>' +
            '<div class="ui-table-wrap"><table class="ui-table ui-table--compact ui-table--stack"><thead><tr><th scope="col">Measure</th><th scope="col">A</th><th scope="col">B</th><th scope="col">A to B</th></tr></thead><tbody>' +
                row('Services held', num(A.services_count), num(B.services_count), D.services_count) +
                row('Total attendance', num(A.total_attendance), num(B.total_attendance), D.total_attendance) +
                row('Average', num(A.avg_attendance), num(B.avg_attendance), D.avg_attendance) +
                row('First timers', num(A.first_timers), num(B.first_timers), D.first_timers) +
                row('Workers present', num(A.workers), num(B.workers), D.workers) +
            '</tbody></table></div>' +
            '<div class="relative h-56"><canvas id="an-compare-chart" role="img" aria-label="Comparison chart"></canvas></div>';
        box.hidden = false;
        HOD.chart($('#an-compare-chart'), {
            type: 'bar',
            data: { labels: ['Total attendance', 'Average', 'First timers', 'Workers'], datasets: [
                { label: 'Period A', data: [A.total_attendance, A.avg_attendance, A.first_timers, A.workers], backgroundColor: NAVY, borderRadius: 6 },
                { label: 'Period B', data: [B.total_attendance, B.avg_attendance, B.first_timers, B.workers], backgroundColor: RED, borderRadius: 6 }
            ] },
            options: { plugins: { legend: legend() }, scales: { y: countAxis() } }
        }).catch(function () {});
    }

    /* ---------- CSV of what is loaded ---------- */

    HOD.actions.on('an.csv', function () {
        var o = an.overview;
        if (!o || !o.events.length) { HOD.toast('Nothing to export yet.', 'warning'); return; }
        var lines = [['Date', 'Title', 'Total Attendance', 'Male', 'Female', '1st Timers', 'Workers', 'vs Previous %'].join(',')];
        o.events.forEach(function (e) {
            lines.push([short(e.event_date).replace(/,/g, ''), '"' + String(e.title).replace(/"/g, '""') + '"', e.total, e.male, e.female, e.first_timers, e.workers,
                e.vs_previous == null ? '' : e.vs_previous].join(','));
        });
        var url = URL.createObjectURL(new Blob([lines.join('\n')], { type: 'text/csv;charset=utf-8;' }));
        var a = document.createElement('a');
        a.href = url;
        a.download = o.category_label.replace(/\s+/g, '_') + '_analytics_' + o.range.start + '_to_' + o.range.end + '.csv';
        document.body.appendChild(a);
        a.click();
        a.remove();
        setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
    });
})();
