// /assets/se/js/studio/tabs/checkin.js
//
// Check-in (guide §10.9, §14.2, §15.7): the welcome verses people see on
// their phone when they check in, and the printable QR posters that get
// them there.
//
// The AI never picks a verse on its own. It suggests references, the KJV
// text is fetched by the server, and nothing becomes usable until a human
// says so — the Approve button for hand-added verses, or the suggestion
// picker, whose "Add" saves and approves the verses the producer ticked.

import { html } from '@se/core/html.js';
import { useState, useEffect } from 'preact/hooks';
import { studio, studioUpload } from '@se/core/api.js';
import { current, toast, can } from '../state.js';
import { Card, Button, Spinner, EmptyState, Field, TextInput } from '../ui.js';

// --------------------------------------------------------------------------
// Verses
// --------------------------------------------------------------------------

function Verses() {
    const event = current.value;
    const [verses, setVerses] = useState(null);
    const [error, setError] = useState(null);
    const [ref, setRef] = useState('');
    const [prayer, setPrayer] = useState('');
    const [theme, setTheme] = useState('');
    const [busy, setBusy] = useState(false);
    const [suggesting, setSuggesting] = useState(false);
    const [adding, setAdding] = useState(false);
    // The suggestion round in review: {jobId, theme, dropped, rows}. `rows`
    // holds what the producer can still change; the picker edits it live.
    const [picker, setPicker] = useState(null);
    const [pickerOpen, setPickerOpen] = useState(false);

    const editable = can('event.edit');

    const load = async () => {
        try {
            const data = await studio('verses_list', { id: event.id });
            setVerses(data.verses || []);
        } catch (e) { setError(e.message); }
    };

    useEffect(() => { load(); }, [event.id]);

    if (error) {
        return html`<${Card} title="Welcome verses">
            <${EmptyState} title="Not available yet" message=${error} />
        <//>`;
    }
    if (!verses) return html`<${Spinner} label="Loading verses…" />`;

    const approved = verses.filter((v) => v.approved).length;

    /** Turn a suggestions response into the editable picker state and open it. */
    const openPicker = (result, themeWords) => {
        setPicker({
            jobId: result.job_id || null,
            theme: themeWords.trim(),
            dropped: result.dropped || [],
            rows: (result.verses || []).map((v, i) => ({
                key: i + ':' + v.ref_display,
                ref: v.ref_display,
                lookedUpRef: v.ref_display,
                text: v.text,
                why: v.why || '',
                prayer: v.prayer_template || '',
                alreadyAdded: !!v.already_added,
                picked: !v.already_added,
                checking: false,
                problem: '',
            })),
        });
        setPickerOpen(true);
    };

    const patchRow = (key, patch) =>
        setPicker((p) => p && ({
            ...p,
            rows: p.rows.map((r) => (r.key === key ? { ...r, ...patch } : r)),
        }));

    const dismissPicker = () => {
        setPicker(null);
        setPickerOpen(false);
    };

    /** Save the ticked verses. Accepting approves them (§15.7). */
    const addPicked = async () => {
        if (!picker || adding) return;

        const picks = picker.rows
            .filter((r) => r.picked && !r.alreadyAdded && r.ref.trim() !== '')
            .map((r) => ({ ref: r.ref.replace(/\s+/g, ' ').trim(), prayer_template: r.prayer }));
        if (!picks.length) return;

        setAdding(true);
        try {
            const data = await studio('verses_accept', { id: event.id, job_id: picker.jobId, picks });
            setVerses(data.verses);

            // A rejected pick keeps its row with the server's reason pinned
            // under it; everything else graduated into the list below.
            const reasonByRef = {};
            (data.rejected || []).forEach((rej) => {
                if (rej.ref) reasonByRef[rej.ref] = rej.why;
            });

            const remaining = [];
            for (const row of picker.rows) {
                const submitted = row.picked && !row.alreadyAdded && row.ref.trim() !== '';
                if (!submitted) { remaining.push(row); continue; }
                const key = row.ref.replace(/\s+/g, ' ').trim();
                if (reasonByRef[key]) {
                    remaining.push({ ...row, checking: false, problem: reasonByRef[key] });
                }
            }

            if (remaining.some((r) => !r.alreadyAdded)) {
                setPicker({ ...picker, rows: remaining });
            } else {
                dismissPicker();
            }

            const acceptedCount = (data.accepted || []).length;
            if (acceptedCount) {
                toast(acceptedCount + ' ' + (acceptedCount === 1 ? 'verse' : 'verses') + ' added and approved.', 'success');
            }
            if (reasonByRef && (data.rejected || []).length) {
                toast(data.rejected.length + ' could not be added — see the note in the picker.', 'error');
            }
        } catch (e) {
            toast(e.message, 'error');
        }
        setAdding(false);
    };

    return html`
        <${Card} title="Welcome verses"
            subtitle=${approved
                ? approved + ' approved. Each guest gets one at check-in, spread evenly across the list.'
                : 'Nobody sees a verse until at least one is approved.'}>

            ${editable ? html`
                <div class="grid gap-4 sm:grid-cols-2 mb-6">
                    <${Field} label="Add a reference" name="ref" hint="For example Psalms 16:11. KJV text is fetched for you.">
                        <${TextInput} name="ref" value=${ref} onInput=${setRef} maxLength=${60} />
                    <//>
                    <${Field} label="Prayer line (optional)" name="prayer_template"
                        hint="Must contain {name} — that is where the guest's first name goes.">
                        <${TextInput} name="prayer_template" value=${prayer} onInput=${setPrayer} maxLength=${300} />
                    <//>
                    <div>
                        <${Button} disabled=${busy || !ref.trim()} loading=${busy} onClick=${async () => {
                            setBusy(true);
                            try {
                                const data = await studio('verse_add', { id: event.id, ref, prayer_template: prayer });
                                setVerses(data.verses);
                                setRef(''); setPrayer('');
                                toast('Added.', 'success');
                            } catch (e) { toast(e.message, 'error'); }
                            setBusy(false);
                        }}>Add verse<//>
                    </div>
                </div>` : null}

            ${verses.length
                ? html`<ul class="divide-y divide-gray-100">
                    ${verses.map((verse) => html`
                        <li class="py-4 flex flex-wrap items-start justify-between gap-4" key=${verse.id}>
                            <div class="min-w-0 flex-1">
                                <p class="font-semibold text-gray-900">
                                    ${verse.ref_display}
                                    ${verse.approved
                                        ? html`<span class="ml-2 text-xs font-bold text-emerald-600">approved</span>`
                                        : html`<span class="ml-2 text-xs font-bold text-amber-600">needs review</span>`}
                                    ${verse.ai_suggested ? html`<span class="ml-2 text-xs text-gray-400">suggested by AI</span>` : null}
                                </p>
                                <p class="text-sm text-gray-600 mt-1">${verse.text}</p>
                                ${verse.prayer_template
                                    ? html`<p class="text-xs text-gray-400 mt-1 italic">${verse.prayer_template}</p>`
                                    : null}
                            </div>
                            ${editable ? html`
                                <div class="flex gap-2 shrink-0">
                                    ${!verse.approved ? html`
                                        <${Button} variant="secondary" onClick=${async () => {
                                            try {
                                                const data = await studio('verse_approve', { id: event.id, verse_id: verse.id });
                                                setVerses(data.verses);
                                            } catch (e) { toast(e.message, 'error'); }
                                        }}>Approve<//>` : null}
                                    <${Button} variant="ghost" onClick=${async () => {
                                        try {
                                            const data = await studio('verse_delete', { id: event.id, verse_id: verse.id });
                                            setVerses(data.verses);
                                        } catch (e) { toast(e.message, 'error'); }
                                    }}>Remove<//>
                                </div>` : null}
                        </li>`)}
                </ul>`
                : html`<${EmptyState} title="No verses yet"
                    message="Add a few references, or ask for suggestions below." />`}

            ${editable ? html`
                <div class="mt-8 pt-6 border-t border-gray-100 space-y-4">
                    <p class="text-sm font-semibold text-gray-700">Ask for suggestions</p>
                    <p class="text-sm text-gray-500">
                        The AI only sees the words you type here — never a guest's name, number or answers.
                        It proposes references; the KJV text comes from the server and opens in a picker,
                        and nothing is used until you add it there.
                    </p>
                    <div class="flex flex-wrap gap-3 items-end">
                        <div class="flex-1 min-w-[16rem]">
                            <${Field} label="Theme" name="theme" hint="For example: joy, new beginnings, courage.">
                                <${TextInput} name="theme" value=${theme} onInput=${setTheme} maxLength=${160} />
                            <//>
                        </div>
                        <${Button} variant="secondary" loading=${suggesting} disabled=${suggesting || !theme.trim()}
                            onClick=${async () => {
                                setSuggesting(true);
                                try {
                                    openPicker(await studio('verses_suggest', { id: event.id, theme, count: 8 }), theme);
                                } catch (e) { toast(e.message, 'error'); }
                                setSuggesting(false);
                            }}>Suggest verses<//>
                    </div>

                    ${picker && !pickerOpen ? html`
                        <div class="rounded-2xl bg-blue-50 border border-blue-100 p-4 flex flex-wrap items-center justify-between gap-3">
                            <p class="text-sm font-semibold text-hodBlue">
                                Verse suggestions for “${picker.theme}” are waiting. Nothing is saved yet.
                            </p>
                            <div class="flex gap-2">
                                <${Button} variant="secondary" onClick=${() => setPickerOpen(true)}>Review them<//>
                                <${Button} variant="ghost" onClick=${dismissPicker}>Dismiss<//>
                            </div>
                        </div>` : null}
                </div>` : null}

            ${picker && pickerOpen ? html`
                <${SuggestionPicker} picker=${picker} eventId=${event.id} adding=${adding}
                    onPatch=${patchRow} onClose=${() => setPickerOpen(false)}
                    onDismiss=${dismissPicker} onAdd=${addPicked} />` : null}
        <//>`;
}

