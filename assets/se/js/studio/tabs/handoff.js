// /assets/se/js/studio/tabs/handoff.js
//
// Studio → Hand-off (guide §17.3–§17.6): after the night, the guests who
// agreed to be contacted go to Reach (follow-up calls) or Embrace
// (first-timer care). Nothing leaves Envision until someone reviews the list
// and presses Push; a person can be sent to the other team or held back,
// but the rules (consent, opt-outs, members) cannot be overridden — the
// server refuses that too. Re-running is safe: nobody is handed off twice.

import { html } from '@se/core/html.js';
import { useEffect, useState } from 'preact/hooks';
import { studio } from '@se/core/api.js';
import { current, toast, can } from '../state.js';
import { Card, Button, Spinner } from '../ui.js';

const REASON = {
    ready: 'Ready',
    already_handed_off: 'Already handed off',
    skipped_opted_out: 'Opted out of contact',
    skipped_member: 'Already a church member',
    skipped_no_consent: 'Did not agree to follow-up',
    skipped_no_show: 'Did not come',
    skipped_invalid_phone: 'Phone number not usable',
    skipped_excluded: 'Held back',
    created: 'Created',
    linked_existing: 'Linked to an existing person',
};

const DESTINATION = {
    reach: 'Reach — follow-up calls',
    embrace: 'Embrace — first-timer care',
    none: 'Hold back',
};

function parseSummary(json) {
    try {
        return typeof json === 'string' ? JSON.parse(json) : (json || {});
    } catch (e) {
        return {};
    }
}

