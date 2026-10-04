// /assets/se/js/studio/home.js
//
// Studio home (guide §13.13): event cards with status, dates and a
// registered/capacity ring, plus New event and Clone.

import { html } from '@se/core/html.js';
import { useState } from 'preact/hooks';
import { studio } from '@se/core/api.js';
import { formatDateTime } from '@se/core/boot.js';
import {
    boot, events, seriesList, listFilter, listLoading,
    loadEvents, openEvent, toast,
} from './state.js';
import { Card, Field, TextInput, Select, Switch, Button, StatusPill, Spinner, EmptyState } from './ui.js';

const FILTERS = [
    ['active', 'Active'],
    ['draft', 'Drafts'],
    ['published', 'Live'],
    ['upcoming', 'Upcoming'],
    ['archived', 'Archived'],
    ['all', 'Everything'],
];

function CapacityRing({ registered, capacity }) {
    if (!capacity) {
        return html`<span class="text-xs font-bold text-gray-400">${registered} in</span>`;
    }

    const pct = Math.min(100, Math.round((registered / capacity) * 100));
    const tone = pct >= 100 ? 'text-hodRed' : (pct >= 80 ? 'text-amber-500' : 'text-emerald-500');
    const r = 16;
    const c = 2 * Math.PI * r;

    return html`
        <div class="flex items-center gap-2" title=${`${registered} of ${capacity} seats`}>
            <svg class="w-10 h-10 -rotate-90" viewBox="0 0 40 40" aria-hidden="true">
                <circle cx="20" cy="20" r=${r} fill="none" stroke="currentColor" stroke-width="4" class="text-gray-100" />
                <circle cx="20" cy="20" r=${r} fill="none" stroke="currentColor" stroke-width="4" stroke-linecap="round"
                        stroke-dasharray=${c} stroke-dashoffset=${c * (1 - pct / 100)} class=${tone} />
            </svg>
            <span class="text-xs font-bold text-gray-600">${registered}/${capacity}</span>
        </div>`;
}

function EventCard({ event }) {
    return html`
        <li class="bg-white rounded-3xl shadow-sm border border-gray-100 overflow-hidden hover:shadow-md transition-shadow">
            <button type="button" onClick=${() => openEvent(event.id)}
                class="w-full text-left p-6 focus:outline-none focus:ring-2 focus:ring-hodBlue rounded-3xl">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2 flex-wrap">
                            <h3 class="font-display font-bold text-gray-900 truncate">
                                ${event.title} ${event.edition_label || ''}
                            </h3>
                            <${StatusPill} status=${event.status} />
                        </div>
                        ${event.tagline ? html`
                            <p class="text-sm text-gray-500 mt-1 line-clamp-2">${event.tagline}</p>` : null}
                    </div>
                    <div class="w-10 h-10 rounded-xl shrink-0 border border-gray-100"
                         style=${{ background: event.theme?.tokens?.['--se-primary'] || '#1D356A' }}
                         aria-hidden="true"></div>
                </div>

                <div class="flex items-center justify-between gap-3 mt-5">
                    <div class="text-xs text-gray-500">
                        <p class="font-semibold text-gray-700">${formatDateTime(event.starts_at, { dateStyle: 'medium', timeStyle: 'short' })}</p>
                        ${event.venue_name ? html`<p class="truncate">${event.venue_name}</p>` : null}
                        <p class="font-mono text-[10px] text-gray-400 mt-1">/e/${event.slug}</p>
                    </div>
                    <${CapacityRing} registered=${event.counts?.confirmed ?? 0} capacity=${event.online_capacity} />
                </div>
            </button>
        </li>`;
}

