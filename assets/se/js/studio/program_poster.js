// /assets/se/js/studio/program_poster.js
//
// The programme poster (guide §14.2b): the run of show as one picture, in
// the two shapes anybody ever asks for — A4 to print, 16:9 to put on the
// lobby TV — with the portal's own hero photo behind it.
//
// Why this is not an se__*.svg template like the share cards. A template is
// a fixed set of tokens, and a programme is a list of unknown length: six
// items on a Sunday, twenty-two at a carol service. So the SVG source is
// BUILT here instead, which lets the layout answer the only question that
// matters for a poster — "does all of it fit on one page?" — by measuring
// the list and choosing the row height, the type sizes and the number of
// columns to suit. Nothing is ever cut off; it gets smaller instead.
//
// Everything above the component is pure: no DOM, no network, no canvas.
// buildProgramPosterSvg() takes the hero as a data URI, the fonts as CSS and
// the QR as markup, all three fetched by the caller, and returns a string.
// That is what makes the layout testable in node --test.

import { html } from '@se/core/html.js';
import { useState, useEffect, useMemo } from 'preact/hooks';
import { studio } from '@se/core/api.js';
import { toast } from './state.js';
import { Card, Button, Field, Select, Switch, Spinner, EmptyState } from './ui.js';

// --------------------------------------------------------------------------
// The two shapes
// --------------------------------------------------------------------------

export const POSTER_FORMATS = {
    a4: {
        key: 'a4',
        label: 'A4 poster',
        hint: '210 × 297 mm at 300 dpi',
        width: 2480,
        height: 3508,
        layout: 'portrait',
    },
    screen: {
        key: 'screen',
        label: '16:9 screen',
        hint: 'Projector, lobby TV or a slide',
        width: 1920,
        height: 1080,
        layout: 'split',
    },
};

/** `chara-2026-programme-a4.jpg` (§14.3). */
export function posterFilename(base, formatKey, extension = 'jpg') {
    const slug = String(base || 'programme')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');

    return `${slug || 'programme'}-${formatKey}.${extension}`;
}

// --------------------------------------------------------------------------
// Text: measuring, fitting, wrapping
// --------------------------------------------------------------------------

const clamp = (value, low, high) => Math.max(low, Math.min(high, value));

