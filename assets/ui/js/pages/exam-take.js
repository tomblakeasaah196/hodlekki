// /assets/ui/js/pages/exam-take.js
// Taking an exam (modules/exams/take.php), one question per screen: the
// rules and Start, then a wizard (where you are, the clock, saved, a map of
// every question, Back / Next, a flag to come back to), a review before
// handing in, and the result. The server keeps the clock, saves each answer
// as it is chosen, hands the paper in at zero and marks it (/api/exams_api.php
// actions take, start, answer, focus, submit; includes/exams/engine.php). The
// browser never learns which option is right. Flags and the question you were
// on stay in this tab (sessionStorage) and are never sent.
// Motion (app.css "Exam wizard"): .xt-motion and the slide-ins only when the
// reader has not asked for reduced motion.
(function () {
    'use strict';

    var HOD = window.HOD;
    var API = '/api/exams_api.php';
    var LETTERS = ['A', 'B', 'C', 'D', 'E'];
    var HUES = ['navy', 'violet', 'teal'];
    var TYPES = { single: 'Choose one', multiple: 'Choose all that apply', true_false: 'True or false' };
    var WARN_AT = 300;   // seconds: the clock turns amber
    var DANGER_AT = 60;  // seconds: the clock turns red
    // Retakes (attempts set per quiz, owner's choice): the take view brings
    // attempts_allowed, attempts_used, can_retake and best. Without them
    // there is no Try again. The action that starts the next attempt:
    var RETAKE_ACTION = 'start';
    var cfg = {};
    var reducedMotion = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : null;

    var take = {
        view: null, qs: [], at: 0, reviewing: false, chosen: {}, flags: {},
        deadline: 0, timer: null, ending: false, band: null, saving: {}
    };

    function call(action, data, opts) {
        return HOD.api(API, Object.assign({ action: action, json: true, data: data || {} }, opts || {}));
    }
    function one(sel, root) { return (root || document).querySelector(sel); }
    function all(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
    function esc(v) { return HOD.esc(v == null ? '' : String(v)); }
    function plural(n, a, b) { return n + ' ' + (n === 1 ? a : b); }
    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function motionOk() { return !(reducedMotion && reducedMotion.matches); }
    function when(at) {
        if (!at) return '';
        var d = new Date(String(at).replace(' ', 'T'));
        return isNaN(d.getTime()) ? '' : d.toLocaleString('en-GB', { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
    }
    /** "after the exam closes, Fri 20 Nov, 12:00" and the like. */
    function reviewWhen(kind, at) {
        if (kind === 'close') return 'after the exam closes, ' + when(at);
        if (kind === 'end') return 'after the training ends, ' + when(at);
        return 'once your tutor releases them';
    }

    /** Plays a one-off entrance on el (never under reduced motion). */
    function animateIn(el, cls) {
        if (!el || !cls || !motionOk()) return;
        el.classList.add(cls);
        el.addEventListener('animationend', function done() {
            el.classList.remove(cls);
            el.removeEventListener('animationend', done);
        });
    }

    /** A polite word for screen readers (the clock's 5- and 1-minute marks). */
    function say(text) {
        var el = one('[data-xt-say]');
        if (!el) return;
        el.textContent = '';
        window.setTimeout(function () { el.textContent = text; }, 60);
    }

    /* ---------- the flags and your place, in this tab only ---------- */

    function storeKey() { return 'hod.exam.' + cfg.item; }
    function remember() {
        try { window.sessionStorage.setItem(storeKey(), JSON.stringify({ at: take.at, flags: take.flags })); } catch (e) { /* private mode */ }
    }
    function recall() {
        try { return JSON.parse(window.sessionStorage.getItem(storeKey()) || 'null') || {}; } catch (e) { return {}; }
    }
    function forget() {
        try { window.sessionStorage.removeItem(storeKey()); } catch (e) { /* private mode */ }
    }

    /* ---------- before and after ---------- */

    function render(v) {
        take.view = v;
        var view = one('[data-xt-view]');
        var run = one('[data-xt-run]');
        if (v.state === 'started') {
            view.hidden = true;
            view.innerHTML = '';
            run.hidden = false;
            begin(v.paper);
            return;
        }
        stopClock();
        run.hidden = true;
        view.hidden = false;
        if (v.state === 'not_set') {
            view.innerHTML = '<div class="ui-empty"><p class="ui-empty__title">Not ready yet</p><p class="ui-empty__text">Your tutor has not set this exam up yet.</p></div>';
            return;
        }
        if (v.state === 'ready') {
            view.innerHTML = introHtml(v);
            animateIn(view.firstElementChild, 'xt-rise');
            return;
        }
        forget();
        view.innerHTML = resultHtml(v.result, v);
        animateIn(view.firstElementChild, 'xt-rise');
        scoreIn(v.result);
        celebrate(v);
    }

    /* ---------- the moment: confetti on a pass, a gentle sway on a miss ---------- */

    var CONFETTI = ['#0B1B3F', '#C9A227', '#E8C766', '#2BB3A3', '#E4572E', '#7C5CFF'];
    var celebrated = {};

    /** Right after handing in (v.fresh), once per attempt; never under reduced motion. A result opened later stays calm. */
    function celebrate(v) {
        var r = v.result || {};
        var key = cfg.item + ':' + r.attempt_no;
        if (!motionOk() || celebrated[key] || !v.fresh) return;
        celebrated[key] = true;
        if (r.passed) {
            confetti();
        } else {
            animateIn(one('.xt-result__icon'), 'xt-sway');
        }
    }

    function confetti() {
        var box = document.createElement('div');
        box.className = 'xt-confetti';
        box.setAttribute('aria-hidden', 'true');
        for (var i = 0; i < 90; i++) {
            var p = document.createElement('i');
            p.style.left = (Math.random() * 100).toFixed(2) + '%';
            p.style.background = CONFETTI[i % CONFETTI.length];
            p.style.setProperty('--x', ((Math.random() - 0.5) * 220).toFixed(0) + 'px');
            p.style.setProperty('--r', ((Math.random() - 0.5) * 1440).toFixed(0) + 'deg');
            p.style.animationDelay = (Math.random() * 0.6).toFixed(2) + 's';
            p.style.animationDuration = (2.4 + Math.random() * 1.6).toFixed(2) + 's';
            if (i % 3 === 0) p.className = 'is-round';
            box.appendChild(p);
        }
        document.body.appendChild(box);
        window.setTimeout(function () { if (box.parentNode) box.parentNode.removeChild(box); }, 4800);
    }

    function fact(label, value) {
        return '<div class="xt-fact"><dt>' + esc(label) + '</dt><dd>' + esc(value) + '</dd></div>';
    }

    function introHtml(v) {
        var line = v.window === 'not_open' ? 'Opens ' + when(v.opens) + '.' : (v.window === 'closed' ? 'This exam has closed.' : (v.closes ? 'Open until ' + when(v.closes) + '.' : 'Open now.'));
        return '<section class="xt-card xt-warm" aria-labelledby="xt-intro-title">' +
            '<h2 class="xt-title" id="xt-intro-title">Ready when you are</h2>' +
            '<dl class="xt-facts">' + fact('Questions', v.questions) + fact('Minutes', v.minutes) + fact('To pass', v.pass_percent + '%') + '</dl>' +
            '<p>' + esc(line + ' ' + (Number(v.attempts_allowed) > 1 ? v.attempts_allowed + ' tries, until you pass' : 'One attempt') + '; the clock starts when you press Start.') + '</p>' +
            (v.review ? '<p class="xt-note">' + esc(v.review.open ? 'Answers to any you get wrong show right after.'
                : 'Answers to any you get wrong show ' + reviewWhen(v.review.when, v.review.at) + '.') + '</p>' : '') +
            (v.window === 'open' ? '<div class="xt-start"><button type="button" class="ui-btn ui-btn--primary ui-btn--lg" data-action="xt.start"><span>Start</span>' + HOD.icon('arrow-right') + '</button></div>' : '') +
            '</section>';
    }

    /** Best so far, attempts left and Try again, from the take view; nothing when the fields are missing. */
    function attemptsHtml(v) {
        var allowed = Number(v.attempts_allowed) || 0;
        var used = Number(v.attempts_used) || 0;
        var best = v.best && v.best.percent != null && !isNaN(Number(v.best.percent)) ? Math.round(Number(v.best.percent)) : null;
        var bits = [];
        if (best !== null && used > 1) bits.push('Best so far: ' + best + '%');
        if (allowed > 0 && used > 0) bits.push(used < allowed ? plural(allowed - used, 'attempt', 'attempts') + ' left' : 'No attempts left');
        var html = bits.length ? '<p class="xt-attempts">' + esc(bits.join(' · ')) + '</p>' : '';
        if (v.can_retake === true && (allowed === 0 || used < allowed)) {
            html += '<div class="xt-retake"><button type="button" class="ui-btn ui-btn--primary ui-btn--lg" data-action="xt.retake"' +
                (allowed ? ' aria-describedby="xt-retake-n"' : '') + '><span>Try again</span>' + HOD.icon('refresh-cw') + '</button>' +
                (allowed ? '<p class="ui-meta" id="xt-retake-n">Attempt ' + (used + 1) + ' of ' + allowed + '</p>' : '') + '</div>';
        }
        return html;
    }

    function resultHtml(r, v) {
        v = v || {};
        var pct = Math.round(Number(r.percent) || 0);
        var passed = !!r.passed;
        var right = Math.max(0, (Number(r.questions) || 0) - (Number(r.wrong_count) || 0));
        var html = '<section class="xt-card xt-warm xt-result' + (passed ? ' xt-result--pass' : ' xt-result--miss') + '" aria-labelledby="xt-result-title">' +
            '<span class="xt-result__icon">' + HOD.icon(passed ? 'party-popper' : 'book-open') + '</span>' +
            '<h2 class="xt-title" id="xt-result-title">' + (passed ? 'You passed. Well done!' : 'Not this time') + '</h2>' +
            '<p class="xt-result__line">' + esc(passed ? 'Thank you for learning with us. Keep going.'
                : (v.can_retake ? 'Look over the ones you missed, then try again.' : 'Thank you for sitting it. Keep learning; your tutor can help.')) + '</p>' +
            '<div class="xt-score"><p class="xt-score__pct"><span data-xt-pct>' + pct + '</span>%</p>' +
            '<div class="xt-score__bar" role="img" aria-label="' + esc(pct + '%, and ' + r.pass_percent + '% to pass') + '">' +
            '<span class="xt-score__fill" data-xt-score></span><span class="xt-score__pass" data-xt-passmark></span></div>' +
            '<p class="ui-meta">' + esc(r.pass_percent + '% to pass') + '</p></div>' +
            '<dl class="xt-tally">' +
            '<div class="xt-tally__item xt-tally__item--right"><dt>Right</dt><dd>' + right + '</dd></div>' +
            '<div class="xt-tally__item xt-tally__item--miss"><dt>Missed</dt><dd>' + (Number(r.wrong_count) || 0) + '</dd></div>' +
            '<div class="xt-tally__item"><dt>Marks</dt><dd>' + esc(r.score + ' / ' + r.max) + '</dd></div></dl>' +
            attemptsHtml(v) +
            (v.certificate ? '<div class="xt-cert"><a class="ui-btn ui-btn--secondary" href="' + esc(v.certificate) + '">' + HOD.icon('file-down') + '<span>Download certificate</span></a></div>' : '') +
            '</section>';
        var back = v.test ? '<p class="xt-after"><a class="ui-link" href="/modules/trainings/#tab=tests">Back to Trainings</a></p>' : '';
        if (r.wrong_count === 0) {
            return html + '<p class="xt-after">Every answer right. Well done.</p>' + back;
        }
        if (!r.review_open && r.missed && r.missed.length) {
            // A test with another try left: the questions they missed, without the answers.
            return html + '<h2 class="ui-h3 xt-learn-title">You missed</h2><ol class="xt-learn">' + r.missed.map(function (w) {
                return '<li class="xt-card xt-missed"><p class="ui-meta">Question ' + w.n + '</p><p class="xt-learn__q">' + esc(w.text) + '</p></li>';
            }).join('') + '</ol><p class="xt-after">The right answers show after your last try, or once you pass.</p>' + back;
        }
        if (!r.review_open) {
            return html + '<p class="xt-after">' + esc(plural(r.wrong_count, 'answer was', 'answers were') + ' not right. The right answers and why show here ' + reviewWhen(r.review_when, r.review_at) + '.') + '</p>' + back;
        }
        return html + '<h2 class="ui-h3 xt-learn-title">To learn from</h2><ol class="xt-learn">' + r.wrong.map(function (w) {
            return '<li class="xt-card"><p class="ui-meta">Question ' + w.n + (w.part ? ' · ' + w.part + ' of ' + w.marks + ' marks' : '') + '</p>' +
                '<p class="xt-learn__q">' + esc(w.text) + '</p>' +
                '<p class="xt-ans xt-ans--yours">' + HOD.icon('circle-x') + '<span><strong>Your answer:</strong> ' + esc(w.yours || 'none') + '</span></p>' +
                '<p class="xt-ans xt-ans--right">' + HOD.icon('circle-check') + '<span><strong>Right answer:</strong> ' + esc(w.right) + '</span></p>' +
                (w.explanation ? '<p class="xt-why">' + HOD.icon('book-open') + '<span>' + esc(w.explanation) + '</span></p>' : '') + '</li>';
        }).join('') + '</ol>' + back;
    }

    /** The score bar fills and the percentage counts up, once. */
    function scoreIn(r) {
        var fill = one('[data-xt-score]');
        var mark = one('[data-xt-passmark]');
        var pctEl = one('[data-xt-pct]');
        if (!fill) return;
        var pct = Math.max(0, Math.min(100, Math.round(Number(r.percent) || 0)));
        mark.style.setProperty('--xt-pass', String(Math.max(0, Math.min(100, Number(r.pass_percent) || 0)) / 100));
        if (!motionOk() || pct === 0) {
            fill.style.setProperty('--xt-s', String(pct / 100));
            return;
        }
        fill.style.setProperty('--xt-s', '0');
        pctEl.textContent = '0';
        window.requestAnimationFrame(function () {
            window.requestAnimationFrame(function () { fill.style.setProperty('--xt-s', String(pct / 100)); });
        });
        var start = null;
        function step(now) {
            if (start === null) start = now;
            var p = Math.min(1, (now - start) / 900);
            pctEl.textContent = String(Math.round(pct * (1 - Math.pow(1 - p, 3))));
            if (p < 1) window.requestAnimationFrame(step);
        }
        window.requestAnimationFrame(step);
    }

    function retake(btn) {
        var v = take.view || {};
        var next = (Number(v.attempts_used) || 0) + 1;
        HOD.confirm({ title: 'Start attempt ' + next + '?', message: (v.minutes ? v.minutes + ' minutes. ' : '') + 'The clock keeps running if you leave.', confirmLabel: 'Start' }).then(function (yes) {
            if (!yes) return;
            HOD.busy(btn, true);
            forget();
            call(RETAKE_ACTION, { item: cfg.item }).then(render).catch(function () { HOD.busy(btn, false); });
        });
    }

    function start(btn) {
        var v = take.view;
        HOD.confirm({ title: 'Start the exam?', message: 'One attempt, ' + v.minutes + ' minutes. The clock keeps running if you leave.', confirmLabel: 'Start' }).then(function (yes) {
            if (!yes) return;
            HOD.busy(btn, true);
            forget();
            call('start', { item: cfg.item }).then(render).catch(function () { HOD.busy(btn, false); });
        });
    }

    /* ---------- the wizard ---------- */

    function answered(n) { return (take.chosen[n] || []).length > 0; }
    function answeredCount() { return take.qs.filter(function (q) { return answered(q.n); }).length; }

    function begin(p) {
        var first = !take.qs.length;
        take.qs = p.questions || [];
        take.chosen = {};
        take.qs.forEach(function (q) { take.chosen[q.n] = (q.chosen || []).filter(function (i) { return i >= 0; }); });
        var kept = recall();
        take.flags = kept.flags && typeof kept.flags === 'object' ? kept.flags : {};
        take.deadline = Date.now() + p.seconds_left * 1000;
        take.band = null;
        take.saving = {};
        saved('');
        if (!take.timer) take.timer = window.setInterval(clock, 1000);
        clock();
        show(Math.min(Math.max(0, Number(kept.at) || 0), Math.max(0, take.qs.length - 1)), first ? 'rise' : 0);
    }

    function head() {
        var n = take.qs.length;
        var at = take.at;
        one('[data-xt-pos]').textContent = take.reviewing ? 'Review' : 'Question ' + (at + 1);
        one('[data-xt-of]').textContent = take.reviewing ? '' : 'of ' + n;
        var bar = one('[data-xt-bar]');
        bar.setAttribute('aria-valuemax', String(n));
        bar.setAttribute('aria-valuenow', String(take.reviewing ? n : at + 1));
        bar.setAttribute('aria-valuetext', take.reviewing ? 'Review, before handing in' : 'Question ' + (at + 1) + ' of ' + n);
        one('[data-xt-fill]').style.setProperty('--xt-p', String(n ? (take.reviewing ? 1 : (at + 1) / n) : 0));
        one('[data-xt-answered]').textContent = answeredCount() + ' of ' + n + ' answered';
        one('[data-xt-map]').innerHTML = take.qs.map(function (q, i) { return chip(q, i, !take.reviewing && i === at); }).join('');
    }

    function chip(q, i, current) {
        var state = [answered(q.n) ? 'answered' : 'no answer yet', take.flags[q.n] ? 'flagged' : ''].filter(Boolean).join(', ');
        return '<li><button type="button" class="xt-chip' + (answered(q.n) ? ' is-answered' : '') + (take.flags[q.n] ? ' is-flagged' : '') + '"' +
            ' data-action="xt.go" data-i="' + i + '"' + (current ? ' aria-current="step"' : '') +
            ' aria-label="' + esc('Question ' + (i + 1) + ', ' + state) + '">' + (i + 1) + '</button></li>';
    }

    function foot() {
        var back = one('[data-xt-back]');
        var next = one('[data-xt-next]');
        back.disabled = !take.reviewing && take.at === 0;
        if (take.reviewing) next.innerHTML = '<span>Hand in</span>' + HOD.icon('send');
        else if (take.at === take.qs.length - 1) next.innerHTML = '<span>Review</span>' + HOD.icon('clipboard-list');
        else next.innerHTML = '<span>Next</span>' + HOD.icon('arrow-right');
    }

    function questionHtml(q) {
        var kind = q.type === 'multiple' ? 'checkbox' : 'radio';
        var chosen = take.chosen[q.n] || [];
        var flagged = !!take.flags[q.n];
        var keys = 'Keys: ' + LETTERS[0] + '–' + LETTERS[Math.min(q.options.length, LETTERS.length) - 1] + ' to choose, Enter for next.';
        return '<section class="xt-card xt-q" aria-labelledby="xt-q-' + q.n + '">' +
            '<div class="xt-q__meta"><span class="xt-type">' + esc(TYPES[q.type] || TYPES.single) +
            ' <span class="xt-type__marks">· ' + esc(plural(q.marks, 'mark', 'marks')) + '</span></span>' +
            '<button type="button" class="xt-flag" aria-pressed="' + flagged + '" aria-label="Flag for review" data-action="xt.flag">' + HOD.icon('flag') +
            '<span>' + (flagged ? 'Flagged' : 'Flag') + '</span></button></div>' +
            '<h2 class="xt-q__text" id="xt-q-' + q.n + '" tabindex="-1" data-xt-q>' + esc(q.text) + '</h2>' +
            '<fieldset aria-labelledby="xt-q-' + q.n + '" aria-describedby="xt-q-type">' +
            '<span class="sr-only" id="xt-q-type">' + esc(TYPES[q.type] || TYPES.single) + '</span>' +
            '<div class="xt-opts ' + (kind === 'radio' ? 'xt-opts--one' : 'xt-opts--many') + (q.type === 'true_false' ? ' xt-opts--tf' : '') + '">' +
            q.options.map(function (o, i) {
                var on = chosen.indexOf(i) !== -1;
                return '<label class="xt-opt' + (on ? ' is-on' : '') + '"><input type="' + kind + '" name="xt-q' + q.n + '" value="' + i + '"' +
                    (on ? ' checked' : '') + ' data-action-change="xt.pick">' +
                    '<span class="xt-opt__key" aria-hidden="true">' + LETTERS[i] + HOD.icon('check') + '</span>' +
                    '<span class="xt-opt__text">' + esc(o) + '</span></label>';
            }).join('') + '</div></fieldset>' +
            '<p class="xt-hint">' + esc(keys) + '</p></section>';
    }

    /** Shows question i; dir 1 (Next), -1 (Back), 'rise' (the first) or 0. */
    function show(i, dir) {
        if (!take.qs.length) return;
        take.at = Math.max(0, Math.min(i, take.qs.length - 1));
        take.reviewing = false;
        var q = take.qs[take.at];
        one('[data-exam-take]').setAttribute('data-hue', HUES[take.at % HUES.length]);
        var stage = one('[data-xt-stage]');
        stage.innerHTML = questionHtml(q);
        animateIn(stage.firstElementChild, dir === 1 ? 'xt-in-next' : (dir === -1 ? 'xt-in-back' : (dir === 'rise' ? 'xt-rise' : '')));
        head();
        foot();
        remember();
        if (dir === 1 || dir === -1) settle();
    }

    /** After moving: back to the top, and the new question is what is read next. */
    function settle() {
        var main = one('.ui-main');
        if (main && main.scrollTop > 0) main.scrollTop = 0;
        var h = one('[data-xt-q]');
        if (h) h.focus({ preventScroll: true });
    }

    function next() {
        if (take.reviewing) { handIn(); return; }
        if (take.at < take.qs.length - 1) { show(take.at + 1, 1); return; }
        review();
    }

    function back() {
        if (take.reviewing) { show(take.qs.length - 1, -1); return; }
        if (take.at > 0) show(take.at - 1, -1);
    }

    function go(btn) {
        var i = Number(btn.getAttribute('data-i'));
        closeMap();
        if (!take.reviewing && i === take.at) return;
        show(i, take.reviewing || i < take.at ? -1 : 1);
    }

    function closeMap() {
        var toggle = one('[data-action="xt.map"]');
        toggle.setAttribute('aria-expanded', 'false');
        one('#xt-map').hidden = true;
    }

    function toggleMap(btn) {
        var open = btn.getAttribute('aria-expanded') !== 'true';
        btn.setAttribute('aria-expanded', String(open));
        one('#xt-map').hidden = !open;
        if (open) animateIn(one('#xt-map'), 'xt-rise');
    }

    function flag(btn) {
        var n = take.qs[take.at].n;
        var on = !take.flags[n];
        if (on) take.flags[n] = true; else delete take.flags[n];
        btn.setAttribute('aria-pressed', String(on));
        btn.querySelector('span').textContent = on ? 'Flagged' : 'Flag';
        head();
        remember();
    }

    function jumps(title, icon, list) {
        if (!list.length) return '';
        return '<div class="xt-group"><p class="xt-group__title">' + HOD.icon(icon) + '<span>' + esc(title) + '</span></p><ul class="xt-jumps">' +
            list.map(function (i) { return chip(take.qs[i], i, false); }).join('') + '</ul></div>';
    }

    function review() {
        take.reviewing = true;
        closeMap();
        var n = take.qs.length;
        var done = answeredCount();
        var missing = [];
        var flagged = [];
        take.qs.forEach(function (q, i) {
            if (!answered(q.n)) missing.push(i);
            if (take.flags[q.n]) flagged.push(i);
        });
        one('[data-exam-take]').setAttribute('data-hue', 'navy');
        var stage = one('[data-xt-stage]');
        stage.innerHTML = '<section class="xt-card xt-review" aria-labelledby="xt-review-title">' +
            '<h2 class="xt-title" id="xt-review-title" tabindex="-1" data-xt-q>Ready to hand in?</h2>' +
            '<p class="xt-tally"><span class="xt-tally__n">' + done + '</span><span class="ui-meta">of ' + n + ' answered</span></p>' +
            '<p class="ui-meta">You can change any answer until you hand in.</p>' +
            (missing.length || flagged.length ? jumps('No answer yet', 'circle-help', missing) + jumps('Flagged to come back to', 'flag', flagged)
                : '<p class="xt-group xt-allset">' + HOD.icon('circle-check') + '<span>Every question has an answer.</span></p>') +
            '</section>';
        animateIn(stage.firstElementChild, 'xt-in-next');
        head();
        foot();
        settle();
    }

    function handIn() {
        var empty = take.qs.filter(function (q) { return !answered(q.n); }).length;
        HOD.confirm({ title: 'Hand in now?', message: empty ? plural(empty, 'question has', 'questions have') + ' no answer. You cannot change anything after this.' : 'You cannot change your answers after this.', confirmLabel: 'Hand in' })
            .then(function (yes) { if (yes) finish(false); });
    }

    /* ---------- choosing and saving ---------- */

    function pick(input) {
        var n = Number(String(input.name).replace('xt-q', ''));
        var inputs = all('input[name="xt-q' + n + '"]');
        inputs.forEach(function (i) { i.closest('.xt-opt').classList.toggle('is-on', i.checked); });
        take.chosen[n] = inputs.filter(function (i) { return i.checked; }).map(function (i) { return Number(i.value); });
        head();
        save(n, take.chosen[n]);
    }

    function saved(state) {
        var el = one('[data-xt-saved]');
        el.classList.toggle('is-error', state === 'error' || state === 'offline');
        el.innerHTML = state === 'saving' ? '<span>Saving…</span>'
            : state === 'ok' ? HOD.icon('check') + '<span>Saved</span>'
            : state === 'error' ? HOD.icon('circle-x') + '<span>Not saved</span>'
            : state === 'offline' ? HOD.icon('cloud-off') + '<span>Not saved: offline</span>' : '';
    }

    // One request at a time per question, always with the latest choice, so
    // a quick change of mind cannot be overtaken by an older answer.
    function save(n, chosen) {
        var s = take.saving[n] || (take.saving[n] = { busy: false, next: null });
        s.next = chosen.slice();
        if (!s.busy) flush(n);
    }

    function flush(n) {
        var s = take.saving[n];
        if (!s || s.next === null) return;
        var chosen = s.next;
        s.next = null;
        s.busy = true;
        saved('saving');
        call('answer', { item: cfg.item, n: n, chosen: chosen }, { quiet: true, raw: true }).then(function (res) {
            if (res.status === 'success') {
                take.deadline = Date.now() + res.seconds_left * 1000;
                if (s.next === null) saved('ok');
                return;
            }
            if (res.code === 'ENDED') {
                s.next = null;
                HOD.toast(res.message, 'warning');
                call('take', { item: cfg.item }).then(render).catch(function () {});
                return;
            }
            saved('error');
            HOD.toast(res.message, 'danger');
        }).catch(function () {
            saved('offline');
        }).finally(function () {
            s.busy = false;
            flush(n);
        });
    }

    /* ---------- the clock and handing in ---------- */

    function clock() {
        var left = Math.max(0, Math.round((take.deadline - Date.now()) / 1000));
        one('[data-xt-clock]').textContent = pad(Math.floor(left / 60)) + ':' + pad(left % 60);
        var band = left <= DANGER_AT ? 'danger' : (left <= WARN_AT ? 'warn' : 'calm');
        var pill = one('[data-xt-timer]');
        pill.classList.toggle('is-warn', band === 'warn');
        pill.classList.toggle('is-danger', band === 'danger');
        if (band !== take.band) {
            if (band === 'warn') say('Under 5 minutes left.');
            if (band === 'danger' && left > 0) say('Under 1 minute left. At zero your answers are handed in.');
            take.band = band;
        }
        if (left === 0) finish(true);
    }

    function stopClock() {
        window.clearInterval(take.timer);
        take.timer = null;
    }

    function finish(auto) {
        if (take.ending) return;
        take.ending = true;
        stopClock();
        call('submit', { item: cfg.item }, { quiet: true }).then(function (res) {
            if (auto) HOD.toast('Time is up. Your answers were handed in.', 'info');
            res.fresh = true;   // just handed in: the result gets its moment (celebrate())
            render(res);
        }).catch(function () {
            // The server hands it in at the deadline anyway; show where things stand.
            call('take', { item: cfg.item }, { quiet: true }).then(render).catch(function () {});
        }).finally(function () { take.ending = false; });
    }

    function focusNote() {
        if (!take.view || take.view.state !== 'started') return;
        call('focus', { item: cfg.item, kind: document.hidden ? 'leave' : 'return' }, { quiet: true }).catch(function () {});
        if (!document.hidden) HOD.toast('Leaving the exam tab is noted for your tutor.', 'warning');
    }

    /* ---------- the keyboard: A–E or 1–5 choose, Enter is Next ---------- */

    function onKey(e) {
        if (!take.view || take.view.state !== 'started' || take.ending) return;
        if (e.defaultPrevented || e.altKey || e.ctrlKey || e.metaKey) return;
        if (document.querySelector('dialog[open]')) return;
        var t = e.target;
        var tag = t && t.tagName;
        if (t && (t.isContentEditable || tag === 'TEXTAREA' || tag === 'SELECT' || (tag === 'INPUT' && t.type !== 'radio' && t.type !== 'checkbox'))) return;
        if (e.key === 'Enter') {
            if (tag === 'BUTTON' || tag === 'A' || tag === 'SUMMARY') return;
            e.preventDefault();
            next();
            return;
        }
        if (take.reviewing) return;
        var boxes = all('[data-xt-stage] .xt-opt input');
        var idx = /^[1-5]$/.test(e.key) ? Number(e.key) - 1 : (/^[a-e]$/i.test(e.key) ? e.key.toUpperCase().charCodeAt(0) - 65 : -1);
        if (idx >= 0) {
            var input = boxes[idx];
            if (!input) return;
            e.preventDefault();
            if (input.type === 'radio') {
                if (input.checked) return;
                input.checked = true;
            } else {
                input.checked = !input.checked;
            }
            input.focus({ preventScroll: true });
            pick(input);
            return;
        }
        // Radios move with the arrows by themselves; checkboxes get the same.
        if ((e.key === 'ArrowDown' || e.key === 'ArrowUp') && t && t.type === 'checkbox') {
            var to = boxes[boxes.indexOf(t) + (e.key === 'ArrowDown' ? 1 : -1)];
            if (to) {
                e.preventDefault();
                to.focus();
            }
        }
    }

    HOD.ready(function () {
        cfg = HOD.data('exams-config') || {};
        var page = one('[data-exam-take]');
        if (!page || cfg.page !== 'take') return;
        if (motionOk()) page.classList.add('xt-motion');
        HOD.actions.on('xt.start', start);
        HOD.actions.on('xt.pick', pick);
        HOD.actions.on('xt.next', next);
        HOD.actions.on('xt.back', back);
        HOD.actions.on('xt.go', go);
        HOD.actions.on('xt.map', toggleMap);
        HOD.actions.on('xt.flag', flag);
        HOD.actions.on('xt.retake', retake);
        document.addEventListener('keydown', onKey);
        document.addEventListener('visibilitychange', focusNote);
        call('take', { item: cfg.item }).then(render).catch(function () {});
    });
})();
