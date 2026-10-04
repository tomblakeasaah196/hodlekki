// /assets/se/js/studio/tabs/messages.js
//
// Messages (guide §16).
//
// Everything here is spent money, so the tab is built around one question:
// "how many people, how many units, and what exactly will they read?" The
// preview is the server's own render — same cleaner, same segment maths —
// so what is on screen is what the gateway will bill for.
//
// Sending never happens from this tab directly: a run is created, the SMS
// Studio queue delivers it, and the run log links to the campaign there.
// That is the whole reason a duplicate reminder is impossible — the run key
// is unique, so pressing the button twice is a no-op (§16.4).

import { html } from '@se/core/html.js';
import { useState, useEffect } from 'preact/hooks';
import { studio } from '@se/core/api.js';
import { current, toast, can } from '../state.js';
import { Card, Button, Spinner, Field, TextInput, TextArea, Switch, Select } from '../ui.js';

function Preview({ preview }) {
    if (!preview) return null;

    return html`
        <div class="rounded-2xl bg-gray-50 border border-gray-100 p-4 space-y-2">
            <p class="text-sm text-gray-900 whitespace-pre-wrap font-mono">${preview.text}</p>
            <p class="text-xs text-gray-500">
                ${preview.chars} characters · ${preview.encoding} ·
                ${preview.pages} ${preview.pages === 1 ? 'segment' : 'segments'} per person
            </p>
            ${preview.replaced?.length ? html`
                <p class="text-xs text-amber-700">
                    Cleaned for the gateway: ${preview.replaced.join(', ')}
                </p>` : null}
        </div>`;
}

function KindCard({ event, kind, onSaved }) {
    const [form, setForm] = useState(kind);
    const [preview, setPreview] = useState(kind.preview);
    const [audience, setAudience] = useState({ recipients: kind.audience, est_units: kind.est_units });
    const [busy, setBusy] = useState(false);
    const [phone, setPhone] = useState('');
    const readOnly = !can('event.edit');

    useEffect(() => { setForm(kind); setPreview(kind.preview); }, [kind]);

    // The preview is debounced rather than live: one request per pause in
    // typing, not one per keystroke.
    useEffect(() => {
        if (form.template === kind.template) return undefined;
        const timer = setTimeout(async () => {
            try {
                const data = await studio('messages_preview', {
                    id: event.id, kind: form.kind, template: form.template, segment: form.kind,
                });
                setPreview(data.preview);
                setAudience({ recipients: data.recipients, est_units: data.est_units });
            } catch (e) {
                toast(e.message, 'error');
            }
        }, 500);
        return () => clearTimeout(timer);
    }, [form.template]);

    async function save() {
        setBusy(true);
        try {
            const patch = {
                [form.kind]: {
                    enabled: !!form.enabled,
                    template: form.template,
                    ...(form.at !== null && form.at !== undefined ? { at: form.at } : {}),
                    ...(form.minutes_before !== null && form.minutes_before !== undefined
                        ? { minutes_before: Number(form.minutes_before) } : {}),
                },
            };
            const data = await studio('messages_save', { id: event.id, settings: patch });
            onSaved(data);
            toast('Saved.', 'success');
        } catch (e) {
            toast(e.message, 'error');
        } finally {
            setBusy(false);
        }
    }

    async function test() {
        setBusy(true);
        try {
            await studio('messages_test', { id: event.id, kind: form.kind, phone });
            toast('Test message queued — it will arrive in a moment.', 'success');
        } catch (e) {
            toast(e.message, 'error');
        } finally {
            setBusy(false);
        }
    }

    return html`
        <${Card} title=${form.label}
            subtitle=${form.scheduled ? 'Scheduled' : 'Not scheduled for this event'}
            actions=${html`<${Button} onClick=${save} loading=${busy} disabled=${readOnly}>Save</${Button}>`}>

            <${Switch} label="Send this one" checked=${!!form.enabled}
                onChange=${(v) => setForm({ ...form, enabled: v })} />

            ${form.at !== null && form.at !== undefined ? html`
                <${Field} label="Time of day" name=${'at-' + form.kind}
                    hint="On a multi-day event this goes out the day before each day.">
                    <input type="time" id=${'at-' + form.kind} value=${form.at}
                        class="rounded-xl border-gray-200 text-sm" disabled=${readOnly}
                        onInput=${(e) => setForm({ ...form, at: e.currentTarget.value })} />
                <//>` : null}

            ${form.minutes_before !== null && form.minutes_before !== undefined ? html`
                <${Field} label="Minutes before the doors" name=${'mb-' + form.kind}>
                    <input type="number" min="0" max="1440" id=${'mb-' + form.kind}
                        value=${form.minutes_before} class="rounded-xl border-gray-200 text-sm" disabled=${readOnly}
                        onInput=${(e) => setForm({ ...form, minutes_before: Number(e.currentTarget.value) || 0 })} />
                <//>` : null}

            <${Field} label="What it says" name=${'tpl-' + form.kind}
                hint="Tokens: {first_name} {event} {date} {time} {venue} {link}">
                <${TextArea} name=${'tpl-' + form.kind} value=${form.template} rows=${4} maxLength=${600}
                    onInput=${(e) => setForm({ ...form, template: e.currentTarget.value })} />
            <//>

            <${Preview} preview=${preview} />

            ${audience.recipients !== null && audience.recipients !== undefined ? html`
                <p class="text-sm text-gray-600 mt-3">
                    Going to <strong>${audience.recipients}</strong> people —
                    about <strong>${audience.est_units}</strong> units.
                </p>` : null}

            ${readOnly ? null : html`
                <div class="mt-4 flex flex-wrap items-end gap-2">
                    <div class="w-56">
                        <${Field} label="Send a test to" name=${'test-' + form.kind}>
                            <${TextInput} name=${'test-' + form.kind} value=${phone}
                                placeholder="024 123 4567" onInput=${(e) => setPhone(e.currentTarget.value)} />
                        <//>
                    </div>
                    <${Button} variant="ghost" onClick=${test} disabled=${busy || !phone.trim()}>Test send</${Button}>
                </div>`}
        <//>`;
}

