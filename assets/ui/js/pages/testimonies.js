// /assets/ui/js/pages/testimonies.js
// The Wall of Praise (testimonies.php): the approved testimonies, Hallelujahs,
// comments, and the share form with an in-browser voice recording.
// Every call goes to /api/testimony_public_api.php as before. The API stores
// testimony and comment text already HTML-escaped (htmlspecialchars on the
// way in) and escapes display names on the way out, so those fields are
// inserted as they come, exactly like the old page; everything else is
// escaped here.
(function () {
    'use strict';

    var HOD = window.HOD;
    var API = '/api/testimony_public_api.php';
    var esc = HOD.esc;
    var recorder = null;
    var chunks = [];
    var recorded = null;
    var timer = null;
    var seconds = 0;

    function $(id) { return document.getElementById(id); }
    function safeUrl(u) { u = String(u || '').trim(); return /^\/(?!\/)/.test(u) ? u : ''; }

    /* ----- The wall ------------------------------------------------------ */

    function card(t) {
        var audio = safeUrl(t.voice_note_url);
        return '<article class="ui-card ui-card__body flex flex-col gap-3">'
            + '<div class="flex items-center gap-3">'
            + '<span class="ui-avatar ui-avatar--c3"><span aria-hidden="true">' + esc(t.avatar_initials) + '</span></span>'
            + '<div class="min-w-0"><h2 class="truncate font-semibold text-ink">' + t.display_name + '</h2>'
            + '<p class="text-xs text-ink-subtle">' + esc(t.nice_date) + '</p></div></div>'
            + '<div class="flex-1 text-sm leading-relaxed text-ink-muted">'
            + (t.content_text ? String(t.content_text).replace(/\n/g, '<br>') : '<span class="italic">Audio testimony.</span>') + '</div>'
            + (audio ? '<audio class="w-full" controls preload="none" src="' + esc(audio) + '"></audio>' : '')
            + '<div class="flex flex-wrap gap-2 border-t border-line pt-3">'
            + '<button type="button" class="ui-btn ui-btn--soft ui-btn--sm" data-action="tm.hallelujah" data-id="' + esc(t.id) + '">'
            + HOD.icon('sparkles') + '<span><span data-hal="' + esc(t.id) + '">' + esc(t.hallelujah_count) + '</span> Hallelujah</span></button>'
            + '<button type="button" class="ui-btn ui-btn--ghost ui-btn--sm" data-action="tm.comments" data-id="' + esc(t.id) + '" aria-expanded="false">'
            + HOD.icon('message-circle') + '<span>' + esc(t.comment_count) + ' comments</span></button></div>'
            + '<div class="space-y-2" data-comments="' + esc(t.id) + '" hidden>'
            + '<div class="space-y-2" data-comment-list="' + esc(t.id) + '"></div>'
            + '<form class="flex gap-2" data-action="tm.comment" data-id="' + esc(t.id) + '" novalidate>'
            + '<label class="sr-only" for="tm-c-' + esc(t.id) + '">Your comment</label>'
            + '<input class="ui-input ui-input--sm flex-1" id="tm-c-' + esc(t.id) + '" name="comment_text" placeholder="Share an encouraging word" autocomplete="off">'
            + '<button type="submit" class="ui-btn ui-btn--primary ui-btn--sm"><span>Post</span></button></form></div>'
            + '</article>';
    }

    function loadWall() {
        return HOD.api(API, { method: 'GET', action: 'fetch_wall', quiet: true }).then(function (res) {
            var list = res.testimonies || [];
            $('tm-wall').innerHTML = list.length ? list.map(card).join('')
                : '<div class="ui-card md:col-span-2 lg:col-span-3"><div class="ui-empty"><p class="ui-empty__title">No testimonies yet</p>'
                    + '<p class="ui-empty__text">Be the first to share what God has done.</p></div></div>';
        }).catch(function (err) {
            $('tm-wall').innerHTML = '<div class="ui-card md:col-span-2 lg:col-span-3"><div class="ui-empty"><p class="ui-empty__title">Could not load the wall</p>'
                + '<p class="ui-empty__text">' + esc(err.message || 'Please try again later.') + '</p></div></div>';
        });
    }

    HOD.actions.on('tm.hallelujah', function (btn) {
        var id = btn.getAttribute('data-id');
        HOD.api(API, { data: { action: 'click_hallelujah', testimony_id: id }, quiet: true, raw: true }).then(function (res) {
            if (res.status === 'success') {
                var count = document.querySelector('[data-hal="' + id + '"]');
                if (count) count.textContent = res.new_count;
                btn.classList.add('text-hodRed');
            } else if (res.message) {
                HOD.toast(res.message, res.status === 'info' ? 'info' : 'danger');
            }
        }).catch(function () { HOD.toast('Network problem. Please try again.', 'danger'); });
    });

    function loadComments(id) {
        var list = document.querySelector('[data-comment-list="' + id + '"]');
        list.innerHTML = '<p class="text-sm text-ink-subtle">Loading…</p>';
        return HOD.api(API, { method: 'GET', data: { action: 'fetch_comments', testimony_id: id }, quiet: true, raw: true }).then(function (res) {
            var rows = res.comments || [];
            list.innerHTML = rows.length ? rows.map(function (c) {
                return '<div class="rounded-ui bg-surface-sunken p-2 text-sm"><p class="flex justify-between gap-2"><span class="font-semibold text-ink">' + c.display_name + '</span>'
                    + '<span class="text-xs text-ink-subtle">' + esc(c.nice_date) + '</span></p><p class="text-ink-muted">' + c.comment_text + '</p></div>';
            }).join('') : '<p class="text-sm text-ink-subtle">No comments yet.</p>';
        }).catch(function () { list.innerHTML = '<p class="text-sm text-danger">Could not load the comments.</p>'; });
    }

    HOD.actions.on('tm.comments', function (btn) {
        var id = btn.getAttribute('data-id');
        var box = document.querySelector('[data-comments="' + id + '"]');
        box.hidden = !box.hidden;
        btn.setAttribute('aria-expanded', box.hidden ? 'false' : 'true');
        if (!box.hidden) loadComments(id);
    });

    HOD.actions.on('tm.comment', function (form, event) {
        var id = form.getAttribute('data-id');
        var input = form.elements.comment_text;
        var text = input.value.trim();
        if (!text) return;
        var btn = (event && event.submitter) || form.querySelector('[type="submit"]');
        HOD.busy(btn, true);
        HOD.api(API, { data: { action: 'post_comment', testimony_id: id, comment_text: text, guest_name: $('tm-name').value || 'Guest' }, quiet: true }).then(function (res) {
            input.value = '';
            HOD.toast(res.message || 'Comment posted.', 'success');
            loadComments(id);
        }).catch(function (err) { HOD.toast(err.message || 'Could not post.', 'danger'); }).then(function () { HOD.busy(btn, false); });
    });

    /* ----- Sharing a testimony -------------------------------------------- */

    function clearAudio() {
        recorded = null;
        chunks = [];
        $('tm-audio-file').value = '';
        $('tm-audio').removeAttribute('src');
        $('tm-audio-preview').hidden = true;
    }
    function showAudio(url) {
        $('tm-audio').src = url;
        $('tm-audio-preview').hidden = false;
    }

    HOD.actions.on('tm.open', function (btn) {
        $('tm-time').value = Date.now();
        HOD.dialog.open('tm-submit', btn);
    });

    HOD.actions.on('tm.record', function () {
        if (!navigator.mediaDevices || !window.MediaRecorder) { HOD.toast('Recording is not supported on this browser. Upload a file instead.', 'warning'); return; }
        navigator.mediaDevices.getUserMedia({ audio: true }).then(function (stream) {
            recorder = new MediaRecorder(stream);
            chunks = [];
            recorder.addEventListener('dataavailable', function (e) { chunks.push(e.data); });
            recorder.addEventListener('stop', function () {
                recorded = new Blob(chunks, { type: 'audio/webm' });
                showAudio(URL.createObjectURL(recorded));
                stream.getTracks().forEach(function (t) { t.stop(); });
                $('tm-rec').hidden = false;
                $('tm-stop').hidden = true;
                $('tm-timer').hidden = true;
            });
            recorder.start();
            $('tm-rec').hidden = true;
            $('tm-stop').hidden = false;
            $('tm-timer').hidden = false;
            seconds = 0;
            $('tm-timer').textContent = '00:00';
            timer = setInterval(function () {
                seconds++;
                $('tm-timer').textContent = String(Math.floor(seconds / 60)).padStart(2, '0') + ':' + String(seconds % 60).padStart(2, '0');
            }, 1000);
        }).catch(function () { HOD.toast('Microphone access was denied or is unavailable.', 'danger'); });
    });

    HOD.actions.on('tm.stop', function () {
        if (recorder && recorder.state !== 'inactive') recorder.stop();
        clearInterval(timer);
    });

    HOD.actions.on('tm.audio-file', function (input) {
        if (input.files && input.files[0]) {
            recorded = null;
            showAudio(URL.createObjectURL(input.files[0]));
        }
    });
    HOD.actions.on('tm.audio-clear', clearAudio);

    document.addEventListener('ui:dialog-close', function (e) {
        if (e.target && e.target.id === 'tm-submit') {
            if (recorder && recorder.state !== 'inactive') recorder.stop();
            clearInterval(timer);
            clearAudio();
        }
    });

    HOD.actions.on('tm.submit', function (form, event) {
        var text = $('tm-text').value.trim();
        var hasAudio = recorded !== null || ($('tm-audio-file').files || []).length > 0;
        if (!text && !hasAudio) { HOD.toast('Write your testimony or add a voice note.', 'warning'); return; }
        var btn = (event && event.submitter) || $('tm-send');
        HOD.busy(btn, true);
        var fd = new FormData(form);
        if (recorded) fd.append('voice_note', recorded, 'voicenote.webm');
        HOD.api(API, { data: fd, quiet: true }).then(function (res) {
            HOD.dialog.close('tm-submit');
            form.reset();
            HOD.toast(res.message || 'Thank you for sharing.', 'success');
            setTimeout(loadWall, 800);
        }).catch(function (err) {
            HOD.toast(err.code === 'NETWORK' ? 'Network error. Please try again.' : (err.message || 'Could not send.'), 'danger');
        }).then(function () { HOD.busy(btn, false); });
    });

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', loadWall);
    else loadWall();
})();
