// /assets/se/js/studio/tabs/program.js
//
// The programme builder (guide §10.7). An import is always a draft: whether
// it came from pasted text, a screenshot or a PDF, the Producer edits the
// rows and presses Apply before a programme item is written.

import { html } from '@se/core/html.js';
import { useState, useEffect, useMemo } from 'preact/hooks';
import { studio, studioUpload } from '@se/core/api.js';
import { current, toast, can, applyEvent } from '../state.js';
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

const PROGRAM_SOURCE_ACCEPT = '.png,.jpg,.jpeg,.webp,.pdf,image/png,image/jpeg,image/webp,application/pdf';
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

/**
 * Studio's `current` signal is the complete get_event payload, not a wrapper
 * around one. Keeping this tiny resolver exported gives the regression test a
 * direct guard against returning to current.value.event and blanking a tab.
 */
export function programEventFromCurrent(value) {
    return value && Number(value.id) > 0 ? value : null;
}

/** The one request used to load a Programme tab. */
export async function loadProgram(studioCall, eventId) {
    return studioCall('program_list', { id: eventId });
}

/** A named view state keeps every Programme-tab path visible and testable. */
export function programPanelState(event, loading, error, program, visibleItems) {
    if (!event) return 'opening';
    if (error) return 'error';
    if (loading || !program) return 'loading';
    return (visibleItems || []).length === 0 ? 'empty' : 'builder';
}

/** Build the reviewed import request without ever applying its rows. */
export function programImportRequest(eventId, { text = '', assetId = null } = {}) {
    const payload = { id: eventId };
    if (text.trim()) payload.text = text;
    if (assetId !== null && assetId !== undefined) payload.asset_id = assetId;
    return payload;
}

/** The final explicit write request, exercised by the review-table tests. */
export function programApplyRequest(eventId, review, mode, dayId) {
    return {
        id: eventId,
        job_id: review.job_id,
        items: review.items,
        mode,
        day_id: Number(dayId) || 0,
    };
}

function usableDayId(days, candidate, fallback) {
    const requested = Number(candidate);
    if (days.some((day) => Number(day.day_id) === requested)) return requested;
    return Number(fallback) || Number(days[0]?.day_id) || 0;
}

/** Ensure every review row has a real event day before the user sees it. */
export function prepareProgramReview(review, days, fallbackDayId) {
    const fallback = usableDayId(days, fallbackDayId, days[0]?.day_id);
    return {
        ...review,
        items: (review.items || []).map((row) => ({
            ...row,
            day_id: usableDayId(days, row.day_id, fallback),
            kind: KINDS.includes(row.kind) ? row.kind : 'other',
            duration_min: Number(row.duration_min) || 0,
        })),
    };
}

export function updateProgramReviewRow(review, index, patch) {
    const items = review.items.slice();
    items[index] = { ...items[index], ...patch };
    return { ...review, items };
}

function reviewClock(row) {
    const value = String(row.start_time || '');
    const iso = value.match(/(?:T|\s)(\d{2}):(\d{2})/);
    if (iso) return iso[1] + ':' + iso[2];
    return /^\d{2}:\d{2}$/.test(value) ? value : '';
}

function dayDate(day) {
    return String(day?.day_date || day?.starts_at || '').slice(0, 10);
}

/** Preserve a typed clock while stamping it onto the selected event day. */
export function reviewStartForDay(row, dayId, days, clock = reviewClock(row)) {
    if (!clock) return null;
    const day = days.find((candidate) => Number(candidate.day_id) === Number(dayId));
    const date = dayDate(day);
    return date ? `${date} ${clock}:00` : null;
}

/**
 * Upload through the shared Special Events asset pipeline, then ask the
 * existing programme import action to read the returned, event-owned asset.
 * This deliberately contains no file handling of its own.
 */
export async function uploadAndImportProgramSource(uploadCall, studioCall, event, file, meta = {}) {
    const uploaded = await uploadCall('asset_upload', {
        id: event.id,
        role: 'program_source',
        title: meta.title || 'Programme source',
        alt_text: meta.altText || '',
    }, { file });
    const assetId = Number(uploaded.asset?.id || 0);
    if (!assetId) throw new Error('The programme source was uploaded without an asset id. Please try again.');

    const review = await studioCall('program_import', programImportRequest(event.id, { assetId }));
    return { uploaded, assetId, review };
}

