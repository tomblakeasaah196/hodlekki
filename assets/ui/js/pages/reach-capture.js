// /assets/ui/js/pages/reach-capture.js
// Reach capture (reach.php?c=<slug>): the volunteer's phone check (kept on
// this phone in localStorage, re-checked on every visit), the lead form with
// the spiritual-state chips and duplicate warning, and voice capture
// (speech → api/reach_public_api.php extract_from_voice → prefilled form).
// Every call goes to /api/reach_public_api.php as before.
(function () {
    'use strict';

    var HOD = window.HOD;
    var API = '/api/reach_public_api.php';
    var cfg = HOD.data('reach-config') || {};
    var LS_KEY = 'reach_volunteer_v1';
    var CATEGORIES = [
        ['New_Convert', 'New Convert'], ['Unsaved', 'Unsaved'], ['Saved', 'Saved'],
        ['Broken', 'Broken'], ['Dechurched', 'Dechurched'], ['Other', 'Other']
    ];

    var volunteer = null;
    var recent = [];
    var count = 0;
    var selected = {};
    var recognition = null;
    var recognizing = false;
    var matchPayload = null;

    function $(id) { return document.getElementById(id); }
    function post(data, raw) { return HOD.api(API, { data: data, quiet: true, raw: raw }); }
    function netError() { HOD.toast('Network problem. Please try again.', 'danger'); }

    /* ----- Volunteer --------------------------------------------------- */

    function store(v) { try { if (v) localStorage.setItem(LS_KEY, JSON.stringify(v)); else localStorage.removeItem(LS_KEY); } catch (e) { /* private mode */ } }
    function stored() { try { return JSON.parse(localStorage.getItem(LS_KEY) || 'null'); } catch (e) { return null; } }

    function activate(v) {
        volunteer = v;
        var name = (v.name || '').trim();
        $('reach-volunteer-name').textContent = name || v.phone;
        $('reach-volunteer-initial').textContent = (name || '?').charAt(0).toUpperCase();
        $('reach-volunteer').hidden = false;
        $('reach-step1').hidden = true;
        $('reach-step2').hidden = false;
        $('reach-first').focus();
    }

    HOD.actions.on('reach.lookup', function (form, event) {
        var phone = $('reach-vol-phone').value.trim();
        $('reach-vol-match').hidden = true;
        $('reach-vol-nomatch').hidden = true;
        if (phone.replace(/\D/g, '').length < 9) { HOD.toast('Enter your full phone number.', 'warning'); return; }
        var btn = (event && event.submitter) || form.querySelector('[type="submit"]');
        HOD.busy(btn, true);
        post({ action: 'check_volunteer', phone: phone }, true).then(function (res) {
            if (res.exists) {
                matchPayload = { phone: phone, name: res.name || '' };
                $('reach-vol-match-name').textContent = res.name || phone;
                $('reach-vol-match').hidden = false;
            } else {
                $('reach-vol-nomatch').hidden = false;
            }
        }).catch(netError).then(function () { HOD.busy(btn, false); });
    });

    HOD.actions.on('reach.confirm', function () {
        if (!matchPayload) return;
        store(matchPayload);
        activate(matchPayload);
    });
    HOD.actions.on('reach.reject', function () {
        $('reach-vol-match').hidden = true;
        $('reach-vol-phone').value = '';
        $('reach-vol-phone').focus();
    });
    HOD.actions.on('reach.switch', function () {
        volunteer = null;
        store(null);
        $('reach-volunteer').hidden = true;
        $('reach-step2').hidden = true;
        $('reach-step1').hidden = false;
        $('reach-vol-match').hidden = true;
        $('reach-vol-nomatch').hidden = true;
        $('reach-vol-phone').value = '';
        $('reach-vol-phone').focus();
    });

    /* ----- Categories --------------------------------------------------- */

    function renderCategories() {
        $('reach-categories').innerHTML = CATEGORIES.map(function (c) {
            return '<button type="button" class="ui-chip" data-action="reach.category" data-v="' + c[0] + '" aria-pressed="false">' + HOD.esc(c[1]) + '</button>';
        }).join('');
    }
    function setCategories(list) {
        selected = {};
        list.forEach(function (v) { selected[v] = true; });
        var vals = CATEGORIES.map(function (c) { return c[0]; }).filter(function (v) { return selected[v]; });
        $('reach-category').value = vals.join(',') || 'Other';
        Array.prototype.forEach.call($('reach-categories').children, function (b) {
            b.setAttribute('aria-pressed', selected[b.getAttribute('data-v')] ? 'true' : 'false');
        });
    }
    HOD.actions.on('reach.category', function (btn) {
        var v = btn.getAttribute('data-v');
        if (selected[v]) {
            delete selected[v];
        } else if (v === 'Other') {
            selected = { Other: true };
        } else {
            delete selected.Other;
            if (v === 'Unsaved') { delete selected.Saved; delete selected.New_Convert; }
            if (v === 'Saved' || v === 'New_Convert') delete selected.Unsaved;
            selected[v] = true;
        }
        setCategories(Object.keys(selected));
    });

    /* ----- Duplicate check ---------------------------------------------- */

    function checkDup() {
        var phone = $('reach-phone').value.trim();
        var box = $('reach-dup');
        box.hidden = true;
        box.innerHTML = '';
        if (!volunteer || phone.replace(/\D/g, '').length < 9) return;
        post({ action: 'check_existing_lead', phone: phone, campaign_id: cfg.campaignId, volunteer_phone: volunteer.phone }, true).then(function (res) {
            var parts = [];
            if (res.is_existing_member && res.member_name) {
                parts.push('<strong>' + HOD.esc(res.member_name) + '</strong> is already part of our church family.');
            }
            if (res.duplicate) {
                var line = 'Already captured in this campaign';
                if (res.prior_capturer_name) line += ' by <strong>' + HOD.esc(res.prior_capturer_name) + '</strong>';
                if (res.prior_capture_date) line += ' on ' + HOD.esc(String(res.prior_capture_date).substring(0, 10));
                parts.push(line + '. Saving merges onto the existing record.');
            }
            if (parts.length) {
                box.innerHTML = HOD.icon('info') + '<div class="ui-banner__body"><p class="ui-banner__text">' + parts.join('<br>') + '</p></div>';
                box.hidden = false;
            }
        }).catch(function () { /* the warning is a nicety */ });
    }
    HOD.actions.on('reach.dup', checkDup);

    /* ----- Saving -------------------------------------------------------- */

    function rememberRecent(name, category) {
        recent.unshift({ name: name, category: category });
        recent = recent.slice(0, 3);
        $('reach-recent').innerHTML = recent.map(function (r) {
            return '<span class="ui-chip">' + HOD.esc(r.name) + ' <span class="text-ink-subtle">' + HOD.esc(r.category.replace(/_/g, ' ').replace(/,/g, ' · ')) + '</span></span>';
        }).join('');
    }

    HOD.actions.on('reach.save', function (form, event) {
        if (!volunteer) { HOD.toast('Please sign in first.', 'danger'); return; }
        var btn = (event && event.submitter) || $('reach-save');
        if (btn.disabled) return;
        HOD.busy(btn, true);
        var name = ($('reach-first').value.trim() + ' ' + $('reach-last').value.trim()).trim();
        var category = $('reach-category').value || 'Other';
        var fd = new FormData(form);
        fd.append('volunteer_phone', volunteer.phone);
        post(fd).then(function (res) {
            count++;
            $('reach-count').textContent = count;
            rememberRecent(name || 'Someone', category);
            HOD.toast(res.data && res.data.merged ? 'Saved, merged with an earlier capture.' : 'Soul #' + count + ' saved.', 'success');
            form.reset();
            $('reach-dup').hidden = true;
            setCategories([]);
            $('reach-first').focus();
        }).catch(function (err) {
            HOD.toast(err.code === 'NETWORK' ? 'Network problem. Not saved; try again.' : (err.message || 'Could not save.'), 'danger');
        }).then(function () { HOD.busy(btn, false); });
    });

    /* ----- Voice ---------------------------------------------------------- */

    function speech() {
        var SR = window.SpeechRecognition || window.webkitSpeechRecognition;
        if (!SR) return null;
        var r = new SR();
        r.lang = 'en-NG';
        r.interimResults = false;
        r.maxAlternatives = 1;
        r.onresult = function (evt) {
            var t = evt.results[0][0].transcript;
            var box = $('reach-voice-text');
            box.value = box.value ? box.value + ' ' + t : t;
        };
        r.onend = function () { recognizing = false; recUi(false, 'Tap to speak'); };
        r.onerror = function () { recognizing = false; recUi(false, 'Mic error. Try again.'); };
        return r;
    }
    function recUi(on, status) {
        var b = $('reach-voice-rec');
        b.setAttribute('aria-pressed', on ? 'true' : 'false');
        b.classList.toggle('bg-hodRed', on);
        b.classList.toggle('animate-pulse', on);
        b.classList.toggle('bg-hodBlue', !on);
        if (status) $('reach-voice-status').textContent = status;
    }

    HOD.actions.on('reach.voice', function (btn) {
        if (!volunteer) { HOD.toast('Please sign in first.', 'warning'); return; }
        if (!recognition) recognition = speech();
        $('reach-voice-text').value = '';
        recUi(false, 'Tap to speak');
        HOD.dialog.open('reach-voice-dialog', btn);
    });
    HOD.actions.on('reach.voice-rec', function () {
        if (!recognition) { HOD.toast('Voice needs Chrome. Type instead.', 'warning'); return; }
        if (recognizing) { try { recognition.stop(); } catch (e) { /* stopped */ } return; }
        try {
            recognition.start();
            recognizing = true;
            recUi(true, 'Listening…');
        } catch (e) {
            HOD.toast('The microphone is blocked.', 'danger');
        }
    });
    document.addEventListener('ui:dialog-close', function (e) {
        if (e.target && e.target.id === 'reach-voice-dialog' && recognizing && recognition) {
            try { recognition.stop(); } catch (err) { /* stopped */ }
        }
    });

    HOD.actions.on('reach.voice-apply', function (btn) {
        var transcript = $('reach-voice-text').value.trim();
        if (!transcript) { HOD.toast('Say something first.', 'warning'); return; }
        HOD.busy(btn, true);
        $('reach-voice-status').textContent = 'Thinking…';
        post({ action: 'extract_from_voice', transcript: transcript, volunteer_phone: volunteer.phone }).then(function (res) {
            var d = res.data || {};
            if (d.first_name) $('reach-first').value = d.first_name;
            if (d.last_name) $('reach-last').value = d.last_name;
            if (d.phone) $('reach-phone').value = d.phone;
            if (d.category && d.category !== 'Other') setCategories([d.category]);
            var form = $('reach-lead-form');
            form.elements.willing_for_visit.checked = !!d.willing_for_visit;
            if (d.will_attend_church) form.elements.will_attend_church.checked = true;
            if (d.notes && $('reach-notes')) $('reach-notes').value = d.notes;
            HOD.dialog.close('reach-voice-dialog');
            HOD.toast('Prefilled. Check it, then save.', 'success');
            checkDup();
        }).catch(function (err) {
            $('reach-voice-status').textContent = 'Tap to speak';
            HOD.toast(err.code === 'NETWORK' ? 'Network problem. Please try again.' : (err.message || 'Could not understand that.'), 'danger');
        }).then(function () { HOD.busy(btn, false); });
    });

    /* ------------------------------------------------------------------- */

    function init() {
        renderCategories();
        setCategories([]);
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
