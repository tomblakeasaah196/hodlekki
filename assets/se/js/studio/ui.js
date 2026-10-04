// /assets/se/js/studio/ui.js
//
// Shared Studio controls, in the ERP's look (guide §13.13): white cards,
// rounded-3xl, hodBlue accents, Tailwind classes from the CDN that
// header.php already loads.

import { html } from '@se/core/html.js';
import { fieldErrors } from './state.js';

/** A labelled field with inline error text wired to aria-describedby. */
export function Field({ label, name, hint, required, children }) {
    const error = fieldErrors.value[name];
    const errorId = error ? `se-err-${name}` : undefined;

    return html`
        <div class="space-y-1.5">
            <label class="block text-sm font-semibold text-gray-700" for=${'se-f-' + name}>
                ${label}${required ? html`<span class="text-hodRed ml-0.5" aria-hidden="true">*</span>` : null}
            </label>
            ${children}
            ${hint && !error ? html`<p class="text-xs text-gray-500">${hint}</p>` : null}
            ${error ? html`<p id=${errorId} class="text-xs font-semibold text-hodRed" role="alert">${error}</p>` : null}
        </div>`;
}

export function TextInput({ name, value, onInput, placeholder, type = 'text', maxLength, disabled, autocomplete }) {
    const error = fieldErrors.value[name];

    return html`
        <input
            id=${'se-f-' + name}
            name=${name}
            type=${type}
            value=${value ?? ''}
            placeholder=${placeholder || ''}
            maxLength=${maxLength}
            disabled=${disabled}
            autocomplete=${autocomplete}
            aria-invalid=${error ? 'true' : undefined}
            aria-describedby=${error ? `se-err-${name}` : undefined}
            onInput=${(e) => onInput?.(e.currentTarget.value)}
            class=${'w-full px-4 py-3 rounded-xl border outline-none transition-colors bg-white '
                + (error ? 'border-hodRed focus:border-hodRed' : 'border-gray-200 focus:border-hodBlue')
                + (disabled ? ' bg-gray-50 text-gray-400' : '')} />`;
}

export function TextArea({ name, value, onInput, rows = 5, placeholder, maxLength }) {
    const error = fieldErrors.value[name];

    return html`
        <textarea
            id=${'se-f-' + name}
            name=${name}
            rows=${rows}
            placeholder=${placeholder || ''}
            maxLength=${maxLength}
            aria-invalid=${error ? 'true' : undefined}
            aria-describedby=${error ? `se-err-${name}` : undefined}
            onInput=${(e) => onInput?.(e.currentTarget.value)}
            class=${'w-full px-4 py-3 rounded-xl border outline-none transition-colors bg-white font-mono text-sm '
                + (error ? 'border-hodRed focus:border-hodRed' : 'border-gray-200 focus:border-hodBlue')}
        >${value ?? ''}</textarea>`;
}

export function Select({ name, value, onChange, options, disabled }) {
    return html`
        <select
            id=${'se-f-' + name}
            name=${name}
            disabled=${disabled}
            onChange=${(e) => onChange?.(e.currentTarget.value)}
            class="w-full px-4 py-3 rounded-xl border border-gray-200 outline-none focus:border-hodBlue bg-white font-medium disabled:bg-gray-50 disabled:text-gray-400">
            ${options.map((o) => html`
                <option key=${o.value} value=${o.value} selected=${String(o.value) === String(value)}>${o.label}</option>`)}
        </select>`;
}

export function Switch({ label, checked, onChange, hint, disabled }) {
    return html`
        <label class=${'flex items-start gap-3 ' + (disabled ? 'opacity-60' : 'cursor-pointer')}>
            <input type="checkbox" checked=${!!checked} disabled=${disabled}
                onChange=${(e) => onChange?.(e.currentTarget.checked)}
                class="mt-1 w-5 h-5 rounded border-gray-300 text-hodBlue focus:ring-hodBlue shrink-0" />
            <span>
                <span class="block text-sm font-semibold text-gray-800">${label}</span>
                ${hint ? html`<span class="block text-xs text-gray-500 mt-0.5">${hint}</span>` : null}
            </span>
        </label>`;
}

export function Button({ children, onClick, variant = 'primary', disabled, loading, type = 'button', title }) {
    const base = 'inline-flex items-center justify-center gap-2 px-5 py-2.5 rounded-xl font-semibold transition-all disabled:opacity-50 disabled:cursor-not-allowed min-h-[44px]';
    const styles = {
        primary:   'bg-hodBlue text-white hover:bg-blue-900 shadow-sm',
        secondary: 'bg-white text-hodBlue border border-gray-200 hover:border-hodBlue',
        danger:    'bg-hodRed text-white hover:bg-red-700 shadow-sm',
        ghost:     'text-gray-600 hover:text-hodBlue hover:bg-blue-50',
    };

    return html`
        <button type=${type} title=${title} disabled=${disabled || loading} onClick=${onClick}
                class=${base + ' ' + (styles[variant] || styles.primary)}>
            ${loading ? html`<span class="w-4 h-4 border-2 border-current border-t-transparent rounded-full animate-spin" aria-hidden="true"></span>` : null}
            ${children}
        </button>`;
}

