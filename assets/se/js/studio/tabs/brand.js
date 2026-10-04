// /assets/se/js/studio/tabs/brand.js
//
// Brand (guide §13.13): paste-friendly hex inputs, a live contrast report,
// the AI palette grid, font pickers with a specimen, and asset pickers.
//
// The preview is computed in the browser by @se/core/theme.js, which is a
// twin of the PHP engine — so what a Producer sees here is exactly what the
// portal will render (§13.2).

import { html } from '@se/core/html.js';
import { useState, useMemo } from 'preact/hooks';
import { studio } from '@se/core/api.js';
import { themeDerive } from '@se/core/theme.js';
import {
    boot, current, draft, saving, isDirty, setDraft, discardDraft, fieldValue,
    saveSection, toast, applyEvent, can,
} from '../state.js';
import { Card, Field, Select, Button, SaveBar, HexInput, Spinner } from '../ui.js';

const RATIO_LABELS = {
    text_on_bg: 'Body text on the background',
    muted_on_bg: 'Muted text on the background',
    primary_on_bg: 'Primary colour on the background',
    secondary_on_bg: 'Secondary colour on the background',
    accent_on_bg: 'Accent colour on the background',
    on_primary_on_primary: 'Text on a primary button',
    on_secondary_on_secondary: 'Text on a secondary button',
    text_on_surface: 'Text on a card',
};

