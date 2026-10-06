// /assets/se/js/studio/tabs/attendees.js
//
// Studio → Attendees (guide §12.5, §13.13).
//
// The crew's working list: search, filter, correct, cancel, restore,
// promote, delete, add by hand, reset links, erase, and export.
//
// Two destructive actions and no more (§12.5): Delete, which carries both the
// soft grade and the permanent one behind a single button, and Erase, which is
// the privacy request. Everything else on the row is reversible.
//
// Phone numbers appear in full only when the server masks nothing: the list
// payload masks them unless the caller holds `attendee.pii` (§19.1), so a
// Media or DJ crew member sees "0803 *** 4567" and nothing more.

import { html } from '@se/core/html.js';
import { useState, useEffect, useCallback } from 'preact/hooks';
import { studio } from '@se/core/api.js';
import { formatDateTime } from '@se/core/boot.js';
import { current, can, toast } from '../state.js';
import { Card, Button, Spinner, EmptyState, Field, TextInput, Select } from '../ui.js';

const STATUS_FILTERS = [
    { value: 'live', label: 'On the list' },
    { value: 'confirmed', label: 'Confirmed' },
    { value: 'waitlisted', label: 'Waitlist' },
    { value: 'cancelled', label: 'Cancelled' },
    { value: 'removed', label: 'Removed' },
    // Deleted registrations keep the status they left with, so they need
    // their own filter — the server matches on `deleted_at`, not on status.
    { value: 'deleted', label: 'Deleted' },
    { value: 'all', label: 'Everyone' },
];

const STATUS_STYLES = {
    confirmed: 'bg-emerald-50 text-emerald-700',
    waitlisted: 'bg-amber-50 text-amber-700',
    cancelled: 'bg-gray-100 text-gray-600',
    removed: 'bg-red-50 text-hodRed',
    deleted: 'bg-gray-100 text-gray-400',
};

function StatusChip({ status, deleted }) {
    const label = deleted ? 'deleted' : status;
    const style = deleted ? STATUS_STYLES.deleted : (STATUS_STYLES[status] || STATUS_STYLES.cancelled);

    return html`
        <span class=${'px-2 py-0.5 rounded-full text-[11px] font-bold ' + style}>
            ${label}
        </span>`;
}

function Kpi({ label, value, tone = 'text-gray-900' }) {
    return html`
        <div class="bg-gray-50 rounded-2xl px-4 py-3">
            <p class="text-[10px] uppercase tracking-wider text-gray-400 font-bold">${label}</p>
            <p class=${'text-2xl font-display font-bold ' + tone}>${value}</p>
        </div>`;
}

// --------------------------------------------------------------------------
// Add by hand (§12.5 attendee_add)
// --------------------------------------------------------------------------

function AddPanel({ eventId, onDone, onCancel }) {
    const [form, setForm] = useState({ phone: '', first_name: '', last_name: '', gender: '', email: '', consent: false });
    const [busy, setBusy] = useState(false);

    const set = (key) => (value) => setForm((f) => ({ ...f, [key]: value }));

    const submit = async () => {
        setBusy(true);
        try {
            const data = await studio('attendee_add', { id: eventId, ...form, force: true });
            toast(data.message || 'Added.', 'success');
            onDone();
        } catch (e) {
            toast(e.message, 'error');
        } finally {
            setBusy(false);
        }
    };

    return html`
        <${Card} title="Add someone by hand"
                 subtitle="For the desk and the phone: this works even when online registration is closed.">
            <div class="grid sm:grid-cols-2 gap-4">
                <${Field} label="Phone number" name="phone" required>
                    <${TextInput} name="phone" value=${form.phone} onInput=${set('phone')} placeholder="0803 123 4567" />
                <//>
                <${Field} label="First name" name="first_name" required>
                    <${TextInput} name="first_name" value=${form.first_name} onInput=${set('first_name')} />
                <//>
                <${Field} label="Last name" name="last_name">
                    <${TextInput} name="last_name" value=${form.last_name} onInput=${set('last_name')} />
                <//>
                <${Field} label="Gender" name="gender">
                    <${Select} name="gender" value=${form.gender} onChange=${set('gender')}
                        options=${[{ value: '', label: 'Not given' }, { value: 'Male', label: 'Male' }, { value: 'Female', label: 'Female' }]} />
                <//>
                <${Field} label="Email" name="email">
                    <${TextInput} name="email" type="email" value=${form.email} onInput=${set('email')} />
                <//>
                <div class="flex items-end">
                    <label class="flex items-center gap-3 cursor-pointer">
                        <input type="checkbox" checked=${form.consent}
                            onChange=${(e) => set('consent')(e.currentTarget.checked)}
                            class="w-5 h-5 rounded border-gray-300 text-hodBlue focus:ring-hodBlue" />
                        <span class="text-sm font-semibold text-gray-700">They agreed to follow-up contact</span>
                    </label>
                </div>
            </div>
            <div class="flex gap-2 mt-6">
                <${Button} onClick=${submit} loading=${busy} disabled=${!form.phone || !form.first_name}>Add<//>
                <${Button} variant="ghost" onClick=${onCancel}>Cancel<//>
            </div>
        <//>`;
}

