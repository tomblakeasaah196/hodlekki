// /assets/se/js/host/games.js
//
// The game runner on the host console (guide §11.2–§11.11, §13.10).
//
// One person runs every game from here, usually while talking into a
// microphone. So each moment of a round offers ONE big next step (show the
// question, reveal, next question), the answer the host must not say out
// loud is always on screen in amber, and anything that cannot be undone —
// voiding a round, undoing points — asks for a reason in place instead of
// a pop-up.
//
// Everything comes from the console poll (`state.game` is the active game
// with its private view: the answer, the buzz queue with names, the Feud
// board, the charades phrase). Every button goes through `act`, which
// carries the live-state version, so two crew phones cannot both press it.

import { html } from '@se/core/html.js';
import { useEffect, useState } from 'preact/hooks';
import { serverNow } from '@se/core/clock.js';

const ICON = { live_quiz: '⚡', trivia: '🧠', buzzer: '🔔', who_am_i: '🕵️', charades: '🎭', feud: '📋' };
const LETTERS = ['A', 'B', 'C', 'D'];

const NEXT_LABEL = {
    live_quiz: 'Next question', trivia: 'Next question', buzzer: 'Next question',
    who_am_i: 'Next person', charades: 'Next turn', feud: 'Next board',
};

// --------------------------------------------------------------------------
// Small pieces
// --------------------------------------------------------------------------

/** Re-render every quarter second while a clock is running. */
function useNow(running) {
    const [now, setNow] = useState(serverNow());
    useEffect(() => {
        if (!running) return undefined;
        const timer = setInterval(() => setNow(serverNow()), 250);
        return () => clearInterval(timer);
    }, [running]);
    return running ? now : serverNow();
}

export const teamName = (team) => (team ? (team.name || 'Team ' + team.label) : 'No team');

export function TeamChip({ team }) {
    if (!team) return null;
    return html`
        <span class="se-team-badge ${team.ring ? 'se-team-badge-ring' : ''}"
              style=${{ '--team-color': team.hex, '--team-on': team.on }}>${teamName(team)}</span>`;
}

/** "Get ready 3", "12s left", "Time's up" — from the round's server times. */
function Countdown({ opensMs, closesMs, label = 'left' }) {
    const running = !!closesMs;
    const now = useNow(running);
    if (!opensMs || !closesMs) return null;

    if (now < opensMs) {
        return html`<p class="se-gr-clock" data-phase="ready">Get ready… ${Math.ceil((opensMs - now) / 1000)}</p>`;
    }
    if (now < closesMs) {
        return html`<p class="se-gr-clock" data-phase="open">${Math.ceil((closesMs - now) / 1000)}s ${label}</p>`;
    }
    return html`<p class="se-gr-clock" data-phase="over">Time's up</p>`;
}

/** A destructive button that asks for its reason in place. */
export function ReasonButton({ label, placeholder, onConfirm, small = false }) {
    const [open, setOpen] = useState(false);
    const [reason, setReason] = useState('');

    if (!open) {
        return html`<button type="button" class=${'se-tap se-tap-danger' + (small ? ' se-tap-sm' : '')}
            onClick=${() => setOpen(true)}>${label}</button>`;
    }

    const confirm = async () => {
        if (await onConfirm(reason.trim())) {
            setOpen(false);
            setReason('');
        }
    };

    return html`
        <div class="se-gr-reason">
            <input class="se-input" value=${reason} maxLength="160" placeholder=${placeholder}
                aria-label="Reason" onInput=${(e) => setReason(e.currentTarget.value)}
                onKeyDown=${(e) => { if (e.key === 'Enter' && reason.trim().length >= 3) confirm(); }} />
            <div class="se-gr-actions">
                <button type="button" class="se-tap se-tap-danger" disabled=${reason.trim().length < 3} onClick=${confirm}>${label}</button>
                <button type="button" class="se-tap" onClick=${() => { setOpen(false); setReason(''); }}>Cancel</button>
            </div>
        </div>`;
}

