// /assets/se/js/studio/tabs/chapters.js
//
// Chapters (guide §13.3 S3): the content behind "The night, chapter by
// chapter" on the public portal.
//
// A chapter is a title, a blurb of 25 words or fewer, one animated icon from
// the catalogue and an optional photograph behind the card. Producers add,
// retire, rewrite and reorder them here; the portal renders whatever this
// tab saved, in this order.
//
// Two things worth knowing while reading this file:
//
//   * The icon artwork is the SERVER's SVG, delivered in `chapters_get` and
//     injected with dangerouslySetInnerHTML. It is our own constant markup
//     from includes/special_events/chapters.php — never user input — and
//     sharing it is what stops the Studio preview from drifting away from
//     the real portal.
//   * The background image goes through the normal asset pipeline
//     (`asset_upload`, role `background`): magic-byte type detection, EXIF
//     stripped by the re-encode, responsive variants generated. There is no
//     second, weaker uploader here.

import { html } from '@se/core/html.js';
import { useState, useEffect } from 'preact/hooks';
import { studio, studioUpload } from '@se/core/api.js';
import { current, toast, can, refreshEvent } from '../state.js';
import { Card, Button, Spinner, EmptyState, Field, TextInput, TextArea, Switch, Select } from '../ui.js';

const FEATURE_LABELS = {
    karaoke: 'Karaoke',
    games: 'Games',
    teams: 'Teams',
};

const BLURB_WORDS = 25;

function words(text) {
    return (text || '').trim().split(/\s+/).filter(Boolean).length;
}

/** Server-rendered icon markup, on the portal's dark card. */
function IconArt({ svg, size = 'h-24' }) {
    // The injected <svg> has no width/height attributes (the portal sizes it
    // in se.css, which the Studio does not load), so it is constrained here.
    return html`
        <span class=${'block text-amber-200/90 [&>svg]:w-full [&>svg]:h-full [&>svg]:max-h-full '
            + '[&_.se-orbit]:w-full [&_.se-orbit]:h-full ' + size}
              dangerouslySetInnerHTML=${{ __html: svg }}></span>`;
}

/**
 * The card as the portal paints it: photo, then the fixed scrim, then the
 * icon on top. The scrim is not optional — it is what keeps the icon and its
 * animation readable over somebody's phone snap of last year's night.
 */
function ArtPreview({ icon, bgPath, icons }) {
    const art = icons.find((i) => i.key === icon);

    return html`
        <div class="relative overflow-hidden rounded-2xl bg-[#140c08] aspect-[4/3] grid place-items-center">
            ${bgPath ? html`
                <img src=${bgPath} alt="" class="absolute inset-0 w-full h-full object-cover opacity-80 blur-[1px] saturate-[0.85]" />
                <span class="absolute inset-0 bg-gradient-to-t from-black/85 via-black/55 to-black/40"></span>` : null}
            <span class="relative drop-shadow-[0_2px_12px_rgba(0,0,0,0.65)]">
                <${IconArt} svg=${art?.svg || ''} />
            </span>
        </div>`;
}

// --------------------------------------------------------------------------
// The icon picker
// --------------------------------------------------------------------------

function IconPicker({ icons, value, onPick }) {
    return html`
        <div class="grid grid-cols-3 sm:grid-cols-5 gap-2">
            ${icons.map((icon) => html`
                <button type="button" key=${icon.key} onClick=${() => onPick(icon.key)}
                    title=${icon.label + ' — ' + icon.hint}
                    aria-pressed=${value === icon.key ? 'true' : 'false'}
                    class=${'rounded-xl p-2 border-2 transition-colors bg-[#140c08] '
                        + (value === icon.key ? 'border-hodRed' : 'border-transparent hover:border-gray-300')}>
                    <${IconArt} svg=${icon.svg} size="h-12" />
                    <span class="block text-[10px] font-semibold text-gray-300 mt-1 truncate">${icon.label}</span>
                </button>`)}
        </div>`;
}