// --------------------------------------------------------------------------
// Delete — both grades, one button
// --------------------------------------------------------------------------

/**
 * The destructive actions, behind one button (§12.5).
 *
 * Delete and Erase are the only things in this tab that a crew member cannot
 * take back, so they live in a menu rather than on the row, where a mis-tap is
 * one thumb away from somebody's seat.
 *
 * Delete carries both grades. The soft one is the everyday tool: off the list,
 * links dead, seat back in the room and — the whole point — free to register
 * again. The permanent one is the escape hatch for a test account or a
 * duplicate, and it is the only action here that leaves nothing behind.
 *
 * Erase stays its own button because it makes a different promise: the counts
 * survive it, and it is what answers a privacy request. Both it and the
 * permanent grade are manager-only on the server (`se_require_manager`), so
 * they are hidden from a Producer rather than offered and then refused.
 */
function DangerMenu({ row, onDelete }) {
    const [open, setOpen] = useState(false);
    const [block, setBlock] = useState(false);
    const manager = can('settings.manage');

    // A menu that outlives the click that opened it ends up hiding the list
    // underneath it. A click anywhere outside — or Escape — closes it; clicks
    // inside never reach these listeners, because the wrapper stops them. That
    // is deliberate: a `closest()` test would depend on whether the listener
    // had been attached yet when the opening click was still bubbling.
    useEffect(() => {
        if (!open) return undefined;
        const close = () => setOpen(false);
        const onKey = (e) => { if (e.key === 'Escape') setOpen(false); };
        document.addEventListener('click', close);
        document.addEventListener('keydown', onKey);
        return () => {
            document.removeEventListener('click', close);
            document.removeEventListener('keydown', onKey);
        };
    }, [open]);

    const pick = (permanent) => {
        setOpen(false);
        onDelete(row, permanent, block);
    };

    return html`
        <div class="relative" data-se-danger="1" onClick=${(e) => e.stopPropagation()}>
            <${Button} variant="danger" onClick=${() => setOpen(!open)}>Delete ▾<//>

            ${open ? html`
            <div class="absolute mt-2 w-56 p-2 bg-white border border-gray-200 rounded-2xl shadow-lg z-50 text-left"
                style=${{ right: 0 }}>

                <label class="flex items-start gap-2 px-3 py-2 cursor-pointer">
                    <input type="checkbox" checked=${block} class="w-4 h-4 mt-1 shrink-0"
                        onChange=${(e) => setBlock(e.currentTarget.checked)} />
                    <span class="text-xs text-gray-700">Also keep them out — the door turns them away</span>
                </label>

                <div class="border-t border-gray-100 mt-1 pt-1">
                    <button type="button" onClick=${() => pick(false)}
                        class="block w-full text-left px-3 py-2 rounded-xl text-sm text-gray-800 hover:bg-gray-50">
                        <span class="block font-semibold">Delete</span>
                        <span class="block text-xs text-gray-500">Off the list. They can register again.</span>
                    </button>

                    ${manager ? html`
                    <button type="button" onClick=${() => pick(true)}
                        class="block w-full text-left px-3 py-2 rounded-xl text-sm text-red-600 hover:bg-gray-50">
                        <span class="block font-semibold">Delete permanently</span>
                        <span class="block text-xs text-gray-500">Every trace goes, and the counts drop.</span>
                    </button>` : null}
                </div>
            </div>` : null}
        </div>`;
}

