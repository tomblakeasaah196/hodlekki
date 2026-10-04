// /assets/se/js/studio/tabs/details.js
//
// Details (guide §13.13): name, link with live slug validation, venue,
// description, and the event days.

import { html } from '@se/core/html.js';
import { useState, useEffect, useRef } from 'preact/hooks';
import { studio } from '@se/core/api.js';
import {
    current, draft, saving, isDirty, setDraft, discardDraft, fieldValue,
    saveSection, toast, applyEvent, can,
} from '../state.js';
import { Card, Field, TextInput, TextArea, Select, Button, SaveBar, Spinner } from '../ui.js';

/** Live slug check, debounced, with the reclaim path for an archived holder. */
function SlugField({ event }) {
    const value = fieldValue('slug', event.slug);
    const [state, setState] = useState(null);
    const timer = useRef(null);

    useEffect(() => {
        if (value === event.slug) { setState(null); return; }
        clearTimeout(timer.current);
        timer.current = setTimeout(async () => {
            try {
                setState(await studio('check_slug', { slug: value, event_id: event.id }));
            } catch (e) {
                setState(null);
            }
        }, 350);
        return () => clearTimeout(timer.current);
    }, [value, event.slug, event.id]);

    const reclaim = async () => {
        if (!confirm(`Move "${state.held_by.title}" aside and give this event /e/${state.slug}?\n\nThe older event keeps working on a dated link.`)) return;
        try {
            const data = await studio('reclaim_slug', { id: event.id, slug: state.slug });
            applyEvent(data.event);
            toast(`Link reclaimed. The older event now lives at /e/${data.result.old_event_slug}.`, 'success', 8000);
            setState(null);
        } catch (e) {
            toast(e.message, 'error');
        }
    };

    return html`
        <${Field} label="Short link" name="slug" required
                  hint="This is what goes on the poster. Changing it later keeps the old link working as a redirect.">
            <div class="flex items-center gap-2">
                <span class="text-sm text-gray-400 font-mono shrink-0">/e/</span>
                <${TextInput} name="slug" value=${value} maxLength="40"
                    onInput=${(v) => setDraft('slug', v.toLowerCase())} />
            </div>

            ${state && !state.valid ? html`
                <p class="text-xs font-semibold text-hodRed mt-1.5">
                    ${state.reason}
                    ${state.suggestion ? html`
                        <button type="button" onClick=${() => setDraft('slug', state.suggestion)}
                            class="ml-1 underline font-bold">Use ${state.suggestion}</button>` : null}
                </p>` : null}

            ${state && state.valid && state.available ? html`
                <p class="text-xs font-semibold text-emerald-600 mt-1.5">${state.url} is free.</p>` : null}

            ${state && state.valid && !state.available ? html`
                <p class="text-xs font-semibold text-amber-600 mt-1.5">
                    Taken by “${state.held_by?.title}” (${state.held_by?.status}).
                    ${state.held_by?.reclaimable ? html`
                        <button type="button" onClick=${reclaim} class="ml-1 underline font-bold">Reclaim it</button>` : null}
                </p>` : null}

            ${value !== event.slug && event.status === 'published' ? html`
                <p class="text-xs text-gray-500 mt-1.5">
                    Printed QR codes keep working — the old link redirects here.
                </p>` : null}
        <//>`;
}

