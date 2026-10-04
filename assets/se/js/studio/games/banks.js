// /assets/se/js/studio/games/banks.js
//
// Studio → Games → Question banks (guide §11.1, Appendix C, §15.4).
//
// A bank ("deck") holds one kind of question. Producers add questions with a
// form made for that kind — never raw JSON — and AI drafts arrive in a
// review list where nothing is added until somebody ticks it. A question
// typed by a producer is approved as it is saved; an AI draft is approved by
// being kept.

import { html } from '@se/core/html.js';
import { useEffect, useState } from 'preact/hooks';
import { Button, Spinner } from '../ui.js';
import { act, Chip, In, Pick, Check, Link, KIND_HELP } from './common.js';

const LETTERS = ['A', 'B', 'C', 'D'];

/** An empty form for a kind of question. */
function blank(kind) {
    switch (kind) {
        case 'mcq': return { prompt: '', choices: ['', '', '', ''], answer_index: 0, explanation: '' };
        case 'open': return { prompt: '', answer: '', accept: '' };
        case 'emoji': return { emojis: '', answer: '', accept: '', with_choices: false, choices: ['', '', '', ''], answer_index: 0 };
        case 'verse': return { lead: '', answer: '', text: '', split: 0, with_choices: false, choices: ['', '', '', ''], answer_index: 0 };
        case 'clues': return { clues: ['', '', '', '', ''], answer: '', accept: '' };
        case 'charade': return { phrase: '', category: 'story', hint: '' };
        case 'survey': return { question: '' };
        default: return {};
    }
}

/** A stored item → the form's shape. */
function toForm(kind, item) {
    const p = item?.payload || {};
    const form = { ...blank(kind), ...p };
    if (Array.isArray(p.accept)) form.accept = p.accept.join(', ');
    if (Array.isArray(p.choices)) {
        form.choices = [...p.choices, '', '', '', ''].slice(0, 4);
        form.with_choices = true;
    }
    if (kind === 'clues') form.clues = [...(p.clues || []), '', '', '', '', ''].slice(0, 5);
    if (kind === 'verse') {
        form.text = item?.scripture_text || [p.lead, p.answer].filter(Boolean).join(' ');
        form.split = (p.lead || '').split(/\s+/).filter(Boolean).length;
    }
    return form;
}

/** The form → the payload the server validates (Appendix C). */
function toPayload(kind, form) {
    switch (kind) {
        case 'mcq':
            return { prompt: form.prompt, choices: form.choices, answer_index: Number(form.answer_index), explanation: form.explanation };
        case 'open':
            return { prompt: form.prompt, answer: form.answer, accept: form.accept };
        case 'emoji':
        case 'verse': {
            const base = kind === 'emoji'
                ? { emojis: form.emojis, answer: form.answer, accept: form.accept }
                : { lead: form.lead, answer: form.answer };
            return form.with_choices ? { ...base, choices: form.choices, answer_index: Number(form.answer_index) } : base;
        }
        case 'clues':
            return { clues: form.clues, answer: form.answer, accept: form.accept };
        case 'charade':
            return { phrase: form.phrase, category: form.category, hint: form.hint };
        case 'survey':
            return { question: form.question };
        default:
            return {};
    }
}

/** Four answer boxes with a "this one is right" radio. */
function Choices({ form, set, errors }) {
    return html`
        <fieldset class="space-y-2">
            <legend class="block text-xs font-semibold text-gray-600 mb-1">Answers — tick the right one</legend>
            ${form.choices.map((choice, i) => html`
                <div key=${i} class="flex items-center gap-2">
                    <input type="radio" name="se-correct" checked=${Number(form.answer_index) === i}
                        aria-label=${'Answer ' + LETTERS[i] + ' is correct'}
                        onChange=${() => set({ answer_index: i })}
                        class="w-4 h-4 text-emerald-600 focus:ring-emerald-500 shrink-0" />
                    <span class="w-6 text-sm font-bold text-gray-500">${LETTERS[i]}</span>
                    <input class=${'flex-1 px-3.5 py-2 rounded-xl border outline-none text-sm '
                        + (Number(form.answer_index) === i ? 'border-emerald-400 bg-emerald-50/40' : 'border-gray-200 focus:border-hodBlue')}
                        maxLength="60" value=${choice} placeholder=${i < 2 ? 'Required' : 'Optional'}
                        onInput=${(e) => { const next = [...form.choices]; next[i] = e.currentTarget.value; set({ choices: next }); }} />
                </div>`)}
            ${errors.choices || errors.answer_index
                ? html`<p class="text-xs font-semibold text-hodRed" role="alert">${errors.choices || errors.answer_index}</p>` : null}
        </fieldset>`;
}