function NewEventForm({ onDone, cloneFrom }) {
    const defaults = boot.value?.defaults || {};
    const [form, setForm] = useState({
        title: cloneFrom ? `${cloneFrom.title} (copy)` : '',
        slug: '',
        tagline: '',
        venue_name: cloneFrom?.venue_name || '',
        online_capacity: cloneFrom?.online_capacity ?? 120,
        starts_at: '',
        series_name: '',
        series_id: cloneFrom?.series_id ?? '',
        brand_primary: defaults.primary || '#1D356A',
        brand_secondary: defaults.secondary || '#D11920',
    });
    const [options, setOptions] = useState({
        details: true, brand: true, registration: true, form_fields: true,
        teams: true, verses: true, crew: false,
    });
    const [busy, setBusy] = useState(false);
    const [errors, setErrors] = useState({});

    const set = (k) => (v) => setForm((f) => ({ ...f, [k]: v }));

    const slugFrom = (title) => title.toLowerCase().trim()
        .replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 40);

    const submit = async (e) => {
        e.preventDefault();
        setBusy(true);
        setErrors({});

        const slug = form.slug || slugFrom(form.title);
        const starts = form.starts_at
            ? form.starts_at.replace('T', ' ') + ':00'
            : null;

        try {
            let data;
            if (cloneFrom) {
                data = await studio('clone_event', {
                    source_id: cloneFrom.id,
                    title: form.title,
                    slug,
                    first_start: starts,
                    options,
                });
            } else {
                if (!starts) {
                    setErrors({ starts_at: 'When does it start?' });
                    setBusy(false);
                    return;
                }
                const end = new Date(new Date(form.starts_at).getTime() + 4 * 3600000);
                const endSql = new Date(end.getTime() - end.getTimezoneOffset() * 60000)
                    .toISOString().slice(0, 19).replace('T', ' ');

                data = await studio('create_event', {
                    ...form,
                    slug,
                    online_capacity: form.online_capacity === '' ? null : Number(form.online_capacity),
                    series_id: form.series_id || null,
                    days: [{ starts_at: starts, ends_at: endSql }],
                });
            }

            toast(cloneFrom ? 'Cloned. It starts as a draft.' : 'Event created.', 'success');
            onDone();
            openEvent(data.event.id, 'details');
        } catch (err) {
            if (err.code === 'VALIDATION') {
                setErrors(err.fields);
                toast(err.message, 'error');
            } else {
                toast(err.message, 'error');
            }
        } finally {
            setBusy(false);
        }
    };

    return html`
        <${Card} title=${cloneFrom ? `Clone “${cloneFrom.title}”` : 'New event'}
                 subtitle=${cloneFrom
                     ? 'Everything you tick is copied. Registrations, check-ins and scores never are.'
                     : 'Two minutes now; everything else can wait.'}
                 actions=${html`<${Button} variant="ghost" onClick=${onDone}>Cancel<//>`}>
            <form onSubmit=${submit} class="space-y-5">
                <div class="grid sm:grid-cols-2 gap-5">
                    <${Field} label="Name" name="title" required>
                        <${TextInput} name="title" value=${form.title} maxLength="120"
                            onInput=${(v) => { set('title')(v); if (!form.slug) set('slug')(slugFrom(v)); }} />
                        ${errors.title ? html`<p class="text-xs font-semibold text-hodRed mt-1">${errors.title}</p>` : null}
                    <//>

                    <${Field} label="Short link" name="slug" required
                              hint=${`hodlc.lpc.cm/e/${form.slug || slugFrom(form.title) || '…'}`}>
                        <${TextInput} name="slug" value=${form.slug} maxLength="40"
                            onInput=${(v) => set('slug')(v.toLowerCase())} />
                        ${errors.slug ? html`<p class="text-xs font-semibold text-hodRed mt-1">${errors.slug}</p>` : null}
                    <//>

                    <${Field} label="Starts" name="starts_at" required>
                        <input type="datetime-local" value=${form.starts_at}
                            onInput=${(e) => set('starts_at')(e.currentTarget.value)}
                            class="w-full px-4 py-3 rounded-xl border border-gray-200 outline-none focus:border-hodBlue bg-white" />
                        ${errors.starts_at ? html`<p class="text-xs font-semibold text-hodRed mt-1">${errors.starts_at}</p>` : null}
                    <//>

                    ${!cloneFrom ? html`
                    <${Field} label="Venue" name="venue_name">
                        <${TextInput} name="venue_name" value=${form.venue_name} maxLength="160" onInput=${set('venue_name')} />
                    <//>` : null}
                </div>

                ${!cloneFrom ? html`
                <div class="grid sm:grid-cols-2 gap-5">
                    <${Field} label="Seats via the link" name="online_capacity"
                              hint="Leave empty for no limit. Chara used 120.">
                        <${TextInput} name="online_capacity" type="number" value=${form.online_capacity}
                            onInput=${set('online_capacity')} />
                    <//>

                    <${Field} label="Series" name="series_id"
                              hint="Groups editions together, so next year can clone this one.">
                        ${seriesList.value.length > 0
                            ? html`<${Select} name="series_id" value=${form.series_id} onChange=${set('series_id')}
                                      options=${[{ value: '', label: '— none —' }]
                                          .concat(seriesList.value.map((s) => ({ value: String(s.id), label: s.name })))} />`
                            : html`<${TextInput} name="series_name" value=${form.series_name}
                                      placeholder="e.g. Chara" onInput=${set('series_name')} />`}
                    <//>
                </div>` : null}

                ${cloneFrom ? html`
                <fieldset class="border border-gray-200 rounded-2xl p-5">
                    <legend class="text-sm font-bold text-gray-700 px-2">What to copy</legend>
                    <div class="space-y-3">
                        ${[
                            ['details', 'Description, venue and organiser'],
                            ['brand', 'Colours, fonts and brand-kit images'],
                            ['registration', 'Capacity rules and the form'],
                            ['form_fields', 'Your own questions'],
                            ['teams', 'Team colours (empty, ready to fill)'],
                            ['verses', 'Welcome verses, already approved'],
                            ['crew', 'The crew list'],
                        ].map(([key, label]) => html`
                            <${Switch} key=${key} label=${label} checked=${options[key]}
                                onChange=${(v) => setOptions((o) => ({ ...o, [key]: v }))} />`)}
                    </div>
                    <p class="text-xs text-gray-500 mt-4">
                        Registrations, check-ins, scores, karaoke claims and display keys are never copied.
                        Team names and captains are not either — only the colours. Day times shift to the
                        new start date.
                    </p>
                </fieldset>` : null}

                <${Button} type="submit" loading=${busy}>
                    ${cloneFrom ? 'Create the clone' : 'Create event'}
                <//>
            </form>
        <//>`;
}

