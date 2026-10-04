// /assets/se/js/host/main.js
//
// The host console (guide §13.10).
//
// One person runs the whole night from this screen, often standing, often
// in the dark, sometimes on a tablet. So: large targets, a keyboard map for
// the laptop, and the state of the room always visible — health dots, the
// version number, and a TEST MODE ribbon that is impossible to miss.
//
// Every mutation carries `expected_version`. Two producers tapping at the
// same moment is not a hypothetical at a church event (the host and the
// producer both have the console open), so the loser gets STALE_VERSION,
// the console refreshes, and nobody's change is silently lost.
//
// PR3 shipped the Show column, the Teams column and the health bar. PR4
// adds the run of show — start, finish, skip, undo, with the drift the
// whole room is feeling shown in plain minutes — and a karaoke strip so
// the host can see who is singing without opening the DJ screen. The game
// runner (PR5/PR6: every game type, judging, awards, the finale) lives in
// ./games.js and takes the wide middle column.

import { render } from 'preact';
import { useEffect, useState, useCallback, useRef } from 'preact/hooks';
import { html } from '@se/core/html.js';
import { call, SeApiError } from '@se/core/api.js';
import { boot, toast, toasts, dismissToast } from '@se/core/store.js';
import { syncClock, keepClockSynced } from '@se/core/clock.js';
import { GameRunner, ReasonButton, TeamChip, teamName } from './games.js';

const config = boot.value || {};
const POLL_MS = 1000;

const SCENE_LABEL = {
    standby: 'Standby', welcome: 'Welcome', program: 'Programme', teams: 'Teams', game: 'Game',
    leaderboard: 'Leaderboard', karaoke: 'Karaoke', announcement: 'Announcement', break: 'Break',
    blank: 'Blank', recap: 'Recap', finale: 'Finale',
};
const CUE_LABEL = {
    tick: 'Tick', arm: 'Get ready', reveal: 'Reveal', correct: 'Correct', wrong: 'Wrong', buzz: 'Buzz',
    strike: 'Strike', ding: 'Ding', team_name: 'Team name', fanfare: 'Fanfare', applause: 'Applause',
    drumroll: 'Drumroll', whoosh: 'Whoosh',
};
const label = (map, key) => map[key] || key.replace(/_/g, ' ');

// --------------------------------------------------------------------------
// Server talk
// --------------------------------------------------------------------------

function useConsole() {
    const [state, setState] = useState(null);
    const [error, setError] = useState(null);
    const timer = useRef(null);

    const refresh = useCallback(async () => {
        try {
            const data = await call('live', 'console', { event: config.event.public_id });
            setState(data);
            setError(null);
        } catch (e) {
            setError(e.message || 'Lost the console.');
        }
    }, []);

    useEffect(() => {
        refresh();
        timer.current = setInterval(() => {
            if (document.visibilityState === 'visible') refresh();
        }, POLL_MS);
        return () => clearInterval(timer.current);
    }, [refresh]);

    /**
     * Run one mutating action with the version we last saw.
     *
     * A STALE_VERSION is not an error the host did anything about — it just
     * means somebody else got there first — so it refreshes and says so in
     * plain words rather than showing a code.
     */
    const act = useCallback(async (action, payload = {}) => {
        const version = state?.state?.version;
        try {
            const data = await call('live', action, {
                event: config.event.public_id,
                expected_version: version,
                ...payload,
            });
            await refresh();
            return data;
        } catch (e) {
            if (e instanceof SeApiError && e.code === 'STALE_VERSION') {
                await refresh();
                toast('Someone else just changed the show — check and retry.', 'error');
                return null;
            }
            toast(e.message || 'That did not work.', 'error');
            return null;
        }
    }, [state, refresh]);

    return { state, error, refresh, act };
}

// --------------------------------------------------------------------------
// Pieces
// --------------------------------------------------------------------------

