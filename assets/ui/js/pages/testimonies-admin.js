// /assets/ui/js/pages/testimonies-admin.js
// Testimonies admin (modules/testimonies/index.php): stats, the list with
// search and a status filter, the Sunday toggle, delete, and the review
// dialog with a small editor (bold, italic, underline, lists). Data from
// /api/testimony_admin_api.php through HOD.api.
//
// Names and previews go into the page as TEXT. The editor receives
// content_html, which the API has already put through its allowlist; what the
// editor sends back is cleaned by the same allowlist before it is stored.
(function () {
    'use strict';

    var HOD = window.HOD;
    if (!HOD || !HOD.actions) return;
    var boot = HOD.data('testimonies-admin-boot');
    if (!boot) return;
    var API = boot.api;

    var rows = [];
    var STATUS = { Published: ['Published', 'success'], Pending_Editing: ['Pending', 'warning'], Hidden: ['Hidden', ''] };
    var PRIVACY = { Public: ['Public', 'info'], Anonymous_To_All: ['Anonymous', ''], Pastoral_Only: ['Pastors only', 'primary'] };

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
        if (opts.pressed !== undefined) b.setAttribute('aria-pressed', opts.pressed ? 'true' : 'false');
        Object.keys(opts.data || {}).forEach(function (k) { b.setAttribute('data-' + k, opts.data[k]); });
        return b;
    }

    function cell(label, cls) {
        var td = el('td', cls || '');
        if (label) td.setAttribute('data-label', label);
        return td;
    }

    function safeUrl(u) {
        u = String(u || '').trim();
        return /^\/(?!\/)/.test(u) ? u : '';
    }

    /** The words of cleaned HTML, never parsed into this page. */
    function plain(html) {
        var doc = new DOMParser().parseFromString('<body>' + String(html || '') + '</body>', 'text/html');
        return (doc.body.textContent || '').replace(/\s+/g, ' ').trim();
    }

    function find(id) {
        return rows.filter(function (r) { return String(r.id) === String(id); })[0] || null;
    }

    function when(raw) {
        return raw ? HOD.format.date(String(raw).replace(' ', 'T')) : '';
    }

    /* ---------------------------------------------------------------- list */

    function render() {
        var body = q('[data-tm-body]');
        var term = (q('#tm-search').value || '').trim().toLowerCase();
        var status = q('#tm-status').value;
        var shown = rows.filter(function (t) {
            if (status === 'sunday' && Number(t.is_selected_for_service) !== 1) return false;
            if (status && status !== 'sunday' && t.status !== status) return false;
            if (!term) return true;
            return (t.display_name + ' ' + t._text).toLowerCase().indexOf(term) !== -1;
        });
        body.textContent = '';
        if (!shown.length) {
            var tr = el('tr');
            var td = el('td');
            td.colSpan = 5;
            var box = el('div', 'ui-empty ui-empty--compact');
            var ic = el('div', 'ui-empty__icon');
            ic.appendChild(icon('sparkles'));
            box.appendChild(ic);
            box.appendChild(el('p', 'ui-empty__title', rows.length ? 'No testimony matches' : 'No testimonies yet'));
            td.appendChild(box);
            tr.appendChild(td);
            body.appendChild(tr);
            return;
        }
        shown.forEach(function (t) {
            var tr = el('tr');

            var from = cell('From');
            var f = el('div');
            f.appendChild(el('div', '', t.display_name));
            f.appendChild(el('div', 'ui-meta', t.submitter_type + ' · ' + when(t.created_at)));
            from.appendChild(f);
            tr.appendChild(from);

            var st = cell('Status');
            var tags = el('div', 'flex flex-wrap gap-1');
            var s = STATUS[t.status] || [t.status || '—', ''];
            tags.appendChild(badge(s[0], s[1]));
            var p = PRIVACY[t.privacy_level] || [t.privacy_level || '', ''];
            if (p[0]) tags.appendChild(badge(p[0], p[1]));
            if (Number(t.request_editing) === 1) tags.appendChild(badge('Edit asked', 'danger'));
            st.appendChild(tags);
            tr.appendChild(st);

            var text = cell('Testimony');
            var tw = el('div');
            var preview = t._text.length > 140 ? t._text.slice(0, 140) + '…' : t._text;
            tw.appendChild(el('div', 'break-words', preview || 'Voice note only.'));
            if (safeUrl(t.voice_note_url)) tw.appendChild(el('div', 'ui-meta', 'Voice note attached'));
            text.appendChild(tw);
            tr.appendChild(text);

            var sun = cell('Sunday');
            var on = Number(t.is_selected_for_service) === 1;
            if (boot.canEdit) {
                sun.appendChild(button(on ? 'Chosen' : 'Choose', {
                    variant: on ? 'primary' : 'secondary', icon: on ? 'check' : 'calendar', pressed: on,
                    data: { action: 'tma.sunday', id: t.id }
                }));
            } else {
                sun.appendChild(on ? badge('Chosen', 'success') : el('span', 'ui-meta', '—'));
            }
            tr.appendChild(sun);

            var act = cell('', 'is-actions');
            var group = el('div', 'ui-toolbar justify-end');
            if (boot.canEdit) group.appendChild(button('Review', { icon: 'pencil', data: { action: 'tma.review', id: t.id } }));
            if (boot.canDelete) group.appendChild(button('Delete', { variant: 'ghost', icon: 'trash-2', iconOnly: true, data: { action: 'tma.delete', id: t.id } }));
            act.appendChild(group);
            tr.appendChild(act);
            body.appendChild(tr);
        });
    }

    function load() {
        return HOD.api(API, { method: 'GET', action: 'fetch_dashboard', quiet: true }).then(function (res) {
            rows = (res.testimonies || []).map(function (t) { t._text = plain(t.content_html); return t; });
            q('[data-tm-error]').hidden = true;
            var a = res.analytics || {};
            ['total_testimonies', 'pending_edits', 'total_voice_notes', 'selected_for_service'].forEach(function (k) {
                var v = q('[data-stat="' + k + '"] .ui-stat__value');
                if (v) v.textContent = HOD.format.number(a[k]);
            });
            render();
        }).catch(function () {
            q('[data-tm-error]').hidden = false;
        });
    }

    HOD.actions.on('tma.filter', render);

    /* ------------------------------------------------------ sunday, delete */

    HOD.actions.on('tma.sunday', function (btn) {
        var t = find(btn.getAttribute('data-id'));
        if (!t) return;
        HOD.busy(btn, true);
        HOD.api(API, { action: 'toggle_service_selection', data: { testimony_id: t.id, current_state: Number(t.is_selected_for_service) === 1 ? 1 : 0 } })
            .then(function (res) {
                HOD.toast(Number(res.selected) === 1 ? 'Chosen for Sunday.' : 'Taken off Sunday.', { tone: 'success' });
                return load();
            }).catch(function () {}).then(function () { HOD.busy(btn, false); });
    });

    HOD.actions.on('tma.delete', function (btn) {
        var t = find(btn.getAttribute('data-id'));
        if (!t) return;
        HOD.confirm({
            title: 'Delete this testimony?',
            message: 'From ' + t.display_name + '. It cannot be brought back.',
            confirmLabel: 'Delete',
            tone: 'danger'
        }).then(function (yes) {
            if (!yes) return;
            HOD.busy(btn, true);
            HOD.api(API, { action: 'delete_testimony', data: { testimony_id: t.id } }).then(function () {
                HOD.toast('Testimony deleted.', { tone: 'success' });
                return load();
            }).catch(function () {}).then(function () { HOD.busy(btn, false); });
        });
    });

    /* -------------------------------------------------------------- editor */

    var editor = q('[data-tm-editor]');

    if (editor) {
        // Pasted text arrives plain: no fonts, colours or links from elsewhere.
        editor.addEventListener('paste', function (e) {
            var text = e.clipboardData ? e.clipboardData.getData('text/plain') : '';
            e.preventDefault();
            document.execCommand('insertText', false, text);
        });
        // Dropped files or HTML stay out too.
        editor.addEventListener('drop', function (e) { e.preventDefault(); });
    }

    HOD.actions.on('tma.format', function (btn) {
        if (!editor) return;
        editor.focus();
        document.execCommand(btn.getAttribute('data-cmd'), false, null);
    });

    // Keep the editor's place while the toolbar is clicked.
    var tools = q('[data-tm-tools]');
    if (tools) tools.addEventListener('mousedown', function (e) { if (e.target.closest('button')) e.preventDefault(); });

    HOD.actions.on('tma.review', function (btn) {
        var t = find(btn.getAttribute('data-id'));
        if (!t || !editor) return;
        var form = q('#tm-review-form');
        form.reset();
        HOD.form.clearErrors(form);
        q('#tm-editor-error').textContent = '';
        form.elements.testimony_id.value = t.id;
        q('[data-tm-meta]', form).textContent = t.display_name + ' · ' + t.submitter_type + ' · '
            + (PRIVACY[t.privacy_level] ? PRIVACY[t.privacy_level][0] : '') + ' · ' + when(t.created_at);

        var audioWrap = q('[data-tm-audio]', form);
        var audio = q('audio', audioWrap);
        var url = safeUrl(t.voice_note_url);
        audioWrap.hidden = !url;
        if (url) audio.src = url; else audio.removeAttribute('src');

        // Already cleaned by the API's allowlist (no attributes, formatting tags only).
        editor.innerHTML = t.content_html || '';
        form.elements.status.value = t.status || 'Published';
        HOD.dialog.open('tm-review-dialog');
    });

    var dialog = document.getElementById('tm-review-dialog');
    if (dialog) dialog.addEventListener('close', function () { var a = q('audio', dialog); if (a) a.pause(); });

    HOD.actions.on('tma.save', function (form, event) {
        var html = editor.innerHTML;
        var err = q('#tm-editor-error');
        err.textContent = '';
        editor.removeAttribute('aria-invalid');
        if (!plain(html)) {
            err.textContent = 'Write the testimony.';
            editor.setAttribute('aria-invalid', 'true');
            editor.focus();
            return;
        }
        var submit = event && event.submitter;
        HOD.busy(submit, true);
        HOD.api(API, { action: 'save_edited_testimony', data: {
            testimony_id: form.elements.testimony_id.value, content_text: html, status: form.elements.status.value
        } }).then(function () {
            HOD.dialog.close('tm-review-dialog');
            HOD.toast('Testimony saved.', { tone: 'success' });
            return load();
        }).catch(function (e) {
            if (e && e.field === 'content_text') { err.textContent = e.message; editor.setAttribute('aria-invalid', 'true'); }
        }).then(function () { HOD.busy(submit, false); });
    });

    load();
})();
