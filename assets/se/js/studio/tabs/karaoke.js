// /assets/se/js/studio/tabs/karaoke.js
//
// Karaoke (guide §10.8).
//
// The song library is global: "Ngozi" typed on one event is the same row as
// "ngozi " typed on the next, because the normalised key says so. This tab
// therefore never asks the producer to think about the library — it asks
// them to add songs to tonight, and the library quietly deduplicates.
//
// Importing is the same shape as the programme import: read, review, apply.
// A row the import is unsure about is marked rather than dropped, because
// a missing song at 9pm is worse than a duplicate line in a list.

import { html } from '@se/core/html.js';
import { useState, useEffect } from 'preact/hooks';
import { studio } from '@se/core/api.js';
import { current, toast, can } from '../state.js';
import { Card, Button, Spinner, EmptyState, Field, TextInput, TextArea, Switch } from '../ui.js';

const STATUS_NOTE = {
    new: 'New song',
    in_library: 'Already in the library',
    in_event: 'Already on tonight’s list',
    duplicate: 'Looks like a duplicate',
    maybe_duplicate: 'Might be a duplicate — check it',
};

function Settings({ event, settings, onSaved }) {
    const [form, setForm] = useState(settings || {});
    const [busy, setBusy] = useState(false);
    const readOnly = !can('event.edit');

    useEffect(() => { setForm(settings || {}); }, [settings]);

    const set = (key, value) => setForm({ ...form, [key]: value });

    async function save() {
        setBusy(true);
        try {
            const data = await studio('karaoke_settings_save', { id: event.id, settings: form });
            onSaved(data.settings);
            toast('Karaoke settings saved.', 'success');
        } catch (e) {
            toast(e.message, 'error');
        } finally {
            setBusy(false);
        }
    }

    return html`
        <${Card} title="How karaoke runs tonight"
            actions=${html`<${Button} onClick=${save} loading=${busy} disabled=${readOnly}>Save</${Button}>`}>
            <div class="grid gap-4 sm:grid-cols-2">
                <${Switch} label="Karaoke is happening" checked=${form.enabled !== false}
                    onChange=${(v) => set('enabled', v)} />
                <${Switch} label="Let people pre-pick on their phone"
                    hint="Picks made before the doors open are holds, and turn into queue numbers at check-in."
                    checked=${form.prepick_enabled !== false} onChange=${(v) => set('prepick_enabled', v)} />
                <${Switch} label="One person per song"
                    hint="The usual choice. Turn it off for a sing-along night."
                    checked=${form.unique_songs !== false} onChange=${(v) => set('unique_songs', v)} />

                <${Field} label="Songs per person" name="k-per-person">
                    <input type="number" min="1" max="5" class="w-full rounded-xl border-gray-200 text-sm"
                        id="k-per-person" value=${form.songs_per_person ?? 1}
                        onInput=${(e) => set('songs_per_person', Number(e.currentTarget.value) || 1)} />
                <//>
                <${Field} label="Most singers tonight" name="k-max"
                    hint="Leave empty for no limit.">
                    <input type="number" min="1" max="500" class="w-full rounded-xl border-gray-200 text-sm"
                        id="k-max" value=${form.max_singers ?? ''}
                        onInput=${(e) => set('max_singers', e.currentTarget.value === '' ? null : Number(e.currentTarget.value))} />
                <//>
                <${Field} label="Release a hold after (minutes)" name="k-release"
                    hint="If someone pre-picks and never checks in, their song goes back on the list this long after the doors open.">
                    <input type="number" min="0" max="600" class="w-full rounded-xl border-gray-200 text-sm"
                        id="k-release" value=${form.release_holds_after_min ?? 30}
                        onInput=${(e) => set('release_holds_after_min', Number(e.currentTarget.value) || 0)} />
                <//>
                <${Field} label="Minutes per song" name="k-avg"
                    hint="Used for the 'about 40 minutes left' line on the DJ console.">
                    <input type="number" min="1" max="15" class="w-full rounded-xl border-gray-200 text-sm"
                        id="k-avg" value=${form.avg_song_min ?? 4}
                        onInput=${(e) => set('avg_song_min', Number(e.currentTarget.value) || 4)} />
                <//>
            </div>
        <//>`;
}

