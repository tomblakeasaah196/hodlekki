// /assets/se/js/studio/games/lineup.js
//
// Studio → Games → Lineup (guide §11.1, §11.14).
//
// The games of the night, in running order. Each one opens an editor with
// its settings in plain words (seconds and points, not milliseconds and
// JSON) and a question picker that only offers APPROVED questions the game
// can actually play — a multiple-choice game never sees an open question.

import { html } from '@se/core/html.js';
import { useEffect, useRef, useState } from 'preact/hooks';
import { Button, Spinner } from '../ui.js';
import { act, Chip, In, Pick, Check, Link, TYPE_ICON, TYPE_HELP } from './common.js';
import { toast } from '../state.js';

/** The settings a producer may change, per game type, in plain words. */
const SETTINGS = {
    live_quiz: [
        ['duration_ms', 'Answer time', 'seconds'],
        ['preroll_ms', 'Get-ready countdown', 'seconds'],
        ['points_mode', 'Points for players', 'select', [
            ['standard', 'Standard — up to 1,000 a question'], ['double', 'Double — up to 2,000'], ['none', 'None — just for fun'],
        ]],
        ['team_base', 'Team points a question (shared by how many got it right)', 'number'],
        ['auto_score', 'Award points as soon as the answer is revealed', 'bool'],
    ],
    trivia: [
        ['duration_ms', 'Answer time', 'seconds'],
        ['preroll_ms', 'Get-ready countdown', 'seconds'],
        ['points_correct', 'Points for a right team answer', 'number'],
        ['use_suggestions_if_no_captain', 'If a captain does not answer, use the team’s most suggested answer', 'bool'],
        ['auto_score', 'Award points as soon as the answer is revealed', 'bool'],
    ],
    buzzer: [
        ['window_ms', 'Buzz window', 'seconds'],
        ['points_correct', 'Points for a right answer', 'number'],
        ['wrong_penalty', 'Points lost for a wrong answer', 'number'],
        ['reopen_on_wrong', 'After a wrong answer, the other teams may buzz', 'bool'],
        ['reopen_window_ms', 'Second-chance buzz window', 'seconds'],
    ],
    who_am_i: [
        ['window_ms', 'Buzz window for each clue', 'seconds'],
        ['points_by_clue', 'Points for a right answer on clue 1, 2, 3, 4, 5', 'list'],
        ['wrong_penalty', 'Points lost for a wrong answer', 'number'],
    ],
    charades: [
        ['turn_ms', 'Turn length', 'seconds'],
        ['points_per_word', 'Points per phrase guessed', 'number'],
        ['max_passes', 'Passes allowed in a turn', 'number'],
    ],
    feud: [
        ['round_multipliers', 'Points multiplier for boards 1, 2, 3, 4', 'list'],
        ['faceoff_window_ms', 'Face-off buzz window', 'seconds'],
    ],
};

/** Settings ↔ form values (ms ↔ seconds, lists ↔ "500, 400, …"). */
function settingsToForm(type, settings) {
    const form = {};
    for (const [key, , kind] of SETTINGS[type] || []) {
        const value = settings[key];
        form[key] = kind === 'seconds' ? Math.round((value ?? 0) / 100) / 10
            : kind === 'list' ? (value || []).join(', ')
            : value;
    }
    return form;
}

function formToSettings(type, form) {
    const out = {};
    for (const [key, , kind] of SETTINGS[type] || []) {
        const value = form[key];
        out[key] = kind === 'seconds' ? Math.round(Number(value) * 1000)
            : kind === 'number' ? Number(value)
            : kind === 'list' ? String(value).split(/[\s,]+/).filter(Boolean).map(Number)
            : value;
    }
    return out;
}

/** Bring a panel that opens below the cards into view. */
function useScrollIntoView() {
    const ref = useRef(null);
    useEffect(() => { ref.current?.scrollIntoView({ behavior: 'smooth', block: 'start' }); }, []);
    return ref;
}

