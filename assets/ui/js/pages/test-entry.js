// /assets/ui/js/pages/test-entry.js
// The way into a test from its QR code (test.php), as a short conversation: messages arrive one at a time with a
// moment of "typing", answers go in the box at the bottom or by tapping a pill, and a rail shows how far along they
// are. Every step is decided on the server (includes/trainings/entry.php through /api/test_entry_api.php): it answers
// with the next step, and this page only says it in words and asks for what it needs. Passwords show as they are
// typed (owner) and as dots in the conversation. Motion only when the person has not asked for less.
(function () {
    'use strict';

    var HOD = window.HOD;
    var root = document.querySelector('[data-test-entry]');
    if (!root) return;
    var cfg = HOD.data('tq-config') || {};
    var API = '/api/test_entry_api.php';
    var RAIL = ['phone', 'email', 'password', 'test'];
    var reduced = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : null;

    var el = {
        log: root.querySelector('[data-tq-log]'),
        pills: root.querySelector('[data-tq-pills]'),
        form: root.querySelector('.tq-composer'),
        input: root.querySelector('[data-tq-input]'),
        label: root.querySelector('[data-tq-label]'),
        eye: root.querySelector('[data-tq-eye]'),
        send: root.querySelector('[data-tq-send]'),
        hint: root.querySelector('[data-tq-hint]'),
        restart: root.querySelector('[data-action="tq.restart"]')
    };
    var st = { ask: null, busy: false, name: '', newpass: '', last: '', typed: '', run: 0 };

    function calm() { return !!(reduced && reduced.matches); }
    function wait(ms) { return new Promise(function (ok) { window.setTimeout(ok, calm() ? 0 : ms); }); }
    function esc(v) { return HOD.esc(v == null ? '' : String(v)); }

    /* ---------- the conversation ---------- */

    function scroll() {
        el.log.scrollTo({ top: el.log.scrollHeight, behavior: calm() ? 'auto' : 'smooth' });
    }

    // Whole class names, so the CSS build (it scans for them) keeps them.
    var KINDS = { bot: 'tq-msg tq-msg--bot', you: 'tq-msg tq-msg--you', typing: 'tq-msg tq-msg--bot tq-typing' };

    function bubble(kind, html) {
        var li = document.createElement('li');
        li.className = KINDS[kind];
        li.innerHTML = html;
        el.log.appendChild(li);
        if (calm()) {
            li.classList.add('is-in');
        } else {
            window.requestAnimationFrame(function () { window.requestAnimationFrame(function () { li.classList.add('is-in'); }); });
        }
        scroll();
        return li;
    }

    function typing() {
        return bubble('typing', '<span class="sr-only">Typing</span><i></i><i></i><i></i>');
    }

    /** The bot says each line in turn, each after a short moment of typing. Lines are text, or {html}. */
    function say(lines) {
        var run = st.run;
        return lines.reduce(function (p, line) {
            return p.then(function () {
                if (run !== st.run) return null;
                var dots = typing();
                var text = typeof line === 'string' ? line : (line.text || '');
                return wait(Math.min(900, 320 + text.length * 9)).then(function () {
                    dots.remove();
                    if (run !== st.run) return;
                    bubble('bot', typeof line === 'string' ? esc(line) : line.html);
                });
            });
        }, Promise.resolve());
    }

    function you(text) {
        bubble('you', esc(text));
        if (el.restart) el.restart.hidden = false;
    }

    /** The rail only moves forward (a code after a password is still progress). */
    function rail(step) {
        var at = Math.max(RAIL.indexOf(step), st.railAt || 0);
        st.railAt = at;
        Array.prototype.forEach.call(root.querySelectorAll('[data-rail]'), function (li) {
            var i = RAIL.indexOf(li.getAttribute('data-rail'));
            li.classList.toggle('is-done', i < at);
            li.classList.toggle('is-here', i === at);
        });
    }

    /* ---------- asking ---------- */

    var ASKS = {
        phone: { type: 'tel', mode: 'tel', auto: 'tel', ph: '0803 123 4567', label: 'Your phone number' },
        email: { type: 'email', mode: 'email', auto: 'email', ph: 'you@gmail.com', label: 'Your email address' },
        password: { type: 'text', mode: 'text', auto: 'current-password', ph: 'Your password', label: 'Your password', secret: true },
        newpass: { type: 'text', mode: 'text', auto: 'new-password', ph: 'Choose a password', label: 'Your new password', secret: true, rules: true },
        confirm: { type: 'text', mode: 'text', auto: 'new-password', ph: 'The same password again', label: 'Your new password again', secret: true },
        code: { type: 'text', mode: 'numeric', auto: 'one-time-code', ph: '6-digit code', label: 'The 6-digit code', max: 7 },
        birthday: { type: 'text', mode: 'text', auto: 'off', ph: 'e.g. 12 March', label: 'Your birthday (day and month)' }
    };

    /** Ask for one kind of answer (or none: pills only), with these pills under the conversation. */
    function ask(kind, pills) {
        st.ask = kind;
        var a = ASKS[kind] || null;
        el.form.hidden = !a;
        el.hint.hidden = true;
        if (a) {
            el.input.value = '';
            el.input.type = a.type;
            el.input.setAttribute('inputmode', a.mode);
            el.input.setAttribute('autocomplete', a.auto);
            el.input.setAttribute('placeholder', a.ph);
            el.input.setAttribute('maxlength', String(a.max || 190));
            el.input.setAttribute('autocapitalize', kind === 'birthday' ? 'sentences' : 'none');
            el.input.setAttribute('spellcheck', 'false');
            el.label.textContent = a.label;
            el.eye.hidden = !a.secret;
            setEye(false);
            if (a.rules && cfg.rules && cfg.rules.length) {
                el.hint.textContent = 'It needs: ' + cfg.rules.join(' · ') + '.';
                el.hint.hidden = false;
            }
        }
        setPills(pills || []);
        busy(false);
        scroll();   // the pills and the box may have grown: keep the last message in view
        if (a && !st.busy) {
            window.setTimeout(function () { el.input.focus({ preventScroll: true }); }, calm() ? 0 : 60);
        } else if (!a) {
            var first = el.pills.querySelector('button');
            if (first) first.focus({ preventScroll: true });
        }
    }

    function setPills(list) {
        el.pills.innerHTML = list.map(function (p) {
            return '<button type="button" class="tq-pill" data-action="tq.pill" data-do="' + esc(p.do) + '"' +
                (p.value != null ? ' data-value="' + esc(p.value) + '"' : '') + '>' + esc(p.label) + '</button>';
        }).join('');
        // The stagger (strict CSP: through the CSSOM, never a style attribute).
        Array.prototype.forEach.call(el.pills.children, function (b, i) { b.style.setProperty('--i', String(i)); });
        el.pills.hidden = list.length === 0;
    }

    /** Passwords are shown as typed (owner); the eye hides them. */
    function setEye(hidden) {
        if (!ASKS[st.ask] || !ASKS[st.ask].secret) return;
        el.input.type = hidden ? 'password' : 'text';
        el.eye.setAttribute('aria-pressed', hidden ? 'true' : 'false');
        el.eye.setAttribute('aria-label', hidden ? 'Show password' : 'Hide password');
        el.eye.innerHTML = HOD.icon(hidden ? 'eye' : 'eye-off');
    }

    function busy(on) {
        st.busy = on;
        el.send.disabled = on;
        el.input.readOnly = on;
        Array.prototype.forEach.call(el.pills.querySelectorAll('button'), function (b) { b.disabled = on; });
    }

    /* ---------- the server ---------- */

    function call(action, data) {
        st.last = action;
        busy(true);
        var run = st.run;
        var dots = typing();
        return HOD.api(API, { action: action, json: true, data: Object.assign({ t: cfg.item }, data || {}), quiet: true })
            .then(function (res) {
                dots.remove();
                if (run === st.run) return step(res);
            })
            .catch(function (err) {
                dots.remove();
                if (run !== st.run) return;
                var message = err && err.code === 'NETWORK' ? 'No connection. Check your data, then try again.'
                    : (err && err.message) || 'Something went wrong. Please try again.';
                if (err && err.code === 'EXPIRED') {
                    return say([message]).then(function () { begin(false); });
                }
                if (err && err.code === 'CSRF') {
                    return say(['This page was open a long time. Let\'s reload it.']).then(function () { window.location.reload(); });
                }
                var again = st.ask === 'confirm' ? 'newpass' : st.ask;
                return say([message]).then(function () { ask(again, pillsFor(again)); });
            });
    }

    /** The pills that go with each kind of question, from what we know of this person. */
    function pillsFor(kind) {
        if (kind === 'password') return [{ label: 'I can\'t remember', do: 'forgot' }];
        if (kind === 'code') return [{ label: 'Send it again', do: 'resend' }, st.typed ? { label: 'Change email', do: 'change_email' } : { label: 'I don\'t use this email', do: 'old_email' }];
        if (kind === 'email' && st.emailNoneOk) return [{ label: 'I don\'t have an email', do: 'no_email' }];
        return [];
    }

    function thanks() {
        return st.thanked ? [] : (st.thanked = true, ['Thank you, ' + st.name + ' 🙏']);
    }

    function step(res) {
        if (res.name) st.name = res.name;
        switch (res.step) {
        case 'idi':
            rail('phone');
            return say([res.message]).then(function () { ask(null, [{ label: 'Try another number', do: 'restart' }]); });

        case 'choose':
            return say(['Thank you! A few people share this number.', 'Which one is you?']).then(function () {
                ask(null, (res.people || []).map(function (p) { return { label: p.name, do: 'choose', value: p.i }; }));
            });

        case 'password':
            rail('password');
            return say(thanks().concat(['Welcome back! Please type the password you use to sign in to HOD Lekki.']))
                .then(function () { ask('password', pillsFor('password')); });

        case 'email': {
            rail('email');
            st.typed = '';
            var lines;
            st.emailNoneOk = !!(res.first || res.activate);
            if (res.first) {
                lines = thanks().concat(['This is your first time on HOD Lekki, so let\'s set you up. ✨',
                    'Please type your email address. We\'ll send you a code to confirm it.']);
            } else if (res.reset) {
                lines = ['No problem, let\'s sort it out.', 'Please type your email address, and we\'ll send you a code to reset your password.'];
            } else if (res.replace) {
                lines = ['No problem. Please type the email address you use now.'];
            } else {
                lines = ['You\'re signed in. 🎉', 'One last thing: please type your email address, so you can always get back in if you ever forget your password.'];
            }
            return say(lines).then(function () { ask('email', pillsFor('email')); });
        }

        case 'typo':
            st.typed = res.typed;
            return say(['Did you mean ' + res.suggest + '?']).then(function () {
                ask('email', [{ label: 'Yes, ' + res.suggest, do: 'typo_yes', value: res.suggest }, { label: 'No, keep mine', do: 'typo_no', value: res.typed }]);
            });

        case 'birthday':
            return say(['Thanks! One quick check to keep your account safe.', 'When is your birthday? The day and month are enough, like 12 March.'])
                .then(function () { ask('birthday'); });

        case 'birthday_mismatch':
            return say(['Hmm, that\'s not the birthday we have for you. Shall we update it to ' + res.said + '?']).then(function () {
                ask(null, [{ label: 'Yes, update it', do: 'bday_yes' }, { label: 'Let me retype', do: 'bday_retype' }]);
            });

        case 'code': {
            rail('email');
            st.typed = res.typed ? 'yes' : '';
            var intro = [];
            if (res.first) intro = thanks().concat(['This is your first time on HOD Lekki, so let\'s set you up. ✨']);
            else if (st.last === 'signin') intro = ['You\'re signed in. 🎉 Let\'s confirm your email once, so you can always get back in.'];
            else if (st.last === 'forgot') intro = ['No problem, let\'s sort it out.'];
            var mail = res.mail ? '<a class="tq-mail" href="' + esc(res.mail.href) + '" target="_blank" rel="noopener">' + HOD.icon('mail') +
                '<span>' + esc(res.mail.label) + '</span>' + HOD.icon('external-link') + '</a>' : '';
            var to = '<strong class="tq-nowrap">' + esc(res.to) + '</strong>';
            var sent = res.note === 'already'
                ? { text: 'A code is already on its way to ' + res.to + '.', html: '<p>A code is already on its way to ' + to + '.</p>' }
                : { text: 'We\'ve sent a 6-digit code to ' + res.to + '.', html: '<p>We\'ve sent a 6-digit code to ' + to + '.</p>' };
            return say(intro.concat([sent, {
                text: 'Open your email and type the code here. Not in your inbox? Please check Spam or Junk too.',
                html: '<p>Open your email and type the code here.</p>' + mail + '<p class="tq-small">Not in your inbox? Please check <strong>Spam</strong> or <strong>Junk</strong> too.</p>'
            }])).then(function () { ask('code', pillsFor('code')); });
        }

        case 'new_password':
            rail('password');
            return say(['That\'s confirmed. ✅', res.first ? 'Now choose a password for HOD Lekki. You\'ll use it to sign in next time.' : 'Now choose a new password.'])
                .then(function () { ask('newpass'); });

        case 'go':
            rail('test');
            ask(null, []);
            el.form.hidden = true;
            return say(['You\'re in, ' + (res.name || st.name) + '! 🎉', 'Opening your test…']).then(function () {
                return wait(500).then(function () { window.location.href = res.go; });
            });
        }
        return say(['Something went wrong. Please try again.']).then(function () { begin(false); });
    }

    /* ---------- answering ---------- */

    function submit() {
        if (st.busy || !st.ask) return;
        var value = el.input.value.trim();
        if (value === '') {
            el.input.focus();
            return;
        }
        var kind = st.ask;
        var secret = ASKS[kind] && ASKS[kind].secret;
        you(secret ? new Array(Math.min(12, Math.max(6, value.length)) + 1).join('•') : value);
        el.input.value = '';
        switch (kind) {
        case 'phone': return call('lookup', { phone: value });
        case 'email': return call('email', { email: value });
        case 'password': return call('signin', { password: value });
        case 'birthday': return call('birthday', { birthday: value });
        case 'code': return call('code', { code: value });
        case 'newpass':
            st.newpass = value;
            return say(['Please type it once more, to be sure.']).then(function () { ask('confirm'); });
        case 'confirm':
            if (value !== st.newpass) {
                st.newpass = '';
                return say(['Those two aren\'t the same. Let\'s try again: please choose your password.']).then(function () { ask('newpass'); });
            }
            return call('new_password', { password: st.newpass, confirm: value });
        }
    }

    function pill(btn) {
        if (st.busy) return;
        var what = btn.getAttribute('data-do');
        var value = btn.getAttribute('data-value');
        you(btn.textContent);
        setPills([]);
        switch (what) {
        case 'restart': return begin(true);
        case 'choose': return call('choose', { i: Number(value) });
        case 'forgot': return call('forgot');
        case 'old_email': return call('old_email');
        case 'no_email': return call('no_email');
        case 'resend': return call('resend').then(function () {});
        case 'change_email':
            return say(['Sure. Please type your email address again.']).then(function () { ask('email', pillsFor('email')); });
        case 'typo_yes': return call('email', { email: value });
        case 'typo_no': return call('email', { email: value, as_typed: 1 });
        case 'bday_yes': return call('birthday_update');
        case 'bday_retype':
            return say(['No problem. Please type your birthday again, like 12 March.']).then(function () { ask('birthday'); });
        }
    }

    /* ---------- the start ---------- */

    function begin(again) {
        st.run++;
        st = { ask: null, busy: false, name: '', newpass: '', last: '', typed: '', run: st.run, railAt: 0 };
        if (again) el.log.innerHTML = '';
        if (el.restart) el.restart.hidden = true;
        rail('phone');
        ask(null, []);
        el.form.hidden = true;
        var lines = again ? ['Let\'s start again.'] : ['Welcome to ' + (cfg.title || 'the test') + ' 👋'];
        return say(lines.concat(['Please type your phone number, the one HOD Lekki has for you.'])).then(function () { ask('phone'); });
    }

    HOD.actions.on('tq.send', function () { submit(); });
    HOD.actions.on('tq.pill', pill);
    HOD.actions.on('tq.restart', function () { if (!st.busy) begin(true); });
    HOD.actions.on('tq.eye', function () { setEye(el.input.type !== 'password'); el.input.focus(); });

    // The code sends itself once the sixth digit is in (pasted, typed or filled by the phone).
    el.input.addEventListener('input', function () {
        if (st.ask === 'code' && el.input.value.replace(/\D/g, '').length === 6 && !st.busy) submit();
    });

    begin(false);
})();