export function Card({ title, subtitle, children, actions, id }) {
    return html`
        <section id=${id} class="bg-white rounded-3xl shadow-sm border border-gray-100 p-6 sm:p-8">
            ${title ? html`
            <header class="flex flex-wrap items-start justify-between gap-3 mb-6">
                <div>
                    <h2 class="text-lg font-display font-bold text-gray-900">${title}</h2>
                    ${subtitle ? html`<p class="text-sm text-gray-500 mt-1">${subtitle}</p>` : null}
                </div>
                ${actions ? html`<div class="flex flex-wrap gap-2">${actions}</div>` : null}
            </header>` : null}
            ${children}
        </section>`;
}

export function StatusPill({ status }) {
    const styles = {
        draft:     'bg-gray-100 text-gray-700',
        published: 'bg-emerald-50 text-emerald-700',
        cancelled: 'bg-red-50 text-hodRed',
        archived:  'bg-amber-50 text-amber-700',
    };
    const labels = { draft: 'Draft', published: 'Live', cancelled: 'Cancelled', archived: 'Archived' };

    return html`
        <span class=${'px-2.5 py-1 rounded-full text-xs font-bold ' + (styles[status] || styles.draft)}>
            ${labels[status] || status}
        </span>`;
}

export function EmptyState({ title, message, action }) {
    return html`
        <div class="text-center py-12">
            <div class="w-14 h-14 rounded-2xl bg-blue-50 text-hodBlue flex items-center justify-center mx-auto mb-4">
                <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
            </div>
            <h3 class="font-display font-bold text-gray-900">${title}</h3>
            <p class="text-sm text-gray-500 mt-1 max-w-sm mx-auto">${message}</p>
            ${action ? html`<div class="mt-5">${action}</div>` : null}
        </div>`;
}

export function Spinner({ label = 'Loading…' }) {
    return html`
        <div class="flex items-center justify-center gap-3 py-12 text-gray-500" role="status">
            <span class="w-5 h-5 border-2 border-hodBlue border-t-transparent rounded-full animate-spin" aria-hidden="true"></span>
            <span class="text-sm font-medium">${label}</span>
        </div>`;
}

/** A hex colour input: swatch + paste-friendly text box (§13.13 Brand). */
export function HexInput({ name, value, onInput, label, hint, placeholder = '#RRGGBB' }) {
    const valid = /^#[0-9A-Fa-f]{6}$/.test(value || '');

    return html`
        <${Field} label=${label} name=${name} hint=${hint}>
            <div class="flex items-center gap-3">
                <input type="color" value=${valid ? value : '#000000'} aria-label=${label + ' swatch'}
                    onInput=${(e) => onInput?.(e.currentTarget.value.toUpperCase())}
                    class="w-12 h-12 rounded-xl border border-gray-200 cursor-pointer shrink-0 bg-white" />
                <input id=${'se-f-' + name} name=${name} type="text" value=${value ?? ''}
                    placeholder=${placeholder} maxLength="7" spellcheck="false"
                    onInput=${(e) => onInput?.(e.currentTarget.value.toUpperCase())}
                    class=${'flex-1 px-4 py-3 rounded-xl border outline-none font-mono uppercase bg-white '
                        + (valid || !value ? 'border-gray-200 focus:border-hodBlue' : 'border-hodRed')} />
            </div>
        <//>`;
}

/** The sticky save bar every tab shows while it has unsaved edits (§13.13). */
export function SaveBar({ dirty, saving, onSave, onDiscard, note }) {
    if (!dirty) return null;

    return html`
        <div class="sticky bottom-4 z-30 mt-6">
            <div class="bg-hodBlue text-white rounded-2xl shadow-xl px-5 py-4 flex flex-wrap items-center justify-between gap-3">
                <span class="text-sm font-semibold">${note || 'Unsaved changes'}</span>
                <div class="flex gap-2">
                    <button type="button" onClick=${onDiscard} disabled=${saving}
                        class="px-4 py-2 rounded-xl text-sm font-semibold text-white/80 hover:text-white hover:bg-white/10 transition-colors disabled:opacity-50">
                        Discard
                    </button>
                    <button type="button" onClick=${onSave} disabled=${saving}
                        class="px-5 py-2 rounded-xl bg-white text-hodBlue text-sm font-bold hover:bg-blue-50 transition-colors disabled:opacity-50 inline-flex items-center gap-2">
                        ${saving ? html`<span class="w-4 h-4 border-2 border-hodBlue border-t-transparent rounded-full animate-spin" aria-hidden="true"></span>` : null}
                        Save
                    </button>
                </div>
            </div>
        </div>`;
}