/** Team buttons; `value` is a team id (or an array of them with `multi`). */
function TeamPicker({ teams, value, onChange, multi = false, max = 2, exclude = [] }) {
    const chosen = multi ? value : [value];
    return html`
        <div class="se-gr-teams">
            ${teams.filter((t) => !exclude.includes(t.id)).map((team) => {
                const on = chosen.includes(team.id);
                const pick = () => {
                    if (!multi) return onChange(on ? null : team.id);
                    if (on) return onChange(value.filter((id) => id !== team.id));
                    return onChange([...value, team.id].slice(-max));
                };
                return html`
                    <button key=${team.id} type="button" class="se-tap se-gr-team" aria-pressed=${on ? 'true' : 'false'}
                        style=${{ '--team-color': team.hex }} onClick=${pick}>
                        ${teamName(team)}<span class="se-muted se-gr-team-n">${team.n ?? ''}</span>
                    </button>`;
            })}
        </div>`;
}

/** Answer spread for a multiple-choice round. */
function Distribution({ choices, counts, correct }) {
    const total = (counts || []).reduce((sum, n) => sum + n, 0);
    return html`
        <ol class="se-gr-dist">
            ${choices.map((choice, i) => {
                const n = counts?.[i] ?? 0;
                const pct = total ? Math.round((n / total) * 100) : 0;
                return html`
                    <li key=${i} class="se-gr-dist-row" data-correct=${i === correct ? '1' : '0'}>
                        <span class="se-gr-dist-label"><strong>${LETTERS[i]}</strong> ${choice}${i === correct ? ' ✓' : ''}</span>
                        <span class="se-gr-dist-track"><span class="se-gr-dist-fill" style=${{ width: pct + '%' }}></span></span>
                        <span class="se-gr-dist-n">${n}</span>
                    </li>`;
            })}
        </ol>`;
}

/** Points the round gave, team by team. */
function TeamPoints({ points, teamById }) {
    const rows = Object.entries(points || {}).filter(([, p]) => p !== 0);
    if (!rows.length) return html`<p class="se-small se-muted">No team scored this round.</p>`;
    return html`
        <ul class="se-gr-points">
            ${rows.sort((a, b) => b[1] - a[1]).map(([id, p]) => html`
                <li key=${id}><${TeamChip} team=${teamById[id]} /> <strong>${p > 0 ? '+' : ''}${p}</strong></li>`)}
        </ul>`;
}

function Answer({ children }) {
    return html`<div class="se-private-answer"><span class="se-gr-private-tag">Only you see this</span>${children}</div>`;
}

// --------------------------------------------------------------------------
// Rounds, one component per kind of game
// --------------------------------------------------------------------------

