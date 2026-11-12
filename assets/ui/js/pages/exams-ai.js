// /assets/ui/js/pages/exams-ai.js
// AI help for the Academy (modules/exams/ai.php): questions into the bank as
// drafts, an outline, a lesson summary, sermon-review prompts. Every request
// goes to /api/exams_ai_api.php; the server checks access, the daily limit and
// the material, and only ever sends staff-chosen training content to Gemini.
// Nothing is created until a person presses Apply (or approves a draft).
(function () {
    'use strict';

    var HOD = window.HOD;
    var API = '/api/exams_ai_api.php';
    var cfg = {};
    var state = { proposal: null };

    function call(action, data, opts) {
        var payload = data instanceof FormData ? data : Object.assign({ training_id: cfg.training_id }, data || {});
        if (payload instanceof FormData && !payload.has('training_id')) payload.append('training_id', cfg.training_id);
        return HOD.api(API, Object.assign({ action: action, json: !(payload instanceof FormData), data: payload }, opts || {}));
    }
    function one(sel, root) { return (root || document).querySelector(sel); }
    function all(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
    function esc(v) { return HOD.esc(v == null ? '' : String(v)); }
    function n(v) { return Number(v || 0).toLocaleString('en-GB'); }

    function banner(text) {
        one('[data-ai-banner]').innerHTML = text
            ? '<div class="ui-banner ui-banner--warning mb-3" role="alert">' + HOD.icon('triangle-alert') + '<div class="ui-banner__body"><p class="ui-banner__text">' + esc(text) + '</p></div></div>'
            : '';
    }

    function options(select, list, empty) {
        select.innerHTML = list.length
            ? list.map(function (o) { return '<option value="' + o.id + '">' + esc(o.label) + '</option>'; }).join('')
            : '<option value="">' + esc(empty) + '</option>';
        select.disabled = !list.length;
    }

    function load() {
        return call('status', {}, { quiet: true }).then(function (res) {
            var u = res.usage;
            one('[data-ai-usage]').textContent = 'Today: ' + n(u.tokens) + ' of ' + n(u.cap) + ' tokens for the church · you: ' + u.mine_hour + ' of ' + u.hourly + ' this hour.';
            banner(res.blocked);
            var readings = res.readings || [];
            one('[data-ai-readings]').innerHTML = readings.length
                ? readings.map(function (r) {
                    return '<label class="ui-check"><input type="checkbox" name="items" value="' + r.id + '" checked><span>' + esc(r.title) +
                        '<span class="ui-check__hint">' + esc((r.slides ? 'Slides · ' : '') + n(r.chars) + ' characters') + '</span></span></label>';
                }).join('')
                : '<p class="ui-meta">No readings with text or slides yet. Use notes or a PDF.</p>';
            options(one('#ai-s-item'), readings.map(function (r) { return { id: r.id, label: r.title }; }), 'No readings with text yet');
            var assignments = (res.assignments || []).slice().sort(function (a, b) { return (b.sermon ? 1 : 0) - (a.sermon ? 1 : 0); });
            options(one('#ai-p-item'), assignments.map(function (a) { return { id: a.id, label: a.title + (a.sermon ? ' (sermon review)' : '') }; }), 'No assignments yet');
            if (!readings.length) {
                var notes = one('#ai-q-form input[name="source"][value="notes"]');
                notes.checked = true;
                source(notes);
            }
        }).catch(function () {});
    }

    function source(radio) {
        var group = radio.getAttribute('data-group');
        all('[data-ai-pane^="' + group + '-"]').forEach(function (p) {
            p.hidden = p.getAttribute('data-ai-pane') !== group + '-' + radio.value;
        });
    }

    /* ---------- questions ---------- */

    function questions(form) {
        var fd = new FormData();
        var src = form.querySelector('input[name="source"]:checked').value;
        fd.append('source', src);
        fd.append('count', form.elements.count.value);
        fd.append('marks', form.elements.marks.value);
        fd.append('topic', form.elements.topic.value);
        fd.append('bank', form.elements.bank.value);
        all('input[name="types"]:checked', form).forEach(function (b) { fd.append('types[]', b.value); });
        if (src === 'notes') fd.append('notes', form.elements.notes.value);
        if (src === 'items') all('input[name="items"]:checked', form).forEach(function (b) { fd.append('items[]', b.value); });
        if (src === 'pdf') {
            var file = form.elements.pdf.files[0];
            if (!file) { HOD.toast('Choose the PDF.', 'warning'); return; }
            fd.append('pdf', file);
        }
        var btn = form.querySelector('[type="submit"]');
        var out = one('[data-ai-q-result]');
        HOD.busy(btn, true);
        out.textContent = 'Writing… this can take up to a minute.';
        call('questions', fd, { quiet: true }).then(function (res) {
            out.innerHTML = esc(res.message) + ' <a class="ui-link" href="' + esc(res.bank_url) + '">Review drafts</a>';
            load();
        }).catch(function (err) {
            out.textContent = err.message;
            load();
        }).finally(function () { HOD.busy(btn, false); });
    }

    /* ---------- outline ---------- */

    var TYPES = { live: 'Live class', video: 'Video', audio: 'Audio', reading: 'Reading', assignment: 'Assignment', exam: 'Exam' };

    function typeSelect(value) {
        return '<select class="ui-select" data-o-type aria-label="Kind">' + Object.keys(TYPES).map(function (k) {
            return '<option value="' + k + '"' + (k === value ? ' selected' : '') + '>' + TYPES[k] + '</option>';
        }).join('') + '</select>';
    }

    function renderProposal(p) {
        state.proposal = p;
        one('[data-ai-o-cards]').innerHTML = p.sections.map(function (s, si) {
            return '<fieldset class="ui-fieldset rounded-ui-lg border border-line p-3" data-o-card><legend class="sr-only">Card ' + (si + 1) + '</legend>' +
                '<div class="flex items-center gap-2"><input type="checkbox" checked data-o-keep aria-label="Keep this card">' +
                '<input class="ui-input font-medium" maxlength="120" value="' + esc(s.title) + '" data-o-title aria-label="Card name"></div>' +
                '<ul class="mt-2 ui-stack ui-stack--sm">' + s.items.map(function (i) {
                    return '<li class="grid gap-2 sm:grid-cols-[auto_150px_minmax(0,1fr)] items-center" data-o-item>' +
                        '<input type="checkbox" checked data-o-keep aria-label="Keep this item">' + typeSelect(i.type) +
                        '<input class="ui-input" maxlength="160" value="' + esc(i.title) + '" data-o-title aria-label="Item name">' +
                        (i.note ? '<p class="ui-meta sm:col-start-3">' + esc(i.note) + '</p>' : '') +
                        '<input type="hidden" data-o-note value="' + esc(i.note || '') + '"><input type="hidden" data-o-minutes value="' + (i.minutes || '') + '"></li>';
                }).join('') + '</ul></fieldset>';
        }).join('');
        one('[data-ai-o-proposal]').hidden = false;
        one('[data-ai-o-result]').textContent = '';
    }

    function outline(form) {
        var btn = form.querySelector('[type="submit"]');
        HOD.busy(btn, true);
        call('outline', {
            notes: form.elements.notes.value,
            sections: form.elements.sections.value,
            source: form.elements.use_readings.checked ? 'items' : 'none',
            items: []
        }, { quiet: true }).then(function (res) {
            renderProposal(res.proposal);
            load();
        }).catch(function (err) {
            HOD.toast(err.message, 'danger');
            load();
        }).finally(function () { HOD.busy(btn, false); });
    }

    function outlineApply(btn) {
        var sections = all('[data-o-card]').filter(function (c) { return one('[data-o-keep]', c).checked; }).map(function (c) {
            return {
                title: one('[data-o-title]', c).value,
                items: all('[data-o-item]', c).filter(function (i) { return one('[data-o-keep]', i).checked; }).map(function (i) {
                    return { type: one('[data-o-type]', i).value, title: one('[data-o-title]', i).value,
                             note: one('[data-o-note]', i).value, minutes: Number(one('[data-o-minutes]', i).value) || null };
                })
            };
        });
        if (!sections.length) { HOD.toast('Keep at least one card.', 'warning'); return; }
        HOD.confirm({ title: 'Apply the outline?', message: 'The cards and items are added to the training. You can change them in the builder.', confirmLabel: 'Apply' }).then(function (yes) {
            if (!yes) return;
            HOD.busy(btn, true);
            call('outline_apply', { proposal: { sections: sections } }, { quiet: true, raw: true }).then(function (res) {
                one('[data-ai-o-result]').innerHTML = esc(res.message) + (res.builder_url ? ' <a class="ui-link" href="' + esc(res.builder_url) + '">Open the builder</a>' : '');
                if (res.status === 'success') one('[data-ai-o-cards]').innerHTML = '';
            }).catch(function (err) {
                HOD.toast(err.message, 'danger');
            }).finally(function () { HOD.busy(btn, false); load(); });
        });
    }

    /* ---------- summary and prompts ---------- */

    function summary(btn) {
        var id = one('#ai-s-item').value;
        if (!id) return;
        HOD.busy(btn, true);
        call('summary', { item_id: Number(id) }, { quiet: true }).then(function (res) {
            one('#ai-s-text').value = res.summary;
            one('[data-ai-s-out]').hidden = false;
            one('[data-ai-s-result]').textContent = '';
        }).catch(function (err) { HOD.toast(err.message, 'danger'); }).finally(function () { HOD.busy(btn, false); load(); });
    }

    function summaryApply(btn) {
        HOD.busy(btn, true);
        call('summary_apply', { item_id: Number(one('#ai-s-item').value), text: one('#ai-s-text').value }, { quiet: true }).then(function (res) {
            one('[data-ai-s-result]').innerHTML = esc(res.message) + ' <a class="ui-link" href="' + esc(res.builder_url) + '">Open the builder</a>';
        }).catch(function (err) { HOD.toast(err.message, 'danger'); }).finally(function () { HOD.busy(btn, false); });
    }

    function prompts(btn) {
        HOD.busy(btn, true);
        call('prompts', { notes: one('#ai-p-notes').value }, { quiet: true }).then(function (res) {
            one('#ai-p-text').value = res.prompts.join('\n');
            one('[data-ai-p-out]').hidden = false;
            one('[data-ai-p-result]').textContent = '';
        }).catch(function (err) { HOD.toast(err.message, 'danger'); }).finally(function () { HOD.busy(btn, false); load(); });
    }

    function promptsApply(btn) {
        var id = one('#ai-p-item').value;
        if (!id) { HOD.toast('Choose the assignment.', 'warning'); return; }
        HOD.busy(btn, true);
        call('prompts_apply', { item_id: Number(id), prompts: one('#ai-p-text').value.split('\n') }, { quiet: true }).then(function (res) {
            one('[data-ai-p-result]').innerHTML = esc(res.message) + ' <a class="ui-link" href="' + esc(res.builder_url) + '">Open the builder</a>';
        }).catch(function (err) { HOD.toast(err.message, 'danger'); }).finally(function () { HOD.busy(btn, false); });
    }

    HOD.ready(function () {
        if (!document.querySelector('[data-exams-ai]')) return;
        cfg = HOD.data('exams-ai-config') || {};
        HOD.actions.on('ai.source', source);
        HOD.actions.on('ai.questions', questions);
        HOD.actions.on('ai.outline', outline);
        HOD.actions.on('ai.outline-apply', outlineApply);
        HOD.actions.on('ai.summary', summary);
        HOD.actions.on('ai.summary-apply', summaryApply);
        HOD.actions.on('ai.prompts', prompts);
        HOD.actions.on('ai.prompts-apply', promptsApply);
        load();
    });
})();
