// /assets/ui/js/pages/congregation.js
// Congregation (modules/congregation/index.php): the register (filter,
// page through, export to Excel), three KPI drawers, a member's attendance
// history, and the add/edit member form with the address search.
// Every request goes to /api/congregation_api.php with the same shapes.
(function () {
    'use strict';

    var HOD = window.HOD;
    var API = '/api/congregation_api.php';
    var PAGE = 50;
    var esc = HOD.esc;
    var cfg = HOD.data('cong-config') || {};

    var roster = [];
    var kpis = {};
    var filtered = [];
    var shown = PAGE;
    var geo = null;
    var attUser = null;

    function $(sel, scope) { return (scope || document).querySelector(sel); }
    function label(s) { return String(s || '').replace(/_/g, ' '); }
    function person(u) { return ((u.first_name || '') + ' ' + (u.last_name || '')).trim(); }
    function safeUrl(url) {
        url = String(url || '').trim();
        return /^(https?:\/\/|\/|\.\.?\/)/i.test(url) ? url : '';
    }
    function initials(u) { return ((u.first_name || '').charAt(0) + (u.last_name || '').charAt(0)).toUpperCase() || '?'; }
    function tone(name) {
        var h = 0;
        for (var i = 0; i < name.length; i++) h = (h * 31 + name.charCodeAt(i)) >>> 0;
        return 'ui-avatar--c' + ((h % 6) + 1);
    }
    function avatar(u, size) {
        var url = safeUrl(u.picture_path);
        return '<span class="ui-avatar ' + (size || 'ui-avatar--sm') + ' ' + tone(person(u)) + '">'
            + (url ? '<img class="nav-avatar-img" src="' + esc(url) + '" alt="" loading="lazy">' : '<span aria-hidden="true">' + esc(initials(u)) + '</span>')
            + '</span>';
    }
    function waNumber(phone) {
        var n = String(phone || '').replace(/\D/g, '');
        return n.charAt(0) === '0' ? '234' + n.slice(1) : n;
    }
    function inviter(u) { return u.invitation_source === 'Church_Member' ? (u.invited_by || '') : ''; }

    /* ---------- register ---------- */

    function attendanceBadge(status) {
        if (status === 'Unknown') return '<span class="ui-badge ui-badge--danger">Unknown (AWOL)</span>';
        if (status === 'Active') return '<span class="ui-badge ui-badge--success">Active</span>';
        if (status === 'Inconsistent') return '<span class="ui-badge ui-badge--warning">Inconsistent</span>';
        return '<span class="ui-badge">' + esc(label(status) || '—') + '</span>';
    }

    function row(u) {
        var awol = u.attendance_status === 'Unknown';
        var actions = '<div class="flex flex-wrap items-center justify-end gap-1.5 md:flex-nowrap">'
            + (awol ? '<button type="button" class="ui-btn ui-btn--danger-ghost ui-btn--sm whitespace-nowrap" data-action="cong.charis" data-id="' + esc(u.id) + '" data-name="' + esc(person(u)) + '">Push to Charis</button>' : '')
            + '<button type="button" class="ui-btn ui-btn--ghost ui-btn--icon ui-btn--sm" data-action="cong.attendance" data-id="' + esc(u.id) + '" data-name="' + esc(person(u)) + '" aria-haspopup="dialog" aria-label="Attendance of ' + esc(person(u)) + '" data-tooltip="Attendance">' + HOD.icon('calendar-check') + '</button>'
            + '<button type="button" class="ui-btn ui-btn--secondary ui-btn--sm" data-action="cong.edit" data-id="' + esc(u.id) + '" aria-haspopup="dialog">Edit</button>'
            + '</div>';
        return '<tr>'
            + '<td class="is-head"><div class="flex items-center gap-3">' + avatar(u)
            + '<div class="min-w-0"><span class="ui-table__primary' + (awol ? ' text-danger' : '') + '">' + esc(person(u)) + '</span>'
            + '<span class="ui-table__secondary">' + esc([u.gender, u.marital_status].filter(Boolean).join(' · ') || '—') + '</span></div></div></td>'
            + '<td data-label="Contact"><div class="min-w-0 text-right sm:text-left"><span class="block ui-num">' + (u.phone ? '<a class="ui-link" href="tel:' + esc(u.phone) + '">' + esc(u.phone) + '</a>' : '—') + '</span>'
            + '<span class="block max-w-[220px] truncate text-xs text-ink-subtle">' + esc(u.physical_address || 'No address') + '</span></div></td>'
            + '<td data-label="Status"><div class="text-right sm:text-left"><span class="block text-sm font-medium text-ink">' + esc(label(u.spiritual_status)) + '</span><span class="mt-1 inline-block">' + attendanceBadge(u.attendance_status) + '</span></div></td>'
            + '<td data-label="Groups"><div class="min-w-0 text-right sm:text-left"><span class="block text-sm text-ink">' + esc(u.active_tribe || 'No tribe') + '</span>'
            + '<span class="block max-w-[240px] text-xs text-ink-subtle">' + esc(u.active_departments || 'No department') + '</span></div></td>'
            + '<td class="is-actions">' + actions + '</td>'
            + '</tr>';
    }

    function applyFilters() {
        var q = $('#cong-search').value.trim().toLowerCase();
        var tribe = $('#cong-tribe').value.toLowerCase();
        var status = $('#cong-status').value.toLowerCase();
        var growth = $('#cong-growth').value.toLowerCase();
        var dept = $('#cong-dept').value.toLowerCase();
        filtered = roster.filter(function (u) {
            if (q && u._text.indexOf(q) === -1) return false;
            if (tribe && (u.active_tribe || '').toLowerCase().indexOf(tribe) === -1) return false;
            if (status && (u.attendance_status || '').toLowerCase() !== status) return false;
            if (growth && (u.spiritual_status || '').toLowerCase().indexOf(growth) === -1) return false;
            if (dept && (u.active_departments || '').toLowerCase().indexOf(dept) === -1) return false;
            return true;
        });
    }

    function render() {
        var rows = filtered.slice(0, shown);
        $('[data-cong-rows]').innerHTML = rows.length ? rows.map(row).join('')
            : '<tr><td colspan="5" class="py-10 text-center text-ink-subtle">' + (roster.length ? 'Nobody matches these filters.' : 'The register is empty. Add the first member.') + '</td></tr>';
        $('[data-cong-more]').hidden = filtered.length <= shown;
        $('[data-cong-count]').textContent = filtered.length === roster.length
            ? HOD.format.number(roster.length) + ' people'
            : HOD.format.number(filtered.length) + ' of ' + HOD.format.number(roster.length) + ' people';
    }

    function fillSelect(sel, items) {
        if (sel.options.length > 1) return;
        items.forEach(function (i) { sel.insertAdjacentHTML('beforeend', '<option value="' + esc(i.name) + '">' + esc(i.name) + '</option>'); });
    }

    function load() {
        return HOD.api(API, { action: 'fetch_dashboard', quiet: true }).then(function (res) {
            if (res.filters) {
                fillSelect($('#cong-tribe'), res.filters.tribes || []);
                fillSelect($('#cong-dept'), res.filters.departments || []);
            }
            kpis = res.kpis || {};
            document.querySelectorAll('[data-cong-kpi]').forEach(function (el) {
                el.textContent = HOD.format.number((kpis[el.getAttribute('data-cong-kpi')] || []).length);
            });
            roster = (res.roster || []).map(function (u) {
                u._text = [person(u), u.phone, u.email, u.physical_address, inviter(u), u.spiritual_status, u.attendance_status, u.active_tribe, u.active_departments]
                    .join(' ').toLowerCase();
                return u;
            });
            applyFilters();
            render();
        }).catch(function (err) {
            $('[data-cong-rows]').innerHTML = '<tr><td colspan="5" class="py-10 text-center text-danger">' + esc(err.message || 'The register did not load.') + ' Reload the page to try again.</td></tr>';
            $('[data-cong-count]').textContent = '';
        });
    }

    /* ---------- export ---------- */

    function exportExcel(btn) {
        if (!filtered.length) { HOD.toast(roster.length ? 'Nobody matches these filters, so there is nothing to export.' : 'The register is empty.', 'warning'); return; }
        HOD.busy(btn, true);
        HOD.lib('xlsx').then(function () {
            var rows = filtered.map(function (u, i) {
                return {
                    'S/N': i + 1,
                    'Full Name': person(u),
                    'Physical Address': u.physical_address || '',
                    'Phone Number': u.phone || '',
                    'Gender': u.gender || '',
                    'Spiritual Status': label(u.spiritual_status),
                    'Attendance Status': label(u.attendance_status),
                    'Updated At': u.updated_at ? HOD.format.date(u.updated_at) : '',
                    'Departments': u.active_departments || '',
                    'Tribe': u.active_tribe || ''
                };
            });
            var XLSX = window.XLSX;
            var sheet = XLSX.utils.json_to_sheet(rows);
            sheet['!cols'] = [{ wch: 5 }, { wch: 25 }, { wch: 45 }, { wch: 15 }, { wch: 10 }, { wch: 20 }, { wch: 20 }, { wch: 15 }, { wch: 35 }, { wch: 15 }];
            var book = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(book, sheet, 'Congregation Data');
            XLSX.writeFile(book, 'HOD_Congregation_Export_' + new Date().toISOString().slice(0, 10) + '.xlsx');
        }).catch(function () {
            HOD.toast('The Excel export did not load. Check your connection and try again.', 'danger');
        }).finally(function () { HOD.busy(btn, false); });
    }

    /* ---------- KPI drawer ---------- */

    function showKpi(btn) {
        var key = btn.getAttribute('data-kpi');
        var people = kpis[key] || [];
        $('#cong-kpi-title').textContent = btn.getAttribute('data-title');
        $('#cong-kpi-desc').textContent = HOD.format.number(people.length) + (people.length === 1 ? ' person' : ' people');
        $('[data-cong-kpi-list]').innerHTML = people.length ? people.map(function (p) {
            var actions = p.phone
                ? '<a class="ui-btn ui-btn--ghost ui-btn--icon ui-btn--sm" href="tel:' + esc(p.phone) + '" aria-label="Call ' + esc(person(p)) + '">' + HOD.icon('phone') + '</a>'
                  + '<a class="ui-btn ui-btn--ghost ui-btn--icon ui-btn--sm" href="https://wa.me/' + esc(waNumber(p.phone)) + '" target="_blank" rel="noopener" aria-label="WhatsApp ' + esc(person(p)) + '">' + HOD.icon('message-circle') + '</a>'
                : '<span class="ui-meta">No phone</span>';
            return '<li class="ui-list__item px-5">' + avatar(p)
                + '<span class="ui-list__main"><span class="ui-list__title">' + esc(person(p)) + '</span>'
                + '<span class="ui-list__meta">' + esc(label(p.spiritual_status) || (p.phone || '')) + '</span></span>'
                + '<span class="ui-list__end flex gap-1">' + actions + '</span></li>';
        }).join('') : '<li class="ui-list__item px-5 text-sm text-ink-subtle">Nobody in this group yet.</li>';
        HOD.dialog.open('cong-kpi', btn);
    }

    /* ---------- attendance drawer ---------- */

    function loadAttendance() {
        if (!attUser) return;
        var list = $('[data-cong-att-list]');
        list.innerHTML = '<li class="ui-list__item px-5"><span class="ui-skeleton ui-skeleton--text w-2/3"></span></li>';
        ['total', 'present', 'missed'].forEach(function (k) { $('[data-cong-att="' + k + '"]').textContent = '—'; });
        HOD.api(API, { action: 'fetch_member_attendance', data: { user_id: attUser, period: $('#cong-att-period').value }, quiet: true }).then(function (res) {
            var s = res.summary || {};
            $('[data-cong-att="total"]').textContent = HOD.format.number(s.total_events);
            $('[data-cong-att="present"]').textContent = HOD.format.number(s.present);
            $('[data-cong-att="missed"]').textContent = HOD.format.number(s.missed);
            var records = res.records || [];
            list.innerHTML = records.length ? records.map(function (r) {
                var tone = r.status === 'Present' ? 'ui-badge--success' : (r.status === 'Excused' ? 'ui-badge--warning' : 'ui-badge--danger');
                return '<li class="ui-list__item px-5"><span class="ui-list__main"><span class="ui-list__title">' + esc(r.title) + '</span>'
                    + '<span class="ui-list__meta">' + esc(label(r.event_category)) + ' · ' + esc(HOD.format.date(r.event_date)) + '</span></span>'
                    + '<span class="ui-list__end"><span class="ui-badge ' + tone + '">' + esc(r.status) + '</span></span></li>';
            }).join('') : '<li class="ui-list__item px-5 text-sm text-ink-subtle">No attendance recorded in this period.</li>';
        }).catch(function (err) {
            list.innerHTML = '<li class="ui-list__item px-5 text-sm text-danger">' + esc(err.message || 'Attendance did not load.') + '</li>';
        });
    }

    /* ---------- member form ---------- */

    function form() { return $('#cong-member-form'); }

    function setPhoto(url) {
        var holder = $('[data-cong-photo]');
        holder.innerHTML = url ? '<img class="nav-avatar-img" src="' + esc(url) + '" alt="">' : HOD.icon('user');
    }

    function conditional() {
        var f = form();
        $('[data-cong-anniversary]').hidden = f.marital_status.value !== 'Married';
        $('[data-cong-new-church]').hidden = f.attendance_status.value !== 'Attends_Another_Church';
    }

    function initAddress() {
        var holder = $('[data-cong-address]');
        HOD.lib('geoapify').then(function () {
            geo = new window.autocomplete.GeocoderAutocomplete(holder, cfg.geoapifyKey, {
                placeholder: 'Start typing a street or estate',
                filter: { countrycode: ['ng'] }
            });
            var input = holder.querySelector('input');
            if (input) {
                input.id = 'cong-address-search';
                input.classList.add('ui-input');
                input.setAttribute('autocomplete', 'off');
            }
            geo.on('select', function (place) {
                var p = place && place.properties;
                var f = form();
                f.latitude.value = p ? p.lat : '';
                f.longitude.value = p ? p.lon : '';
                f.physical_address.value = p ? p.formatted : '';
            });
            holder.addEventListener('keydown', function (e) { if (e.key === 'Enter') e.preventDefault(); });
            if (form().physical_address.value) geo.setValue(form().physical_address.value);
        }).catch(function () {
            holder.innerHTML = '<p class="ui-meta">The address search is not available right now. Any saved address is kept.</p>';
        });
    }

    function resetForm() {
        var f = form();
        f.reset();
        HOD.form.clearErrors(f);
        f.elements.action.value = 'create_member';
        f.user_id.value = '';
        f.email.value = '';
        f.physical_address.value = '';
        f.latitude.value = '';
        f.longitude.value = '';
        if (geo) geo.setValue('');
        setPhoto(null);
        $('[data-cong-church-email]').textContent = 'Created when you save';
        $('[data-cong-delete]').hidden = true;
        conditional();
    }

    function openAdd(btn) {
        resetForm();
        $('#cong-member-title').textContent = 'Add member';
        HOD.dialog.open('cong-member', btn);
        if (!geo) initAddress();
    }

    function openEdit(btn) {
        HOD.busy(btn, true);
        HOD.api(API, { action: 'get_member', data: { user_id: btn.getAttribute('data-id') } }).then(function (res) {
            var u = res.data || {};
            resetForm();
            var f = form();
            f.elements.action.value = 'update_member';
            f.user_id.value = u.id;
            ['first_name', 'last_name', 'phone', 'real_email', 'dob', 'gender', 'spiritual_status', 'attendance_status', 'new_church_name', 'marital_status', 'wedding_anniversary', 'comments']
                .forEach(function (k) { if (f[k]) f[k].value = u[k] == null ? '' : u[k]; });
            f.email.value = u.email || '';
            f.physical_address.value = u.physical_address || '';
            $('[data-cong-church-email]').textContent = u.email || 'Created when you save';
            setPhoto(safeUrl(u.picture_path));
            $('[data-cong-delete]').hidden = false;
            $('[data-cong-delete]').setAttribute('data-id', u.id);
            $('[data-cong-delete]').setAttribute('data-name', person(u));
            $('#cong-member-title').textContent = 'Edit ' + (u.first_name || 'member');
            conditional();
            if (geo) geo.setValue(u.physical_address || ''); else initAddress();
            HOD.dialog.open('cong-member', btn);
        }).catch(function () {}).finally(function () { HOD.busy(btn, false); });
    }

    function save(f) {
        var file = f.profile_pic.files[0];
        if (file && file.size > 2000000) { HOD.form.setErrors(f, { profile_pic: 'That photo is over 2 MB. Choose a smaller one.' }); return; }
        var button = document.querySelector('button[form="cong-member-form"]');
        HOD.busy(button, true);
        HOD.api(API, { data: new FormData(f) }).then(function (res) {
            HOD.toast(res.message || 'Saved.', 'success');
            HOD.dialog.close('cong-member');
            load();
        }).catch(function () {}).finally(function () { HOD.busy(button, false); });
    }

    function remove(btn) {
        var name = btn.getAttribute('data-name');
        HOD.confirm({
            title: 'Delete ' + name + '?',
            message: 'Their attendance and department records are erased too. This cannot be undone.',
            confirmLabel: 'Delete member',
            tone: 'danger',
            requireText: 'DELETE'
        }).then(function (ok) {
            if (!ok) return;
            HOD.busy(btn, true);
            HOD.api(API, { action: 'delete_member', data: { user_id: btn.getAttribute('data-id') } }).then(function (res) {
                HOD.toast(res.message || 'Deleted.', 'success');
                HOD.dialog.close('cong-member');
                load();
            }).catch(function () {}).finally(function () { HOD.busy(btn, false); });
        });
    }

    function pushToCharis(btn) {
        HOD.confirm({
            title: 'Send ' + btn.getAttribute('data-name') + ' to Charis?',
            message: 'The welfare team gets a follow-up to check on them straight away.',
            confirmLabel: 'Send to Charis'
        }).then(function (ok) {
            if (!ok) return;
            HOD.busy(btn, true);
            HOD.api(API, { action: 'push_to_charis', data: { user_id: btn.getAttribute('data-id') }, quiet: true }).then(function (res) {
                HOD.toast(res.message || 'Sent to Charis.', 'success');
                load();
            }).catch(function (err) {
                var warn = err && err.response && err.response.status === 'warning';
                HOD.toast((err && err.message) || 'That did not work. Please try again.', warn ? 'warning' : 'danger');
            }).finally(function () { HOD.busy(btn, false); });
        });
    }

    /* ---------- wire up ---------- */

    function init() {
        var timer = null;
        HOD.actions.on('cong.filter', function () {
            clearTimeout(timer);
            timer = setTimeout(function () { shown = PAGE; applyFilters(); render(); }, 120);
        });
        HOD.actions.on('cong.more', function () { shown += PAGE; render(); });
        HOD.actions.on('cong.export', exportExcel);
        HOD.actions.on('cong.kpi', showKpi);
        HOD.actions.on('cong.attendance', function (btn) {
            attUser = btn.getAttribute('data-id');
            $('#cong-attendance-title').textContent = btn.getAttribute('data-name');
            HOD.dialog.open('cong-attendance', btn);
            loadAttendance();
        });
        HOD.actions.on('cong.att-period', loadAttendance);
        HOD.actions.on('cong.add', openAdd);
        HOD.actions.on('cong.edit', openEdit);
        HOD.actions.on('cong.save', save);
        HOD.actions.on('cong.delete', remove);
        HOD.actions.on('cong.charis', pushToCharis);
        HOD.actions.on('cong.conditional', conditional);
        HOD.actions.on('cong.photo', function (input) {
            var file = input.files[0];
            if (!file) return;
            var reader = new FileReader();
            reader.onload = function (e) { setPhoto(e.target.result); };
            reader.readAsDataURL(file);
        });
        load();
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