/** Live Quiz and Trivia: arm → answers open → lock → reveal → (score). */
function QuizRound({ game, round, pub, priv, run, settings, teamById }) {
    const item = priv.item?.payload || {};
    const choices = item.choices || pub.choices || [];
    const correct = typeof item.answer_index === 'number' ? item.answer_index : -1;
    const state = round.state;
    const result = pub.result || null;

    return html`
        <div class="se-gr">
            <p class="se-gr-question">${pub.prompt || item.prompt || item.emojis || item.lead || ''}</p>
            ${state === 'pending' ? html`
                <${Answer}>The answer is <strong>${LETTERS[correct]} · ${priv.answer}</strong>${priv.item?.scripture_ref ? ' (' + priv.item.scripture_ref + ')' : ''}</${Answer}>
                <ol class="se-gr-choices">${choices.map((c, i) => html`<li key=${i} data-correct=${i === correct ? '1' : '0'}><strong>${LETTERS[i]}</strong> ${c}</li>`)}</ol>
                <button type="button" class="se-tap se-tap-primary" onClick=${() => run('round_arm', { round_id: round.id })}>
                    Show the question ▶</button>
                <p class="se-small se-muted">Phones get a ${Math.round((settings.preroll_ms || 3000) / 1000)}-second countdown, then ${Math.round((settings.duration_ms || 20000) / 1000)} seconds to answer.</p>` : null}

            ${['armed', 'open', 'locked'].includes(state) ? html`
                <${Countdown} opensMs=${pub.opens_ms} closesMs=${pub.closes_ms} />
                <p class="se-gr-big">${pub.answered ?? 0} <span class="se-small se-muted">${game.type === 'trivia' ? 'captains have locked in' : 'answers in'}</span></p>
                <${Distribution} choices=${choices} counts=${priv.live_distribution} correct=${correct} />
                <div class="se-gr-actions">
                    ${state !== 'locked' ? html`<button type="button" class="se-tap" onClick=${() => run('round_lock', { round_id: round.id })}>Stop answers</button>` : null}
                    <button type="button" class="se-tap se-tap-primary" onClick=${() => run('round_reveal', { round_id: round.id })}>Reveal the answer</button>
                </div>` : null}

            ${state === 'revealed' ? html`
                <${Distribution} choices=${choices} counts=${result?.distribution} correct=${correct} />
                <button type="button" class="se-tap se-tap-primary" onClick=${() => run('round_score', { round_id: round.id })}>Give out the points</button>` : null}

            ${state === 'scored' ? html`
                <p class="se-gr-result">✓ ${result?.answer || priv.answer}</p>
                <${Distribution} choices=${choices} counts=${result?.distribution} correct=${correct} />
                ${game.type === 'trivia' && result?.team_choices ? html`
                    <ul class="se-gr-points">
                        ${Object.entries(result.team_choices).map(([id, c]) => html`
                            <li key=${id}><${TeamChip} team=${teamById[id]} /> ${LETTERS[c.choice] || '—'}${c.by === 'vote' ? ' (team vote)' : ''}</li>`)}
                    </ul>` : null}
                <${TeamPoints} points=${pub.team_points} teamById=${teamById} />` : null}
        </div>`;
}