function isAllowedProgramSource(file) {
    if (!file) return false;
    const name = String(file.name || '').toLowerCase();
    return /\.(png|jpe?g|webp|pdf)$/.test(name)
        || ['image/png', 'image/jpeg', 'image/webp', 'application/pdf'].includes(file.type);
}

function isBrowserImage(file) {
    return !!file && ['image/png', 'image/jpeg', 'image/webp'].includes(file.type);
}

function unavailableImportMessage(error) {
    if (['AI_UNAVAILABLE', 'AI_RETRYABLE', 'AI_LIMIT'].includes(error?.code)) {
        return 'Image and PDF reading is unavailable right now. Keep or paste the programme text and try again later, or add the rows manually — nothing has been saved.';
    }
    return error?.message || 'We could not read that programme. You can still add the rows manually.';
}

/** HH:MM out of whatever the server sent for a pinned start. */
function clockOf(item) {
    if (item.start_local !== undefined && item.start_local !== null) return item.start_local;
    if (!item.planned_start_at) return '';
    const stamp = String(item.planned_start_at).match(/(?:T|\s)(\d{2}):(\d{2})/);
    return stamp ? stamp[1] + ':' + stamp[2] : '';
}

/** A pinned clock time becomes a stamp on that day's date. */
function pinnedStamp(item, days) {
    const clock = clockOf(item);
    if (!clock) return null;
    const day = days.find((d) => d.day_id === item.day_id) || days[0];
    return day ? day.day_date + ' ' + clock + ':00' : null;
}

function etaLabel(item) {
    if (!item.eta_start) return null;
    const d = new Date(item.eta_start);
    return Number.isNaN(d.getTime()) ? null : d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
}

// --------------------------------------------------------------------------
// One row of the builder
// --------------------------------------------------------------------------

