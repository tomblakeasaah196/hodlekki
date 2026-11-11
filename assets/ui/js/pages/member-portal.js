// /assets/ui/js/pages/member-portal.js
// My Portal (modules/member_portal/index.php): the member's giving for a
// chosen period, recent attendance, church broadcasts and upcoming events,
// plus three forms: record giving, send a prayer request, share a testimony
// (text and/or a voice note). Request shapes are unchanged:
// /api/member_portal_api.php and /api/testimony_public_api.php.
(function () {
    'use strict';

    var HOD = window.HOD;
    var API = '/api/member_portal_api.php';
    var TESTIMONY_API = '/api/testimony_public_api.php';
    var RECEIPT_MAX = 2 * 1024 * 1024;

    function $(sel, root) { return (root || document).querySelector(sel); }
    function label(s) { return String(s || '').replace(/_/g, ' '); }
    /** Announcement bodies may hold markup from the editor: show them as plain text. */
    function plain(html) {
        var doc = new DOMParser().parseFromString(String(html || ''), 'text/html');
        return (doc.body.textContent || '').replace(/\s+\n/g, '\n').trim();
    }
    function emptyNote(text) { return '<p class="ui-meta py-1">' + HOD.esc(text) + '</p>'; }

    /* ---------- dashboard ---------- */

    function render(res) {
        var u = res.user_info || {};
        var badges = '';
        if (u.spiritual_status) badges += '<span class="ui-badge ui-badge--primary">' + HOD.esc(label(u.spiritual_status)) + '</span>';
        if (u.tribe_name) badges += '<span class="ui-badge">Tribe: ' + HOD.esc(u.tribe_name) + '</span>';
        if (u.departments) {
            String(u.departments).split(',').forEach(function (d) {
                if (d.trim()) badges += '<span class="ui-badge ui-badge--outline">' + HOD.esc(d.trim()) + '</span>';
            });
        }
        $('[data-portal-badges]').innerHTML = badges;

        var fin = res.financials || {};
        $('[data-portal-total]').textContent = HOD.format.naira(fin.total);
        var byType = {};
        (fin.breakdown || []).forEach(function (row) { byType[row.contribution_type] = row.total_amount; });
        document.querySelectorAll('[data-portal-type]').forEach(function (el) {
            el.textContent = HOD.format.naira(byType[el.getAttribute('data-portal-type')] || 0);
        });
        var finMsg = $('[data-portal-finance-message]');
        finMsg.textContent = fin.message || '';
        finMsg.hidden = !fin.message;

        var att = res.attendance || {};
        var rows = (att.history || []).map(function (a) {
            var present = a.status === 'Present';
            return '<tr>'
                + '<td data-label="Date" class="whitespace-nowrap text-ink-muted">' + HOD.esc(HOD.format.date(a.event_date) || a.event_date) + '</td>'
                + '<td data-label="Event" class="font-medium text-ink">' + HOD.esc(a.title) + '</td>'
                + '<td data-label="Status" class="text-right"><span class="ui-badge ' + (present ? 'ui-badge--success' : 'ui-badge--danger') + '">' + (present ? 'Present' : 'Absent') + '</span></td>'
                + '</tr>';
        });
        $('[data-portal-attendance]').innerHTML = rows.length ? rows.join('')
            : '<tr><td colspan="3" class="py-6 text-center text-ink-subtle">No attendance recorded yet.</td></tr>';
        var attWrap = $('[data-portal-attendance-message]');
        $('.ui-quote', attWrap).textContent = att.message || '';
        attWrap.hidden = !att.message;

        var news = (res.announcements || []).map(function (a) {
            return '<article class="py-2 first:pt-0 last:pb-0">'
                + '<h3 class="text-sm font-semibold text-ink">' + HOD.esc(a.title) + '</h3>'
                + '<p class="mt-1 line-clamp-4 whitespace-pre-line text-sm text-ink-muted">' + HOD.esc(plain(a.content)) + '</p>'
                + '</article>';
        });
        $('[data-portal-broadcasts]').innerHTML = news.length
            ? '<div class="divide-y divide-line">' + news.join('') + '</div>'
            : emptyNote('No broadcasts right now.');

        var events = (res.upcoming_events || []).map(function (e) {
            return '<li class="ui-list__item">'
                + '<span class="ui-list__icon">' + HOD.icon('calendar') + '</span>'
                + '<span class="ui-list__main"><span class="ui-list__title">' + HOD.esc(e.title) + '</span>'
                + '<span class="ui-list__meta"><span class="ui-num">' + HOD.esc(HOD.format.date(e.event_date)) + '</span> · ' + HOD.esc(label(e.event_category)) + '</span></span>'
                + '</li>';
        });
        $('[data-portal-events]').innerHTML = events.length ? events.join('')
            : '<li class="ui-list__item">' + emptyNote('No upcoming events yet.') + '</li>';
    }

    var loading = null;
    function load() {
        var period = ($('#portal-period').value || '|').split('|');
        if (loading) loading.abort();
        loading = new AbortController();
        $('[data-portal-total]').setAttribute('aria-busy', 'true');
        HOD.api(API, { method: 'GET', action: 'fetch_dashboard', data: { start_date: period[0], end_date: period[1] }, signal: loading.signal, quiet: true })
            .then(render)
            .catch(function (err) {
                if (err && err.name === 'AbortError') return;
                HOD.toast(err.message || 'Your portal did not load. Reload the page to try again.', 'danger');
            })
            .finally(function () { $('[data-portal-total]').removeAttribute('aria-busy'); });
    }

    /* ---------- forms ---------- */

    function submit(form, url, data, done) {
        var button = document.querySelector('button[form="' + form.id + '"]');
        HOD.busy(button, true);
        return HOD.api(url, { data: data }).then(function (res) {
            HOD.toast(res.message || 'Sent.', 'success');
            if (done) done(res);
        }).catch(function () { /* HOD.api already showed the message */ })
            .finally(function () { HOD.busy(button, false); });
    }

    function give(form) {
        var file = form.receipt.files[0];
        if (file && file.size > RECEIPT_MAX) {
            HOD.form.setErrors(form, { receipt: 'That file is over 2 MB. Choose a smaller photo or PDF.' });
            return;
        }
        submit(form, API, new FormData(form), function () {
            HOD.dialog.close('portal-give');
            form.reset();
            load();
        });
    }

    function pray(form) {
        submit(form, API, new FormData(form), function () {
            HOD.dialog.close('portal-prayer');
            form.reset();
        });
    }

    /* ---------- testimony + voice note ---------- */

    var rec = { recorder: null, stream: null, chunks: [], blob: null, timer: null, seconds: 0, url: null };

    function setPreview(src) {
        if (rec.url) URL.revokeObjectURL(rec.url);
        rec.url = src;
        var audio = $('[data-portal-audio]');
        if (src) audio.src = src; else audio.removeAttribute('src');
        $('[data-portal-preview]').hidden = !src;
        $('[data-portal-testimony-error]').textContent = '';
    }

    function showRecording(on) {
        $('[data-portal-record]').hidden = on;
        $('[data-portal-stop]').hidden = !on;
        $('[data-portal-recording]').hidden = !on;
    }

    function tick() {
        rec.seconds++;
        var m = String(Math.floor(rec.seconds / 60)).padStart(2, '0');
        var s = String(rec.seconds % 60).padStart(2, '0');
        $('[data-portal-timer]').textContent = m + ':' + s;
    }

    function startRecording() {
        if (!navigator.mediaDevices || !window.MediaRecorder) {
            HOD.toast('This browser cannot record audio. Upload a voice note instead.', 'warning');
            return;
        }
        navigator.mediaDevices.getUserMedia({ audio: true }).then(function (stream) {
            rec.stream = stream;
            rec.chunks = [];
            rec.recorder = new MediaRecorder(stream);
            rec.recorder.addEventListener('dataavailable', function (e) { if (e.data && e.data.size) rec.chunks.push(e.data); });
            rec.recorder.addEventListener('stop', function () {
                stream.getTracks().forEach(function (t) { t.stop(); });
                rec.stream = null;
                if (!rec.chunks.length) return;
                rec.blob = new Blob(rec.chunks, { type: rec.recorder.mimeType || 'audio/webm' });
                $('#portal-audio-file').value = '';
                setPreview(URL.createObjectURL(rec.blob));
            });
            rec.recorder.start();
            rec.seconds = 0;
            $('[data-portal-timer]').textContent = '00:00';
            rec.timer = setInterval(tick, 1000);
            showRecording(true);
        }).catch(function () {
            HOD.toast('Allow microphone access in your browser to record, or upload a voice note instead.', 'warning');
        });
    }

    function stopRecording() {
        clearInterval(rec.timer);
        rec.timer = null;
        showRecording(false);
        if (rec.recorder && rec.recorder.state !== 'inactive') rec.recorder.stop();
    }

    function clearAudio() {
        if (rec.recorder && rec.recorder.state !== 'inactive') { rec.chunks = []; stopRecording(); }
        rec.blob = null;
        rec.chunks = [];
        $('#portal-audio-file').value = '';
        setPreview(null);
    }

    function pickAudio(input) {
        var file = input.files[0];
        if (!file) return;
        rec.blob = null;
        setPreview(URL.createObjectURL(file));
    }

    function testify(form) {
        if (rec.recorder && rec.recorder.state === 'recording') {
            $('[data-portal-testimony-error]').textContent = 'Stop the recording first, then send.';
            return;
        }
        var text = form.content_text.value.trim();
        var fileInput = $('#portal-audio-file');
        var hasAudio = !!rec.blob || fileInput.files.length > 0;
        if (!text && !hasAudio) {
            $('[data-portal-testimony-error]').textContent = 'Write your testimony or add a voice note.';
            form.content_text.focus();
            return;
        }
        var data = new FormData(form);
        if (rec.blob) data.append('voice_note', rec.blob, 'voicenote.webm');
        else if (fileInput.files[0]) data.append('voice_note', fileInput.files[0]);
        submit(form, TESTIMONY_API, data, function () {
            HOD.dialog.close('portal-testimony');
            form.reset();
            clearAudio();
        });
    }

    /* ---------- wire up ---------- */

    function init() {
        HOD.actions.on('portal.period', load);
        HOD.actions.on('portal.give', give);
        HOD.actions.on('portal.prayer', pray);
        HOD.actions.on('portal.testimony', testify);
        HOD.actions.on('portal.record', startRecording);
        HOD.actions.on('portal.stop', stopRecording);
        HOD.actions.on('portal.clear-audio', clearAudio);
        HOD.actions.on('portal.audio-file', pickAudio);

        // Closing the testimony sheet mid-recording releases the microphone.
        $('#portal-testimony').addEventListener('ui:dialog-close', function () {
            if (rec.recorder && rec.recorder.state === 'recording') stopRecording();
        });

        load();
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