function ContrastReport({ contrast }) {
    const rows = Object.entries(contrast || {});
    const failing = rows.filter(([, r]) => !r.passes);

    return html`
        <div>
            <div class=${'rounded-2xl px-4 py-3 mb-3 text-sm font-semibold '
                + (failing.length === 0 ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-800')}>
                ${failing.length === 0
                    ? 'Every pair meets WCAG 2.2 AA.'
                    : `${failing.length} pair${failing.length === 1 ? '' : 's'} below AA — the engine has already lightened what it can.`}
            </div>
            <ul class="space-y-1.5">
                ${rows.map(([key, r]) => html`
                <li key=${key} class="flex items-center justify-between gap-3 text-sm py-1">
                    <span class="text-gray-600">${RATIO_LABELS[key] || key}</span>
                    <span class=${'font-mono text-xs font-bold px-2 py-0.5 rounded-lg shrink-0 '
                        + (r.passes ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-100 text-amber-800')}>
                        ${r.ratio.toFixed(2)}:1 ${r.passes ? '✓' : `needs ${r.required}`}
                    </span>
                </li>`)}
            </ul>
        </div>`;
}

/** A miniature of the portal hero, the stage title and team chips (§13.2.3). */
function ThemeMini({ tokens, title }) {
    const s = (name) => tokens[name];

    return html`
        <div class="rounded-2xl overflow-hidden border border-gray-200">
            <div class="p-5" style=${{ background: s('--se-bg'), color: s('--se-text') }}>
                <p class="text-[9px] uppercase tracking-widest font-bold" style=${{ color: s('--se-text-muted') }}>
                    ${title}
                </p>
                <p class="text-2xl font-display font-bold mt-1 leading-none">
                    Chara <span style=${{ color: s('--se-secondary') }}>2026</span>
                </p>
                <p class="text-xs mt-2" style=${{ color: s('--se-text-muted') }}>A night of joy</p>

                <div class="flex gap-2 mt-4">
                    <span class="px-3 py-1.5 rounded-full text-xs font-bold"
                          style=${{ background: s('--se-primary'), color: s('--se-on-primary') }}>Register</span>
                    <span class="px-3 py-1.5 rounded-full text-xs font-bold"
                          style=${{ background: s('--se-secondary'), color: s('--se-on-secondary') }}>Check in</span>
                    <span class="px-3 py-1.5 rounded-full text-xs font-bold border"
                          style=${{ borderColor: s('--se-accent'), color: s('--se-accent') }}>Games</span>
                </div>

                <div class="mt-4 rounded-xl p-3" style=${{ background: s('--se-surface'), border: `1px solid ${s('--se-border')}` }}>
                    <p class="text-[11px]" style=${{ color: s('--se-text-muted') }}>Seats left</p>
                    <p class="text-lg font-display font-bold leading-none">37</p>
                </div>
            </div>
        </div>`;
}

export function BrandTab() {
    const event = current.value;
    const [palettes, setPalettes] = useState(null);
    const [askingAi, setAskingAi] = useState(false);

    if (!event) return html`<${Spinner} />`;

    const readOnly = !can('event.edit');
    const aiOn = boot.value?.ai?.available;

    const primary = fieldValue('brand_primary', event.brand.primary);
    const secondary = fieldValue('brand_secondary', event.brand.secondary);
    const accent = fieldValue('brand_accent', event.brand.accent || '');
    const fontDisplay = fieldValue('font_display', event.brand.font_display);
    const fontBody = fieldValue('font_body', event.brand.font_body);

    // Live preview, recomputed on every keystroke by the JS twin of the PHP
    // engine. Invalid hex falls back to what is saved, so the mini never
    // flickers to black while someone is typing.
    const preview = useMemo(() => {
        const ok = (v) => /^#[0-9A-Fa-f]{6}$/.test(v || '');
        return themeDerive(
            ok(primary) ? primary : event.brand.primary,
            ok(secondary) ? secondary : event.brand.secondary,
            ok(accent) ? accent : null,
            event.brand.preset
        );
    }, [primary, secondary, accent, event.brand.preset]);

    const suggest = async () => {
        setAskingAi(true);
        try {
            const data = await studio('palette_suggest', {
                id: event.id, primary, secondary,
                mood: ['joy', 'night', 'karaoke'],
            });
            setPalettes(data.palettes || []);
            toast(`${data.palettes.length} palettes ready — each keeps your two colours.`, 'success');
        } catch (e) {
            toast(e.message, 'error', 7000);
        } finally {
            setAskingAi(false);
        }
    };

    const applyPalette = async (palette) => {
        try {
            const data = await studio('apply_palette', {
                id: event.id, palette,
                expected_row_version: event.row_version,
            });
            applyEvent(data.event);
            setPalettes(null);
            toast('Palette applied.', 'success');
        } catch (e) {
            toast(e.message, 'error');
        }
    };

    const fontPairs = boot.value?.font_pairs || {};

    return html`
        <div class="space-y-6">

            <div class="grid lg:grid-cols-2 gap-6">
                <${Card} title="Colours" subtitle="Paste your hex codes. Everything else is derived from them.">
                    <div class="space-y-5">
                        <${HexInput} name="brand_primary" label="Primary" value=${primary}
                            placeholder="#1D356A"
                            hint="The dominant colour. Buttons, glow, the background tint."
                            onInput=${readOnly ? undefined : (v) => setDraft('brand_primary', v)} />

                        <${HexInput} name="brand_secondary" label="Secondary" value=${secondary}
                            placeholder="#D11920"
                            hint="The contrast colour. The edition number, the focus ring."
                            onInput=${readOnly ? undefined : (v) => setDraft('brand_secondary', v)} />

                        <${HexInput} name="brand_accent" label="Accent (optional)" value=${accent}
                            placeholder=${'derived: ' + (preview.tokens['--se-accent'] || '')}
                            hint="Leave empty and one is derived automatically."
                            onInput=${readOnly ? undefined : (v) => setDraft('brand_accent', v)} />
                    </div>

                    ${!readOnly ? html`
                    <div class="mt-6 pt-5 border-t border-gray-100">
                        ${aiOn ? html`
                            <${Button} variant="secondary" onClick=${suggest} loading=${askingAi}>
                                ✨ Suggest palettes
                            <//>
                            <p class="text-xs text-gray-500 mt-2">
                                Four ideas for the accent and the team colours. Your two colours never change.
                            </p>`
                        : html`
                            <p class="text-xs text-gray-500">
                                ${boot.value?.ai?.reason || 'AI suggestions are off.'}
                            </p>`}
                    </div>` : null}
                <//>

                <${Card} title="Preview" subtitle="Exactly what the portal will render.">
                    <${ThemeMini} tokens=${preview.tokens} title="PORTAL HERO" />
                    <div class="mt-5">
                        <${ContrastReport} contrast=${preview.contrast} />
                    </div>
                <//>
            </div>

            ${palettes ? html`
            <${Card} title="AI palette suggestions"
                     subtitle="Your primary and secondary are kept exactly; only the accent and team ideas come from the model. Every contrast figure below was recomputed on the server."
                     actions=${html`<${Button} variant="ghost" onClick=${() => setPalettes(null)}>Dismiss<//>`}>
                <div class="grid sm:grid-cols-2 gap-5">
                    ${palettes.map((p, i) => html`
                    <div key=${i} class="border border-gray-200 rounded-2xl overflow-hidden">
                        <${ThemeMini} tokens=${p.tokens} title=${p.name} />
                        <div class="p-4">
                            <p class="text-sm font-bold text-gray-900">${p.name}</p>
                            <p class="text-xs text-gray-500 mt-1">${p.rationale}</p>

                            <div class="flex items-center gap-2 mt-3">
                                <span class="text-xs font-mono text-gray-600">${p.accent}</span>
                                ${(p.team_suggestions || []).map((t, j) => html`
                                    <span key=${j} class="w-5 h-5 rounded-full border border-gray-200"
                                          style=${{ background: t }} title=${t}></span>`)}
                            </div>

                            <div class="mt-4">
                                <${Button} onClick=${() => applyPalette(p)} disabled=${readOnly}>Apply accent<//>
                            </div>
                        </div>
                    </div>`)}
                </div>
            <//>` : null}

            <${Card} title="Fonts" subtitle="Two Google Fonts: one for headlines, one for text.">
                <div class="grid sm:grid-cols-2 gap-5">
                    <${Field} label="Display font" name="font_display">
                        <${Select} name="font_display" value=${fontDisplay} disabled=${readOnly}
                            onChange=${(v) => {
                                setDraft('font_display', v);
                                if (fontPairs[v]) setDraft('font_body', fontPairs[v]);
                            }}
                            options=${Object.keys(fontPairs).map((f) => ({ value: f, label: f }))} />
                    <//>
                    <${Field} label="Body font" name="font_body">
                        <${Select} name="font_body" value=${fontBody} disabled=${readOnly}
                            onChange=${(v) => setDraft('font_body', v)}
                            options=${[...new Set(Object.values(fontPairs))].map((f) => ({ value: f, label: f }))} />
                    <//>
                </div>

                <div class="mt-5 rounded-2xl bg-gray-50 p-5">
                    <p class="text-[10px] uppercase tracking-widest text-gray-400 font-bold mb-2">Specimen</p>
                    <p class="text-3xl font-bold text-gray-900 leading-tight"
                       style=${{ fontFamily: `"${fontDisplay}", system-ui, sans-serif` }}>
                        Chara 2026
                    </p>
                    <p class="text-sm text-gray-600 mt-2"
                       style=${{ fontFamily: `"${fontBody}", system-ui, sans-serif` }}>
                        A night of karaoke, games and pure joy. Register in twenty seconds.
                    </p>
                </div>
            <//>

            <${Card} title="Images"
                     subtitle="Pick from what you have uploaded on the Assets tab.">
                <div class="grid sm:grid-cols-2 gap-5">
                    ${[
                        ['hero_asset_id', 'Hero image', 'hero', 'The big background on the portal.'],
                        ['logo_asset_id', 'Logo', 'logo', 'Shown on cards and posters.'],
                        ['og_asset_id', 'Link card', 'og_card', 'The preview when the link is shared.'],
                    ].map(([field, label, role, hint]) => {
                        const options = [{ value: '', label: '— none —' }].concat(
                            (event.assets || [])
                                .filter((a) => a.role === role || a.kind === 'image')
                                .map((a) => ({ value: String(a.id), label: `${a.title || a.role} (${a.width}×${a.height})` }))
                        );
                        return html`
                        <${Field} key=${field} label=${label} name=${field} hint=${hint}>
                            <${Select} name=${field} disabled=${readOnly}
                                value=${String(fieldValue(field, event.asset_refs?.[role === 'og_card' ? 'og' : role] ?? '') ?? '')}
                                onChange=${(v) => setDraft(field, v === '' ? null : Number(v))}
                                options=${options} />
                        <//>`;
                    })}
                </div>
                ${(event.assets || []).length === 0 ? html`
                    <p class="text-sm text-gray-500 mt-4">
                        Nothing uploaded yet — add images on the Assets tab first.
                    </p>` : null}
            <//>

            <${SaveBar} dirty=${isDirty.value} saving=${saving.value}
                onSave=${() => saveSection('brand', draft.value)} onDiscard=${discardDraft} />
        </div>`;
}