/** Pick and order the questions a game plays. */
function QuestionPicker({ eventId, game, decks, labels, selected, setSelected }) {
    const banks = decks.filter((d) => game.content_types.includes(d.content_type));
    const [items, setItems] = useState(null);

    useEffect(() => {
        let alive = true;
        (async () => {
            const all = [];
            for (const deck of banks) {
                const res = await act('deck_items', { id: eventId, deck_id: deck.id }, { quiet: true });
                if (res.ok) {
                    for (const item of res.data.items) all.push({ ...item, deck_title: deck.title });
                }
            }
            if (alive) setItems(all);
        })();
        return () => { alive = false; };
    }, [game.id, banks.map((b) => b.id).join(',')]);

    if (!items) return html`<${Spinner} label="Finding questions…" />`;

    const needsChoices = ['live_quiz', 'trivia'].includes(game.type);
    const playable = (item) => item.review_status === 'approved'
        && (!needsChoices || (item.payload?.choices || []).length >= 2);
    const byId = Object.fromEntries(items.map((i) => [i.id, i]));
    const available = items.filter((i) => playable(i) && !selected.includes(i.id));
    const skipped = items.filter((i) => !playable(i)).length;

    const move = (index, delta) => {
        const next = [...selected];
        const [moved] = next.splice(index, 1);
        next.splice(Math.max(0, Math.min(next.length, index + delta)), 0, moved);
        setSelected(next);
    };

    if (!banks.length) {
        return html`<p class="text-sm text-gray-600">
            This game plays ${game.content_types.map((t) => '“' + (labels[t] || t) + '”').join(' or ')} questions. Create a bank for them under
            <strong>Question banks</strong>, or add one of the ready-made games in the Lineup.</p>`;
    }

    return html`
        <div class="grid md:grid-cols-2 gap-4">
            <div class="space-y-2">
                <div class="flex justify-between items-baseline gap-2">
                    <p class="text-sm font-bold text-gray-900">In this game (${selected.length})</p>
                    ${selected.length ? html`<${Link} danger onClick=${() => setSelected([])}>Clear</${Link}>` : null}
                </div>
                ${selected.length ? html`
                    <ol class="space-y-1.5">
                        ${selected.map((id, n) => {
                            const item = byId[id];
                            return html`
                                <li key=${id} class="flex items-center gap-2 rounded-xl border border-gray-200 bg-white px-3 py-2">
                                    <span class="w-6 text-xs font-bold text-gray-400">${n + 1}</span>
                                    <span class="flex-1 min-w-0 text-sm truncate" title=${item?.summary}>${item ? item.summary : 'Question #' + id}</span>
                                    <button type="button" class="px-1.5 text-gray-500 hover:text-hodBlue disabled:opacity-30" aria-label="Move up"
                                        disabled=${n === 0} onClick=${() => move(n, -1)}>↑</button>
                                    <button type="button" class="px-1.5 text-gray-500 hover:text-hodBlue disabled:opacity-30" aria-label="Move down"
                                        disabled=${n === selected.length - 1} onClick=${() => move(n, 1)}>↓</button>
                                    <button type="button" class="px-1.5 text-gray-500 hover:text-hodRed" aria-label="Remove"
                                        onClick=${() => setSelected(selected.filter((x) => x !== id))}>✕</button>
                                </li>`;
                        })}
                    </ol>` : html`<p class="text-sm text-gray-500 rounded-xl border border-dashed border-gray-300 p-4">Add questions from the right.</p>`}
            </div>
            <div class="space-y-2">
                <div class="flex justify-between items-baseline gap-2">
                    <p class="text-sm font-bold text-gray-900">Available (${available.length})</p>
                    ${available.length ? html`<${Link} onClick=${() => setSelected([...selected, ...available.map((i) => i.id)])}>Add all</${Link}>` : null}
                </div>
                <ul class="space-y-1.5 max-h-80 overflow-y-auto pr-1">
                    ${available.map((item) => html`
                        <li key=${item.id}>
                            <button type="button" onClick=${() => setSelected([...selected, item.id])}
                                class="w-full text-left rounded-xl border border-gray-100 bg-gray-50 hover:border-hodBlue px-3 py-2">
                                <span class="block text-sm text-gray-900">+ ${item.summary}</span>
                                <span class="block text-xs text-gray-500">${item.deck_title}${item.answer ? ' · ' + item.answer : ''}</span>
                            </button>
                        </li>`)}
                </ul>
                ${skipped ? html`<p class="text-xs text-gray-500">${skipped} question${skipped === 1 ? ' is' : 's are'} hidden:
                    ${needsChoices ? 'still drafts, or without answers to choose from' : 'still drafts'}.</p>` : null}
            </div>
        </div>`;
}

