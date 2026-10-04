// /assets/se/js/studio/tabs/live.js
//
// Live monitor (guide §18.3) and rehearsal controls (§11.13).
//
// This is the producer's view of the night while it happens: how fast
// people are arriving, whether the teams are even, whether the screens are
// actually receiving anything, and the links to hand to whoever is driving
// them.
//
// It is read-mostly on purpose. The show is run from the host console, not
// from here — the only buttons are the ones a producer needs and a host
// should not have: rotate the display links, and turn the rehearsal on and
// off.

import { html } from '@se/core/html.js';
import { useState, useEffect, useRef } from 'preact/hooks';
import { studio } from '@se/core/api.js';
import { current, toast, can } from '../state.js';
import { Card, Button, Spinner, EmptyState } from '../ui.js';

const POLL_MS = 5000;

function Dot({ level }) {
    const colors = {
        ok: 'bg-emerald-500',
        warn: 'bg-amber-500',
        bad: 'bg-hodRed',
        off: 'bg-gray-300',
    };

    return html`<span class=${'inline-block w-2.5 h-2.5 rounded-full ' + (colors[level] || colors.off)}
                      aria-hidden="true"></span>`;
}

function Health({ health }) {
    const age = health?.snapshot_age_s;
    const rows = [
        ['Screens', age == null ? 'off' : (age < 3 ? 'ok' : (age < 15 ? 'warn' : 'bad')),
            age == null ? 'nothing published yet' : age.toFixed(0) + ' seconds old'],
        ['Realtime', health?.driver === 'ably' ? 'ok' : 'ok', health?.driver === 'ably' ? 'Ably' : 'polling'],
        ['Cron', health?.cron == null ? 'off' : (health.cron < 600 ? 'ok' : 'bad'),
            health?.cron == null ? 'arrives with the games release' : health.cron + 's since the last run'],
        ['SMS worker', health?.sms_worker ? 'ok' : 'warn', health?.sms_worker ? 'healthy' : 'not reporting'],
    ];

    return html`
        <dl class="grid gap-3 sm:grid-cols-2">
            ${rows.map(([label, level, note]) => html`
                <div class="flex items-center gap-3" key=${label}>
                    <${Dot} level=${level} />
                    <dt class="text-sm font-semibold text-gray-700">${label}</dt>
                    <dd class="text-sm text-gray-400">${note}</dd>
                </div>`)}
        </dl>`;
}

function Pace({ pace }) {
    if (!pace?.length) {
        return html`<p class="text-sm text-gray-400">Nobody has checked in yet.</p>`;
    }

    const max = Math.max(...pace.map((p) => p.n), 1);

    return html`
        <div class="flex items-end gap-1 h-24" role="img"
             aria-label=${'Check-ins per five minutes, peaking at ' + max}>
            ${pace.map((bucket, i) => html`
                <div key=${i} class="flex-1 bg-hodBlue/80 rounded-t"
                     style=${{ height: Math.max(4, (bucket.n / max) * 96) + 'px' }}
                     title=${bucket.n + ' in five minutes'}></div>`)}
        </div>`;
}

function Balance({ balance }) {
    if (!balance?.length) {
        return html`<p class="text-sm text-gray-400">Teams fill up as people check in.</p>`;
    }

    const sizes = balance.map((t) => t.n);
    const spread = Math.max(...sizes) - Math.min(...sizes);

    return html`
        <div class="space-y-3">
            <p class=${'text-sm font-semibold ' + (spread <= 1 ? 'text-emerald-600' : 'text-amber-600')}>
                ${spread <= 1
                    ? 'Balanced — no more than one person between the biggest and smallest team.'
                    : 'Out by ' + spread + '. That usually means somebody was moved by hand.'}
            </p>
            ${balance.map((team) => html`
                <div class="flex items-center gap-3" key=${team.id}>
                    <span class="w-5 h-5 rounded-md shrink-0" style=${{ background: team.hex }} aria-hidden="true"></span>
                    <span class="w-32 text-sm font-semibold text-gray-700 truncate">${team.name || team.label}</span>
                    <span class="text-sm text-gray-900 font-bold w-8">${team.n}</span>
                    <span class="text-xs text-gray-400">
                        ${team.n_Female}F · ${team.n_Male}M · ${team.n_member} members · ${team.n_guest} guests
                    </span>
                </div>`)}
        </div>`;
}

function Displays({ displays, onRotated }) {
    const [busy, setBusy] = useState(false);

    if (!displays || !Object.keys(displays).length) {
        return html`<p class="text-sm text-gray-400">Links appear once the event has gone live at least once.</p>`;
    }

    const labels = {
        stage: ['Stage screen', 'The projector in the hall. Open it, press Start, leave it alone.'],
        lobby: ['Lobby screen', 'The TV by the door: QR, countdown and arrivals.'],
        room: ['Room feed', 'The raw data feed. Only needed for debugging.'],
    };

    return html`
        <div class="space-y-4">
            ${Object.entries(displays).map(([kind, url]) => html`
                <div key=${kind}>
                    <p class="text-sm font-semibold text-gray-900">${labels[kind]?.[0] || kind}</p>
                    <p class="text-xs text-gray-400 mb-1">${labels[kind]?.[1] || ''}</p>
                    <div class="flex flex-wrap items-center gap-2">
                        <code class="text-xs bg-gray-50 border border-gray-200 rounded-lg px-2.5 py-1.5 break-all">${url}</code>
                        <${Button} variant="ghost" onClick=${() => {
                            navigator.clipboard?.writeText(url);
                            toast('Link copied.', 'success');
                        }}>Copy<//>
                    </div>
                </div>`)}

            ${can('event.edit') ? html`
                <div class="pt-3 border-t border-gray-100">
                    <p class="text-xs text-gray-500 mb-2">
                        Anyone holding a link can watch the screen feed. Rotating makes every old link
                        stop working immediately — do it if a link has been shared too widely.
                    </p>
                    <${Button} variant="secondary" loading=${busy} onClick=${async () => {
                        if (!confirm('Rotate the display links? Every screen will need the new link.')) return;
                        setBusy(true);
                        try {
                            const data = await studio('keys_rotate', { id: current.value.id });
                            onRotated(data.displays);
                            toast('New links. Re-open the screens.', 'success');
                        } catch (e) { toast(e.message, 'error'); }
                        setBusy(false);
                    }}>Rotate the links<//>
                </div>` : null}
        </div>`;
}

