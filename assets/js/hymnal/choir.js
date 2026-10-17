/* /assets/js/hymnal/choir.js
 *
 * Choir mode: the four-part score, the tonic sol-fa under it, and playback.
 *
 * This is the mode the choir rehearses from and the pianist works with. Three
 * things make it useful rather than decorative:
 *
 *   - It draws the real engraved staff from the ABC, with abcjs — the same
 *     library, the same version, that generated the stored sol-fa, so the notes
 *     a chorister sees and the syllables under them can never disagree.
 *   - It isolates a part. An alto taps "Alto" and hears only her line, at
 *     whatever speed she can manage, with the other parts dimmed on the page.
 *   - It transposes. Movable doh means the sol-fa does not change when the key
 *     does, so a hymn can be dropped for a congregation that cannot reach the
 *     written key and the choir still reads the same syllables.
 *
 * Playback is synthesised from the score, so it is available for exactly the
 * songs that have one and there is nothing to record. Where there is no score
 * the controls are hidden entirely and the page says so, rather than offering
 * a play button that does nothing.
 */
(function (window, document) {
    'use strict';

    var HY = window.HODHymnal;
    if (!HY) {
        return;
    }
    var esc = HY.esc;

    /* FluidR3 General MIDI program numbers for the two instruments we ship
     * (assets/hymnal/soundfont/). Anything else would 404 note by note. */
    var PROGRAM = { acoustic_grand_piano: 0, choir_aahs: 52 };

    var synth = null;      /* the live CreateSynth, if any */
    var visual = null;     /* the parsed tune abcjs rendered */
    var playing = false;
    var primeToken = 0;    /* guards against a slow prime landing after a change */

    /* ------------------------------------------------------------- markup */

    HY.choirHtml = function (song) {
        if (!song.has || !song.has.score) {
            return '<div class="hy-noscore"><b>The notes for this song are not available.</b>'
                + esc(song.no_score || 'No score has been added yet.')
                + ' The words below are complete, and the music team can add a score in the Studio.</div>';
        }
        var score = song.score || {};
        var voices = score.voices || [];
        var showSolfa = HY.prefs().show_solfa !== false;

        var html = '<div class="hy-doh">doh = <b>' + esc(score.doh || song.key || '?') + '</b>'
            + (song.key ? ' &nbsp;·&nbsp; written in ' + esc(song.key) : '')
            + (song.time ? ' &nbsp;·&nbsp; ' + esc(song.time) : '')
            + '</div>'
            + '<div class="hy-score" id="hyScore"></div>';

        if (voices.length && showSolfa) {
            html += '<div class="hy-solfa" id="hySolfa">' + solfaRows(voices) + '</div>';
        }
        return html;
    };

    function solfaRows(voices) {
        var solo = HY.state.soloVoices;
        return voices.map(function (v) {
            var dim = solo.length && solo.indexOf(v.id) === -1 ? ' is-dim' : '';
            var bars = (v.solfa || []).map(function (bar) {
                var syls = bar.map(function (s) {
                    /* A rest is blank on a sol-fa sheet, so it is given width
                     * rather than a glyph — the gap is the notation. */
                    var cls = s === '' ? ' is-rest' : '';
                    return '<span class="hy-solfa-syl' + cls + '">' + esc(s) + '</span>';
                }).join('');
                return '<span class="hy-solfa-bar">' + syls + '</span>';
            }).join('');
            return '<div class="hy-solfa-row' + dim + '" data-voice="' + esc(v.id) + '">'
                + '<span class="hy-solfa-name">' + esc(v.name) + '</span>'
                + '<span class="hy-solfa-bars">' + bars + '</span>'
                + '</div>';
        }).join('');
    }

    /* ------------------------------------------------------------ the staff */

    HY.choirMounted = function (song) {
        var host = document.getElementById('hyScore');
        if (!host || !song.score || !window.ABCJS) {
            return;
        }
        try {
            var tunes = window.ABCJS.renderAbc(host, song.score.abc, {
                responsive: 'resize',
                staffwidth: Math.max(320, host.clientWidth || 640),
                visualTranspose: HY.state.transpose,
                /* The score is for reading, not editing: no click handlers, and
                 * the lyric lines stay, because the words under the melody are
                 * half of why a choir wants the staff. */
                add_classes: true,
                selectionColor: '#D11920',
                wrap: { minSpacing: 1.6, maxSpacing: 2.8, preferredMeasuresPerLine: 4 },
                format: { gchordfont: 'Inter 11', vocalfont: 'Literata 13' }
            });
            visual = tunes && tunes[0] ? tunes[0] : null;
        } catch (e) {
            host.innerHTML = '<div class="hy-noscore"><b>This score would not draw.</b>'
                + 'The words below are complete. Please tell the music team which hymn this was.</div>';
            visual = null;
        }
        buildVoiceButtons(song);
        resetSynth();
    };

    function buildVoiceButtons(song) {
        var host = document.getElementById('hyVoices');
        if (!host) {
            return;
        }
        var voices = (song.score && song.score.voices) || [];
        if (!voices.length) {
            host.innerHTML = '';
            return;
        }
        var solo = HY.state.soloVoices;
        host.innerHTML = '<button type="button" class="hy-voice'
            + (solo.length ? '' : ' is-on') + '" data-voice="">All</button>'
            + voices.map(function (v) {
                var on = solo.indexOf(v.id) !== -1 ? ' is-on' : '';
                /* One letter on a phone, the full part name where there is room. */
                return '<button type="button" class="hy-voice' + on + '" data-voice="' + esc(v.id) + '"'
                    + ' title="' + esc(v.name) + '">' + esc(v.id) + '</button>';
            }).join('');
    }

    /* ----------------------------------------------------------- playback */

    /* Which abcjs voice indexes to silence, from the parts the chorister chose.
     * The stored voices and abcjs's own voice order both come from the same
     * ABC in the same order, which is what lets this be an index lookup. */
    function voicesOff(song) {
        var solo = HY.state.soloVoices;
        if (!solo.length) {
            return [];
        }
        var off = [];
        ((song.score && song.score.voices) || []).forEach(function (v, i) {
            if (solo.indexOf(v.id) === -1) {
                off.push(i);
            }
        });
        return off;
    }

    function tempoQpm(song) {
        var pct = Number(HY.prefs().tempo || 100);
        /* The hymn's own written tempo where the source gave one; a steady
         * hymn speed otherwise. Scaled, so "90%" means the same thing to a
         * chorister on any hymn. */
        var base = Number(song.bpm) > 0 ? Number(song.bpm) : 96;
        return Math.max(20, Math.min(300, Math.round(base * pct / 100)));
    }

    function stopSynth() {
        playing = false;
        if (synth) {
            try {
                synth.stop();
            } catch (e) {
                /* Already stopped, or the context was never started. */
            }
        }
        paintPlay();
    }

    function resetSynth() {
        stopSynth();
        synth = null;
        var play = document.getElementById('hyPlay');
        if (play) {
            play.disabled = !visual || !window.ABCJS || !window.ABCJS.synth.supportsAudio();
        }
    }

    HY.choirResetAudio = resetSynth;

    function paintPlay() {
        var play = document.getElementById('hyPlay');
        if (!play) {
            return;
        }
        var ic1 = play.querySelector('.hy-tr-ic-play');
        var ic2 = play.querySelector('.hy-tr-ic-pause');
        if (ic1) {
            ic1.hidden = playing;
        }
        if (ic2) {
            ic2.hidden = !playing;
        }
        play.setAttribute('aria-label', playing ? 'Stop' : 'Play');
    }

    HY.choirPlay = function () {
        var song = HY.state.song;
        if (!visual || !song || !window.ABCJS || !window.ABCJS.synth.supportsAudio()) {
            HY.say('This device cannot play the music back.', 'error');
            return;
        }
        if (playing) {
            stopSynth();
            return;
        }

        var token = ++primeToken;
        var instrument = HY.prefs().instrument || 'acoustic_grand_piano';
        var program = PROGRAM[instrument] === undefined ? 0 : PROGRAM[instrument];

        /* An AudioContext may only start inside a gesture, which is why this
         * runs from the click rather than when the score is drawn. */
        window.ABCJS.synth.activeAudioContext && window.ABCJS.synth.activeAudioContext();

        synth = new window.ABCJS.synth.CreateSynth();
        synth.init({
            visualObj: visual,
            options: {
                /* Served from this repo, so playback works with no data once
                 * the app is installed and nobody outside sees what is being
                 * rehearsed. */
                soundFontUrl: HY.cfg.soundfont || '/assets/hymnal/soundfont/',
                /* abcjs only applies its own 3x boost to its own default URL.
                 * These are the same FluidR3 samples, so without this the
                 * score plays back almost inaudibly. */
                soundFontVolumeMultiplier: 3.0,
                program: program,
                midiTranspose: HY.state.transpose,
                qpm: tempoQpm(song),
                voicesOff: voicesOff(song),
                chordsOff: true
            }
        }).then(function () {
            if (token !== primeToken) {
                return null;
            }
            return synth.prime();
        }).then(function () {
            if (token !== primeToken) {
                return;
            }
            synth.start();
            playing = true;
            paintPlay();
        }).catch(function () {
            if (token !== primeToken) {
                return;
            }
            playing = false;
            paintPlay();
            HY.say('The music could not be loaded for playback.', 'error');
        });
    };

    /* Anything that changes what should be heard stops the current sound and
     * drops the primed buffers, because they were built for the old settings. */
    HY.choirAudioChanged = function () {
        primeToken++;
        resetSynth();
    };

    /* Dim the parts that are not soloed, without redrawing the staff. */
    HY.choirPaintVoices = function () {
        var song = HY.state.song;
        if (!song) {
            return;
        }
        buildVoiceButtons(song);
        var solo = HY.state.soloVoices;
        var rows = document.querySelectorAll('#hySolfa .hy-solfa-row');
        Array.prototype.forEach.call(rows, function (row) {
            var id = row.getAttribute('data-voice');
            var dim = solo.length && solo.indexOf(id) === -1;
            row.classList.toggle('is-dim', dim);
        });
    };

    HY.choirStop = stopSynth;
})(window, document);