// --------------------------------------------------------------------------
// Background: upload one, or reuse an image already in the kit
// --------------------------------------------------------------------------

function BackgroundPicker({ eventId, value, images, onPick, disabled }) {
    const [busy, setBusy] = useState(false);
    const [alt, setAlt] = useState('');

    const upload = async (file) => {
        if (!file) return;
        setBusy(true);
        try {
            const data = await studioUpload('asset_upload', {
                id: eventId,
                role: 'background',
                title: 'Chapter background',
                alt_text: alt || 'Chapter background',
            }, { file });
            onPick(data.asset.id, data.asset.path);
            toast('Image uploaded and set as the background.', 'success');
        } catch (e) {
            toast(e.message, 'error', 7000);
        } finally {
            setBusy(false);
        }
    };

    return html`
        <div class="space-y-3">
            <${Field} label="Upload an image" name="chapter_bg"
                hint="JPEG, PNG or WebP. It is re-encoded on the server (location data stripped) and resized for phones. Landscape, 1200px wide or more, looks best.">
                <input type="file" accept="image/*" disabled=${disabled || busy}
                    onChange=${(e) => upload(e.currentTarget.files?.[0])}
                    class="w-full text-sm text-gray-600 file:mr-3 file:px-4 file:py-2 file:rounded-xl file:border-0 file:bg-blue-50 file:text-hodBlue file:font-semibold file:cursor-pointer" />
            <//>

            ${busy ? html`<p class="text-xs font-semibold text-gray-500">Uploading…</p>` : null}

            ${images.length ? html`
                <div>
                    <p class="text-xs font-semibold text-gray-700 mb-1.5">…or reuse something already in the kit</p>
                    <div class="flex flex-wrap gap-2">
                        <button type="button" onClick=${() => onPick(null, null)} disabled=${disabled}
                            class=${'w-16 h-12 rounded-lg border-2 text-[10px] font-bold text-gray-500 '
                                + (!value ? 'border-hodRed' : 'border-gray-200 hover:border-gray-400')}>None</button>
                        ${images.map((img) => html`
                            <button type="button" key=${img.id} onClick=${() => onPick(img.id, img.path)} disabled=${disabled}
                                title=${img.title || img.role}
                                class=${'w-16 h-12 rounded-lg overflow-hidden border-2 '
                                    + (value === img.id ? 'border-hodRed' : 'border-gray-200 hover:border-gray-400')}>
                                <img src=${img.path} alt="" class="w-full h-full object-cover" />
                            </button>`)}
                    </div>
                </div>` : null}
        </div>`;
}

// --------------------------------------------------------------------------
// One chapter
// --------------------------------------------------------------------------

