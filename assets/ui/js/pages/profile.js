// /assets/ui/js/pages/profile.js
// My Profile (modules/profile/index.php): the member's own details, photo
// and wedding photo (cropped square in the browser), a name-correction
// request, and password + signed-in devices. Everything goes through
// /api/profile_api.php; the shapes of its requests are unchanged.
(function () {
    'use strict';

    var HOD = window.HOD;
    var API = '/api/profile_api.php';
    var PW_MIN = 10;
    var RULES = [
        { id: 'len', label: 'At least ' + PW_MIN + ' characters', test: function (v) { return v.length >= PW_MIN; } },
        { id: 'upper', label: 'An uppercase letter', test: function (v) { return /[A-Z]/.test(v); } },
        { id: 'lower', label: 'A lowercase letter', test: function (v) { return /[a-z]/.test(v); } },
        { id: 'digit', label: 'A number', test: function (v) { return /[0-9]/.test(v); } },
        { id: 'sym', label: 'A symbol (optional, stronger)', test: function (v) { return /[^A-Za-z0-9]/.test(v); }, optional: true }
    ];

    var cfg = {};
    var geo = null;
    var cropper = null;
    var cropTarget = 'profile';

    function $(sel, root) { return (root || document).querySelector(sel); }
    function setField(name, value) {
        document.querySelectorAll('[data-profile="' + name + '"]').forEach(function (el) { el.value = value == null ? '' : value; });
    }
    function label(s) { return String(s || '').replace(/_/g, ' '); }
    function when(raw) {
        if (!raw) return null;
        var d = new Date(String(raw).replace(' ', 'T'));
        if (isNaN(d)) return String(raw);
        return d.toLocaleString('en-GB', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
    }

    /* ---------- profile ---------- */

    function showMarried(isMarried) {
        $('[data-profile-married]').hidden = !isMarried;
        if (!isMarried) setField('wedding_anniversary', '');
    }

    function setPhoto(target, path) {
        if (!path) return;
        var holder = target === 'profile' ? $('[data-profile-avatar]') : $('[data-profile-wedding]');
        holder.innerHTML = '<img class="' + (target === 'profile' ? 'nav-avatar-img' : '') + '" src="' + HOD.esc(path) + '" alt="" decoding="async">';
        if (target === 'profile') {
            document.querySelectorAll('.nav-avatar-img').forEach(function (img) { img.src = path; });
        }
    }

    function loadProfile() {
        HOD.api(API, { action: 'fetch_profile', quiet: true }).then(function (res) {
            var u = res.data || {};
            $('[data-profile-name]').textContent = ((u.first_name || '') + ' ' + (u.last_name || '')).trim() || 'My profile';
            $('[data-profile-badges]').innerHTML =
                (u.spiritual_status ? '<span class="ui-badge ui-badge--primary">' + HOD.esc(label(u.spiritual_status)) + '</span>' : '') +
                (u.attendance_status ? '<span class="ui-badge">' + HOD.esc(label(u.attendance_status)) + '</span>' : '');
            ['first_name', 'last_name', 'email', 'real_email', 'phone', 'dob', 'physical_address', 'wedding_anniversary'].forEach(function (k) { setField(k, u[k]); });
            setField('marital_status', u.marital_status || 'Single');
            showMarried((u.marital_status || 'Single') === 'Married');
            if (u.picture_path) setPhoto('profile', u.picture_path);
            if (u.wedding_picture_path) setPhoto('wedding', u.wedding_picture_path);
            if (u.physical_address && geo) geo.setValue(u.physical_address);
        }).catch(function (err) {
            HOD.toast(err.message || 'Your profile did not load. Reload the page to try again.', 'danger');
        });
    }

    function initAddress() {
        var holder = $('[data-profile-address]');
        HOD.lib('geoapify').then(function () {
            geo = new window.autocomplete.GeocoderAutocomplete(holder, cfg.geoapifyKey, {
                placeholder: 'Search for your address',
                filter: { countrycode: ['ng'] }
            });
            var input = holder.querySelector('input');
            if (input) {
                input.id = 'profile-address-search';
                input.classList.add('ui-input');
                input.setAttribute('autocomplete', 'off');
            }
            geo.on('select', function (place) {
                var p = place && place.properties;
                setField('latitude', p ? p.lat : '');
                setField('longitude', p ? p.lon : '');
                setField('physical_address', p ? p.formatted : '');
            });
            // Enter picks from the list; it must not submit the whole form.
            holder.addEventListener('keydown', function (e) { if (e.key === 'Enter') e.preventDefault(); });
            var current = $('[data-profile="physical_address"]').value;
            if (current) geo.setValue(current);
        }).catch(function () {
            holder.innerHTML = '<p class="ui-meta">The address search is not available right now. Your saved address is kept.</p>';
        });
    }

    function submitForm(form, after) {
        var button = form.querySelector('[type="submit"]') || document.querySelector('[form="' + form.id + '"]');
        HOD.busy(button, true);
        return HOD.api(API, { data: HOD.form.values(form) }).then(function (res) {
            HOD.toast(res.message || 'Saved.', 'success');
            if (after) after(res);
        }).catch(function (err) {
            if (err.field) {
                var errs = {}; errs[err.field] = err.message; HOD.form.setErrors(form, errs);
            }
        }).finally(function () { HOD.busy(button, false); });
    }

    /* ---------- photos ---------- */

    function pickPhoto(input) {
        var file = input.files && input.files[0];
        input.value = '';
        if (!file) return;
        if (!/^image\/(png|jpe?g)$/.test(file.type)) {
            HOD.toast('Choose a JPG or PNG photo.', 'warning');
            return;
        }
        cropTarget = input.getAttribute('data-target') === 'wedding' ? 'wedding' : 'profile';
        var img = $('[data-profile-crop-img]');
        var reader = new FileReader();
        reader.onload = function (e) {
            HOD.lib('cropper').then(function () {
                img.src = e.target.result;
                img.hidden = false;
                HOD.dialog.open('profile-crop-dialog');
                if (cropper) cropper.destroy();
                cropper = new window.Cropper(img, {
                    aspectRatio: 1, viewMode: 1, dragMode: 'move', autoCropArea: 0.85,
                    restore: false, guides: true, center: true, highlight: false,
                    cropBoxMovable: true, cropBoxResizable: true, toggleDragModeOnDblclick: false
                });
            }).catch(function () { HOD.toast('The photo editor did not load. Check your connection and try again.', 'danger'); });
        };
        reader.readAsDataURL(file);
    }

    function savePhoto(button) {
        if (!cropper) return;
        HOD.busy(button, true);
        var busy = $('[data-profile-photo-busy]');
        if (cropTarget === 'profile') busy.hidden = false;
        var data = cropper.getCroppedCanvas({ width: 600, height: 600 }).toDataURL('image/png');
        HOD.api(API, { action: cropTarget === 'profile' ? 'upload_picture' : 'upload_wedding_picture', data: { image_base64: data } })
            .then(function (res) {
                setPhoto(cropTarget, res.path);
                HOD.toast(res.message || 'Photo saved.', 'success');
                HOD.dialog.close('profile-crop-dialog');
            })
            .catch(function () {})
            .finally(function () { HOD.busy(button, false); busy.hidden = true; });
    }

    /* ---------- password ---------- */

    function renderRules() {
        $('[data-profile-pw-rules]').innerHTML = RULES.map(function (r) {
            return '<li class="flex items-center gap-2 text-sm text-ink-subtle" data-rule="' + r.id + '">' +
                HOD.icon('circle-check', 'ui-icon--sm') + '<span>' + HOD.esc(r.label) + '</span></li>';
        }).join('');
    }

    function evaluate() {
        var v = $('#profile-pw-new').value || '';
        var total = 0, met = 0, bonus = 0;
        RULES.forEach(function (r) {
            var ok = r.test(v);
            if (r.optional) { if (ok) bonus++; } else { total++; if (ok) met++; }
            var li = $('[data-profile-pw-rules] [data-rule="' + r.id + '"]');
            li.classList.toggle('text-success', ok);
            li.classList.toggle('text-ink-subtle', !ok);
        });
        var score = 0;
        if (v.length) {
            score = (met / total) * 70 + (v.length >= 14 ? 15 : (v.length >= 12 ? 8 : 0)) + (bonus ? 15 : 0);
            score = Math.min(100, Math.round(score));
        }
        var bar = $('[data-profile-pw-bar]');
        var text = 'Start typing', tone = '';
        if (v.length) {
            if (met < total) { text = 'Too weak'; tone = 'ui-progress--danger'; }
            else if (score < 80) { text = 'Good'; tone = 'ui-progress--warning'; }
            else { text = 'Strong'; tone = 'ui-progress--success'; }
        }
        bar.value = score;
        bar.textContent = score + '%';
        bar.classList.remove('ui-progress--danger', 'ui-progress--warning', 'ui-progress--success');
        if (tone) bar.classList.add(tone);
        $('[data-profile-pw-label]').textContent = text;
        checkMatch();
    }

    function checkMatch() {
        var a = $('#profile-pw-new').value || '';
        var b = $('#profile-pw-confirm');
        var err = $('#profile-pw-confirm-error');
        if (!b.value) { err.textContent = ''; b.removeAttribute('aria-invalid'); return true; }
        var same = a === b.value;
        err.textContent = same ? '' : 'The two passwords do not match yet.';
        if (same) b.removeAttribute('aria-invalid'); else b.setAttribute('aria-invalid', 'true');
        return same;
    }

    function changePassword(form) {
        var cur = $('#profile-pw-current');
        if (!cur.value) { HOD.form.setErrors(form, { current_password: 'Enter the password you use today.' }); return; }
        var met = RULES.every(function (r) { return r.optional || r.test($('#profile-pw-new').value || ''); });
        if (!met) { HOD.form.setErrors(form, { new_password: 'Your new password needs every rule ticked below.' }); return; }
        if (!checkMatch()) { $('#profile-pw-confirm').focus(); return; }
        submitForm(form, function () {
            form.reset();
            form.querySelector('[name="sign_out_others"]').checked = true;
            evaluate();
            $('[data-profile-forced]').hidden = true;
            loadSecurity();
        });
    }

    function loadSecurity() {
        HOD.api(API, { action: 'fetch_security', quiet: true }).then(function (res) {
            var d = res.data || {};
            $('[data-profile-last-login]').textContent = (when(d.last_login_at) || 'This is your first recorded sign-in') + (d.last_login_ip ? ' · ' + d.last_login_ip : '');
            $('[data-profile-pw-changed]').textContent = when(d.password_changed_at) || 'Not recorded yet';
            var sessions = d.sessions || [];
            $('[data-profile-device-count]').textContent = String(sessions.length);
            var list = $('[data-profile-devices]');
            list.innerHTML = sessions.length ? sessions.map(function (s) {
                return '<li class="ui-list__item">' +
                    '<span class="ui-list__icon">' + HOD.icon('smartphone') + '</span>' +
                    '<span class="ui-list__main"><span class="ui-list__title">' + HOD.esc(s.device) + '</span>' +
                    '<span class="ui-list__meta">' + HOD.esc(s.ip_address || 'Unknown network') + ' · active ' + HOD.esc(when(s.last_seen_at) || '—') + '</span></span>' +
                    (s.is_current ? '<span class="ui-list__end"><span class="ui-badge ui-badge--success">This device</span></span>' : '') +
                '</li>';
            }).join('') : '<li class="ui-list__item"><span class="ui-meta">No other signed-in devices on record.</span></li>';
            if (d.must_change_password) $('[data-profile-forced]').hidden = false;
        }).catch(function () {});
    }

    function signOutOthers(button) {
        HOD.confirm({
            title: 'Sign out your other devices?',
            message: 'Every other phone or computer signed in as you is signed out. This one stays signed in.',
            confirmLabel: 'Sign them out',
            tone: 'danger'
        }).then(function (yes) {
            if (!yes) return;
            HOD.busy(button, true);
            HOD.api(API, { action: 'sign_out_other_devices' }).then(function (res) {
                HOD.toast(res.message || 'Your other devices are signed out.', 'success');
                loadSecurity();
            }).catch(function () {}).finally(function () { HOD.busy(button, false); });
        });
    }

    HOD.ready(function () {
        if (!$('[data-profile-name]')) return;
        cfg = HOD.data('profile-config') || {};

        HOD.actions.on('profile.save', function (form) { submitForm(form); });
        HOD.actions.on('profile.name-change', function (form) {
            submitForm(form, function () { form.reset(); HOD.dialog.close('profile-name-dialog'); });
        });
        HOD.actions.on('profile.password', changePassword);
        HOD.actions.on('profile.marital', function (el) { showMarried(el.value === 'Married'); });
        HOD.actions.on('profile.pick-photo', pickPhoto);
        HOD.actions.on('profile.save-photo', savePhoto);
        HOD.actions.on('profile.sign-out-others', signOutOthers);
        HOD.actions.on('profile.toggle-pw', function (btn) {
            var input = document.getElementById(btn.getAttribute('data-target'));
            var show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            btn.setAttribute('aria-pressed', show ? 'true' : 'false');
            btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
            btn.querySelector('use').setAttribute('href', btn.querySelector('use').getAttribute('href').replace(/#.*$/, show ? '#eye-off' : '#eye'));
        });

        document.getElementById('profile-crop-dialog').addEventListener('ui:dialog-close', function () {
            if (cropper) { cropper.destroy(); cropper = null; }
            $('[data-profile-crop-img]').hidden = true;
        });
        $('#profile-pw-new').addEventListener('input', evaluate);
        $('#profile-pw-confirm').addEventListener('input', checkMatch);

        renderRules();
        evaluate();
        initAddress();
        loadProfile();
        loadSecurity();

        if (window.location.hash === '#security') {
            document.getElementById('security').scrollIntoView({ block: 'start' });
        }
    });
})();
