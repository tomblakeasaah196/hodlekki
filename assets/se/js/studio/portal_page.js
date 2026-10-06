// /assets/se/js/studio/portal_page.js
//
// The settings.portal branch, edited where the rest of the page is edited:
// Studio → Details (guide §13.2, §13.13).
//
// Until now the opening line, the two hero switches and the "Good to know"
// questions existed only in Appendix E, so every event shipped the six
// starter questions and nobody could change them without a deploy. This is
// the editor for them. It writes through `portal_settings_save`, a settings
// patch — no row_version, last save wins — and keeps its own small Save row
// instead of the tab's sticky bar, so saving a question never saves a
// half-typed tagline above it (and never throws one away either).

import { html } from '@se/core/html.js';
import { useState, useEffect } from 'preact/hooks';
import { studio } from '@se/core/api.js';
import { boot, current, refreshEvent, toast, can, fieldErrors } from './state.js';
import { Card, Field, TextInput, TextArea, Switch, Button, EmptyState } from './ui.js';
import { Copywriter } from './copywriter.js';

const limits = () => boot.value?.limits || {};
const faqMax = () => limits().faq_max || 20;
const qMax = () => limits().faq_q_max || 160;
const aMax = () => limits().faq_a_max || 1000;

/** The saved portal branch, as plain editable values. */
function readPortal(event) {
    const portal = event?.settings?.portal || {};

    return {
        intro_line: portal.intro_line || '',
        show_countdown: portal.show_countdown !== false,
        hero_video_enabled: portal.hero_video_enabled !== false,
        faq: (portal.faq || []).map((row) => ({ q: row.q || '', a: row.a || '' })),
    };
}

function FaqRow({ row, index, total, readOnly, aiOn, onChange, onMove, onRemove }) {
    const [helper, setHelper] = useState(false);
    const answerLength = row.a.length;

    return html`
        <li class="rounded-2xl border border-gray-200 bg-white p-4 sm:p-5 space-y-4">
            <div class="flex items-center justify-between gap-3">
                <p class="text-xs font-bold uppercase tracking-wider text-gray-400">
                    Question ${index + 1}
                </p>
                ${readOnly ? null : html`
                <div class="flex items-center gap-1.5">
                    <button type="button" aria-label="Move up" title="Move up"
                        disabled=${index === 0}
                        onClick=${() => onMove(index, -1)}
                        class="w-8 h-8 rounded-lg border border-gray-200 text-gray-600 hover:border-hodBlue hover:text-hodBlue disabled:opacity-30">↑</button>
                    <button type="button" aria-label="Move down" title="Move down"
                        disabled=${index === total - 1}
                        onClick=${() => onMove(index, 1)}
                        class="w-8 h-8 rounded-lg border border-gray-200 text-gray-600 hover:border-hodBlue hover:text-hodBlue disabled:opacity-30">↓</button>
                    <button type="button" onClick=${() => onRemove(index)}
                        class="ml-1 px-2 py-1 text-xs font-bold text-hodRed hover:underline">Remove</button>
                </div>`}
            </div>

            <${Field} label="The question" name=${'faq_' + index + '_q'}
                      hint="As a guest would ask it. Short enough to read at a glance.">
                <${TextInput} name=${'faq_' + index + '_q'} value=${row.q} maxLength=${qMax()}
                    disabled=${readOnly} placeholder="Is it free?"
                    onInput=${(v) => onChange(index, 'q', v)} />
            <//>

            <${Field} label="The answer" name=${'faq_' + index + '_a'}
                      hint=${'Plain words, one or two sentences. ' + answerLength + ' of ' + aMax() + ' characters.'}>
                <${TextArea} name=${'faq_' + index + '_a'} rows=${3} value=${row.a} maxLength=${aMax()}
                    disabled=${readOnly} placeholder="Yes! Just register so we can save you a seat."
                    onInput=${(v) => onChange(index, 'a', v)} />
            <//>

            ${readOnly || !aiOn ? null : html`
            <div>
                <button type="button" onClick=${() => setHelper(!helper)}
                    class="text-xs font-bold text-hodBlue hover:underline">
                    ${helper ? 'Close the writing helper' : '✨ Help me write this answer'}
                </button>
                ${helper ? html`
                <div class="mt-3 rounded-2xl bg-gray-50 p-4">
                    <${Copywriter} purpose="faq_answer" compact
                        onPick=${(text) => { onChange(index, 'a', text); setHelper(false); }} />
                </div>` : null}
            </div>`}
        </li>`;
}

