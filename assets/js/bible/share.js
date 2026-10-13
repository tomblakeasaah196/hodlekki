// /assets/js/bible/share.js
// The Bible — "Share as image": a verse card drawn on a <canvas>, sized for
// a WhatsApp/Instagram post (square) or a status/story (9:16), shared with
// the phone's own share sheet where it has one, downloaded otherwise.
(function () {
    'use strict';

    const BB = window.HODBible;
    if (!BB) return;
    const $ = BB.$;

    const THEMES = {
        paper:   { label: 'Paper',   bg: ['#FBF6EA', '#EFE4CC'], ink: '#2B261F', accent: '#9E1B1B', soft: 'rgba(43,38,31,.55)', logo: 'color' },
        night:   { label: 'Night',   bg: ['#0B1426', '#1D356A'], ink: '#F1E6C8', accent: '#E2B65C', soft: 'rgba(241,230,200,.6)', logo: 'white' },
        royal:   { label: 'Royal',   bg: ['#1D356A', '#7F1D1D'], ink: '#FFFFFF', accent: '#FCD34D', soft: 'rgba(255,255,255,.7)', logo: 'white' },
        sunrise: { label: 'Sunrise', bg: ['#F59E0B', '#BE185D'], ink: '#FFFFFF', accent: '#FFF7ED', soft: 'rgba(255,255,255,.78)', logo: 'white' },
        forest:  { label: 'Forest',  bg: ['#064E3B', '#134E4A'], ink: '#ECFDF5', accent: '#FCD34D', soft: 'rgba(236,253,245,.7)', logo: 'white' },
        rose:    { label: 'Rose',    bg: ['#FDF2F8', '#FBCFE8'], ink: '#4A1D2F', accent: '#BE185D', soft: 'rgba(74,29,47,.55)', logo: 'color' },
    };

    const st = { text: '', ref: '', translation: '', theme: 'paper', format: 'square', brand: true, logo: null };

    function loadLogo() {
        if (st.logo) return Promise.resolve(st.logo);
        return new Promise((resolve) => {
            const img = new Image();
            img.onload = () => { st.logo = img; resolve(img); };
            img.onerror = () => resolve(null);
            img.src = (BB.cfg.logo || '/assets/images/logo_hod.png');
        });
    }

    /** The logo as a white silhouette, for dark backgrounds. */
    function whiteLogo(img, w, h) {
        const c = document.createElement('canvas');
        c.width = w;
        c.height = h;
        const x = c.getContext('2d');
        x.drawImage(img, 0, 0, w, h);
        x.globalCompositeOperation = 'source-in';
        x.fillStyle = '#ffffff';
        x.fillRect(0, 0, w, h);
        return c;
    }

    function wrap(ctx, text, maxWidth) {
        const words = text.split(/\s+/);
        const lines = [];
        let line = '';
        words.forEach((w) => {
            const test = line ? line + ' ' + w : w;
            if (ctx.measureText(test).width > maxWidth && line) {
                lines.push(line);
                line = w;
            } else {
                line = test;
            }
        });
        if (line) lines.push(line);
        return lines;
    }

    async function draw() {
        const canvas = $('#bbShareCanvas');
        if (!canvas) return;
        const W = 1080;
        const H = st.format === 'story' ? 1920 : 1080;
        canvas.width = W;
        canvas.height = H;
        const ctx = canvas.getContext('2d');
        const t = THEMES[st.theme] || THEMES.paper;

        try {
            await Promise.all([
                document.fonts.load('400 60px "Literata"'),
                document.fonts.load('italic 400 60px "Literata"'),
                document.fonts.load('600 30px "Inter"'),
            ]);
        } catch (e) { /* system serif will do */ }

        // Background
        const g = ctx.createLinearGradient(0, 0, W, H);
        g.addColorStop(0, t.bg[0]);
        g.addColorStop(1, t.bg[1]);
        ctx.fillStyle = g;
        ctx.fillRect(0, 0, W, H);
        // soft light from the top
        const r = ctx.createRadialGradient(W * 0.25, H * 0.1, 10, W * 0.25, H * 0.1, W * 0.9);
        r.addColorStop(0, 'rgba(255,255,255,0.16)');
        r.addColorStop(1, 'rgba(255,255,255,0)');
        ctx.fillStyle = r;
        ctx.fillRect(0, 0, W, H);

        const pad = 110;
        const maxW = W - pad * 2;
        const top = st.format === 'story' ? 360 : 190;
        const bottom = H - (st.format === 'story' ? 420 : 250);

        // Opening quote mark
        ctx.fillStyle = t.accent;
        ctx.globalAlpha = 0.9;
        ctx.font = '400 150px "Literata", Georgia, serif';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'alphabetic';
        ctx.fillText('“', W / 2, top - 10);
        ctx.globalAlpha = 1;

        // Fit the verse into the box
        let text = st.text;
        if (text.length > 700) text = text.slice(0, 690).replace(/\s+\S*$/, '') + '…';
        let size = st.format === 'story' ? 66 : 60;
        let lines = [];
        let lh = 0;
        for (; size >= 28; size -= 2) {
            ctx.font = '400 ' + size + 'px "Literata", Georgia, serif';
            lines = wrap(ctx, text, maxW);
            lh = size * 1.42;
            if (lines.length * lh <= bottom - top) break;
        }
        const blockH = lines.length * lh;
        let y = top + Math.max(0, (bottom - top - blockH) / 2) + size;
        ctx.fillStyle = t.ink;
        ctx.textAlign = 'center';
        lines.forEach((ln) => {
            ctx.fillText(ln, W / 2, y);
            y += lh;
        });

        // Reference
        y += size * 0.55;
        ctx.fillStyle = t.accent;
        ctx.fillRect(W / 2 - 40, y - 18, 80, 3);
        ctx.font = '600 32px "Inter", system-ui, sans-serif';
        if ('letterSpacing' in ctx) ctx.letterSpacing = '6px';
        ctx.fillText((st.ref + ' · ' + st.translation).toUpperCase(), W / 2, y + 40);
        if ('letterSpacing' in ctx) ctx.letterSpacing = '0px';

        // Brand
        if (st.brand) {
            const img = await loadLogo();
            const by = H - (st.format === 'story' ? 210 : 120);
            ctx.fillStyle = t.soft;
            ctx.font = '600 24px "Inter", system-ui, sans-serif';
            if ('letterSpacing' in ctx) ctx.letterSpacing = '5px';
            let textX = W / 2;
            if (img && img.naturalWidth) {
                const lhH = 54;
                const lw = Math.round(img.naturalWidth * (lhH / img.naturalHeight));
                const label = 'HOUSEHOLD OF DAVID · LEKKI';
                const tw = ctx.measureText(label).width;
                const total = lw + 22 + tw;
                const lx = W / 2 - total / 2;
                const src = t.logo === 'white' ? whiteLogo(img, lw, lhH) : img;
                ctx.globalAlpha = 0.9;
                ctx.drawImage(src, lx, by - lhH + 14, lw, lhH);
                ctx.globalAlpha = 1;
                textX = lx + lw + 22 + tw / 2;
                ctx.fillText(label, textX, by);
            } else {
                ctx.fillText('HOUSEHOLD OF DAVID · LEKKI', textX, by);
            }
            if ('letterSpacing' in ctx) ctx.letterSpacing = '0px';
        }
    }

    function blob() {
        return new Promise((resolve) => $('#bbShareCanvas').toBlob((b) => resolve(b), 'image/png'));
    }

    function fileName() {
        return (st.ref + '-' + st.translation).toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') + '.png';
    }

    async function download() {
        const b = await blob();
        if (!b) return;
        const url = URL.createObjectURL(b);
        const a = document.createElement('a');
        a.href = url;
        a.download = fileName();
        // Inside the open dialog: modal-manager.js cancels clicks on anything
        // outside it, and that would swallow the download.
        ($('#bibleShareModal') || document.body).appendChild(a);
        a.click();
        a.remove();
        setTimeout(() => URL.revokeObjectURL(url), 4000);
    }

    async function share() {
        const b = await blob();
        if (!b) return;
        const file = new File([b], fileName(), { type: 'image/png' });
        if (navigator.canShare && navigator.canShare({ files: [file] })) {
            try {
                await navigator.share({ files: [file], text: st.ref + ' (' + st.translation + ')' });
                return;
            } catch (e) {
                if (e && e.name === 'AbortError') return;
            }
        }
        await download();
        BB.toast('Image saved — share it from your downloads');
    }

    function open(opts) {
        st.text = String(opts.text || '').trim();
        st.ref = opts.ref || '';
        st.translation = opts.translation || '';
        if (!st.text) return;
        const holder = $('#bbShareThemes');
        holder.innerHTML = Object.keys(THEMES).map((k) => {
            const t = THEMES[k];
            return '<button type="button" data-theme="' + k + '" style="background:linear-gradient(135deg,' + t.bg[0] + ',' + t.bg[1] + ');color:' + t.ink + '"'
                + (k === st.theme ? ' class="is-on"' : '') + '>' + t.label + '</button>';
        }).join('');
        BB.$$('#bbShareFormat button').forEach((b) => b.classList.toggle('is-on', b.dataset.format === st.format));
        $('#bbShareBrand').checked = st.brand;
        BB.openModal('bibleShareModal');
        draw();
    }

    function bind() {
        $('#bbShareThemes').addEventListener('click', (e) => {
            const b = e.target.closest('button');
            if (!b) return;
            st.theme = b.dataset.theme;
            BB.$$('#bbShareThemes button').forEach((x) => x.classList.toggle('is-on', x === b));
            draw();
        });
        $('#bbShareFormat').addEventListener('click', (e) => {
            const b = e.target.closest('button');
            if (!b) return;
            st.format = b.dataset.format;
            BB.$$('#bbShareFormat button').forEach((x) => x.classList.toggle('is-on', x === b));
            draw();
        });
        $('#bbShareBrand').addEventListener('change', (e) => { st.brand = e.target.checked; draw(); });
        $('#bbShareGo').addEventListener('click', share);
        $('#bbShareDownload').addEventListener('click', download);
    }

    BB.share = { open: open, bind: bind };
})();