function DayRow({ day, index, onChange, onRemove, canRemove }) {
    const set = (key) => (e) => onChange(index, key, e.currentTarget.value);

    return html`
        <div class="bg-gray-50 rounded-2xl p-4 space-y-3">
            <div class="flex items-center justify-between gap-3">
                <h4 class="text-sm font-bold text-gray-700">Day ${index + 1}</h4>
                ${canRemove ? html`
                    <button type="button" onClick=${() => onRemove(index)}
                        class="text-xs font-bold text-hodRed hover:underline">Remove</button>` : null}
            </div>

            <div class="grid sm:grid-cols-2 gap-3">
                <label class="block">
                    <span class="block text-xs font-semibold text-gray-600 mb-1">Starts</span>
                    <input type="datetime-local" value=${day.starts_at?.slice(0, 16).replace(' ', 'T') || ''}
                        onInput=${set('starts_at')}
                        class="w-full px-3 py-2.5 rounded-xl border border-gray-200 outline-none focus:border-hodBlue bg-white text-sm" />
                </label>
                <label class="block">
                    <span class="block text-xs font-semibold text-gray-600 mb-1">Ends</span>
                    <input type="datetime-local" value=${day.ends_at?.slice(0, 16).replace(' ', 'T') || ''}
                        onInput=${set('ends_at')}
                        class="w-full px-3 py-2.5 rounded-xl border border-gray-200 outline-none focus:border-hodBlue bg-white text-sm" />
                </label>
                <label class="block">
                    <span class="block text-xs font-semibold text-gray-600 mb-1">Doors open</span>
                    <input type="datetime-local" value=${day.doors_open_at?.slice(0, 16).replace(' ', 'T') || ''}
                        onInput=${set('doors_open_at')}
                        class="w-full px-3 py-2.5 rounded-xl border border-gray-200 outline-none focus:border-hodBlue bg-white text-sm" />
                </label>
                <label class="block">
                    <span class="block text-xs font-semibold text-gray-600 mb-1">Check-in closes</span>
                    <input type="datetime-local" value=${day.checkin_closes_at?.slice(0, 16).replace(' ', 'T') || ''}
                        onInput=${set('checkin_closes_at')}
                        class="w-full px-3 py-2.5 rounded-xl border border-gray-200 outline-none focus:border-hodBlue bg-white text-sm" />
                </label>
            </div>

            <label class="block">
                <span class="block text-xs font-semibold text-gray-600 mb-1">Label (optional)</span>
                <input type="text" value=${day.label || ''} placeholder="e.g. Karaoke night" maxLength="60"
                    onInput=${set('label')}
                    class="w-full px-3 py-2.5 rounded-xl border border-gray-200 outline-none focus:border-hodBlue bg-white text-sm" />
            </label>
        </div>`;
}

