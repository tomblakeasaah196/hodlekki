// /assets/se/js/studio/tabs/assets.js
//
// Assets (guide §13.13, §14.1): the brand asset kit. Every image needs alt
// text before it can be saved (§13.14), and the server decides the real file
// type from magic bytes — the browser's idea of it is never trusted (§19.6).

import { html } from '@se/core/html.js';
import { useState } from 'preact/hooks';
import { studioUpload, studio } from '@se/core/api.js';
import { boot, current, toast, applyEvent, can } from '../state.js';
import { Card, Field, TextInput, Select, Button, Spinner, EmptyState } from '../ui.js';

const ROLE_LABELS = {
    logo: 'Logo', wordmark: 'Wordmark', hero: 'Hero image', hero_video: 'Hero video',
    hero_poster: 'Video poster', flyer: 'Flyer', illustration: 'Illustration',
    background: 'Background', gallery: 'Gallery image', sponsor: 'Sponsor',
    sfx: 'Sound effect', music: 'Music', lottie: 'Lottie animation',
    template: 'Card template (SVG)', program_source: 'Programme source (AI input)',
    songs_source: 'Song list source (AI input)', og_card: 'Link card',
    story: 'Story format', square: 'Square format', portrait: 'Portrait format',
    projector: 'Projector format', poster_a4: 'Poster A4', poster_a3: 'Poster A3',
};

function bytes(n) {
    if (n >= 1048576) return (n / 1048576).toFixed(1) + ' MB';
    if (n >= 1024) return Math.round(n / 1024) + ' KB';
    return n + ' bytes';
}

function AssetCard({ asset, readOnly, onChanged }) {
    const [editing, setEditing] = useState(false);
    const [title, setTitle] = useState(asset.title || '');
    const [alt, setAlt] = useState(asset.alt_text || '');
    const [busy, setBusy] = useState(false);

    // Asset actions name the ASSET in `id` (§12.5); the server derives the
    // event from it and checks the capability there.
    const commit = async () => {
        setBusy(true);
        try {
            const data = await studio('asset_update', { id: asset.id, title, alt_text: alt });
            onChanged(data.asset);
            setEditing(false);
            toast('Saved.', 'success');
        } catch (e) {
            toast(e.message, 'error');
        } finally {
            setBusy(false);
        }
    };

    const remove = async () => {
        if (!confirm('Remove this file from the event? Anything pointing at it is cleared.')) return;
        setBusy(true);
        try {
            const data = await studio('asset_delete', { id: asset.id });
            applyEvent(data.event);
            onChanged(null, data.assets);
            toast('File removed.', 'success');
        } catch (e) {
            toast(e.message, 'error');
        } finally {
            setBusy(false);
        }
    };

    const isImage = asset.kind === 'image';
    const missingAlt = isImage && !asset.alt_text;

    return html`
        <div class="border border-gray-200 rounded-2xl overflow-hidden bg-white">
            <div class="aspect-video bg-gray-50 flex items-center justify-center overflow-hidden">
                ${isImage
                    ? html`<img src=${asset.path} alt=${asset.alt_text || ''} loading="lazy"
                                class="w-full h-full object-cover" />`
                    : html`<span class="text-xs font-bold uppercase tracking-wide text-gray-400">${asset.kind}</span>`}
            </div>

            <div class="p-4">
                <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0">
                        <p class="text-sm font-bold text-gray-900 truncate">${asset.title || ROLE_LABELS[asset.role] || asset.role}</p>
                        <p class="text-xs text-gray-500">
                            ${ROLE_LABELS[asset.role] || asset.role} · ${bytes(asset.bytes)}
                            ${asset.width ? ` · ${asset.width}×${asset.height}` : ''}
                        </p>
                    </div>
                </div>

                ${missingAlt ? html`
                    <p class="text-xs font-semibold text-amber-600 mt-2">Needs alt text.</p>` : null}

                ${asset.meta?.tokens ? html`
                    <p class="text-xs text-gray-500 mt-2">
                        Template tokens: ${Object.entries(asset.meta.tokens)
                            .filter(([, v]) => v.length).map(([k, v]) => `${k}(${v.length})`).join(', ') || 'none recognised'}
                    </p>` : null}

                ${editing ? html`
                <div class="space-y-3 mt-3">
                    <input type="text" value=${title} placeholder="Title" maxLength="160"
                        onInput=${(e) => setTitle(e.currentTarget.value)}
                        class="w-full px-3 py-2 rounded-xl border border-gray-200 outline-none focus:border-hodBlue text-sm" />
                    <input type="text" value=${alt} placeholder="Describe the image" maxLength="255"
                        onInput=${(e) => setAlt(e.currentTarget.value)}
                        class="w-full px-3 py-2 rounded-xl border border-gray-200 outline-none focus:border-hodBlue text-sm" />
                    <div class="flex gap-2">
                        <${Button} onClick=${commit} loading=${busy}>Save<//>
                        <${Button} variant="ghost" onClick=${() => setEditing(false)}>Cancel<//>
                    </div>
                </div>`
                : (!readOnly ? html`
                <div class="flex gap-2 mt-3">
                    <button type="button" onClick=${() => setEditing(true)}
                        class="text-xs font-bold text-hodBlue hover:underline">Edit</button>
                    <button type="button" onClick=${remove} disabled=${busy}
                        class="text-xs font-bold text-hodRed hover:underline">Remove</button>
                    <a href=${asset.path} target="_blank" rel="noopener"
                        class="text-xs font-bold text-gray-500 hover:underline ml-auto">Open</a>
                </div>` : null)}
            </div>
        </div>`;
}

