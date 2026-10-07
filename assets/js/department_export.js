// /assets/js/department_export.js
//
// "Download the workers of every department" — the A4 image renderer.
//
// WHY THIS IS A CANVAS RENDERER AND NOT A DOM CAPTURE
// --------------------------------------------------
// Same reasoning as assets/js/celebrants_export.js: html2canvas / html-to-image
// need a laid-out DOM node, and the result then depends on the browser's own
// font metrics, its flex-shrink behaviour and how far it has got decoding <img>
// elements. A roster sheet that must land on exactly ONE A4 page cannot be left
// to that. Geometry here is arithmetic, every string is measured with
// ctx.measureText before it is painted, and a fitter chooses the largest type
// size that still fits 297 mm.
//
// The fitter
// ----------
// Two things chew up vertical space: the type size, and the per-department
// furniture (leadership strip, PRIMARY/SECONDARY headings, card padding). So the
// sheet is laid out for every combination of four densities and every type size
// from large to small, and the best combination wins:
//
//   1 comfortable  name columns, full leadership block   (prettiest)
//   2 compact      name columns, leadership inline
//   3 tight        names as flowing lines, leadership block
//   4 dense        names as flowing lines, leadership inline
//
// Names are never truncated: the column count for a department is derived from
// its own longest name, and in flowing mode the line breaks are computed from
// measured widths. 507 people on one A4 page is therefore possible, just small.

