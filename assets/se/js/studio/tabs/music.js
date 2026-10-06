// /assets/se/js/studio/tabs/music.js
//
// Music (guide §13.3 S0b): the background playlist on the public portal.
//
// Five tracks, in running order, each one attested as clear to use. Three
// things this tab is deliberate about:
//
//   * The rights checkbox is not decoration. The Upload button stays
//     disabled until it is ticked, the server refuses the upload without it
//     (se_asset_store), and refuses the playlist row without it a second
//     time (se_music_add). Who ticked it and when is written to se_music and
//     to se_audit_log.
//   * The producer can HEAR the track here, at the volume the portal will
//     use, before anyone else does. A background bed that is fine in
//     headphones at full volume and awful at 30% on a phone is a mistake
//     worth catching in the Studio.
//   * It tells the truth about autoplay. The explainer text is not filler —
//     producers ask "why didn't it start?", and the answer is a browser
//     policy nobody can override, so it is written where they will read it.

import { html } from '@se/core/html.js';
import { useState, useEffect, useRef } from 'preact/hooks';
import { studio, studioUpload } from '@se/core/api.js';
import { current, toast, can } from '../state.js';
import { Card, Button, Spinner, EmptyState, Field, TextInput, Switch } from '../ui.js';

function bytes(n) {
    if (!n) return '';
    if (n >= 1048576) return (n / 1048576).toFixed(1) + ' MB';

    return Math.round(n / 1024) + ' KB';
}

/**
 * Preview at the portal's own volume.
 *
 * One shared element would be simpler, but a per-row element means the
 * producer can leave one playing while they read the next, which is how
 * people actually compare two pieces of music.
 */
function Preview({ src, volume }) {
    const ref = useRef(null);
    const [on, setOn] = useState(false);

    useEffect(() => {
        if (ref.current) ref.current.volume = Math.min(1, Math.max(0, volume / 100));
    }, [volume, on]);

    const toggle = () => {
        const el = ref.current;
        if (!el) return;

        if (on) {
            el.pause();
            setOn(false);

            return;
        }
        el.volume = Math.min(1, Math.max(0, volume / 100));
        el.play().then(() => setOn(true)).catch(() => toast('The browser would not play that file.', 'error'));
    };

    return html`
        <button type="button" onClick=${toggle}
            class=${'inline-flex items-center gap-1.5 text-xs font-bold '
                + (on ? 'text-hodRed' : 'text-hodBlue') + ' hover:underline'}>
            ${on ? '■ Stop' : `▶ Preview at ${volume}%`}
            <audio ref=${ref} src=${src} preload="none" onEnded=${() => setOn(false)}></audio>
        </button>`;
}