export function esc(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

/**
 * A width measurer.
 *
 * In the browser this is a 2D context, which knows the real metrics of the
 * event's own fonts once they are loaded. In node (and before the webfont
 * arrives) it falls back to an average-advance estimate, which is close
 * enough: every caller leaves slack, and a slightly conservative guess only
 * ever makes the poster roomier.
 */
export function makeMeasurer() {
    let ctx = null;
    try {
        if (typeof document !== 'undefined' && document.createElement) {
            ctx = document.createElement('canvas').getContext('2d');
        }
    } catch (e) {
        ctx = null;
    }

    return function measure(text, size, family = 'sans-serif', weight = 400) {
        const value = String(text ?? '');
        if (!value) return 0;
        if (ctx) {
            ctx.font = `${weight} ${size}px ${family}, sans-serif`;
            const width = ctx.measureText(value).width;
            if (width > 0) return width;
        }
        // No real metrics: guess high. A display face like Unbounded averages
        // about 0.7em per glyph, and a poster that is slightly too small is
        // a poster; one that is slightly too big is a bug.
        const caps = (value.match(/[A-Z0-9]/g) || []).length / value.length;

        return value.length * size * (0.58 + caps * 0.1) * (weight >= 700 ? 1.06 : 1);
    };
}

/** `Hello world` → `Hello w…` once it stops fitting in `maxWidth`. */
export function ellipsise(text, maxWidth, size, measure, family, weight) {
    const value = String(text ?? '').trim();
    if (!value || maxWidth <= 0) return '';
    if (measure(value, size, family, weight) <= maxWidth) return value;

    let low = 0;
    let high = value.length;
    while (low < high) {
        const mid = Math.ceil((low + high) / 2);
        if (measure(value.slice(0, mid) + '…', size, family, weight) <= maxWidth) low = mid;
        else high = mid - 1;
    }

    return low > 0 ? value.slice(0, low).trimEnd() + '…' : '…';
}

/**
 * A measurer that also counts the letter-spacing, which is wide enough on
 * the small-caps lines to push them off the page if it is ignored.
 */
export function withSpacing(measure, spacingRatio) {
    if (!spacingRatio) return measure;

    return (value, size, family, weight) =>
        measure(value, size, family, weight)
        + size * spacingRatio * Math.max(0, String(value ?? '').length - 1);
}

/** The biggest size at which one line fits, ellipsised if even `min` is too wide. */
export function fitLine(value, maxWidth, max, min, measure, family, weight, spacingRatio = 0) {
    const m = withSpacing(measure, spacingRatio);
    let size = max;
    while (size > min && m(value, size, family, weight) > maxWidth) {
        size = Math.max(min, Math.round(size * 0.94));
    }

    return { size, text: ellipsise(value, maxWidth, size, m, family, weight) };
}

/** Greedy word wrap at a fixed size. */
export function wrapLines(text, maxWidth, size, measure, family, weight) {
    const words = String(text ?? '').trim().split(/\s+/).filter(Boolean);
    if (!words.length) return [];

    const lines = [];
    let line = '';
    for (const word of words) {
        const next = line ? line + ' ' + word : word;
        if (line && measure(next, size, family, weight) > maxWidth) {
            lines.push(line);
            line = word;
        } else {
            line = next;
        }
    }
    if (line) lines.push(line);

    return lines;
}

/**
 * The biggest size at which `text` wraps into at most `maxLines` lines.
 * Shrinks in 6% steps, then ellipsises the last line if even `min` is too
 * small — a title can be small, but it can never run off the page.
 */
export function fitBlock(text, maxWidth, maxLines, max, min, measure, family, weight) {
    let size = max;
    let lines = wrapLines(text, maxWidth, size, measure, family, weight);

    while (lines.length > maxLines && size > min) {
        size = Math.max(min, Math.round(size * 0.94));
        lines = wrapLines(text, maxWidth, size, measure, family, weight);
    }

    if (lines.length > maxLines) {
        const kept = lines.slice(0, maxLines);
        kept[maxLines - 1] = ellipsise(
            lines.slice(maxLines - 1).join(' '), maxWidth, size, measure, family, weight
        );
        lines = kept;
    }

    return { size, lines };
}

// --------------------------------------------------------------------------
// The fitting rule (pure, and the whole reason this file exists)
// --------------------------------------------------------------------------

/**
 * How to lay `count` rows inside `avail` pixels of height.
 *
 * The row height is whatever makes them all fit, capped so a short
 * programme does not turn into six enormous slabs. A second column is
 * opened when the list is long enough that one column would squash it.
 * Blurbs are a luxury: they appear only while the rows are tall enough to
 * hold two lines of type comfortably.
 */
export function planPosterRows(count, avail, metrics) {
    const gap = metrics.gap;
    const maxColumns = metrics.maxColumns ?? 2;

    let columns = 1;
    const heightFor = (cols) => {
        const perColumn = Math.ceil(count / cols);
        return (avail - gap * (perColumn - 1)) / Math.max(1, perColumn);
    };

    while (columns < maxColumns) {
        const squashed = heightFor(columns) < metrics.minRow;
        if (!(squashed || (columns === 1 && count > metrics.columnBreak))) break;
        columns += 1;
    }

    const perColumn = Math.max(1, Math.ceil(count / columns));
    const rowH = Math.max(24, Math.min(metrics.maxRow, heightFor(columns)));

    const titleSize = clamp(rowH * metrics.titleRatio, metrics.titleMin, metrics.titleMax);
    const showBlurbs = rowH >= metrics.blurbAt && columns <= (metrics.blurbColumns ?? 1);

    return {
        count,
        columns,
        perColumn,
        gap,
        rowH,
        titleSize,
        titleMin: metrics.titleMin,
        timeSize: clamp(titleSize * 0.82, metrics.timeMin, metrics.timeMax),
        blurbSize: titleSize * 0.56,
        showBlurbs,
        // True only if the maths was asked to do something impossible.
        overflow: rowH * perColumn + gap * (perColumn - 1) > avail + 1,
    };
}

/** The items of one day, flattened the way the poster draws them. */
export function posterItems(data, dayId) {
    const days = data?.days || [];
    const day = days.find((d) => d.day_id === dayId) || days[0] || null;

    return { day, items: day ? (day.items || []) : [] };
}

// --------------------------------------------------------------------------
// SVG primitives
// --------------------------------------------------------------------------

const n = (value) => (Math.round(value * 100) / 100).toString();

function tag(name, attrs, inner) {
    const parts = [];
    for (const [key, value] of Object.entries(attrs)) {
        if (value === null || value === undefined || value === '') continue;
        parts.push(`${key}="${typeof value === 'number' ? n(value) : esc(value)}"`);
    }
    const open = `<${name} ${parts.join(' ')}`;

    return inner === undefined ? `${open}/>` : `${open}>${inner}</${name}>`;
}

function box({ x, y, w, h, rx = 0, fill, opacity = 1, stroke = null, strokeOpacity = 1, strokeWidth = 1 }) {
    return tag('rect', {
        x, y, width: w, height: h, rx,
        fill: fill || 'none',
        'fill-opacity': opacity === 1 ? null : opacity,
        stroke,
        'stroke-opacity': stroke ? strokeOpacity : null,
        'stroke-width': stroke ? strokeWidth : null,
    });
}

function text(value, { x, y, size, family, weight = 400, fill, opacity = 1, anchor = 'start', spacing = 0 }) {
    return tag('text', {
        x, y,
        'font-family': family,
        'font-size': size,
        'font-weight': weight,
        fill,
        'fill-opacity': opacity === 1 ? null : opacity,
        'text-anchor': anchor === 'start' ? null : anchor,
        'letter-spacing': spacing || null,
        'xml:space': 'preserve',
    }, esc(value));
}

function line({ x1, y1, x2, y2, stroke, opacity = 1, width = 2 }) {
    return tag('line', {
        x1, y1, x2, y2, stroke,
        'stroke-opacity': opacity === 1 ? null : opacity,
        'stroke-width': width,
        'stroke-linecap': 'round',
    });
}

// --------------------------------------------------------------------------
// The poster
// --------------------------------------------------------------------------

const METRICS = {
    portrait: {
        margin: 180,
        top: 230,
        eyebrow: 44,
        titleMax: 250, titleMin: 96, titleLines: 2,
        edition: 78,
        tagline: 50,
        meta: 42,
        heading: 52,
        footer: 360,
        rows: {
            gap: 24, minRow: 104, maxRow: 290, columnBreak: 18, maxColumns: 2,
            titleRatio: 0.40, titleMin: 40, titleMax: 98,
            timeMin: 32, timeMax: 66,
            blurbAt: 190, blurbColumns: 1,
        },
        timeCol: 430,
        pad: 48,
    },
    split: {
        margin: 72,
        panel: 700,
        top: 96,
        eyebrow: 24,
        titleMax: 124, titleMin: 38, titleLines: 3,
        edition: 34,
        tagline: 26,
        meta: 25,
        heading: 28,
        footer: 86,
        rows: {
            gap: 14, minRow: 74, maxRow: 130, columnBreak: 8, maxColumns: 2,
            titleRatio: 0.42, titleMin: 20, titleMax: 46,
            timeMin: 18, timeMax: 34,
            blurbAt: 94, blurbColumns: 1,
        },
        timeCol: 196,
        pad: 26,
    },
};

/**
 * Where the QR sits, in one place: the component draws the code, the layout
 * writes the caption under it, and both need the same box.
 */
export function posterQrRect(formatKey) {
    const format = POSTER_FORMATS[formatKey] || POSTER_FORMATS.a4;
    if (format.layout === 'split') {
        const m = METRICS.split;
        const size = 124;

        return { x: m.margin, y: format.height - m.margin - size, width: size, height: size };
    }

    const m = METRICS.portrait;
    const size = 200;

    return { x: format.width - m.margin - size, y: format.height - m.footer + 16, width: size, height: size };
}

/** The background: the hero photo if there is one, otherwise the palette. */
function backdrop(format, colors, heroDataUri) {
    const { width: W, height: H } = format;
    const parts = [box({ x: 0, y: 0, w: W, h: H, fill: colors.bg })];

    if (heroDataUri) {
        parts.push(tag('image', {
            x: 0, y: 0, width: W, height: H,
            preserveAspectRatio: 'xMidYMid slice',
            href: heroDataUri,
            'xlink:href': heroDataUri,
        }));
    }

    parts.push(box({ x: 0, y: 0, w: W, h: H, fill: 'url(#se-scrim)' }));
    parts.push(box({ x: 0, y: 0, w: W, h: H, fill: 'url(#se-glow)' }));

    return parts.join('');
}

function defs(format, colors, fontCss, heroDataUri) {
    const horizontal = format.layout === 'split';
    const scrim = horizontal
        ? `<linearGradient id="se-scrim" x1="0" y1="0" x2="1" y2="0.35">
               <stop offset="0" stop-color="${esc(colors.bg)}" stop-opacity="${heroDataUri ? 0.97 : 1}"/>
               <stop offset="0.42" stop-color="${esc(colors.bg)}" stop-opacity="${heroDataUri ? 0.88 : 0.96}"/>
               <stop offset="1" stop-color="${esc(colors.bg)}" stop-opacity="${heroDataUri ? 0.82 : 0.94}"/>
           </linearGradient>`
        : `<linearGradient id="se-scrim" x1="0" y1="0" x2="0" y2="1">
               <stop offset="0" stop-color="${esc(colors.bg)}" stop-opacity="${heroDataUri ? 0.68 : 1}"/>
               <stop offset="0.38" stop-color="${esc(colors.bg)}" stop-opacity="${heroDataUri ? 0.88 : 0.97}"/>
               <stop offset="1" stop-color="${esc(colors.bg)}" stop-opacity="${heroDataUri ? 0.97 : 1}"/>
           </linearGradient>`;

    const glow = `<radialGradient id="se-glow" cx="${horizontal ? 0.78 : 0.5}" cy="${horizontal ? 0.2 : 0.12}" r="0.75">
            <stop offset="0" stop-color="${esc(colors.primary)}" stop-opacity="0.45"/>
            <stop offset="0.55" stop-color="${esc(colors.secondary)}" stop-opacity="0.16"/>
            <stop offset="1" stop-color="${esc(colors.bg)}" stop-opacity="0"/>
        </radialGradient>`;

    // CDATA: the inlined @font-face CSS is not XML, and this SVG is parsed
    // as XML on its way to the canvas.
    const style = fontCss ? `<style><![CDATA[${fontCss}]]></style>` : '';

    return `<defs>${scrim}${glow}</defs>${style}`;
}

/** One row of the run of show. */
function posterRow(item, position, plan, m, colors, fonts, measure, index) {
    const { x, y, w, h } = position;
    const rx = Math.min(h * 0.3, 40);
    const featured = !!item.featured;
    const pad = m.pad;
    const timeCol = plan.timeCol;

    const out = [
        box({
            x, y, w, h, rx,
            fill: featured ? colors.accent : colors.surface,
            opacity: featured ? 0.2 : 0.42,
            stroke: featured ? colors.accent : colors.text,
            strokeOpacity: featured ? 0.55 : 0.12,
            strokeWidth: Math.max(1.5, h * 0.018),
        }),
    ];

    if (featured) {
        out.push(box({ x: x + pad * 0.3, y: y + h * 0.22, w: Math.max(5, h * 0.06), h: h * 0.56, rx: h * 0.03, fill: colors.accent }));
    }

    const cy = y + h / 2;
    const label = item.time || String(index + 1).padStart(2, '0');

    out.push(text(label, {
        x: x + pad + (featured ? Math.max(5, h * 0.06) : 0),
        y: cy + plan.timeSize * 0.35,
        size: plan.timeSize,
        family: fonts.body,
        weight: 700,
        fill: featured ? colors.accent : colors.text,
        opacity: featured ? 1 : 0.82,
    }));

    out.push(line({
        x1: x + timeCol - pad * 0.5, y1: y + h * 0.24,
        x2: x + timeCol - pad * 0.5, y2: y + h * 0.76,
        stroke: colors.text, opacity: 0.16, width: Math.max(1, h * 0.012),
    }));

    const textX = x + timeCol;
    const textW = w - timeCol - pad;

    const support = item.blurb || (item.host ? 'with ' + item.host : '');
    const withBlurb = plan.showBlurbs && support;

    const titleText = ellipsise(item.title, textW, plan.titleSize, measure, fonts.display, 700);
    out.push(text(titleText, {
        x: textX,
        y: withBlurb ? cy - plan.blurbSize * 0.35 : cy + plan.titleSize * 0.35,
        size: plan.titleSize,
        family: fonts.display,
        weight: 700,
        fill: colors.text,
    }));

    if (withBlurb) {
        out.push(text(ellipsise(support, textW, plan.blurbSize, measure, fonts.body, 400), {
            x: textX,
            y: cy + plan.blurbSize * 1.25,
            size: plan.blurbSize,
            family: fonts.body,
            fill: colors.text,
            opacity: 0.66,
        }));
    } else if (item.host && !plan.showBlurbs) {
        // No room for a second line: the host goes on the right of the
        // first, but only if the title has genuinely left room for it.
        const hostWidth = measure(item.host, plan.timeSize * 0.82, fonts.body, 400);
        const titleWidth = measure(titleText, plan.titleSize, fonts.display, 700);
        if (hostWidth < textW * 0.34 && titleWidth + hostWidth + pad < textW) {
            out.push(text(item.host, {
                x: x + w - pad,
                y: cy + plan.timeSize * 0.3,
                size: plan.timeSize * 0.82,
                family: fonts.body,
                fill: colors.text,
                opacity: 0.55,
                anchor: 'end',
            }));
        }
    }

    return out.join('');
}

/** The list, laid into one or two columns inside `region`. */
function posterList(items, region, plan, m, colors, fonts, measure) {
    const columnGap = m.pad;
    const columnW = (region.w - columnGap * (plan.columns - 1)) / plan.columns;
    const out = [];

    // Two passes settle the three measurements that depend on each other:
    // the time column is as wide as the widest time, the titles take what
    // is left, and the time type is sized to match the titles. A fixed time
    // column either wastes a third of a narrow column or runs into the
    // title, and ellipsising one row in three reads as a bug — shrinking
    // the whole list by the same amount reads as a design decision.
    for (let pass = 0; pass < 2; pass++) {
        plan.timeSize = clamp(plan.titleSize * 0.86, m.rows.timeMin, m.rows.timeMax);

        const widest = items.reduce(
            (wide, item) => Math.max(wide, measure(item.time || '00', plan.timeSize, fonts.body, 700)),
            0
        );
        plan.timeCol = Math.min(columnW * 0.42, m.pad * 1.9 + widest * 1.08);

        const titleRoom = columnW - plan.timeCol - m.pad;
        const longest = items.reduce(
            (wide, item) => Math.max(wide, measure(item.title, plan.titleSize, fonts.display, 700)),
            0
        );
        if (longest > titleRoom) {
            plan.titleSize = Math.max(plan.titleMin, plan.titleSize * (titleRoom / longest));
        }
    }
    plan.blurbSize = plan.titleSize * 0.56;

    // A short programme is capped at maxRow, so centre what is left rather
    // than leaving a hole under the last item.
    const tallest = Math.min(plan.perColumn, items.length);
    const used = plan.rowH * tallest + plan.gap * (tallest - 1);
    const offsetY = Math.max(0, (region.h - used) / 2);

    items.forEach((item, index) => {
        const column = Math.floor(index / plan.perColumn);
        const row = index % plan.perColumn;
        out.push(posterRow(item, {
            x: region.x + column * (columnW + columnGap),
            y: region.y + offsetY + row * (plan.rowH + plan.gap),
            w: columnW,
            h: plan.rowH,
        }, plan, m, colors, fonts, measure, index));
    });

    return out.join('');
}

/** "THE PROGRAMME", with its accent rule. */
function sectionHeading(label, { x, y, size, anchor, colors, fonts, maxWidth, measure }) {
    const ruleX = anchor === 'middle' ? x - 80 : x;
    if (maxWidth && measure) {
        const fitted = fitLine(label.toUpperCase(), maxWidth, size, size * 0.7, measure, fonts.body, 700, 0.22);
        size = fitted.size;
        label = fitted.text;
    }

    return text(label.toUpperCase(), {
        x, y, size, family: fonts.body, weight: 700,
        fill: colors.accent, spacing: size * 0.22, anchor,
    }) + line({
        x1: ruleX, y1: y + size * 0.72, x2: ruleX + 160, y2: y + size * 0.72,
        stroke: colors.accent, opacity: 0.85, width: Math.max(3, size * 0.09),
    });
}

/**
 * Build the whole poster as SVG source.
 *
 * @param {object} data   the program_poster_data payload
 * @param {object} opts   {format, dayId, fontCss, heroDataUri, qrMarkup, measure, showQr}
 */
export function buildProgramPosterSvg(data, opts = {}) {
    const format = POSTER_FORMATS[opts.format] || POSTER_FORMATS.a4;
    const measure = opts.measure || makeMeasurer();
    const colors = data.colors || {};
    const fonts = {
        display: (data.fonts && data.fonts.display) || 'Unbounded',
        body: (data.fonts && data.fonts.body) || 'Space Grotesk',
    };
    const copy = data.text || {};
    const { day, items } = posterItems(data, opts.dayId);
    const note = opts.showNote === false ? '' : (data.note || '');

    const body = format.layout === 'split'
        ? splitLayout({ format, data, copy, day, items, colors, fonts, measure, opts, note })
        : portraitLayout({ format, data, copy, day, items, colors, fonts, measure, opts, note });

    return [
        `<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"`,
        ` width="${format.width}" height="${format.height}"`,
        ` viewBox="0 0 ${format.width} ${format.height}">`,
        defs(format, colors, opts.fontCss || '', opts.heroDataUri || ''),
        backdrop(format, colors, opts.heroDataUri || ''),
        body,
        '</svg>',
    ].join('');
}

/** A4: a centred bill — organiser, title, the list, then the small print. */
function portraitLayout({ format, copy, day, items, colors, fonts, measure, opts, note }) {
    const m = METRICS.portrait;
    const W = format.width;
    const H = format.height;
    const centre = W / 2;
    const contentW = W - m.margin * 2;
    const out = [];

    // A hairline frame makes it read as a poster rather than a screenshot.
    out.push(box({
        x: m.margin * 0.42, y: m.margin * 0.42,
        w: W - m.margin * 0.84, h: H - m.margin * 0.84,
        rx: 48, fill: null, stroke: colors.text, strokeOpacity: 0.18, strokeWidth: 3,
    }));

    let y = m.top;

    const eyebrow = fitLine(
        ((copy.organizer || '') + ' presents').toUpperCase(),
        contentW, m.eyebrow, m.eyebrow * 0.7, measure, fonts.body, 700, 0.34
    );
    out.push(text(eyebrow.text, {
        x: centre, y, size: eyebrow.size, family: fonts.body, weight: 700,
        fill: colors.accent, spacing: eyebrow.size * 0.34, anchor: 'middle',
    }));
    y += m.eyebrow * 1.1 + 90;

    const title = fitBlock(copy.title || 'Programme', contentW, m.titleLines, m.titleMax, m.titleMin, measure, fonts.display, 800);
    title.lines.forEach((lineText, index) => {
        y += index === 0 ? title.size * 0.78 : title.size * 1.04;
        out.push(text(lineText, {
            x: centre, y, size: title.size, family: fonts.display, weight: 800,
            fill: colors.text, anchor: 'middle',
        }));
    });

    if (copy.edition) {
        y += m.edition * 1.5;
        out.push(text(copy.edition, {
            x: centre, y, size: m.edition, family: fonts.display, weight: 400,
            fill: colors.accent, anchor: 'middle', spacing: m.edition * 0.04,
        }));
    }

    if (copy.tagline) {
        const tagline = fitBlock(copy.tagline, contentW * 0.82, 2, m.tagline, m.tagline * 0.72, measure, fonts.body, 400);
        tagline.lines.forEach((lineText, index) => {
            y += index === 0 ? tagline.size * 1.9 : tagline.size * 1.3;
            out.push(text(lineText, {
                x: centre, y, size: tagline.size, family: fonts.body,
                fill: colors.text, opacity: 0.78, anchor: 'middle',
            }));
        });
    }

    const when = [day && day.date, day && (day.doors || day.start)]
        .map((part) => String(part || '').trim()).filter(Boolean).join('   ·   ');
    const spacedMeasure = withSpacing(measure, 0.16);
    const oneLine = [when, copy.venue].filter(Boolean).join('   ·   ').toUpperCase();
    const metaLines = spacedMeasure(oneLine, m.meta, fonts.body, 700) <= contentW
        ? [oneLine]
        : [when.toUpperCase(), String(copy.venue || '').toUpperCase()].filter(Boolean);

    metaLines.forEach((lineText, index) => {
        y += index === 0 ? m.meta * 2.4 : m.meta * 1.5;
        out.push(text(ellipsise(lineText, contentW, m.meta, spacedMeasure, fonts.body, 700), {
            x: centre, y, size: m.meta, family: fonts.body, weight: 700,
            fill: colors.text, opacity: index === 0 ? 0.9 : 0.72, anchor: 'middle', spacing: m.meta * 0.16,
        }));
    });

    y += m.heading * 2.6;
    out.push(sectionHeading(copy.heading || 'The programme', {
        x: centre, y, size: m.heading, anchor: 'middle', colors, fonts,
        maxWidth: contentW * 0.8, measure,
    }));
    y += m.heading * 2.2;

    // --- the list ---------------------------------------------------------
    const footerTop = H - m.footer;
    const region = { x: m.margin, y, w: contentW, h: footerTop - 70 - y };
    const plan = planPosterRows(items.length || 1, region.h, m.rows);
    out.push(posterList(items, region, plan, m, colors, fonts, measure));

    // --- footer -----------------------------------------------------------
    out.push(line({
        x1: m.margin, y1: footerTop, x2: W - m.margin, y2: footerTop,
        stroke: colors.text, opacity: 0.2, width: 2,
    }));

    const qrRect = posterQrRect('a4');
    const qr = opts.qrMarkup ? qrRect.width : 0;
    out.push(text(copy.url || '', {
        x: m.margin, y: footerTop + 96, size: 52, family: fonts.display, weight: 700, fill: colors.text,
    }));
    if (note) {
        out.push(text(ellipsise(note, contentW - qr - 60, 40, measure, fonts.body, 400), {
            x: m.margin, y: footerTop + 160, size: 40, family: fonts.body,
            fill: colors.text, opacity: 0.6,
        }));
    }
    out.push(text('The full programme, directions and updates are on the page.', {
        x: m.margin, y: footerTop + 220, size: 36, family: fonts.body, fill: colors.text, opacity: 0.45,
    }));

    if (opts.qrMarkup) {
        out.push(opts.qrMarkup);
        out.push(text('Scan for the page', {
            x: qrRect.x + qrRect.width / 2, y: qrRect.y + qrRect.height + 46, size: 32, family: fonts.body,
            fill: colors.text, opacity: 0.6, anchor: 'middle',
        }));
    }

    return out.join('');
}

/** 16:9: a title panel on the left, the running order on the right. */
function splitLayout({ format, copy, day, items, colors, fonts, measure, opts, note }) {
    const m = METRICS.split;
    const W = format.width;
    const H = format.height;
    const panel = m.panel;
    const out = [];

    // Panel wash, so the title stays legible over a busy photograph.
    out.push(box({ x: 0, y: 0, w: panel, h: H, fill: colors.bg, opacity: 0.55 }));
    out.push(line({ x1: panel, y1: m.margin, x2: panel, y2: H - m.margin, stroke: colors.text, opacity: 0.16, width: 2 }));

    const innerW = panel - m.margin * 2;
    // The panel block is built into its own list first: once its height is
    // known it can be centred, so a one-word title does not leave a hole.
    const head = [];
    let y = m.top;

    const eyebrow = fitLine(
        ((copy.organizer || '') + ' presents').toUpperCase(),
        innerW, m.eyebrow, m.eyebrow * 0.7, measure, fonts.body, 700, 0.3
    );
    head.push(text(eyebrow.text, {
        x: m.margin, y, size: eyebrow.size, family: fonts.body, weight: 700,
        fill: colors.accent, spacing: eyebrow.size * 0.3,
    }));
    y += 56;

    const title = fitBlock(copy.title || 'Programme', innerW, m.titleLines, m.titleMax, m.titleMin, measure, fonts.display, 800);
    title.lines.forEach((lineText, index) => {
        y += index === 0 ? title.size * 0.8 : title.size * 1.06;
        head.push(text(lineText, { x: m.margin, y, size: title.size, family: fonts.display, weight: 800, fill: colors.text }));
    });

    if (copy.edition) {
        y += m.edition * 1.5;
        head.push(text(copy.edition, { x: m.margin, y, size: m.edition, family: fonts.display, fill: colors.accent }));
    }

    if (copy.tagline) {
        const tagline = fitBlock(copy.tagline, innerW, 3, m.tagline, m.tagline * 0.78, measure, fonts.body, 400);
        tagline.lines.forEach((lineText, index) => {
            y += index === 0 ? tagline.size * 2 : tagline.size * 1.35;
            head.push(text(lineText, { x: m.margin, y, size: tagline.size, family: fonts.body, fill: colors.text, opacity: 0.76 }));
        });
    }

    const metaLines = [
        [day && day.date, ''].filter(Boolean)[0],
        [day && day.doors, day && day.start ? 'Starts ' + day.start : ''].filter(Boolean).join('  ·  '),
        copy.venue,
    ].map((part) => String(part || '').trim()).filter(Boolean);

    y += m.meta * 1.6;
    metaLines.forEach((lineText, index) => {
        y += index === 0 ? m.meta * 1.2 : m.meta * 1.45;
        head.push(text(ellipsise(lineText, innerW, m.meta, measure, fonts.body, index === 0 ? 700 : 400), {
            x: m.margin, y, size: m.meta, family: fonts.body, weight: index === 0 ? 700 : 400,
            fill: colors.text, opacity: index === 0 ? 0.95 : 0.7,
        }));
    });

    // Centre what we built between the top margin and the footer block.
    const headRoom = H - m.margin - 150 - m.top;
    const shift = Math.max(0, (headRoom - (y - m.top)) / 2);
    out.push(`<g transform="translate(0 ${n(shift)})">${head.join('')}</g>`);

    // Bottom of the panel: the address of the page, and the code for it.
    const footY = H - m.margin - 10;
    if (opts.qrMarkup) out.push(opts.qrMarkup);
    const textX = m.margin + (opts.qrMarkup ? posterQrRect('screen').width + 26 : 0);
    out.push(text(copy.url || '', {
        x: textX, y: footY - 46, size: 30, family: fonts.display, weight: 700, fill: colors.text,
    }));
    if (note) {
        const noteBlock = fitBlock(note, innerW - (textX - m.margin), 2, 22, 17, measure, fonts.body, 400);
        noteBlock.lines.forEach((lineText, index) => {
            out.push(text(lineText, {
                x: textX, y: footY - 10 + index * noteBlock.size * 1.25, size: noteBlock.size,
                family: fonts.body, fill: colors.text, opacity: 0.6,
            }));
        });
    }

    // --- the list ---------------------------------------------------------
    const listX = panel + m.margin;
    const listW = W - listX - m.margin;

    out.push(sectionHeading(copy.heading || 'The programme', {
        x: listX, y: m.top + 6, size: m.heading, anchor: 'start', colors, fonts,
        maxWidth: listW * 0.7, measure,
    }));

    const region = { x: listX, y: m.top + m.heading * 2.2, w: listW, h: 0 };
    region.h = H - m.margin - region.y;
    const plan = planPosterRows(items.length || 1, region.h, m.rows);
    out.push(posterList(items, region, plan, m, colors, fonts, measure));

    return out.join('');
}

// --------------------------------------------------------------------------
// The Studio card
// --------------------------------------------------------------------------

/**
 * Save a blob to the visitor's downloads.
 *
 * Deliberately NOT shareOrDownload(): a poster is made at a desk, on the
 * way to a printer or a USB stick, and a share sheet in the middle of that
 * is a detour. Click, file, done.
 */
function downloadBlob(blob, filename) {
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    link.remove();
    setTimeout(() => URL.revokeObjectURL(url), 10000);
}

/** Fetch a same-origin image and inline it, because SVG cannot fetch. */
async function heroDataUri(path) {
    if (!path) return '';
    try {
        const { blobToDataUri } = await import('@se/core/svg.js');
        const response = await fetch(path, { credentials: 'same-origin' });
        if (!response.ok) return '';

        return await blobToDataUri(await response.blob());
    } catch (e) {
        console.warn('[se] poster hero could not be inlined', e);

        return '';
    }
}

/**
 * Put the event's own fonts in the Studio document before anything is
 * measured.
 *
 * The layout asks a canvas how wide a title is, and a canvas answers for
 * the font it actually has. Without this the browser measures Arial, lays
 * the poster out for Arial, and then draws it in Unbounded — which is a
 * third wider. The @font-face CSS is the same one that gets embedded in the
 * SVG, so this costs no extra network.
 */
async function primeFonts(fontCss, families) {
    if (typeof document === 'undefined') return;
    try {
        if (fontCss) {
            let style = document.getElementById('se-poster-fonts');
            if (!style) {
                style = document.createElement('style');
                style.id = 'se-poster-fonts';
                const nonce = document.querySelector('style[nonce]')?.nonce;
                if (nonce) style.setAttribute('nonce', nonce);
                document.head.appendChild(style);
            }
            if (style.textContent !== fontCss) style.textContent = fontCss;
        }
        if (!document.fonts) return;
        await Promise.all(families.filter(Boolean).flatMap(
            (family) => [400, 700, 800].map((weight) => document.fonts.load(`${weight} 48px "${family}"`).catch(() => {}))
        ));
    } catch (e) {
        /* measuring in the fallback face is survivable — see makeMeasurer */
    }
}

/** The QR as serialised markup, so the builder itself stays DOM-free. */
async function qrMarkupFor(value, rect) {
    try {
        const { qrGroup } = await import('@se/core/svg.js');

        return new XMLSerializer().serializeToString(qrGroup(value, rect));
    } catch (e) {
        return '';
    }
}

export function ProgramPosterCard({ event }) {
    const [data, setData] = useState(null);
    const [error, setError] = useState('');
    const [format, setFormat] = useState('a4');
    const [dayId, setDayId] = useState(null);
    const [withQr, setWithQr] = useState(true);
    const [preview, setPreview] = useState('');
    const [busy, setBusy] = useState('');
    const [previewUrl, setPreviewUrl] = useState('');
    const [assets, setAssets] = useState({ hero: '', fontCss: '' });

    useEffect(() => {
        let alive = true;
        setData(null);
        setError('');
        studio('program_poster_data', { id: event.id })
            .then((next) => {
                if (!alive) return;
                setData(next);
                setDayId(next.days?.[0]?.day_id ?? null);
            })
            .catch((caught) => { if (alive) setError(caught.message || 'The poster data could not load.'); });

        return () => { alive = false; };
    }, [event.id]);

    // The hero photo and the webfonts are fetched once per event and reused
    // by every redraw — they are the slow part, not the drawing.
    useEffect(() => {
        let alive = true;
        if (!data) return undefined;
        (async () => {
            const [hero, fontCss] = await Promise.all([
                heroDataUri(data.hero),
                import('@se/core/svg.js')
                    .then((mod) => mod.embedFontCss([data.fonts?.display, data.fonts?.body]))
                    .catch(() => ''),
            ]);
            await primeFonts(fontCss, [data.fonts?.display, data.fonts?.body]);
            if (alive) setAssets({ hero, fontCss });
        })();

        return () => { alive = false; };
    }, [data]);

    const qrRect = useMemo(() => posterQrRect(format), [format]);

    // Redraw whenever anything the picture depends on changes.
    useEffect(() => {
        let alive = true;
        if (!data) return undefined;
        (async () => {
            const qrMarkup = withQr ? await qrMarkupFor(data.qr, qrRect) : '';
            if (!alive) return;
            setPreview(buildProgramPosterSvg(data, {
                format, dayId, qrMarkup,
                fontCss: assets.fontCss,
                heroDataUri: assets.hero,
            }));
        })();

        return () => { alive = false; };
    }, [data, format, dayId, withQr, assets, qrRect]);

    // The preview carries the hero photo and two embedded fonts, so it is
    // handed to the <img> as a blob rather than a percent-encoded data URI.
    useEffect(() => {
        if (!preview) return undefined;
        const url = URL.createObjectURL(new Blob([preview], { type: 'image/svg+xml;charset=utf-8' }));
        setPreviewUrl(url);

        return () => URL.revokeObjectURL(url);
    }, [preview]);

    const download = async (type) => {
        setBusy(type);
        try {
            const { rasterise } = await import('@se/core/svg.js');
            const spec = POSTER_FORMATS[format];
            const blob = await rasterise(preview, spec.width, spec.height, {
                type: type === 'png' ? 'image/png' : 'image/jpeg',
                quality: 0.94,
                background: data.colors?.bg || '#0B0D13',
            });
            downloadBlob(blob, posterFilename(data.filename, format, type));
            toast('Poster saved to your downloads.', 'success');
        } catch (caught) {
            toast(caught.message || 'The poster could not be made.', 'error');
        } finally {
            setBusy('');
        }
    };

    if (error) {
        return html`<${Card} title="Programme poster">
            <p class="text-sm text-gray-600">${error}</p>
        <//>`;
    }
    if (!data) return html`<${Card} title="Programme poster"><${Spinner} label="Loading the poster…" /><//>`;

    const { items } = posterItems(data, dayId);
    const spec = POSTER_FORMATS[format];

    return html`
        <${Card} title="Programme poster"
            subtitle="The run of show as one picture, over the page's hero photo. A4 to print, 16:9 for the screens."
            actions=${html`
                <div class="flex flex-wrap items-center gap-2">
                    ${Object.values(POSTER_FORMATS).map((option) => html`
                        <button key=${option.key} type="button"
                            class=${'px-3 py-2 rounded-full text-sm min-h-[44px] ' +
                                (format === option.key ? 'bg-hodBlue text-white' : 'bg-gray-100 text-gray-600')}
                            aria-pressed=${format === option.key}
                            onClick=${() => setFormat(option.key)}>${option.label}</button>`)}
                </div>`}>

            ${!items.length ? html`
                <${EmptyState} title="Nothing to put on it yet"
                    message="Add the first items to the run of show above and the poster draws itself." />`
            : html`
            <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_18rem]">
                <div class="rounded-2xl bg-gray-900 p-4 flex items-center justify-center overflow-hidden">
                    ${previewUrl
                        ? html`<img src=${previewUrl} alt=${'Preview of the ' + spec.label}
                                class="max-h-[32rem] w-auto max-w-full rounded-lg shadow-lg" />`
                        : html`<${Spinner} label="Drawing…" />`}
                </div>

                <div class="space-y-5">
                    <p class="text-xs text-gray-400">
                        ${spec.width} × ${spec.height} px · ${spec.hint}
                    </p>

                    ${(data.days || []).length > 1 ? html`
                        <${Field} label="Which day" name="poster-day" hint="One day per poster keeps it readable.">
                            <${Select} name="poster-day" value=${String(dayId)}
                                options=${(data.days || []).map((day) => ({ value: String(day.day_id), label: day.date }))}
                                onChange=${(value) => setDayId(Number(value))} />
                        <//>` : null}

                    <${Switch} label="Show the QR code" checked=${withQr}
                        hint="Points at the event page."
                        onChange=${setWithQr} />

                    <div class="flex flex-wrap gap-2">
                        <${Button} onClick=${() => download('jpg')} loading=${busy === 'jpg'} disabled=${!preview || !!busy}>
                            Download JPEG
                        <//>
                        <${Button} variant="secondary" onClick=${() => download('png')}
                            loading=${busy === 'png'} disabled=${!preview || !!busy}>PNG<//>
                    </div>

                    <p class="text-xs text-gray-400">
                        ${items.length} items, drawn to fit — long programmes shrink and split into two
                        columns rather than run off the page.
                        ${data.hero ? '' : ' Add a hero image in Brand and it becomes the background.'}
                    </p>
                </div>
            </div>`}
        <//>`;
}