/** Look a reference up in the KJV and split the verse where the room takes over. */
function VerseFields({ eventId, form, set, errors, ref, setRef }) {
    const [busy, setBusy] = useState(false);
    const words = (form.text || '').split(/\s+/).filter(Boolean);

    const fetchVerse = async () => {
        setBusy(true);
        const res = await act('bible_lookup', { id: eventId, ref });
        setBusy(false);
        if (!res.ok) return;
        const text = res.data.text || '';
        const all = text.split(/\s+/).filter(Boolean);
        const split = Math.max(1, Math.min(all.length - 1, Math.round(all.length * 0.6)));
        setRef(res.data.ref_display || ref);
        set({ text, split, lead: all.slice(0, split).join(' '), answer: all.slice(split).join(' ') });
    };

    const move = (split) => set({ split, lead: words.slice(0, split).join(' '), answer: words.slice(split).join(' ') });

    return html`
        <div class="space-y-3">
            <div class="flex gap-2 items-end">
                <div class="flex-1"><${In} label="Bible reference" value=${ref} onInput=${setRef} placeholder="John 3:16" maxLength="60" /></div>
                <${Button} variant="secondary" loading=${busy} onClick=${fetchVerse}>Fetch the KJV text</${Button}>
            </div>
            ${words.length ? html`
                <div class="rounded-2xl bg-gray-50 p-4 space-y-3">
                    <p class="text-sm leading-relaxed">
                        <span class="font-semibold text-gray-900">${form.lead}</span>
                        <span class="text-hodBlue font-semibold"> … ${form.answer}</span>
                    </p>
                    <label class="block text-xs font-semibold text-gray-600">
                        Where the room takes over (word ${form.split} of ${words.length})
                        <input type="range" min="1" max=${Math.max(1, words.length - 1)} value=${form.split}
                            onInput=${(e) => move(Number(e.currentTarget.value))} class="w-full mt-1" />
                    </label>
                </div>` : html`<p class="text-xs text-gray-500">Type a reference and fetch it — the text always comes from the KJV, never typed.</p>`}
            ${errors.lead || errors.answer ? html`<p class="text-xs font-semibold text-hodRed" role="alert">${errors.lead || errors.answer}</p>` : null}
        </div>`;
}