// --------------------------------------------------------------------------
// One person
// --------------------------------------------------------------------------

function AttendeeRow({ row, onAct, onEdit, onDelete, onErase, expanded, onToggle }) {
    return html`
        <li class="border-b border-gray-50 last:border-0">
            <div class="py-3 flex flex-wrap items-center gap-3">
                <button type="button" onClick=${onToggle}
                    class="flex-1 min-w-0 text-left group"
                    aria-expanded=${expanded ? 'true' : 'false'}>
                    <p class="font-semibold text-gray-900 group-hover:text-hodBlue truncate">
                        ${row.display_name}
                        ${row.is_test ? html`<span class="ml-2 text-[10px] font-bold uppercase text-amber-600">test</span>` : null}
                    </p>
                    <p class="text-xs text-gray-500 font-mono">${row.reg_code} · ${row.phone || 'no phone'}</p>
                </button>

                <${StatusChip} status=${row.status} deleted=${row.deleted} />

                ${row.is_member
                    ? html`<span class="text-[11px] font-bold text-hodBlue bg-blue-50 px-2 py-0.5 rounded-full">Member</span>`
                    : null}
                ${row.karaoke_interest ? html`<span title="Wants to sing" aria-label="Wants to sing">🎤</span>` : null}
                ${row.wants_visit ? html`<span title="Would like a Sunday visit" aria-label="Would like a Sunday visit">💛</span>` : null}
            </div>

            ${expanded ? html`
            <div class="pb-4 pl-1 pr-1 space-y-3">
                <dl class="grid sm:grid-cols-3 gap-3 text-sm bg-gray-50 rounded-2xl p-4">
                    ${[
                        ['Registered', formatDateTime(row.created_at)],
                        ['Channel', row.channel],
                        ['Seat pool', row.seat_pool || '—'],
                        ['Source', row.src || '—'],
                        ['Email', row.email || '—'],
                        ['Gender', row.gender || '—'],
                        ['Consent', row.consent ? 'Given' : 'Not given'],
                        ['Opted out', row.opted_out ? 'Yes' : 'No'],
                        ['Invite code', row.ref_code],
                    ].concat(row.deleted_at ? [
                        ['Deleted', formatDateTime(row.deleted_at)],
                        ['Why', row.cancel_reason || '—'],
                    ] : []).map(([k, v]) => html`
                    <div key=${k}>
                        <dt class="text-[10px] uppercase tracking-wider text-gray-400 font-bold">${k}</dt>
                        <dd class="text-gray-800 break-words">${v}</dd>
                    </div>`)}
                </dl>

                ${row.name_correction ? html`
                <p class="text-sm bg-amber-50 text-amber-800 rounded-xl px-4 py-2">
                    They typed a different name: <strong>${row.name_correction}</strong>
                </p>` : null}

                ${Object.keys(row.answers || {}).length ? html`
                <dl class="grid sm:grid-cols-2 gap-3 text-sm">
                    ${Object.entries(row.answers).map(([k, v]) => html`
                    <div key=${k}>
                        <dt class="text-[10px] uppercase tracking-wider text-gray-400 font-bold">${k}</dt>
                        <dd class="text-gray-800">${Array.isArray(v) ? v.join(', ') : String(v)}</dd>
                    </div>`)}
                </dl>` : null}

                <div class="flex flex-wrap gap-2">
                    ${can('attendee.pii') ? html`<${Button} variant="secondary" onClick=${() => onEdit(row)}>Correct details<//>` : null}

                    ${row.status === 'waitlisted' && can('event.capacity_override')
                        ? html`<${Button} variant="secondary" onClick=${() => onAct('attendee_promote', row)}>Promote<//>` : null}

                    ${['confirmed', 'waitlisted'].includes(row.status) && can('desk.checkin')
                        ? html`<${Button} variant="secondary"
                            onClick=${() => onAct('attendee_cancel', row, 'Release this seat?')}>Release seat<//>` : null}

                    ${['cancelled', 'removed'].includes(row.status) && can('desk.checkin')
                        ? html`<${Button} variant="secondary" onClick=${() => onAct('attendee_restore', row)}>Put back on the list<//>` : null}

                    ${can('attendee.pii') ? html`
                        <${Button} variant="secondary" onClick=${() => onAct('attendee_reset_links', row,
                            'Kill every link this person holds and issue a new one?')}>Reset links<//>` : null}

                    ${can('attendee.pii') ? html`<${DangerMenu} row=${row} onDelete=${onDelete} />` : null}

                    ${can('settings.manage') ? html`
                        <${Button} variant="danger" onClick=${() => onErase(row)}>Erase their data<//>` : null}
                </div>
            </div>` : null}
        </li>`;
}

