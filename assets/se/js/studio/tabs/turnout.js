// /assets/se/js/studio/tabs/turnout.js
//
// Studio → Turnout: four cards — who registered, who checked in, who
// registered on the day, and who registered but never came. Each card opens
// a modal listing the people behind the number, with search, tap-to-call
// and a CSV download.
//
// Members and guests: the server checks every phone against the ERP users
// table. A member is listed under their exact system name with a Member pill
// (and what they typed underneath when it differs); a guest keeps the name
// they typed and gets a Guest pill.
//
// The server decides what a phone number looks like: full for a caller with
// `attendee.pii`, masked otherwise — so tap-to-call only appears when the
// full number is there (`phone_e164`). Rehearsal records are never listed.

import { html } from '@se/core/html.js';
import { useEffect, useMemo, useRef, useState } from 'preact/hooks';
import { studio } from '@se/core/api.js';
import { formatDateTime } from '@se/core/boot.js';
import { current } from '../state.js';
import { Card, Button, Spinner } from '../ui.js';

const nf = new Intl.NumberFormat();

const CARDS = [
    {
        key: 'registered',
        title: 'Registered',
        note: 'Everyone still signed up — confirmed or on the waitlist.',
        tone: 'text-hodBlue',
        bar: 'bg-hodBlue',
    },
    {
        key: 'checked_in',
        title: 'Checked in',
        note: 'Everyone who came through the door, on any day.',
        tone: 'text-emerald-700',
        bar: 'bg-emerald-600',
    },
    {
        key: 'day_of',
        title: 'Came and registered on the day',
        note: 'Checked in, and signed up at the door or on/after the first event day.',
        tone: 'text-amber-700',
        bar: 'bg-amber-500',
    },
    {
        key: 'no_show',
        title: 'Registered, did not come',
        note: 'Signed up but never checked in.',
        tone: 'text-hodRed',
        bar: 'bg-hodRed',
    },
];

const CHANNEL_LABEL = {
    portal: 'Online',
    walkin_self: 'Walk-in (self)',
    walkin_desk: 'Walk-in (desk)',
    studio: 'Added by crew',
};

function pct(part, whole) {
    return whole ? Math.round((100 * part) / whole) : 0;
}

