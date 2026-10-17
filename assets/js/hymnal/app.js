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

    /* --------------------------------------------------------------- menus */

    /* The mode menu, the ⋯ menu and the choir options are popovers, not
     * dialogs. Each is a [hidden] panel next to the button that opens it
     * (aria-controls). One is open at a time; it closes on a tap outside, on
     * Escape (focus goes back to its button), when focus leaves it, and when one
     * of its items is chosen. modal-manager.js never sees them: none has
     * "modal" in its id. */
    var openMenu = null;

    function menuButton(menu) {
        return menu ? document.querySelector('[aria-controls="' + menu.id + '"]') : null;
    }

    function menuItems(menu) {
        return Array.prototype.filter.call(
            menu.querySelectorAll('[role^="menuitem"]'),
            function (item) { return !item.disabled && item.getClientRects().length > 0; }
        );
    }

    function closeMenu(refocus) {
        var menu = openMenu;
        if (!menu) {
            return;
        }
        openMenu = null;
        menu.hidden = true;
        var btn = menuButton(menu);
        if (btn) {
            btn.setAttribute('aria-expanded', 'false');
            if (refocus) {
                btn.focus();
            }
        }
    }

    function showMenu(menu, focusFirst) {
        closeMenu(false);
        var btn = menuButton(menu);
        menu.hidden = false;
        openMenu = menu;
        if (btn) {
            btn.setAttribute('aria-expanded', 'true');
        }
        /* The bar must not slide away under an open menu. */
        var bar = document.getElementById('hyBar');
        if (bar && bar.contains(menu)) {
            bar.classList.remove('is-hidden');
        }
        if (focusFirst) {
            var items = menuItems(menu);
            var on = items.filter(function (i) { return i.getAttribute('aria-checked') === 'true'; })[0];
            if (on || items[0]) {
                (on || items[0]).focus();
            }
        }
    }

    HY.closeMenu = closeMenu;

    /* Buttons that own a popover toggle it. A keyboard press (detail 0) puts
     * focus on the first item, as a menu should; a tap leaves it where it is,
     * so no focus ring flashes on a phone. */
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[aria-controls][aria-expanded]');
        if (!btn) {
            return;
        }
        var menu = document.getElementById(btn.getAttribute('aria-controls'));
        if (!menu || !menu.classList.contains('hy-menu')) {
            return;
        }
        if (openMenu === menu) {
            closeMenu(false);
        } else {
            showMenu(menu, e.detail === 0);
        }
    });

    /* Capture phase, so the menu is shut — and focus is back on its button —
     * before the item's own handler opens a dialog. modal-manager.js records
     * whatever has focus as the dialog's opener and returns focus there on
     * close; a hidden menu item would leave focus nowhere. */
    document.addEventListener('click', function (e) {
        if (!openMenu) {
            return;
        }
        var item = e.target.closest('[role^="menuitem"]');
        if (item && openMenu.contains(item)) {
            closeMenu(true);
        }
    }, true);

    document.addEventListener('pointerdown', function (e) {
        if (!openMenu) {
            return;
        }
        var wrap = openMenu.closest('.hy-pop') || openMenu;
        if (!wrap.contains(e.target)) {
            closeMenu(false);
        }
    }, true);

    document.addEventListener('focusin', function (e) {
        if (!openMenu) {
            return;
        }
        var wrap = openMenu.closest('.hy-pop') || openMenu;
        if (!wrap.contains(e.target)) {
            closeMenu(false);
        }
    });

    document.addEventListener('keydown', function (e) {
        if (!openMenu) {
            return;
        }
        if (e.key === 'Escape') {
            e.preventDefault();
            closeMenu(true);
            return;
        }
        /* Arrow keys move through a real menu. The choir options panel holds a
         * slider and a select, which need their arrows, so it is left alone. */
        if (openMenu.getAttribute('role') !== 'menu') {
            return;
        }
        var items = menuItems(openMenu);
        if (!items.length) {
            return;
        }
        var at = items.indexOf(document.activeElement);
        var next = null;
        if (e.key === 'ArrowDown') {
            next = items[(at + 1) % items.length];
        } else if (e.key === 'ArrowUp') {
            next = items[(at - 1 + items.length) % items.length];
        } else if (e.key === 'Home') {
            next = items[0];
        } else if (e.key === 'End') {
            next = items[items.length - 1];
        }
        if (next) {
            e.preventDefault();
            next.focus();
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

    /* The mode button shows the mode you are in — its icon, and its name in
     * small type under it — and the menu ticks it. */
    function paintMode() {
        var choir = HY.state.mode === 'choir';
        Array.prototype.forEach.call(document.querySelectorAll('#hyModeMenu [data-mode]'), function (item) {
            item.setAttribute('aria-checked', String(item.getAttribute('data-mode') === HY.state.mode));
        });
        Array.prototype.forEach.call(document.querySelectorAll('#hyModeOpen [data-mode-icon]'), function (ic) {
            ic.hidden = ic.getAttribute('data-mode-icon') !== HY.state.mode;
        });
        var label = document.getElementById('hyModeLabel');
        if (label) {
            label.textContent = choir ? 'Choir' : 'Congregation';
        }
        var btn = document.getElementById('hyModeOpen');
        if (btn) {
            btn.setAttribute('aria-label', 'Reading mode: ' + (choir ? 'Choir' : 'Congregation'));
        }
    }

    function setMode(mode) {
        HY.setMode(mode);
        paintMode();
        if (HY.state.mode !== 'choir') {
            HY.choirStop && HY.choirStop();
        }
        HY.renderSong();
    }

    HY.on('hyModeMenu', 'click', function (e) {
        var item = e.target.closest('[data-mode]');
        if (item && item.getAttribute('data-mode') !== HY.state.mode) {
            setMode(item.getAttribute('data-mode'));
        }
    });

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

    /* --------------------------------------------------------------- share */

    /* Two doors to the same card: the round button at the foot of the song
     * and "Share as a card" in the ⋯ menu. share.js decides whether this song
     * may be shared at all, and says why not when it may not. */
    document.addEventListener('click', function (e) {
        if (e.target.closest('[data-share]') && HY.share) {
            HY.share.open();
        }
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

    /* The options button carries the speed, and the key when it has moved,
     * so a chorister can see at a glance that the music is not as written. */
    function paintChoirSummary() {
        var sum = document.getElementById('hyChoirOptsSum');
        if (!sum) {
            return;
        }
        var bits = [(Number(HY.prefs().tempo) || 100) + '%'];
        var t = Number(HY.state.transpose) || 0;
        if (t !== 0) {
            bits.push((t > 0 ? '+' : '−') + Math.abs(t));
        }
        sum.textContent = bits.join(' · ');
        var btn = document.getElementById('hyChoirOptsOpen');
        if (btn) {
            btn.setAttribute('aria-label', 'Playback options: speed ' + bits[0]
                + (t !== 0 ? ', key ' + (t > 0 ? 'up ' : 'down ') + Math.abs(t) : ', key as written'));
        }
    }

    HY.paintChoirSummary = paintChoirSummary;

    HY.on('hyTempo', 'input', function (e) {
        var pct = Number(e.target.value);
        var out = document.getElementById('hyTempoOut');
        if (out) {
            out.textContent = pct + '%';
        }
        HY.setPrefs({ tempo: pct });
        paintChoirSummary();
        HY.choirAudioChanged();
    });

    HY.on('hyTranspose', 'change', function (e) {
        HY.state.transpose = Number(e.target.value) || 0;
        paintChoirSummary();
        /* The staff has to be redrawn for a visual transposition, and the sol-fa
         * does not change at all — that is the point of movable doh. */
        HY.renderSong();
    });

    HY.on('hySolfaToggle', 'change', function (e) {
        HY.setPrefs({ show_solfa: e.target.checked });
        HY.renderSong();
    });

    function paintInstrument() {
        var current = HY.prefs().instrument || 'acoustic_grand_piano';
        Array.prototype.forEach.call(document.querySelectorAll('#hyInstrument [data-instrument]'), function (b) {
            var on = b.getAttribute('data-instrument') === current;
            b.classList.toggle('is-on', on);
            b.setAttribute('aria-pressed', String(on));
        });
    }

    HY.on('hyInstrument', 'click', function (e) {
        var btn = e.target.closest('[data-instrument]');
        if (!btn || btn.getAttribute('data-instrument') === (HY.prefs().instrument || 'acoustic_grand_piano')) {
            return;
        }
        HY.setPrefs({ instrument: btn.getAttribute('data-instrument') });
        paintInstrument();
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
        /* Not while a dialog or a menu is up: the arrows belong to whatever is
         * in it. */
        if (openMenu || document.querySelector('[id$="Modal"]:not([hidden])')) {
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
     * upward scroll, so a phone screen is nearly all words while singing.
     *
     * The page does not scroll the window: includes/header.php gives the
     * screen a fixed-height shell and scrolls its <main>. Listening on window
     * meant the bar never moved, so this listens on whatever HY.scroller()
     * finds, and still works on a page where that is the window. */
    (function () {
        var bar = document.getElementById('hyBar');
        if (!bar) {
            return;
        }
        var scroller = HY.scroller();
        var target = scroller || window;
        var read = function () {
            return scroller ? scroller.scrollTop : window.scrollY;
        };
        var last = read();
        var ticking = false;
        target.addEventListener('scroll', function () {
            if (ticking) {
                return;
            }
            ticking = true;
            window.requestAnimationFrame(function () {
                var now = read();
                if (now > last + 8 && now > 120 && !(openMenu && bar.contains(openMenu))) {
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
        paintInstrument();
        var solfa = document.getElementById('hySolfaToggle');
        if (solfa) {
            solfa.checked = p.show_solfa !== false;
        }
        paintChoirSummary();

        setMode(HY.state.mode);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})(window, document);
