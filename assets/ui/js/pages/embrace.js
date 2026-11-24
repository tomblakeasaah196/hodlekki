// /assets/ui/js/pages/embrace.js
// Embrace Center (modules/embrace/index.php): the first-timers pipeline
// (all / my assignments, search), record and edit a first timer (with the
// vendored Geoapify address search), assign a worker, log a call, notes,
// push to the congregation, growth insights (HOD.chart), the archive, and
// the export (Excel from the API, or an image summary drawn on a canvas).
// Requests go to /api/embrace_api.php unchanged.
(function () {
    'use strict';

    var HOD = window.HOD;
    var API = '/api/embrace_api.php';
    var esc = HOD.esc;
    var cfg = HOD.data('emb-config') || {};
    var ME = String(cfg.me || 0);

    var visitors = [];
    var view = 'all';
    var geo = null;
    var charts = {};
    var notesFor = null;

    var SOURCES = [['Church_Member', 'Members'], ['Social_Media', 'Social media'], ['Flyer_Billboard', 'Flyers'], ['Self_Discovery', 'Walk-in'], ['Other', 'Other or event']];
    var INVITED = {
        Church_Member: ['Member who invited them', 'e.g. Bro. Gospel'],
        Social_Media: ['Platform or page', 'e.g. Instagram'],
        Other: ['Event or place', 'e.g. a conference, HQ']
    };

    function $(sel, scope) { return (scope || document).querySelector(sel); }
    function label(s) { return String(s || '').replace(/_/g, ' '); }
    function person(v) { return ((v.first_name || '') + ' ' + (v.last_name || '')).trim(); }
    function safeUrl(url) {
        url = String(url || '').trim();
        return /^(https?:\/\/|\/|\.\.?\/)/i.test(url) ? url : '';
    }
    function tone(name) {
        var h = 0;
        for (var i = 0; i < name.length; i++) h = (h * 31 + name.charCodeAt(i)) >>> 0;
        return 'ui-avatar--c' + ((h % 6) + 1);
    }
    function avatar(v) {
        var url = safeUrl(v.picture_path);
        return '<span class="ui-avatar ui-avatar--sm ' + tone(person(v)) + '">' + (url ? '<img class="nav-avatar-img" src="' + esc(url) + '" alt="" loading="lazy">' : '<span aria-hidden="true">' + esc((v.first_name || '?').charAt(0)) + '</span>') + '</span>';
    }
    function waNumber(phone) { var n = String(phone || '').replace(/\D/g, ''); return n.charAt(0) === '0' ? '234' + n.slice(1) : n; }
    function when(s, withTime) {
        var d = new Date(String(s || '').replace(' ', 'T'));
        if (isNaN(d)) return '';
        return withTime ? HOD.format.date(d) + ', ' + HOD.format.time(d) : HOD.format.date(d);
    }
    function find(id) { return visitors.filter(function (v) { return String(v.id) === String(id); })[0] || null; }
    function isOpen(v) { return v.followup_status === 'Pending' || v.followup_status === 'In_Progress'; }

    /* ---------- pipeline ---------- */

    function statusBadge(s) {
        if (s === 'Completed') return '<span class="ui-badge ui-badge--success">Completed</span>';
        if (s === 'Pending' || s === 'In_Progress') return '<span class="ui-badge ui-badge--warning">' + esc(label(s)) + '</span>';
        if (s === 'Unreachable') return '<span class="ui-badge ui-badge--danger">Unreachable</span>';
        return '<span class="ui-badge">Unassigned</span>';
    }

    // When they last came to church (every service counts). Tapping it lists
    // every day, named, for context on the call. Absent when it did not load.
    function day(d, long) {
        var x = new Date(String(d || '') + 'T00:00');
        if (isNaN(x)) return '';
        return long ? x.toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' }) : HOD.format.date(x);
    }
    function visitsLine(v) {
        var vs = v.visits;
        if (!vs) return '';
        if (!vs.count) return '<span class="block text-xs text-ink-subtle">No visit recorded</span>';
        return '<button type="button" class="ui-link mt-1 block text-left text-xs" data-action="emb.visits" data-id="' + esc(v.id) + '" aria-haspopup="dialog">'
            + 'Last in church ' + esc(day(vs.last)) + (vs.since ? ' · ' + esc(vs.since) : '') + '</button>';
    }

    function row(v) {
        var markers = '';
        if (Number(v.is_born_again) === 1) markers += '<span class="ui-badge ui-badge--success">Born again</span> ';
        if (Number(v.wants_to_join) === 1) markers += '<span class="ui-badge ui-badge--primary">Wants to join</span> ';
        if (v.visitation_preference === 'In-Person' || v.visitation_preference === 'Virtual') markers += '<span class="ui-badge ui-badge--info">Visit: ' + esc(v.visitation_preference === 'Virtual' ? 'virtual' : 'in person') + '</span>';

        var worker = ((v.worker_fname || '') + ' ' + (v.worker_lname || '')).trim();
        var earlier = (v.worker_history || []).filter(function (n) { return n !== worker; });
        var followup = '<div class="text-right sm:text-left">' + statusBadge(v.followup_status)
            + (worker ? '<span class="mt-1 block text-xs text-ink-muted">' + esc(worker) + '</span>' : '')
            + (earlier.length ? '<span class="block text-xs text-ink-subtle">Earlier: ' + esc(earlier.join(', ')) + '</span>' : '') + '</div>';

        var primary;
        if (!v.followup_id || v.followup_status === 'Unassigned') {
            primary = '<button type="button" class="ui-btn ui-btn--secondary ui-btn--sm" data-action="emb.assign" data-id="' + esc(v.id) + '">Assign</button>';
        } else if (v.followup_status === 'Completed') {
            primary = '<button type="button" class="ui-btn ui-btn--soft ui-btn--sm" data-action="emb.promote" data-id="' + esc(v.id) + '">Push to congregation</button>'
                + '<button type="button" class="ui-btn ui-btn--ghost ui-btn--sm" data-action="emb.report" data-id="' + esc(v.id) + '">Report</button>';
        } else if (String(v.assigned_worker_id) === ME) {
            primary = '<button type="button" class="ui-btn ui-btn--primary ui-btn--sm" data-action="emb.log" data-id="' + esc(v.id) + '">Log call</button>';
        } else {
            primary = '<span class="ui-meta">Awaiting ' + esc(v.worker_fname || 'worker') + '</span>';
        }
        var notesCount = (v.secure_notes || []).length;
        var notes = v.followup_id
            ? '<button type="button" class="ui-btn ui-btn--ghost ui-btn--sm" data-action="emb.notes" data-id="' + esc(v.id) + '" aria-haspopup="dialog">' + HOD.icon('message-square-text') + '<span>Notes' + (notesCount ? ' (' + notesCount + ')' : '') + '</span></button>'
            : '';
        var contact = v.phone
            ? '<a class="ui-btn ui-btn--ghost ui-btn--icon ui-btn--sm" href="tel:' + esc(v.phone) + '" aria-label="Call ' + esc(person(v)) + '">' + HOD.icon('phone') + '</a>'
              + '<a class="ui-btn ui-btn--ghost ui-btn--icon ui-btn--sm" href="https://wa.me/' + esc(waNumber(v.phone)) + '" target="_blank" rel="noopener" aria-label="WhatsApp ' + esc(person(v)) + '">' + HOD.icon('message-circle') + '</a>'
            : '';

        return '<tr>'
            + '<td class="is-head"><div class="flex items-center gap-3">' + avatar(v) + '<div class="min-w-0"><span class="ui-table__primary">' + esc(person(v)) + '</span>'
            + '<span class="ui-table__secondary">' + esc(label(v.spiritual_status) || 'First timer') + '</span>' + visitsLine(v) + '</div></div></td>'
            + '<td data-label="Joined" class="whitespace-nowrap">' + esc(when(v.created_at)) + '</td>'
            + '<td data-label="Markers"><div class="flex flex-wrap justify-end gap-1 sm:justify-start">' + (markers || '<span class="ui-meta">Not yet known</span>') + '</div></td>'
            + '<td data-label="Follow-up">' + followup + '</td>'
            + '<td data-label="Contact"><div class="flex items-center justify-end gap-0.5 sm:justify-start"><span class="ui-num mr-1 text-sm">' + esc(v.phone || '—') + '</span>' + contact + '</div></td>'
            + '<td class="is-actions"><div class="flex flex-wrap items-center justify-end gap-1.5 md:flex-nowrap">' + notes
            + '<button type="button" class="ui-btn ui-btn--ghost ui-btn--icon ui-btn--sm" data-action="emb.edit" data-id="' + esc(v.id) + '" aria-label="Edit ' + esc(person(v)) + '" data-tooltip="Edit">' + HOD.icon('pencil') + '</button>'
            + primary + '</div></td>'
            + '</tr>';
    }

    function render() {
        var q = $('#emb-search').value.trim().toLowerCase();
        var mine = visitors.filter(function (v) { return String(v.assigned_worker_id) === ME && isOpen(v); }).length;
        var count = $('[data-emb-mine-count]');
        count.textContent = mine;
        count.hidden = mine === 0;
        var list = visitors.filter(function (v) {
            if (view === 'mine' && String(v.assigned_worker_id) !== ME) return false;
            if (!q) return true;
            return [person(v), v.phone, v.email, v.followup_status, v.worker_fname, v.worker_lname].join(' ').toLowerCase().indexOf(q) !== -1;
        });
        $('[data-emb-rows]').innerHTML = list.length ? list.map(row).join('')
            : '<tr><td colspan="6" class="py-12 text-center text-ink-subtle">' + (visitors.length ? 'No first timers in this view.' : 'No first timers yet. Record the first one.') + '</td></tr>';
    }

    function load() {
        return HOD.api(API, { method: 'GET', action: 'fetch_visitors', quiet: true }).then(function (res) {
            visitors = res.data || [];
            render();
            if (!$('#panel-insights').hidden) renderInsights();
            if (notesFor && $('#emb-notes').open) showNotes(notesFor, null, true);
        }).catch(function (err) {
            $('[data-emb-rows]').innerHTML = '<tr><td colspan="6" class="py-12 text-center text-danger">' + esc(err.message || 'The pipeline did not load.') + '</td></tr>';
        });
    }

    /* ---------- insights ---------- */

    function renderInsights() {
        var total = visitors.length;
        var followed = visitors.filter(function (v) { return v.followup_status === 'Completed'; }).length;
        var joining = visitors.filter(function (v) { return Number(v.wants_to_join) === 1; }).length;
        var values = document.querySelectorAll('#panel-insights .ui-stat__value');
        values[0].textContent = HOD.format.number(total);
        values[1].textContent = (total ? Math.round(followed / total * 100) : 0) + '%';
        values[2].textContent = HOD.format.number(joining);

        var byDay = {};
        var bySource = {};
        visitors.forEach(function (v) {
            if (v.created_at) { var d = String(v.created_at).slice(0, 10); byDay[d] = (byDay[d] || 0) + 1; }
            var s = v.invitation_source || 'Self_Discovery';
            bySource[s] = (bySource[s] || 0) + 1;
        });
        var days = Object.keys(byDay).sort();
        if (charts.trend) charts.trend.destroy();
        if (charts.source) charts.source.destroy();
        HOD.chart($('[data-emb-chart="trend"]'), {
            type: 'line',
            data: { labels: days.map(function (d) { return HOD.format.date(d); }), datasets: [{ label: 'First timers', data: days.map(function (d) { return byDay[d]; }), borderColor: HOD.palette[0], backgroundColor: 'rgba(29, 53, 106, .08)', fill: true, tension: 0.3, pointRadius: 2 }] },
            options: { maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } }, x: { grid: { display: false } } } }
        }).then(function (c) { charts.trend = c; }).catch(function () {});
        HOD.chart($('[data-emb-chart="source"]'), {
            type: 'doughnut',
            data: { labels: SOURCES.map(function (s) { return s[1]; }), datasets: [{ data: SOURCES.map(function (s) { return bySource[s[0]] || 0; }), backgroundColor: HOD.palette.slice(0, SOURCES.length), borderWidth: 0 }] },
            options: { maintainAspectRatio: false, cutout: '65%', plugins: { legend: { position: 'bottom' } } }
        }).then(function (c) { charts.source = c; }).catch(function () {});
    }

    /* ---------- archive ---------- */

    function loadArchive() {
        var body = $('[data-emb-archive]');
        body.innerHTML = '<tr><td colspan="4"><span class="ui-skeleton ui-skeleton--text w-1/2"></span></td></tr>';
        HOD.api(API, { method: 'GET', action: 'fetch_archive', quiet: true }).then(function (res) {
            var rows = res.data || [];
            body.innerHTML = rows.length ? rows.map(function (a) {
                var notes = (a.secure_notes || []).map(function (n) {
                    return '<li class="text-sm"><span class="font-medium text-ink">' + esc(n.first_name || '') + ':</span> <span class="whitespace-pre-line text-ink-muted">' + esc(n.note_text || '') + '</span></li>';
                }).join('');
                return '<tr><td data-label="Integrated" class="whitespace-nowrap">' + esc(when(a.archive_date) || '—') + '</td>'
                    + '<td class="is-head"><span class="ui-table__primary">' + esc(person(a)) + '</span><span class="ui-table__secondary">' + esc(a.phone || '') + '</span></td>'
                    + '<td data-label="Worker">' + esc(a.worker_fname ? a.worker_fname + ' ' + (a.worker_lname || '') : 'Unassigned') + '</td>'
                    + '<td data-label="Notes">' + (notes ? '<ul class="ui-stack ui-stack--sm text-left">' + notes + '</ul>' : '<span class="ui-meta">No notes</span>') + '</td></tr>';
            }).join('') : '<tr><td colspan="4" class="py-12 text-center text-ink-subtle">Nobody has been pushed to the congregation yet.</td></tr>';
        }).catch(function (err) {
            body.innerHTML = '<tr><td colspan="4" class="py-12 text-center text-danger">' + esc(err.message || 'The archive did not load.') + '</td></tr>';
        });
    }

    /* ---------- record form ---------- */

    function vform() { return $('#emb-visitor-form'); }

    function conditional() {
        var f = vform();
        $('[data-emb-anniversary]').hidden = f.marital_status.value !== 'Married';
        var inv = INVITED[f.invitation_source.value];
        $('[data-emb-invited]').hidden = !inv;
        if (inv) {
            $('[data-emb-invited-label]').textContent = inv[0];
            f.invited_by.placeholder = inv[1];
        } else {
            f.invited_by.value = '';
        }
    }

    function setPhoto(url) {
        $('[data-emb-photo]').innerHTML = url ? '<img class="nav-avatar-img" src="' + esc(url) + '" alt="">' : HOD.icon('user');
    }

    function initAddress() {
        var holder = $('[data-emb-address]');
        HOD.lib('geoapify').then(function () {
            geo = new window.autocomplete.GeocoderAutocomplete(holder, cfg.geoapifyKey, { placeholder: 'Search for the address', filter: { countrycode: ['ng'] } });
            var input = holder.querySelector('input');
            if (input) { input.id = 'emb-address-search'; input.classList.add('ui-input'); input.setAttribute('autocomplete', 'off'); }
            geo.on('select', function (place) {
                var p = place && place.properties;
                var f = vform();
                f.latitude.value = p ? p.lat : '';
                f.longitude.value = p ? p.lon : '';
                f.physical_address.value = p ? p.formatted : '';
            });
            holder.addEventListener('keydown', function (e) { if (e.key === 'Enter') e.preventDefault(); });
            if (vform().physical_address.value) geo.setValue(vform().physical_address.value);
        }).catch(function () {
            holder.innerHTML = '<p class="ui-meta">The address search is not available right now. Any saved address is kept.</p>';
        });
    }

    function openVisitor(v, opener) {
        var f = vform();
        f.reset();
        HOD.form.clearErrors(f);
        f.elements.action.value = v ? 'update_visitor' : 'add_visitor';
        f.visitor_id.value = v ? v.id : '';
        f.physical_address.value = v ? (v.physical_address || '') : '';
        f.latitude.value = '';
        f.longitude.value = '';
        $('#emb-visitor-title').textContent = v ? 'Edit ' + (v.first_name || 'record') : 'New first timer';
        $('#emb-visitor-desc').textContent = v ? 'Update their record.' : 'Welcome them home with excellence.';
        $('[data-emb-visitor-save]').querySelector('span').textContent = v ? 'Update record' : 'Save record';
        if (v) {
            ['first_name', 'last_name', 'phone', 'email', 'invited_by', 'prayer_requests', 'wedding_anniversary'].forEach(function (k) { f[k].value = v[k] == null ? '' : v[k]; });
            f.gender.value = v.gender || 'Male';
            f.marital_status.value = v.marital_status || 'Single';
            f.invitation_source.value = v.invitation_source || 'Self_Discovery';
            f.visitation_preference.value = v.visitation_preference || 'None';
            f.is_born_again.checked = Number(v.is_born_again) === 1;
            f.wants_to_join.checked = Number(v.wants_to_join) === 1;
        }
        setPhoto(v ? safeUrl(v.picture_path) : null);
        conditional();
        if (geo) geo.setValue(f.physical_address.value); else initAddress();
        HOD.dialog.open('emb-visitor', opener);
    }

    /* ---------- notes ---------- */

    function showNotes(id, opener, refresh) {
        var v = find(id);
        if (!v) return;
        notesFor = id;
        var f = $('#emb-note-form');
        if (!refresh) resetNote();
        f.followup_id.value = v.followup_id || '';
        $('#emb-notes-desc').textContent = person(v);
        var notes = v.secure_notes || [];
        $('[data-emb-notes-list]').innerHTML = notes.length ? notes.map(function (n) {
            var tags = [];
            try { tags = JSON.parse(n.visible_to || '[]').filter(function (t) { return t !== 'All'; }); } catch (e) { tags = []; }
            var mine = String(n.author_id) === ME;
            return '<li class="ui-card ui-card--sunken p-3"><div class="flex items-start justify-between gap-2"><p class="text-xs text-ink-subtle"><span class="font-medium text-ink">' + esc(((n.first_name || '') + ' ' + (n.last_name || '')).trim() || 'Unknown') + '</span>' + (mine ? ' (you)' : '') + ' · ' + esc(when(n.created_at, true)) + '</p>'
                + '<span class="flex flex-wrap gap-1">' + tags.map(function (t) { return '<span class="ui-badge">' + esc(label(t)) + '</span>'; }).join('') + '</span></div>'
                + '<p class="mt-1 whitespace-pre-line text-sm text-ink">' + esc(n.note_text || '') + '</p>'
                + (mine ? '<button type="button" class="ui-btn ui-btn--ghost ui-btn--sm mt-1" data-action="emb.note-edit" data-note="' + esc(n.id) + '">Edit my note</button>' : '')
                + '</li>';
        }).join('') : '<li class="text-sm text-ink-subtle">No notes yet.</li>';
        if (opener !== null && !$('#emb-notes').open) HOD.dialog.open('emb-notes', opener);
    }

    function resetNote() {
        var f = $('#emb-note-form');
        f.note_id.value = '';
        f.note_text.value = '';
        f.vis_all.checked = true;
        f.vis_pastor.checked = false;
        HOD.form.clearErrors(f);
        $('label[for="emb-note-text"]').firstChild.textContent = 'Add a note';
        $('[data-emb-note-save]').querySelector('span').textContent = 'Save note';
        $('[data-emb-note-cancel]').hidden = true;
    }

    /* ---------- export ---------- */

    function range() {
        var start = $('#emb-export-start').value, end = $('#emb-export-end').value;
        if (!start || !end) { HOD.toast('Choose both dates.', 'warning'); return null; }
        if (start > end) { HOD.toast('The start date is after the end date.', 'warning'); return null; }
        return [start, end];
    }

    /** Draws the first-timers summary straight onto a canvas (no html2canvas:
     *  it cannot clone the page under the strict CSP). 800 px wide, 2x. */
    function drawSummary(list, span) {
        return new Promise(function (resolve) {
            var W = 800, PAD = 60, HEAD = 120, ROW = 70, FOOT = 90, S = 2;
            var H = PAD + HEAD + list.length * ROW + FOOT;
            var canvas = document.createElement('canvas');
            canvas.width = W * S;
            canvas.height = H * S;
            var x = canvas.getContext('2d');
            x.scale(S, S);
            var family = getComputedStyle(document.body).fontFamily || 'sans-serif';
            function font(weight, size, style) { x.font = (style || '') + ' ' + weight + ' ' + size + 'px ' + family; }
            function fit(text, max) {
                text = String(text);
                if (x.measureText(text).width <= max) return text;
                while (text.length > 1 && x.measureText(text + '…').width > max) text = text.slice(0, -1);
                return text + '…';
            }
            x.fillStyle = '#FFFFFF';
            x.fillRect(0, 0, W, H);

            var logo = new Image();
            logo.onload = logo.onerror = function () {
                if (logo.naturalWidth) x.drawImage(logo, PAD, PAD, logo.naturalWidth / logo.naturalHeight * 60, 60);
                x.textBaseline = 'alphabetic';
                x.textAlign = 'right';
                x.fillStyle = '#111827'; font(800, 28); x.fillText('FIRST TIMERS', W - PAD, PAD + 28);
                x.fillStyle = '#6B7280'; font(500, 16); x.fillText(span, W - PAD, PAD + 56);
                x.fillStyle = '#D11920'; x.fillRect(PAD, PAD + 84, W - PAD * 2, 2);

                var y = PAD + HEAD;
                list.forEach(function (v, i) {
                    x.textAlign = 'left';
                    x.fillStyle = '#111827'; font(700, 18);
                    var name = fit((i + 1) + '. ' + person(v), 420);
                    x.fillText(name, PAD, y + 26);
                    var nameW = x.measureText(name).width;
                    x.fillStyle = '#4B5563'; font(400, 15);
                    x.fillText(fit('(' + (v.phone || 'no phone') + ')', 520 - nameW), PAD + nameW + 8, y + 26);
                    x.textAlign = 'right';
                    x.fillStyle = '#9CA3AF'; font(600, 13);
                    x.fillText('Joined ' + HOD.format.date(String(v.created_at).replace(' ', 'T')), W - PAD, y + 26);
                    x.textAlign = 'left';
                    x.fillStyle = '#6B7280'; font(400, 14);
                    x.fillText(fit('Address: ' + (v.physical_address || 'not given'), W - PAD * 2), PAD, y + 50);
                    x.fillStyle = '#F3F4F6'; x.fillRect(PAD, y + ROW - 1, W - PAD * 2, 1);
                    y += ROW;
                });
                x.textAlign = 'center';
                x.fillStyle = '#9CA3AF'; font(400, 12, 'italic');
                x.fillText('Embrace Center · ' + new Date().toLocaleString('en-GB'), W / 2, y + 60);
                resolve(canvas);
            };
            logo.src = '/assets/images/hod_logo.svg';
        });
    }

    function jpeg(btn) {
        var r = range();
        if (!r) return;
        var list = visitors.filter(function (v) { var d = String(v.created_at || '').slice(0, 10); return d && d >= r[0] && d <= r[1]; });
        if (!list.length) { HOD.toast('No first timers in those dates.', 'warning'); return; }
        HOD.busy(btn, true);
        var span = r[0] === r[1] ? HOD.format.date(r[0]) : HOD.format.date(r[0]) + ' – ' + HOD.format.date(r[1]);
        drawSummary(list, span).then(function (canvas) {
            var a = document.createElement('a');
            a.download = 'First_Timers_Summary_' + r[0] + '_to_' + r[1] + '.jpg';
            a.href = canvas.toDataURL('image/jpeg', 0.9);
            a.click();
            HOD.toast('Image summary downloaded.', 'success');
            HOD.dialog.close('emb-export');
        }).catch(function () {
            HOD.toast('The image summary did not work. Try the Excel list instead.', 'danger');
        }).finally(function () { HOD.busy(btn, false); });
    }

    /* ---------- wire up ---------- */

    function init() {
        HOD.actions.on('emb.view', function (btn) {
            view = btn.getAttribute('data-view');
            document.querySelectorAll('[data-action="emb.view"]').forEach(function (b) { b.setAttribute('aria-pressed', b === btn ? 'true' : 'false'); });
            render();
        });
        var t = null;
        HOD.actions.on('emb.search', function () { clearTimeout(t); t = setTimeout(render, 120); });
        HOD.actions.on('emb.new', function (btn) { openVisitor(null, btn); });
        HOD.actions.on('emb.edit', function (btn) { openVisitor(find(btn.getAttribute('data-id')), btn); });
        HOD.actions.on('emb.conditional', conditional);
        HOD.actions.on('emb.photo', function (input) {
            var file = input.files[0];
            if (!file) return;
            var reader = new FileReader();
            reader.onload = function (e) { setPhoto(e.target.result); };
            reader.readAsDataURL(file);
        });
        HOD.actions.on('emb.save-visitor', function (f) {
            var email = (f.email.value || '').toLowerCase();
            // Same rule as api/embrace_api.php: never a church address.
            if (/@hodlc\.com$/.test(email) || /[@.]hodlekki\.org$/.test(email)) {
                HOD.form.setErrors(f, { email: 'Use their personal address, not a church one.' });
                return;
            }
            var button = document.querySelector('button[form="emb-visitor-form"]');
            HOD.busy(button, true);
            HOD.api(API, { data: new FormData(f) }).then(function (res) {
                HOD.toast(res.message || 'Saved.', 'success');
                HOD.dialog.close('emb-visitor');
                load();
            }).catch(function () {}).finally(function () { HOD.busy(button, false); });
        });

        // Assign, log, promote: the same simple POSTs.
        HOD.actions.on('emb.save', function (f) {
            var button = document.querySelector('button[form="' + f.id + '"]');
            HOD.busy(button, true);
            HOD.api(API, { data: new FormData(f) }).then(function (res) {
                HOD.toast(res.message || 'Saved.', 'success');
                HOD.dialog.close(f.closest('dialog').id);
                load();
            }).catch(function () {}).finally(function () { HOD.busy(button, false); });
        });
        HOD.actions.on('emb.assign', function (btn) {
            var v = find(btn.getAttribute('data-id'));
            var f = $('#emb-assign-form');
            f.reset();
            f.visitor_id.value = v.id;
            $('#emb-assign-desc').textContent = person(v);
            HOD.dialog.open('emb-assign', btn);
        });
        HOD.actions.on('emb.log', function (btn) {
            var v = find(btn.getAttribute('data-id'));
            var f = $('#emb-log-form');
            f.reset();
            HOD.form.clearErrors(f);
            f.followup_id.value = v.followup_id;
            f.visitor_id.value = v.id;
            f.is_born_again.checked = Number(v.is_born_again) === 1;
            f.wants_to_join.checked = Number(v.wants_to_join) === 1;
            f.visitation_preference.value = v.visitation_preference || 'None';
            $('#emb-log-desc').textContent = 'Speaking with ' + person(v);
            HOD.dialog.open('emb-log', btn);
        });
        HOD.actions.on('emb.promote', function (btn) {
            var v = find(btn.getAttribute('data-id'));
            var f = $('#emb-promote-form');
            f.reset();
            f.visitor_id.value = v.id;
            $('#emb-promote-desc').textContent = 'Adding ' + person(v) + ' to the church register. Choose where they start.';
            HOD.dialog.open('emb-promote', btn);
        });
        HOD.actions.on('emb.report', function (btn) {
            var v = find(btn.getAttribute('data-id'));
            $('#emb-report-desc').textContent = 'Worker: ' + (((v.worker_fname || '') + ' ' + (v.worker_lname || '')).trim() || '—');
            $('[data-emb-report-notes]').textContent = v.followup_notes || 'No notes.';
            HOD.dialog.open('emb-report', btn);
        });

        HOD.actions.on('emb.visits', function (btn) {
            var v = find(btn.getAttribute('data-id'));
            var vs = v && v.visits;
            if (!vs) return;
            $('#emb-visits-desc').textContent = person(v);
            $('[data-emb-visits-count]').textContent = (vs.count === 1 ? 'Came once' : 'Came ' + vs.count + ' times')
                + (vs.count > vs.days.length ? ' · latest ' + vs.days.length + ' shown' : '');
            $('[data-emb-visits-list]').innerHTML = vs.days.map(function (d) {
                return '<li class="ui-list__item"><span class="ui-list__main"><span class="ui-list__title">' + esc(day(d.date, true)) + '</span>'
                    + '<span class="ui-list__meta">' + esc(d.services.length ? d.services.join(' · ') : 'A service') + '</span></span></li>';
            }).join('');
            HOD.dialog.open('emb-visits', btn);
        });

        // Notes
        HOD.actions.on('emb.notes', function (btn) { showNotes(btn.getAttribute('data-id'), btn); });
        HOD.actions.on('emb.note-edit', function (btn) {
            var v = find(notesFor);
            var n = (v.secure_notes || []).filter(function (x) { return String(x.id) === btn.getAttribute('data-note'); })[0];
            if (!n) return;
            var f = $('#emb-note-form');
            var vis = [];
            try { vis = JSON.parse(n.visible_to || '[]'); } catch (e) { vis = []; }
            f.note_id.value = n.id;
            f.note_text.value = n.note_text || '';
            f.vis_all.checked = vis.indexOf('All') !== -1;
            f.vis_pastor.checked = vis.indexOf('Pastors') !== -1;
            $('label[for="emb-note-text"]').firstChild.textContent = 'Edit your note';
            $('[data-emb-note-save]').querySelector('span').textContent = 'Update note';
            $('[data-emb-note-cancel]').hidden = false;
            f.note_text.focus();
        });
        HOD.actions.on('emb.note-reset', resetNote);
        HOD.actions.on('emb.vis', function (box) {
            var f = $('#emb-note-form');
            if (!box.checked) return;
            if (box.name === 'vis_all') f.vis_pastor.checked = false; else f.vis_all.checked = false;
        });
        HOD.actions.on('emb.save-note', function (f) {
            var button = $('[data-emb-note-save]');
            HOD.busy(button, true);
            HOD.api(API, { data: new FormData(f) }).then(function (res) {
                HOD.toast(res.message || 'Note saved.', 'success');
                resetNote();
                load();
            }).catch(function () {}).finally(function () { HOD.busy(button, false); });
        });
        $('#emb-notes').addEventListener('ui:dialog-close', function () { notesFor = null; });

        // Export
        HOD.actions.on('emb.excel', function () {
            var r = range();
            if (!r) return;
            window.location.href = API + '?action=export_excel&start_date=' + encodeURIComponent(r[0]) + '&end_date=' + encodeURIComponent(r[1]);
            HOD.toast('Your Excel file is downloading.', 'success');
            HOD.dialog.close('emb-export');
        });
        HOD.actions.on('emb.jpeg', jpeg);

        document.addEventListener('ui:tab', function (e) {
            var tab = e.detail && e.detail.tab;
            if (tab === 'insights') renderInsights();
            if (tab === 'archive') loadArchive();
        });

        HOD.api(API, { method: 'GET', action: 'fetch_workers', quiet: true }).then(function (res) {
            var sel = $('#emb-worker');
            sel.innerHTML = '<option value="">Choose a team member</option>' + (res.data || []).map(function (w) {
                return '<option value="' + esc(w.id) + '">' + esc(person(w)) + '</option>';
            }).join('');
            sel.dispatchEvent(new Event('change', { bubbles: true }));
        }).catch(function () {});

        load();
        if (!$('#panel-archive').hidden) loadArchive();
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