function ItemRow({ item, index, count, onChange, onRemove, onMove, readOnly }) {
    const [open, setOpen] = useState(false);
    const eta = etaLabel(item);
    // TextInput and TextArea intentionally pass their value (not the DOM
    // event) so all shared Studio controls behave the same way.
    const set = (field) => (value) => onChange({ ...item, [field]: value });

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
                    <${TextInput} name=${'title-' + item.id} value=${item.title} disabled=${readOnly}
                        placeholder="What happens" onInput=${set('title')} maxLength=${120} />
                    <p class="text-xs text-gray-400 mt-1">
                        ${eta ? 'Runs at about ' + eta : 'Follows the item above'}
                        ${item.status && item.status !== 'planned' ? ' · ' + item.status : ''}
                    </p>
                </div>

                <div class="w-36">
                    <${Select} name=${'kind-' + item.id} value=${item.kind}
                        onChange=${(kind) => onChange({ ...item, kind })}
                        options=${KINDS.map((kind) => ({ value: kind, label: kind }))} disabled=${readOnly} />
                </div>

                <div class="w-24">
                    <input type="number" min="0" max="600" value=${item.duration_min} disabled=${readOnly}
                        aria-label="Minutes" class="w-full rounded-xl border-gray-200 text-sm"
                        onInput=${(event) => onChange({ ...item, duration_min: Number(event.currentTarget.value) || 0 })} />
                    <p class="text-[11px] text-gray-400 mt-1">minutes</p>
                </div>

                <div class="w-28">
                    <input type="time" value=${clockOf(item)} disabled=${readOnly}
                        aria-label="Pinned start time" class="w-full rounded-xl border-gray-200 text-sm"
                        onInput=${(event) => onChange({ ...item, start_local: event.currentTarget.value })} />
                    <p class="text-[11px] text-gray-400 mt-1">pin a time</p>
                </div>

                <button type="button" class="text-sm text-hodBlue hover:underline pt-2" disabled=${readOnly}
                    onClick=${() => setOpen(!open)}>${open ? 'Less' : 'More'}</button>
                <button type="button" class="text-sm text-hodRed hover:underline pt-2" disabled=${readOnly}
                    onClick=${() => onRemove(item.id)}>Remove</button>
            </div>

            ${open ? html`
                <div class="grid gap-4 sm:grid-cols-2 pt-2 border-t border-gray-100">
                    <${Field} label="Who is leading it" name=${'host-' + item.id}>
                        <${TextInput} name=${'host-' + item.id} value=${item.host_name || ''} disabled=${readOnly}
                            onInput=${set('host_name')} maxLength=${80} />
                    <//>
                    <${Field} label="Line for the guests" name=${'blurb-' + item.id}
                        hint="Shown on the programme page. Leave it empty to show only the title.">
                        <${TextInput} name=${'blurb-' + item.id} value=${item.public_blurb || ''} disabled=${readOnly}
                            onInput=${set('public_blurb')} maxLength=${160} />
                    <//>
                    <${Field} label="Crew note" name=${'notes-' + item.id} hint="Never shown to guests.">
                        <${TextArea} name=${'notes-' + item.id} value=${item.crew_notes || ''} disabled=${readOnly}
                            rows=${2} onInput=${set('crew_notes')} maxLength=${500} />
                    <//>
                    <div class="space-y-2">
                        <${Switch} label="Show on the programme page" checked=${!!item.is_public} disabled=${readOnly}
                            onChange=${(value) => onChange({ ...item, is_public: value })} />
                        <${Switch} label="Feature it" hint="Bigger on the programme and on the stage screen." disabled=${readOnly}
                            checked=${!!item.is_featured} onChange=${(value) => onChange({ ...item, is_featured: value })} />
                    </div>
                </div>` : null}
        </li>`;
}

// --------------------------------------------------------------------------
// Import (§15.3)
// --------------------------------------------------------------------------

function ImportPanel({ event, days, defaultDayId, onApplied, onAddManual }) {
    const [text, setText] = useState('');
    const [file, setFile] = useState(null);
    const [altText, setAltText] = useState('');
    const [sourceAssetId, setSourceAssetId] = useState(null);
    const [busy, setBusy] = useState(false);
    const [review, setReview] = useState(null);
    const [mode, setMode] = useState('append');
    const [targetDayId, setTargetDayId] = useState(defaultDayId);
    const [error, setError] = useState('');

    useEffect(() => {
        setTargetDayId((previous) => usableDayId(days, previous, defaultDayId));
    }, [defaultDayId, days.map((day) => day.day_id).join(',')]);

    const dayOptions = days.map((day) => ({ value: day.day_id, label: day.label || day.day_date }));
    const addReview = (result) => {
        const next = prepareProgramReview(result, days, targetDayId || defaultDayId);
        setReview(next);
        setError('');
        if (!next.items.length) setError('We could not find any programme rows. You can paste clearer text or add rows manually.');
    };

    async function readText() {
        setBusy(true);
        setError('');
        try {
            addReview(await studio('program_import', programImportRequest(event.id, { text })));
        } catch (caught) {
            setError(unavailableImportMessage(caught));
        } finally {
            setBusy(false);
        }
    }

    async function readUploadedSource() {
        setBusy(true);
        setError('');
        try {
            if (sourceAssetId) {
                addReview(await studio('program_import', programImportRequest(event.id, { assetId: sourceAssetId })));
                return;
            }
            if (!file) {
                setError('Choose a PNG, JPEG, WebP or PDF programme first.');
                return;
            }
            if (!isAllowedProgramSource(file)) {
                setError('Choose a PNG, JPEG, WebP or PDF. The server will verify the actual file type before it is stored.');
                return;
            }
            if (isBrowserImage(file) && !altText.trim()) {
                setError('Describe the screenshot before uploading it so the image is accessible to colleagues using a screen reader.');
                return;
            }

            const result = await uploadAndImportProgramSource(studioUpload, studio, event, file, {
                altText: altText.trim(),
                title: file.name || 'Programme source',
            });
            setSourceAssetId(result.assetId);
            if (result.uploaded.assets) applyEvent({ ...event, assets: result.uploaded.assets });
            addReview(result.review);
        } catch (caught) {
            setError(unavailableImportMessage(caught));
        } finally {
            setBusy(false);
        }
    }

    function updateRow(index, patch) {
        setReview((previous) => updateProgramReviewRow(previous, index, patch));
    }

    function chooseTargetDay(value) {
        const nextDay = usableDayId(days, value, defaultDayId);
        setTargetDayId(nextDay);
        if (mode === 'replace') {
            setReview((previous) => previous && {
                ...previous,
                items: previous.items.map((row) => ({
                    ...row,
                    day_id: nextDay,
                    start_time: reviewStartForDay(row, nextDay, days),
                })),
            });
        }
    }

    function chooseMode(nextMode) {
        setMode(nextMode);
        if (nextMode === 'replace') {
            setReview((previous) => previous && {
                ...previous,
                items: previous.items.map((row) => ({
                    ...row,
                    day_id: targetDayId,
                    start_time: reviewStartForDay(row, targetDayId, days),
                })),
            });
        }
    }

    async function apply() {
        setBusy(true);
        setError('');
        try {
            const data = await studio('program_apply', programApplyRequest(event.id, review, mode, targetDayId));
            setReview(null);
            setText('');
            onApplied(data.program);
            toast('Programme updated.', 'success');
        } catch (caught) {
            setError(caught.message || 'The programme could not be applied. Nothing was changed.');
        } finally {
            setBusy(false);
        }
    }

    return html`
        <${Card} title="Import a programme"
            subtitle="Upload the screenshot or PDF here, or paste text. Every result stays editable until you press Apply.">
            <div class="grid gap-5 lg:grid-cols-2">
                <div class="space-y-4">
                    <${Field} label="Upload screenshot or PDF" name="program-import-file"
                        hint="PNG, JPEG, WebP or PDF, up to the event asset limit. The file goes through the same checked upload pipeline as Assets.">
                        <input id="se-f-program-import-file" type="file" accept=${PROGRAM_SOURCE_ACCEPT}
                            onChange=${(event) => {
                                setFile(event.currentTarget.files?.[0] || null);
                                setSourceAssetId(null);
                                setError('');
                            }}
                            class="block w-full text-sm text-gray-600 file:mr-3 file:px-4 file:py-2 file:rounded-xl file:border-0 file:bg-blue-50 file:font-semibold file:cursor-pointer" />
                    <//>
                    ${isBrowserImage(file) ? html`
                        <${Field} label="Describe the screenshot" name="program-import-alt" required=${true}
                            hint="A short description for colleagues who use a screen reader.">
                            <${TextInput} name="program-import-alt" value=${altText}
                                placeholder="Programme schedule screenshot" maxLength=${255}
                                onInput=${(value) => setAltText(value)} />
                        <//>` : null}
                    <${Button} onClick=${readUploadedSource} loading=${busy} disabled=${!file && !sourceAssetId}>
                        ${sourceAssetId ? 'Read uploaded source' : 'Upload and read'}
                    <//>
                </div>

                <div class="space-y-4 border-t border-gray-100 pt-5 lg:pt-0">
                    <${Field} label="Paste programme text" name="program-import-text"
                        hint="A WhatsApp message, copied schedule or a list from a document.">
                        <${TextArea} name="program-import-text" value=${text} rows=${7}
                            placeholder=${'3:30 PM–4:30 PM Photo Booth and games\n4:30–4:35 Welcome\n4:35–4:45 Bingo'}
                            onInput=${(value) => setText(value)} maxLength=${20000} />
                    <//>
                    <${Button} onClick=${readText} loading=${busy} disabled=${!text.trim()}>
                        Read pasted text
                    <//>
                </div>
            </div>

            <div class="mt-5 flex flex-wrap items-center gap-3">
                <${Button} variant="ghost" onClick=${onAddManual}>Add a row manually</${Button}>
                <p class="text-xs text-gray-500">Nothing above changes the programme until you review and Apply it.</p>
            </div>

            ${error ? html`
                <p class="mt-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900" role="alert">
                    ${error}
                </p>` : null}

            ${review ? html`
                <div class="mt-6 space-y-4">
                    <div class="flex flex-wrap items-end justify-between gap-3">
                        <p class="max-w-2xl text-sm text-gray-600">
                            Review every row before applying it. Start times, durations, kinds and days are all editable;
                            no extracted value is saved automatically.
                        </p>
                        <div style=${{ minWidth: '13rem' }}>
                            <${Field} label=${mode === 'replace' ? 'Day to replace' : 'Default event day'} name="program-import-day">
                                <${Select} name="program-import-day" value=${targetDayId} options=${dayOptions}
                                    onChange=${chooseTargetDay} />
                            <//>
                        </div>
                    </div>

                    ${review.warnings?.length ? html`
                        <ul class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 list-disc list-inside">
                            ${review.warnings.map((warning, index) => html`<li key=${index}>${warning}</li>`)}
                        </ul>` : null}

                    <div class="overflow-x-auto rounded-xl border border-gray-100">
                        <table class="min-w-full w-full text-sm" style=${{ minWidth: '52rem' }}>
                            <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500">
                                <tr>
                                    <th class="px-3 py-3">Title</th><th class="px-3 py-3">Kind</th>
                                    <th class="px-3 py-3">Day</th><th class="px-3 py-3">Start</th>
                                    <th class="px-3 py-3">Minutes</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 bg-white">
                                ${review.items.map((row, index) => html`
                                    <tr key=${index}>
                                        <td class="p-2" style=${{ minWidth: '13rem' }}>
                                            <input class="w-full rounded-lg border-gray-200 text-sm" value=${row.title}
                                                aria-label=${'Title of row ' + (index + 1)} maxLength=${120}
                                                onInput=${(event) => updateRow(index, { title: event.currentTarget.value })} />
                                        </td>
                                        <td class="p-2" style=${{ minWidth: '9rem' }}>
                                            <${Select} name=${'program-review-kind-' + index} value=${row.kind}
                                                options=${KINDS.map((kind) => ({ value: kind, label: kind }))}
                                                onChange=${(kind) => updateRow(index, { kind })} />
                                        </td>
                                        <td class="p-2" style=${{ minWidth: '10rem' }}>
                                            <${Select} name=${'program-review-day-' + index} value=${row.day_id} options=${dayOptions}
                                                disabled=${mode === 'replace'}
                                                onChange=${(value) => {
                                                    const dayId = usableDayId(days, value, targetDayId);
                                                    updateRow(index, {
                                                        day_id: dayId,
                                                        start_time: reviewStartForDay(row, dayId, days),
                                                    });
                                                }} />
                                        </td>
                                        <td class="p-2" style=${{ minWidth: '8rem' }}>
                                            <input type="time" class="w-full rounded-lg border-gray-200 text-sm" value=${reviewClock(row)}
                                                aria-label=${'Start time of row ' + (index + 1)}
                                                onInput=${(event) => updateRow(index, {
                                                    start_time: reviewStartForDay(row, row.day_id, days, event.currentTarget.value),
                                                })} />
                                        </td>
                                        <td class="p-2" style=${{ minWidth: '6rem' }}>
                                            <input type="number" min="0" max="1440" class="w-full rounded-lg border-gray-200 text-sm"
                                                value=${row.duration_min} aria-label=${'Minutes for row ' + (index + 1)}
                                                onInput=${(event) => updateRow(index, { duration_min: Number(event.currentTarget.value) || 0 })} />
                                        </td>
                                    </tr>`)}
                            </tbody>
                        </table>
                    </div>

                    <div class="flex flex-wrap items-center gap-3 border-t border-gray-100 pt-4">
                        <label class="text-sm text-gray-700 flex items-center gap-2">
                            <input type="radio" name="se-prog-mode" checked=${mode === 'append'}
                                onChange=${() => chooseMode('append')} /> Add to programme
                        </label>
                        <label class="text-sm text-gray-700 flex items-center gap-2">
                            <input type="radio" name="se-prog-mode" checked=${mode === 'replace'}
                                onChange=${() => chooseMode('replace')} /> Replace this day
                        </label>
                        <${Button} onClick=${apply} loading=${busy} disabled=${!review.items.length}>
                            Apply ${review.items.length} items
                        <//>
                        <${Button} variant="ghost" onClick=${() => setReview(null)} disabled=${busy}>Discard review<//>
                    </div>
                </div>` : null}
        <//>`;
}

