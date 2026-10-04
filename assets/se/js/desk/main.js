// /assets/se/js/desk/main.js
//
// Desk mode (guide §13.11).
//
// A volunteer on a phone at the door, in a hall where the Wi-Fi is one bar
// and the 4G drops every time the lift doors open. The one thing that must
// never happen is a queue forming because the network is slow.
//
// So every check-in is written to an IndexedDB queue *first* and sent
// afterwards. The person at the door sees "Checked in" immediately; the
// queue drains in the background and shows a pending count if it cannot.
// Duplicate sends are harmless: the server's unique key makes a repeat a
// no-op, and each queued item carries a client id so a retry after a
// timeout cannot double-register anybody.

import { render } from 'preact';
import { useEffect, useState, useCallback } from 'preact/hooks';
import { html } from '@se/core/html.js';
import { call, SeApiError } from '@se/core/api.js';
import { boot, toast, toasts, dismissToast } from '@se/core/store.js';
import { normalizePhone } from '@se/core/phone.js';

const config = boot.value || {};
const DB_NAME = 'se-desk';
const STORE = 'queue';

// --------------------------------------------------------------------------
// The offline queue
// --------------------------------------------------------------------------

let dbPromise = null;

function openDb() {
    if (dbPromise) return dbPromise;

    dbPromise = new Promise((resolve, reject) => {
        const request = indexedDB.open(DB_NAME, 1);
        request.onupgradeneeded = () => {
            request.result.createObjectStore(STORE, { keyPath: 'id', autoIncrement: true });
        };
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    });

    return dbPromise;
}

function tx(mode, run) {
    return openDb().then((db) => new Promise((resolve, reject) => {
        const transaction = db.transaction(STORE, mode);
        const result = run(transaction.objectStore(STORE));
        transaction.oncomplete = () => resolve(result?.result ?? result);
        transaction.onerror = () => reject(transaction.error);
    }));
}

const queue = {
    add: (item) => tx('readwrite', (store) => store.add({ ...item, at: Date.now() })),
    all: () => tx('readonly', (store) => store.getAll()),
    remove: (id) => tx('readwrite', (store) => store.delete(id)),
};

/**
 * Try to send everything we are holding.
 *
 * A rule error (already checked in, walk-ins full) is *not* retried: the
 * server has made a decision and repeating it will not change the answer,
 * so the item is dropped and the desk is told. Only transport failures stay
 * in the queue.
 */
async function drain(onCount) {
    let items = [];
    try { items = await queue.all(); } catch (e) { return; }

    for (const item of items) {
        try {
            await call('live', item.action, item.payload);
            await queue.remove(item.id);
        } catch (error) {
            if (error instanceof SeApiError && error.status >= 400 && error.status < 500) {
                await queue.remove(item.id);
                toast((item.payload.name || 'That check-in') + ': ' + error.message, 'error', 8000);
            } else {
                break; // still offline — keep the order and try again later
            }
        }
    }

    try { onCount((await queue.all()).length); } catch (e) { /* ignore */ }
}

// --------------------------------------------------------------------------
// Screens
// --------------------------------------------------------------------------

function Search({ onPicked }) {
    const [term, setTerm] = useState('');
    const [results, setResults] = useState([]);
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        if (term.trim().length < 2) { setResults([]); return undefined; }

        // 250 ms is long enough that a volunteer typing a surname does not
        // fire six queries, short enough that it still feels instant.
        const handle = setTimeout(async () => {
            setBusy(true);
            try {
                const data = await call('live', 'desk_search', {
                    event: config.event.public_id,
                    q: term.trim(),
                });
                setResults(data.results || []);
            } catch (e) {
                setResults([]);
            }
            setBusy(false);
        }, 250);

        return () => clearTimeout(handle);
    }, [term]);

    return html`
        <section class="se-panel">
            <h2>Find someone</h2>
            <input class="se-input" value=${term} autocomplete="off"
                inputmode="search" placeholder="Name or last 4 digits"
                aria-label="Search by name or phone"
                onInput=${(e) => setTerm(e.currentTarget.value)} />
            ${busy ? html`<p class="se-small se-muted">Searching…</p>` : null}

            <div class="se-rows">
                ${results.map((person) => html`
                    <button type="button" class="se-row" key=${person.registration_id}
                        onClick=${() => onPicked(person)}>
                        <span>${person.display_name}</span>
                        <span class="se-small se-muted">
                            ${person.checked_in ? 'already in' : person.status}
                            ${person.player_no ? ' · #' + person.player_no : ''}
                            ${person.team ? ' · ' + (person.team.name || ('Team ' + person.team.label)) : ''}
                            ${person.phone_masked ? ' · ' + person.phone_masked : ''}
                        </span>
                    </button>`)}
                ${term.trim().length >= 2 && !busy && !results.length
                    ? html`<p class="se-small se-muted">Nobody by that name. Use “Walk-in” below.</p>`
                    : null}
            </div>
        </section>`;
}