/** The add/edit form for one question of a bank's kind. */
function ItemForm({ eventId, deck, item, onSaved, onCancel }) {
    const kind = deck.content_type;
    const [form, setForm] = useState(() => toForm(kind, item));
    const [ref, setRef] = useState(item?.scripture_ref || '');
    const [errors, setErrors] = useState({});
    const [busy, setBusy] = useState(false);
    const set = (patch) => setForm((f) => ({ ...f, ...patch }));

    const save = async () => {
        setBusy(true);
        const res = await act('deck_item_save', {
            id: eventId,
            deck_id: deck.id,
            item_id: item?.id || null,
            payload: toPayload(kind, form),
            scripture_ref: ref,
            scripture_text: kind === 'verse' ? form.text : (item?.scripture_text || ''),
        });
        setBusy(false);
        setErrors(res.ok ? {} : res.fields);
        if (res.ok) onSaved(res.data.item);
    };

    const e = errors;
    let body = null;

    if (kind === 'mcq') {
        body = html`
            <${In} label="Question" area value=${form.prompt} maxLength="160" error=${e.prompt} onInput=${(v) => set({ prompt: v })}
                placeholder="Who was swallowed by a great fish?" />
            <${Choices} form=${form} set=${set} errors=${e} />
            <${In} label="Shown after the reveal (optional)" value=${form.explanation} maxLength="200" onInput=${(v) => set({ explanation: v })}
                placeholder="God prepared a great fish to swallow Jonah." />`;
    } else if (kind === 'open') {
        body = html`
            <${In} label="Question" area value=${form.prompt} maxLength="160" error=${e.prompt} onInput=${(v) => set({ prompt: v })} />
            <div class="grid sm:grid-cols-2 gap-3">
                <${In} label="Answer" value=${form.answer} maxLength="60" error=${e.answer} onInput=${(v) => set({ answer: v })} />
                <${In} label="Also accept (comma separated)" value=${form.accept} onInput=${(v) => set({ accept: v })} placeholder="simon peter, simon" />
            </div>`;
    } else if (kind === 'emoji') {
        body = html`
            <${In} label="The emoji" value=${form.emojis} maxLength="60" error=${e.emojis} onInput=${(v) => set({ emojis: v })} placeholder="🌧️🚢🦒🦁🌈" />
            <div class="grid sm:grid-cols-2 gap-3">
                <${In} label="Answer" value=${form.answer} maxLength="60" error=${e.answer} onInput=${(v) => set({ answer: v })} />
                <${In} label="Also accept (comma separated)" value=${form.accept} onInput=${(v) => set({ accept: v })} />
            </div>
            <${Check} label="Also give answers to choose from" hint="Needed if this puzzle should appear in the Live Quiz."
                checked=${form.with_choices} onChange=${(v) => set({ with_choices: v })} />
            ${form.with_choices ? html`<${Choices} form=${form} set=${set} errors=${e} />` : null}`;
    } else if (kind === 'verse') {
        body = html`
            <${VerseFields} eventId=${eventId} form=${form} set=${set} errors=${e} ref=${ref} setRef=${setRef} />
            <${Check} label="Also give endings to choose from" hint="Needed if this verse should appear in the Live Quiz or Trivia."
                checked=${form.with_choices} onChange=${(v) => set({ with_choices: v })} />
            ${form.with_choices ? html`<${Choices} form=${form} set=${set} errors=${e} />` : null}`;
    } else if (kind === 'clues') {
        body = html`
            <fieldset class="space-y-2">
                <legend class="block text-xs font-semibold text-gray-600 mb-1">Clues — hardest first (at least three)</legend>
                ${form.clues.map((clue, i) => html`
                    <div key=${i} class="flex items-center gap-2">
                        <span class="w-14 text-xs font-bold text-gray-500">Clue ${i + 1}</span>
                        <input class="flex-1 px-3.5 py-2 rounded-xl border border-gray-200 focus:border-hodBlue outline-none text-sm"
                            maxLength="90" value=${clue} placeholder=${i < 3 ? 'Required' : 'Optional'}
                            onInput=${(ev) => { const next = [...form.clues]; next[i] = ev.currentTarget.value; set({ clues: next }); }} />
                    </div>`)}
                ${e.clues ? html`<p class="text-xs font-semibold text-hodRed" role="alert">${e.clues}</p>` : null}
            </fieldset>
            <div class="grid sm:grid-cols-2 gap-3">
                <${In} label="Who is it?" value=${form.answer} maxLength="40" error=${e.answer} onInput=${(v) => set({ answer: v })} />
                <${In} label="Also accept (comma separated)" value=${form.accept} onInput=${(v) => set({ accept: v })} />
            </div>`;
    } else if (kind === 'charade') {
        body = html`
            <div class="grid sm:grid-cols-2 gap-3">
                <${In} label="Phrase to act out" value=${form.phrase} maxLength="40" error=${e.phrase} onInput=${(v) => set({ phrase: v })}
                    placeholder="Zacchaeus climbing a tree" />
                <${Pick} label="Category" value=${form.category} onChange=${(v) => set({ category: v })}
                    options=${[['person', 'Person'], ['story', 'Story'], ['object', 'Object'], ['place', 'Place'], ['miracle', 'Miracle'], ['parable', 'Parable']]} />
            </div>
            <${In} label="Hint for the presenter (optional)" value=${form.hint} maxLength="60" onInput=${(v) => set({ hint: v })} />`;
    } else if (kind === 'survey') {
        body = html`
            <${In} label="Survey question" value=${form.question} maxLength="100" error=${e.question} onInput=${(v) => set({ question: v })}
                placeholder="Name something you'd find on Noah's Ark." />`;
    }

    return html`
        <div class="rounded-2xl border-2 border-hodBlue/20 bg-blue-50/30 p-5 space-y-4">
            <p class="text-sm font-bold text-gray-900">${item ? 'Edit question' : 'New question'}</p>
            ${body}
            ${kind !== 'verse' && kind !== 'survey' ? html`
                <${In} label="Bible reference (optional)" value=${ref} maxLength="60" onInput=${setRef} placeholder="Jonah 1:17"
                    hint="Shown with the answer on stage." />` : null}
            <div class="flex flex-wrap gap-2">
                <${Button} loading=${busy} onClick=${save}>${item ? 'Save' : 'Add question'}</${Button}>
                <${Button} variant="ghost" onClick=${onCancel}>Cancel</${Button}>
            </div>
        </div>`;
}

