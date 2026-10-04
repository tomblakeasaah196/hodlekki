// /assets/se/js/studio/tabs/registration.js
//
// Registration (guide §13.13): every capacity behaviour from D8, the consent
// mode from D7a, custom questions, and the manual override.
//
// PR1 sets these options; the engine that enforces them at registration time
// is PR2.

import { html } from '@se/core/html.js';
import { useState, useEffect } from 'preact/hooks';
import { studio } from '@se/core/api.js';
import {
    boot, current, draft, saving, isDirty, setDraft, discardDraft, fieldValue,
    toast, applyEvent, can,
} from '../state.js';
import { Card, Field, TextInput, TextArea, Select, Switch, Button, SaveBar, Spinner } from '../ui.js';

function settingsValue(event, path, fallback) {
    const fromDraft = draft.value[`settings:${path}`];
    if (fromDraft !== undefined) return fromDraft;

    return path.split('.').reduce((node, key) => (node ?? {})[key], event.settings) ?? fallback;
}

function setSetting(path, value) {
    setDraft(`settings:${path}`, value);
}

/** Turn the flat `settings:a.b.c` draft keys into a nested document. */
function collectSettings() {
    const out = {};
    for (const [key, value] of Object.entries(draft.value)) {
        if (!key.startsWith('settings:')) continue;
        const parts = key.slice('settings:'.length).split('.');
        let node = out;
        parts.slice(0, -1).forEach((p) => { node = node[p] ??= {}; });
        node[parts[parts.length - 1]] = value;
    }
    return out;
}

function CustomQuestions({ event, readOnly }) {
    const [fields, setFields] = useState([]);
    const [dirty, setDirty] = useState(false);
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        setFields((event.form_fields || []).map((f) => ({ ...f })));
        setDirty(false);
    }, [event.id, event.row_version]);

    const types = boot.value?.form_field_types || ['text'];
    const max = boot.value?.limits?.max_fields || 20;

    const change = (i, key, value) => {
        setFields((prev) => prev.map((f, n) => (n === i ? { ...f, [key]: value } : f)));
        setDirty(true);
    };

    const add = () => {
        setFields((prev) => [...prev, {
            field_key: '', label: '', type: 'text', options: [],
            is_required: false, audience: 'everyone', is_active: true,
        }]);
        setDirty(true);
    };

    const remove = (i) => {
        setFields((prev) => prev.filter((_, n) => n !== i));
        setDirty(true);
    };

    const save = async () => {
        setBusy(true);
        try {
            const data = await studio('form_fields_save', { id: event.id, fields });
            applyEvent({ ...event, form_fields: data.form_fields });
            toast('Questions saved.', 'success');
            setDirty(false);
        } catch (e) {
            toast(e.code === 'VALIDATION' ? Object.values(e.fields)[0] || e.message : e.message, 'error');
        } finally {
            setBusy(false);
        }
    };

    return html`
        <${Card} title="Your own questions"
                 subtitle=${`Up to ${max}. Answers are stored against the registration and appear in the export.`}
                 actions=${!readOnly && fields.length < max
                     ? html`<${Button} variant="secondary" onClick=${add}>Add a question<//>` : null}>

            ${fields.length === 0
                ? html`<p class="text-sm text-gray-500">No extra questions. The standard form already asks for phone, name, gender and how they heard about you.</p>`
                : html`<div class="space-y-4">
                    ${fields.map((f, i) => html`
                    <div key=${i} class="bg-gray-50 rounded-2xl p-4 space-y-3">
                        <div class="flex items-start justify-between gap-3">
                            <span class="text-xs font-bold text-gray-500 uppercase tracking-wide">Question ${i + 1}</span>
                            ${!readOnly ? html`
                                <button type="button" onClick=${() => remove(i)}
                                    class="text-xs font-bold text-hodRed hover:underline">Remove</button>` : null}
                        </div>

                        <input type="text" value=${f.label} placeholder="What do you want to ask?" maxLength="160"
                            disabled=${readOnly} onInput=${(e) => change(i, 'label', e.currentTarget.value)}
                            class="w-full px-3 py-2.5 rounded-xl border border-gray-200 outline-none focus:border-hodBlue bg-white text-sm" />

                        <div class="grid sm:grid-cols-3 gap-3">
                            <select value=${f.type} disabled=${readOnly}
                                onChange=${(e) => change(i, 'type', e.currentTarget.value)}
                                class="px-3 py-2.5 rounded-xl border border-gray-200 outline-none focus:border-hodBlue bg-white text-sm">
                                ${types.map((t) => html`<option key=${t} value=${t} selected=${f.type === t}>${t}</option>`)}
                            </select>

                            <select value=${f.audience} disabled=${readOnly}
                                onChange=${(e) => change(i, 'audience', e.currentTarget.value)}
                                class="px-3 py-2.5 rounded-xl border border-gray-200 outline-none focus:border-hodBlue bg-white text-sm">
                                <option value="everyone" selected=${f.audience === 'everyone'}>Everyone</option>
                                <option value="guests" selected=${f.audience === 'guests'}>Guests only</option>
                                <option value="members" selected=${f.audience === 'members'}>Members only</option>
                            </select>

                            <label class="flex items-center gap-2 text-sm">
                                <input type="checkbox" checked=${!!f.is_required} disabled=${readOnly}
                                    onChange=${(e) => change(i, 'is_required', e.currentTarget.checked)}
                                    class="w-4 h-4 rounded border-gray-300 text-hodBlue" />
                                <span class="font-medium text-gray-700">Required</span>
                            </label>
                        </div>

                        ${['select', 'multiselect'].includes(f.type) ? html`
                            <input type="text" value=${(f.options || []).join(', ')}
                                placeholder="Options, separated by commas" disabled=${readOnly}
                                onInput=${(e) => change(i, 'options', e.currentTarget.value.split(',').map((s) => s.trim()).filter(Boolean))}
                                class="w-full px-3 py-2.5 rounded-xl border border-gray-200 outline-none focus:border-hodBlue bg-white text-sm" />` : null}
                    </div>`)}
                </div>`}

            ${dirty ? html`
                <div class="flex gap-2 mt-5">
                    <${Button} onClick=${save} loading=${busy}>Save questions<//>
                    <${Button} variant="ghost" onClick=${() => {
                        setFields((event.form_fields || []).map((f) => ({ ...f })));
                        setDirty(false);
                    }}>Discard<//>
                </div>` : null}
        <//>`;
}

