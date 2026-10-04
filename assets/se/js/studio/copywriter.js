// /assets/se/js/studio/copywriter.js
//
// The AI copywriting helper (guide §15.8).
//
// Three options, never applied automatically: the human picks one and it is
// copied to the clipboard (or handed to a caller through `onPick`), which is
// the "human in the loop" rule in §15.1.

import { html } from '@se/core/html.js';
import { useState } from 'preact/hooks';
import { studio } from '@se/core/api.js';
import { current, toast } from './state.js';
import { Button, Select, Field, TextInput } from './ui.js';

// What each purpose is for, shown under the picker so the crew knows what to
// expect back. The limits mirror SE_COPYWRITE_LIMITS in constants.php.
const PURPOSE_HINTS = {
    tagline: 'One line for the top of the page, the flyer and the share card.',
    description:
        'The paragraph a first-time guest reads before deciding to come. Options come back as full paragraphs '
        + '(about 70–110 words), each taking a different angle: a warm invitation, an activity-led one, and one '
        + 'about the people in the room.',
    activity_blurb: 'One or two sentences about a single activity.',
    faq_answer: 'A direct answer first, then the practical detail that follows it.',
    sms_reminder: 'Plain GSM-7 text, two pages at most including the link.',
    sms_thanks: 'Plain GSM-7 text, two pages at most including the link.',
    card_headline: 'Six words at most — it has to read at a glance.',
};

const PURPOSES = [
    { value: 'tagline', label: 'Tagline (≤ 12 words)' },
    { value: 'description', label: 'Portal description (≤ 120 words)' },
    { value: 'activity_blurb', label: 'Activity blurb (≤ 25 words)' },
    { value: 'faq_answer', label: 'FAQ answer' },
    { value: 'sms_reminder', label: 'SMS reminder' },
    { value: 'sms_thanks', label: 'SMS thank-you' },
    { value: 'card_headline', label: 'Share-card headline' },
];

/**
 * @param {{purpose?: string, onPick?: (text: string) => void, compact?: boolean}} props
 */
export function Copywriter({ purpose: fixedPurpose, onPick, compact }) {
    const event = current.value;
    const [purpose, setPurpose] = useState(fixedPurpose || 'tagline');
    const [brief, setBrief] = useState('');
    const [variants, setVariants] = useState(null);
    const [busy, setBusy] = useState(false);

    const run = async () => {
        setBusy(true);
        try {
            const data = await studio('copywrite', { id: event.id, purpose, brief });
            setVariants(data.variants || []);
            if (!data.variants?.length) toast('Nothing usable came back. Try again, or tweak the brief.', 'info');
        } catch (e) {
            // AI_UNAVAILABLE is normal on a host with no key: say so plainly.
            toast(e.code === 'AI_UNAVAILABLE' ? 'The AI helper is not configured on this server.' : e.message, 'error');
        } finally {
            setBusy(false);
        }
    };

    const wordCount = (text) => (text.trim().match(/\S+/g) || []).length;

    const pick = async (text) => {
        if (onPick) { onPick(text); return; }
        try {
            await navigator.clipboard.writeText(text);
            toast('Copied — paste it where you want it.', 'success');
        } catch (e) {
            toast(text, 'info', 12000);
        }
    };

    return html`
        <div class="space-y-4">
            <div class=${compact ? 'space-y-3' : 'grid sm:grid-cols-2 gap-4'}>
                ${fixedPurpose ? null : html`
                <${Field} label="Write me…" name="copy_purpose">
                    <${Select} name="copy_purpose" value=${purpose} onChange=${setPurpose} options=${PURPOSES} />
                <//>`}
                <${Field} label="Anything specific? (optional)" name="copy_brief"
                          hint="e.g. “mention that it ends by 9pm”">
                    <${TextInput} name="copy_brief" value=${brief} onInput=${setBrief} maxLength=${240} />
                <//>
            </div>

            ${PURPOSE_HINTS[purpose] ? html`
            <p class="text-xs text-gray-500">${PURPOSE_HINTS[purpose]}</p>` : null}

            ${purpose === 'description' ? html`
            <p class="text-xs text-gray-500">
                The description field accepts Markdown: headings, <b>bold</b>, <i>italic</i>, inline code, links,
                bullet lists, numbered lists, quotes, dividers and paragraphs. Raw HTML and images are not allowed.
                These suggestions come back as plain paragraphs — add any formatting yourself.
            </p>` : null}

            <${Button} onClick=${run} loading=${busy} variant="secondary">
                ✨ Suggest three
            <//>

            ${variants === null ? null : html`
            <ul class="space-y-2">
                ${variants.length === 0
                    ? html`<li class="text-sm text-gray-500">Nothing came back that fits the limits.</li>`
                    : variants.map((text, i) => html`
                    <li key=${i} class="flex items-start gap-3 bg-gray-50 rounded-2xl p-4">
                        <div class="flex-1">
                            <p class="text-sm text-gray-800">${text}</p>
                            ${purpose === 'description' ? html`
                            <p class="mt-1 text-xs ${wordCount(text) > 120 ? 'text-red-600' : 'text-gray-400'}">
                                Option ${i + 1} · ${wordCount(text)} words${wordCount(text) > 120 ? ' — over the 120-word limit, trim it' : ''}
                            </p>` : null}
                        </div>
                        <button type="button" onClick=${() => pick(text)}
                            class="shrink-0 px-3 py-1.5 rounded-lg text-xs font-bold text-hodBlue hover:bg-blue-100 transition-colors">
                            ${onPick ? 'Use this' : 'Copy'}
                        </button>
                    </li>`)}
            </ul>
            <p class="text-xs text-gray-400">
                Nothing is applied automatically — read it, edit it, then use it.
            </p>`}
        </div>`;
}