/** AI drafts for one bank: ask, review, keep the good ones. */
function AiDrafts({ eventId, deck, onAdded, onClose }) {
    const [topic, setTopic] = useState('');
    const [count, setCount] = useState(8);
    const [busy, setBusy] = useState(false);
    const [draft, setDraft] = useState(null);
    const [keep, setKeep] = useState({});

    const generate = async () => {
        setBusy(true);
        const res = await act('deck_generate', { id: eventId, deck_id: deck.id, topic, count: Number(count) });
        setBusy(false);
        if (!res.ok) return;
        setDraft(res.data);
        const picked = {};
        res.data.items.forEach((item, i) => { if (item.ok) picked[i] = true; });
        setKeep(picked);
    };

    const apply = async () => {
        const indexes = Object.keys(keep).filter((i) => keep[i]).map(Number);
        if (!indexes.length) return;
        setBusy(true);
        const res = await act('deck_generate_apply', { id: eventId, job_id: draft.job_id, deck_id: deck.id, indexes });
        setBusy(false);
        if (res.ok) onAdded(res.data.created);
    };

    return html`
        <div class="rounded-2xl border-2 border-violet-200 bg-violet-50/40 p-5 space-y-4">
            <div class="flex justify-between gap-3">
                <p class="text-sm font-bold text-gray-900">✨ Draft questions with AI</p>
                <${Link} onClick=${onClose}>Close</${Link}>
            </div>
            ${!draft ? html`
                <div class="grid sm:grid-cols-[1fr_8rem_auto] gap-3 items-end">
                    <${In} label="Topic" value=${topic} onInput=${setTopic} maxLength="160" placeholder="Miracles of Jesus, women of the Bible…" />
                    <${In} label="How many" type="number" min="1" max="15" value=${count} onInput=${setCount} />
                    <${Button} loading=${busy} onClick=${generate}>Draft</${Button}>
                </div>
                <p class="text-xs text-gray-500">Nothing is added until you tick it. References are checked against the KJV; verse text is fetched, never written by the AI.</p>` : html`
                <ul class="space-y-2">
                    ${draft.items.map((item, i) => html`
                        <li key=${i} class=${'rounded-xl border p-3 flex gap-3 ' + (item.ok ? 'bg-white border-gray-200' : 'bg-red-50/60 border-red-200')}>
                            <input type="checkbox" class="mt-1 w-4 h-4" disabled=${!item.ok} checked=${!!keep[i]}
                                aria-label=${'Keep ' + item.summary}
                                onChange=${(e) => setKeep({ ...keep, [i]: e.currentTarget.checked })} />
                            <div class="min-w-0 space-y-0.5">
                                <p class="text-sm font-semibold text-gray-900">${item.summary || '(empty)'}</p>
                                ${item.answer && item.answer !== item.summary ? html`<p class="text-xs text-emerald-700">Answer: ${item.answer}</p>` : null}
                                ${item.scripture_ref ? html`<p class="text-xs text-gray-500">${item.scripture_ref}${item.scripture_text ? ' — ' + item.scripture_text.slice(0, 140) : ''}</p>` : null}
                                ${[...item.errors, ...item.flags].map((m) => html`<p class="text-xs font-semibold text-hodRed">${m}</p>`)}
                            </div>
                        </li>`)}
                </ul>
                <div class="flex flex-wrap gap-2">
                    <${Button} loading=${busy} onClick=${apply}>Add ${Object.values(keep).filter(Boolean).length} to “${deck.title}”</${Button}>
                    <${Button} variant="ghost" onClick=${() => setDraft(null)}>Try again</${Button}>
                </div>`}
        </div>`;
}