// --------------------------------------------------------------------------
// The suggestion picker (§15.7): a modal that opens the moment the AI
// answers, so the producer ticks, fixes and adds — never blind-accepts.
// --------------------------------------------------------------------------

function SuggestionPicker({ picker, eventId, adding, onPatch, onClose, onDismiss, onAdd }) {
    // Esc closes the picker; the suggestions wait behind the review chip, so
    // a stray keypress loses nothing.
    useEffect(() => {
        const onKey = (e) => { if (e.key === 'Escape') onClose(); };
        document.addEventListener('keydown', onKey);
        return () => document.removeEventListener('keydown', onKey);
    }, []);

    const pickedCount = picker.rows.filter(
        (r) => r.picked && !r.alreadyAdded && r.ref.trim() !== ''
    ).length;

    return html`
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4" data-app-modal data-modal-ignore>
            <button type="button" aria-label="Close" class="absolute inset-0 bg-gray-900/40"
                    onClick=${onClose}></button>
            <div role="dialog" aria-modal="true" aria-label="Verse suggestions"
                 class="relative bg-white rounded-3xl shadow-2xl w-full max-w-3xl max-h-[90vh] flex flex-col">

                <header class="flex items-start justify-between gap-3 px-6 pt-6 pb-4 border-b border-gray-100">
                    <div>
                        <h3 class="font-display font-bold text-gray-900">Verses for “${picker.theme}”</h3>
                        <p class="text-sm text-gray-500 mt-1">
                            Tick the ones you want, and fix anything the AI got wrong — the KJV text
                            re-fetches as you edit a reference. Whatever you add is approved and can
                            greet a guest straight away.
                        </p>
                    </div>
                    <button type="button" onClick=${onClose} aria-label="Close"
                            class="text-gray-400 hover:text-gray-900 p-2 shrink-0">✕</button>
                </header>

                <div class="flex-1 overflow-y-auto px-6 py-4">
                    <ul class="space-y-3">
                        ${picker.rows.map((row) => html`
                            <${SuggestionRow} key=${row.key} row=${row} eventId=${eventId}
                                onPatch=${onPatch} />`)}
                    </ul>

                    ${picker.dropped.length ? html`
                        <div class="mt-4 rounded-2xl bg-gray-50 p-4">
                            <p class="text-xs font-semibold text-gray-500">
                                ${picker.dropped.length} more from the AI never made it this far:
                            </p>
                            <ul class="text-xs text-gray-400 list-disc pl-5 mt-1 space-y-0.5">
                                ${picker.dropped.map((d, i) => html`<li key=${i}>${d.ref} — ${d.why}</li>`)}
                            </ul>
                        </div>` : null}
                </div>

                <footer class="flex flex-wrap items-center justify-between gap-3 px-6 py-4 border-t border-gray-100">
                    <${Button} variant="ghost" onClick=${onDismiss}>Discard suggestions<//>
                    <div class="flex gap-2">
                        <${Button} variant="secondary" onClick=${onClose}>Not now<//>
                        <${Button} loading=${adding} disabled=${adding || !pickedCount}
                            onClick=${onAdd}>Add ${pickedCount} ${pickedCount === 1 ? 'verse' : 'verses'}<//>
                    </div>
                </footer>
            </div>
        </div>`;
}

