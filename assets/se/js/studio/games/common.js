// /assets/se/js/studio/games/common.js
//
// Small pieces shared by the Games tab: plain-language labels, the status
// chip, compact inputs with their own error text, and an API wrapper that
// turns a VALIDATION reply into per-field messages.

import { html } from '@se/core/html.js';
import { useState } from 'preact/hooks';
import { studio, SeApiError } from '@se/core/api.js';
import { toast } from '../state.js';

export const TYPE_ICON = {
    live_quiz: '⚡', trivia: '🧠', buzzer: '🔔', who_am_i: '🕵️', charades: '🎭', feud: '📋',
};

export const TYPE_HELP = {
    live_quiz: 'Everyone answers on their own phone; faster right answers score more. Plays multiple-choice questions.',
    trivia: 'One answer per team, locked in by the team captain while teammates suggest. Plays multiple-choice questions.',
    buzzer: 'The first team to buzz answers out loud, and you judge it right or wrong.',
    who_am_i: 'Clues appear one at a time — the earlier a team buzzes in with the right person, the more it scores.',
    charades: 'One player acts out phrases that only their phone shows; their team guesses out loud.',
    feud: 'Survey says! Teams guess the most popular answers the guests gave in the survey.',
};

export const KIND_HELP = {
    mcq: 'A question with two to four answers to choose from.',
    open: 'A question the host reads out; teams answer out loud.',
    emoji: 'A Bible story told in emoji.',
    verse: 'The room hears the start of a verse and finishes it. The text comes from the KJV.',
    clues: 'Three to five clues about a Bible person, hardest first.',
    charade: 'A phrase for one player to act out.',
    survey: 'A Family Feud question the guests answer before the game.',
};

export const STATUS_STYLE = {
    draft: ['bg-gray-100 text-gray-700', 'Needs questions'],
    ready: ['bg-blue-50 text-hodBlue', 'Ready'],
    live: ['bg-emerald-50 text-emerald-700', 'Playing now'],
    paused: ['bg-amber-50 text-amber-700', 'Paused'],
    finished: ['bg-gray-100 text-gray-500', 'Finished'],
    approved: ['bg-emerald-50 text-emerald-700', 'Approved'],
    rejected: ['bg-red-50 text-hodRed', 'Rejected'],
};

export function Chip({ status, label }) {
    const [style, text] = STATUS_STYLE[status] || STATUS_STYLE.draft;
    return html`<span class=${'px-2.5 py-1 rounded-full text-xs font-bold whitespace-nowrap ' + style}>${label || text}</span>`;
}

/** Call the Studio API; on failure, toast and return the field errors. */
export async function act(action, payload, { quiet = false } = {}) {
    try {
        const data = await studio(action, payload);
        return { ok: true, data };
    } catch (e) {
        const fields = e instanceof SeApiError ? e.fields : {};
        if (!quiet) toast(e.message || 'That did not work.', 'error');
        return { ok: false, error: e, fields };
    }
}

const INPUT = 'w-full px-3.5 py-2.5 rounded-xl border outline-none transition-colors bg-white text-sm';

/** A compact labelled input with its own error line. */
let fieldSeq = 0;
const useFieldId = (id) => useState(() => id || 'se-g-' + (++fieldSeq))[0];

export function In({ label, value, onInput, error, hint, placeholder, type = 'text', maxLength, min, max, step, id, area, rows = 2 }) {
    const fieldId = useFieldId(id);
    const cls = INPUT + ' ' + (error ? 'border-hodRed' : 'border-gray-200 focus:border-hodBlue');
    return html`
        <div class="space-y-1">
            ${label ? html`<label class="block text-xs font-semibold text-gray-600" for=${fieldId}>${label}</label>` : null}
            ${area
                ? html`<textarea id=${fieldId} class=${cls} rows=${rows} maxLength=${maxLength} placeholder=${placeholder || ''}
                        value=${value ?? ''} onInput=${(e) => onInput(e.currentTarget.value)}></textarea>`
                : html`<input id=${fieldId} class=${cls} type=${type} maxLength=${maxLength} min=${min} max=${max} step=${step}
                        placeholder=${placeholder || ''} value=${value ?? ''} onInput=${(e) => onInput(e.currentTarget.value)} />`}
            ${error ? html`<p class="text-xs font-semibold text-hodRed" role="alert">${error}</p>`
                : hint ? html`<p class="text-xs text-gray-500">${hint}</p>` : null}
        </div>`;
}

export function Pick({ label, value, onChange, options, id }) {
    const fieldId = useFieldId(id);
    return html`
        <div class="space-y-1">
            ${label ? html`<label class="block text-xs font-semibold text-gray-600" for=${fieldId}>${label}</label>` : null}
            <select id=${fieldId} class=${INPUT + ' border-gray-200 focus:border-hodBlue font-medium'}
                onChange=${(e) => onChange(e.currentTarget.value)}>
                ${options.map(([v, l]) => html`<option key=${v} value=${v} selected=${String(v) === String(value)}>${l}</option>`)}
            </select>
        </div>`;
}

export function Check({ label, checked, onChange, hint }) {
    return html`
        <label class="flex items-start gap-2.5 cursor-pointer">
            <input type="checkbox" checked=${!!checked} onChange=${(e) => onChange(e.currentTarget.checked)}
                class="mt-0.5 w-4 h-4 rounded border-gray-300 text-hodBlue focus:ring-hodBlue shrink-0" />
            <span>
                <span class="block text-sm font-semibold text-gray-800">${label}</span>
                ${hint ? html`<span class="block text-xs text-gray-500">${hint}</span>` : null}
            </span>
        </label>`;
}

/** A small text button. */
export function Link({ children, onClick, danger, disabled }) {
    return html`
        <button type="button" disabled=${disabled} onClick=${onClick}
            class=${'text-sm font-semibold disabled:opacity-40 ' + (danger ? 'text-hodRed hover:underline' : 'text-hodBlue hover:underline')}>
            ${children}
        </button>`;
}
