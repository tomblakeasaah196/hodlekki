// /assets/ui/js/pages/assimilation-find.js
// Assimilation (modules/assimilation/index.php), part 2, managers only, on
// top of window.Assim from assimilation.js: Find people (the drift rule,
// filters, selection and bulk assign, the Excel chooser), watchlists, the
// six-step list builder, and Analytics with the monthly PDF.
(function () {
    'use strict';

    var HOD = window.HOD;
    var A = window.Assim;
    if (!A || !A.cfg.manager || !document.getElementById('panel-find')) return;
    var esc = HOD.esc;

    /* =================================================================== *
     * Find people
     * =================================================================== */

    var find = { page: 1, selected: {}, ready: false };
    var statusPicks = {};

    function val(id) { var el = A.$('#' + id); return el ? el.value : ''; }

    function currentRule() {
        return {
            max_services: +val('assim-rule-max') || 3,
            window_value: +val('assim-rule-window') || 2,
            window_unit: val('assim-rule-unit') || 'months',
            spiritual_status: Object.keys(statusPicks),
            department_id: +val('assim-f-dept') || 0,
            region_id: +val('assim-f-region') || 0,
            gender: val('assim-f-gender'),
            age_band: val('assim-f-age'),
            ever_attended: A.$('#assim-f-ever').checked ? 1 : 0,
            last_attended_before: val('assim-f-before') || null,
            last_attended_after: val('assim-f-after') || null,
            case_state: val('assim-f-case'),
            search: val('assim-f-search').trim()
        };
    }

    function describe(r) {
        var unit = r.window_unit.replace(/s$/, '') + (r.window_value === 1 ? '' : 's');
        var s = 'Fewer than ' + r.max_services + ' service' + (r.max_services === 1 ? '' : 's') + ' in the past ' + r.window_value + ' ' + unit;
        if (r.spiritual_status.length) s += ' · ' + r.spiritual_status.map(A.label).join(' / ');
        if (r.ever_attended) s += ' · has attended before';
        return s;
    }
    function describeRule() { A.$('#assim-rule-summary').textContent = describe(currentRule()); }

    function paintStatusChips() {
        A.$$('#assim-f-status [data-status]').forEach(function (b) {
            b.setAttribute('aria-pressed', statusPicks[b.getAttribute('data-status')] ? 'true' : 'false');
        });
        describeRule();
    }

    function applyRule(r) {
        A.$('#assim-rule-max').value = r.max_services;
        A.$('#assim-rule-window').value = r.window_value;
        A.$('#assim-rule-unit').value = r.window_unit;
        A.$('#assim-f-ever').checked = !!r.ever_attended;
        statusPicks = {};
        (r.spiritual_status || []).forEach(function (s) { statusPicks[s] = true; });
        paintStatusChips();
    }

    function personCard(p) {
        var name = ((p.first_name || '') + ' ' + (p.last_name || '')).trim();
        var prev = Number(p.services_previous), now = Number(p.services_in_window);
        var trend = prev > now ? ['trending-down', 'text-danger'] : (prev < now ? ['trending-up', 'text-success'] : ['minus', 'text-ink-subtle']);
        var on = !!find.selected[p.id];
        return '<article class="ui-card ui-card__body space-y-3' + (on ? ' ring-2 ring-primary' : '') + '" data-person="' + esc(p.id) + '">'
            + '<div class="flex items-start gap-3">'
            + '<label class="ui-check mt-0.5"><input type="checkbox" data-action-change="assim.select" data-id="' + esc(p.id) + '"' + (on ? ' checked' : '') + '>'
            + '<span class="sr-only">Select ' + esc(name) + '</span></label>'
            + '<div class="min-w-0 flex-1"><p class="truncate font-semibold text-ink">' + esc(name) + '</p><p class="text-sm text-ink-subtle">' + esc(p.phone || 'No phone') + '</p></div>'
            + A.badge(A.label(p.spiritual_status), 'outline') + '</div>'
            + '<div class="flex items-end justify-between gap-3"><div>'
            + '<p class="text-xs text-ink-subtle">Last in church</p><p class="font-semibold text-ink">' + esc(p.since_words) + '</p>'
            + '<p class="text-xs text-ink-subtle">' + (p.last_attended ? esc(A.niceDate(p.last_attended)) : 'no record') + '</p></div>'
            + A.sparkline(p.sparkline) + '</div>'
            + '<p class="flex items-center gap-1 text-sm ' + trend[1] + '">' + HOD.icon(trend[0]) + '<span>' + esc(now) + ' in this window, ' + esc(prev) + ' before</span></p>'
            + (p.departments ? '<p class="truncate text-xs text-ink-subtle">' + esc(p.departments) + '</p>' : '')
            + (p.case_id
                ? A.btn(A.word(p.case_status) + ' · ' + (p.assignee_name || 'unclaimed'), 'assim.case', { id: p.case_id }, 'soft')
                : '<p class="text-sm font-medium text-warning">Nobody is calling them yet</p>')
            + '</article>';
    }

    function syncBulk() {
        var n = Object.keys(find.selected).length;
        A.$('#assim-sel-count').textContent = n;
        A.$('#assim-bulk').hidden = n === 0;
    }
    function clearSelection() {
        find.selected = {};
        A.$$('#assim-find-list [data-action-change="assim.select"]').forEach(function (b) { b.checked = false; });
        A.$$('#assim-find-list [data-person]').forEach(function (c) { c.classList.remove('ring-2', 'ring-primary'); });
        syncBulk();
    }

    function runFind(reset) {
        reset = reset !== false;
        describeRule();
        find.page = reset ? 1 : find.page + 1;
        var list = A.$('#assim-find-list');
        if (reset) { list.innerHTML = A.skeleton(3); clearSelection(); }
        return A.api({ action: 'find_people', rule: JSON.stringify(currentRule()), page: find.page, sort: val('assim-find-sort') }).then(function (res) {
            var d = res.data;
            A.$('#assim-find-total').textContent = Number(d.total) === 1 ? '1 person matches' : HOD.format.number(d.total) + ' people match';
            var html = (d.people || []).map(personCard).join('');
            if (reset) list.innerHTML = html || A.empty('Nobody has drifted by this rule', 'Good news. Try a wider window or another group.');
            else list.insertAdjacentHTML('beforeend', html);
            A.$('#assim-find-more').hidden = !d.has_more;
        }).catch(function () { if (reset) list.innerHTML = ''; });
    }

    function initFind() {
        if (find.ready) return;
        find.ready = true;
        refreshWatchlists();
        runFind();
    }

    HOD.actions.on('assim.find', function () { runFind(); });
    HOD.actions.on('assim.find-search', A.debounce(function () { runFind(); }, 400));
    HOD.actions.on('assim.find-more', function () { runFind(false); });
    HOD.actions.on('assim.preset', function (btn) {
        A.$('#assim-rule-max').value = btn.getAttribute('data-max');
        A.$('#assim-rule-window').value = btn.getAttribute('data-win');
        A.$('#assim-rule-unit').value = btn.getAttribute('data-unit');
        runFind();
    });
    HOD.actions.on('assim.status', function (btn) {
        var s = btn.getAttribute('data-status');
        if (statusPicks[s]) delete statusPicks[s]; else statusPicks[s] = true;
        paintStatusChips();
        runFind();
    });
    HOD.actions.on('assim.filters', function (btn) {
        var panel = A.$('#assim-filters');
        panel.hidden = !panel.hidden;
        btn.setAttribute('aria-expanded', panel.hidden ? 'false' : 'true');
        if (!panel.hidden) describeRule();
    });
    HOD.actions.on('assim.select', function (box) {
        var id = box.getAttribute('data-id');
        if (box.checked) find.selected[id] = true; else delete find.selected[id];
        var card = box.closest('[data-person]');
        if (card) { card.classList.toggle('ring-2', box.checked); card.classList.toggle('ring-primary', box.checked); }
        syncBulk();
    });
    HOD.actions.on('assim.clear-selection', clearSelection);
    HOD.actions.on('assim.bulk-assign', function (btn) {
        var ids = Object.keys(find.selected);
        if (!ids.length) return;
        HOD.busy(btn, true);
        A.api({ action: 'bulk_assign', user_ids: ids, to_user_id: +val('assim-bulk-assignee') }).then(function (res) {
            HOD.toast(res.message || 'Assigned.', 'success');
            clearSelection();
            runFind();
        }).catch(function () {}).then(function () { HOD.busy(btn, false); });
    });

    /* ----- Excel --------------------------------------------------------- */

    HOD.actions.on('assim.export', function (btn) {
        HOD.dialog.close('assim-export');
        exportExcel(btn.getAttribute('data-report'));
    });
    function exportExcel(reportType) { A.postExcelExport(reportType, currentRule()); }

    /* =================================================================== *
     * Watchlists
     * =================================================================== */

    var watchlists = [];

    function renderWatchlists() {
        var box = A.$('#assim-wl-list');
        if (!watchlists.length) {
            box.innerHTML = A.empty('No watchlists yet', 'Build a list on Find people. It takes a minute.');
            return;
        }
        box.innerHTML = watchlists.map(function (w) {
            var chips = [];
            if (Number(w.is_open)) {
                if (Number(w.pool)) chips.push(w.pool + ' waiting for a volunteer');
                if (Number(w.claimed)) chips.push(w.claimed + ' being called');
                if (Number(w.untouched)) chips.push(w.untouched + ' held back by past outcomes');
                if (!chips.length) chips.push('Everyone on it is being called');
            } else if (Number(w.untouched)) {
                chips.push(w.untouched + ' with nobody calling yet');
            }
            return '<article class="ui-card ui-card__body space-y-3">'
                + '<div class="flex items-start justify-between gap-3"><div class="min-w-0">'
                + '<p class="flex flex-wrap items-center gap-2 font-semibold text-ink">' + esc(w.name)
                + (Number(w.is_open) ? A.badge('Open', 'success') : '') + (Number(w.is_active) ? '' : A.badge('Paused', 'outline')) + '</p>'
                + '<p class="text-sm text-ink-muted">' + esc(w.summary) + '</p></div>'
                + '<div class="text-right"><p class="font-display text-2xl font-bold tabular-nums text-ink">' + esc(w.people) + '</p><p class="text-xs text-ink-subtle">people</p></div></div>'
                + (chips.length ? '<div class="ui-chips">' + chips.map(function (c) { return '<span class="ui-chip">' + esc(c) + '</span>'; }).join('') + '</div>' : '')
                + '<div class="flex flex-wrap gap-2">'
                + A.btn('Open', 'assim.wl-open', { id: w.id }, 'primary', 'search')
                + A.btn(Number(w.is_active) ? 'Pause digest' : 'Turn on', 'assim.wl-toggle', { id: w.id, on: Number(w.is_active) ? 0 : 1 }, 'secondary')
                + A.btn('Delete', 'assim.wl-delete', { id: w.id, name: w.name }, 'danger-ghost', 'trash-2')
                + '</div></article>';
        }).join('');
    }

    function refreshWatchlists() {
        return A.api({ action: 'list_watchlists' }, { quiet: true }).then(function (res) {
            watchlists = res.data || [];
            var btn = A.$('[data-action="assim.watchlists"]');
            if (btn) {
                var count = btn.querySelector('.ui-count');
                if (!count) { count = document.createElement('span'); count.className = 'ui-count'; btn.appendChild(count); }
                count.textContent = watchlists.length;
                count.hidden = !watchlists.length;
            }
            var sel = A.$('#assim-c-watchlist');
            if (sel) {
                var keep = sel.value;
                sel.innerHTML = '<option value="0">Any watchlist</option>' + watchlists.map(function (w) {
                    return '<option value="' + esc(w.id) + '">' + esc(w.name) + '</option>';
                }).join('');
                sel.value = keep || '0';
            }
            renderWatchlists();
        }).catch(function () {});
    }

    HOD.actions.on('assim.watchlists', function (btn) {
        A.$('#assim-wl-list').innerHTML = A.skeleton(2);
        HOD.dialog.open('assim-watchlists', btn);
        refreshWatchlists();
    });
    HOD.actions.on('assim.wl-open', function (btn) {
        var w = watchlists.filter(function (x) { return String(x.id) === btn.getAttribute('data-id'); })[0];
        if (!w) return;
        var r = w.rule || {};
        applyRule(r);
        A.$('#assim-f-dept').value = r.department_id || 0;
        A.$('#assim-f-region').value = r.region_id || 0;
        A.$('#assim-f-gender').value = r.gender || '';
        A.$('#assim-f-age').value = r.age_band || '';
        A.$('#assim-f-before').value = r.last_attended_before || '';
        A.$('#assim-f-after').value = r.last_attended_after || '';
        A.$('#assim-f-case').value = r.case_state || '';
        A.$('#assim-f-search').value = r.search || '';
        HOD.dialog.close('assim-watchlists');
        HOD.tabs.select(A.$('[data-ui-tabs]'), 'find');
        runFind();
    });
    HOD.actions.on('assim.wl-toggle', function (btn) {
        HOD.busy(btn, true);
        A.api({ action: 'toggle_watchlist', id: btn.getAttribute('data-id'), is_active: btn.getAttribute('data-on') }).then(function (res) {
            HOD.toast(res.message || 'Saved.', 'success');
            refreshWatchlists();
        }).catch(function () { HOD.busy(btn, false); });
    });
    HOD.actions.on('assim.wl-delete', function (btn) {
        HOD.confirm({ title: 'Delete watchlist?', message: '“' + btn.getAttribute('data-name') + '” goes. Follow-ups opened from it are kept.', confirmLabel: 'Delete', tone: 'danger' }).then(function (ok) {
            if (!ok) return;
            HOD.busy(btn, true);
            A.api({ action: 'delete_watchlist', id: btn.getAttribute('data-id') }).then(function (res) {
                HOD.toast(res.message || 'Deleted.', 'success');
                refreshWatchlists();
            }).catch(function () { HOD.busy(btn, false); });
        });
    });

    HOD.actions.on('assim.save-wl', function (btn) {
        A.$('#assim-save-wl-desc').textContent = describe(currentRule());
        A.$('#assim-wl-name').value = '';
        A.$('#assim-wl-open').checked = false;
        HOD.dialog.open('assim-save-wl', btn);
    });
    HOD.actions.on('assim.save-wl-submit', function (form, event) {
        var btn = (event && event.submitter) || A.$('[form="assim-save-wl-form"]');
        HOD.busy(btn, true);
        A.api({
            action: 'save_watchlist', name: A.$('#assim-wl-name').value.trim(),
            notify: A.$('#assim-wl-notify').checked ? 1 : 0, is_open: A.$('#assim-wl-open').checked ? 1 : 0,
            rule: JSON.stringify(currentRule())
        }).then(function (res) {
            HOD.toast(res.message || 'Watchlist saved.', 'success');
            if (res.data && res.data.self_claim_turned_on && A.boot) A.boot.allow_self_claim = true;
            HOD.dialog.close('assim-save-wl');
            refreshWatchlists();
        }).catch(function () {}).then(function () { HOD.busy(btn, false); });
    });

    /* =================================================================== *
     * The list builder
     * =================================================================== */

    var BW_TOTAL = 6;
    var BW_AWAY = {
        nos: { max: 1, win: 1, unit: 'months', label: 'Missed a few Sundays' },
        slip: { max: 3, win: 2, unit: 'months', label: 'Slipping away' },
        gone: { max: 5, win: 3, unit: 'months', label: 'Gone a while' }
    };
    var BW_STATUSES = [['1st_Timer', '1st timer'], ['2nd_Timer', '2nd timer'], ['3rd_Timer', '3rd timer'], ['Visitor', 'Visitor'],
        ['Non_Member', 'Not a member yet'], ['Member', 'Member'], ['Worker', 'Worker'], ['Pastor', 'Pastor']];
    var bw = null;
    var SELECTED = ['border-primary', 'bg-primary-soft', 'ring-1', 'ring-primary'];

    function paintChoice(el, on) {
        SELECTED.forEach(function (c) { el.classList.toggle(c, on); });
        if (el.getAttribute('role') === 'radio') el.setAttribute('aria-checked', on ? 'true' : 'false');
        if (el.hasAttribute('aria-pressed')) el.setAttribute('aria-pressed', on ? 'true' : 'false');
    }

    function bwRule() {
        var a = bw.away === 'custom' ? bw.custom : BW_AWAY[bw.away];
        return {
            max_services: a.max, window_value: a.win, window_unit: a.unit,
            spiritual_status: Object.keys(bw.who),
            department_id: +val('assim-bw-dept') || 0,
            region_id: +val('assim-bw-region') || 0,
            gender: bw.gender || '', age_band: bw.age || '',
            ever_attended: A.$('#assim-bw-ever').checked ? 1 : 0,
            last_attended_before: null, last_attended_after: null,
            case_state: '', search: ''
        };
    }

    function bwSuggest() {
        var a = bw.away === 'custom' ? 'Custom watch' : BW_AWAY[bw.away].label;
        var who = Object.keys(bw.who).length ? Object.keys(bw.who).map(A.label).join(' & ') : 'Everyone';
        return (a + ' · ' + who).slice(0, 120);
    }

    function bwPaintAll() {
        A.$$('[data-action="assim.bw-away"]').forEach(function (b) { paintChoice(b, b.getAttribute('data-away') === bw.away); });
        A.$('#assim-bw-custom').hidden = bw.away !== 'custom';
        A.$('#assim-bw-max').textContent = bw.custom.max;
        A.$('#assim-bw-win').textContent = bw.custom.win;
        var everyone = !Object.keys(bw.who).length;
        paintChoice(A.$('#assim-bw-everyone'), everyone);
        A.$$('#assim-bw-who [data-who]').forEach(function (b) { b.setAttribute('aria-pressed', bw.who[b.getAttribute('data-who')] ? 'true' : 'false'); });
        A.$$('#assim-bw-gender [data-g]').forEach(function (b) { b.setAttribute('aria-pressed', b.getAttribute('data-g') === bw.gender ? 'true' : 'false'); });
        A.$$('#assim-bw-age [data-age]').forEach(function (b) { b.setAttribute('aria-pressed', b.getAttribute('data-age') === bw.age ? 'true' : 'false'); });
        A.$$('[data-action="assim.bw-mode"]').forEach(function (b) { paintChoice(b, b.getAttribute('data-mode') === bw.mode); });
        A.$('#assim-bw-digest-label').textContent = bw.mode === 'open' ? 'Nudge the team when someone drifts in' : 'Nudge the managers when someone drifts in';
        A.$('#assim-bw-claim-note').hidden = !(bw.mode === 'open' && A.boot && !A.boot.allow_self_claim);
    }

    function bwFooter() {
        var last = bw.step === BW_TOTAL - 1;
        var back = A.$('#assim-bw-back');
        back.classList.toggle('invisible', bw.step === 0);
        back.disabled = bw.busy;
        A.$('#assim-bw-export-wrap').hidden = !last;
        A.$('#assim-bw-export-btn').disabled = bw.busy;
        if (!last || bw.busy) exportMenu(false);
        var next = A.$('#assim-bw-next');
        var spans = next.querySelectorAll('span:not(.ui-btn__spinner)');
        if (spans.length) spans[spans.length - 1].textContent = last ? 'Launch watchlist' : (bw.step === 0 ? 'Let’s go' : 'Continue');
        HOD.busy(next, bw.busy);
    }

    function bwDots() {
        A.$('#assim-bw-dots').innerHTML = Array.apply(null, Array(BW_TOTAL)).map(function (_, i) {
            var cls = i === bw.step ? 'w-6 bg-primary' : (i < bw.step ? 'w-2 bg-primary opacity-40' : 'w-2 bg-line-strong');
            return '<span class="h-2 rounded-full transition-all ' + cls + '"></span>';
        }).join('');
    }

    function bwShow(step) {
        bw.step = Math.max(0, Math.min(BW_TOTAL - 1, step));
        A.$$('#assim-builder [data-wstep]').forEach(function (s) {
            var on = Number(s.getAttribute('data-wstep')) === bw.step;
            s.hidden = !on;
            s.classList.toggle('animate-fade-in-up', on);
        });
        A.$('#assim-builder-desc').textContent = 'Step ' + (bw.step + 1) + ' of ' + BW_TOTAL;
        var body = A.$('#assim-builder .ui-dialog__body');
        if (body) body.scrollTop = 0;
        bwDots();
        bwFooter();
        var heading = A.$('#assim-builder [data-wstep="' + bw.step + '"] [tabindex="-1"]');
        if (bw.step === BW_TOTAL - 1) {
            if (!bw.nameTouched) A.$('#assim-bw-name').value = bwSuggest();
            bwRecap();
            bwCount();
        } else if (heading && bw.step > 0) {
            heading.focus({ preventScroll: true });
        }
    }

    function bwRecap() {
        var a = bw.away === 'custom' ? 'Under ' + bw.custom.max + ' in ' + bw.custom.win + ' ' + bw.custom.unit : BW_AWAY[bw.away].label;
        var chips = [a, Object.keys(bw.who).length ? Object.keys(bw.who).map(A.label).join(', ') : 'Everyone'];
        var boot = A.boot || { departments: [], regions: [] };
        var dept = boot.departments.filter(function (d) { return Number(d.id) === +val('assim-bw-dept'); })[0];
        if (dept) chips.push(dept.name);
        var region = boot.regions.filter(function (r) { return Number(r.id) === +val('assim-bw-region'); })[0];
        if (region) chips.push(region.name);
        if (bw.gender) chips.push(bw.gender);
        if (bw.age) chips.push('Aged ' + bw.age);
        chips.push(bw.mode === 'open' ? 'Open to every volunteer' : 'Managers assign');
        chips.push(bw.digest ? 'Daily nudge on' : 'No nudges');
        A.$('#assim-bw-recap').innerHTML = chips.map(function (c) { return '<span class="ui-chip">' + esc(c) + '</span>'; }).join('');
    }

    function bwCount() {
        var sig = JSON.stringify(bwRule());
        if (bw.countSig === sig) return;
        bw.countSig = sig;
        bw.count = null;
        var el = A.$('#assim-bw-count');
        el.textContent = '…';
        el.classList.add('animate-pulse');
        A.$('#assim-bw-count-sub').textContent = 'Counting…';
        A.api({ action: 'count_rule', rule: sig }, { quiet: true }).then(function (res) {
            if (!bw || bw.countSig !== sig) return;
            var n = Number(res.data && res.data.count) || 0;
            bw.count = n;
            el.classList.remove('animate-pulse');
            el.textContent = HOD.format.number(n);
            A.$('#assim-bw-count-sub').textContent = n === 1 ? 'person matches right now' : 'people match right now';
        }).catch(function () {
            if (!bw || bw.countSig !== sig) return;
            el.classList.remove('animate-pulse');
            el.textContent = '—';
            A.$('#assim-bw-count-sub').textContent = 'Could not count right now';
        });
    }

    function exportMenu(open) {
        var menu = A.$('#bwExportMenu');
        if (!menu) return;
        var willOpen = open === undefined ? menu.hidden : !!open;
        menu.hidden = !willOpen;
        if (willOpen) requestAnimationFrame(function () { menu.classList.add('is-open'); });
        else menu.classList.remove('is-open');
        A.$('#assim-bw-export-btn').setAttribute('aria-expanded', willOpen ? 'true' : 'false');
        if (willOpen) menu.querySelector('[role="menuitem"]').focus();
    }

    function bwExport(reportType) {
        exportMenu(false);
        if (!bw || bw.busy) return;
        if (bw.count === 0) { HOD.toast('Nobody matches this list yet, so there is nothing to download.', 'warning'); return; }
        A.postExcelExport(reportType, bwRule());
    }

    function openBuilder(btn) {
        if (!A.boot) { HOD.toast('Still loading. One moment.', 'warning'); return; }
        bw = { step: 0, away: 'slip', custom: { max: 3, win: 2, unit: 'months' }, who: {}, gender: '', age: '', mode: 'open', digest: 1, nameTouched: false, busy: false, countSig: null, count: null };
        A.$('#assim-bw-dept').innerHTML = '<option value="0">Every department</option>' + A.boot.departments.map(function (d) { return '<option value="' + esc(d.id) + '">' + esc(d.name) + '</option>'; }).join('');
        A.$('#assim-bw-region').innerHTML = '<option value="0">Every region</option>' + A.boot.regions.map(function (r) { return '<option value="' + esc(r.id) + '">' + esc(r.name) + '</option>'; }).join('');
        A.$('#assim-bw-who').innerHTML = BW_STATUSES.map(function (s) {
            return '<button type="button" class="ui-chip" data-action="assim.bw-who" data-who="' + s[0] + '" aria-pressed="false">' + esc(s[1]) + '</button>';
        }).join('');
        A.$('#assim-bw-gender').innerHTML = [['', 'Everyone'], ['Male', 'Male'], ['Female', 'Female']].map(function (g) {
            return '<button type="button" class="ui-chip" data-action="assim.bw-gender" data-g="' + g[0] + '" aria-pressed="false">' + g[1] + '</button>';
        }).join('');
        A.$('#assim-bw-age').innerHTML = [['', 'Any age']].concat((A.boot.age_bands || []).map(function (a) { return [a, a]; })).map(function (a) {
            return '<button type="button" class="ui-chip" data-action="assim.bw-age" data-age="' + esc(a[0]) + '" aria-pressed="false">' + esc(a[1]) + '</button>';
        }).join('');
        A.$('#assim-bw-unit').value = 'months';
        A.$('#assim-bw-ever').checked = true;
        A.$('#assim-bw-digest').checked = true;
        A.$('#assim-bw-name').value = '';
        bwPaintAll();
        HOD.dialog.open('assim-builder', btn);
        bwShow(0);
    }

    function changed() { if (bw) bw.countSig = null; }

    HOD.actions.on('assim.builder', openBuilder);
    HOD.actions.on('assim.bw-away', function (btn) { bw.away = btn.getAttribute('data-away'); changed(); bwPaintAll(); });
    HOD.actions.on('assim.bw-step', function (btn) {
        var field = btn.getAttribute('data-field'), d = Number(btn.getAttribute('data-delta'));
        if (field === 'max') bw.custom.max = Math.max(1, Math.min(50, bw.custom.max + d));
        else bw.custom.win = Math.max(1, Math.min(104, bw.custom.win + d));
        changed();
        bwPaintAll();
    });
    HOD.actions.on('assim.bw-unit', function (select) { bw.custom.unit = select.value; changed(); });
    HOD.actions.on('assim.bw-everyone', function () { bw.who = {}; changed(); bwPaintAll(); });
    HOD.actions.on('assim.bw-who', function (btn) {
        var k = btn.getAttribute('data-who');
        if (bw.who[k]) delete bw.who[k]; else bw.who[k] = true;
        changed();
        bwPaintAll();
    });
    HOD.actions.on('assim.bw-gender', function (btn) { bw.gender = btn.getAttribute('data-g'); changed(); bwPaintAll(); });
    HOD.actions.on('assim.bw-age', function (btn) { bw.age = btn.getAttribute('data-age'); changed(); bwPaintAll(); });
    HOD.actions.on('assim.bw-changed', changed);
    HOD.actions.on('assim.bw-mode', function (btn) { bw.mode = btn.getAttribute('data-mode'); changed(); bwPaintAll(); });
    HOD.actions.on('assim.bw-digest', function (box) { bw.digest = box.checked ? 1 : 0; changed(); });
    HOD.actions.on('assim.bw-name', function () { if (bw) bw.nameTouched = true; });
    HOD.actions.on('assim.bw-back', function () { if (bw && !bw.busy) bwShow(bw.step - 1); });
    HOD.actions.on('assim.bw-export-menu', function () { exportMenu(); });
    HOD.actions.on('assim.bw-export', function (btn) { bwExport(btn.getAttribute('data-report')); });

    document.addEventListener('click', function (e) {
        var wrap = A.$('#assim-bw-export-wrap');
        if (wrap && !wrap.hidden && !wrap.contains(e.target)) exportMenu(false);
    });
    document.addEventListener('keydown', function (e) {
        var menu = A.$('#bwExportMenu');
        if (!menu || menu.hidden) return;
        if (e.key === 'Escape') { e.stopPropagation(); e.preventDefault(); exportMenu(false); A.$('#assim-bw-export-btn').focus(); return; }
        if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp') return;
        e.preventDefault();
        var items = A.$$('[role="menuitem"]', menu);
        var at = items.indexOf(document.activeElement);
        items[(at + (e.key === 'ArrowDown' ? 1 : items.length - 1)) % items.length].focus();
    }, true);

    HOD.actions.on('assim.bw-next', function () {
        if (!bw || bw.busy) return;
        if (bw.step < BW_TOTAL - 1) { bwShow(bw.step + 1); return; }
        var name = A.$('#assim-bw-name').value.trim();
        if (!name) { HOD.toast('Give the list a short name first.', 'warning'); A.$('#assim-bw-name').focus(); return; }
        bw.busy = true;
        bwFooter();
        A.api({ action: 'save_watchlist', name: name, rule: JSON.stringify(bwRule()), notify: bw.digest ? 1 : 0, is_open: bw.mode === 'open' ? 1 : 0 }).then(function (res) {
            if (res.data && res.data.self_claim_turned_on && A.boot) A.boot.allow_self_claim = true;
            HOD.dialog.close('assim-builder');
            celebrate();
            HOD.toast(res.message || 'Watchlist launched.', 'success');
            refreshWatchlists();
        }).catch(function () {}).then(function () { if (bw) { bw.busy = false; bwFooter(); } });
    });

    /** A short burst of brand-coloured confetti, skipped for reduced motion. */
    function celebrate() {
        if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
        if (!document.body.animate) return;
        var colors = ['#1D356A', '#D11920', '#4A6BB0', '#D98A00', '#157A4A'];
        var host = document.createElement('div');
        host.className = 'pointer-events-none fixed inset-0 z-[10001] overflow-hidden';
        host.setAttribute('aria-hidden', 'true');
        document.body.appendChild(host);
        for (var i = 0; i < 36; i++) {
            var s = document.createElement('span');
            var size = 6 + Math.random() * 7;
            s.style.position = 'absolute';
            s.style.top = '-12px';
            s.style.left = (Math.random() * 100) + '%';
            s.style.width = size + 'px';
            s.style.height = (size * (0.6 + Math.random())) + 'px';
            s.style.background = colors[i % colors.length];
            s.style.borderRadius = Math.random() > 0.5 ? '50%' : '2px';
            host.appendChild(s);
            var fall = window.innerHeight * (0.65 + Math.random() * 0.4);
            s.animate([
                { transform: 'translate3d(0,-12px,0) rotate(0deg)', opacity: 1 },
                { transform: 'translate3d(' + ((Math.random() - 0.5) * 240) + 'px,' + fall + 'px,0) rotate(' + (360 + Math.random() * 540) + 'deg)', opacity: 0.85 }
            ], { duration: 1600 + Math.random() * 1200, easing: 'cubic-bezier(.16,.7,.4,1)', fill: 'forwards' });
        }
        setTimeout(function () { host.remove(); }, 3200);
    }

    /* =================================================================== *
     * Analytics
     * =================================================================== */

    var an = { ready: false, preset: 'this_month' };

    function presetRange(p) {
        var now = new Date(), y = now.getFullYear(), m = now.getMonth();
        switch (p) {
            case 'last_month': return [A.ymd(new Date(y, m - 1, 1)), A.ymd(new Date(y, m, 0))];
            case 'last_3': return [A.ymd(new Date(y, m - 2, 1)), A.ymd(new Date(y, m + 1, 0))];
            case 'ytd': return [y + '-01-01', A.ymd(now)];
            case 'custom': return [val('assim-an-from'), val('assim-an-to')];
            default: return [A.ymd(new Date(y, m, 1)), A.ymd(new Date(y, m + 1, 0))];
        }
    }

    function table(head, rows) {
        return '<div class="ui-table-wrap"><table class="ui-table ui-table--compact"><thead><tr>' + head.map(function (h, i) {
            return '<th scope="col"' + (i && h.num ? ' class="ui-num"' : '') + '>' + esc(h.label) + '</th>';
        }).join('') + '</tr></thead><tbody>' + rows.join('') + '</tbody></table></div>';
    }

    function card(title, body, info) {
        return '<section class="ui-card"><div class="ui-card__header"><h3 class="ui-card__title">' + esc(title) + '</h3>' + (info || '') + '</div><div class="ui-card__body">' + body + '</div></section>';
    }

    function renderAnalytics(d) {
        var k = d.kpis || {};
        var tile = function (label, value) {
            return '<div class="ui-card ui-stat"><span class="ui-stat__label"><span>' + esc(label) + '</span></span><span class="ui-stat__value">' + esc(value) + '</span></div>';
        };
        var none = function (t) { return '<p class="text-sm text-ink-muted">' + esc(t) + '</p>'; };
        var leaders = (d.volunteer_leaderboard || []).length
            ? table([{ label: '#' }, { label: 'Volunteer' }, { label: 'Contacts', num: 1 }, { label: 'Home', num: 1 }], d.volunteer_leaderboard.map(function (v, i) {
                return '<tr><td>' + (i + 1) + '</td><td>' + esc(v.name) + '</td><td class="ui-num">' + esc(v.contacts) + '</td><td class="ui-num">' + esc(v.returned) + '</td></tr>';
            }))
            : none('No follow-up in this period.');
        var watch = (d.watchlists || []).length
            ? table([{ label: 'Watchlist' }, { label: 'Rule' }, { label: 'People', num: 1 }, { label: 'New', num: 1 }], d.watchlists.map(function (w) {
                return '<tr><td>' + esc(w.name) + (Number(w.is_active) ? '' : ' ' + A.badge('Paused', 'outline')) + '</td><td class="text-ink-muted">' + esc(typeof w.rule === 'string' ? w.rule : (w.summary || '')) + '</td>'
                    + '<td class="ui-num">' + esc(w.people) + '</td><td class="ui-num">' + esc(w.new_in_range || 0) + '</td></tr>';
            }))
            : none('No watchlists yet.');
        var statusRows = d.status_breakdown || [], deptRows = d.department_breakdown || [];

        A.$('#assim-an').innerHTML =
            '<div class="ui-grid ui-grid--stats mb-4 grid-cols-2 lg:grid-cols-5">'
            + tile('On a watchlist', HOD.format.number(k.drifted)) + tile('Followed up', HOD.format.number(k.contacted))
            + tile('Returned home', HOD.format.number(k.returned_home)) + tile('Return rate', (k.return_rate || 0) + '%')
            + tile('Days to 1st call', k.median_days === null || k.median_days === undefined ? '—' : k.median_days + 'd')
            + '</div>'
            + (Number(k.overdue) ? '<button type="button" class="ui-banner ui-banner--warning mb-4 w-full text-left" data-action="assim.goto-overdue"><span class="ui-banner__body">'
                + '<span class="ui-banner__title">' + esc(k.overdue) + ' overdue</span><span class="ui-banner__text">Assigned over ' + esc(A.cfg.overdueDays) + ' days ago, nobody has called.</span></span></button>' : '')
            + '<div class="ui-grid ui-grid--2 mb-4">'
            + card('The journey home', '<div class="relative h-64"><canvas id="assim-ch-funnel" role="img" aria-label="' + esc('Journey home: ' + (d.funnel || []).map(function (f) { return f.stage + ' ' + f.count; }).join(', ')) + '"></canvas></div>')
            + card('Home by month', '<div class="relative h-64"><canvas id="assim-ch-trend" role="img" aria-label="' + esc('Returned home each month: ' + (d.returned_trend || []).map(function (t) { return t.label + ' ' + t.count; }).join(', ')) + '"></canvas></div>')
            + '</div><div class="ui-grid ui-grid--2 mb-4">'
            + card('By spiritual status', statusRows.length ? '<div class="relative" data-rows="' + statusRows.length + '"><canvas id="assim-ch-status" role="img" aria-label="Follow-ups and returns by spiritual status"></canvas></div>' : none('Nothing in this period.'))
            + card('By department', deptRows.length ? '<div class="relative" data-rows="' + deptRows.length + '"><canvas id="assim-ch-dept" role="img" aria-label="Follow-ups and returns by department"></canvas></div>' : none('Nothing in this period.'))
            + '</div><div class="ui-grid ui-grid--2">' + card('Volunteer leaderboard', leaders) + card('Watchlists', watch) + '</div>';

        A.$$('#assim-an [data-rows]').forEach(function (box) { box.style.height = Math.max(150, Number(box.getAttribute('data-rows')) * 42 + 40) + 'px'; });

        var ints = { beginAtZero: true, ticks: { precision: 0 } };
        HOD.chart(A.$('#assim-ch-funnel'), {
            type: 'bar',
            data: { labels: (d.funnel || []).map(function (f) { return f.stage; }), datasets: [{ data: (d.funnel || []).map(function (f) { return f.count; }), backgroundColor: HOD.ramp.slice(1, 5), maxBarThickness: 72 }] },
            options: { plugins: { legend: { display: false } }, scales: { y: ints, x: { grid: { display: false } } } }
        }).catch(function () {});
        HOD.chart(A.$('#assim-ch-trend'), {
            type: 'line',
            data: { labels: (d.returned_trend || []).map(function (t) { return t.label; }), datasets: [{ label: 'Returned home', data: (d.returned_trend || []).map(function (t) { return t.count; }), borderColor: HOD.palette[3], backgroundColor: HOD.palette[3], pointRadius: 3 }] },
            options: { plugins: { legend: { display: false } }, scales: { y: ints, x: { grid: { display: false } } } }
        }).catch(function () {});
        [['assim-ch-status', statusRows], ['assim-ch-dept', deptRows]].forEach(function (g) {
            if (!g[1].length) return;
            HOD.chart(A.$('#' + g[0]), {
                type: 'bar',
                data: {
                    labels: g[1].map(function (r) { return r.label; }),
                    datasets: [
                        { label: 'Followed up', data: g[1].map(function (r) { return r.count; }), backgroundColor: HOD.palette[0], maxBarThickness: 12 },
                        { label: 'Returned home', data: g[1].map(function (r) { return r.returned; }), backgroundColor: HOD.palette[3], maxBarThickness: 12 }
                    ]
                },
                options: { indexAxis: 'y', plugins: { legend: { position: 'bottom' } }, scales: { x: ints, y: { grid: { display: false } } } }
            }).catch(function () {});
        });
    }

    function loadAnalytics() {
        var range = presetRange(an.preset);
        if (!range[0] || !range[1]) return;
        A.$('#assim-an').innerHTML = '<div class="ui-grid ui-grid--stats mb-4 grid-cols-2 lg:grid-cols-5">' + new Array(6).join('<div class="ui-skeleton ui-skeleton--block h-20"></div>') + '</div>'
            + '<div class="ui-grid ui-grid--2">' + new Array(3).join('<div class="ui-skeleton ui-skeleton--block h-64"></div>') + '</div>';
        A.api({ action: 'fetch_analytics', from_date: range[0], to_date: range[1] }).then(function (res) {
            renderAnalytics(res.data || {});
        }).catch(function () { A.$('#assim-an').innerHTML = ''; });
    }

    function pickPreset(p) {
        an.preset = p;
        A.$$('[data-action="assim.an-preset"]').forEach(function (b) { b.setAttribute('aria-pressed', b.getAttribute('data-preset') === p ? 'true' : 'false'); });
        A.$('#assim-an-custom').hidden = p !== 'custom';
        loadAnalytics();
    }

    function initAnalytics() {
        if (an.ready) return;
        an.ready = true;
        var r = presetRange('this_month');
        A.$('#assim-an-from').value = r[0];
        A.$('#assim-an-to').value = r[1];
        pickPreset('this_month');
    }

    HOD.actions.on('assim.an-preset', function (btn) { pickPreset(btn.getAttribute('data-preset')); });
    HOD.actions.on('assim.an-load', loadAnalytics);
    HOD.actions.on('assim.goto-overdue', function () { A.showOverdue(); });
    HOD.actions.on('assim.an-pdf', function (btn) {
        var range = presetRange(an.preset);
        HOD.busy(btn, true);
        A.api({ action: 'generate_pdf', from_date: range[0], to_date: range[1] }).then(function (res) {
            var url = res.data && res.data.url && /^\/(?!\/)/.test(res.data.url) ? res.data.url : '';
            HOD.toast('Report ready.', 'success');
            A.$('#assim-an-pdf-ready').innerHTML = url ? '<div class="ui-banner ui-banner--success"><div class="ui-banner__body"><p class="ui-banner__title">Your report is ready</p>'
                + '<div class="ui-banner__actions"><a class="ui-btn ui-btn--secondary ui-btn--sm" href="' + esc(url) + '" target="_blank" rel="noopener">' + HOD.icon('file-down') + '<span>Open PDF</span></a></div></div></div>' : '';
        }).catch(function () {}).then(function () { HOD.busy(btn, false); });
    });

    /* =================================================================== */

    function onBoot() {
        var b = A.boot;
        var fill = function (id, rows, value, label) {
            var sel = A.$('#' + id);
            if (!sel) return;
            var first = sel.options[0] ? sel.options[0].outerHTML : '';
            sel.innerHTML = first + rows.map(function (r) { return '<option value="' + esc(value(r)) + '">' + esc(label(r)) + '</option>'; }).join('');
        };
        fill('assim-f-dept', b.departments || [], function (d) { return d.id; }, function (d) { return d.name; });
        fill('assim-f-region', b.regions || [], function (r) { return r.id; }, function (r) { return r.name; });
        fill('assim-f-age', b.age_bands || [], function (a) { return a; }, function (a) { return a; });
        A.$('#assim-f-status').innerHTML = (b.spiritual_statuses || []).map(function (s) {
            return '<button type="button" class="ui-chip" data-action="assim.status" data-status="' + esc(s) + '" aria-pressed="false">' + esc(A.label(s)) + '</button>';
        }).join('');
        if (!find.ready && b.default_rule) applyRule(b.default_rule);
        if (!A.$('#panel-find').hidden) initFind();
        if (!A.$('#panel-analytics').hidden) initAnalytics();
    }

    function init() {
        document.addEventListener('assim:boot', onBoot);
        document.addEventListener('ui:tab', function (e) {
            var tab = e.detail && e.detail.tab;
            if (tab === 'find' && A.boot) initFind();
            if (tab === 'analytics') initAnalytics();
        });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
