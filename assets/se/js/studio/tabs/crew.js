// /assets/se/js/studio/tabs/crew.js
//
// Crew (guide §6.2, §13.13): who can do what for this event. Adding someone
// notifies them; the last Producer cannot be removed.
//
// The stage and lobby screens are not people, so their key-bearing links
// live on the Live tab with the rotate button rather than here.

import { html } from '@se/core/html.js';
import { useState, useEffect, useRef } from 'preact/hooks';
import { studio } from '@se/core/api.js';
import { formatDateTime } from '@se/core/boot.js';
import { boot, current, toast, can } from '../state.js';
import { Card, Button, Spinner, EmptyState } from '../ui.js';

const ROLE_HINTS = {
    producer: 'Everything for this event.',
    host: 'Drives the show: scenes, programme, announcements, games.',
    game_master: 'Launches rounds, judges answers, fixes scores.',
    desk: 'Checks people in at the door and moves people between teams.',
    karaoke_dj: 'Runs the karaoke queue.',
    media: 'Uploads assets and renders the formats.',
    followup: 'Sees attendee details, exports, runs the hand-off.',
    viewer: 'Read-only: the numbers, nothing else.',
};

function PersonSearch({ eventId, onAdded }) {
    const [query, setQuery] = useState('');
    const [results, setResults] = useState([]);
    const [role, setRole] = useState('host');
    const [busy, setBusy] = useState(false);
    const timer = useRef(null);

    const roles = boot.value?.crew_roles || {};

    useEffect(() => {
        clearTimeout(timer.current);
        if (query.trim().length < 2) { setResults([]); return; }

        timer.current = setTimeout(async () => {
            try {
                const data = await studio('user_search', { id: eventId, q: query });
                setResults(data.users || []);
            } catch (e) {
                setResults([]);
            }
        }, 300);

        return () => clearTimeout(timer.current);
    }, [query, eventId]);

    const add = async (user) => {
        setBusy(true);
        try {
            const data = await studio('crew_add', { id: eventId, user_id: user.id, role });
            toast(data.message || 'Added.', 'success');
            setQuery('');
            setResults([]);
            onAdded();
        } catch (e) {
            toast(e.message, 'error');
        } finally {
            setBusy(false);
        }
    };

    return html`
        <div class="space-y-4">
            <div class="grid sm:grid-cols-3 gap-3">
                <div class="sm:col-span-2">
                    <label class="block text-sm font-semibold text-gray-700 mb-1.5" for="se-crew-q">Find someone</label>
                    <input id="se-crew-q" type="search" value=${query} placeholder="Name or email…"
                        onInput=${(e) => setQuery(e.currentTarget.value)}
                        class="w-full px-4 py-3 rounded-xl border border-gray-200 outline-none focus:border-hodBlue bg-white" />
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1.5" for="se-crew-role">As</label>
                    <select id="se-crew-role" value=${role} onChange=${(e) => setRole(e.currentTarget.value)}
                        class="w-full px-4 py-3 rounded-xl border border-gray-200 outline-none focus:border-hodBlue bg-white font-medium">
                        ${Object.entries(roles).map(([k, label]) => html`
                            <option key=${k} value=${k} selected=${role === k}>${label}</option>`)}
                    </select>
                </div>
            </div>

            <p class="text-xs text-gray-500">${ROLE_HINTS[role] || ''}</p>

            ${results.length > 0 ? html`
            <ul class="border border-gray-200 rounded-2xl divide-y divide-gray-100 overflow-hidden">
                ${results.map((u) => html`
                <li key=${u.id} class="flex items-center justify-between gap-3 px-4 py-3 hover:bg-gray-50">
                    <div class="min-w-0">
                        <p class="text-sm font-semibold text-gray-900 truncate">${u.name}</p>
                        ${u.email ? html`<p class="text-xs text-gray-500 truncate">${u.email}</p>` : null}
                    </div>
                    <${Button} variant="secondary" disabled=${busy} onClick=${() => add(u)}>Add<//>
                </li>`)}
            </ul>` : null}

            ${query.trim().length >= 2 && results.length === 0 ? html`
                <p class="text-sm text-gray-500">Nobody matches “${query}”.</p>` : null}
        </div>`;
}