function HealthDots({ health, checkin }) {
    const ageHealth = health?.snapshot_age_s == null
        ? 'bad'
        : (health.snapshot_age_s < 3 ? 'ok' : (health.snapshot_age_s < 15 ? 'warn' : 'bad'));

    const dots = [
        ['Screens', ageHealth, health?.snapshot_age_s == null ? 'no snapshot' : health.snapshot_age_s.toFixed(0) + 's old'],
        ['Cron', health?.cron == null ? 'off' : (health.cron < 600 ? 'ok' : 'warn'), health?.cron == null ? 'not running yet' : ''],
        ['SMS', health?.sms_worker ? 'ok' : 'warn', health?.sms_worker ? 'healthy' : 'check the worker'],
        ['Door', checkin?.open ? 'ok' : 'off', checkin?.open ? 'check-in open' : 'check-in closed'],
    ];

    return html`
        <div class="se-health">
            ${dots.map(([label, level, note]) => html`
                <span key=${label}>
                    <span class="se-health-dot" data-health=${level}></span>${label}
                    ${note ? html`<span class="se-muted"> · ${note}</span>` : null}
                </span>`)}
        </div>`;
}

function ScenePanel({ state, act }) {
    const scene = state.state?.scene;

    return html`
        <section class="se-panel">
            <h2>On the big screen</h2>
            <div class="se-grid-buttons">
                ${state.scenes.map((key) => html`
                    <button key=${key} type="button" class="se-tap"
                        data-on=${scene === key ? '1' : '0'}
                        onClick=${() => act('scene', { scene: key, payload: {} })}>${label(SCENE_LABEL, key)}</button>`)}
            </div>
        </section>`;
}

function AnnouncementPanel({ act }) {
    const [text, setText] = useState('');
    const [seconds, setSeconds] = useState(20);

    return html`
        <section class="se-panel">
            <h2>Announcement</h2>
            <input class="se-input" value=${text} maxLength="160" aria-label="Announcement"
                placeholder="The bus leaves at 9:30"
                onInput=${(e) => setText(e.currentTarget.value)} />
            <label class="se-label" for="se-ann-secs">Seconds on screen</label>
            <input class="se-input" id="se-ann-secs" type="number" min="3" max="600" value=${seconds}
                onInput=${(e) => setSeconds(Number(e.currentTarget.value) || 20)} />
            <div class="se-grid-buttons">
                <button type="button" class="se-tap se-tap-primary" disabled=${!text.trim()}
                    onClick=${async () => { if (await act('announce', { text, seconds })) setText(''); }}>
                    Show it</button>
                <button type="button" class="se-tap" onClick=${() => act('announce_clear')}>Clear</button>
            </div>
        </section>`;
}

function SoundBoard({ state, act }) {
    return html`
        <section class="se-panel">
            <h2>Sound board</h2>
            <div class="se-grid-buttons">
                ${state.cues.map((cue) => html`
                    <button key=${cue} type="button" class="se-tap se-tap-sm"
                        onClick=${() => act('sound', { cue })}>${label(CUE_LABEL, cue)}</button>`)}
            </div>
        </section>`;
}

/**
 * The run of show (§10.7.4).
 *
 * The host presses Start when a thing actually begins, and Finish when it
 * actually ends. Those two taps are what make the ETA on two hundred
 * phones honest, so they are the biggest buttons on the screen. Undo is
 * there because the wrong button gets pressed in the dark.
 */
