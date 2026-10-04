// /assets/se/js/dj/main.js
//
// The DJ console (guide §13.12).
//
// One job, done standing up in a dark room: who is singing now, who is
// next, and what to do when somebody vanishes. So the screen is a single
// column of big rows, the two current singers are pinned to the top, and
// every control is a tap target you can hit without looking carefully.
//
// The queue is numbered at check-in, not at pick time, so the numbers on
// this screen are the numbers the guests were told at the door. Reordering
// is a position change, never a renumber — nobody likes being told they are
// number 12 and then called as number 9.
//
// Every mutation carries the version we last saw. If the host moved the
// same entry a second earlier, the DJ gets a plain sentence and a refresh,
// not a silent overwrite.

import { render } from 'preact';
import { useEffect, useState, useCallback, useRef } from 'preact/hooks';
import { html } from '@se/core/html.js';
import { call, SeApiError } from '@se/core/api.js';
import { boot, toast, toasts, dismissToast } from '@se/core/store.js';
import { syncClock, keepClockSynced } from '@se/core/clock.js';

const config = boot.value || {};
const POLL_MS = 2000;

const NEXT_STATUS = {
    queued: 'up_next',
    up_next: 'on_stage',
    on_stage: 'done',
};

const NEXT_LABEL = {
    queued: 'Call them up',
    up_next: 'On stage',
    on_stage: 'Done',
};

// --------------------------------------------------------------------------

function useQueue() {
    const [data, setData] = useState(null);
    const [error, setError] = useState(null);
    const timer = useRef(null);

    const refresh = useCallback(async () => {
        try {
            setData(await call('live', 'karaoke_queue', { event: config.event.public_id }));
            setError(null);
        } catch (e) {
            setError(e.message || 'Lost the queue.');
        }
    }, []);

    useEffect(() => {
        refresh();
        timer.current = setInterval(() => {
            if (document.visibilityState === 'visible') refresh();
        }, POLL_MS);
        return () => clearInterval(timer.current);
    }, [refresh]);

    const act = useCallback(async (action, payload = {}) => {
        try {
            const result = await call('live', action, {
                event: config.event.public_id,
                expected_version: data?.version,
                ...payload,
            });
            setData((previous) => ({ ...previous, ...result }));
            return result;
        } catch (e) {
            if (e instanceof SeApiError && e.code === 'STALE_VERSION') {
                await refresh();
                toast('Someone else just changed the queue — check and retry.', 'error');
                return null;
            }
            toast(e.message || 'That did not work.', 'error');
            return null;
        }
    }, [data, refresh]);

    return { data, error, refresh, act };
}

// --------------------------------------------------------------------------

function Spotlight({ title, entry, act }) {
    if (!entry) {
        return html`
            <section class="se-panel">
                <h2>${title}</h2>
                <p class="se-small se-muted">Nobody yet.</p>
            </section>`;
    }

    const next = NEXT_STATUS[entry.status];

    return html`
        <section class="se-panel" data-spotlight=${title.toLowerCase()}>
            <h2>${title}</h2>
            <p class="se-dj-song">${entry.song.title}</p>
            <p class="se-small">${entry.song.artist}${entry.song.duration ? ' · ' + entry.song.duration : ''}</p>
            <p class="se-dj-singer">
                ${entry.queue_no ? '#' + entry.queue_no + ' ' : ''}${entry.singer}
                ${entry.is_test ? html`<span class="se-small se-muted"> · test</span>` : null}
            </p>
            <div class="se-grid-buttons">
                ${next ? html`
                    <button type="button" class="se-tap"
                        onClick=${() => act('karaoke_set', { entry_id: entry.id, status: next })}>
                        ${NEXT_LABEL[entry.status]}</button>` : null}
                <button type="button" class="se-tap"
                    onClick=${() => act('karaoke_set', { entry_id: entry.id, status: 'skipped' })}>Skip</button>
                <button type="button" class="se-tap"
                    onClick=${() => act('karaoke_set', { entry_id: entry.id, status: 'no_show' })}>No show</button>
                <button type="button" class="se-tap"
                    onClick=${() => act('karaoke_set', { entry_id: entry.id, status: 'queued' })}>Back in the queue</button>
            </div>
        </section>`;
}