function ImportPanel({ event, onCommitted }) {
    const [text, setText] = useState('');
    const [busy, setBusy] = useState(false);
    const [review, setReview] = useState(null);

    async function read(asCsv) {
        setBusy(true);
        try {
            const data = await studio('songs_import_preview',
                asCsv ? { id: event.id, csv: text } : { id: event.id, text });
            setReview(data);
            if (!data.rows?.length) toast('We could not find any songs in that.', 'error');
        } catch (e) {
            toast(e.message, 'error');
        } finally {
            setBusy(false);
        }
    }

    async function commit() {
        setBusy(true);
        try {
            const rows = review.rows.filter((row) => row.include);
            const data = await studio('songs_import_commit', { id: event.id, rows });
            setReview(null);
            setText('');
            onCommitted(data);
            toast(data.added + ' songs added.', 'success');
        } catch (e) {
            toast(e.message, 'error');
        } finally {
            setBusy(false);
        }
    }

    const counts = review?.counts || {};

    return html`
        <${Card} title="Add songs"
            subtitle="Paste a list, or paste a CSV with title, artist and length. One song per line.">
            <${Field} label="The list" name="songs-import-text">
                <${TextArea} name="songs-import-text" value=${text} rows=${7}
                    placeholder=${'Imela — Nathaniel Bassey\nWay Maker - Sinach 5:12\nOceans — Hillsong'}
                    onInput=${(e) => setText(e.currentTarget.value)} maxLength=${60000} />
            <//>
            <div class="flex flex-wrap gap-2">
                <${Button} onClick=${() => read(false)} loading=${busy} disabled=${!text.trim()}>Read it</${Button}>
                <${Button} variant="ghost" onClick=${() => read(true)} disabled=${busy || !text.trim()}>
                    It is a CSV</${Button}>
            </div>

            ${review ? html`
                <div class="mt-5 space-y-3">
                    <p class="text-sm text-gray-500">
                        ${counts.new || 0} new · ${counts.in_library || 0} already in the library ·
                        ${counts.in_event || 0} already on tonight’s list ·
                        ${(counts.duplicate || 0) + (counts.maybe_duplicate || 0)} to check.
                        Untick anything you do not want.
                    </p>
                    <div class="max-h-96 overflow-y-auto rounded-2xl border border-gray-100">
                        <table class="min-w-full text-sm">
                            <thead class="text-left text-xs uppercase text-gray-400 bg-gray-50 sticky top-0">
                                <tr><th class="py-2 px-3">Add</th><th class="px-3">Title</th>
                                    <th class="px-3">Artist</th><th class="px-3">Length</th><th class="px-3"></th></tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                ${review.rows.map((row, i) => html`
                                    <tr key=${i} class=${row.status === 'in_event' ? 'opacity-50' : ''}>
                                        <td class="py-2 px-3">
                                            <input type="checkbox" checked=${!!row.include}
                                                aria-label=${'Add ' + row.title}
                                                onChange=${(e) => {
                                                    const rows = review.rows.slice();
                                                    rows[i] = { ...row, include: e.currentTarget.checked };
                                                    setReview({ ...review, rows });
                                                }} />
                                        </td>
                                        <td class="px-3 font-medium text-gray-900">${row.title}</td>
                                        <td class="px-3 text-gray-500">${row.artist}</td>
                                        <td class="px-3 text-gray-400">${row.duration || '—'}</td>
                                        <td class="px-3 text-xs text-gray-400">${STATUS_NOTE[row.status] || ''}</td>
                                    </tr>`)}
                            </tbody>
                        </table>
                    </div>
                    <${Button} onClick=${commit} loading=${busy}>
                        Add ${review.rows.filter((r) => r.include).length} songs</${Button}>
                </div>` : null}
        <//>`;
}