// --------------------------------------------------------------------------
// Correction panel
// --------------------------------------------------------------------------

function EditPanel({ eventId, row, onDone, onCancel }) {
    const [form, setForm] = useState({
        first_name: row.first_name,
        last_name: row.last_name,
        gender: row.gender || '',
        email: row.email || '',
        karaoke_interest: row.karaoke_interest,
        wants_visit: row.wants_visit,
    });
    const [busy, setBusy] = useState(false);

    const set = (key) => (value) => setForm((f) => ({ ...f, [key]: value }));

    return html`
        <${Card} title=${'Correct ' + row.display_name} subtitle="Also updates what this person's next registration pre-fills.">
            <div class="grid sm:grid-cols-2 gap-4">
                <${Field} label="First name" name="first_name" required>
                    <${TextInput} name="first_name" value=${form.first_name} onInput=${set('first_name')} />
                <//>
                <${Field} label="Last name" name="last_name">
                    <${TextInput} name="last_name" value=${form.last_name} onInput=${set('last_name')} />
                <//>
                <${Field} label="Gender" name="gender">
                    <${Select} name="gender" value=${form.gender} onChange=${set('gender')}
                        options=${[{ value: '', label: 'Not given' }, { value: 'Male', label: 'Male' }, { value: 'Female', label: 'Female' }]} />
                <//>
                <${Field} label="Email" name="email">
                    <${TextInput} name="email" type="email" value=${form.email} onInput=${set('email')} />
                <//>
            </div>
            <div class="flex flex-wrap gap-5 mt-4">
                <label class="flex items-center gap-2 cursor-pointer text-sm font-semibold text-gray-700">
                    <input type="checkbox" checked=${form.karaoke_interest}
                        onChange=${(e) => set('karaoke_interest')(e.currentTarget.checked)}
                        class="w-5 h-5 rounded border-gray-300 text-hodBlue focus:ring-hodBlue" />
                    Wants to sing
                </label>
                <label class="flex items-center gap-2 cursor-pointer text-sm font-semibold text-gray-700">
                    <input type="checkbox" checked=${form.wants_visit}
                        onChange=${(e) => set('wants_visit')(e.currentTarget.checked)}
                        class="w-5 h-5 rounded border-gray-300 text-hodBlue focus:ring-hodBlue" />
                    Would like a Sunday visit
                </label>
            </div>
            <div class="flex gap-2 mt-6">
                <${Button} loading=${busy} onClick=${async () => {
                    setBusy(true);
                    try {
                        await studio('attendee_update', { id: eventId, attendee_id: row.id, fields: form });
                        toast('Saved.', 'success');
                        onDone();
                    } catch (e) {
                        toast(e.message, 'error');
                    } finally {
                        setBusy(false);
                    }
                }}>Save<//>
                <${Button} variant="ghost" onClick=${onCancel}>Cancel<//>
            </div>
        <//>`;
}

// --------------------------------------------------------------------------
// Tab
// --------------------------------------------------------------------------

