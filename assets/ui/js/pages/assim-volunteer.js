// /assets/ui/js/pages/assim-volunteer.js
// The Assimilation volunteer page (assimilation.php): the phone check (kept
// on this phone, re-checked on every visit), the volunteer's people to call
// and the unclaimed pool, and the follow-up sheet (channel, outcome, notes by
// voice with "Tidy with AI", prayer points, next touch, an opening line).
// Every call goes to /api/assimilation_public_api.php with the volunteer's
// phone, which the API re-checks each time.
(function () {
    'use strict';

    var HOD = window.HOD;
    var API = '/api/assimilation_public_api.php';
    var LS_KEY = 'assimilation_volunteer_v1';
    var CHANNELS = { Call: 'Call', WhatsApp: 'WhatsApp', SMS: 'SMS', Visit: 'Visit', At_Church: 'At church' };
    var OUTCOMES = {
        Spoke_With_Them: 'We spoke', Promised_To_Come: 'Promised to come', No_Answer: 'No answer',
        Wrong_Number: 'Wrong number', Not_Interested: 'Not interested', Relocated: 'Has relocated',
        Attends_Elsewhere: 'Attends elsewhere', Asked_For_No_Contact: 'Asked for no contact'
    };
    var CLOSING = ['Not_Interested', 'Relocated', 'Attends_Elsewhere', 'Asked_For_No_Contact'];

    var volunteer = null;
    var matchPayload = null;
    var people = { mine: [], pool: [] };
    var sheet = { caseId: null, channel: 'Call', outcome: '', next: '', clean: '', keep: false };
    var recognition = null;
    var recognizing = false;
    var esc = HOD.esc;

    function $(id) { return document.getElementById(id); }
    function post(data, raw) { return HOD.api(API, { data: data, quiet: true, raw: raw }); }
    function fail(err, fallback) {
        HOD.toast(err && err.code === 'NETWORK' ? 'Network problem. Please try again.' : ((err && err.message) || fallback || 'Something went wrong.'), 'danger');
    }
    function store(v) { try { if (v) localStorage.setItem(LS_KEY, JSON.stringify(v)); else localStorage.removeItem(LS_KEY); } catch (e) { /* private mode */ } }
    function stored() { try { return JSON.parse(localStorage.getItem(LS_KEY) || 'null'); } catch (e) { return null; } }

    var pad = function (n) { return String(n).padStart(2, '0'); };
    var ymd = function (d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); };
    var today = function () { return ymd(new Date()); };
    function inDays(n) { var d = new Date(); d.setDate(d.getDate() + n); return ymd(d); }
    function nextSunday() { var d = new Date(); d.setDate(d.getDate() + ((7 - d.getDay()) % 7 || 7)); return ymd(d); }
    function niceDate(s) {
        if (!s) return '';
        var d = new Date(String(s) + 'T00:00:00');
        return isNaN(d) ? String(s) : d.toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short' });
    }

    /* ----- Signing in --------------------------------------------------- */

    function activate(v) {
        volunteer = v;
        var name = (v.name || '').trim();
        $('av-name').textContent = name || v.phone;
        $('av-initial').textContent = (name || '?').charAt(0).toUpperCase();
        $('av-step1').hidden = true;
        $('av-step2').hidden = false;
        loadPeople(true);
    }

    function signOut() {
        volunteer = null;
        store(null);
        $('av-step2').hidden = true;
        $('av-step1').hidden = false;
        $('av-match').hidden = true;
        $('av-nomatch').hidden = true;
        $('av-phone').value = '';
        $('av-phone').focus();
    }

    HOD.actions.on('av.lookup', function (form, event) {
        var phone = $('av-phone').value.trim();
        $('av-match').hidden = true;
        $('av-nomatch').hidden = true;
        if (phone.replace(/\D/g, '').length < 9) { HOD.toast('Enter your full phone number.', 'warning'); return; }
        var btn = (event && event.submitter) || form.querySelector('[type="submit"]');
        HOD.busy(btn, true);
        post({ action: 'check_volunteer', phone: phone }, true).then(function (res) {
            if (res.exists) {
                matchPayload = { phone: phone, name: res.name || '' };
                $('av-match-name').textContent = res.name || phone;
                $('av-match').hidden = false;
            } else {
                $('av-nomatch').hidden = false;
            }
        }).catch(function (err) { fail(err); }).then(function () { HOD.busy(btn, false); });
    });
    HOD.actions.on('av.confirm', function () { if (matchPayload) { store(matchPayload); activate(matchPayload); } });
    HOD.actions.on('av.reject', function () { $('av-match').hidden = true; $('av-phone').value = ''; $('av-phone').focus(); });
    HOD.actions.on('av.switch', signOut);

    /* ----- The lists ---------------------------------------------------- */

    function sparkline(trend) {
        if (!trend || !trend.length) return '';
        var max = Math.max.apply(null, [1].concat(trend));
        var w = 6, gap = 2, h = 22;
        return '<svg class="text-hodBlue" width="' + (trend.length * (w + gap) - gap) + '" height="' + h + '" fill="currentColor" role="img" aria-label="Attendance over the last 12 months: ' + esc(trend.join(', ')) + '">'
            + trend.map(function (v, i) {
                var bh = Math.max(2, Math.round(h * v / max));
                return '<rect x="' + (i * (w + gap)) + '" y="' + (h - bh) + '" width="' + w + '" height="' + bh + '" rx="1.5"></rect>';
            }).join('') + '</svg>';
    }
    function hasPhone(c) { return /\d/.test(String(c.phone || '')); }
    function dueBadge(c) {
        if (c.is_overdue) return '<span class="ui-badge ui-badge--danger">Overdue</span>';
        if (c.next_touch && c.next_touch <= today()) return '<span class="ui-badge ui-badge--warning">Due today</span>';
        if (!c.touches) return '<span class="ui-badge ui-badge--primary">Not called yet</span>';
        return '';
    }
    function contactButtons(c, compact) {
        if (!hasPhone(c)) return '<p class="flex items-center gap-2 text-sm text-ink-subtle">' + HOD.icon('phone') + 'No number on file</p>';
        return '<a class="ui-btn ui-btn--primary flex-1" href="tel:' + esc(c.phone) + '">' + HOD.icon('phone') + '<span>' + (compact ? 'Call' : 'Call ' + esc(c.phone)) + '</span></a>'
            + '<a class="ui-btn ui-btn--secondary' + (compact ? ' flex-1' : ' ui-btn--icon') + '" href="https://wa.me/' + esc(c.whatsapp || '') + '" target="_blank" rel="noopener" aria-label="WhatsApp ' + esc(c.first_name) + '">' + HOD.icon('message-circle') + (compact ? '<span>WhatsApp</span>' : '') + '</a>';
    }

    function personCard(c, inPool) {
        var note = c.last_note && c.last_note.note ? String(c.last_note.note).split('\n')[0] : '';
        var meta = [c.spiritual_status, c.departments].filter(Boolean).join(' · ');
        var prior = c.prior && c.prior.total
            ? 'Reached out ' + c.prior.total + '× · last ' + (c.prior.when || '') + ' by ' + (c.prior.by || 'a volunteer') + (c.prior.outcome ? ' (' + c.prior.outcome + ')' : '')
            : 'Nobody has reached out yet';
        return '<article class="ui-card ui-card__body space-y-3">'
            + '<div class="flex items-start justify-between gap-3"><div class="min-w-0">'
            + '<h3 class="font-semibold text-ink">' + esc(c.name) + '</h3>'
            + (hasPhone(c) && !inPool
                ? '<p class="flex items-center gap-2 text-sm text-ink-muted"><span>' + esc(c.phone) + '</span>'
                    + '<button type="button" class="ui-btn ui-btn--ghost ui-btn--sm" data-action="av.copy" data-phone="' + esc(c.phone) + '">' + HOD.icon('copy') + '<span>Copy</span></button></p>'
                : (c.phone ? '<p class="text-sm text-ink-muted">' + esc(c.phone) + '</p>' : ''))
            + '<p class="text-xs text-ink-subtle">' + esc(meta || 'Household of David') + '</p></div>'
            + (inPool ? '' : dueBadge(c)) + '</div>'
            + (inPool && c.watchlist ? '<p class="flex items-center gap-1 text-xs text-ink-subtle">' + HOD.icon('bell') + esc((c.watchlist_open ? 'Open list: ' : 'From ') + '“' + c.watchlist + '”') + '</p>' : '')
            + '<div class="space-y-1 text-sm text-ink-muted">'
            + '<p class="flex items-center gap-2">' + HOD.icon('calendar') + esc(c.last_attended ? 'Last in church ' + niceDate(c.last_attended) + ' · ' + c.since_words : 'No attendance on record') + '</p>'
            + '<p class="flex items-center gap-2">' + HOD.icon('phone') + esc(prior) + '</p></div>'
            + '<div><p class="mb-1 text-xs text-ink-subtle">Attendance, last 12 months</p>' + sparkline(c.trend) + '</div>'
            + (note ? '<p class="rounded-ui bg-surface-sunken p-2 text-sm italic text-ink-muted">' + esc(note) + '</p>' : '')
            + (c.sms_notes || []).map(function (n) {
                return '<div class="rounded-ui bg-surface-sunken p-2 text-sm"><p class="text-xs text-ink-subtle">SMS sent ' + esc(niceDate(String(n.sent_at || '').slice(0, 10))) + '</p><p class="text-ink-muted">' + esc(n.body) + '</p></div>';
            }).join('')
            + (c.next_touch && !inPool ? '<p class="text-xs text-ink-subtle">Next touch: ' + esc(niceDate(c.next_touch)) + '</p>' : '')
            + (inPool
                ? '<button type="button" class="ui-btn ui-btn--primary ui-btn--block" data-action="av.claim" data-id="' + esc(c.case_id) + '"><span>I’ll call ' + esc(c.first_name) + '</span></button>'
                : '<div class="flex gap-2">' + contactButtons(c, true)
                    + '<button type="button" class="ui-btn ui-btn--brand flex-1" data-action="av.open" data-id="' + esc(c.case_id) + '"><span>Log</span></button></div>')
            + '</article>';
    }

    function render() {
        $('av-mine').innerHTML = people.mine.length ? people.mine.map(function (c) { return personCard(c, false); }).join('')
            : '<div class="ui-card"><div class="ui-empty ui-empty--compact"><span class="ui-empty__icon">' + HOD.icon('circle-check') + '</span>'
                + '<p class="ui-empty__title">Nobody waiting on you</p><p class="ui-empty__text">All caught up. Keep praying for the ones you called.</p></div></div>';
        var pool = $('av-pool-section');
        if (pool) {
            pool.hidden = !people.pool.length;
            $('av-pool').innerHTML = people.pool.map(function (c) { return personCard(c, true); }).join('');
        }
    }

    function loadPeople(skeleton) {
        if (!volunteer) return Promise.resolve();
        if (skeleton) $('av-mine').innerHTML = '<div class="ui-skeleton ui-skeleton--block h-40"></div><div class="ui-skeleton ui-skeleton--block h-40"></div>';
        return post({ action: 'my_people', volunteer_phone: volunteer.phone }).then(function (res) {
            var d = res.data || {};
            people = { mine: d.mine || [], pool: d.pool || [] };
            $('av-today').textContent = d.done_today || 0;
            var photo = d.volunteer && d.volunteer.photo;
            if (photo && /^\/(?!\/)/.test(photo)) $('av-avatar').innerHTML = '<img src="' + esc(photo) + '" alt="">';
            var home = Number(d.brought_home || 0);
            $('av-home').hidden = home === 0;
            $('av-home-count').textContent = home;
            $('av-home-word').textContent = home === 1 ? 'person you called has come home.' : 'people you called have come home.';
            render();
        }).catch(function (err) {
            if (err.code === 'NETWORK') {
                $('av-mine').innerHTML = '<div class="ui-card"><div class="ui-empty ui-empty--compact"><p class="ui-empty__title">We couldn’t reach the server</p>'
                    + '<div class="ui-empty__actions"><button type="button" class="ui-btn ui-btn--secondary ui-btn--sm" data-action="av.reload"><span>Try again</span></button></div></div></div>';
                return;
            }
            fail(err, 'Could not load your list.');
            if (/sign in/i.test(err.message || '')) signOut();
        });
    }
    HOD.actions.on('av.reload', function () { loadPeople(true); });

    HOD.actions.on('av.claim', function (btn) {
        HOD.busy(btn, true);
        post({ action: 'claim_case', volunteer_phone: volunteer.phone, case_id: btn.getAttribute('data-id') }).then(function (res) {
            HOD.toast(res.message || 'They are yours to call.', 'success');
            loadPeople(false);
        }).catch(function (err) { fail(err); HOD.busy(btn, false); });
    });

    HOD.actions.on('av.copy', function (btn) {
        var phone = btn.getAttribute('data-phone') || '';
        var done = function () { HOD.toast('Number copied. Open your Phone app and paste it.', 'success'); };
        var legacy = function () {
            var ta = document.createElement('textarea');
            ta.value = phone;
            ta.setAttribute('readonly', '');
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.select();
            var ok = false;
            try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
            ta.remove();
            if (ok) done(); else HOD.toast('Could not copy. Press and hold the number to copy it.', 'warning');
        };
        if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(phone).then(done, legacy);
        else legacy();
    });

    /* ----- The follow-up sheet ----------------------------------------- */

    function chip(group, value, label, on) {
        return '<button type="button" class="ui-chip" role="radio" aria-checked="' + (on ? 'true' : 'false') + '" data-action="av.pick" data-group="' + group + '" data-value="' + esc(value) + '">' + esc(label) + '</button>';
    }

    function sheetBody(c) {
        var seen = {};
        var quick = [['Tomorrow', inDays(1)], ['In 3 days', inDays(3)], ['Next Sunday', nextSunday()], ['In a week', inDays(7)]]
            .filter(function (q) { if (seen[q[1]]) return false; seen[q[1]] = true; return true; });
        return '<section class="space-y-3 rounded-ui bg-surface-sunken p-4">'
            + '<p class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">Before you call</p>'
            + '<p class="text-sm text-ink-muted">Pray for ' + esc(c.first_name) + ' by name. Lead with love, not attendance.</p>'
            + sparkline(c.trend)
            + '<div><button type="button" class="ui-btn ui-btn--soft ui-btn--sm" id="av-line-btn" data-action="av.line">' + HOD.icon('sparkles') + '<span>Suggest an opener</span></button>'
            + '<p class="mt-2 text-sm italic text-ink" id="av-line" hidden></p></div>'
            + '<div class="flex gap-2">' + contactButtons(c, false)
            + (hasPhone(c) ? '<button type="button" class="ui-btn ui-btn--secondary ui-btn--icon" data-action="av.copy" data-phone="' + esc(c.phone) + '" aria-label="Copy ' + esc(c.phone) + '">' + HOD.icon('copy') + '</button>' : '')
            + '</div></section>'
            + '<fieldset><legend class="ui-label mb-2" id="av-chan-label">How did you reach out?</legend>'
            + '<div class="ui-chips" role="radiogroup" aria-labelledby="av-chan-label">'
            + Object.keys(CHANNELS).map(function (k) { return chip('channel', k, CHANNELS[k], k === sheet.channel); }).join('') + '</div></fieldset>'
            + '<fieldset><legend class="ui-label mb-2" id="av-out-label">How did it go?<span class="ui-label__req" aria-hidden="true">*</span></legend>'
            + '<div class="ui-chips" role="radiogroup" aria-labelledby="av-out-label">'
            + Object.keys(OUTCOMES).map(function (k) { return chip('outcome', k, OUTCOMES[k], false); }).join('') + '</div></fieldset>'
            + '<div class="ui-banner ui-banner--warning" id="av-closing" hidden>' + HOD.icon('triangle-alert')
            + '<div class="ui-banner__body"><p class="ui-banner__text">Saving this closes the follow-up and tells your leaders.</p></div></div>'
            + '<div class="ui-field"><div class="flex items-center justify-between gap-2"><label class="ui-label" for="av-notes">Notes</label>'
            + '<div class="flex gap-1"><button type="button" class="ui-btn ui-btn--soft ui-btn--sm ui-btn--icon" id="av-mic" data-action="av.mic" aria-pressed="false" aria-label="Dictate the notes">' + HOD.icon('mic-vocal') + '</button>'
            + '<button type="button" class="ui-btn ui-btn--ghost ui-btn--sm" id="av-clean-btn" data-action="av.clean">' + HOD.icon('sparkles') + '<span>Tidy with AI</span></button></div></div>'
            + '<textarea class="ui-textarea" id="av-notes" rows="4" placeholder="What did they say? What are they carrying?"></textarea>'
            + '<p class="ui-hint" id="av-mic-hint" hidden>Listening… tap the mic to stop.</p></div>'
            + '<div class="ui-banner ui-banner--info" id="av-clean-box" hidden>' + HOD.icon('sparkles')
            + '<div class="ui-banner__body"><p class="ui-banner__title">Tidied. Keep it?</p><p class="ui-banner__text whitespace-pre-line" id="av-clean-text"></p>'
            + '<div class="ui-banner__actions"><button type="button" class="ui-btn ui-btn--primary ui-btn--sm" data-action="av.clean-keep"><span>Use this</span></button>'
            + '<button type="button" class="ui-btn ui-btn--ghost ui-btn--sm" data-action="av.clean-drop"><span>Keep mine</span></button></div></div></div>'
            + '<div class="ui-field"><label class="ui-label" for="av-prayer">Prayer points</label>'
            + '<textarea class="ui-textarea" id="av-prayer" rows="2" placeholder="What can the team stand with them for?"></textarea></div>'
            + '<fieldset><legend class="ui-label mb-2" id="av-next-label">When will you check on them again?</legend>'
            + '<div class="ui-chips mb-2" role="group" aria-labelledby="av-next-label">'
            + quick.map(function (q) { return '<button type="button" class="ui-chip" aria-pressed="false" data-action="av.pick" data-group="next" data-value="' + q[1] + '">' + q[0] + '</button>'; }).join('') + '</div>'
            + '<label class="sr-only" for="av-next">Next touch date</label><input class="ui-input" type="date" id="av-next" min="' + today() + '" data-action-change="av.next-date"></fieldset>'
            + '<button type="button" class="ui-btn ui-btn--brand ui-btn--lg ui-btn--block" id="av-save" data-action="av.save">' + HOD.icon('check') + '<span>Save follow-up</span></button>'
            + '<p class="text-center text-xs text-ink-subtle">Only your leaders and this team can see what you write.</p>';
    }

    function syncChips() {
        Array.prototype.forEach.call(document.querySelectorAll('#av-log-body [data-action="av.pick"]'), function (b) {
            var group = b.getAttribute('data-group'), v = b.getAttribute('data-value');
            var on = (group === 'channel' && v === sheet.channel) || (group === 'outcome' && v === sheet.outcome) || (group === 'next' && v === sheet.next);
            b.setAttribute(group === 'next' ? 'aria-pressed' : 'aria-checked', on ? 'true' : 'false');
        });
        $('av-closing').hidden = CLOSING.indexOf(sheet.outcome) === -1;
    }

    HOD.actions.on('av.open', function (btn) {
        var id = Number(btn.getAttribute('data-id'));
        var c = people.mine.filter(function (x) { return Number(x.case_id) === id; })[0];
        if (!c) return;
        sheet = { caseId: id, channel: 'Call', outcome: '', next: '', clean: '', keep: false, first: c.first_name };
        $('av-log-title').textContent = c.name;
        $('av-log-desc').textContent = c.status_words + ' · last in church ' + c.since_words;
        $('av-log-body').innerHTML = sheetBody(c);
        syncChips();
        HOD.dialog.open('av-log', btn);
    });

    HOD.actions.on('av.pick', function (btn) {
        var group = btn.getAttribute('data-group'), v = btn.getAttribute('data-value');
        if (group === 'channel') sheet.channel = v;
        if (group === 'outcome') sheet.outcome = v;
        if (group === 'next') { sheet.next = v; $('av-next').value = v; }
        syncChips();
    });
    HOD.actions.on('av.next-date', function (input) { sheet.next = input.value; syncChips(); });

    HOD.actions.on('av.line', function (btn) {
        HOD.busy(btn, true);
        post({ action: 'opening_line', volunteer_phone: volunteer.phone, case_id: sheet.caseId }).then(function (res) {
            var out = $('av-line');
            out.textContent = '“' + ((res.data && res.data.line) || '') + '”';
            out.hidden = false;
            var spans = btn.querySelectorAll('span:not(.ui-btn__spinner)');
            if (spans.length) spans[spans.length - 1].textContent = 'Another opener';
        }).catch(function (err) { fail(err); }).then(function () { HOD.busy(btn, false); });
    });

    /* ----- Voice and Tidy ---------------------------------------------- */

    function micUi(on) {
        var hint = $('av-mic-hint'), mic = $('av-mic');
        if (hint) hint.hidden = !on;
        if (mic) {
            mic.setAttribute('aria-pressed', on ? 'true' : 'false');
            mic.classList.toggle('ui-btn--danger', on);
            mic.classList.toggle('animate-pulse', on);
            mic.classList.toggle('ui-btn--soft', !on);
        }
    }
    function speech() {
        var SR = window.SpeechRecognition || window.webkitSpeechRecognition;
        if (!SR) return null;
        var r = new SR();
        r.lang = 'en-NG';
        r.interimResults = false;
        r.continuous = true;
        r.onresult = function (evt) {
            var text = '';
            for (var i = evt.resultIndex; i < evt.results.length; i++) {
                if (evt.results[i].isFinal) text += evt.results[i][0].transcript;
            }
            text = text.trim();
            if (!text) return;
            var box = $('av-notes');
            if (box) box.value = box.value ? box.value.replace(/\s*$/, '') + ' ' + text : text;
        };
        r.onend = function () { recognizing = false; micUi(false); };
        r.onerror = function () { recognizing = false; micUi(false); HOD.toast('Microphone problem. Type instead.', 'warning'); };
        return r;
    }
    HOD.actions.on('av.mic', function () {
        if (!recognition) recognition = speech();
        if (!recognition) { HOD.toast('Voice needs Chrome. Type instead.', 'warning'); return; }
        if (recognizing) { try { recognition.stop(); } catch (e) { /* stopped */ } return; }
        try { recognition.start(); recognizing = true; micUi(true); } catch (e) { HOD.toast('The microphone is blocked.', 'danger'); }
    });
    document.addEventListener('ui:dialog-close', function (e) {
        if (e.target && e.target.id === 'av-log' && recognizing && recognition) {
            try { recognition.stop(); } catch (err) { /* stopped */ }
        }
    });

    HOD.actions.on('av.clean', function (btn) {
        var notes = ($('av-notes').value || '').trim();
        if (!notes) { HOD.toast('Write or speak the notes first.', 'warning'); $('av-notes').focus(); return; }
        HOD.busy(btn, true);
        post({ action: 'clean_notes', volunteer_phone: volunteer.phone, notes: notes }).then(function (res) {
            sheet.clean = (res.data && res.data.notes_clean) || '';
            $('av-clean-text').textContent = sheet.clean;
            $('av-clean-box').hidden = !sheet.clean;
        }).catch(function (err) {
            HOD.toast(err.code === 'NETWORK' ? 'Network problem. Your notes are safe.' : err.message, 'warning');
        }).then(function () { HOD.busy(btn, false); });
    });
    HOD.actions.on('av.clean-keep', function () {
        sheet.keep = true;
        $('av-clean-box').hidden = true;
        HOD.toast('The tidied version is saved beside your own words.', 'info');
    });
    HOD.actions.on('av.clean-drop', function () { sheet.keep = false; sheet.clean = ''; $('av-clean-box').hidden = true; });

    /* ----- Saving -------------------------------------------------------- */

    HOD.actions.on('av.save', function (btn) {
        if (btn.disabled) return;
        if (!sheet.outcome) { HOD.toast('Tell us how it went first.', 'warning'); return; }
        HOD.busy(btn, true);
        var first = sheet.first || 'They';
        post({
            action: 'log_follow_up',
            volunteer_phone: volunteer.phone,
            case_id: sheet.caseId,
            channel: sheet.channel,
            outcome: sheet.outcome,
            notes: ($('av-notes').value || '').trim(),
            notes_clean: sheet.keep ? sheet.clean : '',
            prayer_points: ($('av-prayer').value || '').trim(),
            next_touch_date: sheet.next || ''
        }).then(function (res) {
            var d = res.data || {};
            HOD.dialog.close('av-log');
            if (d.returned_home) {
                $('av-celebrate-title').textContent = first + ' came home';
                $('av-celebrate-body').textContent = 'They have been back in the house since you reached out. Your leaders have been told. Welcome them well this Sunday.';
                HOD.dialog.open('av-celebrate');
            } else if (d.closed) {
                HOD.toast('Saved: ' + String(d.status_words || '').toLowerCase() + '. Thank you for closing it kindly.', 'info');
            } else {
                HOD.toast('Saved. Thank you for going after them.', 'success');
            }
            loadPeople(false);
        }).catch(function (err) {
            HOD.toast(err.code === 'NETWORK' ? 'No connection. Nothing was lost; tap Save again when you have signal.' : (err.message || 'Could not save.'), 'danger');
        }).then(function () { HOD.busy(btn, false); });
    });

    /* ------------------------------------------------------------------- */

    function init() {
        var cached = stored();
        if (cached && cached.phone) {
            post({ action: 'check_volunteer', phone: cached.phone }, true).then(function (res) {
                if (res.exists) activate({ phone: cached.phone, name: res.name || '' });
                else store(null);
            }).catch(function () { /* stays on step 1 */ });
        }
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