function SongList({ event, list, onChanged }) {
    const [q, setQ] = useState('');
    const readOnly = !can('event.edit');

    const shown = (list.items || []).filter((song) => {
        if (!q.trim()) return true;
        const needle = q.toLowerCase();
        return song.title.toLowerCase().includes(needle) || song.artist.toLowerCase().includes(needle);
    });

    async function toggle(song) {
        try {
            const data = await studio('songs_toggle', {
                id: event.id, song_id: song.id, active: !song.is_active,
            });
            onChanged(data);
        } catch (e) {
            toast(e.message, 'error');
        }
    }

    return html`
        <${Card} title=${'Tonight’s list (' + (list.total || 0) + ')'}
            subtitle="Turning a song off hides it from guests. It stays in the library for next time.">
            <div class="mb-4 max-w-sm">
                <${TextInput} name="songs-filter" value=${q} placeholder="Find a song"
                    onInput=${(e) => setQ(e.currentTarget.value)} />
            </div>

            ${shown.length === 0
                ? html`<${EmptyState} title="No songs yet"
                    message="Paste the list below and we will sort out the duplicates." />`
                : html`
                <ul class="divide-y divide-gray-100">
                    ${shown.map((song) => html`
                        <li class="flex items-center gap-3 py-2" key=${song.id}>
                            <span class="flex-1">
                                <span class=${'font-medium ' + (song.is_active ? 'text-gray-900' : 'text-gray-400 line-through')}>
                                    ${song.title}</span>
                                <span class="text-gray-500"> · ${song.artist}</span>
                                ${song.duration ? html`<span class="text-xs text-gray-400"> · ${song.duration}</span>` : null}
                                ${song.taken ? html`<span class="text-xs text-hodRed"> · taken</span>` : null}
                            </span>
                            <button type="button" class="text-sm text-hodBlue hover:underline disabled:text-gray-300"
                                disabled=${readOnly} onClick=${() => toggle(song)}>
                                ${song.is_active ? 'Turn off' : 'Turn on'}</button>
                        </li>`)}
                </ul>`}
        <//>`;
}

function Queue({ queue }) {
    if (!queue || !queue.ready || !(queue.entries || []).length) {
        return html`
            <${Card} title="The queue">
                <p class="text-sm text-gray-500">
                    Nobody is in the queue yet. Picks appear here as soon as guests make them,
                    and get their numbers at check-in.
                </p>
            <//>`;
    }

    return html`
        <${Card} title="The queue"
            subtitle=${queue.stats.performed + ' done · ' + queue.stats.remaining +
                ' to go · about ' + queue.stats.minutes_left + ' minutes'}>
            <ol class="divide-y divide-gray-100">
                ${queue.entries.map((entry) => html`
                    <li class="flex items-center gap-3 py-2 text-sm" key=${entry.id}>
                        <span class="w-10 font-bold text-gray-400">${entry.queue_no ?? '—'}</span>
                        <span class="flex-1">
                            <span class="font-medium text-gray-900">${entry.song.title}</span>
                            <span class="text-gray-500"> · ${entry.song.artist}</span>
                            <br /><span class="text-xs text-gray-400">${entry.singer}</span>
                        </span>
                        <span class="text-xs uppercase tracking-wide text-gray-400">${entry.status}</span>
                    </li>`)}
            </ol>
        <//>`;
}

// --------------------------------------------------------------------------

export function KaraokeTab() {
    // current.value is already the complete Studio event payload.
    const event = current.value;
    const [data, setData] = useState(null);
    const [busy, setBusy] = useState(false);

    function load() {
        if (!event) return;
        studio('songs_event_list', { id: event.id })
            .then(setData)
            .catch((e) => toast(e.message, 'error'));
    }

    useEffect(load, [event?.id]);

    async function publish(on) {
        setBusy(true);
        try {
            const result = await studio('karaoke_publish_list', { id: event.id, on });
            setData({ ...data, settings: result.settings });
            toast(on ? 'The song list is live on the portal.' : 'The song list is hidden.', 'success');
        } catch (e) {
            toast(e.message, 'error');
        } finally {
            setBusy(false);
        }
    }

    if (!event) return null;
    if (!data) return html`<${Spinner} label="Loading the songs…" />`;

    const published = !!data.settings?.list_published;

    return html`
        <div class="space-y-6">
            <div class=${'rounded-2xl p-4 flex flex-wrap items-center gap-3 ' +
                (published ? 'bg-emerald-50 border border-emerald-200' : 'bg-gray-50 border border-gray-200')}>
                <p class="flex-1 text-sm ${published ? 'text-emerald-900' : 'text-gray-600'}">
                    ${published
                        ? 'The song list is published. Guests can pick on their phones.'
                        : 'The song list is not published yet, so nobody can pick. Publish it when the list is final.'}
                </p>
                <${Button} variant=${published ? 'ghost' : 'primary'} loading=${busy}
                    disabled=${!can('event.edit')} onClick=${() => publish(!published)}>
                    ${published ? 'Unpublish' : 'Publish the list'}</${Button}>
            </div>

            <${SongList} event=${event} list=${data} onChanged=${(d) => setData({ ...data, ...d })} />
            ${can('event.edit') ? html`<${ImportPanel} event=${event} onCommitted=${(d) => setData({ ...data, ...d })} />` : null}
            <${Queue} queue=${data.queue} />
            <${Settings} event=${event} settings=${data.settings}
                onSaved=${(settings) => setData({ ...data, settings })} />
        </div>`;
}
