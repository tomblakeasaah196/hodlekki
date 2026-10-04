// /assets/se/js/studio/tabs/program.js
//
// The programme builder (guide §10.7).
//
// A run of show is written the way a producer thinks about it: a list of
// things, in order, each with a length. Clock times are the exception, not
// the rule — most items simply follow the one before, so the builder shows
// a calculated time in grey and only stores a time when the producer pins
// one ("doors at 5:00", "the message starts at 7:15").
//
// The import path is the reason this tab exists at all: most programmes
// arrive as a screenshot in a WhatsApp group. Paste it, read what the AI
// found in a plain table, fix the two rows it got wrong, then apply.
// Nothing is written until Apply — a review step, never a surprise (§15.3).

import { html } from '@se/core/html.js';
import { useState, useEffect, useMemo } from 'preact/hooks';
import { studio } from '@se/core/api.js';
import { current, toast, can } from '../state.js';
import { Card, Button, Spinner, EmptyState, Field, TextInput, TextArea, Select, Switch } from '../ui.js';

const KINDS = [
    'welcome', 'worship', 'prayer', 'word', 'game', 'karaoke', 'debate',
    'break', 'food', 'announcement', 'performance', 'buffer', 'closing', 'other',
];

const TIME_MODES = [
    { value: 'approximate', label: 'Approximate — "~7:15 PM"' },
    { value: 'exact', label: 'Exact — "7:15 PM"' },
    { value: 'order_only', label: 'Order only — no times at all' },
];

let nextTempId = -1;

function blankItem(dayId) {
    return {
        id: nextTempId--,
        day_id: dayId,
        title: '',
        kind: 'other',
        duration_min: 10,
        start_local: '',
        host_name: '',
        public_blurb: '',
        is_public: true,
        is_featured: false,
        crew_notes: '',
    };
}

/** HH:MM out of whatever the server sent for a pinned start. */
function clockOf(item) {
    if (item.start_local !== undefined && item.start_local !== null) return item.start_local;
    if (!item.planned_start_at) return '';
    const d = new Date(item.planned_start_at);
    return Number.isNaN(d.getTime())
        ? ''
        : String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0');
}

/**
 * A pinned clock time becomes a stamp on that day's date.
 *
 * The producer types "19:15" while looking at Saturday, so the date comes
 * from the day they are editing — never from today.
 */
function pinnedStamp(item, days) {
    const clock = clockOf(item);
    if (!clock) return null;
    const day = days.find((d) => d.day_id === item.day_id) || days[0];
    if (!day) return null;
    return day.day_date + ' ' + clock + ':00';
}

function etaLabel(item) {
    if (!item.eta_start) return null;
    const d = new Date(item.eta_start);
    if (Number.isNaN(d.getTime())) return null;
    return d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
}

// --------------------------------------------------------------------------
// One row of the builder
// --------------------------------------------------------------------------