/** Edit one game: name, weight, settings and its questions. */
function GameEditor({ eventId, game, decks, labels, onSaved, onClose }) {
    const [title, setTitle] = useState(game.title);
    const [weight, setWeight] = useState(game.weight);
    const [form, setForm] = useState(() => settingsToForm(game.type, game.settings));
    const [selected, setSelected] = useState(null);
    const [busy, setBusy] = useState(false);
    const [errors, setErrors] = useState({});
    const panel = useScrollIntoView();

    useEffect(() => {
        act('game_items', { id: eventId, game_id: game.id }).then((res) => {
            if (res.ok) setSelected(res.data.items.map((i) => i.id));
        });
    }, [game.id]);

    const save = async () => {
        setBusy(true);
        const res = await act('game_save', {
            id: eventId, game_id: game.id, title, weight: Number(weight), settings: formToSettings(game.type, form),
        });
        if (res.ok && selected) {
            const items = await act('game_items_save', { id: eventId, game_id: game.id, item_ids: selected });
            if (!items.ok) { setBusy(false); return; }
        }
        setBusy(false);
        setErrors(res.ok ? {} : res.fields);
        if (res.ok) onSaved();
    };

    return html`
        <div ref=${panel} class="scroll-mt-6 rounded-3xl border-2 border-hodBlue/20 bg-white p-6 space-y-6">
            <div class="flex justify-between gap-3">
                <h3 class="font-display font-bold text-gray-900">${TYPE_ICON[game.type]} ${game.type_label}</h3>
                <${Link} onClick=${onClose}>Close</${Link}>
            </div>
            <div class="grid sm:grid-cols-[1fr_12rem] gap-4">
                <${In} label="Name on screen" value=${title} onInput=${setTitle} maxLength="120" error=${errors.title} />
                <${In} label="Weight in the championship" type="number" min="0.25" max="5" step="0.25" value=${weight} onInput=${setWeight}
                    hint="2 doubles this game's team points." />
            </div>
            <div class="grid sm:grid-cols-2 gap-4">
                ${(SETTINGS[game.type] || []).map(([key, label, kind, options]) => kind === 'bool'
                    ? html`<div key=${key} class="sm:col-span-2"><${Check} label=${label} checked=${form[key]} onChange=${(v) => setForm({ ...form, [key]: v })} /></div>`
                    : kind === 'select'
                        ? html`<${Pick} key=${key} label=${label} value=${form[key]} options=${options} onChange=${(v) => setForm({ ...form, [key]: v })} />`
                        : html`<${In} key=${key} label=${label + (kind === 'seconds' ? ' (seconds)' : '')}
                            type=${kind === 'list' ? 'text' : 'number'} step=${kind === 'seconds' ? '0.5' : '1'}
                            value=${form[key]} onInput=${(v) => setForm({ ...form, [key]: v })} />`)}
            </div>
            <div class="space-y-3">
                <p class="text-sm font-bold text-gray-900">${game.type === 'feud' ? 'Survey questions (one board each)' : game.type === 'charades' ? 'Phrases' : 'Questions'}</p>
                ${selected === null ? html`<${Spinner} />` : html`
                    <${QuestionPicker} eventId=${eventId} game=${game} decks=${decks} labels=${labels} selected=${selected} setSelected=${setSelected} />`}
            </div>
            <div class="flex flex-wrap gap-2">
                <${Button} loading=${busy} onClick=${save}>Save game</${Button}>
                <${Button} variant="ghost" onClick=${onClose}>Cancel</${Button}>
            </div>
        </div>`;
}