function Rehearsal({ testMode, onChanged }) {
    const [busy, setBusy] = useState(false);
    if (!can('event.edit')) return null;

    return html`
        <${Card} title="Rehearsal"
            subtitle="Walk the whole night through without leaving a mark on the real numbers.">

            <div class=${'rounded-2xl p-4 mb-5 ' + (testMode ? 'bg-red-50 border border-hodRed/30' : 'bg-gray-50')}>
                <p class=${'font-semibold ' + (testMode ? 'text-hodRed' : 'text-gray-700')}>
                    ${testMode ? 'Test mode is ON' : 'Test mode is off'}
                </p>
                <p class="text-sm text-gray-600 mt-1">
                    ${testMode
                        ? 'Every screen is showing a red TEST MODE ribbon. Registrations and check-ins made '
                          + 'now are marked as tests, the crew can check in outside the window, and nothing '
                          + 'counts towards capacity. It switches itself off fifteen minutes before doors.'
                        : 'Turn this on before a walk-through so the rehearsal can be wiped afterwards.'}
                </p>
            </div>

            <div class="flex flex-wrap gap-3">
                <${Button} variant=${testMode ? 'secondary' : 'primary'} loading=${busy}
                    onClick=${async () => {
                        setBusy(true);
                        try {
                            await studio('test_mode', { id: current.value.id, on: !testMode });
                            onChanged();
                        } catch (e) { toast(e.message, 'error'); }
                        setBusy(false);
                    }}>${testMode ? 'Turn test mode off' : 'Turn test mode on'}<//>

                <${Button} variant="danger" loading=${busy} onClick=${async () => {
                    if (!confirm('Delete every test registration and check-in for this event? This cannot be undone.')) return;
                    setBusy(true);
                    try {
                        const data = await studio('reset_rehearsal', { id: current.value.id });
                        const n = Object.values(data.removed || {}).reduce((a, b) => a + b, 0);
                        toast(n ? n + ' test rows removed.' : 'Nothing to clear.', 'success');
                        onChanged();
                    } catch (e) { toast(e.message, 'error'); }
                    setBusy(false);
                }}>Reset rehearsal<//>
            </div>
        <//>`;
}

export function LiveTab() {
    const event = current.value;
    const [data, setData] = useState(null);
    const [error, setError] = useState(null);
    const timer = useRef(null);

    const load = async () => {
        try {
            setData(await studio('live_monitor', { id: event.id }));
            setError(null);
        } catch (e) {
            setError(e.message);
        }
    };

    useEffect(() => {
        load();
        timer.current = setInterval(() => {
            if (document.visibilityState === 'visible') load();
        }, POLL_MS);
        return () => clearInterval(timer.current);
    }, [event.id]);

    if (error && !data) {
        return html`<${Card} title="Live">
            <${EmptyState} title="Not available yet" message=${error} />
        <//>`;
    }
    if (!data) return html`<${Spinner} label="Reading the room…" />`;

    return html`
        <div class="space-y-6">
            ${data.test_mode ? html`
                <p class="rounded-2xl bg-hodRed text-white font-bold px-5 py-3">
                    TEST MODE — nothing recorded now will count.
                </p>` : null}

            <${Card} title="Tonight">
                <div class="grid gap-6 sm:grid-cols-3 mb-6">
                    ${[
                        ['Checked in', data.checked_in],
                        ['Confirmed', data.confirmed],
                        ['In the games', data.joined_games],
                    ].map(([label, value]) => html`
                        <div key=${label}>
                            <p class="text-3xl font-display font-bold text-gray-900">${value}</p>
                            <p class="text-sm text-gray-500">${label}</p>
                        </div>`)}
                </div>
                <p class="text-sm text-gray-500 mb-2">Arrivals, five minutes at a time</p>
                <${Pace} pace=${data.pace} />
                <p class="text-xs text-gray-400 mt-2">
                    Scene on screen: <span class="font-semibold">${data.scene || 'nothing yet'}</span>
                </p>
            <//>

            <${Card} title="Team balance"
                subtitle="Sizes should never differ by more than one, and gender by no more than two.">
                <${Balance} balance=${data.balance} />
            <//>

            <${Card} title="Health">
                <${Health} health=${data.health} />
            <//>

            <${Card} title="Screen links">
                <${Displays} displays=${data.displays}
                    onRotated=${(displays) => setData({ ...data, displays })} />
            <//>

            <${Rehearsal} testMode=${data.test_mode} onChanged=${load} />

            <${Card} title="Recent activity" subtitle="The last twenty things that happened.">
                ${(data.audit || []).length
                    ? html`<ul class="divide-y divide-gray-100 text-sm">
                        ${data.audit.map((row, i) => html`
                            <li class="py-2 flex justify-between gap-4" key=${i}>
                                <span class="font-mono text-gray-700">${row.action}</span>
                                <span class="text-gray-400">${row.at}</span>
                            </li>`)}
                    </ul>`
                    : html`<p class="text-sm text-gray-400">Nothing yet.</p>`}
            <//>
        </div>`;
}
