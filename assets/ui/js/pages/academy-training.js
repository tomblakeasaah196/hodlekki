// /assets/ui/js/pages/academy-training.js
// HOD Academy builder (modules/academy/training.php): cards and items, the
// item dialog (one form whose fieldsets follow the kind of item), Assign
// (groups + the people picker), visitor invites, each learner's progress
// (tick a class attended, mark work, change the due date), settings and
// publishing. Everything goes to /api/trainings_api.php. The page is rendered
// on the server, so a change reloads it with its toast carried across.
// Names, answers and comments are people's text: always put in with
// textContent, never as markup.
(function () {
    'use strict';

    var API = '/api/trainings_api.php';
    var FLASH = 'hod-trn-flash';
    var data = HOD.data('trb-data') || { sections: [], items: {} };
    var tid = data.trainingId;

    /* ------------------------------------------------------------ helpers */

    function flash(message) {
        try { window.sessionStorage.setItem(FLASH, message); } catch (e) { /* no toast after reload */ }
        window.location.reload();
    }
    try {
        var waiting = window.sessionStorage.getItem(FLASH);
        if (waiting) { window.sessionStorage.removeItem(FLASH); HOD.toast(waiting, 'success'); }
    } catch (e) { /* storage blocked */ }

    function api(action, payload, button) {
        if (button) HOD.busy(button, true);
        var body = payload instanceof FormData ? payload : Object.assign({ training_id: tid }, payload || {});
        if (body instanceof FormData && !body.has('training_id')) body.append('training_id', tid);
        return HOD.api(API, { action: action, data: body }).finally(function () { if (button) HOD.busy(button, false); });
    }

    function values(form) {
        var out = {};
        new FormData(form).forEach(function (v, k) {
            if (v instanceof File) return;
            if (/\[\]$/.test(k)) { k = k.slice(0, -2); (out[k] = out[k] || []).push(v); } else { out[k] = v; }
        });
        return out;
    }

    function fieldError(form, err) {
        if (err && err.field && form.elements[err.field]) {
            var e = {};
            e[err.field] = err.message;
            HOD.form.setErrors(form, e);
        }
    }

    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text !== undefined && text !== null) n.textContent = text;
        return n;
    }

    function button(label, variant, action, attrs) {
        var b = el('button', 'ui-btn ui-btn--sm ui-btn--' + variant, label);
        b.type = 'button';
        b.setAttribute('data-action', action);
        Object.keys(attrs || {}).forEach(function (k) { b.setAttribute(k, attrs[k]); });
        return b;
    }

    function badge(label, tone) {
        return el('span', 'ui-badge ui-badge--dot' + (tone ? ' ui-badge--' + tone : ''), label);
    }

    /** Show or hide a block; a hidden block's controls are disabled, so they are neither checked nor sent. */
    function show(node, on) {
        if (!node) return;
        node.hidden = !on;
        node.querySelectorAll('input, select, textarea').forEach(function (c) { c.disabled = !on; });
    }

    function setLabel(input, text) {
        var label = input && input.id ? document.querySelector('label[for="' + CSS.escape(input.id) + '"]') : null;
        if (label && label.firstChild) label.firstChild.nodeValue = text;
    }

    function sectionOf(id) {
        for (var i = 0; i < data.sections.length; i++) if (String(data.sections[i].id) === String(id)) return data.sections[i];
        return null;
    }

    /* ------------------------------------------------------------ status, settings */

    HOD.actions.on('trb.status', function (btn) {
        var status = btn.getAttribute('data-status');
        var ask = status === 'archive'
            ? HOD.confirm({ title: 'Archive this training?', message: 'Learners stop seeing it. Their progress is kept.', confirmLabel: 'Archive training' })
            : status === 'unpublish'
                ? HOD.confirm({ title: 'Unpublish this training?', message: 'Learners stop seeing it until you publish again.', confirmLabel: 'Unpublish' })
                : Promise.resolve(true);
        ask.then(function (yes) {
            if (!yes) return;
            api(status, {}, btn).then(function () {
                flash(status === 'publish' ? 'Published. Learners can start.' : (status === 'archive' ? 'Archived.' : 'Back to draft.'));
            }).catch(function () {});
        });
    });

    HOD.actions.on('trb.delete', function (btn) {
        var learners = Number(btn.getAttribute('data-learners')) || 0;
        HOD.confirm({
            title: 'Delete this training?',
            message: learners ? 'Its items and the progress of ' + learners + ' learners go too.' : 'Its cards and items go too.',
            confirmLabel: 'Delete training', tone: 'danger', requireText: learners ? 'DELETE' : undefined
        }).then(function (yes) {
            if (!yes) return;
            api('delete', { confirm_learners: learners ? 1 : 0 }, btn).then(function () {
                try { window.sessionStorage.setItem(FLASH, 'Training deleted.'); } catch (e) { /* ignore */ }
                window.location.href = '/modules/academy/';
            }).catch(function () {});
        });
    });

    HOD.actions.on('trb.settings', function (form) {
        var btn = form.querySelector('button[type="submit"]');
        var v = values(form);
        v.catalogue = form.elements.catalogue.checked ? 1 : 0;
        v.gives_certificate = form.elements.gives_certificate.checked ? 1 : 0;
        api('settings', v, btn).then(function () { flash('Settings saved.'); }).catch(function (err) { fieldError(form, err); });
    });

    /* ------------------------------------------------------------ cards */

    HOD.actions.on('trb.sectionAdd', function (btn) {
        api('section_add', {}, btn).then(function () { flash('Card added.'); }).catch(function () {});
    });

    HOD.actions.on('trb.section', function (btn) {
        var s = sectionOf(btn.getAttribute('data-section'));
        var form = document.getElementById('trb-section-form');
        if (!s || !form) return;
        form.reset();
        form.elements.section_id.value = s.id;
        form.elements.title.value = s.title;
        show(form.querySelector('.trb-s-dated'), s.kind !== 'module' && !!s.date_from);
        form.elements.date_from.value = s.date_from || '';
        HOD.dialog.open('trb-section');
    });

    HOD.actions.on('trb.sectionSave', function (form) {
        api('section_update', values(form), document.querySelector('button[form="trb-section-form"]'))
            .then(function () { flash('Card saved.'); }).catch(function (err) { fieldError(form, err); });
    });

    HOD.actions.on('trb.sectionMove', function (btn) {
        api('section_move', { section_id: btn.getAttribute('data-section'), dir: btn.getAttribute('data-dir') }, btn)
            .then(function () { window.location.reload(); }).catch(function () {});
    });

    HOD.actions.on('trb.sectionDelete', function (btn) {
        var n = Number(btn.getAttribute('data-count')) || 0;
        HOD.confirm({ title: 'Remove this card?', message: n ? 'Its ' + n + (n === 1 ? ' item goes' : ' items go') + ' too.' : 'It has no items.',
            confirmLabel: 'Remove card', tone: 'danger' }).then(function (yes) {
            if (!yes) return;
            api('section_delete', { section_id: btn.getAttribute('data-section') }, btn).then(function () { flash('Card removed.'); }).catch(function () {});
        });
    });

    /* ------------------------------------------------------------ items */

    var itemForm = document.getElementById('trb-item-form');

    function radio(form, name, value) {
        form.querySelectorAll('input[name="' + name + '"]').forEach(function (r) { r.checked = r.value === value; });
    }

    function syncItem() {
        var form = itemForm;
        if (!form) return;
        var type = form.elements.type.value;
        form.querySelectorAll('[data-trb-type]').forEach(function (fs) { show(fs, fs.getAttribute('data-trb-type') === type); });

        var s = sectionOf(form.elements.section_id.value);
        var dated = !!(s && s.date_from);
        show(form.querySelector('.trb-when'), dated);
        var week = dated && s.kind === 'week';
        show(form.querySelector('.trb-when-week'), week);
        if (week) {
            form.elements.date.min = s.date_from;
            form.elements.date.max = s.date_to || s.date_from;
        }

        if (type === 'live') {
            var online = (form.querySelector('input[name="live_mode"]:checked') || {}).value === 'online';
            show(form.querySelector('.trb-inperson'), !online);
            show(form.querySelector('.trb-online'), online);
        }
        if (type === 'audio') {
            var upload = form.querySelector('input[name="audio_source"]').checked;
            show(form.querySelector('.trb-audio-link'), !upload);
            show(form.querySelector('.trb-audio-file'), upload);
        }
        if (type === 'assignment') {
            var sermon = form.elements.assignment_kind.value === 'sermon_review';
            var sermonBox = form.querySelector('.trb-sermon');
            show(sermonBox, sermon);
            if (sermon) {
                var up = form.querySelector('input[name="media_source"]').checked;
                show(form.querySelector('.trb-media-link'), !up);
                show(form.querySelector('.trb-media-file'), up);
            }
        }
    }
    HOD.actions.on('trb.itemType', syncItem);

    function current(form, key, text) {
        var node = form.querySelector('[data-trb-current="' + key + '"]');
        if (!node) return;
        if (key === 'pdf') {
            node.hidden = !text;
            var name = node.querySelector('[data-trb-current-name]');
            if (name) name.textContent = text || '';
            return;
        }
        node.textContent = text ? 'Now: ' + text : '';
    }

    HOD.actions.on('trb.item', function (btn) {
        var form = itemForm;
        if (!form) return;
        var item = btn.hasAttribute('data-item') ? data.items[btn.getAttribute('data-item')] : null;
        form.reset();
        HOD.form.setErrors(form, {});
        form.elements.id.value = item ? item.id : 0;
        form.elements.type.disabled = false;
        form.elements.type.value = item ? item.type : 'live';
        form.elements.section_id.value = item ? item.section_id : btn.getAttribute('data-section');
        form.elements.title.value = item ? item.title : '';
        form.elements.minutes.value = item && item.minutes ? item.minutes : '';
        form.elements.required.checked = item ? !!item.required : true;
        form.elements.time.value = item && item.starts_at ? item.starts_at.substr(11, 5) : '';
        form.elements.date.value = item && item.starts_at ? item.starts_at.substr(0, 10) : '';
        current(form, 'audio', '');
        current(form, 'media', '');
        current(form, 'pdf', '');
        var d = item ? (item.details || {}) : {};
        if (item) {
            if (item.type === 'live') {
                radio(form, 'live_mode', d.mode || 'in_person');
                form.elements.venue.value = d.venue || '';
                form.elements.link.value = d.link || '';
                form.elements.present_minutes.value = d.present_minutes || 30;
            } else if (item.type === 'video') {
                form.querySelector('#trb-video-url').value = d.url || '';
            } else if (item.type === 'audio') {
                form.querySelector('input[name="audio_source"]').checked = d.source === 'file';
                form.querySelector('#trb-audio-url').value = d.source === 'file' ? '' : (d.url || '');
                current(form, 'audio', d.source === 'file' ? (d.name || 'Uploaded file') : '');
            } else if (item.type === 'reading') {
                form.elements.text.value = d.text || '';
                current(form, 'pdf', d.pdf ? (d.pdf_name || 'Reading.pdf') : '');
            } else if (item.type === 'assignment') {
                form.elements.assignment_kind.value = d.kind || 'written';
                form.elements.instructions.value = d.instructions || '';
                form.elements.prompts.value = (d.prompts || []).join('\n');
                form.elements.max_mark.value = d.max_mark || 10;
                form.elements.pass_mark.value = d.pass_mark !== undefined ? d.pass_mark : 5;
                var m = d.media || {};
                form.querySelector('input[name="media_source"]').checked = m.source === 'file';
                form.elements.media_url.value = m.source === 'file' ? '' : (m.url || '');
                current(form, 'media', m.source === 'file' ? (m.name || 'Uploaded file') : '');
            }
        }
        syncItem();
        // The kind of an existing item stays what it is.
        if (item) form.elements.type.disabled = true;
        var title = document.getElementById('trb-item-title');
        if (title) title.textContent = item ? 'Edit item' : 'Add item';
        HOD.dialog.open('trb-item');
    });

    HOD.actions.on('trb.itemSave', function (form) {
        var btn = document.querySelector('button[form="trb-item-form"]');
        var body = new FormData(form);
        if (!form.elements.required.checked) body.set('required', '0');
        api('item_save', body, btn).then(function () { flash('Item saved.'); }).catch(function (err) { fieldError(form, err); });
    });

    HOD.actions.on('trb.itemMove', function (btn) {
        api('item_move', { item_id: btn.getAttribute('data-item'), dir: btn.getAttribute('data-dir') }, btn)
            .then(function () { window.location.reload(); }).catch(function () {});
    });

    HOD.actions.on('trb.itemDelete', function (btn) {
        HOD.confirm({ title: 'Remove this item?', message: 'Learners’ progress on it goes too.', confirmLabel: 'Remove item', tone: 'danger' })
            .then(function (yes) {
                if (!yes) return;
                api('item_delete', { item_id: btn.getAttribute('data-item') }, btn).then(function () { flash('Item removed.'); }).catch(function () {});
            });
    });

    /* ------------------------------------------------------------ the people picker */

    HOD.actions.on('trb.deptFilter', function (input) {
        var q = input.value.trim().toLowerCase();
        document.querySelectorAll('[data-trb-depts] [data-name]').forEach(function (label) {
            label.hidden = q !== '' && label.getAttribute('data-name').indexOf(q) === -1;
        });
    });

    var searchSeq = 0;
    HOD.actions.on('trb.people', function (input) {
        var picker = input.getAttribute('data-trb-picker');
        var list = document.querySelector('[data-trb-results="' + picker + '"]');
        var q = input.value.trim();
        if (q.length < 2) { list.hidden = true; list.textContent = ''; return; }
        var mine = ++searchSeq;
        HOD.api(API, { action: 'people_search', data: { q: q }, quiet: true }).then(function (res) {
            if (mine !== searchSeq) return;
            list.textContent = '';
            if (!res.people.length) {
                list.appendChild(el('li', 'ui-list__item ui-meta', 'Nobody found. Try fewer letters.'));
            }
            res.people.forEach(function (p) {
                var li = el('li', 'ui-list__item');
                var main = el('span', 'ui-list__main');
                main.appendChild(el('span', 'ui-list__title', p.name));
                main.appendChild(el('span', 'ui-list__meta', p.status + (p.signin ? ' · Can sign in' : ' · No sign-in')));
                li.appendChild(main);
                var end = el('span', 'ui-list__end');
                var b = button(picker === 'invite' && p.signin ? 'Assign instead' : 'Add', 'soft', 'trb.pick',
                    { 'data-picker': picker, 'data-id': p.id, 'data-name': p.name, 'data-signin': p.signin ? '1' : '0' });
                if (picker === 'invite' && p.signin) b.disabled = true;
                end.appendChild(b);
                li.appendChild(end);
                list.appendChild(li);
            });
            list.hidden = false;
        }).catch(function () {});
    });

    function chip(picker, id, name) {
        var box = document.querySelector('[data-trb-chosen="' + picker + '"]');
        if (!box || box.querySelector('[data-id="' + CSS.escape(String(id)) + '"]')) return;
        var c = el('span', 'ui-chip');
        c.setAttribute('data-id', id);
        c.appendChild(el('span', '', name));
        if (picker === 'assign') {
            var hidden = el('input');
            hidden.type = 'hidden';
            hidden.name = 'users[]';
            hidden.value = id;
            c.appendChild(hidden);
        }
        var x = button('Remove', 'ghost', 'trb.unpick', { 'data-picker': picker, 'aria-label': 'Remove ' + name });
        x.textContent = '';
        x.insertAdjacentHTML('beforeend', HOD.icon('x', 'ui-icon--xs'));
        c.appendChild(x);
        box.appendChild(c);
    }

    function pickInvite(id, name) {
        var form = document.getElementById('trb-invite-form');
        form.elements.user_id.value = id;
        var box = document.querySelector('[data-trb-chosen="invite"]');
        box.textContent = '';
        if (id) chip('invite', id, name);
        form.querySelector('[data-trb-invite-pick]').hidden = !!id;
        if (!id) return;
        HOD.api(API, { action: 'invite_prefill', data: { user_id: id }, quiet: true }).then(function (res) {
            if (!form.elements.email.value) form.elements.email.value = res.email || '';
            form.elements.email.focus();
        }).catch(function () {});
    }

    HOD.actions.on('trb.pick', function (btn) {
        var picker = btn.getAttribute('data-picker');
        var list = document.querySelector('[data-trb-results="' + picker + '"]');
        if (picker === 'invite') {
            pickInvite(btn.getAttribute('data-id'), btn.getAttribute('data-name'));
        } else {
            chip('assign', btn.getAttribute('data-id'), btn.getAttribute('data-name'));
        }
        list.hidden = true;
        list.textContent = '';
        var input = document.querySelector('[data-trb-picker="' + picker + '"]');
        if (input) input.value = '';
    });

    HOD.actions.on('trb.unpick', function (btn) {
        var picker = btn.getAttribute('data-picker');
        btn.closest('.ui-chip').remove();
        if (picker === 'invite') pickInvite('', '');
    });

    /* ------------------------------------------------------------ assign, invite, remove */

    HOD.actions.on('trb.assign', function (form) {
        var v = values(form);
        if (!v.workers && !v.statuses && !v.departments && !v.users) {
            HOD.toast('Choose who takes it.', 'warning');
            return;
        }
        api('assign', v, document.querySelector('button[form="trb-assign-form"]')).then(function (res) {
            flash(res.added === 0 ? 'Everyone chosen was already on it.'
                : (res.added === 1 ? '1 person enrolled.' : res.added + ' people enrolled.'));
        }).catch(function (err) { fieldError(form, err); });
    });

    HOD.actions.on('trb.invite', function (btn) {
        var form = document.getElementById('trb-invite-form');
        if (!form) return;
        form.reset();
        HOD.form.setErrors(form, {});
        pickInvite(btn.getAttribute('data-user') || '', btn.getAttribute('data-name') || '');
        HOD.dialog.open('trb-invite');
    });

    HOD.actions.on('trb.inviteSend', function (form) {
        if (!form.elements.user_id.value) { HOD.toast('Choose the person first.', 'warning'); return; }
        api('invite', values(form), document.querySelector('button[form="trb-invite-form"]'))
            .then(function () { flash('Invite sent.'); }).catch(function (err) { fieldError(form, err); });
    });

    HOD.actions.on('trb.remove', function (btn) {
        HOD.confirm({ title: 'Take them off?', message: btn.getAttribute('data-name') + ' stops seeing this training.', confirmLabel: 'Take off', tone: 'danger' })
            .then(function (yes) {
                if (!yes) return;
                api('remove_learner', { enrolment_id: btn.getAttribute('data-enrolment') }, btn).then(function () { flash('Taken off.'); }).catch(function () {});
            });
    });

    /* ------------------------------------------------------------ a learner's progress */

    var drawer = document.querySelector('[data-trb-progress]');
    var openEnrolment = 0;
    var changed = false;

    function statusBadge(p, item) {
        if (!p) return null;
        if (p.status === 'completed' && item.type === 'exam' && p.passed === false) return badge('Not passed', 'warning');
        if (p.status === 'completed') return badge('Done', 'success');
        if (p.status === 'submitted') return badge('To mark', 'primary');
        if (p.status === 'returned') return badge('Returned', 'warning');
        return null;
    }

    function renderProgress(res) {
        drawer.textContent = '';
        var head = el('div', 'ui-stack ui-stack--sm');
        head.appendChild(el('p', 'font-semibold text-ink', res.person.name));
        var e = res.enrolment;
        head.appendChild(el('p', 'ui-meta', res.person.status + ' · ' + e.done + ' of ' + e.required + ' done'));
        drawer.appendChild(head);

        if (data.canEdit) {
            var due = el('form', 'flex items-end gap-2');
            due.setAttribute('data-action', 'trb.due');
            var field = el('div', 'ui-field flex-1');
            var lab = el('label', 'ui-label', 'Due');
            lab.setAttribute('for', 'trb-p-due');
            var input = el('input', 'ui-input');
            input.type = 'date';
            input.id = 'trb-p-due';
            input.name = 'due_on';
            input.value = e.due_on || '';
            field.appendChild(lab);
            field.appendChild(input);
            due.appendChild(field);
            var save = el('button', 'ui-btn ui-btn--secondary', 'Save due');
            save.type = 'submit';
            due.appendChild(save);
            drawer.appendChild(due);
        }

        res.sections.forEach(function (s) {
            if (!s.items.length) return;
            var card = el('section', 'ui-stack ui-stack--sm');
            card.appendChild(el('h3', 'ui-label', s.title));
            s.items.forEach(function (item) {
                var p = item.progress;
                var box = el('div', 'ui-card');
                var body = el('div', 'ui-card__body ui-stack ui-stack--sm');
                var row = el('div', 'flex items-center justify-between gap-2');
                var name = el('div');
                name.appendChild(el('p', 'font-medium text-ink', item.title));
                name.appendChild(el('p', 'ui-meta', item.type_label + (item.required ? '' : ' · Optional')));
                row.appendChild(name);
                var b = statusBadge(p, item);
                if (b) row.appendChild(b);
                body.appendChild(row);

                if (item.type === 'assignment' && p) {
                    var prompts = item.details.prompts.length ? item.details.prompts : ['Answer'];
                    prompts.forEach(function (q, i) {
                        body.appendChild(el('p', 'ui-label', q));
                        body.appendChild(el('p', 'text-ink whitespace-pre-line', p.answers[i] || ''));
                    });
                    if (p.mark !== null && p.mark !== undefined) {
                        body.appendChild(el('p', 'ui-meta', 'Mark ' + p.mark + ' / ' + item.details.max_mark + (p.comment ? ' · ' + p.comment : '')));
                    }
                    if (p.status === 'submitted' && data.canEdit) {
                        var f = el('form', 'ui-stack ui-stack--sm');
                        f.setAttribute('data-action', 'trb.markInline');
                        f.setAttribute('data-item', item.id);
                        var mark = el('input', 'ui-input');
                        mark.type = 'number';
                        mark.name = 'mark';
                        mark.min = 0;
                        mark.max = item.details.max_mark;
                        mark.required = true;
                        mark.setAttribute('aria-label', 'Mark out of ' + item.details.max_mark);
                        mark.placeholder = 'Mark out of ' + item.details.max_mark;
                        var comment = el('textarea', 'ui-textarea');
                        comment.name = 'comment';
                        comment.rows = 2;
                        comment.setAttribute('aria-label', 'Comment');
                        comment.placeholder = 'Comment (optional)';
                        var go = el('button', 'ui-btn ui-btn--primary ui-btn--sm', 'Save mark');
                        go.type = 'submit';
                        f.appendChild(mark);
                        f.appendChild(comment);
                        f.appendChild(go);
                        body.appendChild(f);
                    }
                } else if (item.type === 'exam') {
                    // The exam records itself (PR 2's exam_progress_hook()); a tutor never ticks it.
                    if (p && p.percent !== null && p.percent !== undefined) {
                        body.appendChild(el('p', 'ui-meta', 'Score ' + p.percent + '%' + (p.passed ? ' · passed' : ' · not passed')));
                    }
                } else if (item.type !== 'assignment' && data.canEdit) {
                    var done = p && p.status === 'completed';
                    var tick = button(done ? 'Clear' : (item.type === 'live' ? 'Mark attended' : 'Mark done'), done ? 'ghost' : 'soft', 'trb.tick',
                        { 'data-item': item.id, 'data-done': done ? '0' : '1' });
                    var wrap = el('div');
                    wrap.appendChild(tick);
                    body.appendChild(wrap);
                }
                box.appendChild(body);
                card.appendChild(box);
            });
            drawer.appendChild(card);
        });
    }

    function loadProgress() {
        drawer.textContent = '';
        drawer.appendChild(el('p', 'ui-meta', 'Loading…'));
        return HOD.api(API, { action: 'learner_progress', data: { enrolment_id: openEnrolment } }).then(renderProgress).catch(function () {
            drawer.textContent = '';
            drawer.appendChild(el('p', 'ui-meta', 'It did not load. Close and try again.'));
        });
    }

    HOD.actions.on('trb.progress', function (btn) {
        openEnrolment = btn.getAttribute('data-enrolment');
        changed = false;
        HOD.dialog.open('trb-progress');
        loadProgress();
    });

    var progressDialog = document.getElementById('trb-progress');
    if (progressDialog) {
        progressDialog.addEventListener('ui:dialog-close', function () { if (changed) window.location.reload(); });
    }

    HOD.actions.on('trb.tick', function (btn) {
        api('tutor_done', { enrolment_id: openEnrolment, item_id: btn.getAttribute('data-item'), done: btn.getAttribute('data-done') }, btn)
            .then(function (res) {
                changed = true;
                if (res.completed) HOD.toast('They have completed the training.', 'success');
                loadProgress();
            }).catch(function () {});
    });

    HOD.actions.on('trb.markInline', function (form) {
        var btn = form.querySelector('button[type="submit"]');
        api('mark_work', { enrolment_id: openEnrolment, item_id: form.getAttribute('data-item'), mark: form.elements.mark.value,
            comment: form.elements.comment.value }, btn).then(function (res) {
            changed = true;
            HOD.toast(res.passed ? 'Marked. It counts as done.' : 'Marked. It goes back to them.', 'success');
            loadProgress();
        }).catch(function () {});
    });

    HOD.actions.on('trb.due', function (form) {
        api('set_due', { enrolment_id: openEnrolment, due_on: form.elements.due_on.value }, form.querySelector('button')).then(function () {
            changed = true;
            HOD.toast('Due date saved.', 'success');
        }).catch(function () {});
    });
})();