export function HomeScreen() {
    const [creating, setCreating] = useState(false);
    const [cloning, setCloning] = useState(null);
    const canCreate = boot.value?.can_create;

    const list = events.value;

    return html`
        <div class="space-y-6">

            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-display font-bold text-gray-900">Special Events</h1>
                    <p class="text-sm text-gray-500 mt-0.5">Create, brand and run an event from one place.</p>
                </div>
                ${canCreate && !creating && !cloning ? html`
                    <div class="flex gap-2">
                        <${Button} onClick=${() => setCreating(true)}>New event<//>
                        ${list.length > 0 ? html`
                            <${Button} variant="secondary" onClick=${() => setCloning(list[0])}>Clone<//>` : null}
                    </div>` : null}
            </div>

            ${creating ? html`<${NewEventForm} onDone=${() => { setCreating(false); loadEvents(); }} />` : null}

            ${cloning ? html`
            <div class="space-y-4">
                <${Card} title="Which event are you cloning?">
                    <${Select} name="clone_source" value=${String(cloning.id)}
                        onChange=${(v) => setCloning(list.find((e) => String(e.id) === v))}
                        options=${list.map((e) => ({ value: String(e.id), label: `${e.title} ${e.edition_label || ''} (${e.status})` }))} />
                <//>
                <${NewEventForm} cloneFrom=${cloning} onDone=${() => { setCloning(null); loadEvents(); }} />
            </div>` : null}

            <div class="flex flex-wrap gap-2" role="tablist" aria-label="Filter events">
                ${FILTERS.map(([key, label]) => html`
                <button key=${key} type="button" role="tab" aria-selected=${listFilter.value === key}
                    onClick=${() => { listFilter.value = key; loadEvents(); }}
                    class=${'px-4 py-2 rounded-xl text-sm font-semibold transition-colors '
                        + (listFilter.value === key
                            ? 'bg-hodBlue text-white shadow-sm'
                            : 'bg-white text-gray-600 border border-gray-200 hover:border-hodBlue')}>
                    ${label}
                </button>`)}
            </div>

            ${listLoading.value
                ? html`<${Spinner} label="Loading events…" />`
                : (list.length === 0
                    ? html`<${Card}><${EmptyState}
                        title="No events here yet"
                        message=${canCreate
                            ? 'Create one and you can have a branded page live in half an hour.'
                            : 'Once you are added to an event crew, it appears here.'}
                        action=${canCreate ? html`<${Button} onClick=${() => setCreating(true)}>New event<//>` : null} /><//>`
                    : html`<ul class="grid sm:grid-cols-2 lg:grid-cols-3 gap-5">
                        ${list.map((e) => html`<${EventCard} key=${e.id} event=${e} />`)}
                    </ul>`)}
        </div>`;
}