(function (global) {
    'use strict';

    // ── Page geometry ───────────────────────────────────────────────────────
    // Logical px: 1240 x 1754 is A4 portrait at 150 dpi. The canvas is rendered
    // at SCALE times that (300 dpi) so the JPEG is print quality.
    const PAGE_W = 1240;
    const PAGE_H = 1754;
    const SCALE  = 2;

    const INK      = '#0A0E17';   // hodBlue
    const BRAND    = '#D11920';   // hodRed
    const NAVY     = '#152750';
    const BODY     = '#1F2937';
    const MUTED    = '#6B7280';
    const FAINT    = '#9CA3AF';
    const HAIRLINE = '#E5E7EB';
    const CARD_BG  = '#FFFFFF';
    const SUB_BG   = '#F8FAFC';
    const PAPER    = '#FFFFFF';

    const FONT_STACK = '"Inter","Helvetica Neue",Helvetica,Arial,sans-serif';
    const HEAD_FONT  = '"Montserrat","Inter","Helvetica Neue",Helvetica,Arial,sans-serif';

    const LEADER_ROLES = ['Assoc_Pastor', 'Director', 'HOD'];
    const ROLE_LABEL = {
        Assoc_Pastor: 'Pastor in Charge',
        Director: 'Director in Charge',
        HOD: 'Head of Department',
        Sub_Unit_Head: 'Sub-Unit Head',
        Worker: 'Worker',
        Member: 'Member'
    };

    const DENSITIES = [
        { key: 'comfortable', label: 'Comfortable', nameMode: 'grid', leaderMode: 'rows' },
        { key: 'compact',     label: 'Compact',     nameMode: 'grid', leaderMode: 'inline' },
        { key: 'tight',       label: 'Tight',       nameMode: 'flow', leaderMode: 'rows' },
        { key: 'dense',       label: 'Dense',       nameMode: 'flow', leaderMode: 'inline' }
    ];

    // ── Fonts & images ──────────────────────────────────────────────────────
    // The sheet uses the app's own webfonts (Montserrat headings, Inter text). If
    // Google Fonts is unreachable the canvas falls back to the system stack —
    // measurements happen after the fonts settle, so the fit still holds.
    let fontsReady = null;
    function ensureFonts() {
        if (fontsReady) {
            return fontsReady;
        }
        const wanted = [
            '700 20px Montserrat', '800 20px Montserrat', '600 20px Montserrat',
            '400 16px Inter', '500 16px Inter', '600 16px Inter', '700 16px Inter'
        ];
        const load = (f) => (global.document && document.fonts && document.fonts.load ? document.fonts.load(f) : Promise.resolve());
        fontsReady = Promise.race([
            Promise.all(wanted.map(load)),
            new Promise((resolve) => setTimeout(resolve, 2500))
        ]).catch(() => {});
        return fontsReady;
    }

    function loadImage(src) {
        return new Promise((resolve) => {
            const img = new Image();
            img.onload = () => resolve(img);
            img.onerror = () => resolve(null);
            img.src = src;
        });
    }

    async function decodeImage(img) {
        if (!img) {
            return null;
        }
        try {
            await img.decode();
        } catch (e) { /* onload already fired on older engines */ }
        return img;
    }

    // ── Drawing helpers ─────────────────────────────────────────────────────
    function roundRect(ctx, x, y, w, h, r) {
        const radius = Math.max(0, Math.min(r, w / 2, h / 2));
        ctx.beginPath();
        ctx.moveTo(x + radius, y);
        ctx.lineTo(x + w - radius, y);
        ctx.quadraticCurveTo(x + w, y, x + w, y + radius);
        ctx.lineTo(x + w, y + h - radius);
        ctx.quadraticCurveTo(x + w, y + h, x + w - radius, y + h);
        ctx.lineTo(x + radius, y + h);
        ctx.quadraticCurveTo(x, y + h, x, y + h - radius);
        ctx.lineTo(x, y + radius);
        ctx.quadraticCurveTo(x, y, x + radius, y);
        ctx.closePath();
    }

    function drawText(ctx, str, x, y, f, color, align) {
        ctx.font = f;
        ctx.fillStyle = color;
        ctx.textAlign = align || 'left';
        ctx.textBaseline = 'alphabetic';
        ctx.fillText(str, x, y);
    }

    function ellipsize(ctx, str, maxW) {
        if (ctx.measureText(str).width <= maxW) {
            return str;
        }
        let out = str;
        while (out.length > 4 && ctx.measureText(out + '…').width > maxW) {
            out = out.slice(0, -1);
        }
        return out + '…';
    }

    function font(weight, size, head) {
        return weight + ' ' + Math.max(3.2, size).toFixed(2) + 'px ' + (head ? HEAD_FONT : FONT_STACK);
    }

    /** Greedy word wrap, used for the leadership strip and other prose. */
    function wrapText(ctx, str, maxW) {
        const words = String(str).split(/\s+/);
        const lines = [];
        let line = '';
        words.forEach((word) => {
            const candidate = line ? line + ' ' + word : word;
            if (ctx.measureText(candidate).width > maxW && line) {
                lines.push(line);
                line = word;
            } else {
                line = candidate;
            }
        });
        if (line) {
            lines.push(line);
        }
        return lines;
    }

    /**
     * Greedy wrap of whole names separated by " · ". A name is never split, and a
     * separator never starts a line. Returns rows of positioned segments so the
     * sub-unit-head bullet can still be drawn in brand red.
     */
    function flowNames(ctx, members, maxW, separatorW, headPad) {
        const rows = [];
        let row = [];
        let x = 0;

        members.forEach((m, index) => {
            const label = m.name;
            const w = ctx.measureText(label).width + (m.head ? headPad : 0);
            const sepW = index === 0 ? 0 : separatorW;

            if (row.length && x + sepW + w > maxW) {
                rows.push(row);
                row = [];
                x = 0;
            }
            if (row.length) {
                row.push({ kind: 'sep', text: '·', x: x + separatorW * 0.35 });
                x += separatorW;
            }
            row.push({ kind: m.head ? 'head' : 'name', text: label, x: x });
            x += w;
        });
        if (row.length) {
            rows.push(row);
        }
        return rows;
    }

    // ── Card model ──────────────────────────────────────────────────────────
    function collectionValues(value) {
        if (Array.isArray(value)) {
            return value;
        }
        return value && typeof value === 'object' ? Object.values(value) : [];
    }

    function buildCards(data) {
        const cards = [];
        collectionValues(data.departments).forEach((dept) => {
            cards.push(makeCard(dept, 0));
            collectionValues(dept.sub_units).forEach((sub) => cards.push(makeCard(sub, 1)));
        });
        return cards;
    }

    function makeCard(dept, depth) {
        const leaders = [];
        LEADER_ROLES.forEach((role) => {
            const seat = dept.leaders ? dept.leaders[role] : null;
            if (seat && seat.name) {
                leaders.push({ role: role, label: ROLE_LABEL[role], name: seat.name });
            }
        });

        const bucket = (list) => (list || []).map((m) => ({ name: m.name, head: !!m.sub_unit_head }));

        return {
            id: dept.id,
            depth: depth,
            name: dept.name,
            parentName: dept.parent_name || null,
            type: dept.type,
            leaders: leaders,
            primary: bucket(dept.primary),
            secondary: bucket(dept.secondary)
        };
    }

    // ── Layout for one (density, scale) candidate ───────────────────────────
    function marginFor(s) {
        return Math.round(46 - (1 - s) * 20);          // 46 → 26 at the smallest scale
    }

    function layoutFor(ctx, data, s, density) {
        const sizes = {
            church: 25 * s,
            title: 14 * s,
            meta: 9 * s,
            stat: 10.5 * s,
            dept: 11.6 * s,
            deptMeta: 8.2 * s,
            leader: 8.8 * s,
            sectionLabel: 7.8 * s,
            member: 9.6 * s,
            legend: 7.4 * s
        };

        const margin = marginFor(s);
        const contentW = PAGE_W - margin * 2;
        const padX = Math.max(8, 16 * s);
        const padY = Math.max(5, 12 * s);
        const lineH = sizes.member * 1.62;
        const gapX = Math.max(10, sizes.member * 2.0);

        const cards = buildCards(data).map((card) => {
            const indent = card.depth * Math.round(22 * Math.max(0.6, s));
            const cardW = contentW - indent;
            const innerW = cardW - padX * 2 - 8;

            const titleH = sizes.dept * 1.25;

            // Leadership strip
            let leaderLines = [];
            if (density.leaderMode === 'rows') {
                card.leaders.forEach((l) => {
                    wrapText(ctx, l.label + ':  ' + l.name, innerW, font(500, sizes.leader))
                        .forEach((line) => leaderLines.push(line));
                });
            } else if (card.leaders.length) {
                const inline = card.leaders.map((l) => l.label + ': ' + l.name).join('  ·  ');
                wrapText(ctx, inline, innerW, font(500, sizes.leader)).forEach((line) => leaderLines.push(line));
            }
            const leaderH = leaderLines.length ? leaderLines.length * sizes.leader * 1.45 + 3 * s : 0;

            // Member sections
            const sections = [];
            [['Primary', card.primary], ['Secondary', card.secondary]].forEach(([label, members]) => {
                if (!members.length) {
                    return;
                }
                ctx.font = font(400, sizes.member);
                let rows = [];
                let cols = 1;
                let cellW = innerW;

                if (density.nameMode === 'grid') {
                    // As many columns as fit, each wide enough for this
                    // department's longest name (so nothing is ever cut off).
                    let widest = sizes.member * 3.2;                 // ~7 characters minimum
                    members.forEach((m) => {
                        const w = ctx.measureText(m.name).width;
                        if (w > widest) {
                            widest = w;
                        }
                    });
                    cellW = widest + gapX;
                    cols = Math.max(1, Math.min(14, Math.floor((innerW + gapX) / cellW)));
                    cellW = innerW / cols;

                    const buckets = [];
                    members.forEach((m, i) => {
                        const row = Math.floor(i / cols);
                        buckets[row] = buckets[row] || [];
                        buckets[row].push({
                            kind: m.head ? 'head' : 'name',
                            text: m.name,
                            x: (i % cols) * cellW
                        });
                    });
                    rows = buckets;
                } else {
                    rows = flowNames(ctx, members, innerW, ctx.measureText('  ·  ').width, sizes.member * 1.35);
                }

                const labelH = sizes.sectionLabel * 1.5;
                sections.push({
                    label: label,
                    count: members.length,
                    cols: cols,
                    rows: rows,
                    lineH: lineH,
                    labelH: labelH,
                    height: labelH + rows.length * lineH + 4 * s
                });
            });

            const height = padY * 2 + titleH + leaderH + sections.reduce((sum, sec) => sum + sec.height, 0) + 6 * s;

            return {
                card: card,
                indent: indent,
                x: margin + indent,
                cardW: cardW,
                innerW: innerW,
                padX: padX,
                padY: padY,
                titleH: titleH,
                leaderLines: leaderLines,
                leaderH: leaderH,
                sections: sections,
                height: height
            };
        });

        const mastheadH = Math.round(128 * Math.max(0.7, Math.min(1.22, 0.34 + s * 0.66)));
        const footerH = Math.round(38 * Math.max(0.75, Math.min(1, 0.4 + s * 0.6)));
        const body = cards.reduce((sum, c) => sum + c.height, 0);
        const gap = Math.max(4, 10 * s);
        const gaps = Math.max(0, cards.length - 1) * gap;
        const total = margin * 2 + mastheadH + 16 + body + gaps + footerH;

        return {
            scale: s,
            density: density,
            sizes: sizes,
            margin: margin,
            contentW: contentW,
            mastheadH: mastheadH,
            footerH: footerH,
            cards: cards,
            gap: gap,
            body: body,
            gaps: gaps,
            total: total,
            fits: total <= PAGE_H
        };
    }

    /**
     * Largest, prettiest layout that fits A4. Type size is the primary goal — a
     * density tier is worth only a 2% type-size bonus, so the sheet drops to
     * flowing names only when that genuinely buys readable type.
     */
    function fit(ctx, data) {
        let best = null;
        let bestScore = -Infinity;

        DENSITIES.forEach((density) => {
            const beauty = (DENSITIES.length - 1 - DENSITIES.indexOf(density)) * 0.02;
            for (let raw = 1.6; raw >= 0.3; raw -= 0.02) {
                const s = Number(raw.toFixed(3));
                const layout = layoutFor(ctx, data, s, density);
                if (!layout.fits) {
                    continue;                       // smaller candidates only
                }
                const score = s + beauty;
                if (score > bestScore) {
                    bestScore = score;
                    best = layout;
                }
                break;                              // biggest s that fits this density
            }
        });

        if (best) {
            return best;
        }

        // Nothing fits even at the smallest comfortable size: take the densest
        // arrangement at the floor. The user has explicitly asked for one page,
        // so the sheet is squeezed rather than split.
        let fallback = null;
        for (let raw = 0.6; raw >= 0.16; raw -= 0.02) {
            const layout = layoutFor(ctx, data, Number(raw.toFixed(3)), DENSITIES[DENSITIES.length - 1]);
            fallback = layout;
            if (layout.fits) {
                return layout;
            }
        }
        return fallback;
    }

    // ── Painting ────────────────────────────────────────────────────────────
    function drawMasthead(ctx, data, layout, logo) {
        const s = layout.sizes;
        const year = data.year || {};
        const totals = data.totals || {};
        const m = layout.margin;
        const y0 = m;
        const h = layout.mastheadH;

        roundRect(ctx, m, y0, layout.contentW, h, 12);
        ctx.fillStyle = INK;
        ctx.fill();

        // Brand rule across the top of the dark masthead.
        ctx.save();
        roundRect(ctx, m, y0, layout.contentW, h, 12);
        ctx.clip();
        ctx.fillStyle = BRAND;
        ctx.fillRect(m, y0, layout.contentW, Math.max(3, 5 * (s.title / 14)));
        ctx.fillStyle = NAVY;
        ctx.fillRect(m, y0 + Math.max(3, 5 * (s.title / 14)), layout.contentW, 2);
        ctx.restore();

        const padX = Math.max(14, 20 * Math.min(1, s.church / 25 + 0.25));
        const logoSize = Math.min(h - 30, 76 * Math.max(0.62, s.church / 25));
        const logoX = m + padX;
        const logoY = y0 + (h - logoSize) / 2;
        if (logo) {
            ctx.save();
            roundRect(ctx, logoX, logoY, logoSize, logoSize, 8);
            ctx.clip();
            ctx.fillStyle = '#FFFFFF';
            ctx.fillRect(logoX, logoY, logoSize, logoSize);
            ctx.drawImage(logo, logoX, logoY, logoSize, logoSize);
            ctx.restore();
        } else {
            roundRect(ctx, logoX, logoY, logoSize, logoSize, 8);
            ctx.fillStyle = '#FFFFFF';
            ctx.fill();
            drawText(ctx, 'HOD', logoX + logoSize / 2, logoY + logoSize * 0.62, font(800, s.church * 0.42, true), INK, 'center');
        }

        const textX = logoX + logoSize + 16;
        const rightX = m + layout.contentW - padX;
        const top = y0 + h * 0.30;

        // Never let a long church name or period run under the right-hand stamp.
        const headMaxW = Math.max(60, rightX - textX - 12);
        const churchName = (data.church && data.church.name) || 'Household of David';
        const nameFont = font(800, s.church, true);
        ctx.font = nameFont;
        // Shrink the name before truncating it — a clipped church name looks broken.
        let nameSize = s.church;
        while (nameSize > s.church * 0.7 && ctx.measureText(churchName).width > headMaxW) {
            nameSize -= 0.5;
            ctx.font = font(800, nameSize, true);
        }
        drawText(ctx, ellipsize(ctx, churchName, headMaxW), textX, top, font(800, nameSize, true), '#FFFFFF');

        const yearLine = year.heading || 'Ministry Year';
        ctx.font = font(600, s.title);
        drawText(ctx, ellipsize(ctx, yearLine, headMaxW), textX, top + s.title * 1.9, font(600, s.title), '#FCA5A5');

        const people = (totals.primary || 0) + (totals.secondary || 0);
        const sub = [
            totals.departments ? totals.departments + ' departments' : '',
            (totals.primary || 0) + ' primary',
            (totals.secondary || 0) + ' secondary',
            people ? people + ' people in total' : ''
        ].filter(Boolean).join('   ·   ');
        ctx.font = font(400, s.meta);
        drawText(ctx, ellipsize(ctx, sub, headMaxW), textX, top + s.title * 1.9 + s.meta * 2.1, font(400, s.meta), '#CBD5E1');

        // Right-hand block: generated stamp + page note.
        drawText(ctx, 'Generated ' + (data.generated_at || ''), rightX, y0 + h * 0.42, font(500, s.meta), '#CBD5E1', 'right');
        drawText(ctx, 'A4 · one page', rightX, y0 + h * 0.42 + s.meta * 1.8, font(400, s.meta), '#94A3B8', 'right');

        return y0 + h;
    }

    function drawCard(ctx, item, y, layout) {
        const s = layout.sizes;
        const sc = layout.scale;
        const card = item.card;
        const x = item.x;
        const h = item.height;

        roundRect(ctx, x, y, item.cardW, h, 9);
        ctx.fillStyle = card.depth ? CARD_BG : CARD_BG;
        ctx.fill();
        ctx.strokeStyle = HAIRLINE;
        ctx.lineWidth = 1;
        ctx.stroke();

        // Left accent bar; sub-units get a thin navy one and a tinted body.
        ctx.save();
        roundRect(ctx, x, y, item.cardW, h, 9);
        ctx.clip();
        if (card.depth) {
            ctx.fillStyle = SUB_BG;
            ctx.fillRect(x, y, item.cardW, h);
            ctx.fillStyle = NAVY;
            ctx.fillRect(x, y, Math.max(2, 3 * sc), h);
        } else {
            ctx.fillStyle = BRAND;
            ctx.fillRect(x, y, Math.max(3, 4 * sc), h);
        }
        ctx.restore();

        let cursor = y + item.padY;

        // Title row
        const titleStr = (card.depth ? '↳  ' : '') + card.name + (card.parentName ? '   ·   under ' + card.parentName : '');
        const titleFont = font(700, s.dept, true);
        const countText = [
            card.primary.length ? card.primary.length + ' primary' : '',
            card.secondary.length ? card.secondary.length + ' secondary' : '',
            card.leaders.length ? card.leaders.length + ' leader' + (card.leaders.length > 1 ? 's' : '') : ''
        ].filter(Boolean).join('   ·   ');
        const countFont = font(600, s.deptMeta);
        ctx.font = countFont;
        const countW = ctx.measureText(countText).width;

        cursor += item.titleH * 0.82;
        drawText(ctx, ellipsize(ctx, titleStr, item.innerW - countW - 14), x + item.padX + 2, cursor, titleFont, INK);
        if (countText) {
            drawText(ctx, countText, x + item.cardW - item.padX, cursor, countFont, MUTED, 'right');
        }
        cursor += item.titleH * 0.3;

        // Leadership strip
        if (item.leaderLines.length) {
            const lf = font(500, s.leader);
            cursor += 2 * sc;
            item.leaderLines.forEach((line) => {
                cursor += s.leader * 1.3;
                drawText(ctx, line, x + item.padX + 2, cursor, lf, NAVY);
            });
            cursor += 3 * sc;
        }

        // Member sections
        item.sections.forEach((sec) => {
            cursor += sec.labelH * 0.92;
            drawText(ctx, sec.label.toUpperCase() + ' MEMBERS  (' + sec.count + ')',
                x + item.padX + 2, cursor, font(700, s.sectionLabel), sec.label === 'Primary' ? BRAND : FAINT);

            const memberFont = font(400, s.member);
            const headFont = font(600, s.member);
            const startY = cursor + sec.labelH * 0.55;

            sec.rows.forEach((row, rowIndex) => {
                const baseline = startY + (rowIndex + 1) * sec.lineH - sec.lineH * 0.3;
                row.forEach((seg) => {
                    const sx = x + item.padX + 2 + seg.x;
                    if (seg.kind === 'sep') {
                        drawText(ctx, seg.text, sx, baseline, font(400, s.member), FAINT);
                    } else if (seg.kind === 'head') {
                        // Brand bullet marks a sub-unit head.
                        ctx.beginPath();
                        ctx.arc(sx + s.member * 0.34, baseline - s.member * 0.29, Math.max(1.2, s.member * 0.19), 0, Math.PI * 2);
                        ctx.fillStyle = BRAND;
                        ctx.fill();
                        drawText(ctx, seg.text, sx + s.member * 1.35, baseline, headFont, INK);
                    } else {
                        drawText(ctx, seg.text, sx, baseline, memberFont, BODY);
                    }
                });
            });

            cursor = startY + Math.max(1, sec.rows.length) * sec.lineH - sec.lineH * 0.3 + 4 * sc;
        });

        return y + h;
    }

    function drawFooter(ctx, data, layout) {
        const s = layout.sizes;
        const m = layout.margin;
        const y = PAGE_H - m - layout.footerH * 0.45;

        ctx.save();
        ctx.strokeStyle = HAIRLINE;
        ctx.lineWidth = 1;
        ctx.beginPath();
        ctx.moveTo(m, y - layout.footerH * 0.72);
        ctx.lineTo(m + layout.contentW, y - layout.footerH * 0.72);
        ctx.stroke();
        ctx.restore();

        const year = data.year || {};
        drawText(ctx, ((data.church && data.church.name) || '') + '   ·   ' + (year.heading || ''),
            m, y, font(600, s.legend), NAVY);
        drawText(ctx, '• = Sub-Unit Head', m + layout.contentW / 2, y, font(400, s.legend), MUTED, 'center');
        drawText(ctx, 'Page 1 of 1', m + layout.contentW, y, font(600, s.legend), MUTED, 'right');
    }

    function drawEmpty(ctx, data, layout) {
        const y = layout.margin + layout.mastheadH + 70;
        drawText(ctx, 'No one is on a department roster for this ministry year yet.',
            PAGE_W / 2, y, font(600, 14), MUTED, 'center');
        drawText(ctx, 'Add members in the Departments module, then download again.',
            PAGE_W / 2, y + 24, font(400, 11.5), FAINT, 'center');
    }

    // ── Public API ──────────────────────────────────────────────────────────
    async function render(data, opts) {
        opts = opts || {};
        await ensureFonts();

        const probe = document.createElement('canvas');
        probe.width = PAGE_W;
        probe.height = PAGE_H;
        const ctx = probe.getContext('2d');

        const logo = await decodeImage(await loadImage((data.church && data.church.logo) || '/assets/images/logo_hod.png'));

        const layout = fit(ctx, data);

        const canvas = document.createElement('canvas');
        canvas.width = PAGE_W * SCALE;
        canvas.height = PAGE_H * SCALE;
        const out = canvas.getContext('2d');
        out.scale(SCALE, SCALE);

        out.fillStyle = PAPER;
        out.fillRect(0, 0, PAGE_W, PAGE_H);

        const afterHeader = drawMasthead(out, data, layout, logo);

        if (!layout.cards.length) {
            drawEmpty(out, data, layout);
        } else {
            // Spread any leftover vertical space between the cards so a short
            // sheet doesn't huddle at the top of the page.
            const available = PAGE_H - layout.margin - layout.footerH - 10 - (afterHeader + 16);
            const slack = Math.max(0, available - (layout.body + layout.gaps));
            const extra = layout.cards.length > 1
                ? Math.min(slack / (layout.cards.length - 1), Math.max(14, 26 * layout.scale))
                : 0;

            let y = afterHeader + 16;
            layout.cards.forEach((item, index) => {
                drawCard(out, item, y, layout);
                y += item.height + layout.gap + extra;
                if (index === layout.cards.length - 1) {
                    y -= layout.gap + extra;
                }
            });
        }

        drawFooter(out, data, layout);

        const names = layout.cards.reduce((sum, c) => sum
            + c.card.primary.length + c.card.secondary.length + c.card.leaders.length, 0);

        return {
            canvas: canvas,
            layout: layout,
            density: layout.density.key,
            densityLabel: layout.density.label,
            fontPx: Number(layout.sizes.member.toFixed(1)),
            nameCount: names,
            dense: layout.sizes.member < 7,
            overflows: !layout.fits,
            cards: layout.cards.length,
            totals: data.totals || {},
            filename: filenameFor(data)
        };
    }

    function filenameFor(data) {
        const label = ((data.year && data.year.label) || 'current').toString().replace(/[^A-Za-z0-9]+/g, '_');
        const d = new Date();
        const stamp = d.getFullYear() + String(d.getMonth() + 1).padStart(2, '0') + String(d.getDate()).padStart(2, '0');
        return 'Department_Rosters_' + label + '_' + stamp + '.jpg';
    }

    function download(canvas, filename) {
        return new Promise((resolve) => {
            const hand = (url) => {
                const link = document.createElement('a');
                link.href = url;
                link.download = filename || 'Department_Rosters.jpg';
                document.body.appendChild(link);
                link.click();
                link.remove();
                setTimeout(() => URL.revokeObjectURL(url), 30000);
                resolve(true);
            };
            if (canvas.toBlob) {
                canvas.toBlob((blob) => hand(URL.createObjectURL(blob)), 'image/jpeg', 0.93);
            } else {
                hand(canvas.toDataURL('image/jpeg', 0.93));
            }
        });
    }

    function openInTab(canvas) {
        canvas.toBlob((blob) => {
            const url = URL.createObjectURL(blob);
            global.open(url, '_blank');
            setTimeout(() => URL.revokeObjectURL(url), 60000);
        }, 'image/jpeg', 0.93);
    }

    global.HODDepartmentExport = {
        render: render,
        download: download,
        openInTab: openInTab,
        filenameFor: filenameFor,
        PAGE_W: PAGE_W,
        PAGE_H: PAGE_H,
        DENSITIES: DENSITIES
    };
})(window);