function ChapterRow({ chapter, index, total, data, readOnly, onData, eventId }) {
    const [open, setOpen] = useState(false);
    const [busy, setBusy] = useState(false);
    const [form, setForm] = useState(chapter);

    useEffect(() => { setForm(chapter); }, [chapter.id, chapter.title, chapter.icon, chapter.bg_asset_id, chapter.blurb]);

    const call = async (action, payload, message) => {
        setBusy(true);
        try {
            const next = await studio(action, { id: eventId, ...payload });
            onData(next);
            if (message) toast(message, 'success');
            return true;
        } catch (e) {
            toast(e.message, 'error', 6000);
            return false;
        } finally {
            setBusy(false);
        }
    };

    const save = async () => {
        const ok = await call('chapter_save', {
            chapter_id: chapter.id,
            title: form.title,
            blurb: form.blurb,
            icon: form.icon,
            feature: form.feature || '',
            is_active: form.is_active,
            bg_asset_id: form.bg_asset_id,
        }, 'Chapter saved.');
        if (ok) setOpen(false);
    };

    const remove = async () => {
        if (!confirm(`Remove "${chapter.title}" from the portal? The image stays in the asset kit.`)) return;
        await call('chapter_delete', { chapter_id: chapter.id }, 'Chapter removed.');
    };

    const wordCount = words(form.blurb);
    // A just-uploaded image is not in data.images until the next save, so the
    // picker hands its path straight back for the preview.
    const bgPath = form.bg_path_preview
        || (form.bg_asset_id === chapter.bg_asset_id
            ? chapter.bg_path
            : (data.images.find((i) => i.id === form.bg_asset_id)?.path || null));

    return html`
        <li class="rounded-2xl border border-gray-200 bg-white overflow-hidden">
            <div class="flex items-start gap-4 p-4">
                <div class="flex flex-col gap-1 pt-1">
                    <button type="button" aria-label="Move up" title="Move up"
                        disabled=${readOnly || busy || index === 0}
                        onClick=${() => call('chapter_move', { chapter_id: chapter.id, direction: 'up' }, null)}
                        class="w-8 h-8 rounded-lg border border-gray-200 text-gray-600 hover:border-hodBlue hover:text-hodBlue disabled:opacity-30">↑</button>
                    <button type="button" aria-label="Move down" title="Move down"
                        disabled=${readOnly || busy || index === total - 1}
                        onClick=${() => call('chapter_move', { chapter_id: chapter.id, direction: 'down' }, null)}
                        class="w-8 h-8 rounded-lg border border-gray-200 text-gray-600 hover:border-hodBlue hover:text-hodBlue disabled:opacity-30">↓</button>
                </div>

                <div class="w-24 shrink-0">
                    <${ArtPreview} icon=${chapter.icon} bgPath=${chapter.bg_path} icons=${data.icons} />
                </div>

                <div class="min-w-0 flex-1">
                    <p class="text-xs font-bold text-gray-400">
                        ${String(index + 1).padStart(2, '0')}
                        ${!chapter.is_active ? html`<span class="ml-2 text-amber-600">Hidden</span>` : null}
                    </p>
                    <h3 class="font-display font-bold text-gray-900 truncate">${chapter.title}</h3>
                    <p class="text-sm text-gray-500 line-clamp-2">${chapter.blurb}</p>
                    <p class="text-xs text-gray-400 mt-1">
                        ${chapter.icon_label}${chapter.bg_path ? ' · background image' : ''}
                        ${chapter.feature ? ` · ${FEATURE_LABELS[chapter.feature]}` : ''}
                    </p>
                </div>

                ${!readOnly ? html`
                    <div class="flex flex-col items-end gap-2">
                        <button type="button" onClick=${() => setOpen(!open)}
                            class="text-xs font-bold text-hodBlue hover:underline">${open ? 'Close' : 'Edit'}</button>
                        <button type="button" onClick=${remove} disabled=${busy}
                            class="text-xs font-bold text-hodRed hover:underline">Remove</button>
                    </div>` : null}
            </div>

            ${open && !readOnly ? html`
            <div class="border-t border-gray-100 p-5 space-y-5 bg-gray-50/60">
                <div class="grid lg:grid-cols-2 gap-5">
                    <div class="space-y-4">
                        <${Field} label="Title" name="title" required>
                            <${TextInput} name="title" value=${form.title} maxLength=${120}
                                onInput=${(v) => setForm({ ...form, title: v })} />
                        <//>

                        <${Field} label="Blurb" name="blurb"
                            hint=${`${wordCount} word${wordCount === 1 ? '' : 's'}${wordCount > BLURB_WORDS ? ' — over 25, it will start to crowd the card on a phone.' : '. Keep it to 25 or fewer.'}`}>
                            <${TextArea} name="blurb" rows=${3} value=${form.blurb} maxLength=${400}
                                onInput=${(v) => setForm({ ...form, blurb: v })} />
                        <//>

                        <${Switch} label="Show this chapter on the portal"
                            hint="Hiding it keeps the wording and the image for next time."
                            checked=${form.is_active}
                            onChange=${(v) => setForm({ ...form, is_active: v })} />

                        <${Field} label="Linked feature" name="feature"
                            hint="Only used to show the feature's own switch beside this chapter.">
                            <${Select} name="feature" value=${form.feature || ''}
                                onChange=${(v) => setForm({ ...form, feature: v })}
                                options=${[{ value: '', label: 'None' }]
                                    .concat(Object.entries(FEATURE_LABELS).map(([v, label]) => ({ value: v, label })))} />
                        <//>
                    </div>

                    <div class="space-y-4">
                        <${Field} label="Icon" name="icon"
                            hint=${data.icons.find((i) => i.key === form.icon)?.hint || 'Each icon brings its own animation.'}>
                            <${IconPicker} icons=${data.icons} value=${form.icon}
                                onPick=${(key) => setForm({ ...form, icon: key })} />
                        <//>

                        <${BackgroundPicker} eventId=${eventId} value=${form.bg_asset_id} images=${data.images}
                            onPick=${(id, path) => setForm({ ...form, bg_asset_id: id, bg_path_preview: path })} />

                        <div>
                            <p class="text-xs font-semibold text-gray-700 mb-1.5">How the card will look</p>
                            <div class="max-w-[16rem]">
                                <${ArtPreview} icon=${form.icon} bgPath=${bgPath} icons=${data.icons} />
                            </div>
                            <p class="text-xs text-gray-500 mt-1.5">
                                The dark overlay is always applied, so the icon and its animation stay readable on any photo.
                            </p>
                        </div>
                    </div>
                </div>

                <div class="flex flex-wrap gap-2">
                    <${Button} onClick=${save} loading=${busy}>Save chapter<//>
                    <${Button} variant="ghost" onClick=${() => { setForm(chapter); setOpen(false); }}>Cancel<//>
                </div>
            </div>` : null}
        </li>`;
}