/** Choose a type and create a game. */
function AddGame({ eventId, types, onCreated, onClose }) {
    const [busy, setBusy] = useState(null);
    const panel = useScrollIntoView();

    const create = async (type) => {
        setBusy(type);
        const res = await act('game_save', { id: eventId, type });
        setBusy(null);
        if (res.ok) onCreated(res.data.game);
    };

    return html`
        <div ref=${panel} class="scroll-mt-6 rounded-3xl border-2 border-hodBlue/20 bg-white p-6 space-y-4">
            <div class="flex justify-between gap-3">
                <h3 class="font-display font-bold text-gray-900">Add a game</h3>
                <${Link} onClick=${onClose}>Close</${Link}>
            </div>
            <div class="grid sm:grid-cols-2 xl:grid-cols-3 gap-3">
                ${Object.entries(types).map(([type, label]) => html`
                    <button key=${type} type="button" disabled=${busy !== null} onClick=${() => create(type)}
                        class="text-left rounded-2xl border border-gray-200 hover:border-hodBlue hover:bg-blue-50/40 p-4 transition-colors disabled:opacity-50">
                        <span class="block text-2xl" aria-hidden="true">${TYPE_ICON[type]}</span>
                        <span class="block mt-1 font-bold text-gray-900">${busy === type ? 'Adding…' : label}</span>
                        <span class="block mt-1 text-xs text-gray-500">${TYPE_HELP[type]}</span>
                    </button>`)}
            </div>
        </div>`;
}

const UNIT = { question: ['question', 'questions'], person: ['person', 'people'], phrase: ['phrase', 'phrases'], board: ['board', 'boards'] };

/**
 * The ready-made games (the Chara starter pack), each with its own Add
 * button: pick the ones tonight needs. Each arrives with its questions,
 * approved and attached, and the Feud with boards it can play at once.
 */
function ReadyMade({ eventId, catalogue, empty, reload, onBuild }) {
    const [busy, setBusy] = useState(null);
    const missing = catalogue.filter((g) => !g.added);

    const add = async (keys, label) => {
        setBusy(label);
        const res = await act('chara_starter_content', { id: eventId, games: keys });
        setBusy(null);
        if (res.ok) {
            toast(res.data.games ? (keys.length === 1 ? 'Added to your lineup.' : res.data.games + ' games added to your lineup.') : 'Already in your lineup.', 'success');
            reload();
        }
    };

    if (!missing.length) return null;

    return html`
        <section class=${'rounded-3xl border p-5 sm:p-6 space-y-4 '
            + (empty ? 'bg-gradient-to-br from-blue-50 to-amber-50 border-blue-100' : 'bg-white border-gray-100')}>
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="max-w-2xl">
                    <h3 class="font-display text-lg font-bold text-gray-900">${empty ? 'Pick your games' : 'More ready-made games'}</h3>
                    <p class="text-sm text-gray-600">Ready to play: each one comes with its questions, which you can edit or add to
                        in <strong>Question banks</strong>${'. '}Pick the ones you want tonight.</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    ${missing.length > 1 ? html`
                        <${Button} variant="secondary" loading=${busy === 'all'} disabled=${busy !== null}
                            onClick=${() => add(missing.map((g) => g.key), 'all')}>Add all ${missing.length}</${Button}>` : null}
                    ${empty ? html`<${Button} variant="ghost" onClick=${onBuild}>Build my own</${Button}>` : null}
                </div>
            </div>
            <ul class="grid sm:grid-cols-2 xl:grid-cols-3 gap-3">
                ${catalogue.map((g) => html`
                    <li key=${g.key} class=${'rounded-2xl border p-4 flex flex-col gap-2 ' + (g.added ? 'bg-gray-50 border-gray-100' : 'bg-white border-gray-200')}>
                        <div class="flex items-start justify-between gap-2">
                            <p class="font-bold text-gray-900">${TYPE_ICON[g.type]} ${g.title}</p>
                            <span class="text-xs text-gray-500 whitespace-nowrap">${g.count} ${UNIT[g.unit]?.[g.count === 1 ? 0 : 1] || g.unit}</span>
                        </div>
                        <p class="text-xs text-gray-600 flex-1">${g.blurb}</p>
                        ${g.added
                            ? html`<p class="text-sm font-semibold text-emerald-700">✓ In your lineup</p>`
                            : html`<${Button} variant="primary" loading=${busy === g.key} disabled=${busy !== null}
                                onClick=${() => add([g.key], g.key)}>Add ${g.title}</${Button}>`}
                    </li>`)}
            </ul>
        </section>`;
}