function ItemRow({ item, index, count, onChange, onRemove, onMove, readOnly }) {
    const [open, setOpen] = useState(false);
    const eta = etaLabel(item);

    const set = (field) => (e) => onChange({
        ...item,
        [field]: e.currentTarget.type === 'checkbox' ? e.currentTarget.checked : e.currentTarget.value,
    });

    return html`
        <li class="rounded-2xl border border-gray-100 bg-white p-4 space-y-3">
            <div class="flex flex-wrap items-start gap-3">
                <div class="flex flex-col gap-1 pt-1">
                    <button type="button" class="text-gray-400 hover:text-gray-900 disabled:opacity-30"
                        aria-label="Move up" disabled=${readOnly || index === 0}
                        onClick=${() => onMove(index, index - 1)}>▲</button>
                    <button type="button" class="text-gray-400 hover:text-gray-900 disabled:opacity-30"
                        aria-label="Move down" disabled=${readOnly || index === count - 1}
                        onClick=${() => onMove(index, index + 1)}>▼</button>
                </div>

                <div class="min-w-[12rem] flex-1">
                    <${TextInput} name=${'title-' + item.id} value=${item.title}
                        placeholder="What happens" onInput=${set('title')} maxLength=${120} />
                    <p class="text-xs text-gray-400 mt-1">
                        ${eta ? 'Runs at about ' + eta : 'Follows the item above'}
                        ${item.status && item.status !== 'planned' ? ' · ' + item.status : ''}
                    </p>
                </div>

                <div class="w-36">
                    <${Select} name=${'kind-' + item.id} value=${item.kind} onChange=${set('kind')}
                        options=${KINDS.map((k) => ({ value: k, label: k }))} disabled=${readOnly} />
                </div>

                <div class="w-24">
                    <input type="number" min="0" max="600" value=${item.duration_min} disabled=${readOnly}
                        aria-label="Minutes" class="w-full rounded-xl border-gray-200 text-sm"
                        onInput=${(e) => onChange({ ...item, duration_min: Number(e.currentTarget.value) || 0 })} />
                    <p class="text-[11px] text-gray-400 mt-1">minutes</p>
                </div>

                <div class="w-28">
                    <input type="time" value=${clockOf(item)} disabled=${readOnly}
                        aria-label="Pinned start time" class="w-full rounded-xl border-gray-200 text-sm"
                        onInput=${(e) => onChange({ ...item, start_local: e.currentTarget.value })} />
                    <p class="text-[11px] text-gray-400 mt-1">pin a time</p>
                </div>

                <button type="button" class="text-sm text-hodBlue hover:underline pt-2"
                    onClick=${() => setOpen(!open)}>${open ? 'Less' : 'More'}</button>
                <button type="button" class="text-sm text-hodRed hover:underline pt-2" disabled=${readOnly}
                    onClick=${() => onRemove(item.id)}>Remove</button>
            </div>

            ${open ? html`
                <div class="grid gap-4 sm:grid-cols-2 pt-2 border-t border-gray-100">
                    <${Field} label="Who is leading it" name=${'host-' + item.id}>
                        <${TextInput} name=${'host-' + item.id} value=${item.host_name || ''}
                            onInput=${set('host_name')} maxLength=${80} />
                    <//>
                    <${Field} label="Line for the guests" name=${'blurb-' + item.id}
                        hint="Shown on the programme page. Leave it empty to show only the title.">
                        <${TextInput} name=${'blurb-' + item.id} value=${item.public_blurb || ''}
                            onInput=${set('public_blurb')} maxLength=${160} />
                    <//>
                    <${Field} label="Crew note" name=${'notes-' + item.id} hint="Never shown to guests.">
                        <${TextArea} name=${'notes-' + item.id} value=${item.crew_notes || ''}
                            rows=${2} onInput=${set('crew_notes')} maxLength=${500} />
                    <//>
                    <div class="space-y-2">
                        <${Switch} label="Show on the programme page" checked=${!!item.is_public}
                            onChange=${(v) => onChange({ ...item, is_public: v })} />
                        <${Switch} label="Feature it" hint="Bigger on the programme and on the stage screen."
                            checked=${!!item.is_featured} onChange=${(v) => onChange({ ...item, is_featured: v })} />
                    </div>
                </div>` : null}
        </li>`;
}

// --------------------------------------------------------------------------
// Import (§15.3)
// --------------------------------------------------------------------------