// --------------------------------------------------------------------------
// Add a chapter
// --------------------------------------------------------------------------

function AddChapter({ eventId, data, onData }) {
    const [open, setOpen] = useState(false);
    const [busy, setBusy] = useState(false);
    const [form, setForm] = useState({ title: '', blurb: '', icon: 'spark', feature: '', bg_asset_id: null });

    const add = async () => {
        setBusy(true);
        try {
            const next = await studio('chapter_save', {
                id: eventId,
                chapter_id: 0,
                title: form.title,
                blurb: form.blurb,
                icon: form.icon,
                feature: form.feature || '',
                is_active: true,
                bg_asset_id: form.bg_asset_id,
            });
            onData(next);
            setForm({ title: '', blurb: '', icon: 'spark', feature: '', bg_asset_id: null });
            setOpen(false);
            toast('Chapter added at the end — use ↑ to move it.', 'success');
        } catch (e) {
            toast(e.message, 'error', 6000);
        } finally {
            setBusy(false);
        }
    };

    if (!open) {
        return html`<${Button} variant="secondary" onClick=${() => setOpen(true)}>+ Add a chapter<//>`;
    }

    const bgPath = form.bg_path_preview || data.images.find((i) => i.id === form.bg_asset_id)?.path || null;

    return html`
        <${Card} title="New chapter">
            <div class="grid lg:grid-cols-2 gap-5">
                <div class="space-y-4">
                    <${Field} label="Title" name="title" required>
                        <${TextInput} name="title" value=${form.title} maxLength=${120}
                            onInput=${(v) => setForm({ ...form, title: v })} />
                    <//>
                    <${Field} label="Blurb" name="blurb" hint="25 words or fewer.">
                        <${TextArea} name="blurb" rows=${3} value=${form.blurb} maxLength=${400}
                            onInput=${(v) => setForm({ ...form, blurb: v })} />
                    <//>
                    <${BackgroundPicker} eventId=${eventId} value=${form.bg_asset_id} images=${data.images}
                        onPick=${(id, path) => setForm({ ...form, bg_asset_id: id, bg_path_preview: path })} />
                </div>
                <div class="space-y-4">
                    <${Field} label="Icon" name="icon"
                        hint=${data.icons.find((i) => i.key === form.icon)?.hint || ''}>
                        <${IconPicker} icons=${data.icons} value=${form.icon}
                            onPick=${(key) => setForm({ ...form, icon: key })} />
                    <//>
                    <div class="max-w-[16rem]">
                        <${ArtPreview} icon=${form.icon} bgPath=${bgPath} icons=${data.icons} />
                    </div>
                </div>
            </div>
            <div class="flex gap-2 mt-5">
                <${Button} onClick=${add} loading=${busy}>Add chapter<//>
                <${Button} variant="ghost" onClick=${() => setOpen(false)}>Cancel<//>
            </div>
        <//>`;
}