/** Buzzer and Who Am I?: open the buzzers, judge the first buzz, next clue. */
function BuzzRound({ game, round, pub, priv, run, teamById }) {
    const item = priv.item?.payload || {};
    const state = round.state;
    const whoAmI = game.type === 'who_am_i';
    const queue = (priv.buzzes || []).filter((b) => b.attempt === round.attempt);
    const leader = queue.find((b) => b.judged === 'pending') || null;
    const earlier = (priv.buzzes || []).filter((b) => b.attempt !== round.attempt);
    const clues = item.clues || [];
    const clueIndex = pub.clue_index ?? 0;
    const lockedOut = (pub.locked_out || []).map((id) => teamById[id]).filter(Boolean);
    const result = pub.result || null;

    return html`
        <div class="se-gr">
            ${whoAmI ? html`
                <ol class="se-gr-clues">
                    ${clues.map((clue, i) => html`
                        <li key=${i} data-shown=${state !== 'pending' && i <= clueIndex ? '1' : '0'}>
                            <span class="se-muted">Clue ${i + 1}</span> ${clue}</li>`)}
                </ol>` : html`<p class="se-gr-question">${pub.prompt || item.prompt || item.emojis || ''}</p>`}

            ${state !== 'scored' ? html`<${Answer}>Answer: <strong>${priv.answer}</strong>${priv.item?.scripture_ref ? ' (' + priv.item.scripture_ref + ')' : ''}</${Answer}>` : null}

            ${state === 'pending' ? html`
                <button type="button" class="se-tap se-tap-primary" onClick=${() => run('round_arm', { round_id: round.id })}>
                    ${whoAmI ? 'Show the first clue ▶' : 'Open the buzzers ▶'}</button>` : null}

            ${['armed', 'open', 'locked'].includes(state) ? html`
                ${state !== 'locked' ? html`<${Countdown} opensMs=${pub.opens_ms} closesMs=${pub.closes_ms} label="to buzz" />`
                    : html`<p class="se-gr-clock" data-phase="over">Buzzers closed</p>`}
                ${leader ? html`
                    <div class="se-gr-buzz-lead" style=${{ '--team-color': teamById[leader.team_id]?.hex }}>
                        <p class="se-small se-muted">First buzz</p>
                        <p class="se-gr-big"><${TeamChip} team=${teamById[leader.team_id]} /></p>
                        <p>${leader.player}${leader.player_no ? ' · #' + leader.player_no : ''}
                            ${pub.opens_ms ? html`<span class="se-muted"> · ${((leader.effective_ms - pub.opens_ms) / 1000).toFixed(2)}s</span>` : null}</p>
                        <div class="se-gr-actions">
                            <button type="button" class="se-tap se-tap-good" onClick=${() => run('buzz_judge', { round_id: round.id, buzz_id: leader.id, correct: true })}>✓ Right</button>
                            <button type="button" class="se-tap se-tap-danger" onClick=${() => run('buzz_judge', { round_id: round.id, buzz_id: leader.id, correct: false })}>✕ Wrong</button>
                        </div>
                    </div>` : html`<p class="se-small se-muted">${state === 'locked' ? 'Nobody buzzed.' : 'Waiting for a buzz…'}</p>`}
                ${queue.length > 1 ? html`
                    <ol class="se-gr-queue">
                        ${queue.filter((b) => b !== leader).map((b) => html`
                            <li key=${b.id}><${TeamChip} team=${teamById[b.team_id]} /> ${b.player} <span class="se-muted">· ${b.judged === 'pending' ? 'waiting' : b.judged}</span></li>`)}
                    </ol>` : null}
                ${lockedOut.length ? html`<p class="se-small se-muted">Out for this ${whoAmI ? 'clue' : 'question'}: ${lockedOut.map(teamName).join(', ')}</p>` : null}
                <div class="se-gr-actions">
                    ${whoAmI && clueIndex + 1 < clues.length ? html`
                        <button type="button" class="se-tap" disabled=${!!leader} title=${leader ? 'Judge the buzz first' : ''}
                            onClick=${() => run('clue_next', { round_id: round.id })}>Next clue (${clueIndex + 2} of ${clues.length})</button>` : null}
                    ${state !== 'locked' ? html`<button type="button" class="se-tap" onClick=${() => run('round_lock', { round_id: round.id })}>Close the buzzers</button>` : null}
                    <button type="button" class=${'se-tap' + (leader ? '' : ' se-tap-primary')} onClick=${() => run('round_reveal', { round_id: round.id })}>
                        Reveal the answer</button>
                </div>` : null}

            ${state === 'scored' ? html`
                <p class="se-gr-result">✓ ${result?.answer || priv.answer}</p>
                ${result?.winner_team_id ? html`<p><${TeamChip} team=${teamById[result.winner_team_id]} /> got it.</p>`
                    : html`<p class="se-small se-muted">Nobody got it.</p>`}
                <${TeamPoints} points=${pub.team_points} teamById=${teamById} />` : null}

            ${earlier.length && state !== 'pending' ? html`
                <details class="se-small">
                    <summary class="se-muted">Earlier buzzes (${earlier.length})</summary>
                    <ul class="se-gr-queue">${earlier.map((b) => html`<li key=${b.id}><${TeamChip} team=${teamById[b.team_id]} /> ${b.player} · ${b.judged}</li>`)}</ul>
                </details>` : null}
        </div>`;
}

