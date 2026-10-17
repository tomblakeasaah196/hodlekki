/* /assets/js/hymnal/studio.js
 *
 * The Studio: how a song gets into the library.
 *
 * Loaded only for members who hold it, and every write is re-checked by
 * api/hymnal_api.php — a hidden dialog is not a permission.
 *
 * The shape here follows one rule: however the words arrived, there is ONE
 * form, and a person fills it in. The AI path and the typing path converge on
 * the same fields, the AI's own notes about what it could not read sit at the
 * top of that form, and nothing is stored until Save is pressed. A model that
 * invents a verse has to be caught by somebody reading it, so the reading step
 * is the product, not a formality.
 *
 * Sol-fa is never typed and never computed here: the browser extracts the
 * notes with assets/js/hymnal/abc_voices.js — the same file the corpus build
 * uses — and the server generates the syllables with the same function it used
 * for all 511 hymns. The Studio only displays what comes back.
 */
(function (window, document) {
    'use strict';

    var HY = window.HODHymnal;
    if (!HY) {
        return;
    }
    var esc = HY.esc;

    var saved = { id: 0, number: 0, dirty: false };
    var checkedScore = null;   /* {abc, voices, key} once the music checks out */

    function byId(id) {
        return document.getElementById(id);
    }

    function val(id) {
        var n = byId(id);
        return n ? String(n.value || '').trim() : '';
    }

    function setVal(id, v) {
        var n = byId(id);
        if (n) {
            n.value = v == null ? '' : v;
        }
    }

    /* ---------------------------------------------------------------- tabs */

    function showPane(id) {
        ['hyPaneAdd', 'hyPaneList'].forEach(function (p) {
            var node = byId(p);
            if (node) {
                node.hidden = p !== id;
            }
        });
        var tabs = document.querySelectorAll('#hyStudioModal .hy-tab');
        Array.prototype.forEach.call(tabs, function (t) {
            t.classList.toggle('is-on', t.getAttribute('data-pane') === id);
        });
        if (id === 'hyPaneList') {
            loadList('');
        }
    }

    /* ------------------------------------------------------- the words editor */

    function sectionHtml(sec, index) {
        var kinds = ['verse', 'chorus', 'prechorus', 'bridge', 'tag', 'ending'];
        var options = kinds.map(function (k) {
            var on = (sec.kind || 'verse') === k ? ' selected' : '';
            return '<option value="' + k + '"' + on + '>' + k.charAt(0).toUpperCase() + k.slice(1) + '</option>';
        }).join('');
        return '<div class="hy-secedit" data-index="' + index + '">'
            + '<div class="hy-secedit-head">'
            + '<select class="hy-sel" data-kind style="width:auto">' + options + '</select>'
            + '<span class="hy-grow"></span>'
            + '<button type="button" class="hy-btn hy-btn-danger" data-remove>Remove</button>'
            + '</div>'
            + '<textarea class="hy-ta" data-lines placeholder="One line of the song per line here.">'
            + esc((sec.lines || []).join('\n')) + '</textarea>'
            + '</div>';
    }

    function renderSections(sections) {
        var host = byId('hySections');
        if (!host) {
            return;
        }
        var list = sections && sections.length ? sections : [{ kind: 'verse', lines: [] }];
        host.innerHTML = list.map(sectionHtml).join('');
    }

    function readSections() {
        var host = byId('hySections');
        if (!host) {
            return [];
        }
        return Array.prototype.map.call(host.children, function (block) {
            var kind = block.querySelector('[data-kind]');
            var lines = block.querySelector('[data-lines]');
            return {
                kind: kind ? kind.value : 'verse',
                lines: String(lines ? lines.value : '').split(/\r?\n/)
            };
        });
    }

    /* ------------------------------------------------------------- licence */

    /* Which extra licence fields to ask for. Kept in step with
     * HYMNAL_LICENCES in includes/hymnal/songs.php, which is what actually
     * enforces it — this only decides what is on screen. */
    function paintLicence() {
        var code = val('hyLicence');
        var needsNumber = code === 'CCLI';
        var needsDetail = code === 'PERMISSION' || code === 'CC-BY' || code === 'CC-BY-SA';
        var set = function (id, show) {
            var n = byId(id);
            if (n) {
                n.hidden = !show;
            }
        };
        set('hyCcliWrap', needsNumber);
        set('hyHolderWrap', needsNumber);
        set('hyDetailWrap', needsDetail);
    }

    function readShelves() {
        var out = [];
        var chips = document.querySelectorAll('#hyShelfPick [data-shelf]');
        Array.prototype.forEach.call(chips, function (c) {
            if (c.getAttribute('aria-pressed') === 'true') {
                out.push(c.getAttribute('data-shelf'));
            }
        });
        return out;
    }

    function setShelves(slugs) {
        var want = slugs || [];
        var chips = document.querySelectorAll('#hyShelfPick [data-shelf]');
        Array.prototype.forEach.call(chips, function (c) {
            c.setAttribute('aria-pressed', want.indexOf(c.getAttribute('data-shelf')) !== -1 ? 'true' : 'false');
        });
    }

    /* ---------------------------------------------------------------- form */

    var ERRORS = {
        title: 'hyErrTitle',
        sections: 'hyErrSections',
        licence_code: 'hyErrLicence',
        ccli_number: 'hyErrCcli',
        licence_holder: 'hyErrHolder',
        licence_detail: 'hyErrDetail',
        collections: 'hyErrCollections'
    };

    function clearErrors() {
        Object.keys(ERRORS).forEach(function (k) {
            var n = byId(ERRORS[k]);
            if (n) {
                n.hidden = true;
                n.textContent = '';
            }
        });
        Array.prototype.forEach.call(document.querySelectorAll('#hyForm .is-bad'), function (n) {
            n.classList.remove('is-bad');
        });
    }

    /* The server is the one that decides what is valid, so its field errors
     * are shown where the field is rather than summarised in a toast. */
    function showErrors(errors) {
        clearErrors();
        Object.keys(errors || {}).forEach(function (field) {
            var node = byId(ERRORS[field]);
            if (node) {
                node.textContent = errors[field];
                node.hidden = false;
            }
        });
        var first = document.querySelector('#hyForm .hy-err:not([hidden])');
        if (first && first.scrollIntoView) {
            first.scrollIntoView({ block: 'center', behavior: 'smooth' });
        }
    }

    function openForm(data, aiJobId, notes) {
        var d = data || {};
        byId('hyIntake').hidden = true;
        byId('hyForm').hidden = false;
        clearErrors();

        saved.id = Number(d.id || 0);
        saved.number = Number(d.n || d.number || 0);
        saved.dirty = false;
        checkedScore = null;

        setVal('hySongId', saved.id || '');
        setVal('hyAiJobId', aiJobId || '');
        setVal('hyTitle', d.title || '');
        setVal('hyWriter', d.writer || '');
        setVal('hyLang', d.lang || 'en');
        setVal('hyKey', d.song_key || d.key || '');
        setVal('hyTime', d.time_sig || d.time || '');
        setVal('hyBpm', d.bpm || '');
        setVal('hyThemes', Array.isArray(d.themes) ? d.themes.join(', ') : (d.themes || ''));
        setVal('hyScripture', Array.isArray(d.scripture) ? d.scripture.join(', ') : (d.scripture || ''));
        setVal('hyLicence', (d.licence && d.licence.code) || d.licence_code || 'CCLI');
        setVal('hyCcli', (d.licence && d.licence.ccli) || d.ccli_number || '');
        setVal('hyHolder', (d.licence && d.licence.holder) || d.licence_holder || '');
        setVal('hyDetail', (d.licence && d.licence.detail) || d.licence_detail || '');
        setVal('hyAbc', (d.score && d.score.abc) || '');
        paintLicence();
        setShelves(d.collections || []);

        var words = d.langs ? HY.words(d) : null;
        renderSections((words && words.sections) || d.sections || []);

        var noteBox = byId('hyAiNotes');
        if (noteBox) {
            if (notes) {
                noteBox.innerHTML = '<b>Please check this against the original.</b>' + esc(notes);
                noteBox.hidden = false;
            } else if (aiJobId) {
                noteBox.innerHTML = '<b>This was read automatically.</b>'
                    + 'Read it through before you save — check the verse order, the spelling and any words that were hard to make out.';
                noteBox.hidden = false;
            } else {
                noteBox.hidden = true;
            }
        }

        paintSaveState(saved.id ? 'Saved as draft · Hymn ' + saved.number : 'Not saved yet');
        var publish = byId('hyPublish');
        if (publish) {
            publish.disabled = !saved.id;
        }
        var abcSave = byId('hySaveAbc');
        if (abcSave) {
            abcSave.disabled = true;
        }
        byId('hyAbcPreview').innerHTML = '';
        byId('hyAbcSolfa').innerHTML = '';
    }

    function closeForm() {
        byId('hyForm').hidden = true;
        byId('hyIntake').hidden = false;
        setVal('hyPaste', '');
        var file = byId('hyFile');
        if (file) {
            file.value = '';
        }
    }

    function paintSaveState(text) {
        var n = byId('hySaveState');
        if (n) {
            n.textContent = text;
        }
    }

    function collect() {
        return {
            id: saved.id || 0,
            ai_job_id: val('hyAiJobId') || 0,
            title: val('hyTitle'),
            writer: val('hyWriter'),
            lang: val('hyLang'),
            song_key: val('hyKey'),
            time_sig: val('hyTime'),
            bpm: val('hyBpm'),
            themes: val('hyThemes'),
            scripture: val('hyScripture'),
            licence_code: val('hyLicence'),
            ccli_number: val('hyCcli'),
            licence_holder: val('hyHolder'),
            licence_detail: val('hyDetail'),
            collections: readShelves(),
            sections: readSections()
        };
    }

    function save() {
        var btn = byId('hySave');
        if (btn) {
            btn.disabled = true;
        }
        paintSaveState('Saving…');
        return HY.post('song_save', collect()).then(function (data) {
            saved.id = Number(data.id || saved.id);
            saved.number = Number(data.number || saved.number);
            setVal('hySongId', saved.id);
            clearErrors();
            paintSaveState('Saved as draft · Hymn ' + saved.number);
            var publish = byId('hyPublish');
            if (publish) {
                publish.disabled = false;
            }
            HY.say('Saved. It is a draft until a pastor publishes it.');
            return data;
        }).catch(function (err) {
            paintSaveState('Not saved');
            if (err.errors) {
                showErrors(err.errors);
                HY.say('Please check the highlighted fields.', 'error');
            } else {
                HY.say(err.message || 'That did not save.', 'error');
            }
            throw err;
        }).then(function (data) {
            if (btn) {
                btn.disabled = false;
            }
            return data;
        }, function (err) {
            if (btn) {
                btn.disabled = false;
            }
            throw err;
        });
    }

    /* --------------------------------------------------------- the AI path */

    function busy(on, message) {
        var n = byId('hyBusy');
        if (n) {
            n.hidden = !on;
            if (message) {
                n.textContent = message;
            }
        }
    }

    function readResult(job) {
        busy(false);
        var r = (job && job.result) || {};
        if (!r.title && (!r.sections || !r.sections.length)) {
            HY.say('Nothing in that looked like a song. You can still type it in.', 'error');
            return;
        }
        openForm(r, job.id, r.notes);
    }

    function readPaste() {
        var text = val('hyPaste');
        if (text.length < 10) {
            HY.say('Paste the words of the song first.', 'error');
            return;
        }
        busy(true, 'Reading the song…');
        HY.post('ai_read', { text: text }).then(readResult).catch(function (err) {
            busy(false);
            HY.say(err.message || 'That could not be read. You can still type it in.', 'error');
        });
    }

    function readFile(file) {
        if (!file) {
            return;
        }
        var form = new window.FormData();
        form.append('file', file);
        busy(true, 'Reading ' + file.name + '… this can take up to a minute.');
        HY.post('ai_read', form).then(readResult).catch(function (err) {
            busy(false);
            HY.say(err.message || 'That file could not be read. You can still type it in.', 'error');
        });
    }

    /* ------------------------------------------------------------ the music */

    /* Draw the staff and pull the notes out, using the same extractor the
     * corpus build uses. Nothing is saved by this: it is the "does this look
     * right?" step before the server is asked to generate the sol-fa. */
    function checkAbc() {
        var abc = byId('hyAbc') ? byId('hyAbc').value : '';
        var preview = byId('hyAbcPreview');
        var solfa = byId('hyAbcSolfa');
        var saveBtn = byId('hySaveAbc');
        checkedScore = null;
        if (saveBtn) {
            saveBtn.disabled = true;
        }
        solfa.innerHTML = '';
        if (!abc.trim()) {
            preview.innerHTML = '';
            HY.say('Paste the music first.', 'error');
            return;
        }
        if (!window.ABCJS || !window.HYAbcVoices) {
            HY.say('The music tools did not load. Reload the page.', 'error');
            return;
        }
        var tunes;
        try {
            tunes = window.ABCJS.renderAbc(preview, abc, { responsive: 'resize', add_classes: true });
        } catch (e) {
            preview.innerHTML = '<div class="hy-noscore"><b>That music would not draw.</b>'
                + 'Check the ABC — a missing K: line or an unclosed bar is the usual cause.</div>';
            return;
        }
        var tune = tunes && tunes[0];
        if (!tune) {
            preview.innerHTML = '<div class="hy-noscore"><b>No music was found in that.</b></div>';
            return;
        }
        var warnings = tune.warnings || [];
        var voices = window.HYAbcVoices.voicesFromTune(tune);
        if (!voices.length) {
            HY.say('That music has no notes we could read.', 'error');
            return;
        }
        checkedScore = {
            abc: abc,
            voices: voices,
            key: tune.getKeySignature ? tune.getKeySignature() : { root: 'C', acc: '', mode: '', accidentals: [] }
        };
        if (saveBtn) {
            saveBtn.disabled = !saved.id;
            if (!saved.id) {
                saveBtn.title = 'Save the song first, then the music can be attached to it.';
            }
        }
        var names = voices.map(function (v) { return v.name; }).join(', ');
        HY.say(voices.length + ' part' + (voices.length === 1 ? '' : 's') + ' found: ' + names
            + (warnings.length ? ' — with ' + warnings.length + ' warning(s).' : '.'));
    }

    function saveAbc() {
        if (!checkedScore) {
            HY.say('Check the music first.', 'error');
            return;
        }
        if (!saved.id) {
            HY.say('Save the song first, then the music can be attached to it.', 'error');
            return;
        }
        HY.post('score_save', {
            song_id: saved.id,
            abc: checkedScore.abc,
            voices: checkedScore.voices,
            key: checkedScore.key
        }).then(function (data) {
            HY.say('Music saved. doh = ' + (data.doh || '?'));
            /* Show the sol-fa the SERVER generated, not anything worked out
             * here, so what is on screen is what the choir will read. */
            var host = byId('hyAbcSolfa');
            if (host && data.parts) {
                host.innerHTML = data.parts.map(function (v) {
                    var bars = (v.solfa || []).map(function (bar) {
                        return '<span class="hy-solfa-bar">' + bar.map(function (s) {
                            return '<span class="hy-solfa-syl' + (s === '' ? ' is-rest' : '') + '">' + esc(s) + '</span>';
                        }).join('') + '</span>';
                    }).join('');
                    return '<div class="hy-solfa-row"><span class="hy-solfa-name">' + esc(v.name)
                        + '</span><span class="hy-solfa-bars">' + bars + '</span></div>';
                }).join('');
            }
        }).catch(function (err) {
            HY.say(err.message || 'The music did not save.', 'error');
        });
    }

    function clearAbc() {
        if (!saved.id) {
            setVal('hyAbc', '');
            byId('hyAbcPreview').innerHTML = '';
            byId('hyAbcSolfa').innerHTML = '';
            return;
        }
        HY.post('score_save', { song_id: saved.id, abc: '', voices: [], key: {} }).then(function () {
            setVal('hyAbc', '');
            byId('hyAbcPreview').innerHTML = '';
            byId('hyAbcSolfa').innerHTML = '';
            checkedScore = null;
            HY.say('Music removed. Choir mode will say the notes are not available.');
        }).catch(function (err) {
            HY.say(err.message || 'That did not work.', 'error');
        });
    }

    /* ------------------------------------------------------------ our songs */

    function loadList(q) {
        var host = byId('hyStudioList');
        if (!host) {
            return;
        }
        host.innerHTML = '<p class="hy-empty">Loading…</p>';
        HY.get('studio_list', { q: q, limit: 100 }).then(function (data) {
            if (!data.rows.length) {
                host.innerHTML = '<p class="hy-empty">No songs yet. Add the first one.</p>';
                return;
            }
            host.innerHTML = data.rows.map(function (r) {
                return '<button type="button" class="hy-hit" data-id="' + esc(r.id) + '">'
                    + '<span class="hy-hit-n">' + esc(r.n) + '</span>'
                    + '<span class="hy-hit-main"><span class="hy-hit-title">' + esc(r.title) + '</span>'
                    + '<span class="hy-hit-sub">' + esc(r.writer || '') + '</span></span>'
                    + '<span class="hy-hit-tag">' + esc(r.status) + '</span></button>';
            }).join('');
        }).catch(function (err) {
            host.innerHTML = '<p class="hy-empty">' + esc(err.message || 'That list would not load.') + '</p>';
        });
    }

    function editSong(id) {
        HY.get('studio_song', { id: id }).then(function (song) {
            showPane('hyPaneAdd');
            openForm(song, '', '');
            saved.id = Number(song.id);
            saved.number = Number(song.n);
            setVal('hySongId', saved.id);
            paintSaveState(song.status === 'published'
                ? 'Published · Hymn ' + saved.number
                : 'Draft · Hymn ' + saved.number);
            var publish = byId('hyPublish');
            if (publish) {
                publish.disabled = song.status === 'published';
            }
        }).catch(function (err) {
            HY.say(err.message || 'That song would not open.', 'error');
        });
    }

    /* ------------------------------------------------------------- wiring */

    HY.on('hyStudioOpen', 'click', function () {
        HY.openModal('hyStudioModal');
        showPane('hyPaneAdd');
    });

    document.addEventListener('click', function (e) {
        var tab = e.target.closest('#hyStudioModal .hy-tab');
        if (tab) {
            showPane(tab.getAttribute('data-pane'));
            return;
        }
        var add = e.target.closest('#hyForm [data-add]');
        if (add) {
            var host = byId('hySections');
            var next = readSections();
            next.push({ kind: add.getAttribute('data-add'), lines: [] });
            renderSections(next);
            var last = host.lastElementChild;
            var ta = last && last.querySelector('[data-lines]');
            if (ta) {
                ta.focus();
            }
            return;
        }
        var remove = e.target.closest('#hySections [data-remove]');
        if (remove) {
            var block = remove.closest('.hy-secedit');
            var current = readSections();
            current.splice(Number(block.getAttribute('data-index')), 1);
            renderSections(current);
            return;
        }
        var shelf = e.target.closest('#hyShelfPick [data-shelf]');
        if (shelf) {
            shelf.setAttribute('aria-pressed', shelf.getAttribute('aria-pressed') === 'true' ? 'false' : 'true');
            return;
        }
        var hit = e.target.closest('#hyStudioList .hy-hit');
        if (hit) {
            editSong(hit.getAttribute('data-id'));
        }
    });

    HY.on('hyStartBlank', 'click', function () { openForm({}, '', ''); });
    HY.on('hyReadPaste', 'click', readPaste);
    HY.on('hyCancel', 'click', closeForm);
    HY.on('hySave', 'click', function () { save().catch(function () {}); });
    HY.on('hyLicence', 'change', paintLicence);
    HY.on('hyCheckAbc', 'click', checkAbc);
    HY.on('hySaveAbc', 'click', saveAbc);
    HY.on('hyClearAbc', 'click', clearAbc);
    HY.on('hyFile', 'change', function (e) { readFile(e.target.files && e.target.files[0]); });
    HY.on('hyStudioQuery', 'input', function (e) { loadList(e.target.value); });

    HY.on('hyPublish', 'click', function () {
        if (!saved.id) {
            return;
        }
        /* Save first: publishing checks the licence against what is stored,
         * so publishing an unsaved edit would check the wrong thing. */
        save().then(function () {
            return HY.post('song_publish', { id: saved.id });
        }).then(function (data) {
            paintSaveState('Published · Hymn ' + (data.number || saved.number));
            HY.say('Published. The congregation can find it as Hymn ' + (data.number || saved.number) + '.');
            var publish = byId('hyPublish');
            if (publish) {
                publish.disabled = true;
            }
        }).catch(function (err) {
            if (err && err.errors) {
                showErrors(err.errors);
                HY.say('This song is not ready to publish yet.', 'error');
            } else if (err && err.message) {
                HY.say(err.message, 'error');
            }
        });
    });

    /* Drag a photograph of a song sheet onto the drop zone. */
    (function () {
        var drop = byId('hyDrop');
        if (!drop) {
            return;
        }
        ['dragenter', 'dragover'].forEach(function (ev) {
            drop.addEventListener(ev, function (e) {
                e.preventDefault();
                drop.classList.add('is-over');
            });
        });
        ['dragleave', 'drop'].forEach(function (ev) {
            drop.addEventListener(ev, function (e) {
                e.preventDefault();
                drop.classList.remove('is-over');
            });
        });
        drop.addEventListener('drop', function (e) {
            var files = e.dataTransfer && e.dataTransfer.files;
            readFile(files && files[0]);
        });
    })();
})(window, document);
