// /assets/se/js/studio/tabs/overview.js
//
// Overview (guide §13.13): the readiness ring with the Appendix H.1
// checklist, key dates, quick links, KPIs and recent audit activity.

import { html } from '@se/core/html.js';
import { useState, useEffect } from 'preact/hooks';
import { studio } from '@se/core/api.js';
import { formatDateTime } from '@se/core/boot.js';
import { current, can, toast, applyEvent, openEvent } from '../state.js';
import { Card, Button, StatusPill, Spinner } from '../ui.js';
import { ShareKit } from '../share_kit.js';
import { Copywriter } from '../copywriter.js';

function ReadinessRing({ pct }) {
    const r = 52;
    const c = 2 * Math.PI * r;
    const offset = c * (1 - Math.max(0, Math.min(100, pct)) / 100);
    const tone = pct >= 100 ? 'text-emerald-500' : (pct >= 60 ? 'text-hodBlue' : 'text-amber-500');

    return html`
        <div class="relative w-32 h-32 shrink-0" role="img" aria-label=${`Readiness ${pct} per cent`}>
            <svg class="w-32 h-32 -rotate-90" viewBox="0 0 120 120" aria-hidden="true">
                <circle cx="60" cy="60" r=${r} fill="none" stroke="currentColor" stroke-width="10" class="text-gray-100" />
                <circle cx="60" cy="60" r=${r} fill="none" stroke="currentColor" stroke-width="10"
                        stroke-linecap="round" stroke-dasharray=${c} stroke-dashoffset=${offset}
                        class=${tone + ' transition-all duration-700'} />
            </svg>
            <div class="absolute inset-0 flex flex-col items-center justify-center">
                <span class="text-2xl font-display font-bold text-gray-900">${pct}%</span>
                <span class="text-[10px] uppercase tracking-wider text-gray-400 font-bold">Ready</span>
            </div>
        </div>`;
}

function ChecklistRow({ item }) {
    const icon = item.ok
        ? html`<svg class="w-5 h-5 text-emerald-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>`
        : (item.blocking
            ? html`<svg class="w-5 h-5 text-hodRed shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>`
            : html`<svg class="w-5 h-5 text-amber-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>`);

    return html`
        <li class="flex items-start gap-3 py-2.5 border-b border-gray-50 last:border-0">
            ${icon}
            <div class="min-w-0">
                <p class=${'text-sm font-medium ' + (item.ok ? 'text-gray-500 line-through decoration-gray-300' : 'text-gray-900')}>
                    ${item.label}
                    ${!item.blocking ? html`<span class="ml-2 text-[10px] uppercase tracking-wide font-bold text-amber-500">optional</span>` : null}
                </p>
                ${!item.ok && item.hint ? html`<p class="text-xs text-gray-500 mt-0.5">${item.hint}</p>` : null}
            </div>
        </li>`;
}

function Kpi({ label, value, tone = 'text-gray-900' }) {
    return html`
        <div class="bg-gray-50 rounded-2xl px-4 py-3">
            <p class="text-[10px] uppercase tracking-wider text-gray-400 font-bold">${label}</p>
            <p class=${'text-2xl font-display font-bold ' + tone}>${value}</p>
        </div>`;
}

function CopyLink({ label, url }) {
    const [copied, setCopied] = useState(false);

    const copy = async () => {
        try {
            await navigator.clipboard.writeText(url);
            setCopied(true);
            setTimeout(() => setCopied(false), 1800);
        } catch (e) {
            toast('Could not copy. Select the link and copy it by hand.', 'error');
        }
    };

    return html`
        <div class="flex items-center gap-2 py-2 border-b border-gray-50 last:border-0">
            <div class="min-w-0 flex-1">
                <p class="text-xs font-bold text-gray-500 uppercase tracking-wide">${label}</p>
                <a href=${url} target="_blank" rel="noopener"
                   class="text-sm text-hodBlue hover:underline break-all">${url}</a>
            </div>
            <button type="button" onClick=${copy}
                class="px-3 py-1.5 rounded-lg text-xs font-bold text-gray-600 hover:text-hodBlue hover:bg-blue-50 transition-colors shrink-0">
                ${copied ? 'Copied' : 'Copy'}
            </button>
        </div>`;
}

