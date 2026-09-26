// /assets/js/celebrants_export.js
//
// Charis "Celebrants Overview" slide renderer.
//
// WHY THIS IS A CANVAS RENDERER AND NOT DOM CAPTURE
// -------------------------------------------------
// The export used to rasterise a hidden DOM node with html-to-image, which
// wraps the markup in an SVG <foreignObject>. That is why a phone and a laptop
// produced different pictures from the same data:
//
//   * WebKit rasterises <foreignObject> before nested <img> elements have
//     finished decoding, so photos came out missing or half-drawn on iOS.
//   * The slide was laid out with CSS flexbox, so the browser's own font
//     metrics and flex-shrink decided the geometry. Four 460px cards plus a
//     200px logo column needed 2280px inside a 1920px frame, so every engine
//     shrank the row by a different amount and pushed the logo column clean
//     off the canvas.
//   * pixelRatio:1 pinned the result to 1920x1080, so "high-res" was not.
//   * An <a download> pointing at a data: URL is ignored by iOS Safari, so the
//     phone often produced no file at all.
//
// Everything here is drawn with the Canvas 2D API instead. Geometry is
// arithmetic rather than layout, images are awaited via decode() before
// anything is painted, type is measured against a webfont that is loaded
// first, and there is not a single emoji or system glyph in the output. Same
// input, same bytes, on every device.