function TrackRow({ track, position, total, volume, readOnly, onAction }) {
    const [editing, setEditing] = useState(false);
    const [title, setTitle] = useState(track.title || '');
    const [artist, setArtist] = useState(track.artist || '');
    const [bpm, setBpm] = useState(track.bpm ? String(track.bpm) : '');
    const [busy, setBusy] = useState(false);

    const run = async (action, payload, confirmText) => {
        if (confirmText && !confirm(confirmText)) return;
        setBusy(true);
        try {
            await onAction(action, { track_id: track.id, ...payload });
            setEditing(false);
        } finally {
            setBusy(false);
        }
    };

    return html`
        <li class="border border-gray-200 rounded-2xl p-4 bg-white">
            <div class="flex items-start gap-3">
                <span class="w-7 h-7 flex-none grid place-items-center rounded-lg bg-blue-50 text-hodBlue text-xs font-bold">
                    ${position}
                </span>

                <div class="min-w-0 flex-1">
                    <p class="text-sm font-bold text-gray-900 truncate">${track.title || 'Untitled track'}</p>
                    <p class="text-xs text-gray-500">
                        ${track.artist || 'Unknown artist'}
                        ${track.bytes ? ` · ${bytes(track.bytes)}` : ''}
                        ${track.bpm ? ` · ${track.bpm} BPM` : ''}
                        ${!track.is_active ? ' · not in rotation' : ''}
                    </p>

                    ${track.rights.confirmed ? html`
                        <p class="text-xs font-semibold text-emerald-600 mt-1">
                            ✓ Rights confirmed${track.rights.confirmed_at
                                ? ` on ${new Date(track.rights.confirmed_at).toLocaleDateString('en-NG')}`
                                : ''}
                        </p>`
                        : html`
                        <p class="text-xs font-semibold text-hodRed mt-1">
                            Not confirmed — this track will not play on the portal.
                        </p>`}

                    ${editing ? html`
                    <div class="grid sm:grid-cols-3 gap-3 mt-3">
                        <input type="text" value=${title} placeholder="Title" maxLength="120"
                            onInput=${(e) => setTitle(e.currentTarget.value)}
                            class="w-full px-3 py-2 rounded-xl border border-gray-200 outline-none focus:border-hodBlue text-sm" />
                        <input type="text" value=${artist} placeholder="Artist" maxLength="120"
                            onInput=${(e) => setArtist(e.currentTarget.value)}
                            class="w-full px-3 py-2 rounded-xl border border-gray-200 outline-none focus:border-hodBlue text-sm" />
                        <input type="number" value=${bpm} placeholder="BPM (optional)" min="40" max="240"
                            onInput=${(e) => setBpm(e.currentTarget.value)}
                            class="w-full px-3 py-2 rounded-xl border border-gray-200 outline-none focus:border-hodBlue text-sm" />
                    </div>
                    <div class="flex gap-2 mt-3">
                        <${Button} loading=${busy}
                            onClick=${() => run('music_update', {
                                title, artist, bpm: bpm === '' ? null : Number(bpm),
                            })}>Save<//>
                        <${Button} variant="ghost" onClick=${() => setEditing(false)}>Cancel<//>
                    </div>` : null}

                    <div class="flex flex-wrap items-center gap-3 mt-3">
                        ${track.path ? html`<${Preview} src=${track.path} volume=${volume} />` : null}

                        ${!readOnly && !editing ? html`
                            <button type="button" onClick=${() => setEditing(true)}
                                class="text-xs font-bold text-hodBlue hover:underline">Edit</button>
                            <button type="button" disabled=${busy}
                                onClick=${() => run('music_update', { is_active: !track.is_active })}
                                class="text-xs font-bold text-gray-500 hover:underline">
                                ${track.is_active ? 'Take out of rotation' : 'Put back in rotation'}
                            </button>
                            <button type="button" disabled=${busy}
                                onClick=${() => run('music_remove', {},
                                    'Remove this track and delete its audio file? This cannot be undone.')}
                                class="text-xs font-bold text-hodRed hover:underline">Remove</button>` : null}
                    </div>
                </div>

                ${!readOnly ? html`
                <div class="flex flex-col gap-1 flex-none">
                    <button type="button" disabled=${position === 1 || busy}
                        onClick=${() => run('music_move', { direction: 'up' })}
                        aria-label="Move up"
                        class="w-7 h-7 grid place-items-center rounded-lg border border-gray-200 text-gray-500 hover:text-hodBlue disabled:opacity-30">↑</button>
                    <button type="button" disabled=${position === total || busy}
                        onClick=${() => run('music_move', { direction: 'down' })}
                        aria-label="Move down"
                        class="w-7 h-7 grid place-items-center rounded-lg border border-gray-200 text-gray-500 hover:text-hodBlue disabled:opacity-30">↓</button>
                </div>` : null}
            </div>
        </li>`;
}

