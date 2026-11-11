// /assets/ui/js/pages/monitor.js
// System Health (modules/monitor/index.php): the error list and each
// problem's detail with "Copy for AI", the health checks, real-user speed,
// uptime, and the settings. Data from /api/monitor_api.php through HOD.api.
//
// Everything that came from an error (titles, messages, stacks, pages,
// browsers) is put into the page as TEXT: a browser can send anything, so it
// never goes through innerHTML. Markup built here holds only our own words,
// numbers and HOD.esc()'d values.
(function () {
    'use strict';

    var HOD = window.HOD;
    if (!HOD || !HOD.actions) return;
    var boot = HOD.data('monitor-boot');
    if (!boot) return;
    var API = boot.api;
    var TONE = boot.states.tone;
    var WORD = boot.states.word;

    var state = {
        errors: { status: 'open', page: 1, per: 25, total: 0, loaded: false },
        speed: { device: 'phone', days: 7, loaded: false },
        uptimeLoaded: false,
        current: null,       // the problem open in the drawer
        brief: null          // its brief, once fetched
    };

    /* ------------------------------------------------------------- helpers */

    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text !== undefined && text !== null) n.textContent = String(text);
        return n;
    }

    function badge(label, tone, dot) {
        var b = el('span', 'ui-badge' + (tone && tone !== 'neutral' ? ' ui-badge--' + tone : '') + (dot ? ' ui-badge--dot' : ''), label);
        return b;
    }

    function iconEl(name) {
        var wrap = document.createElement('span');
        wrap.innerHTML = HOD.icon(name);   // our own sprite name only
        return wrap.firstChild;
    }

    function plural(n, one, many) {
        return HOD.format.number(n) + ' ' + (Number(n) === 1 ? one : (many || one + 's'));
    }

    /** "3 min ago" from a Lagos 'Y-m-d H:i:s' or a Unix time. */
    function ago(value) {
        if (value === null || value === undefined || value === '') return 'never';
        var t = typeof value === 'number' ? value * 1000 : Date.parse(String(value).replace(' ', 'T') + '+01:00');
        if (isNaN(t)) return '';
        var s = Math.max(0, Math.round((Date.now() - t) / 1000));
        if (s < 45) return 'just now';
        if (s < 5400) return Math.max(1, Math.round(s / 60)) + ' min ago';
        if (s < 129600) return Math.round(s / 3600) + ' h ago';
        return Math.round(s / 86400) + ' days ago';
    }

    var LEVEL = { fatal: 'Fatal', error: 'Error', warning: 'Warning', notice: 'Notice', deprecated: 'Deprecated', slow: 'Slow' };

    function levelBadge(level) {
        return badge(LEVEL[level] || level, levelTone(level));
    }

    function levelTone(level) {
        return level === 'fatal' || level === 'error' ? 'danger' : (level === 'warning' || level === 'slow' ? 'warning' : 'neutral');
    }

    var STATUS = { 'new': ['New', 'danger'], investigating: ['Being looked at', 'warning'], fixed: ['Fixed', 'success'], ignored: ['Ignored', 'neutral'] };

    /** Bars for a list of counts, as SVG built with the DOM (no markup from data). */
    function bars(values, label, cls) {
        var ns = 'http://www.w3.org/2000/svg';
        var svg = document.createElementNS(ns, 'svg');
        svg.setAttribute('class', cls || 'block w-full h-16 text-primary');
        svg.setAttribute('viewBox', '0 0 100 40');
        svg.setAttribute('preserveAspectRatio', 'none');
        svg.setAttribute('role', 'img');
        svg.setAttribute('aria-label', label);
        var max = Math.max.apply(null, [1].concat(values));
        var w = 100 / Math.max(1, values.length);
        values.forEach(function (v, i) {
            var h = v > 0 ? Math.max(2, 38 * v / max) : 1;
            var r = document.createElementNS(ns, 'rect');
            r.setAttribute('x', (i * w + 0.4).toFixed(2));
            r.setAttribute('y', (40 - h).toFixed(2));
            r.setAttribute('width', Math.max(0.6, w - 0.8).toFixed(2));
            r.setAttribute('height', h.toFixed(2));
            r.setAttribute('rx', '0.6');
            r.setAttribute('fill', 'currentColor');
            if (!(v > 0)) r.setAttribute('opacity', '0.25');
            var t = document.createElementNS(ns, 'title');
            t.textContent = String(v);
            r.appendChild(t);
            svg.appendChild(r);
        });
        return svg;
    }

    function empty(icon, title, text) {
        var box = el('div', 'ui-empty ui-empty--compact');
        var ic = el('div', 'ui-empty__icon');
        ic.appendChild(iconEl(icon));
        box.appendChild(ic);
        box.appendChild(el('p', 'ui-empty__title', title));
        if (text) box.appendChild(el('p', 'ui-empty__text', text));
        return box;
    }

    function failed(target, what) {
        target.textContent = '';
        target.appendChild(empty('circle-alert', "We couldn't load " + what, 'Check your connection and try again; nothing was changed.'));
        target.setAttribute('aria-busy', 'false');
    }

    function stat(label, value, meta, tone) {
        var card = el('div', 'ui-card ui-stat');
        card.appendChild(el('span', 'ui-stat__label', label));
        var v = el('span', 'ui-stat__value', value);
        card.appendChild(v);
        if (meta) {
            var m = el('span', 'ui-stat__meta');
            if (tone) m.appendChild(badge(meta, tone, true)); else m.textContent = meta;
            card.appendChild(m);
        }
        return card;
    }

    /* -------------------------------------------------------------- tabs */

    var tablist = document.querySelector('[data-ui-tabs]');

    function loadTab(id) {
        if (id === 'errors' && !state.errors.loaded) loadErrors();
        if (id === 'speed' && !state.speed.loaded) loadSpeed();
        if (id === 'uptime' && !state.uptimeLoaded) loadUptime();
    }

    if (tablist) {
        tablist.addEventListener('ui:tab', function (e) { loadTab(e.detail && e.detail.tab); });
        var selected = tablist.querySelector('[aria-selected="true"]');
        if (selected) loadTab(selected.getAttribute('data-tab'));
    }

    HOD.actions.on('monitor.tab', function (button) {
        var id = button.getAttribute('data-tab-target');
        if (tablist && HOD.tabs.select(tablist, id)) {
            tablist.scrollIntoView({ block: 'start', behavior: 'smooth' });
        }
    });

    /* ------------------------------------------------------------ errors */

    function errorFilters() {
        var q = document.getElementById('monitor-q');
        var kind = document.getElementById('monitor-kind');
        var sort = document.getElementById('monitor-sort');
        var quiet = document.getElementById('monitor-quiet');
        var data = { status: state.errors.status, page: state.errors.page, per: state.errors.per,
            sort: sort ? sort.value : 'last' };
        if (q && q.value.trim()) data.q = q.value.trim();
        if (kind && kind.value) data.kind = kind.value;
        if (quiet && quiet.checked) data.quiet = 1;
        return data;
    }

    var errorsCall = 0;
    function loadErrors() {
        var target = document.getElementById('monitor-errors');
        if (!target) return;
        state.errors.loaded = true;
        target.setAttribute('aria-busy', 'true');
        var mine = ++errorsCall;
        HOD.api(API, { method: 'GET', action: 'errors', data: errorFilters(), quiet: true }).then(function (res) {
            if (mine !== errorsCall) return;
            renderErrors(target, res.data);
        }).catch(function () {
            if (mine === errorsCall) failed(target, 'the problems');
        });
    }

    function renderErrors(target, data) {
        target.textContent = '';
        target.setAttribute('aria-busy', 'false');
        state.errors.total = data.total;
        var pager = document.getElementById('monitor-errors-pages');
        if (!data.rows.length) {
            var filtered = state.errors.status !== 'open' || errorFilters().q || errorFilters().kind;
            target.appendChild(empty(filtered ? 'search' : 'circle-check',
                filtered ? 'No problems match' : 'No open problems',
                filtered ? 'Try another status or clear the search.' : 'New errors appear here within a minute.'));
            if (pager) pager.hidden = true;
            return;
        }
        var wrap = el('div', 'ui-table-wrap');
        var table = el('table', 'ui-table ui-table--stack');
        var head = el('thead');
        var hr = el('tr');
        [['Problem', ''], ['Last 24 h', 'is-num'], ['People (7 days)', 'is-num'], ['Last seen', ''], ['Status', '']].forEach(function (h) {
            var th = el('th', h[1], h[0]);
            th.setAttribute('scope', 'col');
            hr.appendChild(th);
        });
        head.appendChild(hr);
        table.appendChild(head);
        var body = el('tbody');
        data.rows.forEach(function (r) {
            var tr = el('tr');
            var main = el('td');
            main.setAttribute('data-label', 'Problem');
            var title = el('button', 'ui-link ui-table__primary text-left', r.title);
            title.type = 'button';
            title.setAttribute('data-action', 'monitor.open');
            title.setAttribute('data-id', String(r.id));
            main.appendChild(title);
            var where = r.route || (r.file ? r.file + (r.line ? ':' + r.line : '') : '');
            main.appendChild(el('span', 'ui-table__secondary', r.source_label + (where ? ' · ' + where : '') + ' · ' + plural(r.occurrences, 'time') + ' in all'));
            tr.appendChild(main);
            var n24 = el('td', 'is-num', HOD.format.number(r.last_24h));
            n24.setAttribute('data-label', 'Last 24 h');
            tr.appendChild(n24);
            var ppl = el('td', 'is-num', HOD.format.number(r.people_7d));
            ppl.setAttribute('data-label', 'People (7 days)');
            tr.appendChild(ppl);
            var last = el('td', '', ago(r.last_seen_at));
            last.setAttribute('data-label', 'Last seen');
            tr.appendChild(last);
            var st = el('td');
            st.setAttribute('data-label', 'Status');
            var s = STATUS[r.status] || [r.status, 'neutral'];
            var tags = el('span', 'flex flex-wrap gap-1');
            tags.appendChild(badge(s[0], s[1]));
            if (Number(r.regressions) > 0 && r.status === 'new') {
                tags.appendChild(badge('Came back', 'danger'));
            }
            tags.appendChild(levelBadge(r.level));
            st.appendChild(tags);
            tr.appendChild(st);
            body.appendChild(tr);
        });
        table.appendChild(body);
        wrap.appendChild(table);
        target.appendChild(wrap);

        if (pager) {
            var pages = Math.max(1, Math.ceil(data.total / data.per));
            pager.hidden = pages <= 1 && data.total <= data.per;
            document.getElementById('monitor-errors-count').textContent =
                plural(data.total, 'problem') + (pages > 1 ? ' · page ' + data.page + ' of ' + pages : '');
            pager.querySelector('[data-dir="-1"]').disabled = data.page <= 1;
            pager.querySelector('[data-dir="1"]').disabled = data.page >= pages;
        }
    }

    HOD.actions.on('monitor.filter', function () {
        state.errors.page = 1;
        loadErrors();
    });

    HOD.actions.on('monitor.status-filter', function (chip) {
        document.querySelectorAll('[data-action="monitor.status-filter"]').forEach(function (c) {
            c.setAttribute('aria-pressed', c === chip ? 'true' : 'false');
        });
        state.errors.status = chip.getAttribute('data-status');
        state.errors.page = 1;
        loadErrors();
    });

    HOD.actions.on('monitor.page', function (button) {
        state.errors.page = Math.max(1, state.errors.page + Number(button.getAttribute('data-dir')));
        loadErrors();
        var list = document.getElementById('monitor-errors');
        if (list) list.scrollIntoView({ block: 'start', behavior: 'smooth' });
    });

    /* ------------------------------------------------------ one problem */

    function section(title) {
        var s = el('section', 'ui-stack ui-stack--sm');
        s.appendChild(el('h3', 'ui-h3', title));
        return s;
    }

    function pre(text) {
        var p = el('pre', 'text-xs font-mono whitespace-pre-wrap break-words bg-surface-sunken border border-line rounded-lg p-3 max-h-80 overflow-auto', text);
        p.setAttribute('tabindex', '0');
        return p;
    }

    function renderProblem(d) {
        var g = d.group;
        var s = d.stats;
        var body = document.getElementById('monitor-error-body');
        var dialog = document.getElementById('monitor-error');
        dialog.querySelector('.ui-dialog__title').textContent = g.title;
        body.textContent = '';

        var tags = el('div', 'flex flex-wrap gap-2');
        var st = STATUS[g.status] || [g.status, 'neutral'];
        tags.appendChild(badge(st[0], st[1]));
        tags.appendChild(levelBadge(g.level));
        tags.appendChild(badge(g.kind_label, 'info'));
        if (Number(g.regressions) > 0) tags.appendChild(badge('Came back ' + plural(g.regressions, 'time'), 'danger'));
        body.appendChild(tags);

        if (g.kind === 'js' || g.kind === 'csp') {
            var warn = el('div', 'ui-banner ui-banner--info');
            var wb = el('div', 'ui-banner__body');
            wb.appendChild(el('p', 'ui-banner__text', "This was sent by a visitor's browser. Its text is shown exactly as received and is never trusted."));
            warn.appendChild(wb);
            body.appendChild(warn);
        }

        var grid = el('div', 'ui-grid ui-grid--stats');
        grid.appendChild(stat('In all', HOD.format.number(g.occurrences)));
        grid.appendChild(stat('Last 24 hours', HOD.format.number(s.last_24h)));
        grid.appendChild(stat('Last 7 days', HOD.format.number(s.last_7d)));
        grid.appendChild(stat('People, 7 days', HOD.format.number(s.people_7d)));
        body.appendChild(grid);

        var trend = section('The last 14 days');
        var days = Object.keys(s.daily_14d || {});
        trend.appendChild(bars(days.map(function (k) { return Number(s.daily_14d[k]) || 0; }), 'Occurrences per day over the last 14 days'));
        var when = el('p', 'ui-meta', 'First seen ' + ago(g.first_seen_at) + (g.first_release ? ' (release ' + g.first_release + ')' : '') +
            ' · last seen ' + ago(g.last_seen_at) + (g.last_release ? ' (release ' + g.last_release + ')' : ''));
        trend.appendChild(when);
        if (s.fix) {
            trend.appendChild(el('p', 'ui-meta', 'Marked fixed ' + ago(g.fixed_at) + ': ' + s.fix.before + ' in the ' + s.fix.span_hours + ' h before, ' + s.fix.after + ' since.'));
        }
        body.appendChild(trend);

        if (d.hint) {
            var hint = el('div', 'ui-banner ui-banner--warning');
            var hb = el('div', 'ui-banner__body');
            hb.appendChild(el('p', 'ui-banner__title', 'What it usually means'));
            hb.appendChild(el('p', 'ui-banner__text', d.hint));
            hint.appendChild(hb);
            body.appendChild(hint);
        }

        var msg = section('Message');
        if (g.exception_class && g.kind !== 'csp') msg.appendChild(el('p', 'ui-meta', g.exception_class));
        msg.appendChild(pre(g.message || ''));
        var dl = el('dl', 'ui-dl');
        [['Where', g.file ? g.file + (g.line ? ':' + g.line : '') : ''], ['Route', g.route || ''],
         ['Roles', Object.keys(s.roles || {}).map(function (r) { return r.replace(/_/g, ' ') + ' ×' + s.roles[r]; }).join(', ')],
         ['Browsers', Object.keys(s.browsers || {}).map(function (b) { return b + ' ×' + s.browsers[b]; }).join(', ')]].forEach(function (row) {
            if (!row[1]) return;
            dl.appendChild(el('dt', '', row[0]));
            dl.appendChild(el('dd', '', row[1]));
        });
        msg.appendChild(dl);
        body.appendChild(msg);

        if (s.paths && s.paths.length) {
            var pages = section('Pages');
            var ul = el('ul', 'ui-list');
            s.paths.forEach(function (p) {
                var li = el('li', 'ui-list__item');
                var main = el('div', 'ui-list__main');
                main.appendChild(el('p', 'ui-list__title break-all', p.path));
                main.appendChild(el('p', 'ui-list__meta', plural(p.n, 'time') + ' · last ' + ago(p.last_seen_at)));
                li.appendChild(main);
                ul.appendChild(li);
            });
            pages.appendChild(ul);
            body.appendChild(pages);
        }

        var actions = section('Status');
        var row = el('div', 'flex flex-wrap gap-2');
        [['investigating', 'Mark as being looked at', 'secondary'], ['fixed', 'Mark fixed', 'primary'],
         ['ignored', 'Ignore', 'ghost'], ['new', 'Reopen', 'ghost']].forEach(function (a) {
            if (a[0] === g.status) return;
            var b = el('button', 'ui-btn ui-btn--' + a[2] + ' ui-btn--sm', a[1]);
            b.type = 'button';
            b.setAttribute('data-action', 'monitor.status');
            b.setAttribute('data-status', a[0]);
            row.appendChild(b);
        });
        actions.appendChild(row);
        actions.appendChild(el('p', 'ui-hint', 'It reopens by itself if it happens again.'));
        body.appendChild(actions);

        var notes = section('Notes');
        if (d.notes && d.notes.length) {
            var nl = el('ul', 'ui-list');
            d.notes.forEach(function (n) {
                var li = el('li', 'ui-list__item');
                var main = el('div', 'ui-list__main');
                main.appendChild(el('p', 'ui-list__title whitespace-pre-wrap', n.body));
                main.appendChild(el('p', 'ui-list__meta', (n.user_id ? (n.first_name || 'A team member') : 'The monitor') + ' · ' + ago(n.created_at)));
                li.appendChild(main);
                nl.appendChild(li);
            });
            notes.appendChild(nl);
        }
        var form = el('form', 'ui-form');
        form.setAttribute('data-action', 'monitor.note');
        var field = el('div', 'ui-field');
        var label = el('label', 'ui-label', 'Add a note');
        label.setAttribute('for', 'monitor-note');
        var ta = el('textarea', 'ui-textarea');
        ta.id = 'monitor-note';
        ta.name = 'body';
        ta.rows = 2;
        ta.maxLength = 5000;
        field.appendChild(label);
        field.appendChild(ta);
        form.appendChild(field);
        var save = el('button', 'ui-btn ui-btn--secondary ui-btn--sm', 'Save note');
        save.type = 'submit';
        var fa = el('div', 'ui-form-actions');
        fa.appendChild(save);
        form.appendChild(fa);
        notes.appendChild(form);
        body.appendChild(notes);

        if (d.events && d.events.length) {
            var occ = section('Recent occurrences');
            var ol = el('ul', 'ui-list');
            d.events.slice(0, 10).forEach(function (e) {
                var li = el('li', 'ui-list__item');
                var main = el('div', 'ui-list__main');
                main.appendChild(el('p', 'ui-list__title break-all', ((e.method || '') + ' ' + (e.path || '')).trim() || '—'));
                main.appendChild(el('p', 'ui-list__meta', [ago(e.occurred_at), e.role ? e.role.replace(/_/g, ' ') : '', e.device || '', e.browser || '',
                    e.duration_ms ? (e.duration_ms / 1000).toFixed(1) + ' s' : '', 'ref ' + e.ref].filter(Boolean).join(' · ')));
                li.appendChild(main);
                ol.appendChild(li);
            });
            occ.appendChild(ol);
            body.appendChild(occ);
        }

        var trace = g.trace || (d.events && d.events[0] && d.events[0].trace);
        if (trace) {
            var tr = section('Stack trace');
            tr.appendChild(pre(trace));
            body.appendChild(tr);
        }
    }

    function openProblem(id) {
        state.current = Number(id);
        state.brief = null;
        var body = document.getElementById('monitor-error-body');
        var dialog = document.getElementById('monitor-error');
        dialog.querySelector('.ui-dialog__title').textContent = 'Problem';
        body.textContent = '';
        ['title', 'text', 'block'].forEach(function (k) { body.appendChild(el('div', 'ui-skeleton ui-skeleton--' + k)); });
        HOD.dialog.open('monitor-error');
        return HOD.api(API, { method: 'GET', action: 'error', data: { id: id }, quiet: true }).then(function (res) {
            if (state.current === Number(id)) renderProblem(res.data);
        }).catch(function (err) {
            body.textContent = '';
            body.appendChild(empty('circle-alert', "We couldn't load this problem", err && err.message ? err.message : 'Try again in a moment.'));
        });
    }

    HOD.actions.on('monitor.open', function (button) {
        openProblem(button.getAttribute('data-id'));
    });

    function fetchBrief() {
        if (state.brief) return Promise.resolve(state.brief);
        return HOD.api(API, { method: 'GET', action: 'brief', data: { id: state.current } }).then(function (res) {
            state.brief = res.data.markdown;
            return state.brief;
        });
    }

    HOD.actions.on('monitor.copy-brief', function (button) {
        if (!state.current) return;
        HOD.busy(button, true);
        fetchBrief().then(function (md) {
            var done = function () { HOD.toast('Copied. Paste it into Claude to get a fix.', 'success'); };
            if (navigator.clipboard && window.isSecureContext) {
                return navigator.clipboard.writeText(md).then(done, function () { downloadText(md); });
            }
            downloadText(md);
        }).catch(function () {}).finally(function () { HOD.busy(button, false); });
    });

    function downloadText(md) {
        var blob = new Blob([md], { type: 'text/markdown;charset=utf-8' });
        var a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = 'hodlekki-problem-' + state.current + '.md';
        // Inside the open dialog: a click outside it would be cancelled (docs/UI.md, dialogs).
        (document.getElementById('monitor-error') || document.body).appendChild(a);
        a.click();
        setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
        HOD.toast('Saved as a file you can attach to a message to Claude.', 'info');
    }

    HOD.actions.on('monitor.download-brief', function (button) {
        if (!state.current) return;
        HOD.busy(button, true);
        fetchBrief().then(downloadText).catch(function () {}).finally(function () { HOD.busy(button, false); });
    });

    HOD.actions.on('monitor.status', function (button) {
        var status = button.getAttribute('data-status');
        var ask = status === 'fixed'
            ? HOD.prompt({ title: 'Mark this problem fixed', label: 'What was changed? (optional)', confirmLabel: 'Mark fixed', multiline: true })
            : Promise.resolve('');
        ask.then(function (note) {
            if (note === null) return;
            HOD.busy(button, true);
            return HOD.api(API, { action: 'set_status', data: { id: state.current, status: status, note: note || '' } }).then(function (res) {
                HOD.toast(res.message || 'Saved.', 'success');
                state.errors.loaded = false;
                if (!document.getElementById('panel-errors').hidden) loadErrors();
                return openProblem(state.current);
            }).finally(function () { HOD.busy(button, false); });
        }).catch(function () {});
    });

    HOD.actions.on('monitor.note', function (form) {
        var ta = form.querySelector('textarea');
        var text = ta.value.trim();
        if (!text) {
            ta.setAttribute('aria-invalid', 'true');
            ta.focus();
            return;
        }
        var button = form.querySelector('[type="submit"]');
        HOD.busy(button, true);
        HOD.api(API, { action: 'add_note', data: { id: state.current, body: text } }).then(function () {
            HOD.toast('Note saved.', 'success');
            state.brief = null;
            return openProblem(state.current);
        }).catch(function () {}).finally(function () { HOD.busy(button, false); });
    });

    /* ---------------------------------------------------- health, scan */

    HOD.actions.on('monitor.scan', function (button) {
        HOD.busy(button, true);
        HOD.api(API, { action: 'scan_now' }).then(function (res) {
            HOD.toast(res.message || 'Logs read.', 'success');
            state.errors.loaded = false;
            if (!document.getElementById('panel-errors').hidden) loadErrors();
        }).catch(function () {}).finally(function () { HOD.busy(button, false); });
    });

    HOD.actions.on('monitor.checks', function (button) {
        HOD.busy(button, true);
        HOD.api(API, { action: 'run_checks' }).then(function (res) {
            HOD.toast((res.message || 'Checked.') + ' Reloading…', 'success');
            setTimeout(function () { window.location.hash = 'tab=health'; window.location.reload(); }, 600);
        }).catch(function () { HOD.busy(button, false); });
    });

    /* ------------------------------------------------------------- speed */

    var VITALS = [
        ['lcp', 'Loading (LCP)', function (v) { return (v / 1000).toFixed(1) + ' s'; }, [2500, 4000]],
        ['inp', 'Response to taps (INP)', function (v) { return Math.round(v) + ' ms'; }, [200, 500]],
        ['cls', 'Layout shift (CLS)', function (v) { return (v / 1000).toFixed(2); }, [100, 250]],
        ['ttfb', 'Server response (TTFB)', function (v) { return (v / 1000).toFixed(1) + ' s'; }, [800, 1800]]
    ];

    function rating(v, limits) {
        return v <= limits[0] ? ['Good', 'success'] : (v > limits[1] ? ['Poor', 'danger'] : ['Needs work', 'warning']);
    }

    function loadSpeed() {
        var target = document.getElementById('monitor-speed');
        if (!target) return;
        state.speed.loaded = true;
        target.setAttribute('aria-busy', 'true');
        HOD.api(API, { method: 'GET', action: 'speed', data: { device: state.speed.device, days: state.speed.days }, quiet: true }).then(function (res) {
            renderSpeed(target, res.data);
        }).catch(function () { failed(target, 'the speed figures'); });
    }

    function renderSpeed(target, d) {
        target.textContent = '';
        target.setAttribute('aria-busy', 'false');
        var sum = (d.summary || {})[d.device === 'tablet' ? 'phone' : d.device] || {};
        var stack = el('div', 'ui-stack ui-stack--md');
        if (!sum.n || sum.n < 20) {
            stack.appendChild(empty('smartphone', 'Not enough visits measured yet',
                (sum.n ? plural(sum.n, 'visit') + ' in 7 days; ' : '') + 'the figures appear after about 20 page views on ' + (d.device === 'phone' ? 'phones' : 'computers') + '. Every page with the app shell measures itself.'));
            target.appendChild(stack);
            return;
        }
        var grid = el('div', 'ui-grid ui-grid--stats');
        VITALS.forEach(function (v) {
            if (sum[v[0]] === undefined) return;
            var r = rating(sum[v[0]], v[3]);
            grid.appendChild(stat(v[1], v[2](sum[v[0]]), r[0], r[1]));
        });
        stack.appendChild(grid);
        stack.appendChild(el('p', 'ui-meta', plural(sum.n, 'visit') + ' measured in the last 7 days' + (sum.worst_page ? '. Slowest page: ' + sum.worst_page + '.' : '.')));

        var lcpDays = (d.daily || []).filter(function (r) { return r.metric === 'lcp'; });
        if (lcpDays.length > 1) {
            var card = el('div', 'ui-card');
            var head = el('div', 'ui-card__header');
            head.appendChild(el('h2', 'ui-card__title', 'Loading time (LCP, 75th percentile) by day'));
            card.appendChild(head);
            var cb = el('div', 'ui-card__body ui-stack ui-stack--sm');
            cb.appendChild(bars(lcpDays.map(function (r) { return Number(r.p75) || 0; }), 'Loading time by day'));
            cb.appendChild(el('p', 'ui-meta', lcpDays[0].day + ' to ' + lcpDays[lcpDays.length - 1].day));
            card.appendChild(cb);
            stack.appendChild(card);
        }

        if (d.pages && d.pages.length) {
            var pc = el('div', 'ui-card');
            var ph = el('div', 'ui-card__header ui-card__header--divided');
            ph.appendChild(el('h2', 'ui-card__title', 'By page'));
            pc.appendChild(ph);
            var wrap = el('div', 'ui-table-wrap');
            var table = el('table', 'ui-table ui-table--stack ui-table--compact');
            var thead = el('thead');
            var hr = el('tr');
            ['Page', 'Visits', 'Good loading', 'Good taps', 'Steady layout'].forEach(function (h, i) {
                var th = el('th', i ? 'is-num' : '', h);
                th.setAttribute('scope', 'col');
                hr.appendChild(th);
            });
            thead.appendChild(hr);
            table.appendChild(thead);
            var tb = el('tbody');
            d.pages.forEach(function (p) {
                var tr = el('tr');
                var td = el('td', 'break-all', p.page);
                td.setAttribute('data-label', 'Page');
                tr.appendChild(td);
                var visits = el('td', 'is-num', HOD.format.number(p.views));
                visits.setAttribute('data-label', 'Visits');
                tr.appendChild(visits);
                [['lcp', 'Good loading'], ['inp', 'Good taps'], ['cls', 'Steady layout']].forEach(function (m) {
                    var c = el('td', 'is-num');
                    c.setAttribute('data-label', m[1]);
                    var share = p[m[0]] ? p[m[0]].good_share : null;
                    if (share === null || share === undefined) {
                        c.textContent = '—';
                    } else {
                        c.appendChild(badge(share + '%', share >= 75 ? 'success' : (share >= 50 ? 'warning' : 'danger')));
                    }
                    tr.appendChild(c);
                });
                tb.appendChild(tr);
            });
            table.appendChild(tb);
            wrap.appendChild(table);
            pc.appendChild(wrap);
            stack.appendChild(pc);
        }
        target.appendChild(stack);
    }

    HOD.actions.on('monitor.speed', function (button) {
        var group = button.parentNode;
        group.querySelectorAll('.ui-segmented__item').forEach(function (b) { b.setAttribute('aria-pressed', b === button ? 'true' : 'false'); });
        if (button.hasAttribute('data-device')) state.speed.device = button.getAttribute('data-device');
        if (button.hasAttribute('data-days')) state.speed.days = Number(button.getAttribute('data-days'));
        loadSpeed();
    });

    /* ------------------------------------------------------------ uptime */

    function loadUptime() {
        var target = document.getElementById('monitor-uptime');
        if (!target) return;
        state.uptimeLoaded = true;
        HOD.api(API, { method: 'GET', action: 'uptime', data: { days: 30 }, quiet: true }).then(function (res) {
            renderUptime(target, res.data);
        }).catch(function () { failed(target, 'the uptime figures'); });
    }

    function renderUptime(target, d) {
        target.textContent = '';
        var stack = el('div', 'ui-stack ui-stack--md');
        var latest = d.latest || {};
        if (!Object.keys(latest).length) {
            stack.appendChild(empty('globe', 'No report from outside yet',
                'The outside probe runs from GitHub about every hour once MONITOR_PROBE_SECRET is set in .env and in GitHub (DEPLOY.md, "The system monitor").'));
            target.appendChild(stack);
            return;
        }
        var grid = el('div', 'ui-grid ui-grid--stats');
        [['site', 'hodlekki.org'], ['connect', 'connect.hodlekki.org']].forEach(function (t) {
            var r = latest[t[0]];
            if (!r) return;
            var days = (d.days || []).filter(function (x) { return x.target === t[0]; });
            var up = 0, n = 0;
            days.forEach(function (x) { up += Number(x.up); n += Number(x.n); });
            grid.appendChild(stat(t[1], n ? (Math.round(1000 * up / n) / 10) + '%' : '—',
                (r.ok ? 'Up' : 'DOWN') + ' · ' + (r.ttfb_ms || 0) + ' ms', r.ok ? 'success' : 'danger'));
        });
        var tls = latest.tls && latest.tls.detail;
        if (tls) grid.appendChild(stat('Certificate', (tls.edge_days !== null && tls.edge_days !== undefined ? tls.edge_days + ' days' : '—'), 'until Cloudflare renews it'));
        var lock = latest.origin_lock;
        if (lock) grid.appendChild(stat('Origin lock', lock.detail && lock.detail.tested === false ? 'Not tested' : (lock.ok ? 'Holding' : 'OPEN'),
            lock.detail && lock.detail.tested === false ? 'needs ORIGIN_IP' : (lock.ok ? 'refuses requests around Cloudflare' : 'answers around Cloudflare'),
            lock.detail && lock.detail.tested === false ? 'neutral' : (lock.ok ? 'success' : 'danger')));
        stack.appendChild(grid);

        [['site', 'hodlekki.org, last 30 days'], ['connect', 'connect.hodlekki.org, last 30 days']].forEach(function (t) {
            var days = (d.days || []).filter(function (x) { return x.target === t[0]; });
            if (!days.length) return;
            var card = el('div', 'ui-card');
            var head = el('div', 'ui-card__header');
            head.appendChild(el('h2', 'ui-card__title', t[1]));
            card.appendChild(head);
            var cb = el('div', 'ui-card__body ui-stack ui-stack--sm');
            cb.appendChild(bars(days.map(function (x) { return Number(x.n) ? Math.round(100 * Number(x.up) / Number(x.n)) : 0; }), 'Share of checks up, per day', 'block w-full h-16 text-success'));
            cb.appendChild(el('p', 'ui-meta', 'Each bar is a day, oldest on the left: the share of the outside checks that found it up. Average first byte: ' +
                Math.round(days.reduce(function (a, x) { return a + Number(x.ttfb || 0); }, 0) / days.length) + ' ms.'));
            card.appendChild(cb);
            stack.appendChild(card);
        });
        if (d.probe) stack.appendChild(el('p', 'ui-meta', 'Last report from outside ' + ago(d.probe.at) + (d.probe.main_sha ? '; GitHub\'s main was at ' + String(d.probe.main_sha).slice(0, 7) : '') + '.'));
        target.appendChild(stack);
    }

    /* ---------------------------------------------------------- settings */

    HOD.actions.on('monitor.recipient-add', function (form) {
        if (HOD.form && HOD.form.validate && !HOD.form.validate(form)) return;
        var input = form.querySelector('input[name="email"]');
        var button = form.querySelector('[type="submit"]');
        HOD.busy(button, true);
        HOD.api(API, { action: 'recipient_add', data: { email: input.value.trim() }, quiet: true }).then(function (res) {
            HOD.toast(res.message || 'Confirmation link sent.', 'success');
            setTimeout(function () { window.location.hash = 'tab=settings'; window.location.reload(); }, 900);
        }).catch(function (err) {
            if (HOD.form && HOD.form.setErrors) HOD.form.setErrors(form, { email: err.message });
            else HOD.toast(err.message, 'danger');
        }).finally(function () { HOD.busy(button, false); });
    });

    HOD.actions.on('monitor.recipient-remove', function (button) {
        var email = button.getAttribute('data-email');
        HOD.confirm({ title: 'Stop alerts to ' + email + '?', message: 'No more alerts or summaries. You can add it again later.',
            confirmLabel: 'Remove address', tone: 'danger' }).then(function (yes) {
            if (!yes) return;
            HOD.busy(button, true);
            HOD.api(API, { action: 'recipient_remove', data: { id: button.getAttribute('data-id') } }).then(function () {
                HOD.toast('Removed. ' + email + ' gets nothing more.', 'success');
                var item = button.closest('.ui-list__item');
                if (item) item.remove();
            }).catch(function () {}).finally(function () { HOD.busy(button, false); });
        });
    });

    HOD.actions.on('monitor.settings', function (form) {
        var button = form.querySelector('[type="submit"]');
        HOD.busy(button, true);
        HOD.api(API, { action: 'settings_save', data: { digest_hour: form.querySelector('select').value } }).then(function (res) {
            HOD.toast(res.message || 'Saved.', 'success');
        }).catch(function () {}).finally(function () { HOD.busy(button, false); });
    });

    HOD.actions.on('monitor.copy', function (button) {
        var input = document.getElementById(button.getAttribute('data-copy-target'));
        if (!input) return;
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(input.value).then(function () { HOD.toast('Copied.', 'success'); });
        } else {
            input.select();
            HOD.toast('Select the line and copy it.', 'info');
        }
    });

    HOD.actions.on('monitor.copy-wall', function () {
        var input = document.getElementById('monitor-wall-link');
        if (!input) return;
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(input.value).then(function () { HOD.toast('Live wall link copied.', 'success'); });
        } else {
            input.select();
            HOD.toast('Select the link and copy it.', 'info');
        }
    });

    HOD.actions.on('monitor.wall-rotate', function (button) {
        HOD.confirm({ title: 'Make a new live wall link?', message: 'Screens on the old link stop updating.',
            confirmLabel: 'Make a new link', tone: 'danger' }).then(function (yes) {
            if (!yes) return;
            HOD.busy(button, true);
            HOD.api(API, { action: 'wall_rotate' }).then(function (res) {
                var input = document.getElementById('monitor-wall-link');
                if (input && res.data && res.data.tv_link) input.value = res.data.tv_link;
                HOD.toast(res.message || 'New link made.', 'success');
            }).catch(function () {}).finally(function () { HOD.busy(button, false); });
        });
    });

    HOD.actions.on('monitor.test-alert', function (button) {
        HOD.busy(button, true);
        HOD.api(API, { action: 'test_alert' }).then(function (res) {
            HOD.toast(res.message || 'Sent.', 'success');
        }).catch(function () {}).finally(function () { HOD.busy(button, false); });
    });
})();