export function OverviewTab() {
    const event = current.value;
    const [audit, setAudit] = useState(null);
    const [busy, setBusy] = useState('');

    useEffect(() => {
        if (!event || !can('audit.view')) return;
        studio('audit_list', { id: event.id, per_page: 8 })
            .then((d) => setAudit(d.items || []))
            .catch(() => setAudit([]));
    }, [event?.id]);

    if (!event) return html`<${Spinner} />`;

    const checklist = event.checklist || { items: [], blockers: [], ready_pct: 0, can_publish: false };
    const counts = event.counts || {};

    const act = async (action, payload, confirmText) => {
        if (confirmText && !confirm(confirmText)) return;
        setBusy(action);
        try {
            const data = await studio(action, { id: event.id, ...payload });
            if (data.deleted) {
                toast('Draft deleted.', 'success');
                window.location.href = '/modules/special_events/index.php';
                return;
            }
            applyEvent(data.event);
            toast(data.message || 'Done.', 'success');
        } catch (e) {
            if (e.code === 'VALIDATION' && e.data?.blockers?.length) {
                toast(e.message + ' ' + e.data.blockers.map((b) => b.label).join('; '), 'error', 8000);
            } else {
                toast(e.message, 'error');
            }
        } finally {
            setBusy('');
        }
    };

    return html`
        <div class="space-y-6">

            <${Card}>
                <div class="flex flex-col sm:flex-row gap-6 items-start">
                    <${ReadinessRing} pct=${checklist.ready_pct} />
                    <div class="flex-1 min-w-0">
                        <div class="flex flex-wrap items-center gap-3">
                            <h2 class="text-xl font-display font-bold text-gray-900">${event.title} ${event.edition_label || ''}</h2>
                            <${StatusPill} status=${event.status} />
                            <span class="text-xs font-bold uppercase tracking-wide text-gray-400">${event.phase}</span>
                        </div>
                        ${event.tagline ? html`<p class="text-sm text-gray-500 mt-1">${event.tagline}</p>` : null}

                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mt-5">
                            <${Kpi} label="Registered" value=${counts.confirmed ?? 0} />
                            <${Kpi} label="Waitlist" value=${counts.waitlisted ?? 0} />
                            <${Kpi} label="Checked in" value=${counts.checked_in ?? 0} tone="text-emerald-600" />
                            <${Kpi} label="Capacity" value=${event.online_capacity ?? '∞'}
                                tone=${event.reg_state === 'full' ? 'text-hodRed' : 'text-gray-900'} />
                        </div>

                        <div class="flex flex-wrap gap-2 mt-5">
                            ${event.status === 'draft' && can('event.publish') ? html`
                                <${Button} onClick=${() => act('publish', {})}
                                    loading=${busy === 'publish'}
                                    disabled=${!checklist.can_publish}
                                    title=${checklist.can_publish ? 'Make this event public' : 'Finish the blocking items first'}>
                                    Publish
                                <//>` : null}

                            ${event.status === 'published' && can('event.publish') ? html`
                                <${Button} variant="secondary" loading=${busy === 'unpublish'}
                                    onClick=${() => act('unpublish', {}, 'Take this event back to draft? It disappears from /e/ immediately.')}>
                                    Back to draft
                                <//>` : null}

                            ${['draft', 'published'].includes(event.status) && can('event.publish') ? html`
                                <${Button} variant="danger" loading=${busy === 'cancel'}
                                    onClick=${() => {
                                        const reason = prompt('Why is it cancelled? Guests will see this on the page.');
                                        if (reason) act('cancel', { reason });
                                    }}>
                                    Cancel event
                                <//>` : null}

                            <a href=${event.portal_url} target="_blank" rel="noopener"
                               class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl font-semibold bg-white text-hodBlue border border-gray-200 hover:border-hodBlue transition-all min-h-[44px]">
                                View portal
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                            </a>
                        </div>
                    </div>
                </div>
            <//>

            <div class="grid lg:grid-cols-2 gap-6">

                <${Card} title="Before it goes live"
                         subtitle=${checklist.can_publish
                             ? 'Everything required is done.'
                             : `${checklist.blockers.length} thing${checklist.blockers.length === 1 ? '' : 's'} still to do.`}>
                    <ul>${checklist.items.map((i) => html`<${ChecklistRow} key=${i.key} item=${i} />`)}</ul>
                <//>

                <div class="space-y-6">
                    <${Card} title="Key dates">
                        ${(event.days || []).length === 0
                            ? html`<p class="text-sm text-gray-500">No days yet — add them on the Details tab.</p>`
                            : html`<ul class="space-y-3">
                                ${event.days.map((d) => html`
                                <li key=${d.id} class="flex items-start gap-3">
                                    <div class="w-11 h-11 rounded-xl bg-blue-50 text-hodBlue flex flex-col items-center justify-center shrink-0">
                                        <span class="text-[9px] font-bold uppercase leading-none">${new Date(d.starts_at).toLocaleDateString('en-NG', { month: 'short' })}</span>
                                        <span class="text-base font-display font-bold leading-none">${new Date(d.starts_at).getDate()}</span>
                                    </div>
                                    <div class="text-sm">
                                        <p class="font-semibold text-gray-900">${d.label || formatDateTime(d.starts_at, { dateStyle: 'full', timeStyle: undefined })}</p>
                                        <p class="text-gray-500 text-xs">
                                            Doors ${formatDateTime(d.doors_open_at, { dateStyle: undefined, timeStyle: 'short' })}
                                            · Starts ${formatDateTime(d.starts_at, { dateStyle: undefined, timeStyle: 'short' })}
                                            · Ends ${formatDateTime(d.ends_at, { dateStyle: undefined, timeStyle: 'short' })}
                                        </p>
                                    </div>
                                </li>`)}
                            </ul>`}
                    <//>

                    <${Card} title="Quick links">
                        <${CopyLink} label="Portal" url=${event.portal_url} />
                        ${event.preview_url ? html`<${CopyLink} label="Draft preview (share with crew)" url=${event.preview_url} />` : null}
                        <${CopyLink} label="Check-in (poster QR target)" url=${event.portal_url + '/in'} />
                        <${CopyLink} label="Privacy notice" url=${event.portal_url + '/privacy'} />
                        <${CopyLink} label="Host console" url=${event.portal_url + '/host'} />
                        <${CopyLink} label="Desk" url=${event.portal_url + '/desk'} />
                        <p class="text-xs text-gray-400 pt-2">
                            The stage and lobby screens need a link with a key in it. They are on the
                            Live tab, next to the button that rotates them.
                        </p>
                    <//>
                </div>
            </div>

            <div class="grid lg:grid-cols-2 gap-6">
                <${ShareKit} />

                ${can('event.edit') ? html`
                <${Card} title="Need words?"
                         subtitle="A starting point for a tagline, a description or a text message — never applied on its own.">
                    <${Copywriter} />
                <//>` : null}
            </div>

            ${can('audit.view') ? html`
            <${Card} title="Recent activity">
                ${audit === null ? html`<${Spinner} label="Loading activity…" />`
                    : (audit.length === 0
                        ? html`<p class="text-sm text-gray-500">Nothing yet.</p>`
                        : html`<ul class="divide-y divide-gray-50">
                            ${audit.map((a) => html`
                            <li key=${a.id} class="py-2.5 flex items-center justify-between gap-3 text-sm">
                                <span class="font-mono text-xs text-gray-600">${a.action}</span>
                                <span class="text-gray-500 text-xs text-right shrink-0">
                                    ${a.actor} · ${formatDateTime(a.created_at)}
                                </span>
                            </li>`)}
                        </ul>`)}
            <//>` : null}
        </div>`;
}