function QueueList({ entries, act }) {
    const waiting = entries.filter((entry) => ['queued', 'up_next'].includes(entry.status));

    if (!waiting.length) {
        return html`
            <section class="se-panel">
                <h2>Waiting</h2>
                <p class="se-small se-muted">
                    The queue is empty. Picks arrive as guests choose on their phones, and get
                    their numbers when they check in.
                </p>
            </section>`;
    }

    return html`
        <section class="se-panel">
            <h2>Waiting (${waiting.length})</h2>
            <ol class="se-rows">
                ${waiting.map((entry, i) => html`
                    <li class="se-row" key=${entry.id}>
                        <span class="se-dj-no">${entry.queue_no ?? '—'}</span>
                        <span class="se-dj-row-main">
                            <strong>${entry.song.title}</strong>
                            <span class="se-small se-muted"> · ${entry.song.artist}</span><br />
                            <span class="se-small">${entry.singer}</span>
                        </span>
                        <span class="se-row-sub">
                            <button type="button" class="se-tap"
                                onClick=${() => act('karaoke_set', { entry_id: entry.id, status: 'up_next' })}>
                                Up next</button>
                            <button type="button" class="se-tap" disabled=${i === 0}
                                aria-label=${'Move ' + entry.song.title + ' up'}
                                onClick=${() => act('karaoke_move', {
                                    entry_id: entry.id,
                                    before_id: waiting[i - 1]?.id ?? null,
                                })}>▲</button>
                            <button type="button" class="se-tap" disabled=${i === waiting.length - 1}
                                aria-label=${'Move ' + entry.song.title + ' down'}
                                onClick=${() => act('karaoke_move', {
                                    entry_id: entry.id,
                                    before_id: waiting[i + 2]?.id ?? null,
                                })}>▼</button>
                            <button type="button" class="se-tap"
                                onClick=${() => act('karaoke_set', { entry_id: entry.id, status: 'no_show' })}>
                                No show</button>
                        </span>
                    </li>`)}
            </ol>
        </section>`;
}

function Done({ entries }) {
    const done = entries.filter((entry) => ['done', 'skipped', 'no_show'].includes(entry.status));
    if (!done.length) return null;

    return html`
        <section class="se-panel">
            <h2>Already sung (${done.length})</h2>
            <ul class="se-rows">
                ${done.map((entry) => html`
                    <li class="se-row se-row-quiet" key=${entry.id}>
                        <span class="se-small">
                            ${entry.queue_no ? '#' + entry.queue_no + ' ' : ''}${entry.singer} —
                            ${entry.song.title} <span class="se-muted">(${entry.status})</span>
                        </span>
                    </li>`)}
            </ul>
        </section>`;
}

/** Walk-up singers: somebody at the desk with a player number and a song. */
function AddSinger({ songs, act }) {
    const [playerNo, setPlayerNo] = useState('');
    const [songId, setSongId] = useState('');

    return html`
        <section class="se-panel">
            <h2>Add someone</h2>
            <p class="se-small se-muted">For a walk-up at the stage. Use their player number from the door.</p>
            <label class="se-label" for="se-dj-player">Player number</label>
            <input class="se-input" id="se-dj-player" type="number" min="1" value=${playerNo}
                onInput=${(e) => setPlayerNo(e.currentTarget.value)} />
            <label class="se-label" for="se-dj-song">Song</label>
            <select class="se-input" id="se-dj-song" value=${songId}
                onChange=${(e) => setSongId(e.currentTarget.value)}>
                <option value="">— choose —</option>
                ${songs.map((song) => html`
                    <option key=${song.id} value=${song.id} disabled=${song.taken}>
                        ${song.title} — ${song.artist}${song.taken ? ' (taken)' : ''}
                    </option>`)}
            </select>
            <button type="button" class="se-tap" disabled=${!playerNo || !songId}
                onClick=${async () => {
                    const ok = await act('karaoke_add', {
                        player_no: Number(playerNo), song_id: Number(songId),
                    });
                    if (ok) { setPlayerNo(''); setSongId(''); }
                }}>Add to the queue</button>
        </section>`;
}

function Toasts() {
    const list = toasts.value;
    if (!list.length) return null;

    return html`
        <div class="se-toast-wrap" role="status" aria-live="polite">
            ${list.map((t) => html`
                <div class="se-toast se-glass" data-kind=${t.kind} key=${t.id}
                     onClick=${() => dismissToast(t.id)}>${t.message}</div>`)}
        </div>`;
}

// --------------------------------------------------------------------------

function Dj() {
    const { data, error, act } = useQueue();

    if (error && !data) return html`<p class="se-glass se-pad">${error}</p>`;
    if (!data) return html`<p class="se-glass se-pad">Loading the queue…</p>`;

    const entries = data.entries || [];
    const stats = data.stats || {};

    return html`
        <div class="se-stack">
            <header class="se-panel">
                <h2>Karaoke · ${config.event?.title || ''}</h2>
                <p class="se-small se-muted">
                    ${stats.performed ?? 0} sung · ${stats.remaining ?? 0} to go ·
                    about ${stats.minutes_left ?? 0} minutes left
                </p>
            </header>

            <${Spotlight} title="On stage" entry=${data.now} act=${act} />
            <${Spotlight} title="Up next" entry=${data.next} act=${act} />
            <${QueueList} entries=${entries} act=${act} />
            <${AddSinger} songs=${data.songs || []} act=${act} />
            <${Done} entries=${entries} />
            <${Toasts} />
        </div>`;
}

async function start() {
    const host = document.getElementById('se-app');
    if (!host || !config.event?.public_id) return;

    document.getElementById('se-crew-frame')?.remove();
    host.hidden = false;
    host.className = 'se-container se-stack';

    await syncClock();
    keepClockSynced();

    render(html`<${Dj} />`, host);
}

start();