function AdHoc({ event, segments, onSent }) {
    const [segment, setSegment] = useState(segments[0] || 'confirmed');
    const [template, setTemplate] = useState('');
    const [preview, setPreview] = useState(null);
    const [count, setCount] = useState(null);
    const [busy, setBusy] = useState(false);
    const [confirming, setConfirming] = useState(false);

    async function check() {
        setBusy(true);
        try {
            const data = await studio('messages_estimate', {
                id: event.id, kind: 'adhoc', segment, template,
            });
            setPreview(data.preview);
            setCount(data);
            setConfirming(true);
        } catch (e) {
            toast(e.message, 'error');
        } finally {
            setBusy(false);
        }
    }

    async function send() {
        setBusy(true);
        try {
            const data = await studio('messages_send_adhoc', { id: event.id, segment, template });
            setConfirming(false);
            setTemplate('');
            setPreview(null);
            onSent(data);
            toast('On its way.', 'success');
        } catch (e) {
            toast(e.message, 'error');
        } finally {
            setBusy(false);
        }
    }

    return html`
        <${Card} title="Send something now"
            subtitle="For the things nobody planned: a venue change, a delay, a thank you.">
            <${Field} label="Who gets it" name="adhoc-segment">
                <${Select} name="adhoc-segment" value=${segment}
                    options=${segments.map((s) => ({ value: s, label: s.replace(/_/g, ' ') }))}
                    onChange=${(e) => { setSegment(e.currentTarget.value); setConfirming(false); }} />
            <//>
            <${Field} label="What it says" name="adhoc-template"
                hint="Tokens: {first_name} {event} {date} {time} {venue} {link}">
                <${TextArea} name="adhoc-template" value=${template} rows=${4} maxLength=${600}
                    onInput=${(e) => { setTemplate(e.currentTarget.value); setConfirming(false); }} />
            <//>

            ${preview ? html`<${Preview} preview=${preview} />` : null}

            ${confirming ? html`
                <div class="mt-4 rounded-2xl bg-amber-50 border border-amber-200 p-4 space-y-3">
                    <p class="text-sm text-amber-900">
                        This sends to <strong>${count.recipients}</strong> people and costs about
                        <strong>${count.est_units}</strong> units. There is no undo once it is queued.
                    </p>
                    <div class="flex gap-2">
                        <${Button} onClick=${send} loading=${busy}>Yes, send it</${Button}>
                        <${Button} variant="ghost" onClick=${() => setConfirming(false)}>Cancel</${Button}>
                    </div>
                </div>`
                : html`<${Button} onClick=${check} loading=${busy} disabled=${!template.trim()}>
                    Check it first</${Button}>`}
        <//>`;
}