export function DetailsTab() {
    const event = current.value;
    const [days, setDays] = useState(null);
    const [daysDirty, setDaysDirty] = useState(false);
    const [savingDays, setSavingDays] = useState(false);

    useEffect(() => {
        setDays((event?.days || []).map((d) => ({ ...d })));
        setDaysDirty(false);
    }, [event?.id, event?.row_version]);

    if (!event || days === null) return html`<${Spinner} />`;

    const readOnly = !can('event.edit');

    const changeDay = (index, key, value) => {
        setDays((prev) => prev.map((d, i) => (i === index ? { ...d, [key]: value } : d)));
        setDaysDirty(true);
    };

    const addDay = () => {
        const last = days[days.length - 1];
        const base = last ? new Date(last.starts_at) : new Date();
        base.setDate(base.getDate() + 1);
        const iso = (d) => new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
        const end = new Date(base.getTime() + 4 * 3600000);

        setDays((prev) => [...prev, { starts_at: iso(base), ends_at: iso(end), label: '' }]);
        setDaysDirty(true);
    };

    const removeDay = (index) => {
        setDays((prev) => prev.filter((_, i) => i !== index));
        setDaysDirty(true);
    };

    const saveDays = async () => {
        setSavingDays(true);
        try {
            const data = await studio('days_save', { id: event.id, days });
            applyEvent(data.event);
            toast('Dates saved.', 'success');
            setDaysDirty(false);
        } catch (e) {
            toast(e.code === 'VALIDATION'
                ? Object.values(e.fields)[0] || e.message
                : e.message, 'error');
        } finally {
            setSavingDays(false);
        }
    };

    const saveDetails = () => saveSection('details', draft.value);

    return html`
        <div class="space-y-6">

            <${Card} title="The basics">
                <div class="grid sm:grid-cols-2 gap-5">
                    <${Field} label="Name" name="title" required>
                        <${TextInput} name="title" maxLength="120" disabled=${readOnly}
                            value=${fieldValue('title', event.title)}
                            onInput=${(v) => setDraft('title', v)} />
                    <//>

                    <${Field} label="Edition" name="edition_label" hint="e.g. 2026. Shown next to the name.">
                        <${TextInput} name="edition_label" maxLength="40" disabled=${readOnly}
                            value=${fieldValue('edition_label', event.edition_label || '')}
                            onInput=${(v) => setDraft('edition_label', v)} />
                    <//>
                </div>

                <div class="mt-5">
                    <${Field} label="Tagline" name="tagline" hint="One line. It is the first thing a guest reads.">
                        <${TextInput} name="tagline" maxLength="160" disabled=${readOnly}
                            value=${fieldValue('tagline', event.tagline || '')}
                            onInput=${(v) => setDraft('tagline', v)} />
                    <//>
                </div>

                <div class="mt-5">
                    ${readOnly ? null : html`<${SlugField} event=${event} />`}
                </div>

                <div class="grid sm:grid-cols-2 gap-5 mt-5">
                    <${Field} label="Organiser" name="organizer_label">
                        <${TextInput} name="organizer_label" maxLength="80" disabled=${readOnly}
                            value=${fieldValue('organizer_label', event.organizer_label || '')}
                            onInput=${(v) => setDraft('organizer_label', v)} />
                    <//>

                    <${Field} label="Visibility" name="visibility"
                              hint="Unlisted events work by link but never appear on /e/.">
                        <${Select} name="visibility" disabled=${readOnly}
                            value=${fieldValue('visibility', event.visibility)}
                            onChange=${(v) => setDraft('visibility', v)}
                            options=${[
                                { value: 'unlisted', label: 'Unlisted — by link only' },
                                { value: 'public', label: 'Public — listed on /e/' },
                            ]} />
                    <//>
                </div>
            <//>

            <${Card} title="Venue">
                <div class="grid sm:grid-cols-2 gap-5">
                    <${Field} label="Venue name" name="venue_name" required>
                        <${TextInput} name="venue_name" maxLength="160" disabled=${readOnly}
                            value=${fieldValue('venue_name', event.venue_name || '')}
                            onInput=${(v) => setDraft('venue_name', v)} />
                    <//>
                    <${Field} label="Address" name="venue_address">
                        <${TextInput} name="venue_address" maxLength="255" disabled=${readOnly}
                            value=${fieldValue('venue_address', event.venue_address || '')}
                            onInput=${(v) => setDraft('venue_address', v)} />
                    <//>
                    <${Field} label="Map link" name="venue_map_url" hint="A full https:// link to Google Maps.">
                        <${TextInput} name="venue_map_url" type="url" maxLength="500" disabled=${readOnly}
                            value=${fieldValue('venue_map_url', event.venue_map_url || '')}
                            onInput=${(v) => setDraft('venue_map_url', v)} />
                    <//>
                    <${Field} label="Getting there" name="venue_notes" hint="Parking, which gate, which floor.">
                        <${TextInput} name="venue_notes" maxLength="255" disabled=${readOnly}
                            value=${fieldValue('venue_notes', event.venue_notes || '')}
                            onInput=${(v) => setDraft('venue_notes', v)} />
                    <//>
                </div>
            <//>

            <${Card} title="About this event"
                     subtitle="Markdown: # headings, **bold**, *italic*, `inline code`, [links](https://…), - bullet lists, 1. numbered lists, > quotes, --- dividers, blank-line paragraphs. Raw HTML and images are not allowed.">
                <${Field} label="Description" name="description_md">
                    <${TextArea} name="description_md" rows="10" maxLength="20000"
                        value=${fieldValue('description_md', event.description_md || '')}
                        onInput=${(v) => setDraft('description_md', v)} />
                <//>
            <//>

            <${Card} title="Days"
                     subtitle="One row per calendar day. Doors and check-in default sensibly; adjust if you need to."
                     actions=${!readOnly && days.length < 14
                         ? html`<${Button} variant="secondary" onClick=${addDay}>Add a day<//>`
                         : null}>
                <div class="space-y-4">
                    ${days.map((d, i) => html`
                        <${DayRow} key=${d.id || i} day=${d} index=${i}
                            onChange=${changeDay} onRemove=${removeDay}
                            canRemove=${!readOnly && days.length > 1} />`)}
                </div>

                ${daysDirty ? html`
                    <div class="flex gap-2 mt-5">
                        <${Button} onClick=${saveDays} loading=${savingDays}>Save dates<//>
                        <${Button} variant="ghost" onClick=${() => {
                            setDays((event.days || []).map((d) => ({ ...d })));
                            setDaysDirty(false);
                        }}>Discard<//>
                    </div>` : null}
            <//>

            <${SaveBar} dirty=${isDirty.value} saving=${saving.value}
                onSave=${saveDetails} onDiscard=${discardDraft} />
        </div>`;
}
