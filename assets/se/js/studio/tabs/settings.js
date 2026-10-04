// /assets/se/js/studio/tabs/settings.js
//
// Settings (guide §13.13): module-wide settings (§20.2) and
// Health → Schema, which runs se_schema_check() (Appendix A rule 3).
//
// Only a manager may change these; everyone else sees them read-only.

import { html } from '@se/core/html.js';
import { useState, useEffect } from 'preact/hooks';
import { studio } from '@se/core/api.js';
import { formatDateTime } from '@se/core/boot.js';
import { boot, current, toast } from '../state.js';
import { Card, Field, TextInput, TextArea, Button, Spinner } from '../ui.js';

const LABELS = {
    envision_department_id: ['Envision department ID', 'Which department grants Studio access. 3 unless the directory changes.'],
    default_brand_primary: ['Default primary colour', 'New events start with this.'],
    default_brand_secondary: ['Default secondary colour', ''],
    default_theme_preset: ['Default theme', ''],
    default_privacy_notice_md: ['Privacy notice', 'Markdown. {event} and {privacy_contact_email} are filled in per event.'],
    privacy_contact_email: ['Privacy contact email', 'Shown in the notice. An event cannot be published until this is set.'],
    retention_months_guest: ['Keep guest data (months)', 'After this, guests without consent are anonymised.'],
    ai_user_hourly_limit: ['AI calls per user per hour', ''],
    ai_event_daily_limit: ['AI calls per event per day', ''],
};

function SchemaHealth({ health }) {
    const schema = health?.schema;
    if (!schema) return null;

    const phaseA = schema.phase_a || [];
    const later = schema.later || [];

    return html`
        <div class="space-y-4">
            <div class=${'rounded-2xl px-4 py-3 text-sm font-semibold '
                + (phaseA.length === 0 ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-hodRed')}>
                ${phaseA.length === 0
                    ? 'Every table this version needs is present and up to date.'
                    : `${phaseA.length} problem${phaseA.length === 1 ? '' : 's'} — run php db/migrate.php on the server.`}
            </div>

            ${phaseA.length > 0 ? html`
            <ul class="space-y-2">
                ${phaseA.map((row, i) => html`
                <li key=${i} class="border border-red-100 bg-red-50/40 rounded-xl px-4 py-3">
                    <p class="text-sm font-bold text-hodRed font-mono">${row.table}</p>
                    <p class="text-xs text-gray-600 mt-1">${row.detail}</p>
                    ${row.columns ? html`
                        <p class="text-xs font-mono text-gray-500 mt-1">missing: ${row.columns.join(', ')}</p>` : null}
                    <p class="text-[10px] uppercase tracking-wide text-gray-400 mt-1">${row.migration}</p>
                </li>`)}
            </ul>` : null}

            <div>
                <p class="text-xs font-bold uppercase tracking-wide text-gray-400 mb-2">Later phases</p>
                <ul class="space-y-1.5">
                    ${later.map((p) => html`
                    <li key=${p.phase} class="flex items-center justify-between gap-3 text-sm">
                        <span class="text-gray-600">Phase ${p.phase} — ${p.total} tables</span>
                        <span class=${'text-xs font-bold px-2 py-0.5 rounded-lg '
                            + (p.status === 'ready' ? 'bg-emerald-50 text-emerald-700'
                               : p.status === 'partial' ? 'bg-red-50 text-hodRed'
                               : 'bg-gray-100 text-gray-500')}>
                            ${p.status === 'ready' ? 'ready'
                              : p.status === 'partial' ? `${p.missing.length} missing` : 'not migrated yet'}
                        </span>
                    </li>`)}
                </ul>
            </div>
        </div>`;
}

export function SettingsTab() {
    const event = current.value;
    const [settings, setSettings] = useState(null);
    const [edits, setEdits] = useState({});
    const [health, setHealth] = useState(null);
    const [busy, setBusy] = useState(false);
    const canEdit = boot.value?.is_manager;

    useEffect(() => {
        studio('settings_get', {})
            .then((d) => setSettings(d.settings || {}))
            .catch((e) => { toast(e.message, 'error'); setSettings({}); });

        if (event) {
            studio('health', { id: event.id })
                .then(setHealth)
                .catch(() => setHealth(null));
        }
    }, [event?.id]);

    if (settings === null) return html`<${Spinner} />`;

    const value = (key) => (edits[key] !== undefined ? edits[key] : (settings[key] ?? ''));
    const dirty = Object.keys(edits).length > 0;

    const save = async () => {
        setBusy(true);
        try {
            await studio('settings_save', { settings: edits });
            setSettings({ ...settings, ...edits });
            setEdits({});
            toast('Settings saved.', 'success');
        } catch (e) {
            toast(e.code === 'VALIDATION' ? Object.values(e.fields)[0] || e.message : e.message, 'error');
        } finally {
            setBusy(false);
        }
    };

    return html`
        <div class="space-y-6">

            <${Card} title="Module settings"
                     subtitle=${canEdit
                         ? 'These apply to every special event.'
                         : 'Read-only — only an administrator can change these.'}>
                <div class="space-y-5">
                    ${Object.entries(LABELS).map(([key, [label, hint]]) => html`
                    <${Field} key=${key} label=${label} name=${key} hint=${hint}>
                        ${key === 'default_privacy_notice_md'
                            ? html`<${TextArea} name=${key} rows="12" value=${value(key)}
                                      onInput=${(v) => canEdit && setEdits({ ...edits, [key]: v })} />`
                            : html`<${TextInput} name=${key} value=${value(key)} disabled=${!canEdit}
                                      type=${key === 'privacy_contact_email' ? 'email' : 'text'}
                                      onInput=${(v) => setEdits({ ...edits, [key]: v })} />`}
                    <//>`)}
                </div>

                ${canEdit && dirty ? html`
                    <div class="flex gap-2 mt-6">
                        <${Button} onClick=${save} loading=${busy}>Save settings<//>
                        <${Button} variant="ghost" onClick=${() => setEdits({})}>Discard<//>
                    </div>` : null}
            <//>

            <${Card} title="Health" subtitle="Check this after every deploy.">
                ${health === null ? html`<${Spinner} label="Checking…" />` : html`
                <div class="space-y-6">
                    <div class="grid sm:grid-cols-2 gap-3">
                        ${[
                            ['Module version', health.module_version],
                            ['Server time', formatDateTime(health.server_time)],
                            ['AI', health.ai?.available ? 'ready' : 'off'],
                            ['Token pepper', health.hash_pepper?.configured ? 'configured' : 'MISSING'],
                            ['Realtime driver', health.realtime?.driver],
                            ['WebP support', health.uploads?.gd_webp ? 'yes' : 'no (falls back to PNG/JPEG)'],
                            ['Uploads writable', health.uploads?.writable ? 'yes' : 'NO'],
                            ['Cron last ran', health.cron_last_run || 'never'],
                        ].map(([label, v]) => html`
                        <div key=${label} class="bg-gray-50 rounded-xl px-4 py-2.5">
                            <p class="text-[10px] uppercase tracking-wider text-gray-400 font-bold">${label}</p>
                            <p class=${'text-sm font-semibold ' + (String(v).match(/MISSING|^NO$/) ? 'text-hodRed' : 'text-gray-900')}>${v}</p>
                        </div>`)}
                    </div>

                    <div>
                        <h3 class="text-sm font-bold text-gray-900 mb-3">Schema</h3>
                        <${SchemaHealth} health=${health} />
                    </div>
                </div>`}
            <//>
        </div>`;
}