function SuggestionRow({ row, eventId, onPatch }) {
    // Editing the reference re-fetches the KJV text after a short pause, so
    // what the producer sees is always the server's text, never the AI's.
    useEffect(() => {
        if (row.alreadyAdded) return undefined;
        const refNow = row.ref.replace(/\s+/g, ' ').trim();
        if (refNow === '' || refNow === row.lookedUpRef) {
            // Back at the fetched reference (or blank): cancel any pending
            // lookup state so the row is not left "Fetching…" forever.
            if (row.checking || row.problem) onPatch(row.key, { checking: false, problem: '' });
            return undefined;
        }

        onPatch(row.key, { checking: true, problem: '' });
        const timer = setTimeout(async () => {
            try {
                const found = await studio('bible_lookup', { id: eventId, ref: refNow });
                onPatch(row.key, { checking: false, lookedUpRef: refNow, text: found.text, problem: '' });
            } catch (e) {
                onPatch(row.key, { checking: false, lookedUpRef: refNow, text: '', problem: e.message });
            }
        }, 600);
        return () => clearTimeout(timer);
    }, [row.ref]);

    return html`
        <li class=${'rounded-2xl border p-4 transition-colors ' + (
            row.alreadyAdded ? 'border-gray-100 bg-gray-50'
            : row.picked ? 'border-hodBlue/40 bg-blue-50/50'
            : 'border-gray-100 bg-white')}>
            <div class="flex items-start gap-3">
                <input type="checkbox" checked=${row.picked} disabled=${row.alreadyAdded}
                    aria-label=${'Use ' + row.ref}
                    onChange=${(e) => onPatch(row.key, { picked: e.currentTarget.checked })}
                    class="mt-2.5 w-4 h-4 shrink-0 accent-hodBlue disabled:opacity-40" />
                <div class="flex-1 min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <input type="text" value=${row.ref} maxLength="60" spellcheck="false"
                            disabled=${row.alreadyAdded} aria-label="Reference"
                            onInput=${(e) => onPatch(row.key, { ref: e.currentTarget.value })}
                            class=${'w-44 px-3 py-2 rounded-xl border outline-none text-sm font-semibold disabled:bg-gray-100 disabled:text-gray-400 '
                                + (row.problem ? 'border-hodRed' : 'border-gray-200 focus:border-hodBlue')} />
                        ${row.alreadyAdded
                            ? html`<span class="text-xs font-bold text-gray-400">already in your list</span>`
                            : row.why ? html`<span class="text-xs text-gray-400 italic">${row.why}</span>` : null}
                    </div>
                    ${row.checking
                        ? html`<p class="text-sm text-gray-400 mt-2">Fetching the KJV text…</p>`
                        : row.problem
                            ? html`<p class="text-sm text-hodRed mt-2">${row.problem}</p>`
                            : row.text
                                ? html`<p class="text-sm text-gray-600 mt-2">${row.text}</p>`
                                : html`<p class="text-sm text-gray-400 mt-2">No KJV text for that reference — fix it above.</p>`}
                    ${row.alreadyAdded ? null : html`
                        <input type="text" value=${row.prayer} maxLength="300"
                            placeholder="Prayer line with {name} — optional" aria-label="Prayer line"
                            onInput=${(e) => onPatch(row.key, { prayer: e.currentTarget.value })}
                            class="mt-2 w-full px-3 py-2 rounded-xl border border-gray-200 focus:border-hodBlue outline-none text-sm" />`}
                </div>
            </div>
        </li>`;
}