export function MusicTab() {
    const event = current.value;
    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(true);

    // Upload form
    const [file, setFile] = useState(null);
    const [title, setTitle] = useState('');
    const [artist, setArtist] = useState('');
    const [bpm, setBpm] = useState('');
    const [rights, setRights] = useState(false);
    const [busy, setBusy] = useState(false);

    // Settings
    const [enabled, setEnabled] = useState(true);
    const [volume, setVolume] = useState(30);
    const [shuffle, setShuffle] = useState(false);
    const [savingSettings, setSavingSettings] = useState(false);

    const absorb = (payload) => {
        setData(payload);
        setEnabled(payload.settings.enabled);
        setVolume(payload.settings.volume);
        setShuffle(payload.settings.shuffle);
    };

    useEffect(() => {
        if (!event) return;
        let live = true;
        setLoading(true);
        studio('music_get', { id: event.id })
            .then((payload) => { if (live) absorb(payload); })
            .catch((e) => toast(e.message, 'error'))
            .finally(() => { if (live) setLoading(false); });

        return () => { live = false; };
    }, [event?.id]);

    if (!event || loading) return html`<${Spinner} />`;
    if (!data) return html`<${EmptyState} title="Music is not available" message="The database is still catching up with this feature." />`;

    const readOnly = !can('assets.manage');
    const tracks = data.tracks || [];
    const full = tracks.length >= data.max;

    const act = async (action, payload) => {
        try {
            absorb(await studio(action, { id: event.id, ...payload }));
            toast('Saved.', 'success');
        } catch (e) {
            toast(e.message, 'error', 6000);
            throw e;
        }
    };

    const upload = async (e) => {
        e.preventDefault();
        if (!file) { toast('Choose an MP3 or M4A first.', 'error'); return; }
        if (!rights) { toast('Confirm the rights before uploading.', 'error'); return; }

        setBusy(true);
        try {
            absorb(await studioUpload('music_upload', {
                id: event.id,
                title,
                artist,
                bpm: bpm === '' ? null : Number(bpm),
                rights_confirmed: true,
            }, { file }));

            setFile(null);
            setTitle('');
            setArtist('');
            setBpm('');
            setRights(false);
            if (e.target instanceof HTMLFormElement) e.target.reset();
            toast('Track added.', 'success');
        } catch (err) {
            toast(err.fields?.rights_confirmed || err.message, 'error', 7000);
        } finally {
            setBusy(false);
        }
    };

    const saveSettings = async () => {
        setSavingSettings(true);
        try {
            absorb(await studio('music_settings', { id: event.id, enabled, volume, shuffle }));
            toast('Saved.', 'success');
        } catch (e) {
            toast(e.message, 'error');
        } finally {
            setSavingSettings(false);
        }
    };

    const dirty = data.settings.enabled !== enabled
        || data.settings.volume !== volume
        || data.settings.shuffle !== shuffle;

    return html`
        <div class="space-y-6">

            <${Card} title="How this works on a phone"
                     subtitle="Worth reading once, because two of these are browser rules nobody can change.">
                <ul class="text-sm text-gray-600 space-y-2 list-disc pl-5">
                    <li>
                        <strong class="text-gray-900">Nothing plays until the guest touches the page.</strong>
                        Chrome, Safari and Firefox all block audible autoplay. The portal loads silent and
                        the music fades in on their first tap or scroll.
                    </li>
                    <li>
                        <strong class="text-gray-900">We cannot tell whether a phone is on silent.</strong>
                        No browser exposes the ringer switch or the system volume — so a guest on silent
                        simply hears nothing, and the on-screen button is how they find out there was
                        music at all.
                    </li>
                    <li>
                        <strong class="text-gray-900">Guests can always turn it off,</strong> from two small
                        buttons in the corner of the portal, and we remember what they chose.
                    </li>
                    <li>
                        Music is skipped entirely on 2G and when a phone has Data Saver on — the same rule
                        the hero video follows.
                    </li>
                </ul>
            <//>

            ${!readOnly ? html`
            <${Card} title="Add a track"
                     subtitle=${`${tracks.length} of ${data.max} used. MP3 or M4A, up to ${bytes(data.limits.max_bytes)}.`}>
                ${full ? html`
                    <p class="text-sm font-semibold text-amber-600">
                        The playlist is full. Remove a track to add another.
                    </p>`
                : html`
                <form onSubmit=${upload} class="space-y-5">
                    <${Field} label="Audio file" name="file"
                              hint="Around 96 kbps is plenty for a background bed, and keeps the download small on mobile data.">
                        <input type="file" required accept="audio/mpeg,audio/mp4,.mp3,.m4a"
                            onChange=${(e) => setFile(e.currentTarget.files?.[0] || null)}
                            class="w-full text-sm text-gray-600 file:mr-3 file:px-4 file:py-2 file:rounded-xl file:border-0 file:bg-blue-50 file:text-hodBlue file:font-semibold file:cursor-pointer" />
                    <//>

                    <div class="grid sm:grid-cols-3 gap-5">
                        <${Field} label="Title" name="title">
                            <${TextInput} name="title" value=${title} maxLength="120" onInput=${setTitle} />
                        <//>
                        <${Field} label="Artist" name="artist">
                            <${TextInput} name="artist" value=${artist} maxLength="120" onInput=${setArtist} />
                        <//>
                        <${Field} label="BPM" name="bpm" hint="Optional. Only a fallback for the button's pulse.">
                            <${TextInput} name="bpm" type="number" value=${bpm} onInput=${setBpm} />
                        <//>
                    </div>

                    <label class=${'flex gap-3 p-4 rounded-2xl border cursor-pointer transition-colors '
                        + (rights ? 'border-emerald-300 bg-emerald-50' : 'border-amber-300 bg-amber-50')}>
                        <input type="checkbox" checked=${rights} required
                            onChange=${(e) => setRights(e.currentTarget.checked)}
                            class="mt-0.5 w-5 h-5 flex-none rounded accent-emerald-600" />
                        <span class="text-sm text-gray-700">
                            <strong class="text-gray-900 block">
                                I have checked this track and it is clear for us to use publicly.
                            </strong>
                            It is our own recording, in the public domain, or licensed in a way that
                            permits this use. Your name and today's date are recorded against this
                            track as the person who confirmed it.
                        </span>
                    </label>

                    <${Button} type="submit" loading=${busy} disabled=${!rights || !file}>Upload and add<//>
                </form>`}
            <//>` : null}

            <${Card} title="Playlist"
                     subtitle=${tracks.length
                         ? `Plays in this order, then starts again. ${data.playable} of ${tracks.length} will play on the portal.`
                         : 'Nothing uploaded yet.'}>
                ${tracks.length === 0
                    ? html`<${EmptyState} title="No music yet"
                              message="Upload up to five tracks and they play softly while guests read the page and register." />`
                    : html`<ul class="space-y-3">
                        ${tracks.map((t, i) => html`
                            <${TrackRow} key=${t.id} track=${t} position=${i + 1} total=${tracks.length}
                                volume=${volume} readOnly=${readOnly} onAction=${act} />`)}
                    </ul>`}
            <//>

            ${!readOnly ? html`
            <${Card} title="Playback"
                     subtitle="How the portal behaves when there is at least one confirmed track.">
                <div class="space-y-5">
                    <${Switch} label="Play music on the event portal" checked=${enabled}
                        onChange=${setEnabled}
                        hint="Off hides the control completely and nothing is downloaded." />

                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2" for="se-music-volume">
                            Starting volume — ${volume}%
                        </label>
                        <input id="se-music-volume" type="range" min="5" max="100" step="5"
                            value=${volume} onInput=${(e) => setVolume(Number(e.currentTarget.value))}
                            class="w-full max-w-md accent-hodBlue" />
                        <p class="text-xs text-gray-500 mt-1">
                            30% is the house default: present, but quiet enough to read over. A guest who
                            moves the slider keeps their own level on that device.
                        </p>
                    </div>

                    <${Switch} label="Shuffle the order" checked=${shuffle} onChange=${setShuffle}
                        hint="Off plays the list top to bottom, then loops." />

                    <${Button} onClick=${saveSettings} loading=${savingSettings} disabled=${!dirty}>
                        Save playback settings
                    <//>
                </div>
            <//>` : null}
        </div>`;
}