function RunOfShow({ state, act }) {
    const program = state.program;
    const items = (program?.days || []).flatMap((day) => day.items || []);
    const live = items.find((item) => item.status === 'live') || null;
    const upcoming = items.filter((item) => item.status === 'planned');
    const drift = program?.drift_min || 0;

    const time = (iso) => (iso
        ? new Date(iso).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' })
        : '—');

    return html`
        <div class="se-stack">
            <section class="se-panel">
                <h2>Run of show</h2>

                ${!program?.ready || !items.length
                    ? html`<p class="se-small se-muted">
                        No programme has been built for tonight. Add one on the Programme tab in
                        the Studio and it will appear here.</p>`
                    : html`
                    <p class="se-small ${drift ? 'se-warn' : 'se-muted'}">
                        ${drift
                            ? 'Running ' + Math.abs(drift) + ' minutes ' + (drift > 0 ? 'behind' : 'ahead')
                            : 'On time'}
                    </p>

                    ${live ? html`
                        <div class="se-now-block">
                            <p class="se-small se-muted">On now</p>
                            <p class="se-now-title">${live.title}</p>
                            <p class="se-small">${live.host_name || ''} · started ${time(live.started_at)}</p>
                            <div class="se-grid-buttons">
                                <button type="button" class="se-tap"
                                    onClick=${() => act('program', { item_id: live.id, op: 'finish' })}>Finish</button>
                                <button type="button" class="se-tap"
                                    onClick=${() => act('program', { item_id: live.id, op: 'skip' })}>Skip</button>
                                <button type="button" class="se-tap"
                                    onClick=${() => act('program', { item_id: live.id, op: 'undo' })}>Undo</button>
                            </div>
                        </div>` : null}

                    <ol class="se-rows">
                        ${upcoming.slice(0, 8).map((item) => html`
                            <li class="se-row" key=${item.id}>
                                <span class="se-dj-no">${time(item.eta_start)}</span>
                                <span class="se-dj-row-main">
                                    <strong>${item.title}</strong>
                                    <span class="se-small se-muted"> · ${item.duration_min} min</span>
                                </span>
                                <span class="se-row-sub">
                                    <button type="button" class="se-tap"
                                        onClick=${() => act('program', { item_id: item.id, op: 'start' })}>Start</button>
                                    <button type="button" class="se-tap"
                                        onClick=${() => act('program', { item_id: item.id, op: 'skip' })}>Skip</button>
                                </span>
                            </li>`)}
                    </ol>`}

                <button type="button" class="se-tap" onClick=${() => act('publish_now')}>
                    Refresh the screens now</button>
            </section>

            <section class="se-panel">
                <h2>Karaoke</h2>
                ${!state.karaoke?.ready
                    ? html`<p class="se-small se-muted">No karaoke queue tonight.</p>`
                    : html`
                    <p class="se-small">
                        ${state.karaoke.now
                            ? 'On stage: ' + state.karaoke.now.singer + ' — ' + state.karaoke.now.song.title
                            : 'Nobody on stage.'}
                    </p>
                    <p class="se-small se-muted">
                        ${state.karaoke.next
                            ? 'Up next: ' + state.karaoke.next.singer + ' — ' + state.karaoke.next.song.title
                            : 'Nobody queued.'}
                        · ${state.karaoke.stats.remaining} to go
                    </p>
                    <div class="se-grid-buttons">
                        ${state.karaoke.next ? html`
                            <button type="button" class="se-tap"
                                onClick=${() => act('karaoke_set', { entry_id: state.karaoke.next.id, status: 'on_stage' })}>
                                Send them up</button>` : null}
                        <button type="button" class="se-tap"
                            onClick=${() => act('scene', { scene: 'karaoke', payload: {} })}>Karaoke on screen</button>
                    </div>`}
            </section>
        </div>`;
}