export function AttendeesTab() {
    const event = current.value;
    const [data, setData] = useState(null);
    const [status, setStatus] = useState('live');
    const [q, setQ] = useState('');
    const [page, setPage] = useState(1);
    const [open, setOpen] = useState(null);
    const [panel, setPanel] = useState(null);
    const [exporting, setExporting] = useState(false);

    const load = useCallback(async () => {
        if (!event) return;
        try {
            setData(await studio('attendees_list', {
                id: event.id,
                filters: { status },
                q,
                page,
                per_page: 50,
            }));
        } catch (e) {
            toast(e.message, 'error');
            setData({ items: [], total: 0, pages: 0, page: 1 });
        }
    }, [event?.id, status, q, page]);

    // Debounced, so typing a name does not fire a query per keystroke.
    useEffect(() => {
        const timer = setTimeout(load, q ? 300 : 0);
        return () => clearTimeout(timer);
    }, [load]);

    if (!event) return html`<${Spinner} />`;

    if (!can('attendee.search')) {
        return html`<${Card}><${EmptyState} title="Not for you — yet"
            message="Ask a Producer for the Desk or Follow-up role to see the attendee list." /><//>`;
    }

    const act = async (action, row, confirmText, prompts) => {
        if (confirmText && !confirm(confirmText)) return;

        const payload = { id: event.id, attendee_id: row.id };

        if (prompts === 'reason') {
            const reason = prompt('Why are they being removed? The crew log keeps this.');
            if (!reason) return;
            payload.reason = reason;
        }
        if (prompts === 'erase') {
            if (!confirm(`Erase ${row.display_name}'s personal data? This cannot be undone — only the anonymous counts survive.`)) return;
            const reason = prompt('Record why this erasure was requested (e.g. "subject request, 4 Oct").');
            if (!reason) return;
            payload.reason = reason;
        }

        try {
            const result = await studio(action, payload);
            toast(result.message || 'Done.', 'success');
            if (result.manage_url) {
                try {
                    await navigator.clipboard.writeText(result.manage_url);
                    toast('New link copied to your clipboard.', 'success');
                } catch (e) {
                    toast(result.manage_url, 'info', 12000);
                }
            }
            load();
        } catch (e) {
            toast(e.message, 'error');
        }
    };

    /**
     * Delete, in both grades (§12.5).
     *
     * The wording of the confirm is the only place a crew member is told what
     * they are about to lose, so it says it plainly: the soft grade frees the
     * number, the permanent grade takes the counts with it.
     */
    const deleteAttendee = async (row, permanent, block) => {
        const question = permanent
            ? `Delete ${row.display_name} permanently? This erases every trace — their registration, contact, check-ins, karaoke entries, game answers, feedback and links. The counts for the night will drop. This cannot be undone.`
            : (block
                ? `Delete ${row.display_name} and keep them out? Their seat is released and the door will turn them away if they try to register again.`
                : `Delete ${row.display_name} from the list? Their seat is released, their links stop working, and they can register again.`);

        if (!confirm(question)) return;

        const reason = prompt(permanent
            ? 'Why is this being deleted permanently? The crew log keeps this — the data does not.'
            : 'Why? The crew log keeps this.');
        if (!reason) return;

        try {
            const result = await studio(permanent ? 'attendee_delete_permanent' : 'attendee_delete',
                permanent
                    ? { id: event.id, attendee_id: row.id, reason }
                    : { id: event.id, attendee_id: row.id, reason, block });
            toast(result.message || 'Done.', 'success');
            setOpen(null);
            load();
        } catch (e) {
            toast(e.message, 'error');
        }
    };

    const exportList = async () => {
        setExporting(true);
        try {
            const result = await studio('attendees_export', { id: event.id });
            // A plain navigation: the file is a GET download, not JSON.
            window.location.href = result.url;
        } catch (e) {
            toast(e.message, 'error');
        } finally {
            setExporting(false);
        }
    };

    const counts = data?.counts || {};

    return html`
        <div class="space-y-6">

            ${panel?.kind === 'add' ? html`
                <${AddPanel} eventId=${event.id} onCancel=${() => setPanel(null)}
                    onDone=${() => { setPanel(null); load(); }} />` : null}

            ${panel?.kind === 'edit' ? html`
                <${EditPanel} eventId=${event.id} row=${panel.row} onCancel=${() => setPanel(null)}
                    onDone=${() => { setPanel(null); load(); }} />` : null}

            <${Card}>
                <div class="grid grid-cols-2 sm:grid-cols-5 gap-3">
                    <${Kpi} label="Confirmed" value=${counts.confirmed ?? 0} />
                    <${Kpi} label="Waitlist" value=${counts.waitlisted ?? 0} tone="text-amber-600" />
                    <${Kpi} label="Cancelled" value=${counts.cancelled ?? 0} tone="text-gray-400" />
                    <${Kpi} label="Walk-ins" value=${counts.walkin ?? 0} />
                    <${Kpi} label="Checked in" value=${counts.checked_in ?? 0} tone="text-emerald-600" />
                </div>
            <//>

            <${Card} title="Attendees"
                     subtitle=${data ? `${data.total} matching` : 'Loading…'}
                     actions=${html`
                        ${can('desk.checkin') ? html`<${Button} variant="secondary" onClick=${() => setPanel({ kind: 'add' })}>Add by hand<//>` : null}
                        ${can('attendee.export') ? html`<${Button} variant="secondary" loading=${exporting} onClick=${exportList}>Export to Excel<//>` : null}`}>

                <div class="flex flex-wrap gap-3 mb-5">
                    <div class="flex-1 min-w-[12rem]">
                        <label class="sr-only" for="se-att-q">Search</label>
                        <input id="se-att-q" type="search" value=${q} placeholder="Name, code or phone number"
                            onInput=${(e) => { setPage(1); setQ(e.currentTarget.value); }}
                            class="w-full px-4 py-3 rounded-xl border border-gray-200 outline-none focus:border-hodBlue bg-white" />
                    </div>
                    <div class="w-44">
                        <label class="sr-only" for="se-f-status">Status</label>
                        <${Select} name="status" value=${status}
                            onChange=${(v) => { setPage(1); setStatus(v); }}
                            options=${STATUS_FILTERS} />
                    </div>
                </div>

                ${!data ? html`<${Spinner} label="Loading the list…" />`
                    : (data.items.length === 0
                        ? html`<${EmptyState} title="Nobody here yet"
                                message=${q ? 'No one matches that search.' : 'Registrations will appear here the moment they come in.'} />`
                        : html`
                        <ul>
                            ${data.items.map((row) => html`
                            <${AttendeeRow} key=${row.id} row=${row}
                                expanded=${open === row.id}
                                onToggle=${() => setOpen(open === row.id ? null : row.id)}
                                onEdit=${(r) => setPanel({ kind: 'edit', row: r })}
                                onDelete=${deleteAttendee}
                                onErase=${(r) => act('attendee_erase', r, null, 'erase')}
                                onAct=${act} />`)}
                        </ul>`)}

                ${data && data.pages > 1 ? html`
                <div class="flex items-center justify-between gap-3 mt-5 pt-4 border-t border-gray-100">
                    <${Button} variant="ghost" disabled=${page <= 1} onClick=${() => setPage(page - 1)}>Previous<//>
                    <span class="text-sm text-gray-500">Page ${data.page} of ${data.pages}</span>
                    <${Button} variant="ghost" disabled=${page >= data.pages} onClick=${() => setPage(page + 1)}>Next<//>
                </div>` : null}
            <//>

            ${can('attendee.search') ? html`<${TidyUp} eventId=${event.id} />` : null}
        </div>`;
}

