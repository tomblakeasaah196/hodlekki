// /assets/se/js/studio/share_kit.js
//
// Studio → Overview → Share kit (guide §18.2).
//
// One link and one QR per source code, so Envision can tell the WhatsApp
// status from the printed flyer from the Sunday announcement slide. The
// view counts beside each code come from se_metrics_daily, which holds
// numbers and nothing else (§18.1).
//
// The list opens on the two links the crew reaches for every time — the plain
// link and the check-in poster QR — with the per-channel codes behind "Show
// all". The whole kit is still fetched in one call, so "Show all" is instant
// and the view counts are already there.

import { html } from '@se/core/html.js';
import { useState, useEffect, useRef } from 'preact/hooks';
import { qrcode } from 'qrcode-generator';
import { studio } from '@se/core/api.js';
import { current, toast } from './state.js';
import { Card, Button, Spinner } from './ui.js';

/** A QR as an inline <img> data URI — no canvas, no layout thrash. */
function qrDataUri(value, cells = 6) {
    const qr = qrcode(0, 'H');
    qr.addData(String(value));
    qr.make();

    return qr.createDataURL(cells, 4 * cells);
}

function QrRow({ row, views }) {
    const [copied, setCopied] = useState(false);
    const [big, setBig] = useState(false);

    const copy = async () => {
        try {
            await navigator.clipboard.writeText(row.url);
            setCopied(true);
            setTimeout(() => setCopied(false), 1800);
        } catch (e) {
            toast('Could not copy. Select the link and copy it by hand.', 'error');
        }
    };

    const download = () => {
        const link = document.createElement('a');
        link.href = qrDataUri(row.url, 16);
        link.download = `qr-${row.code || 'plain'}.png`;
        document.body.appendChild(link);
        link.click();
        link.remove();
    };

    return html`
        <li class="py-3 border-b border-gray-50 last:border-0 flex items-center gap-4">
            <button type="button" onClick=${() => setBig(!big)}
                class="shrink-0 rounded-xl border border-gray-100 p-1 hover:border-hodBlue transition-colors"
                aria-label=${'Enlarge the QR code for ' + row.label}>
                <img src=${qrDataUri(row.url, big ? 8 : 3)} alt="" width=${big ? 180 : 68} height=${big ? 180 : 68} />
            </button>

            <div class="min-w-0 flex-1">
                <p class="text-sm font-bold text-gray-900">
                    ${row.label}
                    ${views ? html`<span class="ml-2 text-xs font-semibold text-gray-400">${views} view${views === 1 ? '' : 's'}</span>` : null}
                </p>
                <a href=${row.url} target="_blank" rel="noopener"
                   class="text-xs text-hodBlue hover:underline break-all">${row.url}</a>
            </div>

            <div class="flex gap-1 shrink-0">
                <button type="button" onClick=${copy}
                    class="px-3 py-1.5 rounded-lg text-xs font-bold text-gray-600 hover:text-hodBlue hover:bg-blue-50 transition-colors">
                    ${copied ? 'Copied' : 'Copy'}
                </button>
                <button type="button" onClick=${download}
                    class="px-3 py-1.5 rounded-lg text-xs font-bold text-gray-600 hover:text-hodBlue hover:bg-blue-50 transition-colors">
                    PNG
                </button>
            </div>
        </li>`;
}

/** The two rows that are always worth seeing first, in this order. */
export const PRIMARY_CODES = ['', 'checkin'];

/**
 * Split the kit into the two always-visible rows and the rest.
 *
 * Pure, and forgiving: a missing primary row is skipped rather than rendered
 * empty, and any code the server adds later falls into `rest` instead of
 * disappearing from the Studio.
 *
 * @param {Array<{code?: string}>} links
 * @returns {{primary: Array, rest: Array}}
 */
export function splitShareLinks(links) {
    const all     = Array.isArray(links) ? links : [];
    const primary = PRIMARY_CODES
        .map((code) => all.find((row) => (row?.code || '') === code))
        .filter(Boolean);

    return { primary, rest: all.filter((row) => !primary.includes(row)) };
}

export function ShareKit() {
    const event = current.value;
    const [data, setData] = useState(null);
    const [showAll, setShowAll] = useState(false);

    useEffect(() => {
        if (!event) return;
        studio('share_kit', { id: event.id })
            .then(setData)
            .catch(() => setData({ links: [], views: {} }));
    }, [event?.id]);

    if (!event) return null;

    const { primary, rest } = splitShareLinks(data?.links);
    const visible = showAll ? [...primary, ...rest] : primary;

    return html`
        <${Card} title="Share kit"
                 subtitle="One link and one QR per place you post it, so you can see what actually worked.">
            ${data === null
                ? html`<${Spinner} label="Building the kit…" />`
                : html`
                <ul>${visible.map((row) => html`
                    <${QrRow} key=${row.code || 'plain'} row=${row} views=${data.views?.[row.code] || 0} />`)}
                </ul>
                ${rest.length === 0 ? null : html`
                <button type="button" onClick=${() => setShowAll(!showAll)}
                    class="w-full py-3 text-center text-xs font-bold text-gray-600 hover:text-hodBlue hover:bg-blue-50 rounded-lg transition-colors">
                    ${showAll
                        ? 'Show fewer'
                        : `View more (${rest.length} more link${rest.length === 1 ? '' : 's'})`}
                </button>`}`}
        <//>`;
}