/** Team standings, crew awards and the ledger's recent rows (§11.11). */
function ScoresPanel({ state, act }) {
    const teams = state.teams || [];
    const teamById = Object.fromEntries(teams.map((t) => [t.id, t]));
    const standings = state.finale?.teams || [];
    const [team, setTeam] = useState(null);
    const [points, setPoints] = useState('100');
    const [reason, setReason] = useState('');

    const amount = Number(points);
    const ready = team && amount && reason.trim().length >= 3;

    const give = async () => {
        if (!ready) return;
        const out = await act('score_adjust', { scope: 'team', team_id: team, points: amount, reason: reason.trim() });
        if (out) { setReason(''); toast((amount > 0 ? '+' : '') + amount + ' for ' + teamName(teamById[team]), 'success'); }
    };

    return html`
        <section class="se-panel">
            <h2>Scores</h2>
            ${standings.length ? html`
                <ol class="se-gr-standings">
                    ${standings.map((row, i) => html`
                        <li key=${row.id}><span class="se-muted">${i + 1}</span> <${TeamChip} team=${teamById[row.id]} /> <strong>${row.points}</strong></li>`)}
                </ol>` : html`<p class="se-small se-muted">No teams yet.</p>`}

            ${teams.length ? html`
                <details class="se-gr-award">
                    <summary class="se-tap">Give points or a penalty</summary>
                    <div class="se-stack-sm">
                        <div class="se-gr-teams">
                            ${teams.map((t) => html`
                                <button key=${t.id} type="button" class="se-tap se-gr-team" aria-pressed=${team === t.id ? 'true' : 'false'}
                                    style=${{ '--team-color': t.hex }} onClick=${() => setTeam(t.id)}>${teamName(t)}</button>`)}
                        </div>
                        <div class="se-grid-buttons">
                            ${['100', '200', '500', '-100'].map((p) => html`
                                <button key=${p} type="button" class="se-tap se-tap-sm" aria-pressed=${points === p ? 'true' : 'false'}
                                    onClick=${() => setPoints(p)}>${Number(p) > 0 ? '+' + p : p}</button>`)}
                        </div>
                        <input class="se-input" type="number" min="-5000" max="5000" step="50" value=${points} aria-label="Points (a minus is a penalty)"
                            onInput=${(e) => setPoints(e.currentTarget.value)} />
                        <input class="se-input" value=${reason} maxLength="120" placeholder="What for? e.g. Best team chant" aria-label="Reason"
                            onInput=${(e) => setReason(e.currentTarget.value)} />
                        <button type="button" class="se-tap se-tap-primary" disabled=${!ready} onClick=${give}>
                            ${amount < 0 ? 'Take ' + Math.abs(amount) + ' points' : 'Give ' + (amount || 0) + ' points'}</button>
                    </div>
                </details>` : null}

            ${(state.score_history || []).length ? html`
                <details>
                    <summary class="se-small se-muted">Recent points (${state.score_history.length})</summary>
                    <ol class="se-rows">
                        ${state.score_history.map((row) => html`
                            <li class="se-row" key=${row.id}>
                                <span class="se-small">
                                    <strong>${row.points > 0 ? '+' : ''}${row.points}</strong>
                                    ${row.team_id ? html` <${TeamChip} team=${teamById[row.team_id]} />` : null}
                                    ${row.player ? ' ' + row.player : ''} · ${row.reason || row.kind}
                                </span>
                                <${ReasonButton} label="Undo" small placeholder="Why undo it?"
                                    onConfirm=${(why) => act('score_void', { score_id: row.id, reason: why })} />
                            </li>`)}
                    </ol>
                </details>` : null}
        </section>`;
}

function TeamsPanel({ state, act }) {
    const [names, setNames] = useState({});
    const roster = state.roster || [];

    if (!state.teams.length) {
        return html`
            <section class="se-panel">
                <h2>Teams</h2>
                <p class="se-small se-muted">No teams yet. Set them up in Studio → Teams.</p>
            </section>`;
    }

    return html`
        <section class="se-panel">
            <h2>Teams</h2>
            <div class="se-rows">
                ${state.teams.map((team) => {
                    const members = roster.filter((person) => person.team_id === team.id);
                    const value = names[team.id] ?? team.name ?? '';
                    return html`
                    <details class="se-row" key=${team.id}>
                        <summary class="se-gr-team-row">
                            <${TeamChip} team=${team} />
                            <span class="se-small se-muted">${members.length} here</span>
                        </summary>
                        <div class="se-row-sub se-stack-sm">
                            <label class="se-label" for=${'se-name-' + team.id}>Team name</label>
                            <div class="se-gr-actions">
                                <input class="se-input" id=${'se-name-' + team.id} value=${value} maxLength="40"
                                    onInput=${(e) => setNames({ ...names, [team.id]: e.currentTarget.value })} />
                                <button type="button" class="se-tap se-tap-sm"
                                    onClick=${() => act('team_name', { team_id: team.id, name: value })}>Rename</button>
                            </div>

                            <label class="se-label" for=${'se-cap-' + team.id}>Captain</label>
                            <select class="se-input" id=${'se-cap-' + team.id}
                                onChange=${(e) => act('captain_set', {
                                    team_id: team.id,
                                    registration_id: e.currentTarget.value ? Number(e.currentTarget.value) : null,
                                })}>
                                <option value="">— none —</option>
                                ${members.map((person) => html`
                                    <option key=${person.registration_id} value=${person.registration_id}
                                        selected=${team.captain_registration_id === person.registration_id}>
                                        ${person.player_no ? '#' + person.player_no + ' ' : ''}${person.display_name}
                                    </option>`)}
                            </select>
                        </div>
                    </details>`;
                })}
            </div>
        </section>`;
}