// --------------------------------------------------------------------------
// Posters (§14.2)
// --------------------------------------------------------------------------

function Posters() {
    const event = current.value;
    const [data, setData] = useState(null);
    const [busy, setBusy] = useState('');
    const [saved, setSaved] = useState({});

    useEffect(() => {
        let alive = true;
        studio('poster_data', { id: event.id })
            .then((next) => { if (alive) setData(next); })
            .catch((e) => toast(e.message, 'error'));
        return () => { alive = false; };
    }, [event.id]);

    if (!data) return html`<${Spinner} label="Loading posters…" />`;

    /** Draw one poster in the browser, then send the PNG up to be kept. */
    const make = async (kind) => {
        setBusy(kind);
        try {
            // The template engine and the QR encoder are a few tens of
            // kilobytes that most Studio visits never need, so they are
            // fetched the first time somebody actually makes a poster.
            const { renderTemplate, rasterise, embedFontCss, templateSize } =
                await import('@se/core/svg.js');

            const spec = data.sizes[kind];
            const svgText = await (await fetch(spec.template)).text();
            const fontCss = await embedFontCss([data.fonts.display, data.fonts.body]);

            const source = renderTemplate(svgText, {
                text: data.text,
                colors: data.colors,
                qr: data.qr,
                flags: data.flags,
                fontCss,
            });

            const size = templateSize(source);
            const png = await rasterise(source, size.width, size.height);

            const result = await studioUpload('render_save', { id: event.id, kind }, {
                file: new File([png], spec.filename || kind + '.png', { type: 'image/png' }),
            });

            setSaved({ ...saved, [kind]: result });
            toast('Poster ready.', 'success');
        } catch (e) {
            toast(e.message || 'The poster could not be made.', 'error');
        }
        setBusy('');
    };

    return html`
        <${Card} title="Check-in posters"
            subtitle="A QR straight to the check-in page. Print A4 for doors and corridors, A3 for the foyer.">

            <p class="text-sm text-gray-500 mb-6">
                The code points at <span class="font-mono">${data.text.url}</span>, which is also printed
                underneath in words for anyone whose camera will not focus.
            </p>

            <div class="grid gap-4 sm:grid-cols-2">
                ${Object.entries(data.sizes).map(([kind, spec]) => html`
                    <div class="rounded-2xl border border-gray-100 p-5 space-y-3" key=${kind}>
                        <p class="font-display font-bold text-gray-900">${spec.label}</p>
                        <p class="text-xs text-gray-400">${spec.width} × ${spec.height} px at 300 dpi</p>
                        ${can('assets.manage')
                            ? html`<${Button} loading=${busy === kind} disabled=${!!busy}
                                onClick=${() => make(kind)}>Make the ${spec.label} poster<//>`
                            : html`<p class="text-sm text-gray-400">Ask a producer to render this.</p>`}
                        ${saved[kind] ? html`
                            <div class="flex flex-wrap gap-3 pt-1">
                                <a class="text-sm font-semibold text-hodBlue hover:underline"
                                   href=${saved[kind].asset.path} target="_blank" rel="noopener">PNG</a>
                                <a class="text-sm font-semibold text-hodBlue hover:underline"
                                   href=${saved[kind].pdf} target="_blank" rel="noopener">Print PDF</a>
                            </div>` : null}
                    </div>`)}
            </div>
        <//>`;
}

export function CheckinTab() {
    return html`
        <div class="space-y-6">
            <${Verses} />
            <${Posters} />
        </div>`;
}
