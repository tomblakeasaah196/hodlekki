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

    const EYEBROW    = 'HOUSEHOLD OF DAVID  \u2022  LEKKI CENTRE';
    const TITLE      = 'MONTHLY CELEBRATIONS';
    const HANDLE     = '#HODLC';
    const SIGN_OFF   = 'WE LOVE YOU TOO';

    const CONFETTI_PALETTE = ['#F5A524', '#D11920', '#7DD3FC', '#86EFAC', '#FFFFFF', '#C084FC'];
    const CONFETTI_SEED    = 20260926;

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

    // ── Vector ornaments ────────────────────────────────────────────────────
    // Nothing here is an emoji. Emoji are font glyphs and every OS ships its
    // own artwork for them, so a single \ud83c\udf89 in the output would make the
    // slide device-dependent again -- and it could not be brand-coloured.
    // These are drawn from paths, so they are identical everywhere and use the
    // church palette.

    /** Deterministic PRNG. Same seed, same confetti, on every device. */
    function mulberry32(a) {
        return function () {
            a |= 0; a = (a + 0x6D2B79F5) | 0;
            let t = Math.imul(a ^ (a >>> 15), 1 | a);
            t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
            return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
        };
    }

    /**
     * A drift of confetti across the slide: dense in the header band, thinning
     * downward, so the frame feels like a celebration rather than a dark box.
     * Cards are painted afterwards and hide whatever falls behind them, which
     * is what gives the field its uneven, un-generated look.
     */
    function drawConfettiField(ctx) {
        const rng = mulberry32(CONFETTI_SEED);
        for (let i = 0; i < 110; i++) {
            const x = rng() * SLIDE_W;
            const y = Math.pow(rng(), 1.7) * SLIDE_H;      // biased toward the top
            const colour = CONFETTI_PALETTE[Math.floor(rng() * CONFETTI_PALETTE.length)];
            const scale = 7 + rng() * 15;
            const rot = rng() * Math.PI * 2;
            const kind = rng();

            ctx.save();
            ctx.globalAlpha = 0.16 + rng() * 0.46;
            ctx.fillStyle = colour;
            ctx.strokeStyle = colour;
            ctx.translate(x, y);
            ctx.rotate(rot);

            if (kind < 0.46) {
                // Rectangular flake.
                roundedRectPath(ctx, -scale / 2, -scale / 4.5, scale, scale / 2.2, scale / 6);
                ctx.fill();
            } else if (kind < 0.72) {
                // Round flake.
                ctx.beginPath();
                ctx.arc(0, 0, scale * 0.28, 0, Math.PI * 2);
                ctx.fill();
            } else {
                // Curled streamer.
                ctx.lineWidth = Math.max(2, scale * 0.16);
                ctx.lineCap = 'round';
                ctx.beginPath();
                ctx.moveTo(-scale, 0);
                ctx.bezierCurveTo(-scale * 0.35, -scale * 0.85, scale * 0.35, scale * 0.85, scale, 0);
                ctx.stroke();
            }
            ctx.restore();
        }
    }

    /**
     * Party popper, drawn rather than borrowed from the emoji font.
     * Composed inside a normalised 100x100 box anchored at (left, top), so the
     * whole burst is bounded and can be placed against type predictably.
     */
    function drawPartyPopper(ctx, left, top, size) {
        const u = size / 100;
        ctx.save();
        ctx.translate(left, top);
        ctx.scale(u, u);

        const TIP = [14, 96];
        const A   = [30, 40];   // mouth, upper edge
        const B   = [70, 66];   // mouth, lower edge

        // Cone body.
        const body = ctx.createLinearGradient(TIP[0], TIP[1], B[0], A[1]);
        body.addColorStop(0, '#9C5F12');
        body.addColorStop(0.45, '#F5A524');
        body.addColorStop(1, '#FFD98C');
        ctx.beginPath();
        ctx.moveTo(TIP[0], TIP[1]);
        ctx.lineTo(A[0], A[1]);
        ctx.quadraticCurveTo(58, 40, B[0], B[1]);
        ctx.closePath();
        ctx.fillStyle = body;
        ctx.fill();

        // Banding, clipped to the cone, for a little dimension.
        ctx.save();
        ctx.clip();
        ctx.fillStyle = 'rgba(112,58,8,0.34)';
        [[-6, 20], [6, 40]].forEach(off => {
            ctx.beginPath();
            ctx.moveTo(TIP[0] - 20 + off[0], TIP[1] - off[1]);
            ctx.lineTo(TIP[0] + 60 + off[0], TIP[1] - off[1] - 30);
            ctx.lineTo(TIP[0] + 60 + off[0], TIP[1] - off[1] - 20);
            ctx.lineTo(TIP[0] - 20 + off[0], TIP[1] - off[1] + 10);
            ctx.closePath();
            ctx.fill();
        });
        ctx.restore();

        // Mouth rim.
        ctx.beginPath();
        ctx.moveTo(A[0], A[1]);
        ctx.quadraticCurveTo(58, 40, B[0], B[1]);
        ctx.quadraticCurveTo(42, 66, A[0], A[1]);
        ctx.closePath();
        ctx.fillStyle = '#FFEFC9';
        ctx.fill();

        // Streamers out of the mouth, all ending inside the box.
        ctx.lineCap = 'round';
        const streamers = [
            { c: '#D11920', w: 7,   p: [[52, 46], [64, 12], [86, 26], [97, 6]] },
            { c: '#7DD3FC', w: 6.5, p: [[56, 50], [82, 48], [84, 22], [99, 34]] },
            { c: '#86EFAC', w: 6.5, p: [[46, 44], [44, 14], [62, 8], [60, 1]] },
            { c: '#FFFFFF', w: 5.5, p: [[58, 56], [84, 62], [90, 48], [99, 56]] }
        ];
        streamers.forEach(st => {
            ctx.strokeStyle = st.c;
            ctx.lineWidth = st.w;
            ctx.beginPath();
            ctx.moveTo(st.p[0][0], st.p[0][1]);
            ctx.bezierCurveTo(st.p[1][0], st.p[1][1], st.p[2][0], st.p[2][1], st.p[3][0], st.p[3][1]);
            ctx.stroke();
        });

        [[74, 4, 4, '#F5A524'], [93, 44, 3.6, '#C084FC'], [80, 36, 3.2, '#FFFFFF'], [66, 24, 3, '#7DD3FC']]
            .forEach(d => {
                ctx.beginPath();
                ctx.fillStyle = d[3];
                ctx.arc(d[0], d[1], d[2], 0, Math.PI * 2);
                ctx.fill();
            });

        ctx.restore();
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

        // The photographic confetti stays, but only as grain -- the drawn
        // field below it is what actually carries the celebration.
        if (texture) {
            ctx.save();
            ctx.globalAlpha = 0.07;
            drawCover(ctx, texture, 0, 0, SLIDE_W, SLIDE_H);
            ctx.restore();
        }

        // Warm brand glow top-right, a deeper one bottom-left.
        const warm = ctx.createRadialGradient(1580, 40, 40, 1580, 40, 1050);
        warm.addColorStop(0, 'rgba(209,25,32,0.50)');
        warm.addColorStop(0.55, 'rgba(120,16,24,0.19)');
        warm.addColorStop(1, 'rgba(11,13,18,0)');
        ctx.fillStyle = warm;
        ctx.fillRect(0, 0, SLIDE_W, SLIDE_H);

        const deep = ctx.createRadialGradient(140, 1120, 40, 140, 1120, 900);
        deep.addColorStop(0, 'rgba(146,20,28,0.36)');
        deep.addColorStop(1, 'rgba(11,13,18,0)');
        ctx.fillStyle = deep;
        ctx.fillRect(0, 0, SLIDE_W, SLIDE_H);

        drawConfettiField(ctx);

        // Vignette, so the cards sit forward of the confetti.
        const vig = ctx.createRadialGradient(SLIDE_W / 2, SLIDE_H / 2, SLIDE_H * 0.34,
                                             SLIDE_W / 2, SLIDE_H / 2, SLIDE_H * 0.95);
        vig.addColorStop(0, 'rgba(0,0,0,0)');
        vig.addColorStop(1, 'rgba(0,0,0,0.50)');
        ctx.fillStyle = vig;
        ctx.fillRect(0, 0, SLIDE_W, SLIDE_H);

        // Scrim over the header band: the confetti stays visible but sits
        // behind the type instead of competing with it.
        const headScrim = ctx.createLinearGradient(0, 0, 0, 215);
        headScrim.addColorStop(0, 'rgba(10,12,18,0.62)');
        headScrim.addColorStop(1, 'rgba(10,12,18,0)');
        ctx.fillStyle = headScrim;
        ctx.fillRect(0, 0, SLIDE_W, 215);

        // A soft stage light under the card row lifts it off the backdrop.
        const stage = ctx.createRadialGradient(SLIDE_W / 2, 600, 60, SLIDE_W / 2, 600, 980);
        stage.addColorStop(0, 'rgba(255,214,170,0.10)');
        stage.addColorStop(1, 'rgba(255,214,170,0)');
        ctx.fillStyle = stage;
        ctx.fillRect(0, 0, SLIDE_W, SLIDE_H);
    }

    function drawHeader(ctx, chrome) {
        // Logo, left.
        if (chrome.logo) {
            const lw = chrome.logo.naturalWidth || chrome.logo.width;
            const lh = chrome.logo.naturalHeight || chrome.logo.height;
            if (lw && lh) {
                const drawH = 100;
                ctx.drawImage(chrome.logo, PAD, 56, (lw / lh) * drawH, drawH);
            }
        } else {
            ctx.fillStyle = '#FFFFFF';
            ctx.font = `800 32px ${FONT}`;
            ctx.textBaseline = 'middle';
            ctx.fillText('HOD LEKKI', PAD, 106);
            ctx.textBaseline = 'alphabetic';
        }

        ctx.textBaseline = 'alphabetic';

        // Centre block: a quiet eyebrow over the headline gives the type
        // somewhere to breathe and names the church without shouting.
        const sideRoom = 400;
        const maxTitleW = SLIDE_W - sideRoom * 2;
        let titleSize = 60;
        for (; titleSize >= 34; titleSize -= 1) {
            ctx.font = `800 ${titleSize}px ${FONT}`;
            if (trackedWidth(ctx, TITLE, titleSize * 0.015) <= maxTitleW - 130) break;
        }

        ctx.font = `700 16px ${FONT}`;
        ctx.fillStyle = 'rgba(255,255,255,0.62)';
        fillTracked(ctx, EYEBROW, SLIDE_W / 2, 74, 4.6, 'center');

        ctx.font = `800 ${titleSize}px ${FONT}`;
        const tracking = titleSize * 0.015;
        const titleW = trackedWidth(ctx, TITLE, tracking);
        const popperSize = titleSize * 1.5;
        const blockW = titleW + 18 + popperSize;
        const titleX = (SLIDE_W - blockW) / 2;
        const titleBaseline = 140;

        ctx.fillStyle = '#FFFFFF';
        fillTracked(ctx, TITLE, titleX, titleBaseline, tracking, 'left');
        // The popper box is anchored so its cone tip lands on the text baseline.
        drawPartyPopper(ctx, titleX + titleW + 18, titleBaseline - popperSize * 0.96, popperSize);

        // Right column.
        const rightX = SLIDE_W - PAD;
        ctx.textAlign = 'right';
        ctx.fillStyle = '#FFFFFF';
        ctx.font = `800 34px ${FONT}`;
        ctx.fillText(HANDLE, rightX, 108);

        ctx.font = `700 21px ${FONT}`;
        ctx.fillStyle = 'rgba(255,255,255,0.88)';
        fillTracked(ctx, chrome.periodLabel.toUpperCase(), rightX, 150, 6, 'right');

        ctx.font = `800 19px ${FONT}`;
        ctx.fillStyle = '#FFFFFF';
        fillTracked(ctx, SIGN_OFF, rightX - 30, 190, 1.6, 'right');
        drawHeart(ctx, rightX - 11, 183, 25, '#E0242C');
        ctx.textAlign = 'left';
    }

    // ── Card metrics, shared by every card on a slide so nothing drifts ──────
    function cardMetrics(ctx, people, cardW) {
        const inset  = Math.round(cardW * 0.085);
        const textW  = cardW - inset * 2;

        const maxName = Math.round(cardW * 0.108);
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

        const lineH     = Math.round(nameSize * 1.18);
        const labelSize = Math.max(11, Math.round(cardW * 0.047));
        const chipSize  = Math.max(11, Math.round(cardW * 0.049));
        const padTop    = Math.round(cardW * 0.075);
        const padBottom = Math.round(cardW * 0.078);
        const gapLabel  = Math.round(cardW * 0.048);

        // The date moved onto the photo as a chip, so the caption carries only
        // the name and the event -- a calmer block, and more room for the face.
        const captionH = padTop + nameLines * lineH + gapLabel + labelSize + padBottom;

        return {
            inset: inset, textW: textW,
            nameSize: nameSize, lineH: lineH, nameLines: nameLines,
            labelSize: labelSize, chipSize: chipSize,
            padTop: padTop, gapLabel: gapLabel,
            accentBar: Math.max(3, Math.round(cardW * 0.011)),
            captionH: captionH
        };
    }

    /** Frosted date chip, sat over the top-left of the photo. */
    function drawDateChip(ctx, text, x, y, m) {
        if (!text) return;
        ctx.font = `800 ${m.chipSize}px ${FONT}`;
        const padX = Math.round(m.chipSize * 0.85);
        const padY = Math.round(m.chipSize * 0.62);
        const tracking = m.chipSize * 0.06;
        const w = trackedWidth(ctx, text, tracking) + padX * 2;
        const h = m.chipSize + padY * 2;

        ctx.save();
        ctx.fillStyle = 'rgba(9,11,16,0.66)';
        roundedRectPath(ctx, x, y, w, h, h / 2);
        ctx.fill();
        ctx.strokeStyle = 'rgba(255,255,255,0.22)';
        ctx.lineWidth = 1;
        ctx.stroke();
        ctx.restore();

        ctx.fillStyle = '#FFFFFF';
        ctx.textBaseline = 'middle';
        fillTracked(ctx, text, x + padX, y + h / 2 + 0.5, tracking, 'left');
        ctx.textBaseline = 'alphabetic';
    }

    function drawCard(ctx, person, photo, x, y, w, h, m) {
        const accent = accentColour(person.event_type);

        // Lift the card off the backdrop, with a breath of the event colour in
        // the shadow so each card carries its own temperature.
        ctx.save();
        ctx.shadowColor = 'rgba(0,0,0,0.58)';
        ctx.shadowBlur = 42;
        ctx.shadowOffsetY = 18;
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
            // tinted with the event colour beats a grey "No Photo" block.
            const g = ctx.createLinearGradient(x, y, x + w, y + photoH);
            g.addColorStop(0, '#222838');
            g.addColorStop(1, '#12161F');
            ctx.fillStyle = g;
            ctx.fillRect(x, y, w, photoH);

            ctx.save();
            ctx.globalAlpha = 0.10;
            const halo = ctx.createRadialGradient(x + w / 2, y + photoH * 0.45, 10,
                                                  x + w / 2, y + photoH * 0.45, w * 0.8);
            halo.addColorStop(0, accent);
            halo.addColorStop(1, 'rgba(0,0,0,0)');
            ctx.fillStyle = halo;
            ctx.fillRect(x, y, w, photoH);
            ctx.restore();

            ctx.fillStyle = 'rgba(255,255,255,0.22)';
            ctx.font = `800 ${Math.round(w * 0.40)}px ${FONT}`;
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.fillText(initials(person), x + w / 2, y + photoH / 2);
            ctx.textAlign = 'left';
            ctx.textBaseline = 'alphabetic';
        }

        // Top-down scrim so the date chip always reads, whatever the photo.
        const capTop = ctx.createLinearGradient(0, y, 0, y + photoH * 0.28);
        capTop.addColorStop(0, 'rgba(6,8,12,0.55)');
        capTop.addColorStop(1, 'rgba(6,8,12,0)');
        ctx.fillStyle = capTop;
        ctx.fillRect(x, y, w, photoH * 0.28);

        // Soften the seam between photo and caption.
        const seam = ctx.createLinearGradient(0, captionY - photoH * 0.3, 0, captionY);
        seam.addColorStop(0, 'rgba(10,12,17,0)');
        seam.addColorStop(1, 'rgba(10,12,17,0.80)');
        ctx.fillStyle = seam;
        ctx.fillRect(x, captionY - photoH * 0.3, w, photoH * 0.3);

        drawDateChip(ctx, person.event_date, x + m.inset, y + m.inset, m);

        // Caption band, capped by a hairline in the event colour: the card is
        // colour-coded at a glance without a loud badge.
        const band = ctx.createLinearGradient(0, captionY, 0, y + h);
        band.addColorStop(0, 'rgba(15,18,26,0.95)');
        band.addColorStop(1, 'rgba(8,10,15,0.99)');
        ctx.fillStyle = band;
        ctx.fillRect(x, captionY, w, m.captionH);

        ctx.fillStyle = accent;
        ctx.fillRect(x, captionY, w, m.accentBar);

        // Name.
        ctx.font = `800 ${m.nameSize}px ${FONT}`;
        ctx.fillStyle = '#FFFFFF';
        ctx.textBaseline = 'alphabetic';
        const lines = wrapText(ctx, fullName(person), m.textW, 2).lines;
        let baseline = captionY + m.accentBar + m.padTop + Math.round(m.nameSize * 0.80);
        lines.forEach(line => {
            ctx.fillText(line, x + m.inset, baseline);
            baseline += m.lineH;
        });

        // Event label on the slide-wide grid, led by an accent dot.
        const labelBaseline = captionY + m.accentBar + m.padTop + m.nameLines * m.lineH
                            + m.gapLabel + Math.round(m.labelSize * 0.34);
        const dotR = Math.max(2.5, m.labelSize * 0.19);
        ctx.beginPath();
        ctx.fillStyle = accent;
        ctx.arc(x + m.inset + dotR, labelBaseline, dotR, 0, Math.PI * 2);
        ctx.fill();

        ctx.font = `800 ${m.labelSize}px ${FONT}`;
        ctx.fillStyle = accent;
        ctx.textBaseline = 'middle';
        fillTracked(ctx, eventLabel(person.event_type),
                    x + m.inset + dotR * 2 + Math.round(m.labelSize * 0.55),
                    labelBaseline + 0.5, m.labelSize * 0.13, 'left');
        ctx.textBaseline = 'alphabetic';

        ctx.restore();

        ctx.save();
        roundedRectPath(ctx, x + 0.5, y + 0.5, w - 1, h - 1, CARD_R);
        ctx.strokeStyle = 'rgba(255,255,255,0.12)';
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