function ScreensPanel({ state }) {
    return html`
        <section class="se-panel">
            <h2>Screen links</h2>
            ${Object.entries(state.displays || {}).map(([name, url]) => html`
                <p class="se-small" key=${name}>
                    <strong>${name}</strong><br />
                    <a class="se-md-link se-gr-link" href=${url} target="_blank" rel="noopener">${url}</a>
                </p>`)}
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
// Keyboard (§13.10)
// --------------------------------------------------------------------------

function useShortcuts(act, state) {
    useEffect(() => {
        const onKey = (event) => {
            // Never steal a key from someone typing a team name.
            const tag = event.target?.tagName;
            if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;

            const map = {
                b: () => act('scene', { scene: 'blank', payload: {} }),
                s: () => act('scene', { scene: 'leaderboard', payload: {} }),
                k: () => act('scene', { scene: 'karaoke', payload: {} }),
                t: () => act('scene', { scene: 'teams', payload: {} }),
                w: () => act('scene', { scene: 'welcome', payload: {} }),
            };

            const handler = map[event.key.toLowerCase()];
            if (handler) { event.preventDefault(); handler(); }
        };

        addEventListener('keydown', onKey);
        return () => removeEventListener('keydown', onKey);
    }, [act, state]);
}

// --------------------------------------------------------------------------

function Console() {
    const { state, error, act } = useConsole();
    useShortcuts(act, state);

    if (error && !state) return html`<p class="se-glass se-pad">${error}</p>`;
    if (!state) return html`<p class="se-glass se-pad">Loading tonight…</p>`;

    return html`
        <div>
            ${state.event.test_mode
                ? html`<p class="se-test-ribbon">Test mode — nothing tonight counts</p>`
                : null}

            <div class="se-console">
                <header class="se-panel se-console-head">
                    <div class="se-stack-sm">
                        <h2>${state.event.title}${state.event.edition ? ' ' + state.event.edition : ''} · ${state.event.phase}</h2>
                        <${HealthDots} health=${state.health} checkin=${state.checkin} />
                        <p class="se-small se-muted">${state.counts.checked_in} checked in of ${state.counts.confirmed} · ${state.counts.joined_games} playing on their phones · v${state.state?.version ?? 0}</p>
                    </div>
                    <button type="button" class="se-tap se-tap-danger"
                        title="Shortcut: B" onClick=${() => act('scene', { scene: 'blank', payload: {} })}>Blackout</button>
                </header>

                <div class="se-console-cols">
                    <div class="se-stack">
                        <${RunOfShow} state=${state} act=${act} />
                        <${AnnouncementPanel} act=${act} />
                    </div>
                    <div class="se-stack">
                        <${GameRunner} state=${state} act=${act} />
                        <${ScenePanel} state=${state} act=${act} />
                    </div>
                    <div class="se-stack">
                        <${ScoresPanel} state=${state} act=${act} />
                        <${TeamsPanel} state=${state} act=${act} />
                        <${SoundBoard} state=${state} act=${act} />
                        <${ScreensPanel} state=${state} />
                    </div>
                </div>
            </div>
            <${Toasts} />
        </div>`;
}

async function start() {
    const host = document.getElementById('se-app');
    if (!host || !config.event?.public_id) return;

    document.getElementById('se-crew-frame')?.remove();
    host.hidden = false;

    await syncClock();
    keepClockSynced();

    render(html`<${Console} />`, host);
}

start();
