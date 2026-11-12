// /assets/ui/js/pages/junior-church.js
// Junior Church (modules/junior_church/index.php): the children (with
// medical alerts and a quick call or WhatsApp to a guardian), the classes
// and their attendance, the curriculum vault, and the presenting view for a
// class video, PDF or image. Data from /api/junior_church_api.php through
// HOD.api. Names, notes, topics and titles go into the page as TEXT (DOM
// nodes); links are only same-origin uploads, https links and YouTube ids.
(function () {
    'use strict';

    var HOD = window.HOD;
    if (!HOD || !HOD.actions) return;
    var boot = HOD.data('junior-church-boot');
    if (!boot) return;
    var API = boot.api;

    var data = { children: [], teachers: [], parents: [], services: [], curriculums: [] };
    var att = { id: null, rows: [] };
    var photo = '';      // a newly cropped photo (data URL) for the child dialog
    var cropper = null;

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

    function link(label, href, opts) {
        opts = opts || {};
        var a = el('a', 'ui-btn ' + (opts.variant ? 'ui-btn--' + opts.variant : 'ui-btn--secondary') + ' ui-btn--sm');
        a.href = href;
        if (opts.icon) a.appendChild(icon(opts.icon));
        a.appendChild(el('span', '', label));
        if (opts.blank) { a.target = '_blank'; a.rel = 'noopener noreferrer'; }
        if (opts.download) a.setAttribute('download', '');
        return a;
    }

    function cell(label, cls) {
        var td = el('td', cls || '');
        if (label) td.setAttribute('data-label', label);
        return td;
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

    function localPath(u) { u = String(u || '').trim(); return /^\/(?!\/)[^\s"'<>]*$/.test(u) ? u : ''; }
    function webLink(u) { u = String(u || '').trim(); return /^https?:\/\/[^\s"'<>]+$/i.test(u) ? u : ''; }

    function youtubeId(url) {
        var m = String(url || '').match(/(?:youtube\.com\/(?:watch\?(?:.*&)?v=|embed\/|v\/|shorts\/|live\/)|youtu\.be\/)([A-Za-z0-9_-]{11})/);
        return m ? m[1] : null;
    }

    function fileKind(path) {
        var ext = String(path || '').split('.').pop().toLowerCase();
        if (ext === 'pdf') return 'pdf';
        if (['png', 'jpg', 'jpeg', 'gif', 'webp'].indexOf(ext) !== -1) return 'image';
        if (['doc', 'docx'].indexOf(ext) !== -1) return 'doc';
        if (['ppt', 'pptx'].indexOf(ext) !== -1) return 'slides';
        return 'file';
    }

    function name(first, last) { return ((first || '') + ' ' + (last || '')).trim(); }

    function day(raw, long) { return raw ? HOD.format.date(String(raw).slice(0, 10) + 'T00:00', long ? 'long' : undefined) : ''; }

    function age(dob) {
        if (!dob) return '';
        var d = new Date(String(dob) + 'T00:00');
        if (isNaN(d)) return '';
        var years = Math.floor((Date.now() - d.getTime()) / 31557600000);
        return years + (years === 1 ? ' year' : ' years');
    }

    function waNumber(phone) {
        var n = String(phone || '').replace(/\D/g, '');
        return n.charAt(0) === '0' ? '234' + n.slice(1) : n;
    }

    function contactLinks(phone, who) {
        var row = el('div', 'flex flex-wrap gap-1');
        var digits = String(phone || '').replace(/[^\d+]/g, '');
        if (!digits) return row;
        var call = link('Call', 'tel:' + digits, { icon: 'phone', variant: 'ghost' });
        call.setAttribute('aria-label', 'Call ' + who);
        row.appendChild(call);
        var wa = link('WhatsApp', 'https://wa.me/' + waNumber(phone), { icon: 'message-circle', variant: 'ghost', blank: true });
        wa.setAttribute('aria-label', 'WhatsApp ' + who);
        row.appendChild(wa);
        return row;
    }

    function avatar(path, first, size) {
        var a = el('span', 'ui-avatar ' + (size || 'ui-avatar--sm') + ' ui-avatar--c' + ((String(first || '').length % 6) + 1));
        var url = localPath(path);
        if (url) { var img = el('img'); img.src = url; img.alt = ''; img.loading = 'lazy'; a.appendChild(img); }
        else { var s = el('span', '', (first || '?').charAt(0).toUpperCase()); s.setAttribute('aria-hidden', 'true'); a.appendChild(s); }
        return a;
    }

    function fieldError(form) {
        return function (err) {
            if (err && err.field && form.elements[err.field]) HOD.form.setErrors(form, (function (o) { o[err.field] = err.message; return o; })({}));
        };
    }

    /* ------------------------------------------------------------ children */

    function renderChildren() {
        var box = q('[data-jc-children]');
        var term = (q('#jc-search').value || '').trim().toLowerCase();
        box.textContent = '';
        var shown = data.children.filter(function (c) {
            if (!term) return true;
            return [c.child_first_name, c.child_last_name, c.parent_fname, c.parent_lname, c.parent2_fname, c.parent2_lname].join(' ').toLowerCase().indexOf(term) !== -1;
        });
        if (!shown.length) {
            var wrap = el('div', 'col-span-full');
            wrap.appendChild(empty(data.children.length ? 'No child matches' : 'No children yet', !data.children.length && boot.canEdit ? 'Use Register child.' : '', 'baby'));
            box.appendChild(wrap);
            return;
        }
        shown.forEach(function (c) {
            var card = el('section', 'ui-card ui-card__body ui-stack');
            var head = el('div', 'flex items-center gap-3');
            head.appendChild(avatar(c.picture_path, c.child_first_name, 'ui-avatar--lg'));
            var hw = el('div', 'min-w-0');
            hw.appendChild(el('h3', 'ui-h3 truncate', name(c.child_first_name, c.child_last_name)));
            hw.appendChild(el('p', 'ui-meta', age(c.dob) || 'Age not given'));
            head.appendChild(hw);
            card.appendChild(head);

            if (c.medical_notes) {
                var med = el('div', 'ui-banner ui-banner--danger');
                med.setAttribute('role', 'note');
                med.appendChild(icon('triangle-alert'));
                var mb = el('div', 'ui-banner__body');
                mb.appendChild(el('p', 'ui-banner__title', 'Medical alert'));
                mb.appendChild(el('p', 'ui-banner__text whitespace-pre-line', c.medical_notes));
                med.appendChild(mb);
                card.appendChild(med);
            }

            var g = el('div');
            g.appendChild(el('p', 'ui-label', 'Guardian'));
            var p1 = name(c.parent_fname, c.parent_lname);
            g.appendChild(el('p', '', p1 || '—'));
            if (c.parent_phone) g.appendChild(el('p', 'ui-meta', c.parent_phone));
            g.appendChild(contactLinks(c.parent_phone, p1 || 'the guardian'));
            var p2 = name(c.parent2_fname, c.parent2_lname);
            if (p2) {
                g.appendChild(el('p', 'mt-2', p2));
                if (c.parent2_phone) {
                    g.appendChild(el('p', 'ui-meta', c.parent2_phone));
                    g.appendChild(contactLinks(c.parent2_phone, p2));
                }
            }
            card.appendChild(g);

            if (boot.canEdit) card.appendChild(button('Edit', { icon: 'pencil', data: { action: 'jc.child.edit', id: c.id } }));
            box.appendChild(card);
        });
    }

    HOD.actions.on('jc.filter', renderChildren);

    /* ------------------------------------------------------------- classes */

    function resourceBadges(s) {
        var tags = el('div', 'flex flex-wrap gap-1');
        if (localPath(s.service_file_path)) tags.appendChild(badge('File', 'primary'));
        if (s.media_link) tags.appendChild(badge(youtubeId(s.media_link) ? 'Video' : 'Link', youtubeId(s.media_link) ? 'danger' : 'info'));
        if (s.master_pages_covered) tags.appendChild(badge('Pages ' + s.master_pages_covered, 'warning'));
        if (!tags.childNodes.length) tags.appendChild(el('span', 'ui-meta', '—'));
        return tags;
    }

    function renderClasses() {
        var body = q('[data-jc-classes]');
        body.textContent = '';
        if (!data.services.length) {
            var tr = el('tr');
            var td = el('td');
            td.colSpan = 6;
            td.appendChild(empty('No classes logged yet', boot.canCreate ? 'Use Log class.' : '', 'calendar-check'));
            tr.appendChild(td);
            body.appendChild(tr);
            return;
        }
        data.services.forEach(function (s) {
            var tr = el('tr');
            var d = cell('Date');
            d.textContent = day(s.service_date);
            tr.appendChild(d);
            var t = cell('Topic');
            t.appendChild(el('span', 'font-semibold', s.topic));
            tr.appendChild(t);
            var who = cell('Teacher');
            who.textContent = name(s.teacher_fname, s.teacher_lname);
            tr.appendChild(who);
            var p = cell('Present');
            p.appendChild(badge(HOD.format.number(s.kids_present) + ' present', 'success'));
            tr.appendChild(p);
            var r = cell('Resources');
            r.appendChild(resourceBadges(s));
            tr.appendChild(r);
            var act = cell('', 'is-actions');
            var group = el('div', 'ui-toolbar justify-end');
            group.appendChild(button('Details', { variant: 'ghost', icon: 'file-text', data: { action: 'jc.class.detail', id: s.id } }));
            group.appendChild(button(boot.canEdit ? 'Attendance' : 'Who came', { icon: 'check', data: { action: 'jc.att.open', id: s.id } }));
            act.appendChild(group);
            tr.appendChild(act);
            body.appendChild(tr);
        });
    }

    function findClass(id) {
        return data.services.filter(function (s) { return String(s.id) === String(id); })[0] || null;
    }

    function resourceButtons(s, box) {
        box.textContent = '';
        var yt = youtubeId(s.media_link);
        if (yt) box.appendChild(button('Play video', { variant: 'primary', icon: 'video', data: { action: 'jc.show', kind: 'youtube', src: yt } }));
        else if (webLink(s.media_link)) box.appendChild(link('Open link', webLink(s.media_link), { icon: 'external-link', blank: true }));
        var file = localPath(s.service_file_path);
        if (file) {
            box.appendChild(link('Download', file, { icon: 'download', download: true }));
            var kind = fileKind(file);
            if (kind === 'pdf' || kind === 'image') box.appendChild(button('Present', { icon: 'external-link', data: { action: 'jc.show', kind: kind, src: file } }));
        }
        box.hidden = !box.childNodes.length;
    }

    HOD.actions.on('jc.class.detail', function (btn) {
        var s = findClass(btn.getAttribute('data-id'));
        if (!s) return;
        q('#jc-detail-dialog-title').textContent = s.topic;
        var dl = q('[data-jc-detail]');
        dl.textContent = '';
        function row(label, value) { if (!value) return; dl.appendChild(el('dt', '', label)); var dd = el('dd', 'whitespace-pre-line break-words', value); dl.appendChild(dd); }
        row('Date', day(s.service_date, true));
        row('Teacher', name(s.teacher_fname, s.teacher_lname));
        row('Present', HOD.format.number(s.kids_present));
        row('Curriculum pages', s.master_pages_covered);
        row('Notes', s.notes);
        resourceButtons(s, q('[data-jc-detail-resources]'));
        HOD.dialog.open('jc-detail-dialog');
    });

    /* ---------------------------------------------------------- attendance */

    function renderAttendance() {
        var box = q('[data-jc-att]');
        box.textContent = '';
        var present = att.rows.filter(function (c) { return c.status === 'Present'; }).length;
        q('[data-jc-att-count]').textContent = present + ' of ' + att.rows.length + ' present';
        if (!att.rows.length) {
            box.appendChild(empty('Nobody on this sheet', 'Children join a sheet when they are registered before the class is logged.', 'baby'));
            return;
        }
        att.rows.forEach(function (c) {
            var row = el('div', 'ui-list__item flex-wrap');
            row.appendChild(avatar(c.picture_path, c.child_first_name));
            var main = el('div', 'ui-list__main');
            main.appendChild(el('span', 'ui-list__title', name(c.child_first_name, c.child_last_name)));
            if (c.medical_notes) {
                var med = el('span', 'flex items-start gap-1 text-danger text-sm mt-1');
                med.appendChild(icon('triangle-alert'));
                med.appendChild(el('span', 'whitespace-pre-line break-words', c.medical_notes));
                main.appendChild(med);
            }
            if (c.parent_phone) main.appendChild(contactLinks(c.parent_phone, c.parent_fname || 'the guardian'));
            row.appendChild(main);
            var here = c.status === 'Present';
            if (boot.canEdit) {
                row.appendChild(button(here ? 'Present' : 'Absent', {
                    variant: here ? 'primary' : 'secondary', icon: here ? 'check' : undefined, pressed: here,
                    data: { action: 'jc.att.toggle', child: c.child_id }
                }));
            } else {
                row.appendChild(badge(here ? 'Present' : 'Absent', here ? 'success' : ''));
            }
            box.appendChild(row);
        });
    }

    function loadAttendance() {
        return HOD.api(API, { method: 'GET', action: 'fetch_attendance', data: { service_id: att.id }, quiet: true }).then(function (res) {
            att.rows = res.attendance || [];
            renderAttendance();
        });
    }

    HOD.actions.on('jc.att.open', function (btn) {
        var s = findClass(btn.getAttribute('data-id'));
        if (!s) return;
        att.id = s.id;
        att.rows = [];
        q('[data-jc-att-for]').textContent = s.topic + ' · ' + day(s.service_date, true) + (s.master_pages_covered ? ' · Pages ' + s.master_pages_covered : '');
        resourceButtons(s, q('[data-jc-att-resources]'));
        HOD.busy(btn, true);
        loadAttendance().then(function () {
            HOD.dialog.open('jc-attendance-dialog');
        }).catch(function () {
            HOD.toast('The attendance sheet did not load. Try again.', { tone: 'danger' });
        }).then(function () { HOD.busy(btn, false); });
    });

    HOD.actions.on('jc.att.toggle', function (btn) {
        var id = btn.getAttribute('data-child');
        var c = att.rows.filter(function (x) { return String(x.child_id) === String(id); })[0];
        if (!c) return;
        var before = c.status;
        c.status = before === 'Present' ? 'Absent' : 'Present';   // show it at once; put it back if the save fails
        renderAttendance();
        HOD.api(API, { action: 'mark_attendance', data: { service_id: att.id, child_id: id, status: c.status } }).catch(function () {
            c.status = before;
            renderAttendance();
        });
    });

    HOD.actions.on('jc.att.all', function (btn) {
        HOD.busy(btn, true);
        HOD.api(API, { action: 'mark_all_present', data: { service_id: att.id } }).then(function () {
            att.rows.forEach(function (c) { c.status = 'Present'; });
            renderAttendance();
            HOD.toast('Everyone marked present.', { tone: 'success' });
        }).catch(function () {}).then(function () { HOD.busy(btn, false); });
    });

    var attDialog = document.getElementById('jc-attendance-dialog');
    if (attDialog) attDialog.addEventListener('ui:dialog-close', function () { if (att.id) load(); });

    /* ---------------------------------------------------------- curriculum */

    var KIND_ICON = { pdf: 'file-text', image: 'image', doc: 'file-text', slides: 'file-text', file: 'file-text' };

    function renderCurriculum() {
        var box = q('[data-jc-curriculum]');
        box.textContent = '';
        if (!data.curriculums.length) {
            var wrap = el('div', 'col-span-full');
            wrap.appendChild(empty('No curriculum yet', boot.canCreate ? 'Use Upload to add the first manual.' : '', 'book-open'));
            box.appendChild(wrap);
            return;
        }
        data.curriculums.forEach(function (cur) {
            var path = localPath(cur.file_path);
            var kind = fileKind(path);
            var card = el('section', 'ui-card ui-card__body ui-stack');
            var head = el('div', 'flex items-start gap-3');
            var ic = el('span', 'ui-avatar ui-avatar--sm ui-avatar--square');
            ic.appendChild(icon(KIND_ICON[kind] || 'file-text'));
            head.appendChild(ic);
            var hw = el('div', 'min-w-0');
            hw.appendChild(el('h3', 'ui-h3 break-words', cur.title));
            hw.appendChild(el('p', 'ui-meta', day(cur.uploaded_at) + ' · ' + String(cur.file_type || kind).toUpperCase()));
            if (cur.description) hw.appendChild(el('p', 'ui-meta whitespace-pre-line break-words', cur.description));
            head.appendChild(hw);
            card.appendChild(head);
            var actions = el('div', 'ui-toolbar');
            if (path) actions.appendChild(link('Download', path, { icon: 'download', download: true }));
            if (path && (kind === 'pdf' || kind === 'image')) actions.appendChild(button('Present', { icon: 'external-link', data: { action: 'jc.show', kind: kind, src: path } }));
            if (boot.canDelete) actions.appendChild(button('Delete ' + cur.title, { variant: 'ghost', icon: 'trash-2', iconOnly: true, data: { action: 'jc.curriculum.delete', id: cur.id } }));
            card.appendChild(actions);
            box.appendChild(card);
        });
    }

    HOD.actions.on('jc.curriculum.new', function () {
        var form = q('#jc-curriculum-form');
        form.reset();
        HOD.form.clearErrors(form);
        HOD.dialog.open('jc-curriculum-dialog');
    });

    HOD.actions.on('jc.curriculum.save', function (form, event) {
        var errors = {};
        if (!(form.elements.title.value || '').trim()) errors.title = 'Give it a title.';
        if (!form.elements.curriculum_file.files.length) errors.curriculum_file = 'Choose the file.';
        HOD.form.clearErrors(form);
        if (Object.keys(errors).length) { HOD.form.setErrors(form, errors); return; }
        var submit = event && event.submitter;
        HOD.busy(submit, true);
        HOD.api(API, { action: 'upload_curriculum', data: new FormData(form) }).then(function () {
            HOD.dialog.close('jc-curriculum-dialog');
            HOD.toast('Added to the curriculum.', { tone: 'success' });
            return load();
        }).catch(fieldError(form)).then(function () { HOD.busy(submit, false); });
    });

    HOD.actions.on('jc.curriculum.delete', function (btn) {
        var cur = data.curriculums.filter(function (x) { return String(x.id) === btn.getAttribute('data-id'); })[0];
        if (!cur) return;
        HOD.confirm({ title: 'Delete this resource?', message: cur.title + ' and its file are removed for good.', confirmLabel: 'Delete', tone: 'danger' }).then(function (yes) {
            if (!yes) return;
            HOD.busy(btn, true);
            HOD.api(API, { action: 'delete_curriculum', data: { curriculum_id: cur.id } }).then(function () {
                HOD.toast('Resource deleted.', { tone: 'success' });
                return load();
            }).catch(function () {}).then(function () { HOD.busy(btn, false); });
        });
    });

    /* ----------------------------------------------- presenting to a class */

    HOD.actions.on('jc.show', function (btn) {
        var kind = btn.getAttribute('data-kind');
        var src = btn.getAttribute('data-src');
        var box = q('[data-jc-show]');
        box.textContent = '';
        if (kind === 'youtube' && /^[A-Za-z0-9_-]{11}$/.test(src)) {
            var f = el('iframe', 'w-full aspect-video');
            f.src = 'https://www.youtube-nocookie.com/embed/' + src + '?autoplay=1&rel=0&modestbranding=1';
            f.title = 'Class video';
            f.allow = 'autoplay; encrypted-media; fullscreen';
            f.setAttribute('allowfullscreen', '');
            box.appendChild(f);
        } else if (kind === 'pdf' && localPath(src)) {
            var p = el('iframe', 'w-full h-[70vh] bg-white');
            p.src = localPath(src);
            p.title = 'Class document';
            box.appendChild(p);
        } else if (kind === 'image' && localPath(src)) {
            var img = el('img', 'max-h-[70vh] max-w-full object-contain');
            img.src = localPath(src);
            img.alt = 'Class picture';
            box.appendChild(img);
        } else {
            return;
        }
        HOD.dialog.open('jc-show-dialog');
    });

    HOD.actions.on('jc.show.full', function () {
        var box = q('[data-jc-show]');
        if (box.requestFullscreen) box.requestFullscreen().catch(function () {});
        else if (box.webkitRequestFullscreen) box.webkitRequestFullscreen();
    });

    var showDialog = document.getElementById('jc-show-dialog');
    if (showDialog) showDialog.addEventListener('ui:dialog-close', function () { q('[data-jc-show]').textContent = ''; });

    /* --------------------------------------------------------- child dialog */

    function fillPeople(select, first) {
        select.textContent = '';
        select.appendChild(new Option(first, ''));
        data.parents.forEach(function (p) { select.appendChild(new Option(name(p.first_name, p.last_name) + (p.phone ? ' · ' + p.phone : ''), p.id)); });
    }

    function showPhoto(src, first) {
        var holder = q('[data-jc-photo]');
        holder.textContent = '';
        var url = src && (src.indexOf('data:image/') === 0 ? src : localPath(src));
        if (url) { var img = el('img'); img.src = url; img.alt = ''; holder.appendChild(img); }
        else { var s = el('span', '', (first || '?').charAt(0).toUpperCase()); s.setAttribute('aria-hidden', 'true'); holder.appendChild(s); }
    }

    function openChild(c) {
        var form = q('#jc-child-form');
        form.reset();
        HOD.form.clearErrors(form);
        photo = '';
        fillPeople(form.elements.parent_id, 'Choose…');
        fillPeople(form.elements.parent2_id, 'None');
        form.elements.child_id.value = c ? c.id : '';
        form.elements.child_first_name.value = c ? c.child_first_name : '';
        form.elements.child_last_name.value = c ? c.child_last_name : '';
        form.elements.dob.value = c && c.dob ? c.dob : '';
        form.elements.parent_id.value = c ? String(c.parent_id || '') : '';
        form.elements.parent2_id.value = c && c.parent2_id ? String(c.parent2_id) : '';
        form.elements.medical_notes.value = c ? (c.medical_notes || '') : '';
        HOD.combobox.refresh(form.elements.parent_id);
        HOD.combobox.refresh(form.elements.parent2_id);
        showPhoto(c ? c.picture_path : '', c ? c.child_first_name : '');
        q('#jc-child-dialog-title').textContent = c ? 'Edit child' : 'Register a child';
        HOD.dialog.open('jc-child-dialog');
    }

    HOD.actions.on('jc.child.new', function () { openChild(null); });
    HOD.actions.on('jc.child.edit', function (btn) {
        var c = data.children.filter(function (x) { return String(x.id) === btn.getAttribute('data-id'); })[0];
        if (c) openChild(c);
    });

    HOD.actions.on('jc.photo.pick', function (input) {
        var file = input.files && input.files[0];
        if (!file) return;
        var reader = new FileReader();
        reader.onload = function (e) {
            var img = q('#jc-crop-img');
            img.src = e.target.result;
            HOD.dialog.open('jc-crop-dialog');
            HOD.lib('cropper').then(function () {
                if (cropper) cropper.destroy();
                // After the dialog has laid out (a timer, not a frame: frames
                // pause while the tab is in the background).
                setTimeout(function () {
                    cropper = new window.Cropper(img, { aspectRatio: 1, viewMode: 1, dragMode: 'move', autoCropArea: 0.8, guides: false, toggleDragModeOnDblclick: false });
                }, 60);
            }).catch(function () { HOD.toast('The photo editor did not load. Try again.', { tone: 'danger' }); });
        };
        reader.readAsDataURL(file);
        input.value = '';
    });

    HOD.actions.on('jc.photo.apply', function () {
        if (!cropper) return;
        photo = cropper.getCroppedCanvas({ width: 400, height: 400 }).toDataURL('image/png');
        showPhoto(photo, '');
        HOD.dialog.close('jc-crop-dialog');
    });

    HOD.actions.on('jc.child.save', function (form, event) {
        var v = HOD.form.values(form);
        var errors = {};
        if (!(v.child_first_name || '').trim()) errors.child_first_name = 'Give the first name.';
        if (!(v.child_last_name || '').trim()) errors.child_last_name = 'Give the last name.';
        if (!v.parent_id) errors.parent_id = 'Choose a guardian.';
        HOD.form.clearErrors(form);
        if (Object.keys(errors).length) { HOD.form.setErrors(form, errors); return; }
        var payload = {
            child_id: v.child_id, child_first_name: v.child_first_name.trim(), child_last_name: v.child_last_name.trim(),
            dob: v.dob || '', parent_id: v.parent_id, parent2_id: v.parent2_id || '', medical_notes: (v.medical_notes || '').trim()
        };
        if (photo) payload.image_base64 = photo;
        var submit = event && event.submitter;
        HOD.busy(submit, true);
        HOD.api(API, { action: 'save_child', data: payload }).then(function () {
            HOD.dialog.close('jc-child-dialog');
            HOD.toast(v.child_id ? 'Child saved.' : 'Child registered.', { tone: 'success' });
            return load();
        }).catch(fieldError(form)).then(function () { HOD.busy(submit, false); });
    });

    /* --------------------------------------------------------- class dialog */

    HOD.actions.on('jc.class.new', function () {
        var form = q('#jc-class-form');
        form.reset();
        HOD.form.clearErrors(form);
        var t = form.elements.teacher_id;
        t.textContent = '';
        t.appendChild(new Option(data.teachers.length ? 'Choose…' : 'Nobody on the Junior Church team', ''));
        data.teachers.forEach(function (x) { t.appendChild(new Option(name(x.first_name, x.last_name), x.id)); });
        HOD.dialog.open('jc-class-dialog');
    });

    HOD.actions.on('jc.class.save', function (form, event) {
        var v = HOD.form.values(form);
        var errors = {};
        if (!v.service_date) errors.service_date = 'Choose the date.';
        if (!v.teacher_id) errors.teacher_id = 'Choose the teacher.';
        if (!(v.topic || '').trim()) errors.topic = 'Give the topic.';
        HOD.form.clearErrors(form);
        if (Object.keys(errors).length) { HOD.form.setErrors(form, errors); return; }
        var submit = event && event.submitter;
        HOD.busy(submit, true);
        HOD.api(API, { action: 'create_service', data: new FormData(form) }).then(function () {
            HOD.dialog.close('jc-class-dialog');
            HOD.toast('Class logged: the attendance sheet is ready.', { tone: 'success' });
            return load();
        }).catch(fieldError(form)).then(function () { HOD.busy(submit, false); });
    });

    /* ---------------------------------------------------------------- load */

    function load() {
        return HOD.api(API, { method: 'GET', action: 'fetch_dashboard', quiet: true }).then(function (res) {
            data.children = res.children || [];
            data.teachers = res.teachers || [];
            data.parents = res.parents || [];
            data.services = res.services || [];
            data.curriculums = res.curriculums || [];
            q('[data-jc-error]').hidden = true;
            renderChildren();
            renderClasses();
            renderCurriculum();
        }).catch(function () {
            q('[data-jc-error]').hidden = false;
        });
    }

    load();
})();
