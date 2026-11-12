// /assets/ui/js/pages/envision.js
// Envision (modules/envision/index.php): stats, the sermon archive (publish
// switch, crew per service, the sermon dialog with an upload progress bar),
// the media crew, comment moderation and the live broadcast settings. Data
// from /api/envision_api.php. Everything people typed (including public
// comments) goes into the page as TEXT (DOM nodes); markup strings hold only
// our own words.
(function () {
    'use strict';

    var HOD = window.HOD;
    if (!HOD || !HOD.actions) return;
    var boot = HOD.data('envision-boot');
    if (!boot) return;
    var API = boot.api;

    var data = { sermons: [], roster: [], available: [] };
    var comments = { page: 1, pages: 1 };
    var duty = { id: null };
    var radioLoaded = false;
    var commentsLoaded = false;

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

    function cell(label, cls) {
        var td = el('td', cls || '');
        if (label) td.setAttribute('data-label', label);
        return td;
    }

    function emptyRow(body, cols, title, text, iconName) {
        var tr = el('tr');
        var td = el('td');
        td.colSpan = cols;
        var box = el('div', 'ui-empty ui-empty--compact');
        var ic = el('div', 'ui-empty__icon');
        ic.appendChild(icon(iconName || 'inbox'));
        box.appendChild(ic);
        box.appendChild(el('p', 'ui-empty__title', title));
        if (text) box.appendChild(el('p', 'ui-empty__text', text));
        td.appendChild(box);
        tr.appendChild(td);
        body.appendChild(tr);
    }

    function fullName(o) { return ((o.first_name || '') + ' ' + (o.last_name || '')).trim() || 'Unnamed'; }

    function role(r) { return boot.crewRoles[r] || String(r || '').replace(/_/g, ' '); }

    function safeUrl(url) {
        url = String(url || '').trim();
        return /^(\/(?!\/)|\.\.?\/)/.test(url) ? url : '';
    }

    function fieldError(form) {
        return function (err) {
            if (err && err.field && form.elements[err.field]) HOD.form.setErrors(form, (function (o) { o[err.field] = err.message; return o; })({}));
        };
    }

    function find(id) {
        return data.sermons.filter(function (s) { return String(s.id) === String(id); })[0] || null;
    }

    /* ------------------------------------------------------------- sermons */

    function renderSermons() {
        var body = q('[data-env-sermons]');
        body.textContent = '';
        if (!data.sermons.length) {
            emptyRow(body, 5, 'No sermons archived yet', boot.canCreate ? 'Use Archive sermon to add the first.' : '', 'video');
            return;
        }
        data.sermons.forEach(function (s) {
            var tr = el('tr');
            var t = cell('Sermon');
            var tw = el('div');
            tw.appendChild(el('div', 'font-semibold', s.title));
            tw.appendChild(el('div', 'ui-meta', s.preacher + ' · ' + HOD.format.date(String(s.date_preached) + 'T00:00')));
            t.appendChild(tw);
            tr.appendChild(t);

            var m = cell('Media');
            var tags = el('div', 'flex flex-wrap gap-1');
            if (s.audio_file_path) tags.appendChild(badge('Audio', 'primary'));
            if (s.youtube_link) tags.appendChild(badge('Video', 'danger'));
            if (s.cover_image_path) tags.appendChild(badge('Cover', ''));
            if (!tags.childNodes.length) tags.appendChild(el('span', 'ui-meta', 'None yet'));
            m.appendChild(tags);
            tr.appendChild(m);

            var c = cell('Crew');
            var n = Number(s.media_crew_count) || 0;
            c.appendChild(button(n ? n + ' logged' : (boot.canEdit ? 'Log crew' : 'None'), { icon: 'users', data: { action: 'env.duty.open', id: s.id } }));
            tr.appendChild(c);

            var p = cell('Public');
            var live = Number(s.is_published) === 1;
            if (boot.canApprove) {
                var label = el('label', 'ui-check items-center');
                var sw = el('input', 'ui-switch');
                sw.type = 'checkbox';
                sw.checked = live;
                sw.setAttribute('data-action-change', 'env.sermon.publish');
                sw.setAttribute('data-id', s.id);
                label.appendChild(sw);
                label.appendChild(el('span', '', live ? 'Live' : 'Hidden'));
                p.appendChild(label);
            } else {
                p.appendChild(badge(live ? 'Live' : 'Hidden', live ? 'success' : ''));
            }
            tr.appendChild(p);

            var act = cell('', 'is-actions');
            if (boot.canEdit) act.appendChild(button('Edit', { icon: 'pencil', data: { action: 'env.sermon.edit', id: s.id } }));
            tr.appendChild(act);
            body.appendChild(tr);
        });
    }

    function renderRoster() {
        var box = q('[data-env-roster]');
        box.textContent = '';
        if (!data.roster.length) {
            var e = el('div', 'ui-empty ui-empty--compact');
            e.appendChild(el('p', 'ui-empty__title', 'Nobody on the crew yet'));
            if (boot.canManage) e.appendChild(el('p', 'ui-empty__text', 'Use Add to crew.'));
            box.appendChild(e);
            return;
        }
        data.roster.forEach(function (r) {
            var row = el('div', 'ui-list__item');
            var av = el('span', 'ui-avatar ui-avatar--sm ui-avatar--c' + ((fullName(r).length % 6) + 1));
            var url = safeUrl(r.picture_path);
            if (url) { var img = el('img'); img.src = url; img.alt = ''; img.loading = 'lazy'; av.appendChild(img); }
            else { var s = el('span', '', (r.first_name || '?').charAt(0).toUpperCase()); s.setAttribute('aria-hidden', 'true'); av.appendChild(s); }
            row.appendChild(av);
            var main = el('div', 'ui-list__main');
            main.appendChild(el('span', 'ui-list__title', fullName(r)));
            main.appendChild(el('span', 'ui-list__meta', role(r.role_category)));
            row.appendChild(main);
            if (boot.canManage) {
                row.appendChild(button('Change ' + fullName(r) + '’s specialisation', { variant: 'ghost', icon: 'pencil', iconOnly: true,
                    data: { action: 'env.crew.change', id: r.user_id } }));
            }
            box.appendChild(row);
        });
    }

    function load() {
        return HOD.api(API, { method: 'GET', action: 'fetch_dashboard', quiet: true }).then(function (res) {
            data.sermons = res.sermons || [];
            data.roster = res.roster || [];
            data.available = res.available_members || [];
            q('[data-env-error]').hidden = true;
            var st = res.stats || {};
            Object.keys(st).forEach(function (k) {
                var v = q('[data-stat="' + k + '"] .ui-stat__value');
                if (v) v.textContent = HOD.format.number(st[k]);
            });
            renderSermons();
            renderRoster();
        }).catch(function () {
            q('[data-env-error]').hidden = false;
        });
    }

    HOD.actions.on('env.sermon.publish', function (sw) {
        var on = sw.checked;
        sw.disabled = true;
        HOD.api(API, { action: 'toggle_publish', data: { sermon_id: sw.getAttribute('data-id'), is_published: on ? 1 : 0 } }).then(function () {
            HOD.toast(on ? 'Live on the Sermons page.' : 'Hidden from the Sermons page.', { tone: 'success' });
            return load();
        }).catch(function () { sw.checked = !on; sw.disabled = false; });
    });

    /* ------------------------------------------------------ sermon dialog */

    function openSermon(s) {
        var form = q('#env-sermon-form');
        form.reset();
        HOD.form.clearErrors(form);
        form.elements.sermon_id.value = s ? s.id : '';
        ['title', 'preacher', 'date_preached', 'youtube_link', 'summary', 'full_notes'].forEach(function (k) {
            form.elements[k].value = s ? (s[k] || '') : '';
        });
        var type = form.elements.service_type;
        Array.prototype.forEach.call(type.querySelectorAll('[data-extra]'), function (o) { o.remove(); });
        if (s && s.service_type && !Array.prototype.some.call(type.options, function (o) { return o.value === s.service_type; })) {
            var extra = new Option(s.service_type, s.service_type);
            extra.setAttribute('data-extra', '');
            type.appendChild(extra);
        }
        type.value = s && s.service_type ? s.service_type : 'Total Experience';
        var has = [];
        if (s && s.cover_image_path) has.push('a cover');
        if (s && s.audio_file_path) has.push('audio');
        q('[data-env-has-files]', form).textContent = has.length ? 'Already has ' + has.join(' and ') + '; choose a file only to replace it.' : '';
        q('[data-env-progress]', form).hidden = true;
        q('#env-sermon-dialog-title').textContent = s ? 'Edit sermon' : 'Archive a sermon';
        HOD.dialog.open('env-sermon-dialog');
    }

    HOD.actions.on('env.sermon.new', function () { openSermon(null); });
    HOD.actions.on('env.sermon.edit', function (btn) { var s = find(btn.getAttribute('data-id')); if (s) openSermon(s); });

    HOD.actions.on('env.sermon.save', function (form, event) {
        var v = HOD.form.values(form);
        var errors = {};
        if (!(v.title || '').trim()) errors.title = 'Give it a title.';
        if (!(v.preacher || '').trim()) errors.preacher = 'Who preached?';
        if (!v.date_preached) errors.date_preached = 'Choose the date.';
        HOD.form.clearErrors(form);
        if (Object.keys(errors).length) { HOD.form.setErrors(form, errors); return; }

        // XHR rather than fetch: large audio files need an upload progress bar.
        // ui.js adds the CSRF header to every same-origin XHR.
        var submit = (event && event.submitter) || q('button[form="env-sermon-form"]');
        var wrap = q('[data-env-progress]', form);
        var bar = q('progress', wrap);
        var text = q('[data-env-progress-text]', wrap);
        var fd = new FormData(form);
        fd.append('action', 'save_sermon');
        wrap.hidden = false;
        bar.value = 0;
        text.textContent = 'Uploading…';
        HOD.busy(submit, true);
        var xhr = new XMLHttpRequest();
        xhr.open('POST', API);
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.upload.addEventListener('progress', function (e) {
            if (!e.lengthComputable) return;
            var pct = Math.round(e.loaded / e.total * 100);
            bar.value = pct;
            text.textContent = pct < 100 ? 'Uploading… ' + pct + '%' : 'Saving on the server…';
        });
        function done() { HOD.busy(submit, false); }
        xhr.onload = function () {
            var res = null;
            try { res = JSON.parse(xhr.responseText); } catch (e) { res = null; }
            done();
            if (xhr.status === 413) {
                wrap.hidden = true;
                HOD.toast('The server refused the upload: the file is too large.', { tone: 'danger' });
                return;
            }
            if (!res || res.status !== 'success') {
                wrap.hidden = true;
                var msg = (res && res.message) || 'The sermon did not save. Try again.';
                if (res && res.field && form.elements[res.field]) fieldError(form)({ field: res.field, message: msg });
                else HOD.toast(msg, { tone: 'danger' });
                return;
            }
            HOD.dialog.close('env-sermon-dialog');
            HOD.toast(v.sermon_id ? 'Sermon saved.' : 'Sermon archived.', { tone: 'success' });
            load();
        };
        xhr.onerror = function () {
            done();
            wrap.hidden = true;
            HOD.toast("We couldn't reach the server. Check your connection and try again.", { tone: 'danger' });
        };
        xhr.send(fd);
    });

    /* ----------------------------------------------------------- the crew */

    function loadDuties() {
        var box = q('[data-env-duties]');
        return HOD.api(API, { method: 'GET', action: 'fetch_duties', data: { sermon_id: duty.id }, quiet: true }).then(function (res) {
            box.textContent = '';
            var rows = res.duties || [];
            if (!rows.length) box.appendChild(el('p', 'ui-meta', 'Nobody logged yet.'));
            rows.forEach(function (d) {
                var row = el('div', 'ui-list__item');
                var main = el('div', 'ui-list__main');
                main.appendChild(el('span', 'ui-list__title', fullName(d)));
                main.appendChild(el('span', 'ui-list__meta', role(d.duty_role)));
                row.appendChild(main);
                box.appendChild(row);
            });
        });
    }

    HOD.actions.on('env.duty.open', function (btn) {
        var s = find(btn.getAttribute('data-id'));
        if (!s) return;
        duty.id = s.id;
        q('[data-env-duty-for]').textContent = s.title + ' · ' + HOD.format.date(String(s.date_preached) + 'T00:00');
        var form = q('#env-duty-form');
        if (form) {
            form.reset();
            HOD.form.clearErrors(form);
            var who = form.elements.user_id;
            who.textContent = '';
            who.appendChild(new Option(data.roster.length ? 'Choose…' : 'Nobody on the crew yet', ''));
            data.roster.forEach(function (r) { who.appendChild(new Option(fullName(r) + ' · ' + role(r.role_category), r.user_id)); });
            HOD.combobox.refresh(who);
        }
        q('[data-env-duties]').textContent = '';
        HOD.busy(btn, true);
        loadDuties().catch(function () {}).then(function () {
            HOD.busy(btn, false);
            HOD.dialog.open('env-duty-dialog');
        });
    });

    HOD.actions.on('env.duty.save', function (form, event) {
        var v = HOD.form.values(form);
        HOD.form.clearErrors(form);
        if (!v.user_id) { HOD.form.setErrors(form, { user_id: 'Choose who.' }); return; }
        var submit = (event && event.submitter) || q('button[type="submit"]', form);
        HOD.busy(submit, true);
        HOD.api(API, { action: 'assign_duty', data: { sermon_id: duty.id, user_id: v.user_id, duty_role: v.duty_role } }).then(function () {
            HOD.toast('Duty logged.', { tone: 'success' });
            loadDuties();
            return load();
        }).catch(fieldError(form)).then(function () { HOD.busy(submit, false); });
    });

    function openCrew(person) {
        var form = q('#env-crew-form');
        form.reset();
        HOD.form.clearErrors(form);
        var pick = q('[data-env-crew-pick]', form);
        var name = q('[data-env-crew-name]', form);
        var select = form.elements.user_id;
        select.textContent = '';
        if (person) {
            select.appendChild(new Option(fullName(person), person.user_id));
            select.value = String(person.user_id);
            form.elements.role_category.value = person.role_category;
            name.textContent = fullName(person);
        } else {
            select.appendChild(new Option('Choose…', ''));
            data.available.forEach(function (u) { select.appendChild(new Option(fullName(u), u.id)); });
        }
        HOD.combobox.refresh(select);
        pick.hidden = !!person;
        name.hidden = !person;
        q('#env-crew-dialog-title').textContent = person ? 'Change specialisation' : 'Add to the crew';
        HOD.dialog.open('env-crew-dialog');
    }

    HOD.actions.on('env.crew.new', function () {
        if (!data.available.length) {
            HOD.toast('Everyone in the Envision department is already on the crew.', { tone: 'info' });
            return;
        }
        openCrew(null);
    });

    HOD.actions.on('env.crew.change', function (btn) {
        var r = data.roster.filter(function (x) { return String(x.user_id) === btn.getAttribute('data-id'); })[0];
        if (r) openCrew(r);
    });

    HOD.actions.on('env.crew.save', function (form, event) {
        var v = HOD.form.values(form);
        HOD.form.clearErrors(form);
        if (!v.user_id) { HOD.form.setErrors(form, { user_id: 'Choose who.' }); return; }
        var submit = event && event.submitter;
        HOD.busy(submit, true);
        HOD.api(API, { action: 'assign_member', data: { user_id: v.user_id, role_category: v.role_category } }).then(function () {
            HOD.dialog.close('env-crew-dialog');
            HOD.toast('Crew saved.', { tone: 'success' });
            return load();
        }).catch(fieldError(form)).then(function () { HOD.busy(submit, false); });
    });

    /* ------------------------------------------------------------ comments */

    function loadComments() {
        var body = q('[data-env-comments]');
        var term = (q('#env-comment-search').value || '').trim();
        return HOD.api(API, { method: 'GET', action: 'fetch_comments', data: { page: comments.page, search: term }, quiet: true }).then(function (res) {
            commentsLoaded = true;
            comments.page = Number(res.current_page) || 1;
            comments.pages = Math.max(1, Number(res.total_pages) || 1);
            body.textContent = '';
            var rows = res.comments || [];
            if (!rows.length) emptyRow(body, 4, term ? 'No comment matches' : 'No comments yet', '', 'message-circle');
            rows.forEach(function (c) {
                var tr = el('tr');
                var from = cell('From');
                var fw = el('div');
                fw.appendChild(el('div', '', c.author_name || 'Anonymous'));
                var d = c.created_at ? new Date(String(c.created_at).replace(' ', 'T')) : null;
                if (d && !isNaN(d)) fw.appendChild(el('div', 'ui-meta', HOD.format.date(d) + ' · ' + HOD.format.time(d)));
                from.appendChild(fw);
                tr.appendChild(from);
                var text = cell('Comment');
                text.appendChild(el('span', 'whitespace-pre-line break-words', c.comment_text));
                tr.appendChild(text);
                var s = cell('Sermon');
                s.textContent = c.sermon_title || '—';
                tr.appendChild(s);
                var act = cell('', 'is-actions');
                if (boot.canDelete) act.appendChild(button('Delete comment', { variant: 'ghost', icon: 'trash-2', iconOnly: true, data: { action: 'env.comment.delete', id: c.id } }));
                tr.appendChild(act);
                body.appendChild(tr);
            });
            q('[data-env-comment-meta]').textContent = 'Page ' + comments.page + ' of ' + comments.pages + ' · ' + HOD.format.number(res.total_records) + ' comments';
            q('[data-action="env.comments.page"][data-step="-1"]').hidden = comments.page <= 1;
            q('[data-action="env.comments.page"][data-step="1"]').hidden = comments.page >= comments.pages;
        });
    }

    HOD.actions.on('env.comments.search', function () { comments.page = 1; loadComments().catch(function () {}); });
    HOD.actions.on('env.comments.page', function (btn) {
        comments.page = Math.min(comments.pages, Math.max(1, comments.page + Number(btn.getAttribute('data-step'))));
        loadComments().catch(function () {});
    });

    HOD.actions.on('env.comment.delete', function (btn) {
        HOD.confirm({ title: 'Delete this comment?', message: 'It is removed from the Sermons page for good.', confirmLabel: 'Delete', tone: 'danger' }).then(function (yes) {
            if (!yes) return;
            HOD.busy(btn, true);
            HOD.api(API, { action: 'delete_comment', data: { comment_id: btn.getAttribute('data-id') } }).then(function () {
                HOD.toast('Comment deleted.', { tone: 'success' });
                load();
                return loadComments();
            }).catch(function () {}).then(function () { HOD.busy(btn, false); });
        });
    });

    /* --------------------------------------------------------------- radio */

    function paintRadio(live) {
        var sw = q('[data-action-change="env.radio.live"]');
        sw.checked = !!live;
        sw.disabled = !boot.canManage;
        q('[data-env-radio-state]').textContent = live ? 'The live player is showing to the congregation.' : 'Off: nobody sees the player.';
    }

    function loadRadio() {
        return HOD.api(API, { method: 'GET', action: 'fetch_radio_config', quiet: true }).then(function (res) {
            radioLoaded = true;
            q('#env-radio-form').elements.zeno_stream_url.value = res.zeno_stream_url || '';
            paintRadio(Number(res.is_live_audio_on) === 1);
        });
    }

    HOD.actions.on('env.radio.save', function (form, event) {
        var url = (form.elements.zeno_stream_url.value || '').trim();
        HOD.form.clearErrors(form);
        var submit = (event && event.submitter) || q('button[type="submit"]', form);
        HOD.busy(submit, true);
        HOD.api(API, { action: 'save_radio_config', data: { zeno_stream_url: url } }).then(function () {
            HOD.toast('Stream link saved.', { tone: 'success' });
        }).catch(fieldError(form)).then(function () { HOD.busy(submit, false); });
    });

    HOD.actions.on('env.radio.test', function (btn) {
        HOD.busy(btn, true);
        HOD.api(API, { method: 'GET', action: 'test_radio_connection' }).then(function (res) {
            HOD.toast(res.message, { tone: res.live ? 'success' : 'warning' });
        }).catch(function () {}).then(function () { HOD.busy(btn, false); });
    });

    HOD.actions.on('env.radio.live', function (sw) {
        var on = sw.checked;
        sw.disabled = true;
        HOD.api(API, { action: 'toggle_radio_live', data: { is_live: on ? 1 : 0 } }).then(function () {
            HOD.toast(on ? 'Broadcast is on.' : 'Broadcast is off.', { tone: 'success' });
            paintRadio(on);
        }).catch(function () { paintRadio(!on); });
    });

    /* ---------------------------------------------------------------- tabs */

    function onTab(tab) {
        if (tab === 'comments' && !commentsLoaded) loadComments().catch(function () {});
        if (tab === 'config' && !radioLoaded) loadRadio().catch(function () {});
    }
    document.addEventListener('ui:tab', function (e) { onTab(e.detail && e.detail.tab); });

    load();
    var hash = (window.location.hash.match(/tab=(\w+)/) || [])[1];
    if (hash) onTab(hash);
})();
