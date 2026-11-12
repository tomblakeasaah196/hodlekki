// /assets/ui/js/pages/checkin-monitor.js
// The Check-in Monitor (modules/checkin_monitor): counts and the latest
// check-ins for one event, refreshed every 30 seconds while Live is on and the
// tab is visible. Starts on today's service when there is one.
// Data: /api/checkin_qr_api.php (list_events, checkin_stats).
(function () {
    'use strict';

    var API = '/api/checkin_qr_api.php';
    var EXPORT = '/api/export_checkin_excel.php';
    var SERVICES = ['Sunday_Service', 'Midweek_Service'];
    var cfg = HOD.data('checkin-monitor-config') || {};
    var esc = HOD.esc;
    var eventId = '';
    var timer = null;
    var seq = 0;

    function $(s) { return document.querySelector(s); }
    function call(action, data, opts) {
        return HOD.api(API, Object.assign({ action: action, data: Object.assign({ _csrf: cfg.csrf }, data || {}) }, opts || {}));
    }
    function num(n) { return HOD.format.number(n || 0); }
    function ymd(d) { return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); }

    function set(key, value, meta) {
        $('[data-cm="' + key + '"]').textContent = value;
        if (meta !== undefined) $('[data-cm-meta="' + key + '"]').textContent = meta;
    }

    function load(quiet) {
        if (!eventId) return;
        var mine = ++seq;
        call('checkin_stats', { event_id: eventId }, { quiet: !!quiet }).then(function (res) {
            if (mine !== seq) return;
            var k = res.kpis || {};
            var only = res.event && res.event.checkin_only;
            set('total', num(k.total));
            set('today', num(k.today));
            set('members', num(k.members));
            // At a service a newcomer is a guest (never registered); elsewhere they register at the door.
            $('[data-cm-label="walkins"]').textContent = only ? 'Guests' : 'Walk-ins';
            set('walkins', num(k.walkins), only ? 'New faces, not registered' : 'Registered at the door');
            set('edits', num(k.name_edits));

            var days = k.by_day || [];
            $('#cm-days-card').hidden = days.length < 2;
            $('#cm-days').innerHTML = days.map(function (d) {
                var label = new Date(d.checkin_date + 'T00:00:00').toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short' });
                return '<span class="ui-chip">' + esc(label) + '&nbsp;<strong class="ui-num">' + num(d.n) + '</strong></span>';
            }).join('');

            var rows = res.recent || [];
            $('#cm-rows').innerHTML = rows.length ? rows.map(function (r) {
                var member = String(r.is_member) === '1';
                var walkin = String(r.is_walkin) === '1';
                var type = member ? '<span class="ui-badge ui-badge--info">Member</span>'
                    : walkin ? '<span class="ui-badge ui-badge--warning">' + (only ? 'Guest' : 'Walk-in') + '</span>'
                    : '<span class="ui-badge">Registered</span>';
                var time = r.checked_in_at ? new Date(String(r.checked_in_at).replace(' ', 'T')).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }) : '—';
                return '<tr><td data-label="Name"><span class="ui-table__primary">' + esc(r.full_name) + '</span></td>' +
                    '<td data-label="Phone" class="ui-num">' + esc(r.phone) + '</td>' +
                    '<td data-label="Type">' + type + '</td>' +
                    '<td data-label="How">' + (r.source === 'volunteer' ? 'Helped by a volunteer' : 'Self') + '</td>' +
                    '<td data-label="Time" class="ui-num">' + esc(time) + '</td></tr>';
            }).join('') : '<tr><td colspan="5">' + '<div class="ui-empty ui-empty--compact"><p class="ui-empty__title">No check-ins yet</p></div></td></tr>';
            $('#cm-updated').textContent = 'Updated ' + new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit', second: '2-digit' });
        }).catch(function () {});
    }

    function schedule() {
        clearInterval(timer);
        if (eventId && $('#cm-live').checked) timer = setInterval(function () { if (!document.hidden) load(true); }, 30000);
    }

    function choose(id) {
        eventId = id || '';
        $('#cm-none').hidden = !!eventId;
        $('#cm-body').hidden = !eventId;
        var exp = $('#cm-export');
        if (exp) { exp.hidden = !eventId; exp.href = eventId ? EXPORT + '?event_id=' + encodeURIComponent(eventId) : '#'; }
        try { if (eventId) sessionStorage.setItem('checkin.monitor.event', eventId); } catch (e) { /* private mode */ }
        load(false);
        schedule();
    }

    HOD.actions.on('cm.event', function (sel) { choose(sel.value); });
    HOD.actions.on('cm.live', schedule);
    HOD.actions.on('cm.refresh', function () { load(false); });
    document.addEventListener('visibilitychange', function () { if (!document.hidden && eventId && $('#cm-live').checked) load(true); });

    call('list_events').then(function (res) {
        var list = res.data || [];
        var sel = $('#cm-event');
        sel.innerHTML = '<option value="">Choose an event</option>' + list.map(function (e) {
            var d = e.event_date ? new Date(String(e.event_date).replace(' ', 'T')).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }) : '';
            return '<option value="' + Number(e.id) + '">' + esc(e.title + (d ? ' · ' + d : '')) + '</option>';
        }).join('');
        // Today's service first, then the last event watched.
        var today = ymd(new Date());
        var pick = list.filter(function (e) { return SERVICES.indexOf(e.event_category) !== -1 && String(e.event_date || '').slice(0, 10) === today; })[0];
        var remembered = '';
        try { remembered = sessionStorage.getItem('checkin.monitor.event') || ''; } catch (e) { /* private mode */ }
        var id = pick ? String(pick.id) : remembered;
        if (id && Array.prototype.some.call(sel.options, function (o) { return o.value === id; })) { sel.value = id; choose(id); }
    }).catch(function () {});
})();