function Runs({ runs }) {
    if (!runs?.length) {
        return html`<${Card} title="Run log">
            <p class="text-sm text-gray-500">Nothing has gone out yet.</p>
        <//>`;
    }

    return html`
        <${Card} title="Run log" subtitle="Every send, once. Follow a link to see delivery in the SMS Studio.">
            <ul class="divide-y divide-gray-100 text-sm">
                ${runs.map((run) => html`
                    <li class="py-2 flex flex-wrap items-center gap-2" key=${run.id}>
                        <span class="font-medium text-gray-900 flex-1">${run.label}</span>
                        <span class="text-gray-400">${new Date(run.scheduled_for).toLocaleString()}</span>
                        <span class=${'px-2 py-0.5 rounded-full text-xs ' + (
                            run.status === 'queued' ? 'bg-emerald-100 text-emerald-800'
                            : run.status === 'failed' ? 'bg-red-100 text-red-800'
                            : run.status === 'skipped' ? 'bg-gray-100 text-gray-600'
                            : 'bg-blue-100 text-blue-800')}>${run.status}</span>
                        <span class="text-gray-500">${run.recipients} people · ${run.est_units} units</span>
                        ${run.campaign_url
                            ? html`<a class="text-hodBlue hover:underline" href=${run.campaign_url}>Open in SMS Studio</a>`
                            : null}
                    </li>`)}
            </ul>
        <//>`;
}

// --------------------------------------------------------------------------

export function MessagesTab() {
    // current.value is already the complete Studio event payload.
    const event = current.value;
    const [data, setData] = useState(null);
    const [busy, setBusy] = useState(false);

    function load() {
        if (!event) return;
        studio('messages_get', { id: event.id }).then(setData).catch((e) => toast(e.message, 'error'));
    }

    useEffect(load, [event?.id]);

    async function runNow() {
        setBusy(true);
        try {
            const result = await studio('messages_run_now', { id: event.id });
            setData({ ...data, runs: result.runs });
            toast(result.ran?.length ? 'Done.' : 'Nothing is due right now.', 'info');
        } catch (e) {
            toast(e.message, 'error');
        } finally {
            setBusy(false);
        }
    }

    if (!event) return null;
    if (!data) return html`<${Spinner} label="Loading the messages…" />`;

    return html`
        <div class="space-y-6">
            ${!data.sms.available ? html`
                <p class="rounded-2xl bg-red-50 border border-red-200 text-red-900 text-sm p-4">
                    The SMS Studio is not set up on this site, so nothing can be sent yet.
                </p>`
                : !data.sms.worker_fresh ? html`
                <p class="rounded-2xl bg-amber-50 border border-amber-200 text-amber-900 text-sm p-4">
                    The SMS queue worker has not run recently. Messages will be created but may sit
                    in the queue until it is running again.
                </p>` : null}

            ${data.schedule.length ? html`
                <${Card} title="What is scheduled">
                    <ul class="text-sm text-gray-600 space-y-1">
                        ${data.schedule.map((slot) => html`
                            <li key=${slot.run_key}>
                                <strong class="text-gray-900">${slot.label}</strong> —
                                ${new Date(slot.scheduled_for).toLocaleString()}
                            </li>`)}
                    </ul>
                    <p class="text-xs text-gray-400 mt-3">
                        The cron sends these. A run more than ${data.max_lateness_min} minutes late is
                        skipped rather than sent at the wrong moment.
                    </p>
                    ${can('event.edit') ? html`
                        <div class="mt-3">
                            <${Button} variant="ghost" loading=${busy} onClick=${runNow}>Run what is due now</${Button}>
                        </div>` : null}
                <//>` : null}

            ${data.kinds.map((kind) => html`
                <${KindCard} key=${kind.kind} event=${event} kind=${kind} onSaved=${setData} />`)}

            ${can('event.edit')
                ? html`<${AdHoc} event=${event} segments=${data.segments}
                    onSent=${(result) => setData({ ...data, runs: result.runs })} />`
                : null}

            <${Runs} runs=${data.runs} />
        </div>`;
}