/** Possible duplicates and guests who have since become members (§12.5). */
function TidyUp({ eventId }) {
    const [state, setState] = useState(null);

    useEffect(() => {
        Promise.all([
            studio('possible_duplicates', { id: eventId }).catch(() => ({ items: [] })),
            studio('possible_members', { id: eventId }).catch(() => ({ items: [] })),
        ]).then(([dupes, members]) => setState({ dupes: dupes.items || [], members: members.items || [] }));
    }, [eventId]);

    if (!state || (!state.dupes.length && !state.members.length)) return null;

    return html`
        <${Card} title="Worth a look" subtitle="Nothing is changed automatically — these are only suggestions.">
            ${state.dupes.length ? html`
            <div class="mb-5">
                <h3 class="text-sm font-bold text-gray-700 mb-2">Possible duplicates</h3>
                <ul class="text-sm text-gray-600 space-y-1">
                    ${state.dupes.map((d) => html`<li key=${d.name}>${d.name} — ${d.count} entries</li>`)}
                </ul>
            </div>` : null}

            ${state.members.length ? html`
            <div>
                <h3 class="text-sm font-bold text-gray-700 mb-2">Registered as guests, but their number matches a member</h3>
                <ul class="text-sm text-gray-600 space-y-1">
                    ${state.members.map((m) => html`<li key=${m.id}>${m.display_name} → ${m.member_name}</li>`)}
                </ul>
            </div>` : null}
        <//>`;
}