function ImportPanel({ event, dayId, onApplied }) {
    const [text, setText] = useState('');
    const [busy, setBusy] = useState(false);
    const [review, setReview] = useState(null);
    const [mode, setMode] = useState('append');

    async function read() {
        setBusy(true);
        try {
            const data = await studio('program_import', { id: event.id, text });
            setReview(data);
            if (!data.items?.length) toast('We could not find a programme in that.', 'error');
        } catch (e) {
            toast(e.message, 'error');
        } finally {
            setBusy(false);
        }
    }

    async function apply() {
        setBusy(true);
        try {
            const data = await studio('program_apply', {
                id: event.id,
                job_id: review.job_id,
                items: review.items,
                mode,
                day_id: dayId,
            });
            setReview(null);
            setText('');
            onApplied(data.program);
            toast('Programme updated.', 'success');
        } catch (e) {
            toast(e.message, 'error');
        } finally {
            setBusy(false);
        }
    }

    return html`
        <${Card} title="Import a programme" subtitle="Paste the text, or upload the screenshot on the Assets tab and pick it here.">
            <${Field} label="Paste it here" name="program-import-text"
                hint="A WhatsApp message, a list out of a document, anything with lines in it.">
                <${TextArea} name="program-import-text" value=${text} rows=${6}
                    placeholder=${'5:00 Doors open\n5:30 Worship — 20 min\n6:00 Word'}
                    onInput=${(e) => setText(e.currentTarget.value)} maxLength=${20000} />
            <//>
            <div class="flex gap-2">
                <${Button} onClick=${read} loading=${busy} disabled=${!text.trim()}>Read it</${Button}>
                ${review ? html`<${Button} variant="ghost" onClick=${() => setReview(null)}>Discard</${Button}>` : null}
            </div>

            ${review ? html`
                <div class="mt-5 space-y-3">
                    <p class="text-sm text-gray-500">
                        Here is what we read. Fix anything that is wrong before you apply it —
                        nothing has been saved yet.
                    </p>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="text-left text-xs uppercase text-gray-400">
                                <tr><th class="py-2 pr-3">Title</th><th class="pr-3">Kind</th>
                                    <th class="pr-3">Start</th><th class="pr-3">Minutes</th></tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                ${review.items.map((row, i) => html`
                                    <tr key=${i}>
                                        <td class="py-2 pr-3">
                                            <input class="w-full rounded-lg border-gray-200 text-sm" value=${row.title}
                                                aria-label=${'Title of row ' + (i + 1)}
                                                onInput=${(e) => {
                                                    const items = review.items.slice();
                                                    items[i] = { ...row, title: e.currentTarget.value };
                                                    setReview({ ...review, items });
                                                }} />
                                        </td>
                                        <td class="pr-3 text-gray-500">${row.kind}</td>
                                        <td class="pr-3 text-gray-500">${row.start_time
                                            ? new Date(row.start_time).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' })
                                            : '—'}</td>
                                        <td class="pr-3 text-gray-500">${row.duration_min}</td>
                                    </tr>`)}
                            </tbody>
                        </table>
                    </div>

                    <div class="flex flex-wrap items-center gap-3">
                        <label class="text-sm text-gray-600 flex items-center gap-2">
                            <input type="radio" name="se-prog-mode" checked=${mode === 'append'}
                                onChange=${() => setMode('append')} /> Add to the programme
                        </label>
                        <label class="text-sm text-gray-600 flex items-center gap-2">
                            <input type="radio" name="se-prog-mode" checked=${mode === 'replace'}
                                onChange=${() => setMode('replace')} /> Replace this day
                        </label>
                        <${Button} onClick=${apply} loading=${busy}>Apply ${review.items.length} items</${Button}>
                    </div>
                </div>` : null}
        <//>`;
}

// --------------------------------------------------------------------------

