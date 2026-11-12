// /assets/ui/js/pages/mail-team.js
// The team bar and the AI summary card on the Mail page (includes/mail/mounts/thread_team.php, thread_summary.php;
// docs/MAIL.md §7e), from api/mail_team_api.php.
//
// Follows the page's events: mail:thread-opened {threadId, shared} fills both for that conversation,
// mail:thread-closed empties them, mail:composing {threadId, state} turns the mark into "replying". While a shared
// conversation is open its mark is renewed every 20 seconds, but only while the tab is visible and the person was
// active in the last 5 minutes. Changing status or assignee raises mail:thread-changed {source: 'team'} so the list
// and the reader draw again.
(function () {
    'use strict';
    var CFG = HOD.data('mail-team') || {};
    var bar = document.querySelector('[data-team]');
    var card = document.querySelector('[data-ai-summary]');
    if (!CFG.api || (!bar && !card)) return;

    var current = null;     // the open conversation's id
    var shared = false;
    var replying = false;
    var lastActive = Date.now();
    var poll = 20;
    var timer = null;
    var STATUS = { open: 'Open', waiting: 'Waiting', done: 'Done' };
    var esc = HOD.esc;
    var $ = function (sel, root) { return (root || document).querySelector(sel); };

    function post(action, body) {
        return HOD.api(CFG.api, { data: Object.assign({ action: action }, body || {}), json: true, quiet: true });
    }
    function get(action, thread) {
        return HOD.api(CFG.api, { method: 'GET', data: { action: action, thread: thread }, quiet: true });
    }
    function fail(err) {
        if (err && err.name !== 'AbortError') HOD.toast(err.message, 'danger');
    }
    function emit(name, detail) {
        document.dispatchEvent(new CustomEvent(name, { detail: detail }));
    }

    // ---- the team bar -------------------------------------------------------------------------------------------
    function render(s) {
        if (!bar || !s || s.threadId !== current) return;
        poll = s.poll || poll;
        bar.hidden = false;
        var status = $('[data-team-status]', bar);
        status.innerHTML = Object.keys(STATUS).map(function (k) {
            return '<button type="button" class="mt-pill' + (s.status === k ? ' is-on' : '') + '" data-status="' + k + '" aria-pressed="' + (s.status === k) + '"'
                + (s.can.status ? '' : ' disabled') + '>' + esc(STATUS[k]) + '</button>';
        }).join('');
        var select = $('[data-team-assign]', bar);
        var who = $('[data-team-assignee]', bar);
        if (s.can.assign) {
            var mine = s.assignees.filter(function (p) { return p.me; });
            var others = s.assignees.filter(function (p) { return !p.me; });
            select.innerHTML = '<option value="">Not assigned</option>'
                + mine.map(function (p) { return '<option value="' + p.id + '">Me (' + esc(p.name) + ')</option>'; }).join('')
                + others.map(function (p) { return '<option value="' + p.id + '">' + esc(p.name) + '</option>'; }).join('');
            select.value = s.assignee ? String(s.assignee.id) : '';
            select.hidden = false;
            who.textContent = '';
        } else {
            select.hidden = true;
            who.textContent = s.assignee ? 'Assigned to ' + (s.assignee.me ? 'you' : s.assignee.name) : 'Not assigned';
        }
        var here = $('[data-team-presence]', bar);
        var replyingNow = s.presence.filter(function (p) { return p.state === 'replying'; });
        var reading = s.presence.filter(function (p) { return p.state !== 'replying'; });
        var names = function (list) { return list.map(function (p) { return p.firstName; }).join(', '); };
        var lines = [];
        if (replyingNow.length) lines.push(names(replyingNow) + (replyingNow.length > 1 ? ' are' : ' is') + ' replying');
        if (reading.length) lines.push(names(reading) + (reading.length > 1 ? ' are' : ' is') + ' reading');
        here.textContent = lines.join(' · ');
        here.classList.toggle('is-replying', replyingNow.length > 0);
        here.hidden = !lines.length;
    }
    function hideBar() {
        if (!bar) return;
        bar.hidden = true;
        var log = $('[data-team-log]', bar);
        log.hidden = true;
        log.textContent = '';
        $('[data-team-activity]', bar).setAttribute('aria-expanded', 'false');
    }
    function idle() {
        return document.visibilityState !== 'visible' || Date.now() - lastActive > (CFG.idleMinutes || 5) * 60000;
    }
    function beat(force) {
        if (!bar || !current || !shared || (!force && idle())) return;
        var thread = current;
        post('presence', { thread: thread, state: replying ? 'replying' : 'viewing' }).then(function (r) {
            if (thread === current) render(r.data);
        }).catch(function () { /* the next beat tries again */ });
    }
    function schedule() {
        clearInterval(timer);
        timer = setInterval(beat, poll * 1000);
    }
    function changed(r) {
        render(r.data);
        if (r.changed) emit('mail:thread-changed', { threadId: current, source: 'team' });
    }

    if (bar) {
        bar.addEventListener('click', function (e) {
            var pill = e.target.closest('[data-status]');
            if (pill && !pill.disabled && pill.getAttribute('aria-pressed') !== 'true') {
                post('status', { thread: current, status: pill.getAttribute('data-status') }).then(changed).catch(fail);
                return;
            }
            var toggle = e.target.closest('[data-team-activity]');
            if (toggle) {
                var log = $('[data-team-log]', bar);
                var open = toggle.getAttribute('aria-expanded') !== 'true';
                toggle.setAttribute('aria-expanded', String(open));
                log.hidden = !open;
                if (open) {
                    post('activity', { thread: current }).then(function (r) {
                        log.innerHTML = (r.data || []).map(function (a) {
                            return '<li><span>' + esc(a.text) + '</span><time>' + esc(a.at) + '</time></li>';
                        }).join('') || '<li><span>Nothing yet</span></li>';
                    }).catch(fail);
                }
            }
        });
        $('[data-team-assign]', bar).addEventListener('change', function (e) {
            post('assign', { thread: current, to: Number(e.target.value) || 0 }).then(changed).catch(function (err) { fail(err); beat(true); });
        });
    }

    // ---- the summary card ---------------------------------------------------------------------------------------
    function list(items, label) {
        if (!items || !items.length) return '';
        return '<p class="ai-label">' + esc(label) + '</p><ul>' + items.map(function (i) { return '<li>' + esc(i) + '</li>'; }).join('') + '</ul>';
    }
    function renderSummary(d, thread) {
        if (!card || thread !== current) return;
        if (!d || (!d.available && !d.summary)) { card.hidden = true; return; }
        card.hidden = false;
        $('[data-ai-meta]', card).textContent = d.summary ? (d.by ? d.by + ', ' : '') + d.at : '';
        var button = $('[data-ai-summarise]', card);
        button.hidden = !d.available;
        button.textContent = d.summary ? 'Summarize again' : 'Summarize';
        $('[data-ai-body]', card).innerHTML = d.summary
            ? '<p class="ai-gist">' + esc(d.summary.gist) + '</p>' + list(d.summary.keyPoints, 'Key points') + list(d.summary.asked, 'Asked of us')
            : '';
    }
    function loadSummary(thread) {
        if (!card) return;
        card.hidden = true;
        get('summary', thread).then(function (r) { renderSummary(r.data, thread); }).catch(function () { card.hidden = true; });
    }
    if (card) {
        $('[data-ai-summarise]', card).addEventListener('click', function (e) {
            var button = e.currentTarget;
            var thread = current;
            HOD.busy(button, true);
            post('summarise', { thread: thread }).then(function (r) { renderSummary(r.data, thread); })
                .catch(fail)
                .finally(function () { HOD.busy(button, false); });
        });
    }

    // ---- the page's events --------------------------------------------------------------------------------------
    function leave() {
        if (current && shared && bar) post('presence', { thread: current, state: 'leave' }).catch(function () {});
    }
    document.addEventListener('mail:thread-opened', function (e) {
        var d = e.detail || {};
        if (Number(d.threadId) === current) return;
        leave();
        current = Number(d.threadId) || null;
        shared = !!d.shared;
        replying = false;
        hideBar();
        if (current && shared) { beat(true); schedule(); } else { clearInterval(timer); }
        if (current) loadSummary(current);
    });
    document.addEventListener('mail:thread-closed', function () {
        leave();
        current = null;
        clearInterval(timer);
        hideBar();
        if (card) card.hidden = true;
    });
    document.addEventListener('mail:composing', function (e) {
        var d = e.detail || {};
        if (Number(d.threadId) !== current || !shared) return;
        replying = d.state === 'replying';
        post('presence', { thread: current, state: replying ? 'replying' : 'stop' }).catch(function () {});
    });
    ['mousemove', 'keydown', 'scroll', 'touchstart', 'focus'].forEach(function (name) {
        window.addEventListener(name, function () { lastActive = Date.now(); }, { passive: true, capture: true });
    });
    document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'visible') beat(); });
})();
