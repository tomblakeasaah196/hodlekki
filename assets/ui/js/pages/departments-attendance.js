// /assets/ui/js/pages/departments-attendance.js
// The Attendance tab of Departments (modules/departments/index.php): pick an
// event (a workers' meeting), see every worker on the live ministry year's
// rosters as present or absent, by department, clock people in and export the
// absent list. Data from /api/department_api.php (attendance_*) through
// HOD.api; the roll is polled while Live is on and the tab is open.
// Names and phone numbers come from people, so they go in as TEXT (DOM nodes).
(function () {
    'use strict';

    var HOD = window.HOD;
    if (!HOD || !HOD.actions) return;
    var boot = HOD.data('departments-boot');
    var panel = document.getElementById('panel-attendance');
    if (!boot || !panel) return;

    var API = boot.api;
    var POLL_MS = 8000;
    var KEY = 'datt-event';

    var state = {
        loadedEvents: false, events: [], eventId: '', roll: null, event: null, perms: {},
        view: 'dept', query: '', open: {}, timer: null, inflight: false, busy: {}
    };

    var $select = document.getElementById('datt-event');
    var $none = document.getElementById('datt-none');
    var $body = document.getElementById('datt-body');
    var $view = document.getElementById('datt-view');
    var $search = document.getElementById('datt-search');
    var $live = document.getElementById('datt-live');
    var $export = document.getElementById('datt-export');
    var $progress = document.getElementById('datt-progress');
    var $updated = document.getElementById('datt-updated');

    /* ------------------------------------------------------------- helpers */

    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text !== undefined && text !== null) n.textContent = String(text);
        return n;
    }

    function icon(name) {
        var w = document.createElement('span');
        w.innerHTML = HOD.icon(name);   // our own sprite name
        return w.firstChild;
    }

    function btn(label, opts) {
        var b = el('button', 'ui-btn ui-btn--' + (opts.variant || 'secondary') + ' ui-btn--sm' + (opts.iconOnly ? ' ui-btn--icon' : ''));
        b.type = 'button';
        if (opts.icon) b.appendChild(icon(opts.icon));
        if (opts.iconOnly) { b.setAttribute('aria-label', label); b.setAttribute('data-tooltip', label); }
        else b.appendChild(el('span', '', label));
        Object.keys(opts.data || {}).forEach(function (k) { b.setAttribute('data-' + k, opts.data[k]); });
        return b;
    }

    function call(action, data, opts) {
        return HOD.api(API, Object.assign({ action: action, data: data || {} }, opts || {}));
    }

    function plural(n, one, many) { return n + ' ' + (n === 1 ? one : many); }
    function digits(v) { return String(v || '').replace(/\D/g, ''); }
    function seatLabel(v) { return (boot.seatLabels && boot.seatLabels[v]) || String(v || '').replace(/_/g, ' '); }
    function visible() { return !panel.hidden; }

    function eventLabel(e) {
        var d = e.event_date ? HOD.format.date(e.event_date) : '';
        return e.title + (d ? ' · ' + d : '');
    }

    function today() {
        var d = new Date();
        return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2);
    }

    function remember(id) {
        try { if (id) sessionStorage.setItem(KEY, String(id)); else sessionStorage.removeItem(KEY); } catch (e) { /* private mode */ }
    }

    /* ----------------------------------------------------------- the event */

    function pickDefault() {
        var wanted = '';
        try { wanted = sessionStorage.getItem(KEY) || ''; } catch (e) { /* private mode */ }
        if (wanted && state.events.some(function (e) { return String(e.id) === wanted; })) return wanted;
        var t = today();
        var todays = state.events.filter(function (e) { return String(e.event_date || '').slice(0, 10) === t; });
        var workers = todays.filter(function (e) { return /worker/i.test(e.title + ' ' + e.event_category); });
        var hit = workers[0] || todays[0];
        return hit ? String(hit.id) : '';
    }

    function loadEvents() {
        return call('attendance_events', {}, { quiet: true }).then(function (res) {
            state.loadedEvents = true;
            state.events = res.data || [];
            $select.textContent = '';
            var first = el('option', '', 'Choose an event');
            first.value = '';
            $select.appendChild(first);
            state.events.forEach(function (e) {
                var o = el('option', '', eventLabel(e));
                o.value = String(e.id);
                $select.appendChild(o);
            });
            var id = pickDefault();
            $select.value = id;
            chooseEvent(id);
        }).catch(function (err) {
            HOD.toast(err.message || 'Events did not load.', 'danger');
        });
    }

    function chooseEvent(id) {
        state.eventId = id || '';
        state.roll = null;
        state.event = null;
        state.open = {};
        state.query = '';
        $search.value = '';
        remember(state.eventId);
        $none.hidden = !!state.eventId;
        $body.hidden = !state.eventId;
        $export.hidden = true;
        if (!state.eventId) { stopPolling(); return; }
        $view.textContent = '';
        $view.appendChild(el('div', 'ui-loading')).appendChild(el('span', 'ui-spinner'));
        loadRoll().then(startPolling);
    }

    /* ----------------------------------------------------------- the roll */

    function loadRoll() {
        var id = state.eventId;
        if (!id || state.inflight) return Promise.resolve();
        state.inflight = true;
        return call('attendance_roll', { event_id: id }, { quiet: true }).then(function (res) {
            if (id !== state.eventId) return;
            state.roll = res.roll;
            state.event = res.event;
            state.perms = res.permissions || {};
            $updated.textContent = 'Updated ' + (res.at || '');
            render();
        }).catch(function (err) {
            if (id !== state.eventId) return;
            if (!state.roll) {
                $view.textContent = '';
                $view.appendChild(el('p', 'text-sm text-danger', err.message || 'The roll did not load. Try Refresh.'));
            } else {
                $updated.textContent = 'Offline. Last update kept.';
            }
        }).then(function () { state.inflight = false; });
    }

    function startPolling() {
        stopPolling();
        if (!$live.checked || !state.eventId || !visible()) return;
        state.timer = setInterval(function () {
            if (document.hidden || !visible() || !$live.checked || !state.eventId) return;
            if (Object.keys(state.busy).length) return;   // never redraw under a tap in flight
            loadRoll();
        }, POLL_MS);
    }

    function stopPolling() {
        if (state.timer) { clearInterval(state.timer); state.timer = null; }
    }

    /* -------------------------------------------------------------- render */

    function setStat(key, value, meta) {
        var box = panel.querySelector('[data-datt-stat="' + key + '"]');
        if (!box) return;
        var v = box.querySelector('.ui-stat__value');
        if (v) v.textContent = String(value);
        var m = box.querySelector('.ui-stat__meta');
        if (meta) {
            if (!m) { m = el('span', 'ui-stat__meta'); box.querySelector('.ui-stat').appendChild(m); }
            m.textContent = meta;
        } else if (m) {
            m.remove();
        }
    }

    function matches(text, phone) {
        var q = state.query.trim().toLowerCase();
        if (!q) return true;
        if (String(text).toLowerCase().indexOf(q) > -1) return true;
        var d = digits(q);
        return d.length > 2 && digits(phone).indexOf(d) > -1;
    }

    function canMarkIn(deptIds) {
        if (!state.perms.can_mark) return false;
        if (state.perms.all_people) return true;
        var scope = state.perms.scope || [];
        return (deptIds || []).some(function (id) { return scope.indexOf(id) > -1; });
    }

    function render() {
        var r = state.roll;
        if (!r) return;
        var t = r.totals;
        setStat('workers', t.workers, t.outside > 0 ? '+' + t.outside + ' not on a roster' : '');
        setStat('present', t.present);
        setStat('absent', t.absent);
        setStat('percent', t.percent + '%');
        $progress.value = t.percent;
        panel.querySelector('[data-datt-count="absent"]').textContent = t.absent;
        panel.querySelector('[data-datt-count="present"]').textContent = t.present;
        panel.querySelectorAll('[data-action="datt.view"]').forEach(function (b) {
            b.setAttribute('aria-pressed', b.getAttribute('data-view') === state.view ? 'true' : 'false');
        });

        if (state.perms.can_export && state.eventId) {
            $export.href = '/api/department_attendance_export.php?scope=absent&event_id=' + encodeURIComponent(state.eventId);
            $export.hidden = false;
        }

        $view.textContent = '';
        if (!t.workers) {
            var none = el('div', 'ui-empty');
            none.appendChild(el('p', 'ui-empty__title', 'No workers yet'));
            none.appendChild(el('p', 'ui-empty__text', 'Add people to a department first.'));
            $view.appendChild(none);
            return;
        }
        if (state.view === 'dept') drawDepartments();
        else drawFlat(state.view === 'present');
    }

    function personLine(person, opts) {
        var li = el('li', 'ui-list__item');
        var main = el('div', 'ui-list__main');
        main.appendChild(el('span', 'ui-list__title', person.name));
        main.appendChild(el('span', 'ui-list__meta', opts.meta));
        li.appendChild(main);
        var end = el('div', 'flex items-center gap-2');
        if (opts.time) end.appendChild(el('span', 'ui-meta ui-num', opts.time));
        var tel = digits(person.phone);
        if (!person.present && tel) {
            var a = el('a', 'ui-btn ui-btn--ghost ui-btn--sm ui-btn--icon');
            a.href = 'tel:' + (String(person.phone).trim().charAt(0) === '+' ? '+' : '') + tel;
            a.setAttribute('aria-label', 'Call ' + person.name);
            a.setAttribute('data-tooltip', 'Call');
            a.appendChild(icon('phone'));
            end.appendChild(a);
        }
        if (opts.canMark) {
            if (person.present) {
                if (person.door) end.appendChild(el('span', 'ui-badge', 'Door'));
                else end.appendChild(btn('Move back', { variant: 'danger-ghost', icon: 'rotate-ccw', data: { action: 'datt.out', uid: person.user_id } }));
            } else {
                end.appendChild(btn('Clock in', { variant: 'brand', icon: 'clock', data: { action: 'datt.in', uid: person.user_id } }));
            }
        }
        li.appendChild(end);
        return li;
    }

    function memberMeta(m) {
        var parts = [];
        parts.push(m.leader ? seatLabel(m.seat) : m.membership);
        if (m.phone) parts.push(m.phone);
        return parts.join(' · ');
    }

    function drawFlat(present) {
        var people = state.roll.people.filter(function (p) {
            return p.present === present && matches(p.name, p.phone);
        });
        var card = el('section', 'ui-card');
        var head = el('div', 'ui-card__header ui-card__header--divided');
        head.appendChild(el('h2', 'ui-card__title', present ? 'Present' : 'Absent'));
        head.appendChild(el('span', 'ui-badge ui-num' + (present ? ' ui-badge--success' : ''), people.length));
        card.appendChild(head);
        if (!people.length) {
            card.appendChild(el('p', 'ui-meta p-4', state.query ? 'No match.' : (present ? 'Nobody yet.' : 'Everyone is here.')));
        } else {
            var ul = el('ul', 'ui-list');
            people.forEach(function (p) {
                ul.appendChild(personLine(p, {
                    meta: p.departments.join(', ') + (p.phone ? ' · ' + p.phone : ''),
                    time: p.time, canMark: canMarkIn(p.dept_ids)
                }));
            });
            card.appendChild(ul);
        }
        $view.appendChild(card);
    }

    function drawDepartments() {
        var grid = el('div', 'ui-grid ui-grid--3');
        var any = false;
        state.roll.departments.forEach(function (d) {
            var members = d.members.filter(function (m) { return matches(m.name, m.phone); });
            if (!members.length) return;
            any = true;
            var absent = members.filter(function (m) { return !m.present; });
            var here = members.filter(function (m) { return m.present; });
            var open = !!state.open[d.id] || (!!state.query.trim() && here.length > 0);
            var pct = d.total ? Math.round(d.present * 100 / d.total) : 0;

            var card = el('section', 'ui-card');
            var head = el('div', 'ui-card__header ui-card__header--divided');
            var title = el('div', 'min-w-0');
            title.appendChild(el('h3', 'ui-card__title', d.name));
            if (d.parent) title.appendChild(el('p', 'ui-meta', 'Part of ' + d.parent));
            head.appendChild(title);
            head.appendChild(el('span', 'ui-badge ui-num ' + (d.absent === 0 ? 'ui-badge--success' : ''), d.present + '/' + d.total));
            card.appendChild(head);

            var bar = el('progress', 'ui-progress ui-progress--sm ' + (pct === 100 ? 'ui-progress--success' : (pct >= 50 ? 'ui-progress--warning' : 'ui-progress--danger')));
            bar.max = 100;
            bar.value = pct;
            bar.setAttribute('aria-label', d.name + ' present');
            card.appendChild(bar);

            var ul = el('ul', 'ui-list');
            if (!absent.length) {
                var done = el('li', 'ui-list__item');
                done.appendChild(icon('circle-check'));
                done.appendChild(el('span', 'ui-meta', 'Everyone is here.'));
                ul.appendChild(done);
            }
            absent.forEach(function (m) {
                ul.appendChild(personLine(m, { meta: memberMeta(m), canMark: canMarkIn([d.id]) }));
            });
            card.appendChild(ul);

            if (here.length) {
                var toggle = el('button', 'ui-btn ui-btn--ghost ui-btn--sm w-full justify-between');
                toggle.type = 'button';
                toggle.setAttribute('data-action', 'datt.open');
                toggle.setAttribute('data-dept', d.id);
                toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
                toggle.appendChild(el('span', '', 'Present (' + here.length + ')'));
                toggle.appendChild(icon(open ? 'chevron-up' : 'chevron-down'));
                card.appendChild(toggle);
                if (open) {
                    var ph = el('ul', 'ui-list');
                    here.forEach(function (m) {
                        ph.appendChild(personLine(m, { meta: memberMeta(m), time: m.time, canMark: canMarkIn([d.id]) }));
                    });
                    card.appendChild(ph);
                }
            }
            grid.appendChild(card);
        });
        if (!any) $view.appendChild(el('p', 'ui-meta', 'No match.'));
        else $view.appendChild(grid);
    }

    /* ------------------------------------------------------------- actions */

    function flag(uid, present, time) {
        var id = Number(uid);
        function set(m) { if (Number(m.user_id) === id) { m.present = present; m.time = time || ''; } }
        state.roll.people.forEach(set);
        state.roll.departments.forEach(function (d) {
            var was = d.members.filter(function (m) { return Number(m.user_id) === id && m.present !== present; }).length;
            d.members.forEach(set);
            d.present += present ? was : -was;
            d.absent = d.total - d.present;
        });
        var t = state.roll.totals;
        t.present = state.roll.people.filter(function (p) { return p.present; }).length;
        t.absent = t.workers - t.present;
        t.percent = t.workers ? Math.round(t.present * 100 / t.workers) : 0;
    }

    function mark(uid, present) {
        var id = String(uid);
        if (state.busy[id]) return;
        state.busy[id] = true;
        var person = state.roll.people.filter(function (p) { return String(p.user_id) === id; })[0];
        var nm = person ? person.name : 'Worker';
        if (present) flag(uid, true, '…');   // show it at once
        else flag(uid, false, '');
        render();
        call(present ? 'attendance_mark' : 'attendance_unmark', { event_id: state.eventId, user_id: uid }, { quiet: true }).then(function () {
            HOD.toast(present ? nm + ' is in.' : nm + ' is back to absent.', 'success');
        }, function (err) {
            var r = err.response;
            if (r && r.status === 'warning') HOD.toast(r.message, 'warning');
            else HOD.toast(err.message || 'Could not save. Try again.', 'danger');
        }).then(function () {
            delete state.busy[id];
            state.inflight = false;
            return loadRoll();
        });
    }

    HOD.actions.on('datt.in', function (b) { mark(b.getAttribute('data-uid'), true); });

    HOD.actions.on('datt.out', function (b) {
        var uid = b.getAttribute('data-uid');
        var person = state.roll.people.filter(function (p) { return String(p.user_id) === String(uid); })[0];
        var nm = person ? person.name : 'this person';
        HOD.confirm({
            title: 'Move ' + nm + ' back?',
            message: 'Their clock-in for this event is removed and they show as absent.',
            confirmLabel: 'Move back',
            tone: 'danger'
        }).then(function (ok) { if (ok) mark(uid, false); });
    });

    HOD.actions.on('datt.event', function (sel) { chooseEvent(sel.value); });
    HOD.actions.on('datt.refresh', function () { state.inflight = false; loadRoll(); });
    HOD.actions.on('datt.live', function () { if ($live.checked) { loadRoll(); startPolling(); } else stopPolling(); });
    HOD.actions.on('datt.view', function (b) { state.view = b.getAttribute('data-view') || 'dept'; render(); });
    HOD.actions.on('datt.open', function (b) {
        var id = b.getAttribute('data-dept');
        state.open[id] = !state.open[id];
        render();
    });
    HOD.actions.on('datt.search', function () { state.query = $search.value; render(); });

    // Enter clocks in the one worker left on the list.
    $search.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter' || !state.roll) return;
        e.preventDefault();
        state.query = $search.value;   // the input action is debounced; Enter must not use a stale query
        var hits = state.roll.people.filter(function (p) { return !p.present && matches(p.name, p.phone) && canMarkIn(p.dept_ids); });
        if (hits.length === 1) {
            mark(hits[0].user_id, true);
            $search.value = '';
            state.query = '';
        } else if (hits.length > 1) {
            HOD.toast('More than one match. Tap Clock in.', 'warning');
        } else {
            HOD.toast('No absent match.', 'warning');
        }
    });

    /* ----------------------------------------------------------- the tab */

    function onTab(tab) {
        var actions = document.querySelector('.ui-page-header__actions');
        if (actions) actions.hidden = tab === 'attendance';
        if (tab === 'attendance') {
            if (!state.loadedEvents) loadEvents();
            else if (state.eventId) { loadRoll(); startPolling(); }
        } else {
            stopPolling();
        }
    }

    document.addEventListener('ui:tab', function (e) { if (e.detail && e.detail.tab) onTab(e.detail.tab); });
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden && visible() && state.eventId) { loadRoll(); startPolling(); }
    });
    if (visible()) onTab('attendance');
})();
