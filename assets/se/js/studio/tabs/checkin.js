// /assets/se/js/studio/tabs/checkin.js
//
// Check-in (guide §10.9, §14.2, §15.7): the welcome verses people see on
// their phone when they check in, and the printable QR posters that get
// them there.
//
// The AI never picks a verse on its own. It suggests references, the KJV
// text is fetched by the server, and nothing becomes usable until a human
// presses Approve — §15.9's human-review rule, made visible as a button.

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
    const [suggestions, setSuggestions] = useState(null);

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
                                    ${verse.source === 'ai' ? html`<span class="ml-2 text-xs text-gray-400">suggested</span>` : null}
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
                        It proposes references; the KJV text comes from the server, and nothing is used
                        until you approve it.
                    </p>
                    <div class="flex flex-wrap gap-3 items-end">
                        <div class="flex-1 min-w-[16rem]">
                            <${Field} label="Theme" name="theme" hint="For example: joy, new beginnings, courage.">
                                <${TextInput} name="theme" value=${theme} onInput=${setTheme} maxLength=${160} />
                            <//>
                        </div>
                        <${Button} variant="secondary" loading=${busy} disabled=${busy || !theme.trim()}
                            onClick=${async () => {
                                setBusy(true);
                                try {
                                    setSuggestions(await studio('verses_suggest', { id: event.id, theme, count: 8 }));
                                } catch (e) { toast(e.message, 'error'); }
                                setBusy(false);
                            }}>Suggest verses<//>
                    </div>

                    ${suggestions ? html`
                        <div class="rounded-2xl bg-blue-50 border border-blue-100 p-4 space-y-2">
                            <p class="text-sm font-semibold text-hodBlue">
                                ${(suggestions.verses || []).length} saved as “needs review”. Approve the ones you want.
                            </p>
                            ${(suggestions.dropped || []).length ? html`
                                <ul class="text-xs text-gray-500 list-disc pl-5">
                                    ${suggestions.dropped.map((d, i) => html`
                                        <li key=${i}>${d.ref} — ${d.why}</li>`)}
                                </ul>` : null}
                            <${Button} variant="ghost" onClick=${() => { setSuggestions(null); load(); }}>Refresh the list<//>
                        </div>` : null}
                </div>` : null}
        <//>`;
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