export function CrewTab() {
    const event = current.value;
    const [crew, setCrew] = useState(null);
    const [busy, setBusy] = useState(0);

    const load = async () => {
        try {
            const data = await studio('crew_list', { id: event.id });
            setCrew(data.crew || []);
        } catch (e) {
            toast(e.message, 'error');
            setCrew([]);
        }
    };

    useEffect(() => { if (event) load(); }, [event?.id]);

    if (!event || crew === null) return html`<${Spinner} />`;

    const canManage = can('event.crew');
    const roles = boot.value?.crew_roles || {};

    const revoke = async (member) => {
        if (!confirm(`Remove ${member.name} as ${member.role_label}?`)) return;
        setBusy(member.id);
        try {
            await studio('crew_revoke', { id: member.id });
            toast('Crew member removed.', 'success');
            load();
        } catch (e) {
            toast(e.message, 'error');
        } finally {
            setBusy(0);
        }
    };

    const active = crew.filter((c) => !c.revoked);
    const past = crew.filter((c) => c.revoked);

    return html`
        <div class="space-y-6">

            ${canManage ? html`
            <${Card} title="Add someone"
                     subtitle="They get a notification with a link to this event.">
                <${PersonSearch} eventId=${event.id} onAdded=${load} />
            <//>` : null}

            <${Card} title="Crew" subtitle=${`${active.length} active.`}>
                ${active.length === 0
                    ? html`<${EmptyState} title="No crew yet"
                              message="An event needs at least one Producer before it can be published." />`
                    : html`<ul class="divide-y divide-gray-100">
                        ${active.map((m) => html`
                        <li key=${m.id} class="flex items-center justify-between gap-3 py-3">
                            <div class="flex items-center gap-3 min-w-0">
                                <div class="w-10 h-10 rounded-xl bg-blue-50 text-hodBlue font-bold flex items-center justify-center shrink-0 overflow-hidden">
                                    ${m.picture_path
                                        ? html`<img src=${m.picture_path} alt="" class="w-full h-full object-cover" />`
                                        : (m.name || '?').charAt(0).toUpperCase()}
                                </div>
                                <div class="min-w-0">
                                    <p class="text-sm font-semibold text-gray-900 truncate">${m.name}</p>
                                    <p class="text-xs text-gray-500">
                                        ${m.role_label}
                                        ${m.added_at ? ` · since ${formatDateTime(m.added_at, { dateStyle: 'medium', timeStyle: undefined })}` : ''}
                                    </p>
                                </div>
                            </div>
                            ${canManage ? html`
                                <button type="button" onClick=${() => revoke(m)} disabled=${busy === m.id}
                                    class="text-xs font-bold text-hodRed hover:underline shrink-0 disabled:opacity-50">
                                    Remove
                                </button>` : null}
                        </li>`)}
                    </ul>`}
            <//>

            ${past.length > 0 ? html`
            <${Card} title="No longer on the crew">
                <ul class="divide-y divide-gray-100">
                    ${past.map((m) => html`
                    <li key=${m.id} class="py-2.5 text-sm text-gray-500 flex justify-between gap-3">
                        <span>${m.name}</span>
                        <span class="text-xs">${m.role_label}</span>
                    </li>`)}
                </ul>
            <//>` : null}

            <${Card} title="Screens"
                     subtitle="The stage and lobby displays sign in with a link, not a password.">
                <p class="text-sm text-gray-500">
                    Their links carry a key, so they live on the <strong>Live</strong> tab —
                    together with the button that rotates them if a link gets shared too widely.
                </p>
            <//>

            <${Card} title="What each role can do">
                <ul class="space-y-2">
                    ${Object.entries(roles).map(([key, label]) => html`
                    <li key=${key} class="text-sm flex gap-3">
                        <span class="font-bold text-gray-900 w-32 shrink-0">${label}</span>
                        <span class="text-gray-500">${ROLE_HINTS[key] || ''}</span>
                    </li>`)}
                </ul>
            <//>
        </div>`;
}