// --------------------------------------------------------------------------
// The tab
// --------------------------------------------------------------------------

export function ChaptersTab() {
    const event = current.value;
    const [data, setData] = useState(null);
    const [error, setError] = useState(null);
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        let alive = true;
        (async () => {
            try {
                const next = await studio('chapters_get', { id: event.id });
                if (alive) setData(next);
            } catch (e) {
                if (alive) setError(e.message);
            }
        })();
        return () => { alive = false; };
    }, [event.id]);

    if (error) {
        return html`<${Card} title="Chapters">
            <${EmptyState} title="Not available yet" message=${error} />
        <//>`;
    }
    if (!data) return html`<${Spinner} label="Loading chapters…" />`;

    const readOnly = !can('event.edit');
    const chapters = data.chapters || [];
    const hidden = chapters.filter((c) => !c.is_active).length;

    const toggleFeature = async (feature, enabled) => {
        setBusy(true);
        try {
            const result = await studio('chapter_feature_toggle', { id: event.id, feature, enabled });
            setData(result);
            refreshEvent(result.event);
            toast(`${FEATURE_LABELS[feature]} ${enabled ? 'switched on' : 'switched off'} for this event.`, 'success');
        } catch (e) {
            toast(e.message, 'error', 6000);
        } finally {
            setBusy(false);
        }
    };

    const reset = async () => {
        if (!confirm('Replace every chapter with the four defaults? Anything you have written here is lost.')) return;
        setBusy(true);
        try {
            setData(await studio('chapters_reset', { id: event.id }));
            toast('The four default chapters are back.', 'success');
        } catch (e) {
            toast(e.message, 'error', 6000);
        } finally {
            setBusy(false);
        }
    };

    return html`
        <div class="space-y-6">
            <${Card} title="The night, chapter by chapter"
                subtitle=${`${chapters.length} chapter${chapters.length === 1 ? '' : 's'}`
                    + (hidden ? `, ${hidden} hidden` : '')
                    + `. Up to ${data.max}. The order here is the order on the portal.`}
                actions=${!readOnly ? html`
                    <${Button} variant="ghost" onClick=${reset} loading=${busy}>Restore defaults<//>` : null}>

                ${chapters.length === 0
                    ? html`<${EmptyState} title="No chapters yet"
                            message="Add one, or restore the four defaults, and the section appears on the portal." />`
                    : html`<ol class="space-y-3">
                        ${chapters.map((chapter, i) => html`
                            <${ChapterRow} key=${chapter.id} chapter=${chapter} index=${i} total=${chapters.length}
                                data=${data} readOnly=${readOnly} eventId=${event.id}
                                onData=${setData} />`)}
                    </ol>`}

                ${!readOnly ? html`<div class="mt-5">
                    <${AddChapter} eventId=${event.id} data=${data} onData=${setData} />
                </div>` : null}
            <//>

            <${Card} title="Features"
                subtitle="Whether the night actually runs karaoke, games and teams. Separate from whether their chapters appear above.">
                <div class="grid sm:grid-cols-3 gap-4">
                    ${Object.entries(FEATURE_LABELS).map(([key, label]) => html`
                        <${Switch} key=${key} label=${label}
                            hint=${data.features[key] ? 'On for this event.' : 'Off — the rest of the Studio hides it too.'}
                            checked=${!!data.features[key]} disabled=${readOnly || busy}
                            onChange=${(v) => toggleFeature(key, v)} />`)}
                </div>
            <//>
        </div>`;
}
