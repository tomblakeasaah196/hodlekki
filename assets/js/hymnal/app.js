/* /assets/js/hymnal/app.js
 *
 * Wiring. Everything that turns a tap into one of the calls the other files
 * expose, and nothing else — the rendering lives in reader.js, the music in
 * choir.js, the finding in library.js.
 *
 * Loaded last, so every namespace it touches is already there.
 */
(function (window, document) {
    'use strict';

    var HY = window.HODHymnal;
    if (!HY) {
        return;
    }

    /* ------------------------------------------------------------- dialogs */

    /* modal-manager.js does focus, inerting and the scroll lock; a dialog is
     * open when it is not hidden, and they close only through a visible
     * button — never on a click outside, which is the platform contract. */
    function openModal(id) {
        var el = document.getElementById(id);
        if (el) {
            el.hidden = false;
        }
    }

    function closeModal(id) {
        var el = document.getElementById(id);
        if (el) {
            el.hidden = true;
        }
    }

    HY.openModal = openModal;
    HY.closeModal = closeModal;

    document.addEventListener('click', function (e) {
        var close = e.target.closest('[data-close]');
        if (!close) {
            return;
        }
        var modal = close.closest('[id$="Modal"]');
        if (modal) {
            modal.hidden = true;
        }
    });

    /* ------------------------------------------------------------- library */

    HY.on('hyLibraryOpen', 'click', function () {
        openModal('hyLibraryModal');
        HY.libraryOpened();
    });

    HY.on('hySearchOpen', 'click', function () {
        openModal('hyLibraryModal');
        HY.libraryOpened();
        var input = document.getElementById('hyQuery');
        if (input) {
            input.focus();
            input.select();
        }
    });

    HY.on('hyQuery', 'input', function (e) {
        HY.librarySearch(e.target.value);
    });

    HY.on('hyQuery', 'keydown', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            var first = document.querySelector('#hyLibraryBody .hy-hit');
            if (first) {
                first.click();
            }
        }
    });

    HY.on('hyScoreOnly', 'click', function (e) {
        HY.librarySetScoreOnly(e.currentTarget.getAttribute('aria-pressed') !== 'true');
    });

    HY.on('hyShelves', 'click', function (e) {
        var chip = e.target.closest('[data-shelf]');
        if (chip) {
            HY.librarySetShelf(chip.getAttribute('data-shelf'));
        }
    });

    HY.on('hyLibraryBody', 'click', function (e) {
        var hit = e.target.closest('.hy-hit');
        if (!hit) {
            return;
        }
        closeModal('hyLibraryModal');
        HY.openSong(hit.getAttribute('data-n'));
    });

    /* ---------------------------------------------------------------- mode */

    function setMode(mode) {
        HY.setMode(mode);
        var c = document.getElementById('hyModeCongregation');
        var k = document.getElementById('hyModeChoir');
        if (c && k) {
            var choir = HY.state.mode === 'choir';
            c.classList.toggle('is-on', !choir);
            k.classList.toggle('is-on', choir);
            c.setAttribute('aria-selected', String(!choir));
            k.setAttribute('aria-selected', String(choir));
        }
        if (!document.getElementById('hyModeChoir') || HY.state.mode !== 'choir') {
            HY.choirStop && HY.choirStop();
        }
        HY.renderSong();
    }

    HY.on('hyModeCongregation', 'click', function () { setMode('congregation'); });
    HY.on('hyModeChoir', 'click', function () { setMode('choir'); });

    /* ------------------------------------------------------------- display */

    HY.on('hyDisplayOpen', 'click', function () { openModal('hyDisplayModal'); });

    HY.on('hyTextSize', 'click', function (e) {
        var btn = e.target.closest('[data-size]');
        if (!btn) {
            return;
        }
        Array.prototype.forEach.call(e.currentTarget.children, function (b) {
            b.classList.toggle('is-on', b === btn);
        });
        HY.setPrefs({ text_size: Number(btn.getAttribute('data-size')) });
    });

    HY.on('hyPage', 'click', function (e) {
        var btn = e.target.closest('[data-page]');
        if (!btn) {
            return;
        }
        Array.prototype.forEach.call(e.currentTarget.children, function (b) {
            b.classList.toggle('is-on', b === btn);
        });
        HY.setPrefs({ page: btn.getAttribute('data-page') });
    });

    HY.on('hyChords', 'change', function (e) {
        HY.setPrefs({ show_chords: e.target.checked });
        HY.renderSong();
    });

    HY.on('hyLangs', 'click', function (e) {
        var btn = e.target.closest('[data-lang]');
        if (!btn) {
            return;
        }
        HY.state.lang = btn.getAttribute('data-lang');
        if (HY.boot.ready) {
            HY.post('state', { lang: HY.state.lang }).catch(function () {});
        }
        HY.renderSong();
    });

    /* ----------------------------------------------------------- favourite */

    HY.on('hyFavourite', 'click', function (e) {
        var song = HY.state.song;
        if (!song) {
            return;
        }
        var btn = e.currentTarget;
        var on = btn.getAttribute('aria-pressed') !== 'true';
        btn.disabled = true;
        HY.post('favourite', { n: song.n, on: on }).then(function (data) {
            HY.markFavourite(data.n, data.on);
            HY.renderSong();
            HY.say(data.on ? 'Added to your favourites.' : 'Removed from your favourites.');
        }).catch(function (err) {
            HY.say(err.message || 'That did not save.', 'error');
        }).then(function () {
            btn.disabled = !HY.boot.ready;
        });
    });

    /* --------------------------------------------------------------- about */

    HY.on('hyAboutOpen', 'click', function () {
        HY.renderAbout();
        openModal('hyAboutModal');
    });

    /* ---------------------------------------------------------------- page */

    ['hyPrev', 'hyPrev2'].forEach(function (id) {
        HY.on(id, 'click', function () { HY.step(-1); });
    });
    ['hyNext', 'hyNext2'].forEach(function (id) {
        HY.on(id, 'click', function () { HY.step(1); });
    });

    /* ----------------------------------------------------------- transport */

    HY.on('hyPlay', 'click', function () { HY.choirPlay(); });

    HY.on('hyVoices', 'click', function (e) {
        var btn = e.target.closest('[data-voice]');
        if (!btn) {
            return;
        }
        var id = btn.getAttribute('data-voice');
        if (id === '') {
            HY.state.soloVoices = [];
        } else {
            var at = HY.state.soloVoices.indexOf(id);
            if (at === -1) {
                HY.state.soloVoices.push(id);
            } else {
                HY.state.soloVoices.splice(at, 1);
            }
        }
        HY.choirPaintVoices();
        HY.choirAudioChanged();
    });

    HY.on('hyTempo', 'input', function (e) {
        var pct = Number(e.target.value);
        var out = document.getElementById('hyTempoOut');
        if (out) {
            out.textContent = pct + '%';
        }
        HY.setPrefs({ tempo: pct });
        HY.choirAudioChanged();
    });

    HY.on('hyTranspose', 'change', function (e) {
        HY.state.transpose = Number(e.target.value) || 0;
        /* The staff has to be redrawn for a visual transposition, and the sol-fa
         * does not change at all — that is the point of movable doh. */
        HY.renderSong();
    });

    HY.on('hySolfaToggle', 'change', function (e) {
        HY.setPrefs({ show_solfa: e.target.checked });
        HY.renderSong();
    });

    HY.on('hyInstrument', 'change', function (e) {
        HY.setPrefs({ instrument: e.target.value });
        HY.choirAudioChanged();
    });

    /* ------------------------------------------------------------ keyboard */

    document.addEventListener('keydown', function (e) {
        if (e.metaKey || e.ctrlKey || e.altKey) {
            return;
        }
        var tag = (e.target.tagName || '').toLowerCase();
        if (tag === 'input' || tag === 'textarea' || tag === 'select' || e.target.isContentEditable) {
            return;
        }
        /* Not while a dialog is up: the arrows belong to whatever is in it. */
        if (document.querySelector('[id$="Modal"]:not([hidden])')) {
            return;
        }
        if (e.key === 'ArrowLeft') {
            HY.step(-1);
        } else if (e.key === 'ArrowRight') {
            HY.step(1);
        } else if (e.key === '/') {
            e.preventDefault();
            var open = document.getElementById('hySearchOpen');
            if (open) {
                open.click();
            }
        } else if (e.key === ' ' && HY.state.mode === 'choir') {
            var song = HY.state.song;
            if (song && song.has && song.has.score) {
                e.preventDefault();
                HY.choirPlay();
            }
        }
    });

    /* --------------------------------------------------- the bar on a phone */

    /* The bar slides away while reading down and comes back on the first
     * upward scroll, so a phone screen is nearly all words while singing. */
    (function () {
        var bar = document.getElementById('hyBar');
        if (!bar) {
            return;
        }
        var last = window.scrollY;
        var ticking = false;
        window.addEventListener('scroll', function () {
            if (ticking) {
                return;
            }
            ticking = true;
            window.requestAnimationFrame(function () {
                var now = window.scrollY;
                if (now > last + 8 && now > 120) {
                    bar.classList.add('is-hidden');
                } else if (now < last - 8) {
                    bar.classList.remove('is-hidden');
                }
                last = now;
                ticking = false;
            });
        }, { passive: true });
    })();

    /* ----------------------------------------------------------- first paint */

    function start() {
        HY.applyPrefs();

        var p = HY.prefs();
        var size = document.getElementById('hyTextSize');
        if (size) {
            Array.prototype.forEach.call(size.children, function (b) {
                b.classList.toggle('is-on', Number(b.getAttribute('data-size')) === Number(p.text_size || 3));
            });
        }
        var page = document.getElementById('hyPage');
        if (page) {
            Array.prototype.forEach.call(page.children, function (b) {
                b.classList.toggle('is-on', b.getAttribute('data-page') === (p.page || 'paper'));
            });
        }
        var tempo = document.getElementById('hyTempo');
        if (tempo) {
            tempo.value = String(p.tempo || 100);
            var out = document.getElementById('hyTempoOut');
            if (out) {
                out.textContent = (p.tempo || 100) + '%';
            }
        }
        var instrument = document.getElementById('hyInstrument');
        if (instrument) {
            instrument.value = p.instrument || 'acoustic_grand_piano';
        }
        var solfa = document.getElementById('hySolfaToggle');
        if (solfa) {
            solfa.checked = p.show_solfa !== false;
        }

        setMode(HY.state.mode);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})(window, document);