/** The lineup: game cards, the editor, add and delete. */
export function LineupSection({ eventId, overview, reload }) {
    const games = overview.games || [];
    const [editing, setEditing] = useState(null);
    const [adding, setAdding] = useState(false);

    const remove = async (game) => {
        if (!confirm(`Delete “${game.title}”? Its questions stay in their banks.`)) return;
        const res = await act('game_delete', { id: eventId, game_id: game.id });
        if (res.ok) { setEditing(null); reload(); }
    };

    const move = async (index, delta) => {
        const ids = games.map((g) => g.id);
        const [moved] = ids.splice(index, 1);
        ids.splice(index + delta, 0, moved);
        const res = await act('game_order', { id: eventId, game_ids: ids });
        if (res.ok) reload();
    };

    const editingGame = games.find((g) => g.id === editing) || null;
    const catalogue = overview.catalogue || [];
    const offered = catalogue.some((g) => !g.added);

    return html`
        <div class="space-y-5">
            ${!games.length && offered ? html`<${ReadyMade} eventId=${eventId} catalogue=${catalogue} empty reload=${reload}
                onBuild=${() => setAdding(true)} />` : null}

            ${games.length ? html`
                <ol class="grid md:grid-cols-2 xl:grid-cols-3 gap-4">
                    ${games.map((game, n) => html`
                        <li key=${game.id} class=${'bg-white rounded-3xl border p-5 flex flex-col gap-3 '
                            + (game.id === editing ? 'border-hodBlue ring-2 ring-hodBlue/20' : 'border-gray-100')}>
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="text-xs font-bold text-gray-400">GAME ${n + 1}</p>
                                    <h3 class="font-display font-bold text-gray-900 truncate">${TYPE_ICON[game.type]} ${game.title}</h3>
                                    <p class="text-xs text-gray-500">${game.type_label}${game.weight !== 1 ? ' · weight ×' + game.weight : ''}</p>
                                </div>
                                <${Chip} status=${game.status} />
                            </div>
                            <p class="text-sm text-gray-700">
                                <strong>${game.items_total}</strong> ${game.type === 'charades' ? 'phrase' : 'question'}${game.items_total === 1 ? '' : 's'}
                                ${game.rounds_played ? html` · ${game.rounds_played} played` : null}
                            </p>
                            <div class="mt-auto flex items-center gap-4">
                                <${Link} onClick=${() => { setAdding(false); setEditing(game.id); }}>Edit</${Link}>
                                <${Link} danger onClick=${() => remove(game)}>Delete</${Link}>
                                <span class="ml-auto flex gap-1">
                                    <button type="button" class="px-2 py-1 rounded-lg text-gray-500 hover:text-hodBlue hover:bg-gray-50 disabled:opacity-30"
                                        aria-label=${'Play ' + game.title + ' earlier'} title="Play earlier" disabled=${n === 0} onClick=${() => move(n, -1)}>←</button>
                                    <button type="button" class="px-2 py-1 rounded-lg text-gray-500 hover:text-hodBlue hover:bg-gray-50 disabled:opacity-30"
                                        aria-label=${'Play ' + game.title + ' later'} title="Play later" disabled=${n === games.length - 1} onClick=${() => move(n, 1)}>→</button>
                                </span>
                            </div>
                        </li>`)}
                </ol>` : null}

            ${games.length || !offered ? html`
                <div class="flex flex-wrap gap-2">
                    <${Button} variant="secondary" onClick=${() => { setEditing(null); setAdding(true); }}>+ Build a game of my own</${Button}>
                </div>` : null}
            ${games.length ? html`<${ReadyMade} eventId=${eventId} catalogue=${catalogue} reload=${reload} />` : null}

            ${adding ? html`<${AddGame} eventId=${eventId} types=${overview.game_types} onClose=${() => setAdding(false)}
                onCreated=${async (game) => { setAdding(false); await reload(); setEditing(game.id); }} />` : null}
            ${editingGame ? html`<${GameEditor} key=${editingGame.id} eventId=${eventId} game=${editingGame} decks=${overview.decks || []}
                labels=${overview.content_types || {}}
                onClose=${() => setEditing(null)} onSaved=${async () => { setEditing(null); await reload(); }} />` : null}
        </div>`;
}