export function RegistrationTab() {
    const event = current.value;
    const [overriding, setOverriding] = useState(false);

    if (!event) return html`<${Spinner} />`;

    const readOnly = !can('event.edit');
    const reg = event.registration || {};

    const save = async () => {
        const columns = {};
        for (const [key, value] of Object.entries(draft.value)) {
            if (!key.startsWith('settings:')) columns[key] = value;
        }

        const settings = collectSettings();

        try {
            const data = await studio('capacity_save', {
                id: event.id,
                fields: columns,
                expected_row_version: event.row_version,
            });
            let latest = data.event;

            if (Object.keys(settings).length) {
                const second = await studio('update_event', {
                    id: event.id, section: 'settings',
                    fields: { settings_json: settings },
                    expected_row_version: latest.row_version,
                });
                latest = second.event;
            }

            applyEvent(latest);
            toast('Registration settings saved.', 'success');
        } catch (e) {
            toast(e.message, 'error');
        }
    };

    const setOverride = async (mode) => {
        const note = mode === 'none' ? '' : (prompt('Why? (shown in the audit log)') ?? '');
        setOverriding(true);
        try {
            const data = await studio('override_set', { id: event.id, mode, note });
            applyEvent(data.event);
            toast(data.message, 'success');
        } catch (e) {
            toast(e.message, 'error');
        } finally {
            setOverriding(false);
        }
    };

    const capacity = fieldValue('online_capacity', reg.online_capacity);
    const unlimited = capacity === null || capacity === '';

    return html`
        <div class="space-y-6">

            <${Card} title="How many seats"
                     subtitle="Chara's decision was 120 through the link, plus about 30 walk-ins on the night.">
                <div class="grid sm:grid-cols-2 gap-5">
                    <${Field} label="Seats via the link" name="online_capacity"
                              hint="Leave empty for no limit — but tick the box below so we know you meant it.">
                        <${TextInput} name="online_capacity" type="number" disabled=${readOnly || unlimited}
                            value=${unlimited ? '' : capacity}
                            onInput=${(v) => setDraft('online_capacity', v === '' ? null : Number(v))} />
                    <//>

                    <div class="flex items-end pb-1">
                        <${Switch} label="No limit" disabled=${readOnly}
                            checked=${unlimited}
                            hint="Registration never closes itself."
                            onChange=${(on) => setDraft('online_capacity', on ? null : 120)} />
                    </div>
                </div>

                <div class="space-y-4 mt-6 pt-5 border-t border-gray-100">
                    <${Switch} label="Close registration automatically at capacity" disabled=${readOnly}
                        checked=${!!fieldValue('auto_close_at_capacity', reg.auto_close_at_capacity)}
                        hint="You can always reopen it from the override below."
                        onChange=${(v) => setDraft('auto_close_at_capacity', v)} />

                    <${Switch} label="Let people cancel their own seat" disabled=${readOnly}
                        checked=${!!fieldValue('self_cancel_enabled', reg.self_cancel_enabled)}
                        hint="A freed seat goes to the first person on the waitlist."
                        onChange=${(v) => setDraft('self_cancel_enabled', v)} />
                </div>
            <//>

            <${Card} title="Waitlist">
                <${Switch} label="Keep a waitlist once the seats are gone" disabled=${readOnly}
                    checked=${!!fieldValue('waitlist_enabled', reg.waitlist_enabled)}
                    onChange=${(v) => setDraft('waitlist_enabled', v)} />

                ${fieldValue('waitlist_enabled', reg.waitlist_enabled) ? html`
                <div class="grid sm:grid-cols-2 gap-5 mt-5">
                    <${Field} label="Waitlist limit" name="waitlist_capacity" hint="Empty = no limit.">
                        <${TextInput} name="waitlist_capacity" type="number" disabled=${readOnly}
                            value=${fieldValue('waitlist_capacity', reg.waitlist_capacity) ?? ''}
                            onInput=${(v) => setDraft('waitlist_capacity', v === '' ? null : Number(v))} />
                    <//>

                    <${Field} label="When a seat opens" name="waitlist_promotion">
                        <${Select} name="waitlist_promotion" disabled=${readOnly}
                            value=${fieldValue('waitlist_promotion', reg.waitlist_promotion)}
                            onChange=${(v) => setDraft('waitlist_promotion', v)}
                            options=${[
                                { value: 'auto_confirm', label: 'Promote the next person automatically' },
                                { value: 'manual', label: 'Let the crew choose' },
                            ]} />
                    <//>

                    <div class="sm:col-span-2">
                        <${Switch} label="Text them when they get a seat" disabled=${readOnly}
                            checked=${!!fieldValue('waitlist_notify_sms', reg.waitlist_notify_sms)}
                            hint="One SMS per promotion, through SMS Studio."
                            onChange=${(v) => setDraft('waitlist_notify_sms', v)} />
                    </div>
                </div>` : null}
            <//>

            <${Card} title="Walk-ins" subtitle="People who turn up on the night without registering.">
                <${Switch} label="Welcome walk-ins at the door" disabled=${readOnly}
                    checked=${!!fieldValue('walkin_enabled', reg.walkin_enabled)}
                    onChange=${(v) => setDraft('walkin_enabled', v)} />

                ${fieldValue('walkin_enabled', reg.walkin_enabled) ? html`
                <div class="grid sm:grid-cols-2 gap-5 mt-5">
                    <${Field} label="How many" name="walkin_capacity" hint="Chara planned for about 30.">
                        <${TextInput} name="walkin_capacity" type="number" disabled=${readOnly}
                            value=${fieldValue('walkin_capacity', reg.walkin_capacity) ?? ''}
                            onInput=${(v) => setDraft('walkin_capacity', v === '' ? null : Number(v))} />
                    <//>
                    <div class="flex items-end pb-1">
                        <${Switch} label="Hard cap" disabled=${readOnly}
                            checked=${!!fieldValue('walkin_hard_cap', reg.walkin_hard_cap)}
                            hint="On: the desk cannot go past it. Off: it is a guide."
                            onChange=${(v) => setDraft('walkin_hard_cap', v)} />
                    </div>
                </div>` : null}
            <//>

            <${Card} title="Seats left counter" subtitle="A gentle nudge, or a silent one.">
                <div class="grid sm:grid-cols-2 gap-5">
                    <${Field} label="Show the counter" name="seats_left_mode">
                        <${Select} name="seats_left_mode" disabled=${readOnly}
                            value=${fieldValue('seats_left_mode', reg.seats_left_mode)}
                            onChange=${(v) => setDraft('seats_left_mode', v)}
                            options=${[
                                { value: 'never', label: 'Never' },
                                { value: 'threshold', label: 'Once it is filling up' },
                                { value: 'always', label: 'Always' },
                            ]} />
                    <//>

                    ${fieldValue('seats_left_mode', reg.seats_left_mode) === 'threshold' ? html`
                    <${Field} label="From what % full" name="seats_left_threshold_pct">
                        <${TextInput} name="seats_left_threshold_pct" type="number" disabled=${readOnly}
                            value=${fieldValue('seats_left_threshold_pct', reg.seats_left_threshold_pct)}
                            onInput=${(v) => setDraft('seats_left_threshold_pct', Number(v))} />
                    <//>` : null}
                </div>
            <//>

            <${Card} title="The form" subtitle="Phone always comes first and is always required (M2).">
                <div class="grid sm:grid-cols-3 gap-5">
                    ${[['gender', 'Gender'], ['email', 'Email'], ['how_heard', 'How did you hear about us?']].map(([key, label]) => html`
                    <${Field} key=${key} label=${label} name=${'f_' + key}>
                        <${Select} name=${'f_' + key} disabled=${readOnly}
                            value=${settingsValue(event, `registration.fields.${key}`, 'optional')}
                            onChange=${(v) => setSetting(`registration.fields.${key}`, v)}
                            options=${[
                                { value: 'required', label: 'Required' },
                                { value: 'optional', label: 'Optional' },
                                { value: 'off', label: 'Do not ask' },
                            ]} />
                    <//>`)}
                </div>

                <div class="mt-5">
                    <${Switch} label="Ask about karaoke interest" disabled=${readOnly}
                        checked=${!!settingsValue(event, 'registration.fields.karaoke_interest', true)}
                        hint="A tick box at registration (M3). Songs are picked later, at check-in."
                        onChange=${(v) => setSetting('registration.fields.karaoke_interest', v)} />
                </div>
            <//>

            <${Card} title="Consent"
                     subtitle="Leadership must approve this wording before registration opens (§19.8).">
                <${Field} label="Consent mode" name="consent_mode">
                    <${Select} name="consent_mode" disabled=${readOnly}
                        value=${settingsValue(event, 'registration.consent_mode', 'required_followup')}
                        onChange=${(v) => setSetting('registration.consent_mode', v)}
                        options=${[
                            { value: 'required_followup', label: 'A required tick to allow follow-up' },
                            { value: 'notice_plus_optional_optin', label: 'Notice, plus an optional opt-in' },
                        ]} />
                <//>

                <div class="mt-5">
                    <${Field} label="Consent text" name="consent_text"
                              hint="Exactly what the guest agrees to. A hash of this text is stored with every registration.">
                        <${TextArea} name="consent_text" rows="3" maxLength="600"
                            value=${settingsValue(event, 'registration.consent_text', '')}
                            onInput=${(v) => setSetting('registration.consent_text', v)} />
                    <//>
                </div>

                ${settingsValue(event, 'registration.consent_mode', '') === 'notice_plus_optional_optin' ? html`
                <div class="mt-5">
                    <${Field} label="Opt-in text" name="optin_text">
                        <${TextArea} name="optin_text" rows="2" maxLength="600"
                            value=${settingsValue(event, 'registration.optin_text', '')}
                            onInput=${(v) => setSetting('registration.optin_text', v)} />
                    <//>
                </div>` : null}
            <//>

            ${can('event.capacity_override') ? html`
            <${Card} title="Override"
                     subtitle="Force registration open or closed, whatever the numbers say. Every change is audited.">
                <div class="flex flex-wrap items-center gap-3">
                    <span class="text-sm text-gray-600">
                        Currently: <strong class="text-gray-900">${
                            reg.reg_override === 'force_open' ? 'forced open'
                            : reg.reg_override === 'force_closed' ? 'paused'
                            : 'following the rules above'}</strong>
                    </span>
                    ${reg.reg_override_note ? html`<span class="text-xs text-gray-500">— ${reg.reg_override_note}</span>` : null}
                </div>

                <div class="flex flex-wrap gap-2 mt-4">
                    <${Button} variant="secondary" disabled=${overriding || reg.reg_override === 'force_open'}
                        onClick=${() => setOverride('force_open')}>Force open<//>
                    <${Button} variant="danger" disabled=${overriding || reg.reg_override === 'force_closed'}
                        onClick=${() => setOverride('force_closed')}>Pause registration<//>
                    ${reg.reg_override !== 'none' ? html`
                        <${Button} variant="ghost" disabled=${overriding}
                            onClick=${() => setOverride('none')}>Clear override<//>` : null}
                </div>
            <//>` : null}

            <${CustomQuestions} event=${event} readOnly=${readOnly} />

            <${SaveBar} dirty=${isDirty.value} saving=${saving.value}
                onSave=${save} onDiscard=${discardDraft} />
        </div>`;
}