export function PortalPage() {
    const event = current.value;
    const [portal, setPortal] = useState(() => readPortal(event));
    const [dirty, setDirty] = useState(false);
    const [busy, setBusy] = useState(false);

    // A save anywhere in the Studio bumps row_version, so this is also how
    // the panel picks up a reset, or someone else's edit after a reload.
    useEffect(() => {
        setPortal(readPortal(event));
        setDirty(false);
    }, [event?.id, event?.row_version]);

    if (!event) return null;

    const readOnly = !can('event.edit');
    const aiOn = !!boot.value?.ai?.available;
    const faq = portal.faq;

    const edit = (patch) => {
        setPortal((prev) => ({ ...prev, ...patch }));
        setDirty(true);
    };

    const editRow = (index, key, value) => {
        setPortal((prev) => ({
            ...prev,
            faq: prev.faq.map((row, i) => (i === index ? { ...row, [key]: value } : row)),
        }));
        setDirty(true);
    };

    const addRow = () => {
        setPortal((prev) => ({ ...prev, faq: [...prev.faq, { q: '', a: '' }] }));
        setDirty(true);
    };

    const removeRow = (index) => {
        setPortal((prev) => ({ ...prev, faq: prev.faq.filter((_, i) => i !== index) }));
        fieldErrors.value = {};
        setDirty(true);
    };

    const moveRow = (index, delta) => {
        const target = index + delta;
        setPortal((prev) => {
            if (target < 0 || target >= prev.faq.length) return prev;
            const next = [...prev.faq];
            [next[index], next[target]] = [next[target], next[index]];
            return { ...prev, faq: next };
        });
        fieldErrors.value = {};
        setDirty(true);
    };

    const discard = () => {
        setPortal(readPortal(event));
        fieldErrors.value = {};
        setDirty(false);
    };

    const save = async () => {
        setBusy(true);
        fieldErrors.value = {};
        try {
            const data = await studio('portal_settings_save', { id: event.id, settings: portal });
            refreshEvent(data.event);
            setDirty(false);
            toast('The public page is updated.', 'success');
        } catch (e) {
            if (e.code === 'VALIDATION') {
                fieldErrors.value = e.fields;
                toast(Object.values(e.fields)[0] || e.message, 'error', 6000);
            } else {
                toast(e.message, 'error');
            }
        } finally {
            setBusy(false);
        }
    };

    const restore = async () => {
        if (!confirm('Put the starter questions back?\n\nAnything you have written here is replaced.')) return;
        setBusy(true);
        try {
            const data = await studio('portal_faq_reset', { id: event.id });
            refreshEvent(data.event);
            setDirty(false);
            toast('The starter questions are back.', 'success');
        } catch (e) {
            toast(e.message, 'error');
        } finally {
            setBusy(false);
        }
    };

    const countNote = faq.length === 0
        ? 'No questions yet — the section is hidden on the page.'
        : faq.length + ' of ' + faqMax() + ' questions. The order here is the order on the page.';

    return html`
        <div class="space-y-6">

            <${Card} title="The public page"
                     subtitle="Small decisions about /e/${event.slug} that are not covered by Brand or Chapters.">
                <div class="space-y-5">
                    <${Field} label="Opening line" name="intro_line"
                              hint="One sentence under the countdown. Leave it empty and the page uses the tagline alone.">
                        <${TextInput} name="intro_line" value=${portal.intro_line} maxLength=${300}
                            disabled=${readOnly}
                            onInput=${(v) => edit({ intro_line: v })} />
                    <//>

                    <${Switch} label="Count down to the first day"
                        hint="Off hides the clock — useful for an event with no fixed start."
                        checked=${portal.show_countdown} disabled=${readOnly}
                        onChange=${(v) => edit({ show_countdown: v })} />

                    <${Switch} label="Play the hero video when there is one"
                        hint="Only applies if a hero video is set in Assets. Off always shows the hero image."
                        checked=${portal.hero_video_enabled} disabled=${readOnly}
                        onChange=${(v) => edit({ hero_video_enabled: v })} />
                </div>
            <//>

            <${Card} title="Good to know"
                     subtitle="The questions guests ask before they come. They appear near the bottom of the page as a tap-to-open list."
                     actions=${readOnly ? null : html`
                        <${Button} variant="ghost" onClick=${restore} disabled=${busy}>Restore the starter questions<//>
                        <${Button} variant="secondary" onClick=${addRow}
                                   disabled=${busy || faq.length >= faqMax()}>Add a question<//>`}>

                ${faq.length === 0 ? html`
                    <${EmptyState} title="No questions on the page"
                        message="Add the ones your team keeps answering on WhatsApp — cost, dress code, what time to arrive."
                        action=${readOnly ? null : html`
                            <${Button} onClick=${addRow}>Add the first question<//>`} />`
                : html`
                    <ul class="space-y-4">
                        ${faq.map((row, i) => html`
                            <${FaqRow} key=${i} row=${row} index=${i} total=${faq.length}
                                readOnly=${readOnly} aiOn=${aiOn}
                                onChange=${editRow} onMove=${moveRow} onRemove=${removeRow} />`)}
                    </ul>`}

                <p class="text-xs text-gray-500 mt-5">${countNote}</p>

                ${fieldErrors.value.faq ? html`
                    <p class="text-xs font-semibold text-hodRed mt-2" role="alert">${fieldErrors.value.faq}</p>` : null}
            <//>

            ${dirty && !readOnly ? html`
            <div class="bg-blue-50 border border-blue-100 rounded-2xl px-5 py-4 flex flex-wrap items-center gap-3">
                <p class="text-sm font-semibold text-hodBlue mr-auto">
                    The public page has unsaved changes.
                </p>
                <${Button} onClick=${save} loading=${busy}>Save the public page<//>
                <${Button} variant="ghost" onClick=${discard} disabled=${busy}>Discard<//>
            </div>` : null}
        </div>`;
}