// --------------------------------------------------------------------------

export function ProgramTab() {
    const event = programEventFromCurrent(current.value);
    const [data, setData] = useState(null);
    const [items, setItems] = useState([]);
    const [dayId, setDayId] = useState(null);
    const [timeMode, setTimeMode] = useState('approximate');
    const [saving, setSaving] = useState(false);
    const [dirty, setDirty] = useState(false);
    const [loadError, setLoadError] = useState('');
    const [loading, setLoading] = useState(true);
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

    async function fetchProgram() {
        if (!event) return;
        setLoading(true);
        setLoadError('');
        try {
            const result = await loadProgram(studio, event.id);
            absorb(result.program, result.time_mode);
        } catch (caught) {
            setLoadError(caught.message || 'We could not load this programme.');
        } finally {
            setLoading(false);
        }
    }

    useEffect(() => {
        setData(null);
        setItems([]);
        setDayId(null);
        fetchProgram();
    }, [event?.id]);

    const dayItems = useMemo(
        () => items.filter((item) => (dayId === null ? true : item.day_id === dayId)),
        [items, dayId]
    );

    function addManualItem() {
        const targetDay = dayId ?? data?.days?.[0]?.day_id ?? null;
        setItems((previous) => [...previous, blankItem(targetDay)]);
        setDirty(true);
    }

    function replaceItem(updated) {
        setItems((previous) => previous.map((item) => (item.id === updated.id ? updated : item)));
        setDirty(true);
    }

    function removeItem(id) {
        setItems((previous) => previous.filter((item) => item.id !== id));
        setDirty(true);
    }

    function move(from, to) {
        if (to < 0 || to >= dayItems.length) return;
        const reordered = dayItems.slice();
        const [moved] = reordered.splice(from, 1);
        reordered.splice(to, 0, moved);
        setItems((previous) => [...previous.filter((item) => !dayItems.includes(item)), ...reordered]);
        setDirty(true);
    }

    async function save() {
        setSaving(true);
        const days = data.days || [];
        try {
            const payload = items.map((item, index) => ({
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
                sort_order: index + 1,
            }));
            const result = await studio('program_save', {
                id: event.id, items: payload, public_time_mode: timeMode,
            });
            absorb(result.program, timeMode);
            toast('Programme saved.', 'success');
        } catch (caught) {
            toast(caught.message, 'error');
        } finally {
            setSaving(false);
        }
    }

    // This should only be visible for a moment while Workspace changes event,
    // but it is deliberately never an empty panel.
    const panelState = programPanelState(event, loading, loadError, data, dayItems);
    if (panelState === 'opening') return html`<${Spinner} label="Opening the programme…" />`;
    if (panelState === 'loading') return html`<${Spinner} label="Loading the programme…" />`;
    if (panelState === 'error') return html`
        <${Card} title="Programme could not load" subtitle="Your programme has not been changed.">
            <p class="text-sm text-gray-600">${loadError}</p>
            <div class="mt-5"><${Button} onClick=${fetchProgram}>Retry<//></div>
        <//>`;

    const days = data.days || [];

    return html`
        <div class="space-y-6">
            <${Card} title="Run of show"
                subtitle="Use the arrows to order items. Times you do not pin are calculated from the durations."
                actions=${html`
                    <div class="flex items-center gap-2">
                        <${Button} variant="ghost" disabled=${readOnly} onClick=${addManualItem}>Add an item<//>
                        <${Button} onClick=${save} loading=${saving} disabled=${readOnly || !dirty}>Save<//>
                    </div>`}>

                ${days.length > 1 ? html`
                    <div class="flex flex-wrap gap-2 mb-4" role="tablist" aria-label="Programme days">
                        ${days.map((day) => html`
                            <button key=${day.day_id} type="button"
                                class=${'px-3 py-2 rounded-full text-sm min-h-[44px] ' +
                                    (dayId === day.day_id ? 'bg-hodBlue text-white' : 'bg-gray-100 text-gray-600')}
                                aria-selected=${dayId === day.day_id} onClick=${() => setDayId(day.day_id)}>
                                ${day.label || day.day_date}
                            </button>`)}
                    </div>` : null}

                ${data.drift_min ? html`
                    <p class="text-sm rounded-xl bg-amber-50 border border-amber-200 text-amber-900 px-3 py-2 mb-4">
                        Running ${Math.abs(data.drift_min)} minutes ${data.drift_min > 0 ? 'behind' : 'ahead'} right now.
                    </p>` : null}

                ${panelState === 'empty'
                    ? html`<${EmptyState} title="No programme yet"
                        message="Build the first item here, or import a screenshot, PDF or pasted schedule below."
                        action=${readOnly ? null : html`<${Button} onClick=${addManualItem}>Add first item<//>`} />`
                    : html`<ol class="space-y-3">
                        ${dayItems.map((item, index) => html`
                            <${ItemRow} key=${item.id} item=${item} index=${index} count=${dayItems.length}
                                readOnly=${readOnly} onChange=${replaceItem} onRemove=${removeItem} onMove=${move} />`)}
                    </ol>`}

                <div class="mt-5 max-w-sm">
                    <${Field} label="What guests see" name="program-time-mode"
                        hint="Approximate keeps the promise loose.">
                        <${Select} name="program-time-mode" value=${timeMode} options=${TIME_MODES} disabled=${readOnly}
                            onChange=${(value) => { setTimeMode(value); setDirty(true); }} />
                    <//>
                </div>
            <//>

            ${readOnly ? null : html`
                <${ImportPanel} event=${event} days=${days} defaultDayId=${dayId ?? days[0]?.day_id ?? 0}
                    onApplied=${(program) => absorb(program, timeMode)} onAddManual=${addManualItem} />`}
        </div>`;
}
