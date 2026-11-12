// /assets/ui/js/pages/register.js
// Event registration (register.php?token=…): loads the event, its days and
// its own questions from /api/registration_api.php (fetch_form), fills a
// signed-in member's details (with "Registering someone else?"), checks the
// answers, and posts the registration (submit_registration) as before.
(function () {
    'use strict';

    var HOD = window.HOD;
    var API = '/api/registration_api.php';
    var cfg = HOD.data('reg-config') || {};
    var esc = HOD.esc;
    var event = null;
    var profile = null;
    var countdown = null;
    var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    function $(id) { return document.getElementById(id); }
    function safeUrl(u) { u = String(u || '').trim(); return /^(https?:\/\/|\/(?!\/))/i.test(u) ? u : ''; }
    function youtubeId(url) {
        var m = String(url || '').match(/^.*(youtu\.be\/|v\/|u\/\w\/|embed\/|watch\?v=|&v=)([^#&?]*).*/);
        return m && m[2].length === 11 ? m[2] : null;
    }

    function showError(message, login) {
        $('reg-loading').hidden = true;
        $('reg-main').hidden = true;
        $('reg-error-text').textContent = message;
        if (login) {
            var link = $('reg-error-link');
            link.setAttribute('href', cfg.login || '/auth/login.php');
            var spans = link.querySelectorAll('span');
            if (spans.length) spans[spans.length - 1].textContent = 'Go to login';
            link.classList.remove('ui-btn--secondary');
            link.classList.add('ui-btn--brand');
        }
        $('reg-error').hidden = false;
    }

    function showIssues(list, title) {
        $('reg-issues-title').textContent = title || 'Please fix these';
        $('reg-issue-list').innerHTML = (Array.isArray(list) ? list : [list]).map(function (m) { return '<li>' + esc(m) + '</li>'; }).join('');
        HOD.dialog.open('reg-issues');
    }

    /* ----- Countdown ----------------------------------------------------- */

    function startCountdown(dateString) {
        clearInterval(countdown);
        var target = new Date(String(dateString).replace(/-/g, '/')).getTime();
        if (isNaN(target)) return;
        var box = $('reg-countdown');
        box.hidden = false;
        var cell = function (id, label) {
            return '<div class="min-w-[4rem] rounded-ui bg-surface-sunken px-3 py-2"><span class="block text-2xl font-bold tabular-nums text-ink" id="' + id + '">00</span>'
                + '<span class="block text-xs uppercase tracking-wide text-ink-subtle">' + label + '</span></div>';
        };
        box.innerHTML = cell('cd-d', 'Days') + cell('cd-h', 'Hrs') + cell('cd-m', 'Min') + cell('cd-s', 'Sec');
        var tick = function () {
            var left = target - Date.now();
            if (left < 0) {
                clearInterval(countdown);
                box.innerHTML = '<span class="ui-badge ui-badge--danger">Event is live</span>';
                return;
            }
            var pad = function (n) { return String(n).padStart(2, '0'); };
            $('cd-d').textContent = pad(Math.floor(left / 86400000));
            $('cd-h').textContent = pad(Math.floor((left % 86400000) / 3600000));
            $('cd-m').textContent = pad(Math.floor((left % 3600000) / 60000));
            $('cd-s').textContent = pad(Math.floor((left % 60000) / 1000));
        };
        tick();
        countdown = setInterval(tick, 1000);
    }

    /* ----- Event questions ------------------------------------------------- */

    function field(f) {
        var required = Number(f.is_required) === 1;
        var name = 'custom_fields[' + f.id + ']';
        var id = 'reg-f-' + f.id;
        var ph = f.placeholder ? ' placeholder="' + esc(f.placeholder) + '"' : '';
        var req = required ? ' required' : '';
        var opts = f.field_options || [];
        var inner;
        if (f.field_type === 'textarea') {
            inner = '<textarea class="ui-textarea" id="' + id + '" name="' + name + '" rows="3"' + req + ph + '></textarea>';
        } else if (f.field_type === 'select') {
            inner = '<select class="ui-select" id="' + id + '" name="' + name + '"' + req + '><option value="">Select…</option>'
                + opts.map(function (o) { return '<option value="' + esc(o) + '">' + esc(o) + '</option>'; }).join('') + '</select>';
        } else if (f.field_type === 'radio' || f.field_type === 'checkbox') {
            var multi = f.field_type === 'checkbox';
            inner = '<div class="flex flex-wrap gap-3" role="group" aria-labelledby="' + id + '-label">' + opts.map(function (o) {
                return '<label class="ui-check"><input type="' + (multi ? 'checkbox' : 'radio') + '" name="' + name + (multi ? '[]' : '') + '" value="' + esc(o) + '"' + (multi ? '' : req) + '><span>' + esc(o) + '</span></label>';
            }).join('') + '</div>';
        } else {
            var t = { email: 'email', number: 'number', date: 'date' }[f.field_type] || 'text';
            inner = '<input class="ui-input" type="' + t + '" id="' + id + '" name="' + name + '"' + req + ph + '>';
        }
        var group = f.field_type === 'radio' || f.field_type === 'checkbox';
        return '<div class="ui-field" data-reg-field data-type="' + esc(f.field_type) + '" data-label="' + esc(f.field_label) + '"' + (required ? ' data-required="1"' : '') + '>'
            + (group ? '<p class="ui-label" id="' + id + '-label">' : '<label class="ui-label" for="' + id + '">') + esc(f.field_label)
            + (required ? '<span class="ui-label__req" aria-hidden="true">*</span>' : '') + (group ? '</p>' : '</label>')
            + inner + '</div>';
    }

    function renumber() {
        var n = 0;
        Array.prototype.forEach.call(document.querySelectorAll('[data-reg-section]'), function (s) {
            if (s.hidden) return;
            n++;
            var badge = s.querySelector('[data-reg-step]');
            if (badge) badge.textContent = n;
        });
    }

    function fillProfile() {
        if (!profile) return;
        $('reg-name').value = ((profile.first_name || '') + ' ' + (profile.last_name || '')).trim();
        if (profile.phone) $('reg-phone').value = profile.phone;
        var email = profile.real_email || profile.email;
        if (email) $('reg-email').value = email;
        if (profile.gender) $('reg-gender').value = profile.gender;
    }

    HOD.actions.on('reg.proxy', function (box) {
        if (box.checked) {
            ['reg-name', 'reg-phone', 'reg-email', 'reg-gender'].forEach(function (id) { $(id).value = ''; });
            $('reg-member-note').hidden = true;
            $('reg-you-title').textContent = 'Guest’s information';
        } else {
            fillProfile();
            $('reg-member-note').hidden = false;
            $('reg-you-title').textContent = 'Your information';
        }
    });

    function render(res) {
        event = res.event_info;
        var days = res.event_days || [];
        var questions = res.custom_fields || [];
        if (Number(event.allow_visitors) === 0 && !cfg.loggedIn) {
            showError('This is a closed church event. Please log in to your member account to register.', true);
            return;
        }
        $('reg-title').textContent = event.title;
        if (event.event_date) startCountdown(event.event_date);
        $('reg-category').textContent = String(event.event_category || 'Event').replace(/_/g, ' ');
        var start = new Date(String(event.event_date).replace(' ', 'T'));
        var label = isNaN(start) ? String(event.event_date || '') : start.toLocaleString('en-GB', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit' });
        if (event.end_date && days.length > 1) {
            var end = new Date(event.end_date + 'T00:00:00');
            label = start.toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: 'numeric' }) + ' – ' + end.toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: 'numeric' });
        }
        $('reg-date').textContent = label;
        if (event.location) { $('reg-location').querySelector('span').textContent = event.location; $('reg-location').hidden = false; }

        var yt = youtubeId(event.youtube_url);
        if (yt) {
            $('reg-video').innerHTML = '<button type="button" class="relative block w-full overflow-hidden rounded-ui" data-action="reg.play" data-id="' + esc(yt) + '" aria-label="Play the event video">'
                + '<img class="aspect-video w-full object-cover" src="https://img.youtube.com/vi/' + esc(yt) + '/hqdefault.jpg" alt="" loading="lazy">'
                + '<span class="absolute inset-0 grid place-items-center"><span class="grid h-16 w-16 place-items-center rounded-full bg-hodRed text-white shadow-ui-lg">'
                + '<svg class="ml-1 h-8 w-8" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg></span></span></button>';
            $('reg-video').hidden = false;
        }
        var ext = safeUrl(event.external_registration_url);
        if (ext) {
            $('reg-external').innerHTML = '<a class="ui-btn ui-btn--brand ui-btn--block" href="' + esc(ext) + '" target="_blank" rel="noopener noreferrer">'
                + HOD.icon('external-link') + '<span>External registration</span></a>';
            $('reg-external').hidden = false;
        }
        // The description is the event's own rich text, written by staff in Events.
        if (event.description) { $('reg-description').innerHTML = event.description; $('reg-description').hidden = false; }
        var banner = safeUrl(event.banner_image_url);
        if (banner) { $('reg-hero-img').src = banner; $('reg-hero').hidden = false; }

        var ministers = Array.isArray(event.ministers) ? event.ministers : [];
        if (ministers.length) {
            $('reg-ministers-list').innerHTML = ministers.map(function (m) {
                var img = safeUrl(m.image);
                return '<div class="w-24 text-center">' + (img ? '<img class="mx-auto h-20 w-20 rounded-full object-cover" src="' + esc(img) + '" alt="' + esc(m.name || '') + '">'
                    : '<span class="mx-auto grid h-20 w-20 place-items-center rounded-full bg-surface-sunken text-xs text-ink-subtle">No photo</span>')
                    + '<p class="mt-1 text-sm font-medium text-ink">' + esc(m.name || '') + '</p></div>';
            }).join('');
            $('reg-ministers').hidden = false;
        } else if (safeUrl(event.ministers_image_url)) {
            $('reg-ministers-list').innerHTML = '<img class="max-h-64 rounded-ui object-cover" src="' + esc(safeUrl(event.ministers_image_url)) + '" alt="Ministering">';
            $('reg-ministers').hidden = false;
        }

        if (cfg.loggedIn && res.member_profile) {
            profile = res.member_profile;
            fillProfile();
            $('reg-member-note').hidden = false;
            $('reg-proxy-wrap').hidden = false;
        }
        if (days.length > 1) {
            $('reg-day-list').innerHTML = days.map(function (day) {
                var d = new Date(day + 'T00:00:00');
                return '<label class="ui-chip cursor-pointer gap-2"><input type="checkbox" class="ui-checkbox" name="selected_days[]" value="' + esc(day) + '">'
                    + '<span>' + esc(d.toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short' })) + '</span></label>';
            }).join('');
            $('reg-days').hidden = false;
        }
        if (questions.length) {
            $('reg-fields').innerHTML = questions.map(field).join('');
            $('reg-custom').hidden = false;
        }
        renumber();
        $('reg-loading').hidden = true;
        $('reg-main').hidden = false;
        if (!reduceMotion) $('reg-main').classList.add('animate-fade-in-up');
    }

    HOD.actions.on('reg.play', function (btn) {
        var id = btn.getAttribute('data-id');
        $('reg-video').innerHTML = '<iframe class="aspect-video w-full rounded-ui" src="https://www.youtube-nocookie.com/embed/' + esc(id)
            + '?modestbranding=1&rel=0&iv_load_policy=3&autoplay=1" title="Event video" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>';
    });

    /* ----- Sharing --------------------------------------------------------- */

    HOD.actions.on('reg.share', function () {
        var url = window.location.href;
        var title = event ? event.title : 'Event invitation';
        var text = event ? 'You’re invited: ' + event.title + '. Register here:' : 'You are invited! Register here:';
        if (navigator.share) {
            navigator.share({ title: title, text: text, url: url }).catch(function () { /* cancelled */ });
        } else if (navigator.clipboard) {
            navigator.clipboard.writeText(url).then(function () { HOD.toast('Link copied. Share it with anyone.', 'success'); },
                function () { HOD.toast(url, 'info'); });
        } else {
            HOD.toast(url, 'info');
        }
    });
    HOD.actions.on('reg.again', function () { window.location.reload(); });

    /* ----- Submitting ------------------------------------------------------ */

    function problems() {
        var issues = [];
        if (!$('reg-name').value.trim()) issues.push('Enter your full name.');
        if (!$('reg-phone').value.trim()) issues.push('Enter your phone number.');
        if (!$('reg-gender').value) issues.push('Select your gender.');
        if (!$('reg-days').hidden && !document.querySelector('input[name="selected_days[]"]:checked')) {
            issues.push('Select at least one day you’ll be attending.');
        }
        Array.prototype.forEach.call(document.querySelectorAll('[data-reg-field][data-required]'), function (box) {
            var type = box.getAttribute('data-type'), label = box.getAttribute('data-label');
            if (type === 'checkbox') {
                if (!box.querySelector('input:checked')) issues.push('Please choose at least one option for “' + label + '”.');
            } else if (type === 'radio') {
                if (!box.querySelector('input:checked')) issues.push('Please select an option for “' + label + '”.');
            } else {
                var el = box.querySelector('input, select, textarea');
                if (!el || !String(el.value || '').trim()) issues.push('Please answer: “' + label + '”.');
            }
        });
        return issues;
    }

    function celebrate() {
        if (reduceMotion || !document.body.animate) return;
        var colors = ['#D11920', '#1D356A', '#4A6BB0', '#D98A00'];
        var host = document.createElement('div');
        host.className = 'pointer-events-none fixed inset-0 z-[10001] overflow-hidden';
        host.setAttribute('aria-hidden', 'true');
        document.body.appendChild(host);
        for (var i = 0; i < 40; i++) {
            var s = document.createElement('span');
            var size = 6 + Math.random() * 7;
            s.style.position = 'absolute';
            s.style.top = '-12px';
            s.style.left = (Math.random() * 100) + '%';
            s.style.width = size + 'px';
            s.style.height = (size * (0.6 + Math.random())) + 'px';
            s.style.background = colors[i % colors.length];
            s.style.borderRadius = Math.random() > 0.5 ? '50%' : '2px';
            host.appendChild(s);
            s.animate([
                { transform: 'translate3d(0,-12px,0) rotate(0deg)', opacity: 1 },
                { transform: 'translate3d(' + ((Math.random() - 0.5) * 240) + 'px,' + (window.innerHeight * (0.6 + Math.random() * 0.4)) + 'px,0) rotate(' + (360 + Math.random() * 540) + 'deg)', opacity: 0.85 }
            ], { duration: 1600 + Math.random() * 1200, easing: 'cubic-bezier(.16,.7,.4,1)', fill: 'forwards' });
        }
        setTimeout(function () { host.remove(); }, 3200);
    }

    HOD.actions.on('reg.submit', function (form, ev) {
        var issues = problems();
        if (issues.length) { showIssues(issues, 'Please fix these'); return; }
        var btn = (ev && ev.submitter) || $('reg-submit');
        HOD.busy(btn, true);
        HOD.api(API, { data: new FormData(form), quiet: true }).then(function (res) {
            $('reg-main').hidden = true;
            $('reg-success').hidden = false;
            window.scrollTo(0, 0);
            celebrate();
            HOD.toast(res.message || 'You are registered.', 'success');
        }).catch(function (err) {
            if (err.code === 'NETWORK' || err.code === 'OFFLINE') {
                showIssues(['Could not reach the server. Please check your connection and try again.'], 'Connection error');
            } else {
                showIssues([err.message], err.response && err.response.status === 'warning' ? 'Heads up' : 'Something went wrong');
            }
        }).then(function () { HOD.busy(btn, false); });
    });

    /* ------------------------------------------------------------------------ */

    function init() {
        if (!cfg.token) { showError('No registration token provided in the URL.'); return; }
        $('reg-token').value = cfg.token;
        HOD.api(API, { method: 'GET', data: { action: 'fetch_form', token: cfg.token }, quiet: true, raw: true }).then(function (res) {
            if (res.status !== 'success') { showError(res.message || 'This link is invalid or the event has been closed.'); return; }
            render(res);
        }).catch(function () {
            showError('Could not connect to the server. Please check your internet connection.');
        });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
