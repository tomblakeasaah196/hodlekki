// /assets/ui/js/pages/live-radio.js
// The live service player (live_radio.php). Play reloads the stream with a
// cache-busting stamp so it starts at the live edge, not stale buffered
// audio; the bars move while sound is playing.
(function () {
    'use strict';

    var HOD = window.HOD;
    var cfg = HOD.data('radio-config') || {};
    var player = document.getElementById('radio-player');
    var btn = document.getElementById('radio-toggle');
    var eq = document.getElementById('radio-eq');
    if (!player || !btn) return;

    function playing(on) {
        document.getElementById('radio-icon-play').hidden = on;
        document.getElementById('radio-icon-pause').hidden = !on;
        btn.setAttribute('aria-label', on ? 'Pause' : 'Play');
        btn.setAttribute('aria-pressed', on ? 'true' : 'false');
        Array.prototype.forEach.call(eq.children, function (bar, i) {
            bar.classList.toggle('opacity-40', !on);
            bar.classList.toggle('motion-safe:animate-pulse', on);
            bar.style.animationDelay = on ? (i * -0.2) + 's' : '';
        });
    }

    HOD.actions.on('radio.toggle', function () {
        if (!cfg.stream) return;
        if (player.paused) {
            player.src = cfg.stream + (cfg.stream.indexOf('?') === -1 ? '?' : '&') + 't=' + Date.now();
            var p = player.play();
            if (p && typeof p.catch === 'function') {
                p.catch(function () { playing(false); HOD.toast('The stream would not start. Try again.', 'warning'); });
            }
            playing(true);
        } else {
            player.pause();
            playing(false);
        }
    });

    player.addEventListener('waiting', function () { eq.classList.add('opacity-60'); });
    player.addEventListener('playing', function () { eq.classList.remove('opacity-60'); playing(true); });
    player.addEventListener('pause', function () { playing(false); });
})();
