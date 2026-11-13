// /assets/ui/js/pages/congregation.js
// Congregation (modules/congregation/index.php): the register (filter,
// page through, export to Excel), three KPI drawers, a member's attendance
// history, and the add/edit member form with the address search.
// A row, a name in a KPI drawer, or #person=<id> (Ctrl+K) opens the person's
// details: Call (tel:), WhatsApp (wa.me with a typed message), SMS (SMS
// Studio access), Email (Mail's composer, for people with a mailbox they may
// send from), Edit, and who reached them lately. Each reach-out is logged.
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
        var who = esc(person(u));
        var actions = '<div class="flex flex-wrap items-center justify-end gap-1.5 md:flex-nowrap">'
            + (awol ? '<button type="button" class="ui-btn ui-btn--danger-ghost ui-btn--sm whitespace-nowrap" data-action="cong.charis" data-id="' + esc(u.id) + '" data-name="' + esc(person(u)) + '">Push to Charis</button>' : '')
            + '<button type="button" class="ui-btn ui-btn--ghost ui-btn--icon ui-btn--sm" data-action="cong.attendance" data-id="' + esc(u.id) + '" data-name="' + esc(person(u)) + '" aria-haspopup="dialog" aria-label="Attendance of ' + esc(person(u)) + '" data-tooltip="Attendance">' + HOD.icon('calendar-check') + '</button>'
            + '<button type="button" class="ui-btn ui-btn--secondary ui-btn--sm" data-action="cong.edit" data-id="' + esc(u.id) + '" aria-haspopup="dialog">Edit</button>'
            + '</div>';
        // The whole row opens the details; the name is its button for the keyboard.
        return '<tr class="is-clickable" data-action="cong.view" data-id="' + esc(u.id) + '" data-name="' + who + '">'
            + '<td class="is-head"><div class="flex items-center gap-3">' + avatar(u)
            + '<div class="min-w-0"><button type="button" class="cong-name ui-table__primary' + (awol ? ' text-danger' : '') + '" data-action="cong.view" data-id="' + esc(u.id) + '" data-name="' + who + '" aria-haspopup="dialog">' + who + '</button>'
            + '<span class="ui-table__secondary">' + esc([u.gender, u.marital_status].filter(Boolean).join(' · ') || '—') + '</span></div></div></td>'
            + '<td data-label="Contact"><div class="min-w-0 text-right sm:text-left"><span class="block ui-num">' + esc(u.phone || '—') + '</span>'
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
                + '<span class="ui-list__main"><button type="button" class="cong-name ui-list__title" data-action="cong.view" data-id="' + esc(p.id) + '" data-name="' + esc(person(p)) + '" aria-haspopup="dialog">' + esc(person(p)) + '</button>'
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
            refreshPerson();
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
                HOD.dialog.close('cong-person');
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
                refreshPerson();
            }).catch(function (err) {
                var warn = err && err.response && err.response.status === 'warning';
                HOD.toast((err && err.message) || 'That did not work. Please try again.', warn ? 'warning' : 'danger');
            }).finally(function () { HOD.busy(btn, false); });
        });
    }

    /* ---------- a person's details ---------- */

    var current = null;      // { id, person, rights, contacts } while the details are open
    var mailFor = {};        // outbox id -> person id, for emails written from here
    var pendingMail = null;  // the person an opening composer is for

    var CHANNEL = {
        call: { icon: 'phone', label: 'Call' },
        whatsapp: { icon: 'message-circle', label: 'WhatsApp' },
        sms: { icon: 'message-square-text', label: 'SMS' },
        email: { icon: 'mail', label: 'Email' }
    };
    var STATE = {
        delivered: ['Delivered', 'ui-badge--success'],
        sent: ['Sent', 'ui-badge--success'],
        sending: ['Sending', ''],
        failed: ['Not sent', 'ui-badge--danger']
    };

    function when(at) { return HOD.format.date(at) + ', ' + HOD.format.time(at); }

    function renderLog(contacts) {
        var list = $('[data-cong-person-log]');
        list.innerHTML = (contacts || []).length ? contacts.map(function (c) {
            var ch = CHANNEL[c.channel] || { icon: 'circle-help', label: label(c.channel) };
            var st = c.state && STATE[c.state];
            return '<li class="ui-list__item px-5"><span class="cong-log__icon" aria-hidden="true">' + HOD.icon(ch.icon) + '</span>'
                + '<span class="ui-list__main"><span class="ui-list__title">' + esc(ch.label) + ' · ' + esc(c.by) + '</span>'
                + '<span class="ui-list__meta">' + esc(when(c.at)) + '</span></span>'
                + (st ? '<span class="ui-list__end"><span class="ui-badge ' + st[1] + '">' + st[0] + '</span></span>' : '')
                + '</li>';
        }).join('') : '<li class="ui-list__item px-5 text-sm text-ink-subtle">No contact logged yet.</li>';
    }

    function reachTile(kind, enabled, why, href) {
        var ch = CHANNEL[kind];
        var inner = HOD.icon(ch.icon) + '<span>' + ch.label + '</span>';
        if (!enabled) return '<button type="button" class="cong-reach__btn" disabled>' + inner + '<span class="cong-reach__why">' + esc(why) + '</span></button>';
        if (href) return '<a class="cong-reach__btn" href="' + esc(href) + '" data-cong-reach="' + kind + '">' + inner + '</a>';
        return '<button type="button" class="cong-reach__btn" data-action="cong.' + kind + '" aria-haspopup="dialog">' + inner + '</button>';
    }

    function fact(name, value, cls) {
        return value ? '<dt>' + esc(name) + '</dt><dd' + (cls ? ' class="' + cls + '"' : '') + '>' + esc(value) + '</dd>' : '';
    }

    function renderPerson() {
        var p = current.person;
        var rights = current.rights || {};
        $('#cong-person-title').textContent = person(p) || 'Person';
        $('[data-cong-person-avatar]').innerHTML = avatar(p, 'ui-avatar--xl');
        $('[data-cong-person-tags]').innerHTML = '<span class="ui-badge">' + esc(label(p.spiritual_status) || 'No stage') + '</span>' + attendanceBadge(p.attendance_status);

        var phone = !!p.phone_intl;
        var tiles = [
            reachTile('call', phone, 'No phone', phone ? 'tel:+' + p.phone_intl : null),
            reachTile('whatsapp', phone, 'No phone')
        ];
        if (rights.sms) tiles.push(reachTile('sms', !!p.can_text, p.phone ? 'Not a mobile' : 'No phone'));
        if (rights.email) tiles.push(reachTile('email', !!p.contact_email, 'No email'));
        var reach = $('[data-cong-reach]');
        // Full class names, so the CSS build keeps them
        reach.className = 'cong-reach ' + ({ 2: 'cong-reach--2', 3: 'cong-reach--3' }[tiles.length] || '');
        reach.innerHTML = tiles.join('');

        var facts = fact('Phone', p.phone, 'ui-num')
            + fact('Email', p.contact_email)
            + fact('Address', p.physical_address)
            + fact('Gender', p.gender)
            + fact('Marital status', p.marital_status)
            + fact('Birthday', p.dob ? HOD.format.date(p.dob) : '')
            + fact('Anniversary', p.marital_status === 'Married' && p.wedding_anniversary ? HOD.format.date(p.wedding_anniversary) : '')
            + fact('Tribe', p.active_tribe)
            + fact('Departments', p.active_departments)
            + fact('Invited by', inviter(p))
            + fact('Now attends', p.attendance_status === 'Attends_Another_Church' ? p.new_church_name : '')
            + fact('Notes', p.comments, 'whitespace-pre-line');
        $('[data-cong-person-facts]').innerHTML = facts || '<dt>Details</dt><dd>Nothing recorded yet.</dd>';
        renderLog(current.contacts);

        var name = person(p);
        var edit = $('[data-cong-person-edit]');
        if (edit) edit.setAttribute('data-id', p.id);
        var att = $('[data-cong-person-att]');
        att.setAttribute('data-id', p.id);
        att.setAttribute('data-name', name);
        var charis = $('[data-cong-person-charis]');
        charis.hidden = p.attendance_status !== 'Unknown';
        charis.setAttribute('data-id', p.id);
        charis.setAttribute('data-name', name);
    }

    function fetchPerson(id) {
        return HOD.api(API, { action: 'get_person', data: { user_id: id }, quiet: true }).then(function (res) {
            if (!current || current.id !== id) return;
            current = { id: id, person: res.data || {}, rights: res.rights || {}, contacts: res.contacts || [] };
            renderPerson();
        });
    }

    function openPerson(id, opener, name) {
        id = String(id);
        current = { id: id };
        $('#cong-person-title').textContent = name || 'Loading…';
        $('[data-cong-person-avatar]').innerHTML = '<span class="ui-skeleton inline-block h-14 w-14 rounded-full"></span>';
        $('[data-cong-person-tags]').innerHTML = '';
        $('[data-cong-reach]').innerHTML = '';
        $('[data-cong-person-facts]').innerHTML = '<dt><span class="ui-skeleton ui-skeleton--text w-20"></span></dt><dd><span class="ui-skeleton ui-skeleton--text w-2/3"></span></dd>';
        $('[data-cong-person-log]').innerHTML = '';
        $('[data-cong-person-charis]').hidden = true;
        HOD.dialog.open('cong-person', opener);
        fetchPerson(id).catch(function (err) {
            if (!current || current.id !== id) return;
            HOD.dialog.close('cong-person');
            HOD.toast((err && err.message) || 'Their details did not load. Try again.', 'danger');
        });
    }

    function refreshPerson() {
        if (current && current.person) fetchPerson(current.id).catch(function () {});
    }

    function logContact(personId, channel, ref) {
        HOD.api(API, { action: 'log_contact', data: { user_id: personId, channel: channel, ref: ref || '' }, quiet: true }).then(function (res) {
            if (current && current.id === String(personId) && res.contacts) renderLog(res.contacts);
        }).catch(function () {});
    }

    // ?#person=<id> (from Ctrl+K)
    function personFromHash() {
        var m = /^#person=(\d+)$/.exec(window.location.hash || '');
        return m ? m[1] : null;
    }
    function openFromHash() {
        var id = personFromHash();
        if (id && !(current && current.id === id)) openPerson(id);
    }

    /* SMS */

    function smsCount() {
        var text = $('#cong-sms-text').value;
        var unicode = /[^\x0A\x0D\x20-\x7E£€]/.test(text);
        var n = text.length;
        var pages = n <= (unicode ? 70 : 160) ? 1 : Math.ceil(n / (unicode ? 67 : 153));
        $('[data-cong-sms-count]').textContent = HOD.format.number(n) + (n === 1 ? ' character' : ' characters') + ' · ' + pages + ' SMS';
    }

    function openSms(btn) {
        var p = current && current.person;
        if (!p) return;
        var f = $('#cong-sms-form');
        f.reset();
        HOD.form.clearErrors(f);
        $('#cong-sms-desc').textContent = 'To ' + person(p) + ' · ' + p.phone;
        smsCount();
        HOD.dialog.open('cong-sms', btn);
        $('#cong-sms-text').focus();
    }

    function sendSms(f) {
        if (!current || !current.person) return;
        var id = current.id;
        var btn = document.querySelector('button[form="cong-sms-form"]');
        HOD.busy(btn, true);
        HOD.api(API, { action: 'send_sms', data: { user_id: id, message: f.message.value }, quiet: true }).then(function (res) {
            HOD.toast(res.message || 'Text sent.', 'success');
            HOD.dialog.close('cong-sms');
            if (current && current.id === id && res.contacts) renderLog(res.contacts);
        }).catch(function (err) {
            if (err && err.field) HOD.form.setErrors(f, { message: err.message });
            else HOD.toast((err && err.message) || 'The text did not go. Try again.', 'danger');
        }).finally(function () { HOD.busy(btn, false); });
    }

    /* WhatsApp: wa.me opens the app on their chat with the message typed in */

    function waLink() {
        var p = current && current.person;
        var text = $('#cong-wa-text').value.trim();
        $('[data-cong-wa-open]').href = 'https://wa.me/' + (p ? p.phone_intl : '') + (text ? '?text=' + encodeURIComponent(text) : '');
    }

    function openWhatsApp(btn) {
        var p = current && current.person;
        if (!p) return;
        $('#cong-wa-text').value = '';
        $('#cong-wa-desc').textContent = 'To ' + person(p) + ' · ' + p.phone;
        waLink();
        HOD.dialog.open('cong-wa', btn);
        $('#cong-wa-text').focus();
    }

    /* Email: Mail's composer (includes/mail/mounts/composer.php), logged once it is on its way */

    function openEmail() {
        var p = current && current.person;
        if (!p || !p.contact_email) return;
        pendingMail = String(p.id);
        document.dispatchEvent(new CustomEvent('mail:compose', { detail: { mode: 'new', to: p.contact_email } }));
    }

    function wirePerson() {
        HOD.actions.on('cong.view', function (el) { openPerson(el.getAttribute('data-id'), el, el.getAttribute('data-name')); });
        HOD.actions.on('cong.sms', openSms);
        HOD.actions.on('cong.sms-send', sendSms);
        HOD.actions.on('cong.whatsapp', openWhatsApp);
        HOD.actions.on('cong.email', openEmail);

        $('#cong-sms-text').addEventListener('input', smsCount);
        $('#cong-wa-text').addEventListener('input', waLink);
        // Links keep their own navigation (tel:, wa.me); the log is written alongside.
        document.addEventListener('click', function (e) {
            var el = e.target instanceof Element ? e.target : null;
            if (!el || !current || !current.person) return;
            if (el.closest('[data-cong-reach="call"]')) logContact(current.id, 'call');
            if (el.closest('[data-cong-wa-open]')) {
                logContact(current.id, 'whatsapp');
                setTimeout(function () { HOD.dialog.close('cong-wa'); }, 300);
            }
        });

        document.addEventListener('mail:compose-opened', function (e) {
            if (pendingMail && e.detail && e.detail.outboxId) mailFor[e.detail.outboxId] = pendingMail;
            pendingMail = null;
        });
        document.addEventListener('mail:sent', function (e) {
            var id = e.detail && e.detail.outboxId;
            if (!id || !mailFor[id]) return;
            logContact(mailFor[id], 'email', id);
            delete mailFor[id];
        });

        $('#cong-person').addEventListener('ui:dialog-close', function () {
            current = null;
            if (personFromHash()) history.replaceState(null, '', window.location.pathname + window.location.search);
        });
        window.addEventListener('hashchange', openFromHash);
        openFromHash();
    }

    /* ---------- wire up ---------- */

    function init() {
        if (!$('[data-cong-rows]')) return;   // no access: require_can() drew its own page
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
        wirePerson();
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