export function AssetsTab() {
    const event = current.value;
    const [role, setRole] = useState('hero');
    const [file, setFile] = useState(null);
    const [title, setTitle] = useState('');
    const [alt, setAlt] = useState('');
    const [busy, setBusy] = useState(false);

    if (!event) return html`<${Spinner} />`;

    const readOnly = !can('assets.manage');
    const assets = event.assets || [];
    const roles = boot.value?.asset_roles || Object.keys(ROLE_LABELS);

    const needsAlt = ['logo', 'wordmark', 'hero', 'hero_poster', 'illustration', 'background', 'gallery', 'sponsor', 'flyer'];

    const upload = async (e) => {
        e.preventDefault();
        if (!file) { toast('Choose a file first.', 'error'); return; }

        setBusy(true);
        try {
            const data = await studioUpload('asset_upload',
                { id: event.id, role, title, alt_text: alt },
                { file });
            applyEvent({ ...event, assets: data.assets });
            setFile(null);
            setTitle('');
            setAlt('');
            if (e.target instanceof HTMLFormElement) e.target.reset();
            toast('Uploaded.', 'success');
        } catch (err) {
            toast(err.code === 'VALIDATION' && err.fields?.alt_text
                ? err.fields.alt_text
                : err.message, 'error', 7000);
        } finally {
            setBusy(false);
        }
    };

    const onChanged = (asset, replacementList) => {
        if (replacementList) {
            applyEvent({ ...event, assets: replacementList });
        } else if (asset) {
            applyEvent({ ...event, assets: assets.map((a) => (a.id === asset.id ? asset : a)) });
        }
    };

    return html`
        <div class="space-y-6">

            ${!readOnly ? html`
            <${Card} title="Add a file"
                     subtitle="Images are re-encoded on the server, which strips EXIF and location data, and responsive versions are generated for you.">
                <form onSubmit=${upload} class="space-y-5">
                    <div class="grid sm:grid-cols-2 gap-5">
                        <${Field} label="What is it?" name="role">
                            <${Select} name="role" value=${role} onChange=${setRole}
                                options=${roles.map((r) => ({ value: r, label: ROLE_LABELS[r] || r }))} />
                        <//>

                        <${Field} label="File" name="file">
                            <input type="file" required
                                onChange=${(e) => setFile(e.currentTarget.files?.[0] || null)}
                                class="w-full text-sm text-gray-600 file:mr-3 file:px-4 file:py-2 file:rounded-xl file:border-0 file:bg-blue-50 file:text-hodBlue file:font-semibold file:cursor-pointer" />
                        <//>
                    </div>

                    <div class="grid sm:grid-cols-2 gap-5">
                        <${Field} label="Title" name="title" hint="For your own reference.">
                            <${TextInput} name="title" value=${title} maxLength="160" onInput=${setTitle} />
                        <//>

                        <${Field} label="Alt text" name="alt_text"
                                  required=${needsAlt.includes(role)}
                                  hint="What is in the picture? Screen readers and slow connections show this.">
                            <${TextInput} name="alt_text" value=${alt} maxLength="255" onInput=${setAlt} />
                        <//>
                    </div>

                    <${Button} type="submit" loading=${busy}>Upload<//>
                </form>
            <//>` : null}

            <${Card} title="Brand kit" subtitle=${`${assets.length} file${assets.length === 1 ? '' : 's'}.`}>
                ${assets.length === 0
                    ? html`<${EmptyState} title="Nothing uploaded yet"
                              message="Add the hero image, the logo and the flyer, and they become available on the Brand tab." />`
                    : html`<div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-5">
                        ${assets.map((a) => html`
                            <${AssetCard} key=${a.id} asset=${a} readOnly=${readOnly} onChanged=${onChanged} />`)}
                    </div>`}
            <//>
        </div>`;
}