/** Bible Charades: pick the acting team and presenter, start, Got it / Pass, end. */
function CharadesRound({ round, pub, priv, run, settings, teams, teamById }) {
    const info = priv.charades || {};
    const [team, setTeam] = useState(round.team_id || null);
    const [player, setPlayer] = useState('');
    const [show, setShow] = useState(!!info.show_on_console);
    const started = !!pub.opens_ms;
    const passesLeft = Math.max(0, (settings.max_passes ?? 2) - (info.passes || 0));
    const now = useNow(started && round.state !== 'scored');
    const late = started && pub.closes_ms && now > pub.closes_ms + 3000;
    const words = (info.words || []).filter((w) => w.result === 'correct').length;

    if (round.state === 'scored') {
        return html`
            <div class="se-gr">
                <p class="se-gr-result">${teamName(teamById[round.team_id])} acted out ${words} phrase${words === 1 ? '' : 's'}.</p>
                <${TeamPoints} points=${pub.team_points} teamById=${teamById} />
            </div>`;
    }

    if (!started) {
        return html`
            <div class="se-gr">
                ${info.presenter ? html`
                    <div class="se-now-block">
                        <p class="se-small se-muted">Presenter for ${teamName(teamById[round.team_id])}</p>
                        <p class="se-now-title">${info.presenter.display_name}${info.presenter.player_no ? ' · #' + info.presenter.player_no : ''}</p>
                        <p class="se-small">The first phrase is on their phone now. Get them to the front, then start the clock.</p>
                    </div>
                    <button type="button" class="se-tap se-tap-primary" onClick=${() => run('charades_start', { round_id: round.id })}>
                        Start the ${Math.round((settings.turn_ms || 60000) / 1000)}-second timer ▶</button>
                    <details>
                        <summary class="se-small se-muted">Choose someone else</summary>
                        ${presenterForm()}
                    </details>` : presenterForm()}
            </div>`;
    }

    function presenterForm() {
        return html`
            <div class="se-stack-sm">
                <p class="se-small"><strong>Which team acts?</strong></p>
                <${TeamPicker} teams=${teams} value=${team} onChange=${setTeam} />
                <label class="se-label" for="se-gr-presenter">Presenter's player number</label>
                <input class="se-input" id="se-gr-presenter" inputMode="numeric" value=${player} placeholder="Leave empty to pick someone at random"
                    onInput=${(e) => setPlayer(e.currentTarget.value)} />
                <label class="se-small se-gr-check">
                    <input type="checkbox" checked=${show} onChange=${(e) => setShow(e.currentTarget.checked)} />
                    Show the phrases on this console too
                </label>
                <button type="button" class="se-tap se-tap-primary" disabled=${!team}
                    onClick=${() => run('charades_turn', { round_id: round.id, team_id: team, player_no: player.trim() || 'random', show_on_console: show })}>
                    Choose the presenter</button>
            </div>`;
    }

    return html`
        <div class="se-gr">
            <p class="se-small se-muted">${teamName(teamById[round.team_id])} · ${info.presenter?.display_name || ''}</p>
            <${Countdown} opensMs=${pub.opens_ms} closesMs=${pub.closes_ms} />
            ${info.phrase ? (info.show_on_console
                ? html`<${Answer}><strong class="se-gr-big">${info.phrase}</strong>${info.category ? html`<br /><span class="se-small">${info.category}</span>` : null}</${Answer}>`
                : html`<p class="se-small se-muted">The phrase is on the presenter's phone.</p>`)
                : html`<p class="se-warn">No phrases left in this game.</p>`}
            <p class="se-gr-big">${words} <span class="se-small se-muted">guessed · ${passesLeft} pass${passesLeft === 1 ? '' : 'es'} left</span></p>
            <div class="se-gr-actions">
                <button type="button" class="se-tap se-tap-good" disabled=${late || !info.phrase} onClick=${() => run('charades_mark', { round_id: round.id, result: 'correct' })}>✓ Got it</button>
                <button type="button" class="se-tap" disabled=${late || !info.phrase || passesLeft === 0} onClick=${() => run('charades_mark', { round_id: round.id, result: 'pass' })}>Pass</button>
                <button type="button" class=${'se-tap' + (late ? ' se-tap-primary' : '')} onClick=${() => run('charades_end', { round_id: round.id })}>End the turn</button>
            </div>
        </div>`;
}

/** Family Feud: face-off → control → strikes → steal → bank. */
function FeudRound({ round, pub, priv, run, teams, teamById, roster }) {
    const [pair, setPair] = useState([]);
    const [repA, setRepA] = useState('');
    const [repB, setRepB] = useState('');
    const phase = pub.phase || 'setup';
    const board = priv.board || [];
    const revealed = new Set((round.data?.revealed || []).map(Number));
    const name = (regId) => roster.find((p) => p.registration_id === regId)?.display_name || '';
    const queue = (priv.buzzes || []).filter((b) => b.attempt === round.attempt);
    const multiplier = pub.multiplier || 1;
    const control = teamById[pub.control_team];
    const steal = teamById[pub.steal_team];
    const bankTo = round.data?.steal_success ? steal : control;

    if (round.state === 'pending' || phase === 'setup') {
        return html`
            <div class="se-gr">
                <p class="se-gr-question">${pub.question}</p>
                <${Answer}>
                    <ol class="se-gr-board-private">${board.map((a) => html`<li key=${a.id}>${a.label} <span>${a.points}</span></li>`)}</ol>
                </${Answer}>
                <p class="se-small"><strong>Choose the two face-off teams</strong></p>
                <${TeamPicker} teams=${teams} value=${pair} onChange=${setPair} multi />
                <div class="se-gr-actions">
                    <input class="se-input" inputMode="numeric" value=${repA} placeholder="Player no. (or random)" aria-label="First team's player"
                        onInput=${(e) => setRepA(e.currentTarget.value)} />
                    <input class="se-input" inputMode="numeric" value=${repB} placeholder="Player no. (or random)" aria-label="Second team's player"
                        onInput=${(e) => setRepB(e.currentTarget.value)} />
                </div>
                <button type="button" class="se-tap se-tap-primary" disabled=${pair.length !== 2}
                    onClick=${() => run('feud_faceoff', { round_id: round.id, team_a: pair[0], team_b: pair[1], rep_a: repA.trim(), rep_b: repB.trim() })}>
                    Start the face-off ▶</button>
            </div>`;
    }

    const boardView = html`
        <ol class="se-gr-board">
            ${board.map((a, i) => {
                const shown = revealed.has(a.id);
                return html`
                    <li key=${a.id}>
                        <button type="button" class="se-tap se-gr-slot" data-revealed=${shown ? '1' : '0'} disabled=${shown}
                            onClick=${() => run('feud_reveal', { round_id: round.id, answer_id: a.id })}>
                            <span class="se-muted">${i + 1}</span> ${a.label} <strong>${a.points}</strong>
                        </button>
                    </li>`;
            })}
        </ol>`;

    return html`
        <div class="se-gr">
            <p class="se-gr-question">${pub.question}</p>
            <p class="se-small se-muted">Board ${round.round_no}${multiplier > 1 ? ' · points ×' + multiplier : ''} · in the bank: <strong>${pub.bank * multiplier}</strong></p>

            ${phase === 'faceoff' ? html`
                <p class="se-small">Face-off: <${TeamChip} team=${teamById[pub.team_a]} /> ${name(round.data?.rep_a)}
                    vs <${TeamChip} team=${teamById[pub.team_b]} /> ${name(round.data?.rep_b)}</p>
                ${queue.length ? html`<p class="se-small">First buzz: <${TeamChip} team=${teamById[queue[0].team_id]} /> ${queue[0].player}</p>` : null}
                <p class="se-small se-muted">Reveal what they say, then give the board to the team that plays it.</p>` : null}

            ${boardView}

            ${phase === 'faceoff' ? html`
                <div class="se-gr-actions">
                    ${[pub.team_a, pub.team_b].map((id) => html`
                        <button key=${id} type="button" class="se-tap se-tap-primary" onClick=${() => run('feud_control', { round_id: round.id, team_id: id })}>
                            ${teamName(teamById[id])} plays</button>`)}
                </div>` : null}

            ${phase === 'control' ? html`
                <p><${TeamChip} team=${control} /> is playing the board.</p>
                <p class="se-gr-strikes" aria-label=${pub.strikes + ' strikes'}>${[0, 1, 2].map((i) => html`<span key=${i} data-on=${i < pub.strikes ? '1' : '0'}>✕</span>`)}</p>
                <div class="se-gr-actions">
                    <button type="button" class="se-tap se-tap-danger" onClick=${() => run('feud_strike', { round_id: round.id })}>✕ Strike</button>
                    <button type="button" class="se-tap" onClick=${() => run('feud_bank', { round_id: round.id })}>Bank ${pub.bank * multiplier} for ${teamName(control)}</button>
                </div>` : null}

            ${phase === 'steal' ? html`
                <p>Three strikes. <${TeamChip} team=${steal} /> can steal with one answer.</p>
                <div class="se-gr-actions">
                    <button type="button" class="se-tap se-tap-good" onClick=${() => run('feud_steal', { round_id: round.id, success: true })}>✓ Stolen</button>
                    <button type="button" class="se-tap se-tap-danger" onClick=${() => run('feud_steal', { round_id: round.id, success: false })}>✕ Not on the board</button>
                </div>` : null}

            ${phase === 'bank' ? html`
                <button type="button" class="se-tap se-tap-primary" onClick=${() => run('feud_bank', { round_id: round.id })}>
                    Bank ${pub.bank * multiplier} for ${teamName(bankTo)}</button>` : null}

            ${phase === 'done' ? html`
                <p class="se-gr-result"><${TeamChip} team=${teamById[pub.winner_team]} /> banks ${round.data?.total ?? pub.bank * multiplier}.</p>
                ${revealed.size < board.length ? html`
                    <button type="button" class="se-tap" onClick=${() => run('feud_reveal_all', { round_id: round.id })}>Turn over the rest of the board</button>` : null}` : null}
        </div>`;
}

// --------------------------------------------------------------------------
// The runner
// --------------------------------------------------------------------------

function GameList({ games, activeId, run }) {
    if (!games.length) {
        return html`<p class="se-small se-muted">No games yet. Build the lineup in Studio → Games — the Chara starter pack is one click.</p>`;
    }

    return html`
        <ol class="se-gr-games">
            ${games.map((g) => {
                const label = g.status === 'paused' ? 'Resume' : g.status === 'finished' ? 'Play again' : g.status === 'live' ? 'Playing' : 'Start';
                const blocked = g.items_total === 0;
                return html`
                    <li key=${g.id} class="se-row" data-active=${g.id === activeId ? '1' : '0'}>
                        <span class="se-gr-game-text">
                            <strong>${ICON[g.type] || '🎲'} ${g.title}</strong>
                            <span class="se-small se-muted">${g.type_label} · ${g.rounds_scored} of ${g.items_total} played${g.status === 'finished' ? ' · finished' : g.status === 'paused' ? ' · paused' : ''}</span>
                        </span>
                        <button type="button" class=${'se-tap' + (g.status === 'live' ? '' : ' se-tap-primary')}
                            disabled=${blocked || g.status === 'live'} title=${blocked ? 'Add questions in Studio → Games' : ''}
                            onClick=${() => run('game_start', { game_id: g.id })}>${blocked ? 'No questions' : label}</button>
                    </li>`;
            })}
        </ol>`;
}

export function GameRunner({ state, act }) {
    const [busy, setBusy] = useState(false);
    const [switching, setSwitching] = useState(false);
    const game = state.game;
    const teams = state.teams || [];
    const teamById = Object.fromEntries(teams.map((t) => [t.id, t]));
    const listed = (state.games || []).find((g) => g.id === game?.id);
    const settings = listed?.settings || {};

    /** One action at a time: a double tap on a slow network is one tap. */
    const run = async (action, payload = {}) => {
        if (busy) return null;
        setBusy(true);
        try {
            return await act(action, payload);
        } finally {
            setBusy(false);
        }
    };

    const footer = html`
        <div class="se-gr-actions se-gr-footer">
            <button type="button" class="se-tap" onClick=${() => run('scene', { scene: 'leaderboard', payload: { show_mvp: true } })}>Leaderboard on screen</button>
            <button type="button" class="se-tap" onClick=${() => { if (confirm('Run the finale? The stage counts down to the champions.')) run('finale'); }}>Run the finale 🏆</button>
        </div>`;

    if (!game || game.status !== 'live' || switching) {
        return html`
            <section class="se-panel" aria-busy=${busy ? 'true' : 'false'}>
                <div class="se-gr-head">
                    <h2>Games</h2>
                    ${switching ? html`<button type="button" class="se-tap se-tap-sm" onClick=${() => setSwitching(false)}>Back to ${game?.title}</button>` : null}
                </div>
                ${game && game.status === 'paused' && !switching ? html`
                    <p class="se-small">⏸ <strong>${game.title}</strong> is paused.</p>` : null}
                <${GameList} games=${state.games || []} activeId=${game?.id}
                    run=${async (action, payload) => { const out = await run(action, payload); if (out) setSwitching(false); return out; }} />
                ${footer}
            </section>`;
    }

    const priv = game.private || {};
    const round = priv.round;
    const pub = game.round || {};
    const remaining = priv.remaining;
    const between = !round || ['scored', 'void'].includes(round.state);
    const props = { game, round, pub, priv, run, settings, teams, teamById, roster: state.roster || [] };

    return html`
        <section class="se-panel se-gr-panel" aria-busy=${busy ? 'true' : 'false'}>
            <div class="se-gr-head">
                <div>
                    <h2>Now playing</h2>
                    <p class="se-gr-title">${ICON[game.type] || '🎲'} ${game.title}</p>
                    <p class="se-small se-muted">
                        ${round ? (game.type === 'charades' ? 'Turn ' : game.type === 'feud' ? 'Board ' : 'Question ') + round.round_no : 'Not started'}
                        ${typeof remaining === 'number' ? ' · ' + remaining + ' left' : ''}
                        ${pub.test ? html` · <span class="se-warn">TEST</span>` : null}
                    </p>
                </div>
                <div class="se-gr-actions">
                    <button type="button" class="se-tap se-tap-sm" onClick=${() => run('game_pause', { game_id: game.id })}>Pause</button>
                    <button type="button" class="se-tap se-tap-sm" onClick=${() => setSwitching(true)}>Other games</button>
                </div>
            </div>

            ${round && round.state === 'void' ? html`<p class="se-small se-warn">The last round was voided: ${round.void_reason}. The next round replays it.</p>` : null}

            ${round && !between || round?.state === 'scored' ? html`
                ${game.type === 'live_quiz' || game.type === 'trivia' ? html`<${QuizRound} ...${props} />` : null}
                ${game.type === 'buzzer' || game.type === 'who_am_i' ? html`<${BuzzRound} ...${props} />` : null}
                ${game.type === 'charades' ? html`<${CharadesRound} key=${round.id} ...${props} />` : null}
                ${game.type === 'feud' ? html`<${FeudRound} key=${round.id} ...${props} />` : null}` : null}

            ${between ? html`
                ${remaining === 0 ? html`
                    <p class="se-small">That was the last ${game.type === 'feud' ? 'board' : 'question'} in ${game.title}.</p>
                    <button type="button" class="se-tap se-tap-primary" onClick=${() => run('game_finish', { game_id: game.id })}>Finish ${game.title}</button>`
                    : html`
                    <button type="button" class="se-tap se-tap-primary" onClick=${() => run('round_next', { game_id: game.id })}>
                        ${NEXT_LABEL[game.type] || 'Next round'} ▶</button>
                    <button type="button" class="se-tap" onClick=${() => run('game_finish', { game_id: game.id })}>Finish ${game.title}</button>`}` : null}

            ${round && round.state !== 'void' && round.state !== 'pending' ? html`
                <${ReasonButton} label="Void this round" small placeholder="Why? e.g. wrong question shown"
                    onConfirm=${(reason) => run('round_void', { round_id: round.id, reason })} />` : null}

            ${footer}
        </section>`;
}
