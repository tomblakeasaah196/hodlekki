// /assets/se/js/studio/games/feud.js
//
// Studio → Games → Family Feud boards (guide §11.10.2).
//
// Guests answer the survey questions on their phones before the game. Here
// the crew turns those answers into boards: grouped by AI once there are
// enough of them, by exact match before that, and always edited and
// approved by a person before a board can be played.

import { html } from '@se/core/html.js';
import { useState } from 'preact/hooks';
import { Button } from '../ui.js';
import { act, Chip, Link } from './common.js';

function BoardEditor({ eventId, item, onSaved }) {
    const [rows, setRows] = useState(() => item.board.length
        ? item.board.map((a) => ({ label: a.label, points: a.points, source: a.source }))
        : []);
    const [note, setNote] = useState(null);
    const [busy, setBusy] = useState(null);

    const build = async () => {
        setBusy('build');
        const res = await act('feud_build_board', { id: eventId, item_id: item.item_id });
        setBusy(null);
        if (!res.ok) return;
        setRows(res.data.answers.map((a) => ({ label: a.label, points: a.points, source: a.source })));
        setNote(res.data.warning || (res.data.mode === 'ai_review'
            ? `Grouped ${res.data.responses} answers with AI. Check the labels, then approve.` : null));
    };

    const save = async (approved) => {
        setBusy(approved ? 'approve' : 'save');
        const res = await act('feud_board_save', {
            id: eventId, item_id: item.item_id, approved,
            answers: rows.filter((r) => String(r.label).trim() !== ''),
        });
        setBusy(null);
        if (res.ok) onSaved();
    };

    const setRow = (i, patch) => setRows(rows.map((r, n) => (n === i ? { ...r, ...patch } : r)));

    return html`
        <div class="space-y-3">
            ${note ? html`<p class="text-xs text-amber-800 bg-amber-50 rounded-xl px-3 py-2">${note}</p>` : null}
            ${rows.length ? html`
                <ol class="space-y-1.5">
                    ${rows.map((row, i) => html`
                        <li key=${i} class="flex items-center gap-2">
                            <span class="w-6 text-xs font-bold text-gray-400">${i + 1}</span>
                            <input class="flex-1 px-3 py-2 rounded-xl border border-gray-200 focus:border-hodBlue outline-none text-sm"
                                maxLength="24" value=${row.label} aria-label=${'Answer ' + (i + 1)}
                                onInput=${(e) => setRow(i, { label: e.currentTarget.value })} />
                            <input class="w-20 px-3 py-2 rounded-xl border border-gray-200 focus:border-hodBlue outline-none text-sm text-right"
                                type="number" min="0" max="999" value=${row.points} aria-label=${'Points for answer ' + (i + 1)}
                                onInput=${(e) => setRow(i, { points: Number(e.currentTarget.value) })} />
                            <button type="button" class="px-1.5 text-gray-500 hover:text-hodRed" aria-label="Remove"
                                onClick=${() => setRows(rows.filter((_, n) => n !== i))}>✕</button>
                        </li>`)}
                </ol>` : html`<p class="text-sm text-gray-500">No board yet. Build it from the guests' answers, or type one.</p>`}
            <div class="flex flex-wrap gap-3 items-center">
                ${rows.length < 8 ? html`<${Link} onClick=${() => setRows([...rows, { label: '', points: 0, source: 'manual' }])}>+ Add an answer</${Link}>` : null}
            </div>
            <div class="flex flex-wrap gap-2">
                <${Button} variant="secondary" loading=${busy === 'build'} onClick=${build}>
                    ${item.responses ? 'Build from ' + item.responses + ' answers' : 'Build from answers'}
                </${Button}>
                <${Button} loading=${busy === 'approve'} disabled=${!rows.length} onClick=${() => save(true)}>Save and approve</${Button}>
                <${Button} variant="ghost" loading=${busy === 'save'} disabled=${!rows.length} onClick=${() => save(false)}>Save draft</${Button}>
            </div>
        </div>`;
}

export function FeudSection({ eventId, overview, reload }) {
    const items = overview.feud || [];
    const [open, setOpen] = useState(null);

    if (!items.length) {
        return html`
            <div class="bg-white rounded-3xl border border-gray-100 p-8 text-center space-y-2">
                <p class="font-display font-bold text-gray-900">No Family Feud questions yet</p>
                <p class="text-sm text-gray-500 max-w-xl mx-auto">Add a Family Feud game in the Lineup and give it survey questions.
                    Guests then answer them on their phones ("Play ahead") before the night, and the boards are built here.</p>
            </div>`;
    }

    return html`
        <div class="space-y-4">
            <p class="text-sm text-gray-600">Guests answer these on their phones until the Feud starts. The ready-made questions come with
                a board already; to play your guests' own answers instead, press <strong>Edit board</strong>${' → '}<strong>Build from answers</strong>,
                tidy the labels and approve it. The host cannot play a board that is not approved.</p>
            ${items.map((item) => html`
                <section key=${item.item_id} class="bg-white rounded-3xl border border-gray-100 p-5 space-y-3">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h3 class="font-bold text-gray-900">${item.question}</h3>
                            <p class="text-xs text-gray-500">${item.responses} survey answer${item.responses === 1 ? '' : 's'} so far</p>
                        </div>
                        <div class="flex items-center gap-3">
                            <${Chip} status=${item.approved ? 'approved' : 'draft'} label=${item.approved ? 'Board approved' : (item.board.length ? 'Board not approved' : 'No board')} />
                            <${Link} onClick=${() => setOpen(open === item.item_id ? null : item.item_id)}>${open === item.item_id ? 'Close' : 'Edit board'}</${Link}>
                        </div>
                    </div>
                    ${open === item.item_id
                        ? html`<${BoardEditor} eventId=${eventId} item=${item} onSaved=${async () => { setOpen(null); await reload(); }} />`
                        : item.board.length ? html`
                            <ol class="flex flex-wrap gap-2">
                                ${item.board.map((a) => html`<li key=${a.id} class="px-3 py-1.5 rounded-xl bg-gray-50 text-sm"><strong>${a.label}</strong> · ${a.points}</li>`)}
                            </ol>` : null}
                </section>`)}
        </div>`;
}
