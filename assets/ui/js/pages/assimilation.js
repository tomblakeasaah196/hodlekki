// /assets/ui/js/pages/assimilation.js
// Assimilation (modules/assimilation/index.php), part 1: the shared helpers
// (window.Assim), the bootstrap, the Follow-up tab and its case drawer, the
// Team tab with the volunteer picker, and the settings. Part 2,
// assimilation-find.js, adds Find people, watchlists, the list builder and
// analytics for managers.
// Every request goes to /api/assimilation_api.php (POST, with the session's
// CSRF token, which HOD.api and ui.js add).
(function () {
    'use strict';

    var HOD = window.HOD;
    var API = '/api/assimilation_api.php';
    var cfg = HOD.data('assim-config') || {};
    var esc = HOD.esc;

    var A = window.Assim = { cfg: cfg, boot: null };

    /* =================================================================== *
     * Shared helpers
     * =================================================================== */

    A.api = function (data, opts) {
        return HOD.api(API, { data: data, quiet: opts && opts.quiet });
    };
    A.$ = function (sel, scope) { return (scope || document).querySelector(sel); };
    A.$$ = function (sel, scope) { return Array.prototype.slice.call((scope || document).querySelectorAll(sel)); };
    A.label = function (s) { return String(s || '').replace(/_/g, ' '); };
    A.STATUS_WORDS = {
        To_Call: 'To call', Reached: 'Reached', Promised: 'Promised to come', Returned_Home: 'Returned home',
        Unreachable: 'Could not reach them', Not_Interested: 'Not interested for now',
        Relocated: 'Has relocated', Attends_Elsewhere: 'Attends another church'
    };
    A.STATUS_TONE = { To_Call: 'info', Reached: 'warning', Promised: 'primary', Returned_Home: 'success' };
    A.word = function (s) { return A.STATUS_WORDS[s] || A.label(s); };
    var CHANNEL_WORDS = { Call: 'Call', WhatsApp: 'WhatsApp', SMS: 'SMS', Visit: 'Visit', At_Church: 'At church' };
    var OUTCOME_WORDS = {
        Spoke_With_Them: 'We spoke', Promised_To_Come: 'Promised to come', No_Answer: 'No answer',
        Wrong_Number: 'Wrong number', Not_Interested: 'Not interested', Relocated: 'Has relocated',
        Attends_Elsewhere: 'Attends elsewhere', Asked_For_No_Contact: 'Asked for no contact'
    };
    A.parseDate = function (s) {
        if (!s) return null;
        var d = new Date(String(s).replace(' ', 'T'));
        return isNaN(d.getTime()) ? null : d;
    };
    A.relTime = function (s) {
        var d = A.parseDate(s);
        if (!d) return '';
        var mins = Math.round((Date.now() - d.getTime()) / 60000);
        if (mins < 1) return 'just now';
        if (mins < 60) return mins + 'm ago';
        if (mins < 1440) return Math.round(mins / 60) + 'h ago';
        if (mins < 10080) return Math.round(mins / 1440) + 'd ago';
        return HOD.format.date(d);
    };
    A.niceDate = function (s) {
        var d = A.parseDate(s);
        return d ? d.toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short' }) : '';
    };
    A.ymd = function (d) {
        var p = function (n) { return String(n).padStart(2, '0'); };
        return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate());
    };
    A.badge = function (text, tone) {
        return '<span class="ui-badge' + (tone ? ' ui-badge--' + tone : '') + '">' + esc(text) + '</span>';
    };
    A.btn = function (label, action, data, variant, icon) {
        var attrs = '';
        Object.keys(data || {}).forEach(function (k) { attrs += ' data-' + k + '="' + esc(data[k] == null ? '' : String(data[k])) + '"'; });
        return '<button type="button" class="ui-btn ui-btn--sm ui-btn--' + (variant || 'secondary') + '" data-action="' + action + '"' + attrs + '>'
            + (icon ? HOD.icon(icon) : '') + '<span>' + esc(label) + '</span></button>';
    };
    A.empty = function (title, text, actions) {
        return '<div class="ui-empty col-span-full"><p class="ui-empty__title">' + esc(title) + '</p>'
            + (text ? '<p class="ui-empty__text">' + esc(text) + '</p>' : '')
            + (actions ? '<div class="ui-empty__actions">' + actions + '</div>' : '') + '</div>';
    };
    A.skeleton = function (n) {
        var one = '<div class="ui-card ui-card__body space-y-2" aria-hidden="true"><div class="ui-skeleton ui-skeleton--title w-1/2"></div>'
            + '<div class="ui-skeleton ui-skeleton--text w-3/4"></div><div class="ui-skeleton ui-skeleton--text w-1/3"></div></div>';
        return new Array((n || 3) + 1).join(one);
    };
    A.avatar = function (name, pic) {
        var url = /^(https?:\/\/|\/(?!\/))/i.test(String(pic || '')) ? pic : '';
        var h = 0;
        for (var i = 0; i < String(name).length; i++) h = (h * 31 + String(name).charCodeAt(i)) >>> 0;
        return '<span class="ui-avatar ui-avatar--c' + ((h % 6) + 1) + '">'
            + (url ? '<img src="' + esc(url) + '" alt="" loading="lazy">' : '<span aria-hidden="true">' + esc(String(name || '?').charAt(0).toUpperCase()) + '</span>')
            + '</span>';
    };
    /** Monthly attendance as small bars (SVG attributes, so no inline style). */
    A.sparkline = function (values, label) {
        if (!values || !values.length) return '';
        var max = Math.max.apply(null, [1].concat(values));
        var w = 6, gap = 2, h = 24;
        var bars = values.map(function (v, i) {
            var bh = Math.max(2, Math.round(h * v / max));
            return '<rect x="' + (i * (w + gap)) + '" y="' + (h - bh) + '" width="' + w + '" height="' + bh + '" rx="1.5"></rect>';
        }).join('');
        return '<svg class="shrink-0 text-primary" width="' + (values.length * (w + gap) - gap) + '" height="' + h + '" fill="currentColor" role="img" aria-label="'
            + esc(label || 'Attendance by month: ' + values.join(', ')) + '">' + bars + '</svg>';
    };
    A.debounce = HOD.debounce;

    /**
     * The one shared Excel download (Find people's chooser and the builder's
     * last step both call it): a POST form to a new tab, CSRF token included.
     */
    A.postExcelExport = function (reportType, rule) {
        if (['general', 'detailed'].indexOf(reportType) === -1) return;
        var form = document.createElement('form');
        form.method = 'post';
        form.action = API;
        form.target = '_blank';
        [['action', 'export_excel'], ['report_type', reportType], ['rule', JSON.stringify(rule)], ['_csrf', HOD.csrf()]].forEach(function (f) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = f[0];
            input.value = f[1];
            form.appendChild(input);
        });
        document.body.appendChild(form);
        form.submit();
        form.remove();
    };

    function fillTeamSelect(select, keepFirst) {
        if (!select || !A.boot) return;
        var first = A.$$('option', select).slice(0, keepFirst).map(function (o) { return o.outerHTML; }).join('');
        select.innerHTML = first + A.boot.team.map(function (t) {
            return '<option value="' + esc(t.user_id) + '">' + esc(t.name) + '</option>';
        }).join('');
    }

    /* =================================================================== *
     * Bootstrap
     * =================================================================== */

    function boot() {
        return A.api({ action: 'bootstrap' }).then(function (res) {
            A.boot = res.data;
            fillTeamSelect(A.$('#assim-bulk-assignee'), 1);
            fillTeamSelect(A.$('#assim-c-assignee'), 3);
            var cel = A.boot.celebrations || [];
            var bar = A.$('#assim-celebration');
            if (cel.length && bar) {
                bar.innerHTML = '<div class="ui-banner ui-banner--success"><div class="ui-banner__body">'
                    + '<p class="ui-banner__title">Back in church</p>'
                    + '<p class="ui-banner__text">' + esc(cel.map(function (c) { return c.name; }).join(', ')) + ': marked Returned home.</p></div></div>';
                bar.hidden = false;
            }
            document.dispatchEvent(new CustomEvent('assim:boot'));
        }).catch(function () {});
    }
    A.reboot = boot;

    /* =================================================================== *
     * Follow-up
     * =================================================================== */

    var cases = { sub: 'to_call', page: 1, overdue: false, ready: false, caseId: null };
    var STAGES = [['to_call', 'To call'], ['reached', 'Reached'], ['promised', 'Promised'], ['returned_home', 'Home'], ['closed', 'Closed'], ['all', 'All']];

    function renderStages(counts) {
        A.$('#assim-stages').innerHTML = STAGES.map(function (s) {
            return '<button type="button" class="ui-chip" data-action="assim.stage" data-stage="' + s[0] + '" aria-pressed="' + (cases.sub === s[0] ? 'true' : 'false') + '">'
                + esc(s[1]) + ' <span class="ui-count">' + esc(counts[s[0]] != null ? counts[s[0]] : 0) + '</span></button>';
        }).join('');
    }

    function caseCard(c) {
        var note = c.last_note ? String(c.last_note).split('\n')[0] : '';
        return '<button type="button" class="ui-card ui-card--interactive block w-full space-y-2 p-4 text-left" data-action="assim.case" data-id="' + esc(c.id) + '">'
            + '<span class="flex items-start justify-between gap-2"><span class="min-w-0">'
            + '<span class="block truncate font-semibold text-ink">' + esc(c.person_name) + '</span>'
            + '<span class="block text-sm text-ink-subtle">' + esc(c.phone || 'No phone') + '</span></span>'
            + '<span class="flex shrink-0 flex-wrap justify-end gap-1">' + (c.is_overdue ? A.badge('Overdue', 'danger') : '') + (c.returned_home_at ? A.badge('Home', 'success') : '') + '</span></span>'
            + '<span class="flex flex-wrap gap-1">' + A.badge(A.word(c.status), A.STATUS_TONE[c.status] || 'outline') + A.badge(A.label(c.spiritual_status), 'outline') + '</span>'
            + '<span class="block text-sm text-ink-muted">Last in church ' + esc(c.since_words) + ' · ' + esc(c.touches) + (Number(c.touches) === 1 ? ' contact' : ' contacts') + '</span>'
            + (note ? '<span class="block truncate text-sm italic text-ink-subtle">' + esc(note) + '</span>' : '')
            + '<span class="flex flex-wrap justify-between gap-2 text-xs text-ink-subtle"><span>' + esc(c.assignee_name || 'Unclaimed') + '</span>'
            + (c.next_touch_date ? '<span>Next ' + esc(A.niceDate(c.next_touch_date)) + '</span>' : '') + '</span>'
            + '</button>';
    }

    function loadCases(reset) {
        reset = reset !== false;
        cases.page = reset ? 1 : cases.page + 1;
        var list = A.$('#assim-cases');
        if (reset) list.innerHTML = A.skeleton(3);
        return A.api({
            action: 'list_cases', sub_tab: cases.sub, page: cases.page,
            search: A.$('#assim-c-search').value.trim(), assignee: A.$('#assim-c-assignee').value,
            watchlist_id: A.$('#assim-c-watchlist').value, overdue_only: cases.overdue ? 1 : 0
        }).then(function (res) {
            var d = res.data;
            renderStages(d.counts || {});
            var overdue = Number((d.counts || {}).overdue || 0);
            A.$('#assim-overdue-count').textContent = overdue;
            A.$('#assim-overdue-hint').textContent = cases.overdue
                ? 'Showing only these. Tap to show everyone.'
                : 'Assigned over ' + (cfg.overdueDays || 7) + ' days ago, nobody has called. Tap to show only these.';
            var banner = A.$('#assim-overdue');
            banner.hidden = overdue === 0 && !cases.overdue;
            banner.setAttribute('aria-pressed', cases.overdue ? 'true' : 'false');
            banner.classList.toggle('ring-2', cases.overdue);
            banner.classList.toggle('ring-danger', cases.overdue);
            var html = (d.cases || []).map(caseCard).join('');
            if (reset) list.innerHTML = html || A.empty('Nothing in this stage', cfg.manager ? 'Try another stage, or find people to follow up.' : 'Nobody is waiting on you right now.');
            else list.insertAdjacentHTML('beforeend', html);
            A.$('#assim-cases-more').hidden = !d.has_more;
        }).catch(function () { if (reset) list.innerHTML = ''; });
    }
    A.loadCases = loadCases;

    function initCases() {
        if (cases.ready) return;
        cases.ready = true;
        loadCases();
    }
    A.showOverdue = function () {
        cases.overdue = true;
        HOD.tabs.select(A.$('[data-ui-tabs]'), 'followup');
        if (cases.ready) loadCases(); else initCases();
    };

    HOD.actions.on('assim.stage', function (btn) { cases.sub = btn.getAttribute('data-stage'); loadCases(); });
    HOD.actions.on('assim.overdue', function () { cases.overdue = !cases.overdue; loadCases(); });
    HOD.actions.on('assim.cases', function () { loadCases(); });
    HOD.actions.on('assim.cases-search', A.debounce(function () { loadCases(); }, 400));
    HOD.actions.on('assim.cases-more', function () { loadCases(false); });

    /* ----- The case drawer --------------------------------------------- */

    function openCase(id) {
        cases.caseId = id;
        A.$('#assim-case-title').textContent = 'Loading…';
        A.$('#assim-case-desc').textContent = '';
        A.$('#assim-case-body').innerHTML = A.skeleton(2);
        HOD.dialog.open('assim-case');
        return A.api({ action: 'case_detail', case_id: id }).then(function (res) {
            if (cases.caseId === id) renderDrawer(res.data);
        }).catch(function () { HOD.dialog.close('assim-case'); });
    }
    A.openCase = openCase;
    HOD.actions.on('assim.case', function (btn) { openCase(btn.getAttribute('data-id')); });

    function section(title, body) {
        return '<section><h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-ink-subtle">' + esc(title) + '</h3>' + body + '</section>';
    }
    function field(k, v) {
        return v ? '<div><dt>' + esc(k) + '</dt><dd>' + esc(v) + '</dd></div>' : '';
    }

    function renderDrawer(d) {
        var c = d.case, p = d.person;
        var name = ((p.first_name || '') + ' ' + (p.last_name || '')).trim();
        A.$('#assim-case-title').textContent = name;
        A.$('#assim-case-desc').textContent = A.word(c.status) + ' · last in church ' + d.since_words;

        var wa = String(p.phone || '').replace(/\D/g, '').replace(/^0/, '234');
        var essentials = '<div class="mb-3 flex flex-wrap gap-1">' + A.badge(A.label(p.spiritual_status), 'outline')
            + (c.is_overdue ? A.badge('Overdue', 'danger') : '') + (c.returned_home_at ? A.badge('Returned home', 'success') : '') + '</div>'
            + (p.phone ? '<div class="mb-3 grid grid-cols-2 gap-2">'
                + '<a class="ui-btn ui-btn--primary" href="tel:' + esc(p.phone) + '">' + HOD.icon('phone') + '<span>Call</span></a>'
                + '<a class="ui-btn ui-btn--secondary" href="https://wa.me/' + esc(wa) + '" target="_blank" rel="noopener">' + HOD.icon('message-circle') + '<span>WhatsApp</span></a></div>' : '')
            + '<dl class="ui-dl ui-dl--stacked text-sm">'
            + field('Phone', p.phone) + field('Departments', p.departments) + field('Region', p.region_name)
            + field('Last in church', d.last_attended ? A.niceDate(d.last_attended) + ' · ' + d.since_words : 'No record at all')
            + field('Volunteer', c.assignee_name || 'Unclaimed')
            + field('Next touch', c.next_touch_date ? A.niceDate(c.next_touch_date) : '—')
            + field('Follow-up opened', A.relTime(c.opened_at) + ' · away ' + String(d.opened_gap || '').replace(' ago', '') + ' then')
            + '</dl>'
            + ((d.cross_links || []).length ? '<div class="mt-3 flex flex-wrap gap-2">' + d.cross_links.map(function (l) {
                var url = /^\/(?!\/)/.test(String(l.url || '')) ? l.url : '#';
                return '<a class="ui-btn ui-btn--ghost ui-btn--sm" href="' + esc(url) + '">' + HOD.icon('external-link') + '<span>' + esc(l.label) + '</span></a>';
            }).join('') + '</div>' : '');

        var months = d.months || [];
        var timeline = A.sparkline(months.map(function (m) { return Number(m.count) || 0; }),
            'Services attended each month: ' + months.map(function (m) { return m.label + ' ' + m.count; }).join(', '))
            + '<p class="mt-1 text-xs text-ink-subtle">' + esc(months.length ? months[0].label + ' to ' + months[months.length - 1].label : '') + '</p>'
            + ((d.recent_days || []).length
                ? '<p class="mt-2 text-sm text-ink-muted"><span class="font-medium text-ink">Last ' + d.recent_days.length + ':</span> ' + d.recent_days.map(function (x) { return esc(A.niceDate(x)); }).join(' · ') + '</p>'
                : '<p class="mt-2 text-sm text-ink-muted">No attendance on record at all.</p>');

        var team = A.boot ? A.boot.team : [];
        var actions = '';
        if (d.can_assign) {
            actions += '<div class="flex flex-wrap items-end gap-2"><div class="min-w-0 flex-1"><label class="ui-label" for="assim-assign-select">Assign to</label>'
                + '<select class="ui-select" id="assim-assign-select"><option value="">Choose a volunteer</option>'
                + team.map(function (m) { return '<option value="' + esc(m.user_id) + '"' + (Number(m.user_id) === Number(c.assigned_to) ? ' selected' : '') + '>' + esc(m.name) + '</option>'; }).join('')
                + '</select></div>' + A.btn(c.assigned_to ? 'Reassign' : 'Assign', 'assim.assign', { id: c.id, re: c.assigned_to ? 1 : 0 }, 'primary') + '</div>';
            if (c.assigned_to) actions += A.btn('Back to pool', 'assim.unassign', { id: c.id }, 'ghost');
        }
        if (!c.assigned_to && !c.closed_at) actions += A.btn('I’ll call them', 'assim.claim', { id: c.id }, 'primary', 'phone');
        if (!c.returned_home_at) {
            actions += '<form class="flex flex-wrap items-end gap-2" data-action="assim.home" data-id="' + esc(c.id) + '">'
                + '<div><label class="ui-label" for="assim-home-date">Back in church on</label>'
                + '<input class="ui-input" type="date" id="assim-home-date" name="came_on" required max="' + A.ymd(new Date()) + '" value="' + A.ymd(new Date()) + '"></div>'
                + '<button type="submit" class="ui-btn ui-btn--secondary">' + HOD.icon('house') + '<span>They came back</span></button></form>';
        }
        if (c.closed_at && d.can_assign) actions += A.btn('Reopen', 'assim.reopen', { id: c.id }, 'secondary', 'refresh-cw');

        var logForm = c.closed_at
            ? '<p class="rounded-ui bg-surface-sunken p-3 text-sm text-ink-muted">Closed ' + esc(A.relTime(c.closed_at)) + (c.outcome ? ': ' + esc(c.outcome) : '') + '.'
                + (d.can_assign ? ' Reopen it to log more.' : '') + '</p>'
            : '<form class="ui-form" id="assim-log-form" data-action="assim.log">'
                + '<input type="hidden" name="action" value="log_follow_up"><input type="hidden" name="case_id" value="' + esc(c.id) + '">'
                + '<input type="hidden" name="notes_clean" id="assim-log-clean">'
                + '<div class="ui-form-grid">'
                + '<div class="ui-field"><label class="ui-label" for="assim-log-channel">How</label><select class="ui-select" id="assim-log-channel" name="channel" required>'
                + (d.channels || []).map(function (ch) { return '<option value="' + esc(ch) + '">' + esc(CHANNEL_WORDS[ch] || A.label(ch)) + '</option>'; }).join('') + '</select></div>'
                + '<div class="ui-field"><label class="ui-label" for="assim-log-outcome">How it went</label><select class="ui-select" id="assim-log-outcome" name="outcome" required>'
                + (d.outcomes || []).map(function (o) { return '<option value="' + esc(o) + '">' + esc(OUTCOME_WORDS[o] || A.label(o)) + '</option>'; }).join('') + '</select></div>'
                + '</div>'
                + '<div class="ui-field"><label class="ui-label" for="assim-log-notes">Notes</label>'
                + '<textarea class="ui-textarea" id="assim-log-notes" name="notes" rows="3" placeholder="What did they say? What are they carrying?"></textarea>'
                + '<div class="mt-2">' + A.btn('Tidy with AI', 'assim.tidy', {}, 'ghost', 'sparkles') + '</div>'
                + '<p class="ui-hint whitespace-pre-line" id="assim-log-clean-preview" hidden></p></div>'
                + '<div class="ui-field"><label class="ui-label" for="assim-log-prayer">Prayer points</label>'
                + '<textarea class="ui-textarea" id="assim-log-prayer" name="prayer_points" rows="2"></textarea></div>'
                + '<div class="ui-field"><label class="ui-label" for="assim-log-next">Next touch</label>'
                + '<input class="ui-input" type="date" id="assim-log-next" name="next_touch_date"></div>'
                + '<div class="flex justify-end"><button type="submit" class="ui-btn ui-btn--primary"><span>Save follow-up</span></button></div>'
                + '</form>';

        var timelineItems = (d.follow_ups || []).length ? '<ol class="space-y-3">' + d.follow_ups.map(function (f) {
            return '<li class="rounded-ui border border-line p-3 text-sm">'
                + '<div class="mb-1 flex flex-wrap items-center gap-2">' + A.badge(OUTCOME_WORDS[f.outcome] || A.label(f.outcome), 'outline')
                + '<span class="text-ink-muted">' + esc(CHANNEL_WORDS[f.channel] || A.label(f.channel)) + '</span>'
                + '<span class="text-ink-subtle">' + esc((f.logged_by_name || '—') + ' · ' + A.relTime(f.created_at)) + '</span></div>'
                + (f.notes_clean ? '<p class="whitespace-pre-line text-ink">' + esc(f.notes_clean) + '</p>' : '')
                + (f.notes ? '<details class="mt-1"><summary class="cursor-pointer text-ink-muted">' + (f.notes_clean ? 'Their own words' : 'Notes') + '</summary><p class="mt-1 whitespace-pre-line text-ink-muted">' + esc(f.notes) + '</p></details>' : '')
                + (f.prayer_points ? '<p class="mt-1 text-ink-muted"><span class="font-medium text-ink">Prayer:</span> ' + esc(f.prayer_points) + '</p>' : '')
                + (f.next_touch_date ? '<p class="mt-1 text-xs text-ink-subtle">Next touch: ' + esc(A.niceDate(f.next_touch_date)) + '</p>' : '')
                + '</li>';
        }).join('') + '</ol>' : '<p class="text-sm text-ink-muted">Nobody has reached out yet.</p>';

        var sms = (d.sms_notes || []).length ? d.sms_notes.map(function (n) {
            var words = { delivered: 'Delivered', failed: 'Not delivered', unknown: 'Outcome unknown' };
            return '<div class="rounded-ui bg-surface-sunken p-3 text-sm"><p class="text-xs text-ink-subtle">SMS ' + esc(A.relTime(n.sent_at)) + (n.sent_by ? ' by ' + esc(n.sent_by) : '')
                + (words[n.status] ? ' · ' + words[n.status] : '') + '</p><p class="whitespace-pre-line text-ink-muted">' + esc(n.body) + '</p></div>';
        }).join('') : '';

        var history = (d.assignments || []).length
            ? section('Assignment history', '<ul class="space-y-1 text-sm text-ink-muted">' + d.assignments.map(function (a) {
                return '<li><span class="font-medium text-ink">' + esc(A.label(a.action)) + '</span>' + (a.to_name ? ' → ' + esc(a.to_name) : '')
                    + ' by ' + esc(a.by_name || '—') + ' · ' + esc(A.relTime(a.created_at)) + '</li>';
            }).join('') + '</ul>')
            : '';

        var closeBlock = c.closed_at ? '' : section('Close this follow-up',
            '<div class="flex flex-wrap items-end gap-2"><div class="min-w-0 flex-1"><label class="ui-label" for="assim-close-reason">Reason</label>'
            + '<select class="ui-select" id="assim-close-reason"><option value="Unreachable">Could not reach them</option><option value="Not_Interested">Not interested for now</option>'
            + '<option value="Relocated">Has relocated</option><option value="Attends_Elsewhere">Attends another church</option></select></div>'
            + A.btn('Close case', 'assim.close', { id: c.id }, 'danger-ghost') + '</div>');

        A.$('#assim-case-body').innerHTML = section('Call essentials', essentials)
            + section('The last year', timeline)
            + (actions ? section('Actions', '<div class="flex flex-col items-start gap-3">' + actions + '</div>') : '')
            + (sms ? section('Last SMS', '<div class="space-y-2">' + sms + '</div>') : '')
            + section(c.closed_at ? 'This follow-up' : 'Log a follow-up', logForm)
            + section('What has happened', timelineItems)
            + history
            + closeBlock;
    }

    function caseAction(btn, data) {
        HOD.busy(btn, true);
        return A.api(data).then(function (res) {
            HOD.toast(res.message || 'Done.', 'success');
            openCase(data.case_id);
            loadCases();
        }).catch(function () { HOD.busy(btn, false); });
    }

    HOD.actions.on('assim.assign', function (btn) {
        var to = A.$('#assim-assign-select').value;
        if (!to) { HOD.toast('Choose a volunteer first.', 'warning'); return; }
        caseAction(btn, { action: btn.getAttribute('data-re') === '1' ? 'reassign' : 'assign_case', case_id: btn.getAttribute('data-id'), to_user_id: to });
    });
    HOD.actions.on('assim.unassign', function (btn) { caseAction(btn, { action: 'unassign', case_id: btn.getAttribute('data-id') }); });
    HOD.actions.on('assim.claim', function (btn) { caseAction(btn, { action: 'self_claim', case_id: btn.getAttribute('data-id') }); });
    HOD.actions.on('assim.reopen', function (btn) { caseAction(btn, { action: 'reopen_case', case_id: btn.getAttribute('data-id') }); });
    HOD.actions.on('assim.home', function (form, event) {
        var btn = (event && event.submitter) || form.querySelector('[type="submit"]');
        var when = form.elements.came_on.value;
        if (!when) { HOD.toast('Pick the date they came back.', 'warning'); return; }
        caseAction(btn, { action: 'mark_returned_home', case_id: form.getAttribute('data-id'), came_on: when });
    });
    HOD.actions.on('assim.close', function (btn) {
        var status = A.$('#assim-close-reason').value;
        HOD.confirm({ title: 'Close this follow-up?', message: 'It stays in the record and can be reopened.', confirmLabel: 'Close case' }).then(function (ok) {
            if (ok) caseAction(btn, { action: 'close_case', case_id: btn.getAttribute('data-id'), status: status });
        });
    });

    HOD.actions.on('assim.log', function (form, event) {
        var btn = (event && event.submitter) || form.querySelector('[type="submit"]');
        HOD.busy(btn, true);
        A.api(new FormData(form)).then(function (res) {
            HOD.toast(res.message || 'Follow-up saved.', 'success');
            openCase(form.elements.case_id.value);
            loadCases();
        }).catch(function () { HOD.busy(btn, false); });
    });

    HOD.actions.on('assim.tidy', function (btn) {
        var notes = (A.$('#assim-log-notes').value || '').trim();
        if (!notes) { HOD.toast('Write the notes first.', 'warning'); A.$('#assim-log-notes').focus(); return; }
        HOD.busy(btn, true);
        A.api({ action: 'clean_notes', notes: notes }).then(function (res) {
            var clean = res.data && res.data.notes_clean;
            A.$('#assim-log-clean').value = clean || '';
            var preview = A.$('#assim-log-clean-preview');
            preview.textContent = clean ? 'Tidied: ' + clean : '';
            preview.hidden = !clean;
        }).catch(function () {}).then(function () { HOD.busy(btn, false); });
    });

    /* =================================================================== *
     * Team
     * =================================================================== */

    var team = { ready: false, rows: [], picked: {} };

    function renderTeam() {
        var grid = A.$('#assim-team');
        if (!team.rows.length) {
            grid.innerHTML = A.empty('No volunteers yet', 'Add the people who will make the calls.',
                cfg.manager ? A.btn('Add volunteers', 'assim.picker', {}, 'primary', 'user-round-plus') : '');
            return;
        }
        grid.innerHTML = team.rows.map(function (t) {
            return '<article class="ui-card ui-card__body space-y-3">'
                + '<div class="flex items-center gap-3">' + A.avatar(t.name, t.picture_path)
                + '<div class="min-w-0"><p class="truncate font-semibold text-ink">' + esc(t.name) + '</p>'
                + '<p class="truncate text-sm text-ink-subtle">' + esc([t.phone, A.label(t.spiritual_status)].filter(Boolean).join(' · ')) + '</p></div></div>'
                + '<dl class="grid grid-cols-3 gap-2 text-center">'
                + '<div class="rounded-ui bg-surface-sunken p-2"><dt class="text-xs text-ink-subtle">Open</dt><dd class="font-semibold tabular-nums">' + esc(t.open_cases) + '</dd></div>'
                + '<div class="rounded-ui bg-surface-sunken p-2"><dt class="text-xs text-ink-subtle">This month</dt><dd class="font-semibold tabular-nums">' + esc(t.contacts_this_month) + '</dd></div>'
                + '<div class="rounded-ui bg-surface-sunken p-2"><dt class="text-xs text-ink-subtle">Home</dt><dd class="font-semibold tabular-nums">' + esc(t.returned_home) + '</dd></div></dl>'
                + '<div class="flex items-center justify-between gap-2"><p class="text-xs text-ink-subtle">Last active ' + esc(t.last_active ? A.relTime(t.last_active) : 'never') + '</p>'
                + (cfg.manager ? A.btn('Remove', 'assim.team-remove', { id: t.user_id, name: t.name }, 'danger-ghost') : '') + '</div>'
                + '</article>';
        }).join('');
    }

    function loadTeam() {
        A.$('#assim-team').innerHTML = A.skeleton(3);
        return A.api({ action: 'team_list' }).then(function (res) {
            team.rows = res.data || [];
            renderTeam();
        }).catch(function () { A.$('#assim-team').innerHTML = ''; });
    }
    function initTeam() {
        if (team.ready) return;
        team.ready = true;
        loadTeam();
    }

    HOD.actions.on('assim.team-remove', function (btn) {
        HOD.confirm({ title: 'Remove volunteer?', message: btn.getAttribute('data-name') + ' leaves the team. Their follow-up history is kept.', confirmLabel: 'Remove', tone: 'danger' }).then(function (ok) {
            if (!ok) return;
            HOD.busy(btn, true);
            A.api({ action: 'team_remove', user_id: btn.getAttribute('data-id') }).then(function (res) {
                HOD.toast(res.message || 'Removed.', 'success');
                team.rows = res.data || [];
                renderTeam();
                boot();
            }).catch(function () { HOD.busy(btn, false); });
        });
    });

    /* ----- The volunteer picker ---------------------------------------- */

    function pickCount() {
        var n = Object.keys(team.picked).length;
        A.setAddLabel(n);
    }
    A.setAddLabel = function (n) {
        var btn = A.$('#assim-picker-add');
        var spans = btn.querySelectorAll('span:not(.ui-btn__spinner)');
        if (spans.length) spans[spans.length - 1].textContent = n ? 'Add ' + n : 'Add';
    };

    function searchPicker(q) {
        var list = A.$('#assim-picker-list');
        list.innerHTML = '<div class="ui-skeleton ui-skeleton--block h-24"></div>';
        return A.api({ action: 'search_users', q: q }).then(function (res) {
            var rows = res.data || [];
            list.innerHTML = rows.length ? rows.map(function (u) {
                return '<label class="flex cursor-pointer items-center gap-3 rounded-ui p-2 hover:bg-surface-hover">'
                    + '<input type="checkbox" class="ui-checkbox" data-action-change="assim.pick" data-id="' + esc(u.id) + '"'
                    + (u.on_team ? ' disabled' : '') + (team.picked[u.id] ? ' checked' : '') + '>'
                    + A.avatar(u.name, u.picture_path)
                    + '<span class="min-w-0 flex-1"><span class="flex flex-wrap items-center gap-2 font-medium text-ink">' + esc(u.name) + (u.on_team ? A.badge('On the team', 'success') : '') + '</span>'
                    + '<span class="block truncate text-sm text-ink-subtle">' + esc([u.phone || 'No phone', A.label(u.spiritual_status), u.departments].filter(Boolean).join(' · ')) + '</span>'
                    + '<span class="block text-xs text-ink-subtle">Last in church ' + esc(u.since_words) + '</span></span></label>';
            }).join('') : '<p class="p-3 text-sm text-ink-muted">Nobody matched that search.</p>';
        }).catch(function () { list.innerHTML = ''; });
    }

    HOD.actions.on('assim.picker', function (btn) {
        team.picked = {};
        pickCount();
        A.$('#assim-picker-search').value = '';
        HOD.dialog.open('assim-picker', btn);
        searchPicker('');
    });
    HOD.actions.on('assim.picker-search', A.debounce(function (input) { searchPicker(input.value); }, 300));
    HOD.actions.on('assim.pick', function (box) {
        var id = box.getAttribute('data-id');
        if (box.checked) team.picked[id] = true; else delete team.picked[id];
        pickCount();
    });
    HOD.actions.on('assim.picker-add', function (btn) {
        var ids = Object.keys(team.picked);
        if (!ids.length) { HOD.toast('Pick at least one person.', 'warning'); return; }
        HOD.busy(btn, true);
        A.api({ action: 'team_add', user_ids: ids }).then(function (res) {
            HOD.toast(res.message || 'Added.', 'success');
            team.rows = res.data || [];
            renderTeam();
            HOD.dialog.close('assim-picker');
            boot();
        }).catch(function () {}).then(function () { HOD.busy(btn, false); });
    });

    /* =================================================================== *
     * Settings (managers)
     * =================================================================== */

    function loadSettings(focus) {
        return A.api({ action: 'fetch_settings' }).then(function (res) {
            var d = res.data || {};
            var r = d.default_rule || {};
            A.$('#assim-set-overdue').value = d.overdue_days || '';
            A.$('#assim-set-claim').checked = !!d.allow_self_claim;
            A.$('#assim-set-max').value = r.max_services || '';
            A.$('#assim-set-window').value = r.window_value || '';
            A.$('#assim-set-unit').value = r.window_unit || 'months';
            A.$('#assim-set-ever').checked = !!r.ever_attended;
            A.$('#assim-set-guide').value = d.guide || '';
            if (focus === 'guide') A.$('#assim-set-guide').focus();
        }).catch(function () {});
    }
    HOD.actions.on('assim.settings', function (btn) {
        HOD.dialog.open('assim-settings', btn);
        loadSettings(btn.getAttribute('data-focus'));
    });

    function saveSettings(form) {
        var fd = new FormData(form);
        fd.append('action', 'save_settings');
        return A.api(fd);
    }
    HOD.actions.on('assim.settings-save', function (form, event) {
        var btn = (event && event.submitter) || A.$('[form="assim-settings-form"]');
        HOD.busy(btn, true);
        saveSettings(form).then(function (res) {
            HOD.toast(res.message || 'Settings saved.', 'success');
            HOD.dialog.close('assim-settings');
        }).catch(function () {}).then(function () { HOD.busy(btn, false); });
    });
    HOD.actions.on('assim.guide-restore', function (btn) {
        HOD.confirm({ title: 'Restore the default guide?', message: 'Your edits to the volunteer guide will be lost.', confirmLabel: 'Restore', tone: 'danger' }).then(function (ok) {
            if (!ok) return;
            A.$('#assim-set-guide').value = '';
            HOD.busy(btn, true);
            saveSettings(A.$('#assim-settings-form')).then(function () {
                HOD.toast('Default guide restored. Reload to see it.', 'success');
                loadSettings();
            }).catch(function () {}).then(function () { HOD.busy(btn, false); });
        });
    });

    /* =================================================================== */

    function init() {
        document.addEventListener('ui:tab', function (e) {
            var tab = e.detail && e.detail.tab;
            if (tab === 'followup') initCases();
            if (tab === 'team') initTeam();
        });
        boot().then(function () {
            if (!A.$('#panel-followup').hidden) initCases();
            if (!A.$('#panel-team').hidden) initTeam();
        });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
