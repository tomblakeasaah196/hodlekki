// /assets/ui/js/pages/exams.js
// Trainings exams (modules/exams/): the question bank and the Excel import,
// an exam's set-up and results, and taking an exam. Every request goes to
// /api/exams_api.php; the server decides access, keeps the clock and marks
// the answers (includes/exams/). The browser never receives which option is
// right while someone is taking an exam.
(function () {
    'use strict';

    var HOD = window.HOD;
    var API = '/api/exams_api.php';
    var LETTERS = ['A', 'B', 'C', 'D', 'E'];
    var cfg = {};

    function call(action, data, opts) {
        return HOD.api(API, Object.assign({ action: action, json: true, data: data || {} }, opts || {}));
    }
    function one(sel, root) { return (root || document).querySelector(sel); }
    function all(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
    function esc(v) { return HOD.esc(v == null ? '' : String(v)); }
    function badge(label, tone) { return '<span class="ui-badge' + (tone ? ' ui-badge--' + tone : '') + '">' + esc(label) + '</span>'; }
    function when(at) {
        if (!at) return '';
        var d = new Date(String(at).replace(' ', 'T'));
        return isNaN(d.getTime()) ? '' : d.toLocaleString('en-GB', { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
    }
    function plural(n, one, many) { return n + ' ' + (n === 1 ? one : many); }

    /* =====================================================================
       The question bank
       ===================================================================== */

    var bank = { list: [], editing: null, token: null, timer: null, picked: {}, approving: [] };
    var STATUS = { approved: ['Ready', 'success'], draft: ['Draft', ''], archived: ['Archived', 'warning'] };

    function loadQuestions() {
        call('questions', {
            training_id: cfg.training_id === null ? '' : cfg.training_id,
            status: one('#ex-status').value,
            q: one('#ex-search').value
        }, { quiet: true }).then(function (res) {
            bank.list = res.questions || [];
            renderQuestions();
        }).catch(function (err) {
            one('[data-ex-list]').innerHTML = '<p class="ui-meta">' + esc(err.message) + '</p>';
        });
    }

    function renderQuestions() {
        var list = bank.list;
        one('[data-ex-count]').textContent = plural(list.length, 'question', 'questions');
        if (!list.length) {
            one('[data-ex-list]').innerHTML = '<div class="ui-empty ui-empty--compact"><p class="ui-empty__title">No questions here</p>' +
                '<p class="ui-empty__text">Add one, or import the Excel template.</p></div>';
            return;
        }
        one('[data-ex-list]').innerHTML = '<div class="ui-table-wrap"><table class="ui-table"><thead><tr>' +
            '<th scope="col"><span class="sr-only">Pick</span></th><th scope="col">Question</th><th scope="col">Type</th><th scope="col">Answer</th><th scope="col" class="text-right">Marks</th>' +
            '<th scope="col">Status</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead><tbody>' +
            list.map(function (q) {
                var s = STATUS[q.status] || [q.status, ''];
                var ready = q.status === 'approved'
                    ? '<button type="button" class="ui-btn ui-btn--ghost ui-btn--sm" data-action="ex.status" data-id="' + q.id + '" data-to="draft"><span>To draft</span></button>'
                    : '<button type="button" class="ui-btn ui-btn--ghost ui-btn--sm" data-action="ex.approve" data-id="' + q.id + '"><span>Ready</span></button>';
                return '<tr><td>' + (q.status === 'draft' ? '<input type="checkbox" data-action-change="ex.pick-row" value="' + q.id + '" aria-label="Pick this question"' + (bank.picked[q.id] ? ' checked' : '') + '>' : '') + '</td>' +
                    '<td><span class="block whitespace-pre-line">' + esc(q.text) + '</span>' +
                    '<span class="ui-meta">' + esc([q.source === 'ai' ? 'AI draft' : '', q.topic, q.explanation ? '' : 'No explanation', q.in_use ? 'Used in an exam' : ''].filter(Boolean).join(' · ')) + '</span></td>' +
                    '<td class="whitespace-nowrap">' + esc(q.type_label) + '</td><td>' + esc(q.answer) + '</td>' +
                    '<td class="text-right tabular-nums">' + q.marks + '</td><td>' + badge(s[0], s[1]) + '</td>' +
                    '<td class="whitespace-nowrap text-right">' +
                    '<button type="button" class="ui-btn ui-btn--ghost ui-btn--sm" data-action="ex.edit" data-id="' + q.id + '"><span>Edit</span></button>' +
                    (q.status !== 'archived'
                        ? ready +
                          '<button type="button" class="ui-btn ui-btn--ghost ui-btn--sm" data-action="ex.status" data-id="' + q.id + '" data-to="archived"><span>Archive</span></button>'
                        : '<button type="button" class="ui-btn ui-btn--ghost ui-btn--sm" data-action="ex.status" data-id="' + q.id + '" data-to="draft"><span>Restore</span></button>') +
                    '</td></tr>';
            }).join('') + '</tbody></table></div>';
    }

    /* ---------- Marking Ready: explanations are written once, here, and checked by a person ---------- */

    function picks() {
        var ids = Object.keys(bank.picked).filter(function (k) { return bank.picked[k]; }).map(Number);
        var btn = one('[data-ex-ready-many]');
        btn.hidden = !ids.length;
        btn.querySelector('span').textContent = ids.length > 1 ? 'Mark ' + ids.length + ' Ready' : 'Mark Ready';
        return ids;
    }

    function pickRow(box) {
        bank.picked[box.value] = box.checked;
        picks();
    }

    function approveStart(ids, btn) {
        if (btn) HOD.busy(btn, true);
        call('approve_check', { ids: ids }, { quiet: true }).then(function (res) {
            var items = res.items || [];
            var asks = items.some(function (it) { return it.suggested || !it.explanation; });
            if (!asks) {
                return approveSave(items.map(function (it) { return { id: it.id, explanation: it.explanation }; }), btn);
            }
            bank.approving = items;
            one('[data-ex-approve-note]').innerHTML = res.ai_error
                ? '<div class="ui-banner ui-banner--warning mb-3" role="alert">' + HOD.icon('triangle-alert') + '<div class="ui-banner__body"><p class="ui-banner__text">' +
                  esc('No AI explanations this time: ' + res.ai_error + ' Write your own, or leave them empty.') + '</p></div></div>'
                : '<p class="ui-meta mb-3">Check each explanation. People who get the question wrong will see it.</p>';
            one('[data-ex-approve-list]').innerHTML = items.map(function (it) {
                return '<li class="rounded-ui-lg border border-line p-3"><p class="whitespace-pre-line font-medium">' + esc(it.text) + '</p>' +
                    '<p class="ui-meta">' + esc(it.type_label + ' · Right: ' + it.answer) + (it.suggested ? ' · ' + badge('AI suggestion', 'info') : '') + '</p>' +
                    '<label class="ui-label mt-2" for="ex-expl-' + it.id + '">Explanation</label>' +
                    '<textarea class="ui-textarea" id="ex-expl-' + it.id + '" rows="3" maxlength="2000" data-expl="' + it.id + '">' + esc(it.explanation) + '</textarea></li>';
            }).join('');
            HOD.dialog.open('ex-approve', btn);
        }).catch(function (err) {
            HOD.toast(err.message, 'danger');
        }).finally(function () { if (btn) HOD.busy(btn, false); });
    }

    function approveSave(items, btn) {
        if (btn) HOD.busy(btn, true);
        return call('approve', { items: items }).then(function (res) {
            HOD.dialog.close('ex-approve');
            if (cfg.page === 'setup') {   // explanations for questions just imported into the exam (already Ready)
                HOD.toast('Explanations saved.', 'success');
                return;
            }
            HOD.toast(res.message, 'success');
            bank.picked = {};
            picks();
            loadQuestions();
        }).catch(function () {}).finally(function () { if (btn) HOD.busy(btn, false); });
    }

    function approveOne(btn) { approveStart([Number(btn.getAttribute('data-id'))], btn); }
    function approveMany(btn) { approveStart(picks(), btn); }

    function approveConfirm(btn) {
        approveSave(bank.approving.map(function (it) {
            var box = one('[data-expl="' + it.id + '"]');
            return { id: it.id, explanation: box ? box.value : it.explanation };
        }), btn);
    }

    function filter() {
        window.clearTimeout(bank.timer);
        bank.timer = window.setTimeout(loadQuestions, 250);
    }

    function switchBank(el) {
        window.location.href = '/modules/exams/?training=' + encodeURIComponent(el.value);
    }

    /** The A–E rows (or True/False) with a tick for the right answer(s). */
    function optionRows(type, options, correct) {
        var box = one('[data-ex-options]');
        var kind = type === 'multiple' ? 'checkbox' : 'radio';
        if (type === 'true_false') {
            box.innerHTML = ['True', 'False'].map(function (label, i) {
                return '<label class="ui-check"><input type="radio" name="right" value="' + i + '"' + (correct.indexOf(i) !== -1 ? ' checked' : '') + '><span>' + label + '</span></label>';
            }).join('');
            return;
        }
        box.innerHTML = LETTERS.map(function (letter, i) {
            return '<div class="flex items-center gap-2">' +
                '<input type="' + kind + '" name="right" value="' + i + '" aria-label="' + letter + ' is right"' + (correct.indexOf(i) !== -1 ? ' checked' : '') + '>' +
                '<label class="w-5 font-semibold" for="ex-opt-' + i + '">' + letter + '</label>' +
                '<input class="ui-input" id="ex-opt-' + i + '" name="opt" maxlength="300" value="' + esc(options[i] || '') + '"' + (i < 2 ? ' required' : '') + '>' +
                '</div>';
        }).join('');
    }

    function openQuestion(q) {
        var f = one('#ex-q-form');
        bank.editing = q;
        f.reset();
        HOD.form.clearErrors(f);
        f.elements.type.value = q ? q.type : 'single';
        f.elements.text.value = q ? q.text : '';
        f.elements.marks.value = q ? q.marks : 1;
        f.elements.topic.value = q ? q.topic : '';
        f.elements.explanation.value = q ? q.explanation : '';
        f.elements.ready.checked = q ? q.status === 'approved' : false;
        optionRows(f.elements.type.value, q ? q.options : [], q ? q.correct : []);
        one('[data-ex-q-locked]').hidden = !(q && q.in_use);
        one('#ex-q-title').textContent = q ? 'Edit question' : 'New question';
        HOD.dialog.open('ex-q');
    }

    function add() { openQuestion(null); }

    function edit(btn) {
        var id = Number(btn.getAttribute('data-id'));
        openQuestion(bank.list.filter(function (q) { return q.id === id; })[0] || null);
    }

    function typeChange(sel) {
        var typed = all('[data-ex-options] input[name="opt"]').map(function (i) { return i.value; });
        optionRows(sel.value, typed, []);
    }

    function saveQuestion(form) {
        var button = document.querySelector('[form="ex-q-form"]');
        var data = {
            id: bank.editing ? bank.editing.id : null,
            training_id: cfg.training_id === null ? '' : cfg.training_id,
            type: form.elements.type.value,
            text: form.elements.text.value,
            options: all('[data-ex-options] input[name="opt"]').map(function (i) { return i.value; }),
            correct: all('[data-ex-options] input[name="right"]:checked').map(function (i) { return Number(i.value); }),
            marks: form.elements.marks.value,
            topic: form.elements.topic.value,
            explanation: form.elements.explanation.value,
            ready: form.elements.ready.checked ? 1 : 0
        };
        HOD.busy(button, true);
        call('question_save', data, { quiet: true }).then(function (res) {
            HOD.dialog.close('ex-q');
            HOD.toast(res.message + (res.warnings && res.warnings.length ? ' ' + res.warnings[0] : ''), res.warnings && res.warnings.length ? 'warning' : 'success');
            loadQuestions();
        }).catch(function (err) {
            HOD.toast(err.message, 'danger');
        }).finally(function () { HOD.busy(button, false); });
    }

    function setStatus(btn) {
        HOD.busy(btn, true);
        call('question_status', { id: Number(btn.getAttribute('data-id')), to: btn.getAttribute('data-to') }).then(function (res) {
            HOD.toast(res.message, 'success');
            loadQuestions();
        }).catch(function () { HOD.busy(btn, false); });
    }

    function importOpen() {
        bank.token = null;
        one('#ex-import-form').reset();
        one('[data-ex-import-pick]').hidden = false;
        one('[data-ex-import-result]').hidden = true;
        one('[data-ex-import-go]').hidden = true;
        HOD.dialog.open('ex-import');
    }

    function importCheck(form) {
        var file = form.elements.sheet.files[0];
        if (!file) {
            HOD.form.setErrors(form, { sheet: 'Choose the Excel file.' });
            return;
        }
        var fd = new FormData();
        fd.append('sheet', file);
        fd.append('training_id', cfg.training_id === null ? '' : cfg.training_id);
        var button = one('[data-ex-import-check]');
        HOD.busy(button, true);
        HOD.api(API, { action: 'import_check', data: fd, quiet: true }).then(function (res) {
            bank.token = res.token;
            var c = res.counts;
            var parts = [plural(c.ok, 'question ready to add', 'questions ready to add')];
            if (c.error) parts.push(plural(c.error, 'row to fix', 'rows to fix'));
            if (c.duplicate) parts.push(c.duplicate + ' already there');
            if (c.example) parts.push(plural(c.example, 'example skipped', 'examples skipped'));
            one('[data-ex-import-summary]').textContent = parts.join(' · ');
            var tone = { ok: ['Add', 'success'], error: ['Fix', 'danger'], duplicate: ['Skip', ''], example: ['Example', ''] };
            one('[data-ex-import-rows]').innerHTML = '<div class="ui-table-wrap"><table class="ui-table ui-table--compact"><thead><tr>' +
                '<th scope="col">Row</th><th scope="col"></th><th scope="col">Question</th><th scope="col">Note</th></tr></thead><tbody>' +
                res.rows.map(function (r) {
                    var t = tone[r.status] || [r.status, ''];
                    var note = (r.status === 'error' ? (r.errors || []) : (r.warnings || [])).join(' ');
                    return '<tr><td class="tabular-nums">' + r.row + '</td><td>' + badge(t[0], t[1]) + '</td><td>' + esc(r.text || '(empty)') + '</td>' +
                        '<td class="' + (r.status === 'error' ? 'text-danger' : 'ui-meta') + '">' + esc(note) + '</td></tr>';
                }).join('') + '</tbody></table></div>';
            one('[data-ex-import-result]').hidden = false;
            // On an exam's set-up page, questions already in the bank can still join the exam.
            one('[data-ex-import-go]').hidden = c.ok + (cfg.page === 'setup' ? c.duplicate : 0) === 0;
            var explainRow = one('[data-ex-import-explain-row]');
            if (explainRow) {
                var missing = res.rows.filter(function (r) { return r.status === 'ok' && !r.explained; }).length;
                explainRow.hidden = missing === 0;
                one('[data-ex-import-explain]').checked = false;
                one('[data-ex-import-explain-hint]').textContent = plural(missing, 'has', 'have') + ' none. AI drafts them; you check before saving.';
            }
        }).catch(function (err) {
            HOD.form.setErrors(form, { sheet: err.message });
        }).finally(function () { HOD.busy(button, false); });
    }

    function importConfirm(btn) {
        if (bank.token) importSend(btn, false);
    }

    /**
     * From an exam's set-up page the server saves them as Ready in this
     * training's bank, adds them to the exam and saves it, with the rules as
     * the form shows them (docs/EXAMS.md §2). If people have started, it
     * answers CONFIRM_STARTED before importing anything; the tutor confirms
     * and it goes again.
     */
    function importSend(btn, startedOk) {
        var into = cfg.page === 'setup';
        var explain = into && one('[data-ex-import-explain]').checked;
        var data = into
            ? { token: bank.token, item: cfg.item, started_ok: startedOk ? 1 : 0, exam: examValues(one('#ex-exam-form')) }
            : { token: bank.token, ready: one('[data-ex-import-ready]').checked ? 1 : 0 };
        HOD.busy(btn, true);
        call('import_confirm', data, { quiet: into }).then(function (res) {
            HOD.dialog.close('ex-import');
            if (into) {
                importedIntoExam(res, explain);
                return;
            }
            HOD.toast(res.message, 'success');
            loadQuestions();
        }).catch(function (err) {
            if (!into) return;
            if (err.code === 'CONFIRM_STARTED') {
                HOD.confirm({ title: 'Add to a started exam?', message: err.message, confirmLabel: 'Add' })
                    .then(function (yes) { if (yes) importSend(btn, true); });
                return;
            }
            HOD.toast(err.message, 'danger');
        }).finally(function () { HOD.busy(btn, false); });
    }

    /* =====================================================================
       An exam's set-up and results
       ===================================================================== */

    var setup = { view: null, attempt: null };

    function local(v) { return v ? String(v).replace(' ', 'T').slice(0, 16) : ''; }

    function fillSetup(v) {
        setup.view = v;
        var f = one('#ex-exam-form');
        var e = v.exam;
        f.elements.window_open.value = e ? local(e.window_open) : '';
        f.elements.window_close.value = e ? local(e.window_close) : '';
        f.elements.minutes.value = e ? e.minutes : 30;
        f.elements.pass_percent.value = e ? e.pass_percent : 50;
        f.elements.leave_alert.value = e ? e.leave_alert : 3;
        f.elements.shuffle.checked = e ? !!e.shuffle : true;
        f.elements.part_marks.checked = e ? !!e.part_marks : false;
        fillPool(v, e ? e.question_ids : []);
    }

    /** The question list (ticked: chosen), the locked note, the answers line and the totals. */
    function fillPool(v, chosen) {
        var pool = v.pool.slice().sort(function (a, b) {
            var ia = chosen.indexOf(a.id), ib = chosen.indexOf(b.id);
            return (ia === -1 ? 1e9 : ia) - (ib === -1 ? 1e9 : ib);
        });
        one('[data-ex-pool]').innerHTML = pool.length ? pool.map(function (q) {
            return '<label class="ui-check rounded-ui-lg border border-line px-3 py-2"><input type="checkbox" name="question_ids" value="' + q.id + '" data-marks="' + q.marks + '"' +
                (chosen.indexOf(q.id) !== -1 ? ' checked' : '') + ' data-action-change="ex.total"><span><span class="whitespace-pre-line">' + esc(q.text) + '</span>' +
                '<span class="ui-check__hint">' + esc([q.type_label, plural(q.marks, 'mark', 'marks'), q.topic, q.shared ? 'Shared bank' : ''].filter(Boolean).join(' · ')) + '</span></span></label>';
        }).join('') : '<p class="ui-meta">No Ready questions yet. Import some, or add them in the question bank.</p>';
        reviewSetup(v.review);
        var locked = v.started > 0;
        var into = one('[data-ex-import-into]');
        if (into) into.textContent = locked ? 'They are saved as Ready and added for new starters.' : 'They are saved as Ready and added to this exam.';
        one('[data-ex-locked]').innerHTML = locked
            ? '<div class="ui-banner ui-banner--info mb-4" role="status">' + HOD.icon('info') + '<div class="ui-banner__body"><p class="ui-banner__text">' +
              esc(plural(v.started, 'person has', 'people have') + ' started: only the times and the note can change.') + '</p></div></div>'
            : '';
        all('[data-ex-pool] input, #ex-exam-form [name="minutes"], #ex-exam-form [name="pass_percent"], #ex-exam-form [name="shuffle"], #ex-exam-form [name="part_marks"]')
            .forEach(function (i) { i.disabled = locked; });
        total();
    }

    /** The set-up's Answers line and switch. */
    function reviewSetup(r) {
        var line = one('[data-ex-review]');
        var btn = one('[data-ex-answers]');
        if (!r) {
            line.textContent = 'Save the exam first.';
            btn.hidden = true;
            return;
        }
        var text = {
            shown: 'Shown since ' + when(r.at) + '.',
            close: (r.open ? 'Shown since ' : 'Shown from ') + when(r.at) + ', when it closes.',
            end: (r.open ? 'Shown since ' : 'Shown from ') + when(r.at) + ', after the training ends.',
            tutor: 'Hidden until you show them.'
        };
        line.textContent = text[r.when] || '';
        var shown = r.when === 'shown';
        btn.hidden = r.open && !shown;
        btn.setAttribute('data-show', shown ? '0' : '1');
        btn.querySelector('span').textContent = shown ? 'Hide answers' : 'Show answers';
    }

    function answers(btn) {
        var show = btn.getAttribute('data-show') === '1';
        var ask = show
            ? HOD.confirm({ title: 'Show the answers?', message: 'Everyone who has finished sees the right answers to the ones they got wrong. Anyone still to sit it could be told them.', confirmLabel: 'Show' })
            : Promise.resolve(true);
        ask.then(function (yes) {
            if (!yes) return;
            HOD.busy(btn, true);
            call('answers', { item: cfg.item, show: show ? 1 : 0 }).then(function (res) {
                fillSetup(res);
                HOD.toast(res.message, 'success');
            }).catch(function () {}).finally(function () { HOD.busy(btn, false); });
        });
    }

    function total() {
        var picked = all('[data-ex-pool] input:checked');
        var marks = picked.reduce(function (s, i) { return s + Number(i.getAttribute('data-marks')); }, 0);
        one('[data-ex-total]').textContent = plural(picked.length, 'question', 'questions') + ' · ' + plural(marks, 'mark', 'marks');
    }

    /**
     * After Import on the set-up page: the new list, with what was ticked
     * still ticked and the file's questions added. The rules on the form are
     * left as typed. The server has already saved them into the exam when it
     * could (res.into: saved | unchanged | unsaved | started | failed).
     */
    function importedIntoExam(res, explain) {
        if (res.into === 'saved') {
            fillSetup(res);   // saved with the form as it was, so the page shows what is saved
        } else {
            // Nothing saved (nothing new, or a rule to fix): the form stays as typed, the file's questions ticked.
            var chosen = res.started > 0
                ? (res.exam ? res.exam.question_ids : [])
                : all('[data-ex-pool] input:checked').map(function (i) { return Number(i.value); });
            if (!(res.started > 0)) {
                (res.question_ids || []).forEach(function (id) { if (chosen.indexOf(id) === -1) chosen.push(id); });
            }
            setup.view = res;
            fillPool(res, chosen);
        }
        HOD.toast(res.message, res.into === 'failed' ? 'warning' : 'success');
        // The tutor asked for AI explanations: suggestions to check; stored only on Save (approveConfirm).
        if (explain && res.unexplained && res.unexplained.length) approveStart(res.unexplained);
    }

    function pickAll() { all('[data-ex-pool] input:not(:disabled)').forEach(function (i) { i.checked = true; }); total(); }
    function pickNone() { all('[data-ex-pool] input:not(:disabled)').forEach(function (i) { i.checked = false; }); total(); }

    function loadSetup() {
        return call('setup', { item: cfg.item }).then(fillSetup);
    }

    /** The set-up form as the tutor sees it: the rules and the ticked questions. */
    function examValues(form) {
        return {
            window_open: form.elements.window_open.value,
            window_close: form.elements.window_close.value,
            minutes: form.elements.minutes.value,
            pass_percent: form.elements.pass_percent.value,
            leave_alert: form.elements.leave_alert.value,
            shuffle: form.elements.shuffle.checked ? 1 : 0,
            part_marks: form.elements.part_marks.checked ? 1 : 0,
            question_ids: all('[data-ex-pool] input:checked').map(function (i) { return Number(i.value); })
        };
    }

    function saveExam(form) {
        var button = form.querySelector('[type="submit"]');
        HOD.busy(button, true);
        call('exam_save', Object.assign({ item: cfg.item }, examValues(form)), { quiet: true }).then(function (res) {
            fillSetup(res);
            results();
            HOD.toast(res.message, 'success');
        }).catch(function (err) {
            HOD.toast(err.message, 'danger');
        }).finally(function () { HOD.busy(button, false); });
    }

    var ENDED = { learner: 'Handed in', timer: 'Time ran out', closed: 'Exam closed', tutor: 'Handed in by a tutor' };

    function results(btn) {
        if (btn) HOD.busy(btn, true);
        call('results', { item: cfg.item }, { quiet: !btn }).then(function (res) {
            var s = res.summary;
            one('[data-ex-summary]').textContent = s
                ? s.enrolled + ' enrolled · ' + s.started + ' started · ' + s.finished + ' handed in · ' + s.passed + ' passed'
                : 'Set the exam up first.';
            var alert = setup.view && setup.view.exam ? setup.view.exam.leave_alert : 3;
            one('[data-ex-results]').innerHTML = (res.rows || []).length
                ? '<div class="ui-table-wrap"><table class="ui-table"><thead><tr><th scope="col">Person</th><th scope="col">Status</th>' +
                  '<th scope="col" class="text-right">Score</th><th scope="col">Result</th><th scope="col" class="text-right">Left tab</th><th scope="col"><span class="sr-only">Open</span></th></tr></thead><tbody>' +
                  res.rows.map(function (r) {
                      var status = r.status === 'not_started' ? 'Not started' : (r.status === 'started' ? 'Writing' : (ENDED[r.ended_by] || 'Handed in'));
                      var score = r.score === null ? '—' : r.score + ' / ' + r.max_score + ' (' + Math.round(r.percent) + '%)';
                      var result = r.passed === null ? '' : (r.passed ? badge('Passed', 'success') : badge('Not passed', 'danger'));
                      return '<tr><td>' + esc(r.name) + '</td><td>' + esc(status) + '</td><td class="text-right tabular-nums">' + esc(score) + '</td><td>' + result + '</td>' +
                          '<td class="text-right">' + (r.leaves >= alert ? badge(String(r.leaves), 'warning') : esc(String(r.leaves || 0))) + '</td>' +
                          '<td class="text-right">' + (r.id ? '<button type="button" class="ui-btn ui-btn--ghost ui-btn--sm" data-action="ex.attempt" data-id="' + r.id + '"><span>View</span></button>' : '') + '</td></tr>';
                  }).join('') + '</tbody></table></div>'
                : '<p class="ui-meta">Nobody yet.</p>';
        }).catch(function () {}).finally(function () { if (btn) HOD.busy(btn, false); });
    }

    var EVENTS = { start: 'Started', leave: 'Left the tab', 'return': 'Came back', submit: 'Handed in', timeout: 'Ended by the clock', told: 'You were told' };

    function showAttempt(res) {
        setup.attempt = res.attempt;
        var a = res.attempt;
        one('#ex-attempt-title').textContent = a.status === 'started' ? 'Writing now' : 'Score ' + a.score + ' / ' + a.max_score;
        one('[data-ex-attempt]').innerHTML =
            '<ol class="ui-stack">' + res.questions.map(function (q) {
                return '<li class="rounded-ui-lg border border-line p-3"><p class="ui-meta">Question ' + q.n + ' · ' + plural(q.marks, 'mark', 'marks') + '</p>' +
                    '<p class="whitespace-pre-line">' + esc(q.text) + '</p>' +
                    '<p class="mt-1 text-sm">Answer: ' + esc(q.yours || 'none') + ' · Right: ' + esc(q.right) +
                    (q.score === null ? '' : ' · ' + (q.correct ? badge(q.score + ' / ' + q.marks, 'success') : badge(q.score + ' / ' + q.marks, q.score > 0 ? 'warning' : 'danger'))) + '</p></li>';
            }).join('') + '</ol>' +
            '<h3 class="ui-h3 mt-4">Timeline</h3><ul class="mt-2 ui-stack ui-stack--sm">' + res.events.map(function (e) {
                return '<li class="text-sm"><span class="tabular-nums ui-meta">' + esc(when(e.created_at)) + '</span> ' + esc(EVENTS[e.kind] || e.kind) + '</li>';
            }).join('') + '</ul>';
        one('[data-ex-end]').hidden = a.status !== 'started';
    }

    function attempt(btn) {
        HOD.busy(btn, true);
        call('attempt', { item: cfg.item, attempt_id: Number(btn.getAttribute('data-id')) }).then(function (res) {
            showAttempt(res);
            HOD.dialog.open('ex-attempt', btn);
        }).catch(function () {}).finally(function () { HOD.busy(btn, false); });
    }

    function endAttempt(btn) {
        HOD.confirm({ title: 'Hand in now?', message: 'Their answers so far are marked. They cannot go on.', confirmLabel: 'Hand in', tone: 'danger' }).then(function (yes) {
            if (!yes || !setup.attempt) return;
            HOD.busy(btn, true);
            call('end_attempt', { item: cfg.item, attempt_id: setup.attempt.id }).then(function (res) {
                showAttempt(res);
                HOD.toast(res.message, 'success');
                results();
            }).catch(function () {}).finally(function () { HOD.busy(btn, false); });
        });
    }

    /* =====================================================================
       Taking an exam
       ===================================================================== */

    var take = { view: null, deadline: 0, timer: null, ending: false };

    /** "after the exam closes, Fri 20 Nov, 12:00" and the like. */
    function reviewWhen(kind, at) {
        if (kind === 'close') return 'after the exam closes, ' + when(at);
        if (kind === 'end') return 'after the training ends, ' + when(at);
        return 'once your tutor releases them';
    }

    function clock() {
        var left = Math.max(0, Math.round((take.deadline - Date.now()) / 1000));
        var m = Math.floor(left / 60), s = left % 60;
        var el = one('[data-ex-clock]');
        el.textContent = (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s + ' left';
        el.classList.toggle('text-danger', left <= 60);
        if (left === 0) finish(true);
    }

    function stopClock() {
        window.clearInterval(take.timer);
        take.timer = null;
        one('[data-ex-bar]').hidden = true;
    }

    function renderTake(v) {
        take.view = v;
        var box = one('[data-ex-take]');
        if (v.state !== 'started') stopClock();
        if (v.state === 'not_set') {
            box.innerHTML = '<div class="ui-empty"><p class="ui-empty__title">Not ready yet</p><p class="ui-empty__text">Your tutor has not set this exam up yet.</p></div>';
            return;
        }
        if (v.state === 'ready') {
            var line = v.window === 'not_open' ? 'Opens ' + when(v.opens) + '.' : (v.window === 'closed' ? 'This exam has closed.' : (v.closes ? 'Open until ' + when(v.closes) + '.' : 'Open now.'));
            box.innerHTML = '<section class="ui-card"><div class="ui-card__body ui-stack">' +
                '<dl class="grid grid-cols-3 gap-3 text-center">' +
                '<div><dt class="ui-meta">Questions</dt><dd class="text-xl font-semibold">' + v.questions + '</dd></div>' +
                '<div><dt class="ui-meta">Minutes</dt><dd class="text-xl font-semibold">' + v.minutes + '</dd></div>' +
                '<div><dt class="ui-meta">To pass</dt><dd class="text-xl font-semibold">' + v.pass_percent + '%</dd></div></dl>' +
                '<p>' + esc(line) + ' One attempt; the clock starts when you press Start.</p>' +
                (v.review ? '<p class="ui-meta">' + esc(v.review.open ? 'Answers to any you get wrong show right after.'
                    : 'Answers to any you get wrong show ' + reviewWhen(v.review.when, v.review.at) + '.') + '</p>' : '') +
                (v.window === 'open' ? '<div><button type="button" class="ui-btn ui-btn--primary" data-action="ex.start"><span>Start</span></button></div>' : '') +
                '</div></section>';
            return;
        }
        if (v.state === 'started') {
            paper(v.paper);
            return;
        }
        result(v.result);
    }

    function paper(p) {
        var box = one('[data-ex-take]');
        var total = p.questions.length;
        box.innerHTML = '<ol class="ui-stack">' + p.questions.map(function (q) {
            var kind = q.type === 'multiple' ? 'checkbox' : 'radio';
            return '<li class="ui-card"><fieldset class="ui-card__body"><legend class="mb-2"><span class="ui-meta block">Question ' + q.n + ' of ' + total + ' · ' +
                plural(q.marks, 'mark', 'marks') + (q.type === 'multiple' ? ' · choose all that apply' : '') + '</span>' +
                '<span class="whitespace-pre-line font-medium">' + esc(q.text) + '</span></legend><div class="ui-stack ui-stack--sm">' +
                q.options.map(function (o, i) {
                    return '<label class="ui-check rounded-ui-lg border border-line px-3 py-2"><input type="' + kind + '" name="q' + q.n + '" value="' + i + '" data-n="' + q.n + '"' +
                        (q.chosen.indexOf(i) !== -1 ? ' checked' : '') + ' data-action-change="ex.pick"><span>' + esc(o) + '</span></label>';
                }).join('') + '</div></fieldset></li>';
        }).join('') + '</ol>';
        take.deadline = Date.now() + p.seconds_left * 1000;
        one('[data-ex-bar]').hidden = false;
        one('[data-ex-saved]').textContent = '';
        if (!take.timer) take.timer = window.setInterval(clock, 1000);
        clock();
    }

    function start(btn) {
        var v = take.view;
        HOD.confirm({ title: 'Start the exam?', message: 'One attempt, ' + v.minutes + ' minutes. The clock keeps running if you leave.', confirmLabel: 'Start' }).then(function (yes) {
            if (!yes) return;
            HOD.busy(btn, true);
            call('start', { item: cfg.item }).then(renderTake).catch(function () { HOD.busy(btn, false); });
        });
    }

    function pick(input) {
        var n = Number(input.getAttribute('data-n'));
        var chosen = all('input[name="q' + n + '"]:checked').map(function (i) { return Number(i.value); });
        var saved = one('[data-ex-saved]');
        saved.textContent = 'Saving…';
        call('answer', { item: cfg.item, n: n, chosen: chosen }, { quiet: true, raw: true }).then(function (res) {
            if (res.status === 'success') {
                saved.textContent = 'Saved';
                take.deadline = Date.now() + res.seconds_left * 1000;
                return;
            }
            if (res.code === 'ENDED') {
                HOD.toast(res.message, 'warning');
                call('take', { item: cfg.item }).then(renderTake);
                return;
            }
            saved.textContent = 'Not saved';
            HOD.toast(res.message, 'danger');
        }).catch(function () {
            saved.textContent = 'Not saved: check your connection';
        });
    }

    function finish(auto) {
        if (take.ending) return;
        take.ending = true;
        stopClock();
        call('submit', { item: cfg.item }, { quiet: true }).then(function (res) {
            if (auto) HOD.toast('Time is up. Your answers were handed in.', 'info');
            renderTake(res);
        }).catch(function () {
            // The server hands it in at the deadline anyway; show where things stand.
            call('take', { item: cfg.item }, { quiet: true }).then(renderTake).catch(function () {});
        }).finally(function () { take.ending = false; });
    }

    function submit() {
        var qs = take.view && take.view.paper ? take.view.paper.questions : [];
        var empty = qs.filter(function (q) { return !all('input[name="q' + q.n + '"]:checked').length; }).length;
        HOD.confirm({ title: 'Hand in now?', message: empty ? plural(empty, 'question has', 'questions have') + ' no answer. You cannot change anything after this.' : 'You cannot change your answers after this.', confirmLabel: 'Hand in' })
            .then(function (yes) { if (yes) finish(false); });
    }

    function result(r) {
        var box = one('[data-ex-take]');
        var head = '<section class="ui-card"><div class="ui-card__body text-center">' +
            '<p class="text-4xl font-semibold tabular-nums">' + Math.round(r.percent) + '%</p>' +
            '<p class="ui-meta">' + r.score + ' of ' + plural(r.max, 'mark', 'marks') + ' · ' + r.pass_percent + '% to pass</p>' +
            '<p class="mt-2">' + (r.passed ? badge('Passed', 'success') : badge('Not passed', 'danger')) + '</p></div></section>';
        var body;
        if (r.wrong_count === 0) {
            body = '<p class="mt-4 text-center">Every answer right. Well done.</p>';
        } else if (!r.review_open) {
            body = '<p class="mt-4 text-center">' + esc(plural(r.wrong_count, 'answer was', 'answers were') + ' not right. The right answers and why show here ' + reviewWhen(r.review_when, r.review_at) + '.') + '</p>';
        } else {
            body = '<h2 class="ui-h3 mt-6 mb-2">To learn from</h2><ol class="ui-stack">' + r.wrong.map(function (w) {
                return '<li class="ui-card"><div class="ui-card__body"><p class="ui-meta">Question ' + w.n + (w.part ? ' · ' + w.part + ' of ' + w.marks + ' marks' : '') + '</p>' +
                    '<p class="whitespace-pre-line font-medium">' + esc(w.text) + '</p>' +
                    '<p class="mt-2 text-sm">Your answer: ' + esc(w.yours || 'none') + '</p><p class="text-sm">Right answer: ' + esc(w.right) + '</p>' +
                    (w.explanation ? '<p class="mt-2 whitespace-pre-line">' + esc(w.explanation) + '</p>' : '') + '</div></li>';
            }).join('') + '</ol>';
        }
        box.innerHTML = head + body;
    }

    function focusNote() {
        if (!take.view || take.view.state !== 'started') return;
        call('focus', { item: cfg.item, kind: document.hidden ? 'leave' : 'return' }, { quiet: true }).catch(function () {});
        if (!document.hidden) HOD.toast('Leaving the exam tab is noted for your tutor.', 'warning');
    }

    HOD.ready(function () {
        cfg = HOD.data('exams-config') || {};
        var page = document.querySelector('[data-exams]');
        if (!page) return;
        if (cfg.page === 'bank') {
            HOD.actions.on('ex.filter', filter);
            HOD.actions.on('ex.bank', switchBank);
            HOD.actions.on('ex.add', add);
            HOD.actions.on('ex.edit', edit);
            HOD.actions.on('ex.type', typeChange);
            HOD.actions.on('ex.save', saveQuestion);
            HOD.actions.on('ex.status', setStatus);
            HOD.actions.on('ex.pick-row', pickRow);
            HOD.actions.on('ex.approve', approveOne);
            HOD.actions.on('ex.ready-many', approveMany);
            HOD.actions.on('ex.approve-confirm', approveConfirm);
            HOD.actions.on('ex.import-open', importOpen);
            HOD.actions.on('ex.import-check', importCheck);
            HOD.actions.on('ex.import-confirm', importConfirm);
            loadQuestions();
        } else if (cfg.page === 'setup') {
            HOD.actions.on('ex.import-open', importOpen);
            HOD.actions.on('ex.import-check', importCheck);
            HOD.actions.on('ex.import-confirm', importConfirm);
            HOD.actions.on('ex.approve-confirm', approveConfirm);
            HOD.actions.on('ex.exam-save', saveExam);
            HOD.actions.on('ex.total', total);
            HOD.actions.on('ex.pick-all', pickAll);
            HOD.actions.on('ex.pick-none', pickNone);
            HOD.actions.on('ex.results', results);
            HOD.actions.on('ex.attempt', attempt);
            HOD.actions.on('ex.end', endAttempt);
            HOD.actions.on('ex.answers', answers);
            loadSetup().then(function () { results(); }).catch(function () {});
            document.addEventListener('ui:tab', function (e) { if (e.detail && e.detail.tab === 'results') results(); });
        } else if (cfg.page === 'take') {
            HOD.actions.on('ex.start', start);
            HOD.actions.on('ex.pick', pick);
            HOD.actions.on('ex.submit', submit);
            document.addEventListener('visibilitychange', focusNote);
            call('take', { item: cfg.item }).then(renderTake).catch(function () {});
        }
    });
})();
