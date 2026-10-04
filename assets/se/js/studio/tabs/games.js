// /assets/se/js/studio/tabs/games.js
//
// Studio → Games (guide §11, §13.13): the night's game lineup, the question
// banks the games draw from, the Family Feud boards, and the rehearsal
// switch. Everything a producer sets up before the night lives here; running
// the games happens on the host console (/e/<slug>/host).

import { html } from '@se/core/html.js';
import { useEffect, useState } from 'preact/hooks';
import { current, can, toast } from '../state.js';
import { Button, Spinner } from '../ui.js';
import { act } from '../games/common.js';
import { LineupSection } from '../games/lineup.js';
import { BanksSection } from '../games/banks.js';
import { FeudSection } from '../games/feud.js';

const SECTIONS = [
    ['lineup', 'Lineup'],
    ['banks', 'Question banks'],
    ['feud', 'Family Feud boards'],
];

function Rehearsal({ eventId, testMode, reload, hostUrl }) {
    const [busy, setBusy] = useState(null);

    const toggle = async () => {
        setBusy('toggle');
        const res = await act('test_mode', { id: eventId, on: !testMode });
        setBusy(null);
        if (res.ok) { toast(res.data.test_mode ? 'Test mode is on.' : 'Test mode is off.', 'success'); reload(); }
    };
    const reset = async () => {
        if (!confirm('Clear everything the rehearsal created — test check-ins, rounds, scores and survey answers?')) return;
        setBusy('reset');
        const res = await act('reset_rehearsal', { id: eventId });
        setBusy(null);
        if (res.ok) { toast('Rehearsal cleared.', 'success'); reload(); }
    };

    return html`
        <section class=${'rounded-3xl border p-5 flex flex-wrap items-center justify-between gap-4 '
            + (testMode ? 'bg-red-50 border-red-200' : 'bg-white border-gray-100')}>
            <div class="max-w-2xl">
                <p class="font-bold text-gray-900">${testMode ? '🔴 Test mode is ON — nothing counts' : 'Rehearse before the night'}</p>
                <p class="text-sm text-gray-600 mt-0.5">
                    ${testMode
                        ? 'Run every game from the host console with a few phones. Scores and answers are marked TEST; Reset clears them. Test mode switches itself off 15 minutes before the doors open.'
                        : 'Switch on test mode, run each game from the host console with a few phones, then press Reset.'}
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                ${hostUrl ? html`<a class="inline-flex items-center px-4 py-2.5 rounded-xl text-sm font-semibold text-hodBlue border border-gray-200 hover:border-hodBlue"
                    href=${hostUrl} target="_blank" rel="noopener">Open the host console ↗</a>` : null}
                ${can('event.edit') ? html`
                    <${Button} variant=${testMode ? 'secondary' : 'primary'} loading=${busy === 'toggle'} onClick=${toggle}>
                        ${testMode ? 'Turn test mode off' : 'Turn test mode on'}
                    </${Button}>
                    <${Button} variant="ghost" loading=${busy === 'reset'} onClick=${reset}>Reset rehearsal</${Button}>` : null}
            </div>
        </section>`;
}

export function GamesTab() {
    const event = current.value;
    const [overview, setOverview] = useState(null);
    const [section, setSection] = useState('lineup');

    const reload = async () => {
        const res = await act('games_overview', { id: event.id });
        if (res.ok) setOverview(res.data);
    };
    useEffect(() => { setOverview(null); reload(); }, [event.id]);

    if (!overview) return html`<${Spinner} label="Loading the games…" />`;

    const hostUrl = event.portal_url ? event.portal_url.replace(/\/$/, '') + '/host' : null;
    const counts = {
        lineup: overview.games.length,
        banks: overview.decks.length,
        feud: overview.feud.filter((f) => !f.approved).length,
    };

    return html`
        <div class="space-y-6">
            <div>
                <h2 class="text-xl font-display font-bold text-gray-900">Games</h2>
                <p class="text-sm text-gray-500">Set up the night's games here; run them live from the host console.</p>
            </div>

            <${Rehearsal} eventId=${event.id} testMode=${overview.test_mode} reload=${reload} hostUrl=${hostUrl} />

            <nav class="flex flex-wrap gap-1 border-b border-gray-200" aria-label="Games sections">
                ${SECTIONS.map(([key, label]) => html`
                    <button key=${key} type="button" onClick=${() => setSection(key)}
                        aria-current=${section === key ? 'page' : undefined}
                        class=${'px-4 py-2.5 -mb-px border-b-2 text-sm font-semibold transition-colors '
                            + (section === key ? 'border-hodBlue text-hodBlue' : 'border-transparent text-gray-500 hover:text-gray-900')}>
                        ${label}
                        ${counts[key] ? html`<span class="ml-1.5 px-1.5 py-0.5 rounded-full bg-gray-100 text-gray-600 text-xs">${counts[key]}</span>` : null}
                    </button>`)}
            </nav>

            ${section === 'lineup' ? html`<${LineupSection} eventId=${event.id} overview=${overview} reload=${reload} />` : null}
            ${section === 'banks' ? html`<${BanksSection} eventId=${event.id} overview=${overview} reload=${reload} />` : null}
            ${section === 'feud' ? html`<${FeudSection} eventId=${event.id} overview=${overview} reload=${reload} />` : null}
        </div>`;
}