/** One bank: its questions, with add, edit, approve and delete. */
function DeckView({ eventId, deckId, ai, onChanged, onDeleted }) {
    const [data, setData] = useState(null);
    const [editing, setEditing] = useState(null);   // null | 'new' | item
    const [aiOpen, setAiOpen] = useState(false);

    const load = async () => {
        const res = await act('deck_items', { id: eventId, deck_id: deckId });
        if (res.ok) setData(res.data);
    };
    useEffect(() => { setData(null); setEditing(null); setAiOpen(false); load(); }, [deckId]);

    if (!data) return html`<${Spinner} label="Opening the bank…" />`;
    const { deck, items } = data;
    const drafts = items.filter((i) => i.review_status === 'draft');

    const review = async (ids, status) => {
        const res = await act('deck_items_review', { id: eventId, ids, status });
        if (res.ok) { await load(); onChanged(); }
    };
    const remove = async (item) => {
        if (!confirm('Delete this question?\n\n' + item.summary)) return;
        const res = await act('deck_item_delete', { id: eventId, item_id: item.id });
        if (res.ok) { await load(); onChanged(); }
    };
    const removeDeck = async () => {
        if (!confirm(`Delete the bank “${deck.title}” and its ${items.length} questions?`)) return;
        const res = await act('deck_delete', { id: eventId, deck_id: deck.id });
        if (res.ok) onDeleted();
    };

    return html`
        <div class="space-y-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h3 class="font-display font-bold text-gray-900">${deck.title}</h3>
                    <p class="text-xs text-gray-500">${deck.content_label} · ${KIND_HELP[deck.content_type] || ''}</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <${Button} onClick=${() => { setAiOpen(false); setEditing('new'); }}>Add a question</${Button}>
                    ${ai ? html`<${Button} variant="secondary" onClick=${() => { setEditing(null); setAiOpen(true); }}>✨ Draft with AI</${Button}>` : null}
                </div>
            </div>

            ${drafts.length ? html`
                <div class="rounded-xl bg-amber-50 border border-amber-200 px-4 py-3 flex flex-wrap items-center justify-between gap-2">
                    <p class="text-sm text-amber-900"><strong>${drafts.length}</strong> question${drafts.length === 1 ? '' : 's'} waiting for review — games only use approved questions.</p>
                    <${Button} variant="secondary" onClick=${() => review(drafts.map((d) => d.id), 'approved')}>Approve all</${Button}>
                </div>` : null}

            ${editing ? html`<${ItemForm} eventId=${eventId} deck=${deck} item=${editing === 'new' ? null : editing}
                onCancel=${() => setEditing(null)}
                onSaved=${async () => { setEditing(null); await load(); onChanged(); }} />` : null}
            ${aiOpen ? html`<${AiDrafts} eventId=${eventId} deck=${deck} onClose=${() => setAiOpen(false)}
                onAdded=${async () => { setAiOpen(false); await load(); onChanged(); }} />` : null}

            ${items.length ? html`
                <ol class="divide-y divide-gray-100 rounded-2xl border border-gray-100">
                    ${items.map((item, n) => html`
                        <li key=${item.id} class="p-4 flex flex-wrap gap-3 items-start">
                            <span class="w-7 text-sm font-bold text-gray-400">${n + 1}</span>
                            <div class="flex-1 min-w-[14rem] space-y-0.5">
                                <p class="text-sm font-semibold text-gray-900">${item.summary}</p>
                                ${item.answer && item.answer !== item.summary ? html`<p class="text-xs text-emerald-700">Answer: ${item.answer}</p>` : null}
                                <p class="text-xs text-gray-400">
                                    ${[item.scripture_ref, item.source === 'ai' ? 'AI draft' : null, item.in_games ? 'in ' + item.in_games + ' game' + (item.in_games === 1 ? '' : 's') : null].filter(Boolean).join(' · ')}
                                </p>
                            </div>
                            <${Chip} status=${item.review_status} label=${item.review_status === 'draft' ? 'Draft' : null} />
                            <div class="flex gap-3">
                                ${item.review_status !== 'approved' ? html`<${Link} onClick=${() => review([item.id], 'approved')}>Approve</${Link}>` : null}
                                ${item.review_status === 'draft' && !item.in_games ? html`<${Link} danger onClick=${() => review([item.id], 'rejected')}>Reject</${Link}>` : null}
                                <${Link} onClick=${() => { setAiOpen(false); setEditing(item); }}>Edit</${Link}>
                                <${Link} danger disabled=${item.in_games > 0} onClick=${() => remove(item)}>Delete</${Link}>
                            </div>
                        </li>`)}
                </ol>` : !editing && !aiOpen ? html`
                <p class="text-sm text-gray-500 py-6 text-center">No questions yet — add one${ai ? ', or draft a few with AI' : ''}.</p>` : null}

            ${deck.scope === 'event' ? html`<div class="pt-2"><${Link} danger onClick=${removeDeck}>Delete this bank</${Link}></div>` : null}
        </div>`;
}