export function ProgramTab() {
    const event = current.value?.event;
    const [data, setData] = useState(null);
    const [items, setItems] = useState([]);
    const [dayId, setDayId] = useState(null);
    const [timeMode, setTimeMode] = useState('approximate');
    const [saving, setSaving] = useState(false);
    const [dirty, setDirty] = useState(false);
    const readOnly = !can('event.edit');

    function absorb(program, mode) {
        setData(program);
        if (mode) setTimeMode(mode);
        const flat = [];
        for (const day of program.days || []) {
            for (const item of day.items || []) flat.push({ ...item, day_id: day.day_id });
        }
        setItems(flat);
        setDirty(false);
        setDayId((previous) => previous ?? program.days?.[0]?.day_id ?? null);
    }

    useEffect(() => {
        if (!event) return;
        let live = true;
        studio('program_list', { id: event.id })
            .then((d) => { if (live) absorb(d.program, d.time_mode); })
            .catch((e) => toast(e.message, 'error'));
        return () => { live = false; };
    }, [event?.id]);

    const dayItems = useMemo(
        () => items.filter((item) => (dayId === null ? true : item.day_id === dayId)),
        [items, dayId]
    );

    function replaceItem(updated) {
        setItems(items.map((item) => (item.id === updated.id ? updated : item)));
        setDirty(true);
    }

    function removeItem(id) {
        setItems(items.filter((item) => item.id !== id));
        setDirty(true);
    }

    function move(from, to) {
        if (to < 0 || to >= dayItems.length) return;
        const reordered = dayItems.slice();
        const [moved] = reordered.splice(from, 1);
        reordered.splice(to, 0, moved);
        setItems([...items.filter((item) => !dayItems.includes(item)), ...reordered]);
        setDirty(true);
    }

    async function save() {
        setSaving(true);
        const days = data.days || [];
        try {
            const payload = items.map((item, i) => ({
                id: item.id > 0 ? item.id : null,
                day_id: item.day_id,
                title: item.title,
                kind: item.kind,
                duration_min: item.duration_min,
                planned_start_at: pinnedStamp(item, days),
                host_name: item.host_name || '',
                public_blurb: item.public_blurb || '',
                crew_notes: item.crew_notes || '',
                is_public: !!item.is_public,
                is_featured: !!item.is_featured,
                sort_order: i + 1,
            }));
            const result = await studio('program_save', {
                id: event.id, items: payload, public_time_mode: timeMode,
            });
            absorb(result.program, timeMode);
            toast('Programme saved.', 'success');
        } catch (e) {
            toast(e.message, 'error');
        } finally {
            setSaving(false);
        }
    }

    if (!event) return null;
    if (!data) return html`<${Spinner} label="Loading the programme…" />`;

    const days = data.days || [];

    return html`
        <div class="space-y-6">
            <${Card} title="Run of show"
                subtitle="Drag-free ordering with the arrows. Times you do not pin are worked out from the durations."
                actions=${html`
                    <div class="flex items-center gap-2">
                        <${Button} variant="ghost" disabled=${readOnly}
                            onClick=${() => { setItems([...items, blankItem(dayId ?? days[0]?.day_id ?? null)]); setDirty(true); }}>
                            Add an item</${Button}>
                        <${Button} onClick=${save} loading=${saving} disabled=${readOnly || !dirty}>Save</${Button}>
                    </div>`}>

                ${days.length > 1 ? html`
                    <div class="flex flex-wrap gap-2 mb-4">
                        ${days.map((day) => html`
                            <button key=${day.day_id} type="button"
                                class=${'px-3 py-1.5 rounded-full text-sm ' +
                                    (dayId === day.day_id ? 'bg-hodBlue text-white' : 'bg-gray-100 text-gray-600')}
                                onClick=${() => setDayId(day.day_id)}>
                                ${day.label || day.day_date}
                            </button>`)}
                    </div>` : null}

                ${data.drift_min ? html`
                    <p class="text-sm rounded-xl bg-amber-50 border border-amber-200 text-amber-900 px-3 py-2 mb-4">
                        Running ${Math.abs(data.drift_min)} minutes
                        ${data.drift_min > 0 ? 'behind' : 'ahead'} right now. Everything after the live
                        item shifts with it, here and on the guests' phones.
                    </p>` : null}

                ${dayItems.length === 0
                    ? html`<${EmptyState} title="No programme yet"
                        message="Add the items one by one, or import the screenshot you were sent." />`
                    : html`<ol class="space-y-3">
                        ${dayItems.map((item, i) => html`
                            <${ItemRow} key=${item.id} item=${item} index=${i} count=${dayItems.length}
                                readOnly=${readOnly} onChange=${replaceItem} onRemove=${removeItem} onMove=${move} />`)}
                    </ol>`}

                <div class="mt-5 max-w-sm">
                    <${Field} label="What guests see" name="program-time-mode"
                        hint="Approximate is the kind choice: it keeps the promise loose.">
                        <${Select} name="program-time-mode" value=${timeMode} options=${TIME_MODES}
                            disabled=${readOnly}
                            onChange=${(e) => { setTimeMode(e.currentTarget.value); setDirty(true); }} />
                    <//>
                </div>
            <//>

            ${readOnly ? null : html`
                <${ImportPanel} event=${event} dayId=${dayId ?? days[0]?.day_id ?? 0}
                    onApplied=${(program) => absorb(program, timeMode)} />`}
        </div>`;
}
