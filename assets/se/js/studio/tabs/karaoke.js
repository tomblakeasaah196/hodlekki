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
import { current, toast, can, refreshEvent } from '../state.js';
import { Card, Button, Spinner, EmptyState, Field, TextInput, TextArea, Switch } from '../ui.js';

// The same look as TextInput, for the number boxes.
const NUMBER = 'w-full px-4 py-3 rounded-xl border border-gray-200 outline-none transition-colors bg-white '
    + 'focus:border-hodBlue disabled:bg-gray-50 disabled:text-gray-400';

const STATUS_NOTE = {
    new: 'New song',
    in_library: 'Already in the library',
    in_event: 'Already on tonight’s list',
    duplicate: 'Looks like a duplicate',
    maybe_duplicate: 'Might be a duplicate — check it',
};

function Settings({ event, settings, onSaved }) {
    const [form, setForm] = useState(settings || {});
    const [dirty, setDirty] = useState(false);
    const [busy, setBusy] = useState(false);
    const readOnly = !can('event.edit');

    // The switches above save on their own; that must not wipe what is
    // being typed here.
    useEffect(() => { if (!dirty) setForm(settings || {}); }, [settings]);

    const set = (key, value) => { setForm({ ...form, [key]: value }); setDirty(true); };

    async function save() {
        setBusy(true);
        try {
            const { enabled, list_published, ...rest } = form;
            const data = await studio('karaoke_settings_save', { id: event.id, settings: rest });
            setDirty(false);
            onSaved(data);
            toast('Karaoke settings saved.', 'success');
        } catch (e) {
            toast(e.message, 'error');
        } finally {
            setBusy(false);
        }
    }

    return html`
        <${Card} title="How karaoke runs"
            actions=${html`<${Button} onClick=${save} loading=${busy} disabled=${readOnly}>Save</${Button}>`}>
            <div class="grid gap-4 sm:grid-cols-2">
                <${Switch} label="Let people pre-pick on their phone"
                    hint="Picks made before the doors open are holds, and turn into queue numbers at check-in."
                    checked=${form.prepick_enabled !== false} onChange=${(v) => set('prepick_enabled', v)} />
                <${Switch} label="One person per song"
                    hint="The usual choice. Turn it off for a sing-along night."
                    checked=${form.unique_songs !== false} onChange=${(v) => set('unique_songs', v)} />

                <${Field} label="Songs per person" name="k-per-person">
                    <input type="number" min="1" max="5" class=${NUMBER} disabled=${readOnly}
                        id="k-per-person" value=${form.songs_per_person ?? 1}
                        onInput=${(e) => set('songs_per_person', Number(e.currentTarget.value) || 1)} />
                <//>
                <${Field} label="Most singers tonight" name="k-max"
                    hint="Leave empty for no limit.">
                    <input type="number" min="1" max="500" class=${NUMBER} disabled=${readOnly}
                        id="k-max" value=${form.max_singers ?? ''}
                        onInput=${(e) => set('max_singers', e.currentTarget.value === '' ? null : Number(e.currentTarget.value))} />
                <//>
                <${Field} label="Release a hold after (minutes)" name="k-release"
                    hint="If someone pre-picks and never checks in, their song goes back on the list this long after the doors open.">
                    <input type="number" min="0" max="600" class=${NUMBER} disabled=${readOnly}
                        id="k-release" value=${form.release_holds_after_min ?? 30}
                        onInput=${(e) => set('release_holds_after_min', Number(e.currentTarget.value) || 0)} />
                <//>
                <${Field} label="Minutes per song" name="k-avg"
                    hint="Used for the 'about 40 minutes left' line on the DJ console.">
                    <input type="number" min="1" max="15" class=${NUMBER} disabled=${readOnly}
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
                    onInput=${setText} maxLength=${60000} />
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
                    onInput=${setQ} />
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

/**
 * The first thing on the tab: is there karaoke at this event, and can guests
 * pick yet? Both are single switches, so they save the moment they are
 * pressed — no Save button to forget.
 */
function OnOff({ event, data, onChanged }) {
    const [busy, setBusy] = useState('');
    const settings = data.settings || {};
    const on = settings.enabled !== false;
    const published = !!settings.list_published;
    const songs = data.total || 0;
    const readOnly = !can('event.edit');

    async function turn(next) {
        setBusy('turn');
        try {
            const result = await studio('karaoke_settings_save', { id: event.id, settings: { enabled: next } });
            onChanged(result);
            toast(next ? 'Karaoke is on for this event.' : 'Karaoke is off for this event.', 'success');
        } catch (e) {
            toast(e.message, 'error');
        } finally {
            setBusy('');
        }
    }

    async function publish(next) {
        setBusy('publish');
        try {
            const result = await studio('karaoke_publish_list', { id: event.id, on: next });
            onChanged(result);
            toast(next ? 'The song list is live. Guests can pick a song now.' : 'The song list is hidden again.', 'success');
        } catch (e) {
            toast(e.message, 'error');
        } finally {
            setBusy('');
        }
    }

    let step;
    if (!on) {
        step = 'Guests will not see karaoke on the event page or the registration form, and nobody can pick a song. '
            + 'You can still get the song list ready below.';
    } else if (published) {
        step = 'The song list is published. Guests can pick a song on their phones.';
    } else if (songs === 0) {
        step = 'Next: add tonight’s songs below, then publish the list. Nobody can pick a song until you do.';
    } else {
        step = 'The song list is not published yet, so nobody can pick. Publish it when the list is final.';
    }

    return html`
        <section class=${'rounded-3xl border p-6 sm:p-8 ' + (on ? 'bg-white border-gray-100 shadow-sm' : 'bg-gray-50 border-gray-200')}>
            <div class="flex flex-wrap items-center gap-4">
                <div class="flex-1 min-w-[14rem]">
                    <h2 class="text-lg font-display font-bold text-gray-900">Karaoke at this event</h2>
                    <p class="text-sm text-gray-500 mt-1">
                        ${on ? 'On. Guests can sign up to sing.' : 'Off. There is no karaoke at this event.'}</p>
                </div>
                <button type="button" role="switch" aria-checked=${on ? 'true' : 'false'}
                    aria-label="Karaoke at this event" disabled=${readOnly || busy !== ''}
                    onClick=${() => turn(!on)}
                    class="inline-flex items-center gap-3 rounded-full py-1 pl-1 pr-4 font-bold text-sm transition-colors disabled:opacity-60 disabled:cursor-not-allowed ${on ? 'bg-emerald-600 text-white' : 'bg-gray-200 text-gray-600'}">
                    <span class="relative inline-flex h-7 w-12 items-center rounded-full ${on ? 'bg-emerald-400' : 'bg-gray-300'}">
                        <span class="inline-block h-5 w-5 rounded-full bg-white shadow transition-transform ${on ? 'translate-x-6' : 'translate-x-1'}"></span>
                    </span>
                    ${busy === 'turn' ? 'Saving…' : (on ? 'On' : 'Off')}
                </button>
            </div>

            <div class=${'mt-5 rounded-2xl p-4 flex flex-wrap items-center gap-3 border '
                + (on && published ? 'bg-emerald-50 border-emerald-200' : 'bg-gray-50 border-gray-200')}>
                <p class=${'flex-1 min-w-[14rem] text-sm ' + (on && published ? 'text-emerald-900' : 'text-gray-600')}>${step}</p>
                ${on ? html`
                    <${Button} variant=${published ? 'ghost' : 'primary'} loading=${busy === 'publish'}
                        disabled=${readOnly || busy !== '' || (!published && songs === 0)}
                        onClick=${() => publish(!published)}>
                        ${published ? 'Unpublish' : 'Publish the list'}</${Button}>` : null}
            </div>
        </section>`;
}

export function KaraokeTab() {
    // current.value is already the complete Studio event payload.
    const event = current.value;
    const [data, setData] = useState(null);

    function load() {
        if (!event) return;
        studio('songs_event_list', { id: event.id })
            .then(setData)
            .catch((e) => toast(e.message, 'error'));
    }

    useEffect(load, [event?.id]);

    // A karaoke switch moves the event's row version, so the Studio takes
    // the fresh event too; otherwise the next Details save would report a
    // clash with nobody.
    function settled(result) {
        if (result.settings) setData((previous) => ({ ...previous, settings: result.settings }));
        refreshEvent(result.event);
    }

    if (!event) return null;
    if (!data) return html`<${Spinner} label="Loading the songs…" />`;

    return html`
        <div class="space-y-6">
            <${OnOff} event=${event} data=${data} onChanged=${settled} />
            <${SongList} event=${event} list=${data} onChanged=${(d) => setData({ ...data, ...d })} />
            ${can('event.edit') ? html`<${ImportPanel} event=${event} onCommitted=${(d) => setData({ ...data, ...d })} />` : null}
            <${Queue} queue=${data.queue} />
            <${Settings} event=${event} settings=${data.settings} onSaved=${settled} />
        </div>`;
}