(function (global) {
    'use strict';

    // ── Slide geometry (logical px; multiplied by SCALE on the real canvas) ──
    const SLIDE_W  = 1920;
    const SLIDE_H  = 1080;
    const SCALE    = 2;            // → 3840x2160
    const PAD      = 64;

    const CONTENT_TOP    = 222;    // below the header band
    const CONTENT_BOTTOM = SLIDE_H - PAD;
    const CARD_R         = 26;

    // A row of five or six needs tighter margins and a taller card to fill the
    // frame; four or fewer can breathe.
    function frameFor(n) {
        return (n >= 5)
            ? { pad: 48, gap: 20, minAspect: 0.40 }
            : { pad: 64, gap: 26, minAspect: 0.46 };
    }

    // A single row reads best, so the row holds up to six. Seven or more is
    // split across balanced pages.
    const MAX_PER_PAGE = 6;

    // Portrait crops are 9:16. With five or six on a row that would leave the
    // frame half empty, so a card may grow taller than its crop (the photo is
    // cover-cropped, never stretched) down to the minAspect in frameFor().
    const NATURAL_ASPECT = 9 / 16;   // 0.5625

    const INK       = '#0B0D12';
    const CARD_BG   = '#151922';
    const BRAND_RED = '#D11920';

    const BG_IMAGE      = '/assets/images/celebration-charis.webp';
    const LOGO_IMAGE    = '/assets/images/hod_logo.svg';
    const LOGO_FALLBACK = '/assets/images/logo_hod.png';

    const FONT = "'Montserrat', 'Inter', Arial, Helvetica, sans-serif";

    const TITLE      = 'MONTHLY CELEBRATIONS';
    const HANDLE     = '#HODLC';
    const SIGN_OFF   = 'WE LOVE YOU TOO';

    // Event accents, all chosen to read on a dark card.
    const ACCENTS = {
        Birthday:     '#F5A524',
        Anniversary:  '#7DD3FC',
        JC_Birthday:  '#86EFAC'
    };
    const ACCENT_DEFAULT = '#F5A524';

    // ── Small helpers ───────────────────────────────────────────────────────
    function accentColour(eventType) {
        return ACCENTS[eventType] || ACCENT_DEFAULT;
    }

    function eventLabel(eventType) {
        if (eventType === 'JC_Birthday') return 'JUNIOR CHURCH';
        return String(eventType || '').replace(/_/g, ' ').toUpperCase();
    }

    function fullName(person) {
        return `${person.first_name || ''} ${person.last_name || ''}`.replace(/\s+/g, ' ').trim();
    }

    function initials(person) {
        const f = (person.first_name || '?').trim().charAt(0);
        const l = (person.last_name || '').trim().charAt(0);
        return (f + l).toUpperCase();
    }

    /**
     * Load an image and guarantee it is decoded before we paint with it.
     *
     * Same-origin URLs go through fetch() so the bytes become a blob URL: that
     * keeps the canvas untainted (toBlob throws on a tainted canvas) and it is
     * what makes a phone wait for the real bytes instead of drawing a hole.
     */
    async function loadImage(src) {
        if (!src) return null;
        let objectUrl = null;
        try {
            let url = src;
            if (!/^data:/i.test(src)) {
                const res = await fetch(src, { credentials: 'same-origin' });
                if (!res.ok) throw new Error('HTTP ' + res.status);
                objectUrl = URL.createObjectURL(await res.blob());
                url = objectUrl;
            }
            const img = new Image();
            img.src = url;
            if (typeof img.decode === 'function') {
                await img.decode();
            } else {
                await new Promise((resolve, reject) => {
                    img.onload = resolve;
                    img.onerror = () => reject(new Error('decode failed'));
                });
            }
            return { img: img, objectUrl: objectUrl };
        } catch (e) {
            if (objectUrl) URL.revokeObjectURL(objectUrl);
            return null;
        }
    }

    function releaseImage(handle) {
        if (handle && handle.objectUrl) URL.revokeObjectURL(handle.objectUrl);
    }

    /** Montserrat is loaded by includes/header.php. Make the faces we draw
     *  with resident BEFORE measuring, or the first slide gets measured
     *  against the fallback font and the type sizes come out wrong. */
    async function ensureFonts() {
        if (!global.document || !document.fonts || !document.fonts.load) return;
        try {
            await Promise.all([
                document.fonts.load("800 60px 'Montserrat'"),
                document.fonts.load("700 30px 'Montserrat'"),
                document.fonts.load("600 20px 'Montserrat'")
            ]);
            await document.fonts.ready;
        } catch (e) { /* the stack in FONT covers us */ }
    }

    function roundedRectPath(ctx, x, y, w, h, r) {
        const rad = Math.max(0, Math.min(r, w / 2, h / 2));
        ctx.beginPath();
        ctx.moveTo(x + rad, y);
        ctx.lineTo(x + w - rad, y);
        ctx.quadraticCurveTo(x + w, y, x + w, y + rad);
        ctx.lineTo(x + w, y + h - rad);
        ctx.quadraticCurveTo(x + w, y + h, x + w - rad, y + h);
        ctx.lineTo(x + rad, y + h);
        ctx.quadraticCurveTo(x, y + h, x, y + h - rad);
        ctx.lineTo(x, y + rad);
        ctx.quadraticCurveTo(x, y, x + rad, y);
        ctx.closePath();
    }

    /** object-fit: cover, as arithmetic, so it matches on every engine. */
    function drawCover(ctx, img, x, y, w, h) {
        const iw = img.naturalWidth || img.width;
        const ih = img.naturalHeight || img.height;
        if (!iw || !ih) return;
        const scale = Math.max(w / iw, h / ih);
        const dw = iw * scale;
        const dh = ih * scale;
        ctx.drawImage(img, x + (w - dw) / 2, y + (h - dh) / 2, dw, dh);
    }

    /** ctx.letterSpacing is not in older Safari, so tracking is done by hand. */
    function trackedWidth(ctx, text, tracking) {
        if (!text) return 0;
        let w = 0;
        for (const ch of text) w += ctx.measureText(ch).width + tracking;
        return w - tracking;
    }

    function fillTracked(ctx, text, x, y, tracking, align) {
        if (!text) return;
        let cursor = x;
        if (align === 'right') cursor = x - trackedWidth(ctx, text, tracking);
        else if (align === 'center') cursor = x - trackedWidth(ctx, text, tracking) / 2;
        const prev = ctx.textAlign;
        ctx.textAlign = 'left';
        for (const ch of text) {
            ctx.fillText(ch, cursor, y);
            cursor += ctx.measureText(ch).width + tracking;
        }
        ctx.textAlign = prev;
    }

    /**
     * Greedy word wrap capped at maxLines.
     *
     * Returns { lines, clipped }. `clipped` matters: the caller shrinks the
     * type until nothing is clipped, and an ellipsis applied here would
     * otherwise make every fit test pass and truncate someone's surname.
     */
    function wrapText(ctx, text, maxWidth, maxLines) {
        const words = String(text || '').split(/\s+/).filter(Boolean);
        if (!words.length) return { lines: [''], clipped: false };

        const lines = [];
        let line = '';
        for (let i = 0; i < words.length; i++) {
            const attempt = line ? line + ' ' + words[i] : words[i];
            if (!line || ctx.measureText(attempt).width <= maxWidth) {
                line = attempt;
            } else if (lines.length < maxLines - 1) {
                lines.push(line);
                line = words[i];
            } else {
                line = attempt;   // overflows the final line; clipped below
            }
        }
        lines.push(line);

        let clipped = false;
        for (let i = 0; i < lines.length; i++) {
            if (ctx.measureText(lines[i]).width > maxWidth) {
                clipped = true;
                let s = lines[i];
                while (s.length > 1 && ctx.measureText(s + '\u2026').width > maxWidth) {
                    s = s.slice(0, -1);
                }
                lines[i] = s + '\u2026';
            }
        }
        return { lines: lines, clipped: clipped };
    }

    // ── Vector ornaments (no emoji: emoji glyphs differ per OS) ──────────────
    function drawConfetti(ctx, x, y, size) {
        const bits = [
            { dx: 0.02, dy: 0.06, w: 0.34, h: 0.13, rot: -0.55, c: '#F5A524' },
            { dx: 0.50, dy: 0.00, w: 0.30, h: 0.12, rot:  0.62, c: '#7DD3FC' },
            { dx: 0.04, dy: 0.56, w: 0.30, h: 0.12, rot:  0.85, c: '#D11920' },
            { dx: 0.52, dy: 0.60, w: 0.32, h: 0.13, rot: -0.35, c: '#86EFAC' }
        ];
        bits.forEach(b => {
            ctx.save();
            ctx.translate(x + b.dx * size, y + b.dy * size);
            ctx.rotate(b.rot);
            ctx.fillStyle = b.c;
            roundedRectPath(ctx, 0, 0, b.w * size, b.h * size, b.h * size / 2);
            ctx.fill();
            ctx.restore();
        });
        [[0.44, 0.36, '#FFFFFF'], [0.88, 0.44, '#D11920'], [0.30, 0.92, '#F5A524']].forEach(d => {
            ctx.beginPath();
            ctx.fillStyle = d[2];
            ctx.arc(x + d[0] * size, y + d[1] * size, size * 0.055, 0, Math.PI * 2);
            ctx.fill();
        });
    }

    function drawHeart(ctx, cx, cy, size, colour) {
        const s = size / 32;
        ctx.save();
        ctx.translate(cx, cy);
        ctx.scale(s, s);
        ctx.fillStyle = colour;
        ctx.beginPath();
        ctx.moveTo(0, 10);
        ctx.bezierCurveTo(-16, -2, -11, -16, 0, -8);
        ctx.bezierCurveTo(11, -16, 16, -2, 0, 10);
        ctx.closePath();
        ctx.fill();
        ctx.restore();
    }

    // ── Background + header ─────────────────────────────────────────────────
    function drawBackdrop(ctx, texture) {
        ctx.fillStyle = INK;
        ctx.fillRect(0, 0, SLIDE_W, SLIDE_H);

        if (texture) {
            ctx.save();
            ctx.globalAlpha = 0.13;
            drawCover(ctx, texture, 0, 0, SLIDE_W, SLIDE_H);
            ctx.restore();
        }

        // Warm brand glow top-right, a deeper one bottom-left.
        const warm = ctx.createRadialGradient(1580, 40, 40, 1580, 40, 1050);
        warm.addColorStop(0, 'rgba(209,25,32,0.52)');
        warm.addColorStop(0.55, 'rgba(120,16,24,0.20)');
        warm.addColorStop(1, 'rgba(11,13,18,0)');
        ctx.fillStyle = warm;
        ctx.fillRect(0, 0, SLIDE_W, SLIDE_H);

        const deep = ctx.createRadialGradient(140, 1120, 40, 140, 1120, 900);
        deep.addColorStop(0, 'rgba(146,20,28,0.38)');
        deep.addColorStop(1, 'rgba(11,13,18,0)');
        ctx.fillStyle = deep;
        ctx.fillRect(0, 0, SLIDE_W, SLIDE_H);

        // Vignette to keep the cards forward.
        const vig = ctx.createRadialGradient(SLIDE_W / 2, SLIDE_H / 2, SLIDE_H * 0.35,
                                             SLIDE_W / 2, SLIDE_H / 2, SLIDE_H * 0.95);
        vig.addColorStop(0, 'rgba(0,0,0,0)');
        vig.addColorStop(1, 'rgba(0,0,0,0.45)');
        ctx.fillStyle = vig;
        ctx.fillRect(0, 0, SLIDE_W, SLIDE_H);
    }

    function drawHeader(ctx, chrome) {
        // Logo, left.
        if (chrome.logo) {
            const lw = chrome.logo.naturalWidth || chrome.logo.width;
            const lh = chrome.logo.naturalHeight || chrome.logo.height;
            if (lw && lh) {
                const drawH = 104;
                ctx.drawImage(chrome.logo, PAD, 52, (lw / lh) * drawH, drawH);
            }
        } else {
            ctx.fillStyle = '#FFFFFF';
            ctx.font = `800 32px ${FONT}`;
            ctx.textBaseline = 'middle';
            ctx.fillText('HOD LEKKI', PAD, 104);
            ctx.textBaseline = 'alphabetic';
        }

        // Centre title, sized so it never collides with either side column.
        const sideRoom = 420;
        let titleSize = 58;
        ctx.textBaseline = 'alphabetic';
        for (; titleSize >= 34; titleSize -= 1) {
            ctx.font = `800 ${titleSize}px ${FONT}`;
            if (trackedWidth(ctx, TITLE, titleSize * 0.02) <= SLIDE_W - sideRoom * 2 - 90) break;
        }
        ctx.font = `800 ${titleSize}px ${FONT}`;
        const tracking = titleSize * 0.02;
        const titleW = trackedWidth(ctx, TITLE, tracking);
        const confettiSize = titleSize * 1.25;
        const blockW = titleW + 26 + confettiSize;
        const titleX = (SLIDE_W - blockW) / 2;

        ctx.fillStyle = '#FFFFFF';
        fillTracked(ctx, TITLE, titleX, 132, tracking, 'left');
        drawConfetti(ctx, titleX + titleW + 26, 132 - confettiSize * 0.82, confettiSize);

        // Right column.
        const rightX = SLIDE_W - PAD;
        ctx.textAlign = 'right';
        ctx.fillStyle = '#FFFFFF';
        ctx.font = `800 34px ${FONT}`;
        ctx.fillText(HANDLE, rightX, 110);

        ctx.font = `700 21px ${FONT}`;
        ctx.fillStyle = 'rgba(255,255,255,0.88)';
        fillTracked(ctx, chrome.periodLabel.toUpperCase(), rightX, 152, 6, 'right');

        ctx.font = `800 19px ${FONT}`;
        ctx.fillStyle = '#FFFFFF';
        const heartGap = 30;
        fillTracked(ctx, SIGN_OFF, rightX - heartGap, 192, 1.6, 'right');
        drawHeart(ctx, rightX - 11, 185, 25, '#E0242C');
        ctx.textAlign = 'left';
    }

    // ── Card metrics, shared by every card on a slide so nothing drifts ──────
    function cardMetrics(ctx, people, cardW) {
        const inset  = Math.round(cardW * 0.085);
        const textW  = cardW - inset * 2;

        const maxName = Math.round(cardW * 0.105);
        let nameSize = maxName;
        let nameLines = 1;

        // One size for the whole slide: the largest at which every name fits
        // inside two lines.
        for (let size = maxName; size >= 18; size -= 1) {
            ctx.font = `800 ${size}px ${FONT}`;
            let worst = 1;
            let ok = true;
            for (const person of people) {
                const wrapped = wrapText(ctx, fullName(person), textW, 2);
                worst = Math.max(worst, wrapped.lines.length);
                if (wrapped.clipped) ok = false;
            }
            nameSize  = size;
            nameLines = worst;
            if (ok) break;
        }

        const lineH     = Math.round(nameSize * 1.2);
        const labelSize = Math.max(11, Math.round(cardW * 0.048));
        const dateSize  = Math.max(11, Math.round(cardW * 0.046));
        const padTop    = Math.round(cardW * 0.072);
        const padBottom = Math.round(cardW * 0.072);
        const gapLabel  = Math.round(cardW * 0.042);
        const gapDate   = Math.round(cardW * 0.036);

        const captionH = padTop + nameLines * lineH + gapLabel + labelSize
                       + gapDate + dateSize + padBottom;

        return {
            inset: inset, textW: textW,
            nameSize: nameSize, lineH: lineH, nameLines: nameLines,
            labelSize: labelSize, dateSize: dateSize,
            padTop: padTop, gapLabel: gapLabel, gapDate: gapDate,
            captionH: captionH
        };
    }

    function drawCard(ctx, person, photo, x, y, w, h, m) {
        // Lift the card off the backdrop.
        ctx.save();
        ctx.shadowColor = 'rgba(0,0,0,0.55)';
        ctx.shadowBlur = 38;
        ctx.shadowOffsetY = 16;
        ctx.fillStyle = CARD_BG;
        roundedRectPath(ctx, x, y, w, h, CARD_R);
        ctx.fill();
        ctx.restore();

        ctx.save();
        roundedRectPath(ctx, x, y, w, h, CARD_R);
        ctx.clip();

        const captionY = y + h - m.captionH;
        const photoH   = captionY - y;

        ctx.fillStyle = CARD_BG;
        ctx.fillRect(x, y, w, h);

        if (photo) {
            drawCover(ctx, photo, x, y, w, photoH);
        } else {
            // No photo on file -- common for Junior Church children. A monogram
            // on the brand gradient beats a grey "No Photo" placeholder.
            const g = ctx.createLinearGradient(x, y, x, y + photoH);
            g.addColorStop(0, '#20252F');
            g.addColorStop(1, '#141820');
            ctx.fillStyle = g;
            ctx.fillRect(x, y, w, photoH);

            ctx.fillStyle = 'rgba(255,255,255,0.20)';
            ctx.font = `800 ${Math.round(w * 0.40)}px ${FONT}`;
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.fillText(initials(person), x + w / 2, y + photoH / 2);
            ctx.textAlign = 'left';
            ctx.textBaseline = 'alphabetic';
        }

        // Soften the seam between photo and caption.
        const seam = ctx.createLinearGradient(0, captionY - photoH * 0.28, 0, captionY);
        seam.addColorStop(0, 'rgba(10,12,17,0)');
        seam.addColorStop(1, 'rgba(10,12,17,0.72)');
        ctx.fillStyle = seam;
        ctx.fillRect(x, captionY - photoH * 0.28, w, photoH * 0.28);

        // Caption band.
        const band = ctx.createLinearGradient(0, captionY, 0, y + h);
        band.addColorStop(0, 'rgba(14,17,24,0.94)');
        band.addColorStop(1, 'rgba(9,11,16,0.98)');
        ctx.fillStyle = band;
        ctx.fillRect(x, captionY, w, m.captionH);

        // Name.
        ctx.font = `800 ${m.nameSize}px ${FONT}`;
        ctx.fillStyle = '#FFFFFF';
        ctx.textBaseline = 'alphabetic';
        const lines = wrapText(ctx, fullName(person), m.textW, 2).lines;
        let baseline = captionY + m.padTop + Math.round(m.nameSize * 0.82);
        lines.forEach(line => {
            ctx.fillText(line, x + m.inset, baseline);
            baseline += m.lineH;
        });

        // Event label, then date, on the slide-wide grid.
        const labelBaseline = captionY + m.padTop + m.nameLines * m.lineH
                            + m.gapLabel + Math.round(m.labelSize * 0.8);
        ctx.font = `800 ${m.labelSize}px ${FONT}`;
        ctx.fillStyle = accentColour(person.event_type);
        fillTracked(ctx, eventLabel(person.event_type), x + m.inset, labelBaseline, m.labelSize * 0.12, 'left');

        if (person.event_date) {
            ctx.font = `600 ${m.dateSize}px ${FONT}`;
            ctx.fillStyle = 'rgba(255,255,255,0.72)';
            ctx.fillText(String(person.event_date), x + m.inset,
                         labelBaseline + m.gapDate + Math.round(m.dateSize * 0.9));
        }

        ctx.restore();

        ctx.save();
        roundedRectPath(ctx, x + 0.5, y + 0.5, w - 1, h - 1, CARD_R);
        ctx.strokeStyle = 'rgba(255,255,255,0.10)';
        ctx.lineWidth = 1;
        ctx.stroke();
        ctx.restore();
    }

    /**
     * Card size for a row of n. Width comes from the frame; height grows
     * toward the full content band, but never past MIN_ASPECT, so a row of
     * five or six still fills the slide instead of floating in the middle.
     */
    function rowGeometry(n) {
        const f         = frameFor(n);
        const availW    = SLIDE_W - f.pad * 2;
        const contentH  = CONTENT_BOTTOM - CONTENT_TOP;
        const byWidth   = (availW - (n - 1) * f.gap) / n;
        const cardW     = Math.floor(Math.min(byWidth, contentH * NATURAL_ASPECT));
        const cardH     = Math.round(Math.min(contentH, cardW / f.minAspect));
        const rowW      = n * cardW + (n - 1) * f.gap;
        return {
            cardW: cardW,
            cardH: cardH,
            gap: f.gap,
            x0: Math.round((SLIDE_W - rowW) / 2),
            y0: Math.round(CONTENT_TOP + (contentH - cardH) / 2)
        };
    }

    function drawSlide(ctx, people, photos, chrome) {
        drawBackdrop(ctx, chrome.background);
        drawHeader(ctx, chrome);

        const geo = rowGeometry(people.length);
        const m   = cardMetrics(ctx, people, geo.cardW);

        let x = geo.x0;
        people.forEach((person, i) => {
            drawCard(ctx, person, photos[i], x, geo.y0, geo.cardW, geo.cardH, m);
            x += geo.cardW + geo.gap;
        });

        if (chrome.slideCount > 1) {
            ctx.fillStyle = 'rgba(255,255,255,0.45)';
            ctx.font = `700 15px ${FONT}`;
            ctx.textAlign = 'center';
            ctx.fillText(`${chrome.slideIndex} / ${chrome.slideCount}`, SLIDE_W / 2, SLIDE_H - 24);
            ctx.textAlign = 'left';
        }
    }

    /**
     * Split n celebrants into balanced pages of at most MAX_PER_PAGE.
     * 1-6 stay on one page; 7 becomes 4+3, 12 becomes 6+6, 13 becomes 5+4+4.
     */
    function paginate(people) {
        const n = people.length;
        if (n === 0) return [];
        const pages = Math.ceil(n / MAX_PER_PAGE);
        const base  = Math.floor(n / pages);
        let extra   = n % pages;

        const chunks = [];
        let cursor = 0;
        for (let i = 0; i < pages; i++) {
            const size = base + (extra > 0 ? 1 : 0);
            if (extra > 0) extra--;
            chunks.push(people.slice(cursor, cursor + size));
            cursor += size;
        }
        return chunks;
    }

    function makeCanvas(scale) {
        const canvas = document.createElement('canvas');
        canvas.width  = SLIDE_W * scale;
        canvas.height = SLIDE_H * scale;
        const ctx = canvas.getContext('2d');
        if (!ctx) return null;
        ctx.scale(scale, scale);
        ctx.imageSmoothingEnabled = true;
        ctx.imageSmoothingQuality = 'high';
        return { canvas: canvas, ctx: ctx };
    }

    function toBlob(canvas, quality) {
        return new Promise(resolve => {
            if (canvas.toBlob) canvas.toBlob(resolve, 'image/jpeg', quality);
            else resolve(null);
        });
    }

    /**
     * Render `people` into 1920x1080-shaped JPEG blobs at 2x.
     * Returns [{ blob, filename }]. `onProgress(done, total)` is optional.
     */
    async function renderSlides(people, options) {
        const opts        = options || {};
        const periodLabel = opts.periodLabel || '';
        const filePrefix  = opts.filePrefix || 'Celebrations';
        const onProgress  = typeof opts.onProgress === 'function' ? opts.onProgress : function () {};

        await ensureFonts();

        const chromeHandles = [];
        const background = await loadImage(BG_IMAGE);
        if (background) chromeHandles.push(background);
        let logo = await loadImage(LOGO_IMAGE);
        if (!logo) logo = await loadImage(LOGO_FALLBACK);
        if (logo) chromeHandles.push(logo);

        const chunks  = paginate(people);
        const results = [];

        try {
            for (let i = 0; i < chunks.length; i++) {
                const handles = [];
                const photos  = [];
                for (const person of chunks[i]) {
                    const handle = await loadImage(person.display_picture);
                    if (handle) handles.push(handle);
                    photos.push(handle ? handle.img : null);
                }

                const chrome = {
                    background:  background ? background.img : null,
                    logo:        logo ? logo.img : null,
                    periodLabel: periodLabel,
                    slideIndex:  i + 1,
                    slideCount:  chunks.length
                };

                let made = makeCanvas(SCALE) || makeCanvas(1);
                if (!made) throw new Error('This browser could not create the export canvas.');
                drawSlide(made.ctx, chunks[i], photos, chrome);

                let blob = await toBlob(made.canvas, 0.95);
                if (!blob && SCALE !== 1) {
                    // Some older mobile browsers refuse to encode 3840x2160.
                    // Redraw at 1x rather than handing back nothing.
                    const small = makeCanvas(1);
                    if (small) {
                        drawSlide(small.ctx, chunks[i], photos, chrome);
                        blob = await toBlob(small.canvas, 0.95);
                    }
                }
                if (!blob) throw new Error('This browser could not encode the slide image.');

                results.push({ blob: blob, filename: `${filePrefix}_Slide${i + 1}.jpg` });

                handles.forEach(releaseImage);
                made.canvas.width = made.canvas.height = 0;  // free the buffer on mobile
                onProgress(i + 1, chunks.length);
            }
        } finally {
            chromeHandles.forEach(releaseImage);
        }

        return results;
    }

    /**
     * Hand the slides to the user. Returns 'shared' | 'downloaded'.
     *
     * The old export pointed an <a download> at a data: URL, which iOS Safari
     * ignores outright. Blob URLs download properly on desktop and Android,
     * and where the OS share sheet exists that is the route that actually
     * lands a picture in the phone's photo library.
     */
    async function deliverSlides(slides, shareTitle) {
        if (!slides.length) return 'downloaded';

        if (navigator.canShare && navigator.share && typeof File === 'function') {
            try {
                const files = slides.map(s => new File([s.blob], s.filename, { type: 'image/jpeg' }));
                if (navigator.canShare({ files: files })) {
                    await navigator.share({ files: files, title: shareTitle || 'Celebrations' });
                    return 'shared';
                }
            } catch (e) {
                // AbortError = the user closed the sheet, which is a success
                // from our side. Anything else: fall through to download.
                if (e && e.name === 'AbortError') return 'shared';
            }
        }

        for (const slide of slides) {
            const url = URL.createObjectURL(slide.blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = slide.filename;
            link.rel = 'noopener';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            // Browsers drop back-to-back programmatic downloads; give each air.
            await new Promise(r => setTimeout(r, 400));
            setTimeout(() => URL.revokeObjectURL(url), 60000);
        }
        return 'downloaded';
    }

    global.CelebrantsExport = {
        renderSlides:  renderSlides,
        deliverSlides: deliverSlides,
        paginate:      paginate,
        accentColour:  accentColour,
        eventLabel:    eventLabel,
        MAX_PER_PAGE:  MAX_PER_PAGE
    };
})(window);