/** A CSV cell, quoted, with spreadsheet formula injection neutralised. */
function csvCell(value) {
    let s = value === null || value === undefined ? '' : String(value);
    if (/^[=+\-@\t\r]/.test(s)) s = "'" + s;
    return '"' + s.replace(/"/g, '""') + '"';
}

function downloadCsv(filename, people) {
    const header = ['Full name', 'Member or guest', 'Name as typed', 'Phone', 'Registered via', 'Registered at', 'Checked in at'];
    const lines = [header.map(csvCell).join(',')];
    for (const p of people) {
        lines.push([
            p.name,
            p.is_member ? 'Member' : 'Guest',
            p.typed_name || '',
            p.phone || '',
            CHANNEL_LABEL[p.channel] || p.channel,
            formatDateTime(p.registered_at),
            formatDateTime(p.checked_in_at),
        ].map(csvCell).join(','));
    }
    // A BOM so Excel reads the names as UTF-8.
    const blob = new Blob(['﻿' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
}

function KindPill({ member, ambiguous }) {
    return member
        ? html`<span class="inline-block px-2 py-0.5 rounded-full text-[11px] font-bold bg-emerald-50 text-emerald-700"
                   title=${ambiguous ? 'This number belongs to more than one member record' : 'This number is in the church directory'}>Member</span>`
        : html`<span class="inline-block px-2 py-0.5 rounded-full text-[11px] font-bold bg-gray-100 text-gray-600"
                   title="This number is not in the church directory">Guest</span>`;
}

function StatCard({ card, count, total, members, onOpen }) {
    const share = card.key === 'registered' ? null : pct(count, total);
    const guests = Math.max(0, count - members);

    return html`
        <button type="button" onClick=${onOpen}
                class="group text-left bg-white rounded-2xl border border-gray-100 p-5 hover:border-gray-300 focus:outline-none focus-visible:ring-2 focus-visible:ring-hodBlue transition-colors">
            <p class="text-sm font-semibold text-gray-600">${card.title}</p>
            <p class=${'text-4xl font-display font-bold tabular-nums mt-1 ' + card.tone}>${nf.format(count)}</p>
            <p class="flex flex-wrap gap-1.5 mt-2">
                <span class="px-2 py-0.5 rounded-full text-[11px] font-bold bg-emerald-50 text-emerald-700 tabular-nums">${nf.format(members)} ${members === 1 ? 'member' : 'members'}</span>
                <span class="px-2 py-0.5 rounded-full text-[11px] font-bold bg-gray-100 text-gray-600 tabular-nums">${nf.format(guests)} ${guests === 1 ? 'guest' : 'guests'}</span>
            </p>
            <p class="text-xs text-gray-500 mt-1 min-h-[2rem]">${card.note}</p>
            ${share !== null ? html`
                <div class="mt-3">
                    <span class="block h-1.5 rounded-full bg-gray-100 overflow-hidden" aria-hidden="true">
                        <span class=${'block h-full rounded-full ' + card.bar} style=${{ width: Math.min(100, share) + '%' }}></span>
                    </span>
                    <p class="text-[11px] text-gray-500 mt-1">${share}% of registered</p>
                </div>` : html`<div class="mt-3 h-[1.375rem]"></div>`}
            <p class="text-xs font-semibold text-hodBlue mt-3 group-hover:underline">See the list →</p>
        </button>`;
}

function PeopleModal({ card, people, pii, canExport, eventSlug, onClose }) {
    const [q, setQ] = useState('');
    const searchRef = useRef(null);
    const returnFocus = useRef(document.activeElement);

    useEffect(() => {
        searchRef.current?.focus();
        const onKey = (e) => { if (e.key === 'Escape') onClose(); };
        document.addEventListener('keydown', onKey);
        return () => {
            document.removeEventListener('keydown', onKey);
            returnFocus.current?.focus?.();
        };
    }, []);

    const filtered = useMemo(() => {
        const needle = q.trim().toLowerCase();
        if (!needle) return people;
        const digits = needle.replace(/\D/g, '');
        return people.filter((p) => {
            if (p.name.toLowerCase().includes(needle)) return true;
            if (p.typed_name && p.typed_name.toLowerCase().includes(needle)) return true;
            if (!digits) return false;
            const phone = String(p.phone_e164 || p.phone || '').replace(/\D/g, '');
            // Match 0803… against +234803… as well.
            const local = phone.startsWith('234') ? '0' + phone.slice(3) : phone;
            return phone.includes(digits) || local.includes(digits);
        });
    }, [q, people]);

    const download = () => {
        const stamp = new Date().toISOString().slice(0, 10);
        downloadCsv(`${eventSlug || 'event'}-${card.key.replace('_', '-')}-${stamp}.csv`, filtered);
    };

    return html`
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4" data-app-modal data-modal-ignore>
            <button type="button" aria-label="Close" tabindex="-1" class="absolute inset-0 bg-gray-900/40" onClick=${onClose}></button>
            <div role="dialog" aria-modal="true" aria-labelledby="se-turnout-title"
                 class="relative bg-white rounded-3xl shadow-2xl w-full max-w-3xl max-h-[90vh] flex flex-col">
                <header class="flex items-start justify-between gap-3 px-6 pt-6 pb-4 border-b border-gray-100">
                    <div>
                        <h3 id="se-turnout-title" class="font-display font-bold text-gray-900">
                            ${card.title} · ${nf.format(people.length)}
                        </h3>
                        <p class="text-sm text-gray-500 mt-1">${card.note}</p>
                        <p class="text-xs text-gray-500 mt-1">Members are listed by their name in the church directory; guests by the name they typed.</p>
                        ${!pii ? html`<p class="text-xs text-amber-700 mt-1">Phone numbers are partly hidden for your crew role.</p>` : null}
                    </div>
                    <button type="button" onClick=${onClose} aria-label="Close"
                            class="text-gray-400 hover:text-gray-900 p-2 shrink-0">✕</button>
                </header>

                <div class="px-6 pt-4 flex flex-wrap items-end gap-3">
                    <div class="flex-1 min-w-[12rem]">
                        <label for="se-turnout-q" class="block text-xs font-semibold text-gray-500 mb-1">Search name or number</label>
                        <input id="se-turnout-q" ref=${searchRef} type="search" value=${q}
                               onInput=${(e) => setQ(e.currentTarget.value)}
                               class="w-full rounded-xl border border-gray-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-hodBlue" />
                    </div>
                    ${canExport ? html`
                        <${Button} variant="secondary" onClick=${download} disabled=${!filtered.length}>
                            Download CSV${q.trim() ? ` (${nf.format(filtered.length)})` : ''}
                        </${Button}>` : null}
                </div>

                <div class="flex-1 overflow-y-auto px-6 py-4">
                    ${filtered.length ? html`
                        <table class="w-full text-sm">
                            <thead class="sticky top-0 bg-white">
                                <tr class="text-left text-gray-500">
                                    <th class="py-2 pr-3 font-semibold">Full name</th>
                                    <th class="py-2 pr-3 font-semibold">Phone</th>
                                    <th class="py-2 pr-3 font-semibold hidden sm:table-cell">Registered</th>
                                    <th class="py-2 font-semibold hidden sm:table-cell">Checked in</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${filtered.map((p) => html`
                                    <tr key=${p.id} class="border-t border-gray-100 align-top">
                                        <td class="py-2 pr-3">
                                            <span class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                                <span class="font-semibold text-gray-900">${p.name || '—'}</span>
                                                <${KindPill} member=${p.is_member} ambiguous=${p.member_ambiguous} />
                                            </span>
                                            ${p.typed_name ? html`<span class="block text-[11px] text-gray-500">Typed: ${p.typed_name}</span>` : null}
                                            <span class="block text-[11px] text-gray-500">
                                                ${CHANNEL_LABEL[p.channel] || p.channel}${p.status === 'waitlisted' ? ' · waitlist' : ''}
                                            </span>
                                            <span class="block sm:hidden text-[11px] text-gray-500">
                                                Reg. ${formatDateTime(p.registered_at)}${p.checked_in_at ? ' · In ' + formatDateTime(p.checked_in_at) : ''}
                                            </span>
                                        </td>
                                        <td class="py-2 pr-3 tabular-nums whitespace-nowrap">
                                            ${p.phone_e164
                                                ? html`<a href=${'tel:' + p.phone_e164} class="text-hodBlue font-semibold hover:underline">${p.phone}</a>`
                                                : (p.phone || '—')}
                                        </td>
                                        <td class="py-2 pr-3 text-gray-600 whitespace-nowrap hidden sm:table-cell">${formatDateTime(p.registered_at)}</td>
                                        <td class="py-2 text-gray-600 whitespace-nowrap hidden sm:table-cell">${p.checked_in_at ? formatDateTime(p.checked_in_at) : '—'}</td>
                                    </tr>`)}
                            </tbody>
                        </table>` : html`
                        <p class="text-sm text-gray-500 py-6 text-center">
                            ${people.length ? 'Nobody matches that search.' : 'Nobody in this list yet.'}
                        </p>`}
                </div>
            </div>
        </div>`;
}

export function TurnoutTab() {
    const event = current.value;
    const [data, setData] = useState(null);
    const [error, setError] = useState(null);
    const [open, setOpen] = useState(null);
    const [refreshing, setRefreshing] = useState(false);

    const load = () => {
        setError(null);
        setRefreshing(true);
        studio('turnout_get', { id: event.id })
            .then(setData)
            .catch((e) => setError(e.message || 'Turnout could not be loaded.'))
            .finally(() => setRefreshing(false));
    };
    useEffect(() => { setData(null); setOpen(null); load(); }, [event.id]);

    if (error && !data) {
        return html`
            <${Card} title="Turnout">
                <p class="text-sm text-gray-700">${error}</p>
                <div class="mt-4"><${Button} variant="secondary" onClick=${load}>Try again</${Button}></div>
            </${Card}>`;
    }
    if (!data) return html`<${Spinner} label="Counting people…" />`;

    const counts = data.counts || {};
    const openCard = CARDS.find((c) => c.key === open);

    return html`
        <div class="space-y-6">
            <div class="flex flex-wrap justify-between items-start gap-3">
                <div>
                    <h2 class="text-xl font-display font-bold text-gray-900">Turnout</h2>
                    <p class="text-sm text-gray-500">
                        Tap a card to see the names and numbers behind it. Rehearsal (test) records are left out.
                    </p>
                </div>
                <${Button} variant="secondary" loading=${refreshing} onClick=${load}>Refresh</${Button}>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
                ${CARDS.map((card) => html`
                    <${StatCard} key=${card.key} card=${card} count=${counts[card.key] || 0}
                        total=${counts.registered || 0}
                        members=${(data.lists?.[card.key] || []).filter((p) => p.is_member).length}
                        onOpen=${() => setOpen(card.key)} />`)}
            </div>

            ${openCard ? html`
                <${PeopleModal} card=${openCard} people=${data.lists?.[openCard.key] || []}
                    pii=${!!data.pii} canExport=${!!data.can_export} eventSlug=${event.slug}
                    onClose=${() => setOpen(null)} />` : null}
        </div>`;
}