/** The banks list and the open bank. */
export function BanksSection({ eventId, overview, reload }) {
    const decks = overview.decks || [];
    const [openId, setOpenId] = useState(decks[0]?.id || null);
    const [title, setTitle] = useState('');
    const [kind, setKind] = useState('mcq');
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        if (openId && !decks.some((d) => d.id === openId)) setOpenId(decks[0]?.id || null);
        if (!openId && decks.length) setOpenId(decks[0].id);
    }, [decks.length]);

    const create = async () => {
        setBusy(true);
        const res = await act('deck_save', { id: eventId, title, content_type: kind, scope: 'event' });
        setBusy(false);
        if (res.ok) {
            setTitle('');
            await reload();
            setOpenId(res.data.deck.id);
        }
    };

    const kinds = Object.entries(overview.content_types || {});

    return html`
        <div class="grid lg:grid-cols-[18rem_1fr] gap-6">
            <aside class="space-y-4">
                <nav class="space-y-1" aria-label="Question banks">
                    ${decks.map((deck) => html`
                        <button key=${deck.id} type="button" onClick=${() => setOpenId(deck.id)}
                            class=${'w-full text-left px-4 py-3 rounded-2xl border transition-colors '
                                + (deck.id === openId ? 'bg-hodBlue text-white border-hodBlue' : 'bg-white border-gray-100 hover:border-hodBlue')}>
                            <span class="block text-sm font-bold">${deck.title}</span>
                            <span class=${'block text-xs ' + (deck.id === openId ? 'text-white/80' : 'text-gray-500')}>
                                ${deck.content_label} · ${deck.items_approved} approved${deck.items_draft ? ' · ' + deck.items_draft + ' to review' : ''}
                            </span>
                        </button>`)}
                </nav>
                <div class="rounded-2xl bg-gray-50 p-4 space-y-3">
                    <p class="text-sm font-bold text-gray-900">New bank</p>
                    <${In} label="Name" value=${title} onInput=${setTitle} maxLength="120" placeholder="Old Testament heroes" />
                    <${Pick} label="Kind of question" value=${kind} onChange=${setKind} options=${kinds} />
                    <p class="text-xs text-gray-500">${KIND_HELP[kind]}</p>
                    <${Button} loading=${busy} disabled=${!title.trim()} onClick=${create}>Create bank</${Button}>
                </div>
            </aside>
            <section class="bg-white rounded-3xl border border-gray-100 p-6 min-w-0">
                ${openId
                    ? html`<${DeckView} eventId=${eventId} deckId=${openId} ai=${overview.ai}
                        onChanged=${reload} onDeleted=${async () => { setOpenId(null); await reload(); }} />`
                    : html`<p class="text-sm text-gray-500 py-10 text-center">Create a bank to start adding questions.</p>`}
            </section>
        </div>`;
}