function Walkin({ onDone }) {
    const [first, setFirst] = useState('');
    const [last, setLast] = useState('');
    const [phone, setPhone] = useState('');
    const [gender, setGender] = useState('');
    const [busy, setBusy] = useState(false);

    const submit = async () => {
        const normalised = normalizePhone(phone);
        if (!first.trim() || !normalised) {
            toast('A first name and a working phone number, please.', 'error');
            return;
        }

        setBusy(true);
        await onDone({
            action: 'desk_walkin',
            name: (first + ' ' + last).trim(),
            payload: {
                event: config.event.public_id,
                first_name: first.trim(),
                last_name: last.trim(),
                phone: normalised.e164,
                gender: gender || null,
            },
        });
        setFirst(''); setLast(''); setPhone(''); setGender('');
        setBusy(false);
    };

    return html`
        <section class="se-panel">
            <h2>Walk-in</h2>
            <label class="se-label" for="se-wi-first">First name</label>
            <input class="se-input" id="se-wi-first" value=${first} autocomplete="given-name"
                onInput=${(e) => setFirst(e.currentTarget.value)} />

            <label class="se-label" for="se-wi-last">Last name</label>
            <input class="se-input" id="se-wi-last" value=${last} autocomplete="family-name"
                onInput=${(e) => setLast(e.currentTarget.value)} />

            <label class="se-label" for="se-wi-phone">Phone</label>
            <input class="se-input" id="se-wi-phone" value=${phone} type="tel" inputmode="tel"
                autocomplete="tel" onInput=${(e) => setPhone(e.currentTarget.value)} />

            <fieldset class="se-choice-row">
                <legend class="se-label">Teams are balanced by this</legend>
                ${[['female', 'Female'], ['male', 'Male']].map(([value, label]) => html`
                    <button type="button" class="se-choice" key=${value}
                        data-on=${gender === value ? '1' : '0'}
                        onClick=${() => setGender(value)}>${label}</button>`)}
            </fieldset>

            <button type="button" class="se-tap" disabled=${busy} onClick=${submit}>
                ${busy ? 'Adding…' : 'Add and check in'}</button>
        </section>`;
}

function Transfer({ person }) {
    const [code, setCode] = useState(null);

    if (!person) {
        return html`
            <section class="se-panel">
                <h2>Move someone to a new phone</h2>
                <p class="se-small se-muted">Find them above first, then come back here.</p>
            </section>`;
    }

    return html`
        <section class="se-panel">
            <h2>Move ${person.display_name} to a new phone</h2>
            <p class="se-small se-muted">
                Read them the code. They type it into their own phone at
                ${String(config.urls?.checkin || '').replace(/^https?:\/\//, '')}.
                It lasts ten minutes and works once.
            </p>
            ${code ? html`<p class="se-verse se-code-big">${code}</p>` : null}
            <button type="button" class="se-tap" onClick=${async () => {
                try {
                    const data = await call('live', 'desk_transfer_code', {
                        event: config.event.public_id,
                        registration_id: person.registration_id,
                    });
                    setCode(data.code);
                } catch (e) { toast(e.message, 'error'); }
            }}>Get a code</button>
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

function Desk() {
    const [state, setState] = useState(null);
    const [pending, setPending] = useState(0);
    const [last, setLast] = useState(null);

    const refresh = useCallback(async () => {
        try {
            setState(await call('live', 'desk_state', { event: config.event.public_id }));
        } catch (e) { /* keep the last good numbers */ }
    }, []);

    useEffect(() => {
        refresh();
        const poll = setInterval(refresh, 5000);
        const drainer = setInterval(() => drain(setPending), 4000);
        addEventListener('online', () => drain(setPending));
        drain(setPending);
        return () => { clearInterval(poll); clearInterval(drainer); };
    }, [refresh]);

    /** Queue first, send second — the volunteer never waits for the network. */
    const enqueue = useCallback(async (item) => {
        await queue.add(item);
        setPending((n) => n + 1);
        toast('Checked in ✓', 'success');
        await drain(setPending);
        refresh();
    }, [refresh]);

    const checkIn = useCallback(async (person) => {
        setLast(person);
        await enqueue({
            action: 'desk_checkin',
            payload: { event: config.event.public_id, registration_id: person.registration_id },
            name: person.display_name,
        });
    }, [enqueue]);

    return html`
        <div class="se-stack">
            ${state?.test_mode
                ? html`<p class="se-test-ribbon">Test mode — nothing tonight counts</p>`
                : null}

            <header class="se-panel">
                <h2>Desk · ${config.event?.title || ''}</h2>
                <p class="se-small se-muted">
                    ${state ? state.counts.checked_in_today + ' in tonight' : 'Loading…'}
                    ${state?.counts?.walkin_left != null ? ' · ' + state.counts.walkin_left + ' walk-in places left' : ''}
                </p>
                ${pending
                    ? html`<p class="se-pending">${pending} waiting to sync — carry on, they will send themselves.</p>`
                    : null}
            </header>

            <${Search} onPicked=${checkIn} />
            <${Walkin} onDone=${enqueue} />

            ${last
                ? html`
                    <section class="se-panel">
                        <h2>Last: ${last.display_name}</h2>
                        <button type="button" class="se-tap se-tap-danger" onClick=${async () => {
                            try {
                                await call('live', 'desk_undo_checkin', {
                                    event: config.event.public_id,
                                    registration_id: last.registration_id,
                                });
                                toast('Undone.', 'info');
                                setLast(null);
                                refresh();
                            } catch (e) { toast(e.message, 'error'); }
                        }}>Undo that check-in</button>
                    </section>`
                : null}

            <${Transfer} person=${last} />
            <${Toasts} />
        </div>`;
}

function start() {
    const host = document.getElementById('se-app');
    if (!host || !config.event?.public_id) return;

    document.getElementById('se-crew-frame')?.remove();
    host.hidden = false;
    render(html`<${Desk} />`, host);
}

start();