export function HandoffTab() {
    const event = current.value;
    const [preview, setPreview] = useState(null);
    const [history, setHistory] = useState([]);
    const [noShows, setNoShows] = useState(false);
    const [choices, setChoices] = useState({});
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);

    const load = () => {
        setError(null);
        return Promise.all([
            studio('handoff_preview', { id: event.id, include_no_shows: noShows }),
            studio('handoff_history', { id: event.id }),
        ]).then(([p, h]) => {
            setPreview(p);
            setHistory(h.runs || []);
            setChoices({});
        }).catch((e) => setError(e.message || 'The hand-off list could not be built.'));
    };
    useEffect(() => { load(); }, [event.id, noShows]);

    if (error && !preview) {
        return html`<${Card} title="Hand-off"><p class="text-sm text-gray-700">${error}</p>
            <div class="mt-4"><${Button} variant="secondary" onClick=${load}>Try again</${Button}></div></${Card}>`;
    }
    if (!preview) return html`<${Spinner} label="Building the hand-off list…" />`;

    const items = [...preview.items].sort((a, b) => (a.reason === 'ready' ? 0 : 1) - (b.reason === 'ready' ? 0 : 1));
    const ready = items.filter((i) => i.reason === 'ready');
    const destinationOf = (item) => choices[item.registration_id] ?? item.destination;
    const going = ready.filter((i) => destinationOf(i) !== 'none');
    const toReach = going.filter((i) => destinationOf(i) === 'reach').length;
    const toEmbrace = going.length - toReach;
    const held = {};
    for (const item of items) {
        if (item.reason !== 'ready') held[item.reason] = (held[item.reason] || 0) + 1;
    }

    const push = async () => {
        if (!going.length) return;
        if (!confirm(`Send ${going.length} ${going.length === 1 ? 'person' : 'people'} on: ${toReach} to Reach and ${toEmbrace} to Embrace?`)) return;
        setBusy(true);
        try {
            const overrides = Object.entries(choices).map(([id, destination]) => ({ registration_id: Number(id), destination }));
            const r = await studio('handoff_push', { id: event.id, options: { include_no_shows: noShows }, overrides });
            const n = (r.counts?.created || 0) + (r.counts?.linked_existing || 0);
            toast(`Hand-off done: ${n} ${n === 1 ? 'person' : 'people'} sent on.`, 'success');
            await load();
        } catch (e) {
            toast(e.message, 'error');
        } finally {
            setBusy(false);
        }
    };

    const archive = async () => {
        if (!confirm('Archive this event? Editing and live operations freeze; the records stay.')) return;
        setBusy(true);
        try {
            await studio('archive', { id: event.id });
            current.value = { ...event, status: 'archived', phase: 'archived' };
            toast('Event archived.', 'success');
        } catch (e) {
            toast(e.message, 'error');
        } finally {
            setBusy(false);
        }
    };

    return html`
        <div class="space-y-6">
            <div class="flex flex-wrap justify-between items-start gap-3">
                <div class="max-w-2xl">
                    <h2 class="text-xl font-display font-bold text-gray-900">Hand-off</h2>
                    <p class="text-sm text-gray-500">Guests who agreed to be contacted go to Reach (follow-up calls) or, if they asked
                        for a visit, Embrace (first-timer care). Review the list, then push. Pushing again never hands anyone off twice.</p>
                </div>
                ${can('event.archive') && event.status !== 'archived'
                    ? html`<${Button} variant="secondary" disabled=${busy} onClick=${archive}>Archive event</${Button}>` : null}
            </div>

            <div class="grid sm:grid-cols-3 gap-3">
                <div class="bg-white rounded-2xl border border-gray-100 p-4">
                    <p class="text-xs font-semibold text-gray-500">Ready to send</p>
                    <p class="text-2xl font-bold text-gray-900">${going.length}</p>
                    <p class="text-xs text-gray-500">${toReach} to Reach · ${toEmbrace} to Embrace</p>
                </div>
                <div class="bg-white rounded-2xl border border-gray-100 p-4 sm:col-span-2">
                    <p class="text-xs font-semibold text-gray-500">Not sent, by rule</p>
                    ${Object.keys(held).length ? html`
                        <ul class="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-sm text-gray-700">
                            ${Object.entries(held).map(([reason, n]) => html`<li key=${reason}><strong>${n}</strong> ${REASON[reason] || reason}</li>`)}
                        </ul>` : html`<p class="text-sm text-gray-500 mt-1">Everyone on the list is ready.</p>`}
                    <label class="mt-3 flex items-center gap-2 text-sm text-gray-700">
                        <input type="checkbox" checked=${noShows} onChange=${(e) => setNoShows(e.currentTarget.checked)} />
                        Also send people who registered but did not come
                    </label>
                </div>
            </div>

            <${Card} title="Review" subtitle="Change where a person goes, or hold them back. The rules above cannot be overridden.">
                <div class="overflow-x-auto max-h-[28rem]">
                    <table class="w-full text-sm">
                        <thead class="sticky top-0 bg-white">
                            <tr class="text-left text-gray-500">
                                <th class="py-2 pr-4 font-semibold">Guest</th>
                                <th class="py-2 pr-4 font-semibold">Goes to</th>
                                <th class="py-2 font-semibold">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${items.map((item) => html`
                                <tr key=${item.registration_id} class="border-t border-gray-100">
                                    <td class="py-2 pr-4 font-medium text-gray-900">${item.name}</td>
                                    <td class="py-2 pr-4">
                                        ${item.reason === 'ready' ? html`
                                            <select class="px-3 py-1.5 rounded-lg border border-gray-200 bg-white text-sm" aria-label=${'Where ' + item.name + ' goes'}
                                                onChange=${(e) => setChoices({ ...choices, [item.registration_id]: e.currentTarget.value })}>
                                                ${['reach', 'embrace', 'none'].map((d) => html`<option key=${d} value=${d} selected=${destinationOf(item) === d}>${DESTINATION[d]}</option>`)}
                                            </select>` : html`<span class="text-gray-400">—</span>`}
                                    </td>
                                    <td class=${'py-2 ' + (item.reason === 'ready' ? 'text-emerald-700 font-semibold' : 'text-gray-500')}>
                                        ${item.reason === 'ready' && destinationOf(item) === 'none' ? 'Held back' : (REASON[item.reason] || item.reason)}
                                    </td>
                                </tr>`)}
                        </tbody>
                    </table>
                </div>
                <div class="mt-4">
                    <${Button} disabled=${busy || !going.length} loading=${busy} onClick=${push}>
                        ${going.length ? `Push ${going.length} ${going.length === 1 ? 'person' : 'people'}` : 'Nobody to push'}
                    </${Button}>
                </div>
            </${Card}>

            <${Card} title="Earlier runs">
                ${history.length ? html`
                    <ul class="divide-y divide-gray-100">
                        ${history.map((run) => {
                            const counts = parseSummary(run.summary_json).counts || {};
                            const parts = Object.entries(counts).map(([k, n]) => n + ' ' + (REASON[k] || k).toLowerCase());
                            return html`<li key=${run.id} class="py-2 text-sm">
                                <span class="font-semibold text-gray-900">${new Date(String(run.created_at).replace(' ', 'T')).toLocaleString()}</span>
                                <span class="text-gray-500"> · ${parts.length ? parts.join(' · ') : 'nothing to do'}</span>
                            </li>`;
                        })}
                    </ul>` : html`<p class="text-sm text-gray-500">No hand-offs yet.</p>`}
            </${Card}>
        </div>`;
}
