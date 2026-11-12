// /assets/ui/js/pages/parent-portal.js
// Junior Church parent portal (parent_portal.php): the phone + child's date
// of birth gate, each child's Sundays (attendance, lesson, activity sheet,
// video), the photo update (cropped with the vendored Cropper) and sign-out.
// Every call goes to /api/parent_portal_api.php as before.
(function () {
    'use strict';

    var HOD = window.HOD;
    var API = '/api/parent_portal_api.php';
    var cfg = HOD.data('pp-config') || {};
    var esc = HOD.esc;
    var data = {};
    var childId = null;
    var cropper = null;
    var photo = '';

    function $(id) { return document.getElementById(id); }
    function safeUrl(u) { u = String(u || '').trim(); return /^(https?:\/\/|\/(?!\/))/i.test(u) ? u : ''; }
    function youtubeId(url) {
        var m = String(url || '').match(/(?:youtube\.com\/(?:watch\?v=|embed\/|v\/|shorts\/)|youtu\.be\/)([a-zA-Z0-9_-]{11})/);
        return m ? m[1] : null;
    }
    function fileType(path) {
        var ext = String(path || '').split('.').pop().toLowerCase();
        if (ext === 'pdf') return 'pdf';
        if (['png', 'jpg', 'jpeg', 'gif', 'webp'].indexOf(ext) !== -1) return 'image';
        return 'file';
    }
    function fail(err, fallback) {
        HOD.toast(err && err.code === 'NETWORK' ? 'Connection failed. Please try again.' : ((err && err.message) || fallback), 'danger');
    }

    /* ----- Gate ---------------------------------------------------------- */

    function showApp(on) {
        $('pp-gate').hidden = on;
        $('pp-app').hidden = !on;
    }

    HOD.actions.on('pp.gate', function (form, event) {
        var btn = (event && event.submitter) || form.querySelector('[type="submit"]');
        HOD.busy(btn, true);
        HOD.api(API, { data: new FormData(form), quiet: true }).then(function (res) {
            HOD.toast(res.message || 'Welcome.', 'success');
            showApp(true);
            load();
        }).catch(function (err) { fail(err, 'Could not verify. Please check and try again.'); })
            .then(function () { HOD.busy(btn, false); });
    });

    HOD.actions.on('pp.logout', function (btn) {
        HOD.busy(btn, true);
        HOD.api(API, { action: 'logout', quiet: true, raw: true }).then(function () {
            HOD.toast('Signed out securely.', 'info');
            data = {};
            childId = null;
            showApp(false);
        }).catch(function (err) { fail(err, 'Could not sign out.'); }).then(function () { HOD.busy(btn, false); });
    });

    /* ----- Dashboard ----------------------------------------------------- */

    function load() {
        $('pp-timeline').innerHTML = '<li class="ui-list__item"><span class="ui-skeleton ui-skeleton--text w-1/2"></span></li>';
        return HOD.api(API, { method: 'GET', action: 'fetch_dashboard', quiet: true, raw: true }).then(function (res) {
            if (res.status === 'auth_required') { showApp(false); return; }
            if (res.status !== 'success') { fail({ message: res.message }, 'Could not load the portal.'); return; }
            data = res;
            var kids = res.children || [];
            $('pp-empty').hidden = kids.length > 0;
            $('pp-children-wrap').hidden = !kids.length;
            $('pp-stats').hidden = !kids.length;
            $('pp-timeline-card').hidden = !kids.length;
            if (!kids.length) return;
            $('pp-children').innerHTML = kids.map(function (c) {
                var pic = safeUrl(c.picture_path);
                var age = c.dob ? Math.floor((Date.now() - new Date(c.dob).getTime()) / 31557600000) : null;
                return '<button type="button" class="ui-chip shrink-0 gap-2 py-2" data-action="pp.child" data-id="' + esc(c.id) + '" aria-pressed="false">'
                    + '<span class="ui-avatar ui-avatar--sm ui-avatar--c2">' + (pic ? '<img src="' + esc(pic) + '" alt="">' : '<span aria-hidden="true">' + esc(String(c.child_first_name || '?').charAt(0)) + '</span>') + '</span>'
                    + '<span class="text-left"><span class="block font-semibold">' + esc(c.child_first_name + ' ' + (c.child_last_name || '')) + '</span>'
                    + (age !== null && !isNaN(age) ? '<span class="block text-xs opacity-70">' + age + ' yrs</span>' : '') + '</span></button>';
            }).join('');
            selectChild(childId && kids.some(function (k) { return String(k.id) === String(childId); }) ? childId : kids[0].id);
        }).catch(function (err) { fail(err, 'Could not load the portal.'); });
    }

    function resourceButtons(t) {
        var out = '';
        var file = safeUrl(t.service_file_path);
        if (file) {
            out += '<a class="ui-btn ui-btn--secondary ui-btn--sm" href="' + esc(file) + '" download>' + HOD.icon('download') + '<span>Activity sheet</span></a>';
            var type = fileType(file);
            if (type === 'pdf' || type === 'image') {
                out += '<button type="button" class="ui-btn ui-btn--ghost ui-btn--sm" data-action="pp.view" data-type="' + type + '" data-url="' + esc(file) + '">' + HOD.icon('eye') + '<span>View</span></button>';
            }
        }
        var link = safeUrl(t.media_link);
        if (link) {
            out += youtubeId(link)
                ? '<button type="button" class="ui-btn ui-btn--primary ui-btn--sm" data-action="pp.view" data-type="youtube" data-url="' + esc(link) + '">' + HOD.icon('video') + '<span>Watch video</span></button>'
                : '<a class="ui-btn ui-btn--secondary ui-btn--sm" href="' + esc(link) + '" target="_blank" rel="noopener">' + HOD.icon('external-link') + '<span>Open link</span></a>';
        }
        return out;
    }

    function selectChild(id) {
        childId = id;
        Array.prototype.forEach.call($('pp-children').children, function (b) {
            b.setAttribute('aria-pressed', String(b.getAttribute('data-id')) === String(id) ? 'true' : 'false');
        });
        var child = (data.children || []).filter(function (c) { return String(c.id) === String(id); })[0];
        var timeline = (data.timeline || {})[id] || [];
        if (child) $('pp-name').textContent = child.child_first_name + '’s Sundays';
        var present = timeline.filter(function (t) { return t.status === 'Present'; }).length;
        $('pp-total').textContent = timeline.length;
        $('pp-present').textContent = present;
        $('pp-absent').textContent = timeline.length - present;
        if (!timeline.length) {
            $('pp-timeline').innerHTML = '<li><div class="ui-empty ui-empty--compact"><p class="ui-empty__title">No Sundays recorded yet</p>'
                + '<p class="ui-empty__text">They appear after your child’s first Sunday.</p></div></li>';
            return;
        }
        $('pp-timeline').innerHTML = timeline.map(function (t) {
            var d = new Date(String(t.service_date) + 'T00:00:00');
            var ok = !isNaN(d.getTime());
            var present = t.status === 'Present';
            var yt = youtubeId(t.media_link);
            return '<li class="ui-list__item items-start gap-4">'
                + '<span class="grid h-14 w-14 shrink-0 place-items-center rounded-ui bg-surface-sunken text-center leading-tight">'
                + '<span class="block text-xs uppercase text-ink-subtle">' + (ok ? esc(d.toLocaleDateString('en-GB', { weekday: 'short' })) : '') + '</span>'
                + '<span class="block font-display text-xl font-bold text-ink">' + (ok ? d.getDate() : '') + '</span></span>'
                + '<div class="min-w-0 flex-1 space-y-2">'
                + '<div class="flex flex-wrap items-start justify-between gap-2"><div class="min-w-0">'
                + '<h3 class="font-semibold text-ink">' + esc(t.topic || 'Sunday service') + '</h3>'
                + '<p class="text-xs text-ink-subtle">' + (ok ? esc(d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' })) : esc(t.service_date)) + '</p></div>'
                + (present ? '<span class="ui-badge ui-badge--success">Present</span>' : '<span class="ui-badge ui-badge--outline">Absent</span>') + '</div>'
                + (t.master_pages_covered ? '<p class="text-sm text-ink-muted">Curriculum: ' + esc(t.master_pages_covered) + '</p>' : '')
                + (yt ? '<button type="button" class="block overflow-hidden rounded-ui" data-action="pp.view" data-type="youtube" data-url="' + esc(t.media_link) + '" aria-label="Watch the video for ' + esc(t.topic || 'this Sunday') + '">'
                    + '<img class="aspect-video w-56 object-cover" src="https://img.youtube.com/vi/' + esc(yt) + '/mqdefault.jpg" alt="" loading="lazy"></button>' : '')
                + '<div class="flex flex-wrap gap-2">' + resourceButtons(t) + '</div>'
                + '</div></li>';
        }).join('');
    }

    HOD.actions.on('pp.child', function (btn) { selectChild(btn.getAttribute('data-id')); });

    /* ----- Viewer -------------------------------------------------------- */

    HOD.actions.on('pp.view', function (btn) {
        var type = btn.getAttribute('data-type');
        var url = btn.getAttribute('data-url');
        var body = $('pp-viewer-body');
        if (type === 'youtube') {
            var id = youtubeId(url);
            if (!id) { HOD.toast('That video link does not work.', 'danger'); return; }
            body.innerHTML = '<iframe class="aspect-video w-full rounded-ui" src="https://www.youtube-nocookie.com/embed/' + esc(id) + '?autoplay=1&rel=0&modestbranding=1" title="Lesson video" allow="autoplay; encrypted-media; fullscreen" allowfullscreen></iframe>';
        } else if (type === 'image') {
            body.innerHTML = '<img class="max-h-[75vh] max-w-full rounded-ui object-contain" src="' + esc(url) + '" alt="Activity sheet">';
        } else {
            body.innerHTML = '<iframe class="h-[75vh] w-full rounded-ui bg-white" src="' + esc(url) + '" title="Activity sheet"></iframe>';
        }
        HOD.dialog.open('pp-viewer', btn);
    });
    document.addEventListener('ui:dialog-close', function (e) {
        if (e.target && e.target.id === 'pp-viewer') $('pp-viewer-body').innerHTML = '';
        if (e.target && e.target.id === 'pp-crop-dialog' && cropper) { cropper.destroy(); cropper = null; }
    });

    /* ----- Photo --------------------------------------------------------- */

    function preview(src) {
        var img = $('pp-photo-preview');
        img.hidden = !src;
        $('pp-photo-empty').hidden = !!src;
        if (src) img.src = src;
    }

    HOD.actions.on('pp.photo', function (btn) {
        if (!childId) { HOD.toast('Choose a child first.', 'warning'); return; }
        photo = '';
        $('pp-photo-save').hidden = true;
        $('pp-photo-file').value = '';
        var child = (data.children || []).filter(function (c) { return String(c.id) === String(childId); })[0];
        preview(child ? safeUrl(child.picture_path) : '');
        HOD.dialog.open('pp-photo-dialog', btn);
    });

    HOD.actions.on('pp.photo-file', function (input) {
        var file = input.files && input.files[0];
        if (!file) return;
        var reader = new FileReader();
        reader.onload = function (e) {
            var img = $('pp-crop-img');
            img.src = e.target.result;
            HOD.dialog.open('pp-crop-dialog');
            HOD.lib('cropper').then(function () {
                if (cropper) cropper.destroy();
                requestAnimationFrame(function () {
                    cropper = new window.Cropper(img, { aspectRatio: 1, viewMode: 1, dragMode: 'move', autoCropArea: 0.8, guides: false, toggleDragModeOnDblclick: false });
                });
            }).catch(function () { HOD.toast('The photo editor did not load. Try again.', 'danger'); });
        };
        reader.readAsDataURL(file);
        input.value = '';
    });

    HOD.actions.on('pp.crop-apply', function () {
        if (!cropper) return;
        photo = cropper.getCroppedCanvas({ width: 400, height: 400 }).toDataURL('image/png');
        preview(photo);
        $('pp-photo-save').hidden = false;
        HOD.dialog.close('pp-crop-dialog');
    });

    HOD.actions.on('pp.photo-save', function (btn) {
        if (!photo || !childId) return;
        HOD.busy(btn, true);
        var fd = new FormData();
        fd.append('action', 'update_child_pic');
        fd.append('child_id', childId);
        fd.append('image_base64', photo);
        HOD.api(API, { data: fd, quiet: true }).then(function (res) {
            HOD.toast(res.message || 'Photo saved.', 'success');
            HOD.dialog.close('pp-photo-dialog');
            load();
        }).catch(function (err) { fail(err, 'Upload failed. Please try again.'); }).then(function () { HOD.busy(btn, false); });
    });

    if (cfg.authenticated) {
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', load);
        else load();
    }
})();
