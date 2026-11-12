// /assets/ui/js/pages/zoe.js
// Zoe (modules/zoe/index.php): a prayer request for everyone; for Zoe members
// the sessions (with their topics), the War Room, the requests and the
// roster; for Zoe / Manage the campaigns, sessions, topics, roll-call,
// request status and volunteers. Data from /api/zoe_api.php through HOD.api.
// Everything people typed goes into the page as TEXT (DOM nodes); links are
// only the ones the API vouches for (our uploads, http(s) meeting links).
(function () {
    'use strict';

    var HOD = window.HOD;
    if (!HOD || !HOD.actions) return;
    var boot = HOD.data('zoe-boot');
    if (!boot) return;
    var API = boot.api;

    var data = { campaigns: [], sessions: [], war: [], requests: [], roster: [], candidates: [] };
    var detail = { id: null, session: null, topics: [], roll: [] };
    var POST_TYPES = { General_Note: ['Note', ''], Scripture: ['Scripture', 'info'], Vision: ['Vision', 'primary'], Word_of_Knowledge: ['Word of knowledge', 'warning'] };
    var REQUEST = { Pending: ['Pending', 'warning'], Active_Prayer: ['In prayer', 'primary'], Answered_Testimony: ['Answered', 'success'] };

    /* ------------------------------------------------------------- helpers */

    function q(sel, root) { return (root || document).querySelector(sel); }

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

    function badge(label, tone) {
        return el('span', 'ui-badge' + (tone ? ' ui-badge--' + tone : ''), label);
    }

    function button(label, opts) {
        opts = opts || {};
        var b = el('button', 'ui-btn ' + (opts.variant ? 'ui-btn--' + opts.variant : 'ui-btn--secondary') + ' ui-btn--sm');
        b.type = 'button';
        if (opts.icon) b.appendChild(icon(opts.icon));
        b.appendChild(el('span', opts.iconOnly ? 'sr-only' : '', label));
        if (opts.iconOnly) b.setAttribute('aria-label', label);
        Object.keys(opts.data || {}).forEach(function (k) { b.setAttribute('data-' + k, opts.data[k]); });
        return b;
    }

    function empty(title, text, iconName) {
        var box = el('div', 'ui-empty ui-empty--compact');
        var ic = el('div', 'ui-empty__icon');
        ic.appendChild(icon(iconName || 'inbox'));
        box.appendChild(ic);
        box.appendChild(el('p', 'ui-empty__title', title));
        if (text) box.appendChild(el('p', 'ui-empty__text', text));
        return box;
    }

    function item(title, meta, trailing, extra) {
        var row = el('div', 'ui-list__item');
        var main = el('div', 'ui-list__main');
        main.appendChild(el('span', 'ui-list__title', title));
        if (meta) main.appendChild(el('span', 'ui-list__meta', meta));
        if (extra) main.appendChild(extra);
        row.appendChild(main);
        (trailing || []).forEach(function (t) { if (t) row.appendChild(t); });
        return row;
    }

    function fullName(o) { return ((o.first_name || '') + ' ' + (o.last_name || '')).trim() || 'Unnamed'; }

    function day(raw) { return raw ? HOD.format.date(String(raw).slice(0, 10) + 'T00:00', 'long') : ''; }

    function hhmm(raw) { return String(raw || '').slice(0, 5); }

    function stamp(raw) {
        var d = raw ? new Date(String(raw).replace(' ', 'T')) : null;
        return d && !isNaN(d) ? HOD.format.date(d) + ' · ' + HOD.format.time(d) : '';
    }

    function fieldError(form) {
        return function (err) {
            if (err && err.field && form.elements[err.field]) HOD.form.setErrors(form, (function (o) { o[err.field] = err.message; return o; })({}));
        };
    }

    function fillSelect(select, rows, first) {
        select.textContent = '';
        select.appendChild(new Option(first, ''));
        rows.forEach(function (r) { select.appendChild(new Option(r.label, r.id)); });
        HOD.combobox.refresh(select);
    }

    /* --------------------------------------------- the request (everyone) */

    HOD.actions.on('zoe.request.new', function () {
        var form = q('#zoe-request-form');
        form.reset();
        HOD.form.clearErrors(form);
        HOD.dialog.open('zoe-request-dialog');
    });

    HOD.actions.on('zoe.request.save', function (form, event) {
        var text = (form.elements.request_text.value || '').trim();
        HOD.form.clearErrors(form);
        if (!text) { HOD.form.setErrors(form, { request_text: 'Write your request.' }); return; }
        var submit = (event && event.submitter) || q('button[type="submit"]', form);
        HOD.busy(submit, true);
        HOD.api(API, { action: 'submit_prayer_request', data: { request_text: text } }).then(function () {
            form.reset();
            HOD.toast('Sent to the Zoe team.', { tone: 'success' });
            if (q('#zoe-request-dialog') && form.id === 'zoe-request-form') {
                HOD.dialog.close('zoe-request-dialog');
                return load();
            }
        }).catch(fieldError(form)).then(function () { HOD.busy(submit, false); });
    });

    if (!boot.isMember) return;   // nothing else on the page for non-members

    /* ---------------------------------------------------------------- load */

    function renderCampaigns() {
        var card = q('[data-zoe-campaigns-card]');
        var box = q('[data-zoe-campaigns]');
        box.textContent = '';
        card.hidden = !data.campaigns.length;
        data.campaigns.forEach(function (c) {
            box.appendChild(item(c.title, day(c.start_date) + ' – ' + day(c.end_date), [badge(c.status || 'Upcoming', c.status === 'Active' ? 'success' : '')],
                c.theme_description ? el('span', 'ui-meta break-words', c.theme_description) : null));
        });
    }

    function renderSessions() {
        var box = q('[data-zoe-sessions]');
        box.textContent = '';
        if (!data.sessions.length) {
            box.appendChild(empty('No sessions coming up', boot.isAdmin ? 'Use Schedule session.' : '', 'calendar'));
            return;
        }
        data.sessions.forEach(function (s) {
            var tags = el('span', 'flex flex-wrap gap-1 mt-1');
            tags.appendChild(badge(s.meeting_type === 'Virtual' ? 'Online' : 'In person', s.meeting_type === 'Virtual' ? 'info' : ''));
            if (s.campaign_title) tags.appendChild(badge(s.campaign_title, 'primary'));
            tags.appendChild(badge(HOD.format.number(s.topic_count) + ' topics', ''));
            box.appendChild(item(s.title, day(s.session_date) + ' · ' + hhmm(s.start_time) + '–' + hhmm(s.end_time),
                [button('Open', { icon: 'chevron-right', data: { action: 'zoe.session.open', id: s.id } })], tags));
        });
    }

    function renderWar() {
        var box = q('[data-zoe-war]');
        box.textContent = '';
        if (!data.war.length) {
            box.appendChild(empty('The War Room is quiet', '', 'flame'));
            return;
        }
        data.war.forEach(function (w) {
            var t = POST_TYPES[w.post_type] || POST_TYPES.General_Note;
            box.appendChild(item(fullName(w), stamp(w.posted_at), [badge(t[0], t[1])], el('span', 'whitespace-pre-line break-words mt-1 block', w.message)));
        });
    }

    function renderRequests() {
        var body = q('[data-zoe-requests]');
        body.textContent = '';
        if (!data.requests.length) {
            var tr = el('tr');
            var td = el('td');
            td.colSpan = 3;
            td.appendChild(empty('No prayer requests yet', '', 'heart-handshake'));
            tr.appendChild(td);
            body.appendChild(tr);
            return;
        }
        data.requests.forEach(function (r) {
            var tr = el('tr');
            var from = el('td');
            from.setAttribute('data-label', 'From');
            var f = el('div');
            f.appendChild(el('div', '', fullName(r)));
            f.appendChild(el('div', 'ui-meta', [r.phone, stamp(r.submitted_at)].filter(Boolean).join(' · ')));
            from.appendChild(f);
            tr.appendChild(from);

            var text = el('td');
            text.setAttribute('data-label', 'Request');
            text.appendChild(el('span', 'whitespace-pre-line break-words', r.request_text));
            tr.appendChild(text);

            var st = el('td');
            st.setAttribute('data-label', 'Status');
            if (boot.isAdmin) {
                var s = el('select', 'ui-select ui-select--sm');
                s.setAttribute('aria-label', 'Status of the request from ' + fullName(r));
                Object.keys(REQUEST).forEach(function (k) { s.appendChild(new Option(REQUEST[k][0], k)); });
                s.value = r.status;
                s.setAttribute('data-prev', r.status);
                s.setAttribute('data-action-change', 'zoe.request.status');
                s.setAttribute('data-id', r.id);
                st.appendChild(s);
            } else {
                var b = REQUEST[r.status] || [r.status, ''];
                st.appendChild(badge(b[0], b[1]));
            }
            tr.appendChild(st);
            body.appendChild(tr);
        });
    }

    function renderRoster() {
        var box = q('[data-zoe-roster]');
        box.textContent = '';
        if (!data.roster.length) {
            box.appendChild(empty('Nobody on the roster yet', boot.isAdmin ? 'Use Add volunteer.' : '', 'users'));
            return;
        }
        data.roster.forEach(function (r) {
            var type = String(r.roster_type || '').replace(/_/g, ' ');
            var trailing = [badge(type || 'Member', r.roster_type === 'Volunteer' ? '' : 'primary')];
            if (boot.isAdmin && r.roster_type === 'Volunteer' && r.id) {
                trailing.push(button('Remove ' + fullName(r), { variant: 'ghost', icon: 'trash-2', iconOnly: true, data: { action: 'zoe.volunteer.remove', id: r.id, name: fullName(r) } }));
            }
            box.appendChild(item(fullName(r), '', trailing));
        });
    }

    function load() {
        return HOD.api(API, { method: 'GET', action: 'fetch_dashboard', quiet: true }).then(function (res) {
            data.campaigns = res.campaigns || [];
            data.sessions = res.sessions || [];
            data.war = res.war_room || [];
            data.requests = res.requests || [];
            data.roster = res.roster || [];
            data.candidates = res.candidates || [];
            q('[data-zoe-error]').hidden = true;
            renderCampaigns();
            renderSessions();
            renderWar();
            renderRequests();
            renderRoster();
        }).catch(function () {
            q('[data-zoe-error]').hidden = false;
        });
    }

    /* ------------------------------------------------------ session detail */

    function renderDetail() {
        var s = detail.session;
        q('[data-zoe-detail-title]').textContent = s.title;
        var dl = q('[data-zoe-detail-meta]');
        dl.textContent = '';
        function row(label, value) {
            dl.appendChild(el('dt', '', label));
            var dd = el('dd');
            if (value instanceof Node) dd.appendChild(value); else dd.textContent = value;
            dl.appendChild(dd);
        }
        row('When', day(s.session_date) + ' · ' + hhmm(s.start_time) + '–' + hhmm(s.end_time));
        if (s.link_href) {
            var a = el('a', 'ui-link break-all', s.location_or_link);
            a.href = s.link_href;
            a.target = '_blank';
            a.rel = 'noopener noreferrer';
            row(s.meeting_type === 'Virtual' ? 'Link' : 'Place', a);
        } else {
            row('Place', s.location_or_link || '—');
        }
        if (s.campaign_title) row('Campaign', s.campaign_title);
        if (s.bulletin_href) {
            var p = el('a', 'ui-link', 'Open the PDF');
            p.href = s.bulletin_href;
            p.target = '_blank';
            p.rel = 'noopener';
            row('Bulletin', p);
        }

        var box = q('[data-zoe-topics]');
        box.textContent = '';
        if (!detail.topics.length) {
            box.appendChild(empty('No topics yet', boot.isAdmin ? 'Use Add topic.' : '', 'book-open'));
        }
        detail.topics.forEach(function (t) {
            var trailing = [button('Read', { icon: 'book-open', data: { action: 'zoe.topic.read', id: t.id } })];
            if (boot.isAdmin) trailing.push(button('Delete topic', { variant: 'ghost', icon: 'trash-2', iconOnly: true, data: { action: 'zoe.topic.delete', id: t.id } }));
            var meta = [t.allocated_time, t.assigned_user_id ? 'Led by ' + fullName(t) : 'No leader yet'].filter(Boolean).join(' · ');
            box.appendChild(item(t.topic_title, meta, trailing));
        });
    }

    function openDetail(id) {
        return HOD.api(API, { method: 'GET', action: 'fetch_session_details', data: { session_id: id }, quiet: false }).then(function (res) {
            detail.id = id;
            detail.session = res.session;
            detail.topics = res.topics || [];
            detail.roll = res.attendance_list || [];
            renderDetail();
            q('[data-zoe-session-list]').hidden = true;
            q('[data-zoe-session-detail]').hidden = false;
        });
    }

    HOD.actions.on('zoe.session.open', function (btn) {
        HOD.busy(btn, true);
        openDetail(btn.getAttribute('data-id')).catch(function () {}).then(function () { HOD.busy(btn, false); });
    });

    HOD.actions.on('zoe.session.back', function () {
        detail.id = null;
        q('[data-zoe-session-detail]').hidden = true;
        q('[data-zoe-session-list]').hidden = false;
        load();
    });

    HOD.actions.on('zoe.topic.read', function (btn) {
        var t = detail.topics.filter(function (x) { return String(x.id) === btn.getAttribute('data-id'); })[0];
        if (!t) return;
        q('#zoe-reader-dialog-title').textContent = t.topic_title;
        var bible = q('[data-zoe-reader-bible]');
        bible.hidden = !t.bible_reference;
        q('span', bible).textContent = t.bible_reference || '';
        q('[data-zoe-reader-points]').textContent = t.prayer_points || '';
        HOD.dialog.open('zoe-reader-dialog');
    });

    /* ------------------------------------------------------------ war room */

    HOD.actions.on('zoe.war.save', function (form, event) {
        var v = HOD.form.values(form);
        HOD.form.clearErrors(form);
        if (!(v.message || '').trim()) { HOD.form.setErrors(form, { message: 'Write the message.' }); return; }
        var submit = (event && event.submitter) || q('button[type="submit"]', form);
        HOD.busy(submit, true);
        HOD.api(API, { action: 'post_war_room', data: { post_type: v.post_type, message: v.message.trim() } }).then(function () {
            form.elements.message.value = '';
            HOD.toast('Posted.', { tone: 'success' });
            return load();
        }).catch(fieldError(form)).then(function () { HOD.busy(submit, false); });
    });

    /* ----------------------------------------------------- runners (manage) */

    HOD.actions.on('zoe.request.status', function (select) {
        select.disabled = true;
        HOD.api(API, { action: 'update_request_status', data: { request_id: select.getAttribute('data-id'), status: select.value } }).then(function () {
            select.setAttribute('data-prev', select.value);
            HOD.toast(select.value === 'Answered_Testimony' ? 'Marked as answered.' : 'Saved.', { tone: 'success' });
        }).catch(function () {
            select.value = select.getAttribute('data-prev');
        }).then(function () { select.disabled = false; });
    });

    function reset(id) {
        var form = q('#' + id);
        form.reset();
        HOD.form.clearErrors(form);
        return form;
    }

    HOD.actions.on('zoe.campaign.new', function () {
        reset('zoe-campaign-form');
        HOD.dialog.open('zoe-campaign-dialog');
    });

    HOD.actions.on('zoe.campaign.save', function (form, event) {
        var v = HOD.form.values(form);
        var errors = {};
        if (!(v.title || '').trim()) errors.title = 'Give it a title.';
        if (!v.start_date) errors.start_date = 'Choose the start.';
        if (!v.end_date) errors.end_date = 'Choose the end.';
        else if (v.start_date && v.end_date < v.start_date) errors.end_date = 'The end is before the start.';
        HOD.form.clearErrors(form);
        if (Object.keys(errors).length) { HOD.form.setErrors(form, errors); return; }
        var submit = event && event.submitter;
        HOD.busy(submit, true);
        HOD.api(API, { action: 'save_campaign', data: {
            title: v.title.trim(), theme_description: (v.theme_description || '').trim(), start_date: v.start_date, end_date: v.end_date
        } }).then(function () {
            HOD.dialog.close('zoe-campaign-dialog');
            HOD.toast('Campaign saved.', { tone: 'success' });
            return load();
        }).catch(fieldError(form)).then(function () { HOD.busy(submit, false); });
    });

    HOD.actions.on('zoe.session.new', function () {
        var form = reset('zoe-session-form');
        fillSelect(form.elements.campaign_id, data.campaigns.map(function (c) { return { id: c.id, label: c.title }; }), 'None');
        HOD.dialog.open('zoe-session-dialog');
    });

    HOD.actions.on('zoe.session.save', function (form, event) {
        var v = HOD.form.values(form);
        var errors = {};
        if (!(v.title || '').trim()) errors.title = 'Give it a title.';
        if (!v.session_date) errors.session_date = 'Choose the date.';
        if (!v.start_time) errors.start_time = 'Choose the start.';
        if (!v.end_time) errors.end_time = 'Choose the end.';
        if (!(v.location_or_link || '').trim()) errors.location_or_link = v.meeting_type === 'Virtual' ? 'Paste the meeting link.' : 'Say where.';
        HOD.form.clearErrors(form);
        if (Object.keys(errors).length) { HOD.form.setErrors(form, errors); return; }
        var submit = event && event.submitter;
        HOD.busy(submit, true);
        HOD.api(API, { action: 'save_session', data: new FormData(form) }).then(function () {
            HOD.dialog.close('zoe-session-dialog');
            HOD.toast('Session scheduled.', { tone: 'success' });
            return load();
        }).catch(fieldError(form)).then(function () { HOD.busy(submit, false); });
    });

    HOD.actions.on('zoe.topic.new', function () {
        if (!detail.id) return;
        var form = reset('zoe-topic-form');
        fillSelect(form.elements.assigned_user_id, data.roster.map(function (r) { return { id: r.user_id, label: fullName(r) }; }), 'Nobody yet');
        HOD.dialog.open('zoe-topic-dialog');
    });

    HOD.actions.on('zoe.topic.save', function (form, event) {
        var v = HOD.form.values(form);
        var errors = {};
        if (!(v.topic_title || '').trim()) errors.topic_title = 'Name the topic.';
        if (!(v.prayer_points || '').trim()) errors.prayer_points = 'Paste the prayer points.';
        HOD.form.clearErrors(form);
        if (Object.keys(errors).length) { HOD.form.setErrors(form, errors); return; }
        var submit = event && event.submitter;
        HOD.busy(submit, true);
        HOD.api(API, { action: 'save_topic', data: {
            session_id: detail.id, topic_title: v.topic_title.trim(), prayer_points: v.prayer_points.trim(),
            bible_reference: (v.bible_reference || '').trim(), allocated_time: (v.allocated_time || '').trim(), assigned_user_id: v.assigned_user_id
        } }).then(function () {
            HOD.dialog.close('zoe-topic-dialog');
            HOD.toast('Topic added.', { tone: 'success' });
            return openDetail(detail.id);
        }).catch(fieldError(form)).then(function () { HOD.busy(submit, false); });
    });

    HOD.actions.on('zoe.topic.delete', function (btn) {
        var t = detail.topics.filter(function (x) { return String(x.id) === btn.getAttribute('data-id'); })[0];
        if (!t) return;
        HOD.confirm({ title: 'Delete this topic?', message: t.topic_title, confirmLabel: 'Delete', tone: 'danger' }).then(function (yes) {
            if (!yes) return;
            HOD.busy(btn, true);
            HOD.api(API, { action: 'delete_topic', data: { topic_id: t.id } }).then(function () {
                HOD.toast('Topic deleted.', { tone: 'success' });
                return openDetail(detail.id);
            }).catch(function () {}).then(function () { HOD.busy(btn, false); });
        });
    });

    HOD.actions.on('zoe.roll.open', function () {
        var box = q('[data-zoe-roll]');
        box.textContent = '';
        if (!detail.roll.length) box.appendChild(empty('Nobody on the Zoe roster', 'Add volunteers on the Roster tab.', 'users'));
        detail.roll.forEach(function (a) {
            var label = el('label', 'ui-list__item ui-check');
            var input = el('input');
            input.type = 'checkbox';
            input.checked = a.current_status === 'Present';
            input.setAttribute('data-uid', a.user_id);
            label.appendChild(input);
            label.appendChild(el('span', '', fullName(a)));
            box.appendChild(label);
        });
        HOD.dialog.open('zoe-roll-dialog');
    });

    HOD.actions.on('zoe.roll.save', function (btn) {
        var marks = {};
        Array.prototype.forEach.call(document.querySelectorAll('[data-zoe-roll] input[data-uid]'), function (i) {
            marks[i.getAttribute('data-uid')] = i.checked ? 'Present' : 'Absent';
        });
        if (!Object.keys(marks).length) { HOD.dialog.close('zoe-roll-dialog'); return; }
        HOD.busy(btn, true);
        HOD.api(API, { action: 'mark_attendance', data: { session_id: detail.id, attendance_data: JSON.stringify(marks) } }).then(function () {
            HOD.dialog.close('zoe-roll-dialog');
            HOD.toast('Roll-call saved.', { tone: 'success' });
            return openDetail(detail.id);
        }).catch(function () {}).then(function () { HOD.busy(btn, false); });
    });

    HOD.actions.on('zoe.volunteer.new', function () {
        var form = reset('zoe-volunteer-form');
        fillSelect(form.elements.user_id, data.candidates.map(function (u) { return { id: u.id, label: fullName(u) }; }), 'Choose…');
        HOD.dialog.open('zoe-volunteer-dialog');
    });

    HOD.actions.on('zoe.volunteer.save', function (form, event) {
        var v = HOD.form.values(form);
        HOD.form.clearErrors(form);
        if (!v.user_id) { HOD.form.setErrors(form, { user_id: 'Choose who.' }); return; }
        var submit = event && event.submitter;
        HOD.busy(submit, true);
        HOD.api(API, { action: 'add_to_roster', data: { user_id: v.user_id, roster_type: v.roster_type } }).then(function () {
            HOD.dialog.close('zoe-volunteer-dialog');
            HOD.toast('Added to the roster.', { tone: 'success' });
            return load();
        }).catch(fieldError(form)).then(function () { HOD.busy(submit, false); });
    });

    HOD.actions.on('zoe.volunteer.remove', function (btn) {
        HOD.confirm({
            title: 'Take off the Zoe roster?',
            message: btn.getAttribute('data-name') + ' stops being a Zoe volunteer.',
            confirmLabel: 'Remove',
            tone: 'danger'
        }).then(function (yes) {
            if (!yes) return;
            HOD.busy(btn, true);
            HOD.api(API, { action: 'remove_from_roster', data: { roster_id: btn.getAttribute('data-id') } }).then(function () {
                HOD.toast('Removed from the roster.', { tone: 'success' });
                return load();
            }).catch(function () {}).then(function () { HOD.busy(btn, false); });
        });
    });

    load();
})();
