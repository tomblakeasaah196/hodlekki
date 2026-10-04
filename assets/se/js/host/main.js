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
// the host can see who is singing without opening the DJ screen. PR5 and
// PR6 add the complete game runner, judging, awards and finale.

import { render } from 'preact';
import { useEffect, useState, useCallback, useRef } from 'preact/hooks';
import { html } from '@se/core/html.js';
import { call, SeApiError } from '@se/core/api.js';
import { boot, toast, toasts, dismissToast } from '@se/core/store.js';
import { syncClock, keepClockSynced } from '@se/core/clock.js';

const config = boot.value || {};
const POLL_MS = 1000;

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

function ShowColumn({ state, act }) {
    const [text, setText] = useState('');
    const [seconds, setSeconds] = useState(20);
    const scene = state.state?.scene;

    return html`
        <div class="se-stack">
            <section class="se-panel">
                <h2>Scene</h2>
                <div class="se-grid-buttons">
                    ${state.scenes.map((key) => html`
                        <button key=${key} type="button" class="se-tap"
                            data-on=${scene === key ? '1' : '0'}
                            onClick=${() => act('scene', { scene: key, payload: {} })}>${key}</button>`)}
                </div>
            </section>

            <section class="se-panel">
                <h2>Announcement</h2>
                <input class="se-input" value=${text} maxLength="160"
                    placeholder="The bus leaves at 9:30"
                    onInput=${(e) => setText(e.currentTarget.value)} />
                <label class="se-label" for="se-ann-secs">Seconds on screen</label>
                <input class="se-input" id="se-ann-secs" type="number" min="3" max="600" value=${seconds}
                    onInput=${(e) => setSeconds(Number(e.currentTarget.value) || 20)} />
                <button type="button" class="se-tap"
                    onClick=${async () => { if (await act('announce', { text, seconds })) setText(''); }}>
                    Show it</button>
                <button type="button" class="se-tap" onClick=${() => act('announce_clear')}>Clear</button>
            </section>

            <section class="se-panel">
                <h2>Sound board</h2>
                <div class="se-grid-buttons">
                    ${state.cues.map((cue) => html`
                        <button key=${cue} type="button" class="se-tap"
                            onClick=${() => act('sound', { cue })}>${cue}</button>`)}
                </div>
            </section>
        </div>`;
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

            <${GameRunner} state=${state} act=${act} />
        </div>`;
}

function GameRunner({ state, act }) {
    const game = state.game;
    const round = game?.private?.row;
    const item = game?.private?.item?.payload || {};
    const buzzes = game?.private?.buzzes || [];
    const [player, setPlayer] = useState('');
    const [team, setTeam] = useState('');
    const [teamB, setTeamB] = useState('');
    if (!game) return html`<section class="se-panel"><h2>Game runner</h2><p class="se-small se-muted">Start a game from the Games tab in Studio.</p></section>`;

    const next = () => act('round_next', { game_id: game.id });
    const arm = () => act('round_arm', { game_id: game.id, round_id: round?.id });
    return html`<section class="se-panel se-stack">
        <div class="flex justify-between"><h2>${game.title}</h2><span class="se-small">${game.type}</span></div>
        ${round ? html`<p class="se-small">Round ${round.round_no} · <strong>${round.state}</strong></p>` : null}
        ${item.phrase ? html`<p class="se-private-answer">Secret: ${item.phrase}</p>` : null}
        ${item.answer ? html`<p class="se-private-answer">Answer: ${item.answer}</p>` : null}
        <div class="se-grid-buttons">
            ${!round || ['scored','void'].includes(round.state) ? html`<button class="se-tap" onClick=${next}>Next round</button>` : null}
            ${round?.state === 'pending' && game.type !== 'charades' ? html`<button class="se-tap" onClick=${arm}>Arm</button>` : null}
            ${['armed','open'].includes(round?.state) && game.type !== 'charades' ? html`<button class="se-tap" onClick=${()=>act('round_lock',{round_id:round.id})}>Lock</button>` : null}
            ${round?.state === 'locked' ? html`<button class="se-tap" onClick=${()=>act('round_reveal',{round_id:round.id})}>Reveal</button>` : null}
            ${round?.state === 'revealed' ? html`<button class="se-tap" onClick=${()=>act('round_score',{round_id:round.id})}>Score</button>` : null}
        </div>
        ${game.type === 'who_am_i' && round ? html`<button class="se-tap" onClick=${()=>act('clue_next',{round_id:round.id})}>Next clue</button>` : null}
        ${game.type === 'charades' && round ? html`<div class="se-stack">
            <select class="se-input" value=${team} onChange=${e=>setTeam(e.currentTarget.value)}><option value="">Acting team</option>${state.teams.map(t=>html`<option value=${t.id}>${t.name||t.label}</option>`)}</select>
            <input class="se-input" value=${player} placeholder="Player number, or random" onInput=${e=>setPlayer(e.currentTarget.value)}/>
            <button class="se-tap" onClick=${()=>act('charades_turn',{round_id:round.id,team_id:Number(team),player_no:player||'random'})}>Pick presenter</button>
            <button class="se-tap" onClick=${()=>act('charades_start',{round_id:round.id})}>Start timer</button>
            <button class="se-tap" onClick=${()=>act('charades_mark',{round_id:round.id,result:'correct'})}>✓ Got it</button>
            <button class="se-tap" onClick=${()=>act('charades_mark',{round_id:round.id,result:'pass'})}>Pass</button>
            <button class="se-tap" onClick=${()=>act('charades_end',{round_id:round.id})}>End turn</button>
        </div>` : null}
        ${buzzes.map(b=>html`<div class="se-row"><span>${b.display_name} · ${b.effective_ms}</span><button class="se-tap" onClick=${()=>act('buzz_judge',{round_id:round.id,buzz_id:b.id,correct:true})}>✓</button><button class="se-tap" onClick=${()=>act('buzz_judge',{round_id:round.id,buzz_id:b.id,correct:false})}>✕</button></div>`)}
        ${game.type === 'feud' && round ? html`<div class="se-stack">
            <div class="se-grid-buttons"><select class="se-input" value=${team} onChange=${e=>setTeam(e.currentTarget.value)}><option value="">Team A</option>${state.teams.map(t=>html`<option value=${t.id}>${t.name||t.label}</option>`)}</select><select class="se-input" value=${teamB} onChange=${e=>setTeamB(e.currentTarget.value)}><option value="">Team B</option>${state.teams.map(t=>html`<option value=${t.id}>${t.name||t.label}</option>`)}</select><button class="se-tap" onClick=${()=>act('feud_faceoff',{round_id:round.id,team_a:Number(team),team_b:Number(teamB)})}>Start face-off</button></div>
            ${(game.private?.board||[]).map(a=>html`<button class="se-tap" onClick=${()=>act('feud_reveal',{round_id:round.id,answer_id:a.id})}>Reveal ${a.label} · ${a.points}</button>`)}
            <div class="se-grid-buttons"><button class="se-tap" onClick=${()=>act('feud_control',{round_id:round.id,team_id:Number(team)})}>A controls</button><button class="se-tap" onClick=${()=>act('feud_control',{round_id:round.id,team_id:Number(teamB)})}>B controls</button><button class="se-tap" onClick=${()=>act('feud_strike',{round_id:round.id})}>Strike ✕</button><button class="se-tap" onClick=${()=>act('feud_steal',{round_id:round.id,success:true})}>Steal ✓</button><button class="se-tap" onClick=${()=>act('feud_bank',{round_id:round.id})}>Bank</button><button class="se-tap" onClick=${()=>act('feud_reveal_all',{round_id:round.id})}>Reveal all</button></div>
        </div>` : null}
        <button class="se-tap" onClick=${()=>act('scene',{scene:'leaderboard',payload:{show_mvp:true}})}>Leaderboard</button>
        <button class="se-tap" onClick=${()=>act('finale')}>Run finale</button>
    </section>`;
}

function TeamsColumn({ state, act }) {
    const [names, setNames] = useState({});
    const roster = state.roster || [];

    return html`
        <div class="se-stack">
            <section class="se-panel">
                <h2>Teams</h2>
                <div class="se-rows">
                    ${state.teams.map((team) => {
                        const members = roster.filter((person) => person.team_id === team.id);
                        const value = names[team.id] ?? team.name ?? '';
                        return html`
                        <div class="se-row" key=${team.id}>
                            <span class="se-team-badge ${team.ring ? 'se-team-badge-ring' : ''}"
                                  style=${{ '--team-color': team.hex, '--team-on': team.on }}>
                                ${team.name || ('Team ' + team.label)}
                            </span>
                            <span>${members.length}</span>

                            <div class="se-row-sub">
                                <input class="se-input" value=${value} maxLength="40"
                                    aria-label=${'Name for team ' + team.label}
                                    onInput=${(e) => setNames({ ...names, [team.id]: e.currentTarget.value })} />
                                <button type="button" class="se-tap"
                                    onClick=${() => act('team_name', { team_id: team.id, name: value })}>
                                    Rename</button>
                                ${[100,200,500].map(points=>html`<button type="button" class="se-tap" onClick=${()=>{const reason=prompt(`Name this +${points} award`);if(reason)act('score_adjust',{scope:'team',team_id:team.id,points,kind:'award',reason});}}>+${points}</button>`)}
                                <button type="button" class="se-tap" onClick=${()=>{const raw=prompt('Points (use a minus for a penalty)');const reason=raw&&prompt('Reason');if(reason)act('score_adjust',{scope:'team',team_id:team.id,points:Number(raw),kind:Number(raw)<0?'penalty':'award',reason});}}>Custom score</button>

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
                        </div>`;
                    })}
                </div>
            </section>

            <section class="se-panel">
                <h2>Recent score changes</h2>
                ${(state.score_history||[]).filter(row=>['award','penalty','correction'].includes(row.kind)).map(row=>html`<div class="se-row"><span><strong>${row.points>0?'+':''}${row.points}</strong> · ${row.reason}</span><button class="se-tap" onClick=${()=>{const reason=prompt('Why undo this score?');if(reason)act('score_void',{score_event_id:row.id,reason});}}>Undo</button></div>`)}
            </section>

            <section class="se-panel">
                <h2>Screens</h2>
                ${Object.entries(state.displays || {}).map(([name, url]) => html`
                    <p class="se-small" key=${name}>
                        <strong>${name}</strong><br />
                        <a class="se-md-link" href=${url} target="_blank" rel="noopener">${url}</a>
                    </p>`)}
            </section>
        </div>`;
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
                <header class="se-panel">
                    <h2>${state.event.title} ${state.event.edition} · ${state.event.phase}</h2>
                    <${HealthDots} health=${state.health} checkin=${state.checkin} />
                    <p class="se-small se-muted">
                        v${state.state?.version ?? 0} ·
                        ${state.counts.checked_in} checked in of ${state.counts.confirmed} ·
                        ${state.counts.joined_games} in the games
                    </p>
                    <button type="button" class="se-tap se-tap-danger"
                        onClick=${() => act('scene', { scene: 'blank', payload: {} })}>Blackout</button>
                </header>

                <div class="se-console-cols">
                    <${RunOfShow} state=${state} act=${act} />
                    <${ShowColumn} state=${state} act=${act} />
                    <${TeamsColumn} state=${state} act=${act} />
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
