// /assets/ui/js/pages/checkin.js
// Check-in at the door (checkin.php). One event (?event=TOKEN) or the
// standing service QR (?service=today: today's service, or a countdown to the
// next one). Phone -> confirm the name -> present -> a blessing to keep.
// A newcomer at a check-in-only event (a service) is checked in as a guest,
// never registered, and offered the Connect card: their name and phone go to
// sessionStorage on this device (never into the address) and connect.js
// prefills the card from it. Data: /api/checkin_api.php.
(function () {
    'use strict';

    var API = '/api/checkin_api.php';
    var PREFILL_KEY = 'hod.connect.prefill';
    var cfg = HOD.data('checkin-config') || {};
    var esc = HOD.esc;

    var state = { event: null, person: null, phone: '', helping: false, edited: false, blessing: null, firstName: '', prefill: null };
    var tick = null;

    function $(s, r) { return (r || document).querySelector(s); }
    function $$(s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); }

    function call(action, data, opts) {
        var payload = Object.assign({ _csrf: cfg.csrf }, data || {});
        if (cfg.service) payload.service = 'today'; else payload.token = cfg.token;
        return HOD.api(API, Object.assign({ action: action, data: payload, quiet: true }, opts || {}));
    }

    function failed(err) {
        HOD.toast((err && err.message) || 'Something went wrong. Please try again.', 'danger');
    }

    function show(step) {
        $$('[data-ci-step]').forEach(function (el) { el.hidden = el.getAttribute('data-ci-step') !== step; });
        var focus = { phone: '#ci-phone', edit: '#ci-edit-name', guest: '#ci-guest-name', register: '#ci-reg-name' }[step];
        if (focus) setTimeout(function () { var f = $(focus); if (f) f.focus(); }, 30);
        else {
            var head = $('[data-ci-step="' + step + '"] h2, [data-ci-step="' + step + '"] h1');
            if (head && step !== 'loading') { head.setAttribute('tabindex', '-1'); head.focus({ preventScroll: true }); }
        }
    }

    function setError(id, message) {
        var el = $('#' + id);
        if (!el) return;
        var slot = $('#' + id + '-error');
        if (message) el.setAttribute('aria-invalid', 'true'); else el.removeAttribute('aria-invalid');
        if (slot) slot.textContent = message || '';
        if (message) el.focus();
    }

    /* ---------- The event, or the countdown ---------- */

    function header(e) {
        var banner = $('#ci-banner');
        if (e.banner_image_url && /^\/(?!\/)|^https:\/\//.test(e.banner_image_url)) {
            banner.src = e.banner_image_url;
            banner.hidden = false;
        }
        var day = e.day_info || {};
        $('#ci-day').textContent = day.day_label && day.in_window ? day.day_label : '';
        $('#ci-day').hidden = !$('#ci-day').textContent;
        $('#ci-title').textContent = e.title || 'Check in';
        $('#ci-meta').textContent = [e.nice_date, e.nice_time, e.location].filter(Boolean).join(' · ');
        $('#ci-head').hidden = false;
        $('#ci-bless-sign').textContent = e.title || 'Household of David, Lekki';
        document.title = (e.title || 'Check in') + ' · Check in';
    }

    function countdown(next) {
        if (!next) {
            $('#ci-closed-title').textContent = 'No service is on today';
            $('#ci-closed-text').textContent = 'Check back on Sunday or Thursday.';
            show('closed');
            return;
        }
        $('#ci-next-title').textContent = next.title;
        $('#ci-next-when').textContent = [next.nice_date, next.nice_time, next.location].filter(Boolean).join(' · ');
        // Counted from the server's figure, so a wrong phone clock can't skew it.
        var target = Date.now() + Number(next.starts_in || 0) * 1000;
        var pad = function (n) { return String(n).padStart(2, '0'); };
        function draw() {
            var left = Math.max(0, Math.round((target - Date.now()) / 1000));
            $('[data-ci-unit="d"]').textContent = String(Math.floor(left / 86400));
            $('[data-ci-unit="h"]').textContent = pad(Math.floor(left % 86400 / 3600));
            $('[data-ci-unit="m"]').textContent = pad(Math.floor(left % 3600 / 60));
            $('[data-ci-unit="s"]').textContent = pad(left % 60);
            // The service's day has come: open check-in.
            if (left === 0) { clearInterval(tick); setTimeout(load, 1500); }
        }
        draw();
        clearInterval(tick);
        tick = setInterval(draw, 1000);
        show('countdown');
    }

    function load() {
        show('loading');
        if (!cfg.service && !cfg.token) {
            $('#ci-closed-title').textContent = 'Scan the QR code again';
            $('#ci-closed-text').textContent = 'This link is missing its event.';
            show('closed');
            return;
        }
        call('get_event').then(function (res) {
            if (!res.event) { countdown(res.next); return; }
            state.event = res.event;
            header(res.event);
            var day = res.event.day_info || {};
            if (!day.in_window) {
                $('#ci-closed-title').textContent = res.event.not_yet ? 'Check-in opens on the day' : 'Check-in has closed';
                $('#ci-closed-text').textContent = res.event.not_yet ? (res.event.nice_date || '') : 'Thank you for coming.';
                show('closed');
                return;
            }
            restart();
        }).catch(function (err) {
            $('#ci-closed-title').textContent = 'This check-in link isn’t working';
            $('#ci-closed-text').textContent = err.message || 'Scan the QR code again.';
            show('closed');
        });
    }

    /* ---------- Phone ---------- */

    function restart() {
        state.person = null;
        state.edited = false;
        state.prefill = null;
        var form = $('[data-ci-step="phone"]');
        form.reset();
        setError('ci-phone', '');
        $('#ci-helper-banner').hidden = !state.helping;
        $('#ci-helper-on').hidden = state.helping;
        $('#ci-phone-title').textContent = state.helping ? 'Who are you checking in?' : 'Welcome!';
        $('#ci-phone-lead').textContent = state.helping ? 'Enter their phone number.' : 'Enter your phone number to check in.';
        show('phone');
    }
    HOD.actions.on('ci.restart', restart);
    HOD.actions.on('ci.helper-on', function () { state.helping = true; restart(); });
    HOD.actions.on('ci.helper-off', function () { state.helping = false; restart(); });

    function digits(v) { return String(v || '').replace(/\D/g, ''); }

    HOD.actions.on('ci.lookup', function (form) {
        var phone = $('#ci-phone').value.trim();
        var n = digits(phone).length;
        if (!phone) { setError('ci-phone', 'Enter a phone number.'); return; }
        if (n < 10 || n > 14) { setError('ci-phone', 'Check the number: it should be like 0803 123 4567.'); return; }
        setError('ci-phone', '');
        state.phone = phone;
        var btn = $('#ci-find');
        HOD.busy(btn, true);
        call('lookup', { phone: phone }).then(function (res) {
            if (res.already_checked_in) { welcomeBack(res.person ? res.person.first_name : ''); return; }
            if (res.found) {
                state.person = res.person;
                $('#ci-confirm-title').textContent = state.helping ? 'Found them' : 'Welcome back!';
                $('#ci-confirm-lead').textContent = state.helping ? 'Is this the right person?' : 'Is this you?';
                $('#ci-confirm-name').textContent = res.person.name;
                show('confirm');
                return;
            }
            $$('[data-ci-phone-echo]').forEach(function (el) { el.textContent = phone; });
            if (res.mode === 'checkin') {
                $('[data-ci-step="guest"]').reset();
                show('guest');
            } else {
                $('[data-ci-step="register"]').reset();
                $('#ci-reg-phone').value = phone;
                show('register');
            }
        }).catch(function (err) {
            if (err && err.response && err.response.message) setError('ci-phone', err.response.message);
            else failed(err);
        }).finally(function () { HOD.busy(btn, false); });
    });

    /* ---------- Present ---------- */

    function present(btn) {
        var p = state.person;
        HOD.busy(btn, true);
        call('mark_present', {
            source: p.source, id: p.id, name: p.name, phone: state.phone,
            name_edited: state.edited ? 1 : 0, is_volunteer: state.helping ? 1 : 0
        }).then(function (res) { done(res, false); }).catch(failed).finally(function () { HOD.busy(btn, false); });
    }
    HOD.actions.on('ci.present', function (btn) { present(btn); });

    HOD.actions.on('ci.edit', function () {
        $('#ci-edit-name').value = state.person ? state.person.name : '';
        setError('ci-edit-name', '');
        show('edit');
    });
    HOD.actions.on('ci.back-confirm', function () { show('confirm'); });
    HOD.actions.on('ci.save-name', function () {
        var name = $('#ci-edit-name').value.trim().replace(/\s+/g, ' ');
        if (!name) { setError('ci-edit-name', 'Type the full name.'); return; }
        var btn = $('#ci-save-name');
        HOD.busy(btn, true);
        call('edit_name', { source: state.person.source, id: state.person.id, name: name }).then(function (res) {
            state.person.name = res.name || name;
            state.edited = true;
            HOD.busy(btn, false);
            present(btn);
        }).catch(function (err) { HOD.busy(btn, false); failed(err); });
    });

    HOD.actions.on('ci.guest', function () {
        var name = $('#ci-guest-name').value.trim().replace(/\s+/g, ' ');
        if (!name) { setError('ci-guest-name', 'Type your name.'); return; }
        var btn = $('#ci-guest-go');
        HOD.busy(btn, true);
        call('guest_present', { name: name, phone: state.phone, is_volunteer: state.helping ? 1 : 0 })
            .then(function (res) { done(res, true); })
            .catch(function (err) {
                var r = err && err.response;
                if (r && r.field === 'name') setError('ci-guest-name', r.message); else failed(err);
            })
            .finally(function () { HOD.busy(btn, false); });
    });

    HOD.actions.on('ci.register', function () {
        var name = $('#ci-reg-name').value.trim().replace(/\s+/g, ' ');
        var phone = $('#ci-reg-phone').value.trim();
        if (!name) { setError('ci-reg-name', 'Type the full name.'); return; }
        if (digits(phone).length < 10) { setError('ci-reg-phone', 'Check the phone number.'); return; }
        var btn = $('#ci-reg-go');
        HOD.busy(btn, true);
        call('register_and_present', { name: name, phone: phone, source: $('#ci-reg-source').value, is_volunteer: state.helping ? 1 : 0 })
            .then(function (res) { done(res, false); }).catch(failed).finally(function () { HOD.busy(btn, false); });
    });

    /* ---------- Done: the blessing ---------- */

    var FALLBACK = {
        text: 'The LORD bless thee, and keep thee: The LORD make his face shine upon thee, and be gracious unto thee: The LORD lift up his countenance upon thee, and give thee peace.',
        ref: 'Numbers 6:24-26',
        prayer: '{name}, may the LORD watch over you, shine His favour upon you, and fill you with His peace.'
    };

    function showBlessing(blessing, firstName) {
        var b = blessing || FALLBACK;
        var name = firstName || 'friend';
        state.blessing = b;
        state.firstName = firstName || '';
        $('#ci-bless-dear').textContent = 'Dear ' + name + ',';
        $('#ci-bless-verse').textContent = b.text;
        $('#ci-bless-ref').textContent = b.ref;
        $('#ci-bless-prayer').textContent = String(b.prayer || '').replace(/\{name\}/g, name);
    }

    function done(res, asGuest) {
        $('#ci-done-title').textContent = res.already ? 'You’re already checked in' : 'You’re checked in';
        $('#ci-done-text').textContent = res.message || '';
        showBlessing(res.blessing, res.first_name);
        state.prefill = asGuest && res.guest && res.prefill ? res.prefill : null;
        $('#ci-welcome').hidden = !state.prefill;
        $('#ci-helper-next').hidden = !state.helping;
        show('done');
    }

    function welcomeBack(firstName) {
        call('get_my_blessing', { phone: state.phone }).then(function (res) {
            done({ already: true, message: 'Welcome back! Here is your word for today.', blessing: res.blessing, first_name: res.first_name || firstName }, false);
        }).catch(function () {
            done({ already: true, message: 'Welcome back!', blessing: null, first_name: firstName }, false);
        });
    }

    // The welcome (Connect) card, prefilled on this device only.
    HOD.actions.on('ci.connect', function () {
        if (state.prefill) {
            try {
                sessionStorage.setItem(PREFILL_KEY, JSON.stringify(Object.assign({ at: Date.now() }, state.prefill)));
            } catch (e) { /* private mode: the card opens empty */ }
        }
        window.location.href = cfg.connect || '/connect';
    });

    function blessingText() {
        var b = state.blessing || FALLBACK;
        var name = state.firstName || 'friend';
        return ($('#ci-title').textContent || '') + '\n\nDear ' + name + ',\n\n"' + b.text + '" ' + b.ref +
            '\n\nA prayer for you:\n' + String(b.prayer || '').replace(/\{name\}/g, name) +
            '\n\nWith love and blessing,\n' + $('#ci-bless-sign').textContent;
    }

    HOD.actions.on('ci.copy', function () {
        var text = blessingText();
        function fallback() {
            var ta = document.createElement('textarea');
            ta.value = text;
            ta.setAttribute('readonly', '');
            ta.className = 'sr-only';
            document.body.appendChild(ta);
            ta.select();
            try { document.execCommand('copy'); HOD.toast('Copied. Keep it close.', 'success'); } catch (e) { HOD.toast('Select the text and copy it.', 'warning'); }
            ta.remove();
        }
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function () { HOD.toast('Copied. Keep it close.', 'success'); }, fallback);
        } else fallback();
    });

    HOD.actions.on('ci.save', function (btn) {
        var card = $('#ci-blessing');
        HOD.busy(btn, true);
        HOD.lib('html2canvas').then(function () {
            return window.html2canvas(card, { scale: 2, backgroundColor: '#0A0E17', logging: false });
        }).then(function (canvas) {
            var name = 'blessing-' + ((state.firstName || 'for-you').toLowerCase().replace(/[^a-z0-9]+/g, '-')) + '.png';
            return new Promise(function (resolve) { canvas.toBlob(resolve, 'image/png'); }).then(function (blob) {
                var file = new File([blob], name, { type: 'image/png' });
                var ios = /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
                // iPhones save pictures through the share sheet; elsewhere, download.
                if (ios && navigator.canShare && navigator.canShare({ files: [file] })) {
                    return navigator.share({ files: [file], title: 'A word just for you' }).catch(function (e) {
                        if (e && e.name !== 'AbortError') throw e;
                    });
                }
                var url = URL.createObjectURL(blob);
                var a = document.createElement('a');
                a.href = url;
                a.download = name;
                document.body.appendChild(a);
                a.click();
                a.remove();
                setTimeout(function () { URL.revokeObjectURL(url); }, 2000);
                HOD.toast('Saved. Your picture is ready.', 'success');
            });
        }).catch(function () {
            HOD.toast('Couldn’t make the picture. Try Copy instead.', 'warning');
        }).finally(function () { HOD.busy(btn, false); });
    });

    load();
})();
