// /assets/js/bible/reader.js
// The Bible — the reading surface: rendering a chapter, exact-word
// selection, highlights, note markers, chapter navigation and "read" tracking.
//
// Offsets: every verse's words live in one <span class="vt" data-v="N">, and
// nothing else inside it carries text (verse numbers, the drop cap and note
// markers sit outside it). So the characters before a point inside a .vt are
// exactly that point's offset into the verse text the server sent.
(function () {
    'use strict';

    const BB = window.HODBible;
    if (!BB) return;
    const $ = BB.$;
    const $$ = BB.$$;

    const ICON_NOTE = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9M16.5 3.5a2.1 2.1 0 013 3L7 19l-4 1 1-4 12.5-12.5z"/></svg>';
    const ICON_JOURNAL = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 19.5A2.5 2.5 0 016.5 17H20M4 19.5V5a2 2 0 012-2h14v18H6.5A2.5 2.5 0 014 19.5z"/></svg>';

    const cache = new Map();          // 'KJV:43:3' → chapter payload (KJV only)
    let loadToken = 0;
    let shownAt = 0;                  // when the current chapter was painted
    let readSent = false;
    let endVisible = false;
    let endTimer = null;
    let renderedKey = '';

    const main = () => document.querySelector('main');
    const textEl = () => document.getElementById('bbText');
    const topbarH = () => { const t = document.getElementById('bbTopbar'); return t ? t.getBoundingClientRect().height : 0; };
    const isTouch = () => window.matchMedia('(pointer: coarse)').matches;

    // ------------------------------------------------------------------ render

    function pairs(flat) {
        const out = [];
        if (!Array.isArray(flat)) return out;
        for (let i = 0; i + 1 < flat.length; i += 2) out.push([flat[i], flat[i + 1]]);
        return out;
    }

    /** Small capitals for words printed in capitals in the KJV (LORD, GOD …). */
    function capsSc(html) {
        return html.replace(/\b([A-Z])([A-Z]{1,})\b/g, '$1<span class="sc">$2</span>');
    }

    /** The inner HTML of one verse's .vt: text cut at every mark boundary. */
    function verseInner(v, ctx) {
        const chars = BB.chars(v.t);
        const len = chars.length;
        const iv = [];
        pairs(v.w).forEach(([s, e]) => iv.push([s, e, 'wj']));
        pairs(v.i).forEach(([s, e]) => iv.push([s, e, 'add']));
        pairs(v.n).forEach(([s, e]) => iv.push([s, e, 'nd']));

        const n = v.v;
        ctx.highlights.forEach((h) => {
            if (n < h.vs || n > h.ve) return;
            const s = n === h.vs ? h.os : 0;
            const e = n === h.ve ? h.oe : len;
            if (e > s) iv.push([s, e, 'hl hl-' + h.color]);
        });
        ctx.phraseNotes.forEach((nt) => {
            if (n < nt.vs || n > nt.ve) return;
            const s = n === nt.vs ? nt.os : 0;
            const e = n === nt.ve ? nt.oe : len;
            if (e > s) iv.push([s, e, 'nt']);
        });

        const br = new Set((v.br || []).filter((o) => o > 0 && o < len));
        const cuts = new Set([0, len]);
        iv.forEach(([s, e]) => { cuts.add(Math.max(0, Math.min(len, s))); cuts.add(Math.max(0, Math.min(len, e))); });
        br.forEach((o) => cuts.add(o));
        const points = Array.from(cuts).sort((a, b) => a - b);

        let html = '';
        for (let i = 0; i + 1 < points.length; i++) {
            const a = points[i];
            const b = points[i + 1];
            if (b <= a) continue;
            if (br.has(a)) html += '<br>';
            let piece = BB.esc(chars.slice(a, b).join(''));
            if (ctx.capsSc) piece = capsSc(piece);
            const cls = [];
            iv.forEach(([s, e, c]) => { if (s <= a && e >= b) cls.push(c); });
            html += cls.length ? '<span class="' + Array.from(new Set(cls)).join(' ') + '">' + piece + '</span>' : piece;
        }
        return html;
    }

    function markersFor(n, ctx) {
        return ctx.notes
            .filter((nt) => nt.ve === n)
            .map((nt) => {
                const label = nt.kind === 'journal' ? 'Open journal entry' : 'Open your note';
                const title = BB.esc((nt.title || nt.snippet || '').slice(0, 80));
                return '<button type="button" class="nm' + (nt.kind === 'journal' ? ' nm-journal' : '') + '" data-note="' + nt.id + '" aria-label="' + label + '" title="' + title + '">'
                    + (nt.kind === 'journal' ? ICON_JOURNAL : ICON_NOTE) + '</button>';
            })
            .join('');
    }

    function render(data, opts) {
        opts = opts || {};
        const el = textEl();
        if (!el) return;
        const prefs = BB.state.prefs;
        const layout = prefs.layout === 'verse' ? 'verse' : 'para';
        const ctx = {
            capsSc: !!data.caps_sc,
            highlights: data.highlights || [],
            notes: data.notes || [],
            phraseNotes: (data.notes || []).filter((n) => n.os != null && n.oe != null && n.translation === data.translation),
        };

        let html = '';
        let open = null;           // 'p' | 'q' | 'v' — the block currently open
        let blocks = 0;
        let prevPoetry = false;
        const close = () => { if (open) { html += '</p>'; open = null; } };
        const openBlock = (kind, extra) => {
            close();
            const cls = kind === 'q' ? 'bb-q' : (kind === 'v' ? 'bb-vline' : 'bb-p');
            html += '<p class="' + cls + (blocks === 0 ? ' bb-first' : '') + (extra || '') + '">';
            open = kind;
            blocks++;
        };

        data.verses.forEach((v, idx) => {
            let afterHead = false;
            (v.h || []).forEach((h) => { close(); html += '<h4 class="bb-heading">' + BB.esc(h) + '</h4>'; afterHead = true; });
            if (v.d) {
                close();
                const acrostic = /^[A-Z]+\.?$/.test(v.d.trim());
                html += '<p class="bb-title' + (acrostic ? ' bb-acrostic' : '') + '">' + BB.esc(v.d) + '</p>';
                afterHead = true;
            }

            if (layout === 'verse') {
                openBlock('v', afterHead ? ' bb-after-head' : '');
            } else if (v.q) {
                openBlock('q', prevPoetry ? '' : ' bb-qstart');
            } else if (idx === 0 || v.p || prevPoetry || afterHead || !open) {
                openBlock('p', afterHead ? ' bb-after-head' : '');
            } else {
                html += ' ';
            }
            prevPoetry = !!v.q && layout !== 'verse';

            if (idx === 0) {
                html += '<span class="bb-dropcap" data-v="' + v.v + '" aria-hidden="true">' + data.chapter + '</span>';
            } else {
                html += '<span class="vn" data-v="' + v.v + '">' + v.v + '</span>';
            }
            html += '<span class="vt" data-v="' + v.v + '">' + verseInner(v, ctx) + '</span>';
            html += markersFor(v.v, ctx);
        });
        close();

        el.innerHTML = html;

        // Head and foot
        const book = BB.book(data.book);
        $('#bbHeadBook').textContent = data.book === 19 ? 'Psalm' : (book ? book.name : data.book_name);
        const tr = BB.translation(data.translation);
        $('#bbHeadTr').textContent = tr ? tr.name : data.translation;
        $('#bbRefLabel').textContent = data.ref;
        $('#bbTrLabel').textContent = data.translation;
        document.title = data.ref + ' (' + data.translation + ') | The Bible';

        const prev = data.prev;
        const next = data.next;
        const prevBtn = $('#bbPrev');
        const nextBtn = $('#bbNext');
        prevBtn.disabled = !prev;
        nextBtn.disabled = !next;
        prevBtn.querySelector('span').textContent = prev ? prev.ref : '';
        nextBtn.querySelector('span').textContent = next ? next.ref : '';
        $('#bbPrevSide').disabled = !prev;
        $('#bbNextSide').disabled = !next;
        $('#bbCopyright').textContent = data.copyright || '';
        setReadButton(!!data.read_today);
        $('#bbFoot').hidden = false;
        $('#bbNotice').hidden = true;

        if (opts.dir) {
            const page = $('#bbPage');
            page.classList.remove('bb-turn-next', 'bb-turn-prev');
            void page.offsetWidth;
            page.classList.add(opts.dir === 'prev' ? 'bb-turn-prev' : 'bb-turn-next');
        }

        // A repaint (new highlight, changed settings) is not a new reading.
        const key = data.translation + ':' + data.book + ':' + data.chapter;
        const fresh = key !== renderedKey;
        if (fresh) {
            renderedKey = key;
            shownAt = Date.now();
            readSent = !!data.read_today;
            endVisible = false;
            // Licensed text is on screen: report it (API.Bible fair use) on
            // every display, whether it came from the cache or not.
            if (data.fums_token) BB.fums(data.fums_token);
        }
        BB.emit('chapter', { data: data, fresh: fresh });
    }

    function setReadButton(done) {
        const btn = $('#bbMarkRead');
        if (!btn) return;
        btn.classList.toggle('is-done', done);
        btn.querySelector('span').textContent = done ? 'Read today' : 'Mark as read';
    }

    // ------------------------------------------------------------------ loading

    function showNotice(html) {
        const n = $('#bbNotice');
        n.innerHTML = html;
        n.hidden = false;
    }

    function skeleton() {
        textEl().innerHTML = '<div class="bb-skeleton"><span></span><span></span><span></span><span></span><span></span></div>';
    }

    function updateHash(push) {
        const s = BB.state;
        const hash = '#read=' + s.book + '.' + s.chapter + '&t=' + s.translation;
        if (window.location.hash === hash) return;
        try {
            if (push) history.pushState(null, '', hash);
            else history.replaceState(null, '', hash);
        } catch (e) { /* sandboxed */ }
    }

    async function load(t, b, c, opts) {
        opts = opts || {};
        b = Number(b);
        c = Number(c);
        const key = t + ':' + b + ':' + c;
        const token = ++loadToken;
        let data = opts.data || (!opts.fresh && cache.get(key)) || null;

        if (!data) {
            const slow = setTimeout(() => { if (token === loadToken) skeleton(); }, 160);
            try {
                data = await BB.api.get('chapter', { t: t, b: b, c: c });
            } catch (err) {
                clearTimeout(slow);
                if (token !== loadToken) return false;
                fail(err, t, b, c);
                return false;
            }
            clearTimeout(slow);
            if (token !== loadToken) return false;
        }
        if (t === 'KJV') cache.set(key, data);

        const prevScroll = main() ? main().scrollTop : 0;
        BB.state.translation = data.translation;
        BB.state.book = data.book;
        BB.state.chapter = data.chapter;
        BB.state.verse = opts.verse || 1;
        BB.state.data = data;
        render(data, opts);
        updateHash(!!opts.push);
        BB.saveState();

        const m = main();
        if (opts.verse) {
            requestAnimationFrame(() => scrollToVerse(opts.verse, { flash: opts.flash !== false, smooth: false }));
        } else if (opts.keepScroll && m) {
            m.scrollTop = prevScroll;
        } else if (m) {
            m.scrollTo({ top: 0, behavior: 'auto' });
        }
        observeEnd();
        prefetch(data);
        return true;
    }

    function fail(err, t, b, c) {
        const book = BB.book(b);
        $('#bbRefLabel').textContent = BB.ref(b, c);
        $('#bbTrLabel').textContent = t;
        $('#bbHeadBook').textContent = book ? book.name : '';
        $('#bbHeadTr').textContent = t;
        textEl().innerHTML = '';
        $('#bbFoot').hidden = true;
        let buttons = '<button type="button" class="bb-btn" data-retry>Try again</button>';
        if (t !== 'KJV') buttons = '<button type="button" class="bb-btn bb-btn-primary" data-kjv>Read ' + BB.esc(BB.ref(b, c)) + ' in the KJV</button> ' + buttons;
        showNotice('<strong>' + BB.esc(err.message) + '</strong><div>' + buttons + '</div>');
        const n = $('#bbNotice');
        const retry = n.querySelector('[data-retry]');
        const kjv = n.querySelector('[data-kjv]');
        if (retry) retry.onclick = () => load(t, b, c, { fresh: true });
        if (kjv) kjv.onclick = () => load('KJV', b, c);
    }

    function prefetch(data) {
        if (data.translation !== 'KJV' || !data.next) return;
        const nx = data.next;
        const key = 'KJV:' + nx.book + ':' + nx.chapter;
        if (cache.has(key)) return;
        const go = () => BB.api.get('chapter', { t: 'KJV', b: nx.book, c: nx.chapter })
            .then((d) => cache.set(key, d))
            .catch(() => { /* it will load on demand */ });
        if ('requestIdleCallback' in window) window.requestIdleCallback(go, { timeout: 2500 });
        else setTimeout(go, 900);
    }

    function go(dir) {
        const d = BB.state.data;
        if (!d) return;
        const target = dir === 'prev' ? d.prev : d.next;
        if (!target) return;
        hideBar();
        load(BB.state.translation, target.book, target.chapter, { dir: dir });
    }

    // ------------------------------------------------------------------ scrolling

    function verseEl(v) {
        return textEl() ? textEl().querySelector('.vt[data-v="' + v + '"]') : null;
    }

    function scrollToVerse(v, opts) {
        opts = opts || {};
        const el = verseEl(v);
        const m = main();
        if (!el || !m) return;
        const top = el.getBoundingClientRect().top - m.getBoundingClientRect().top + m.scrollTop - topbarH() - (opts.center ? m.clientHeight * 0.3 : 28);
        m.scrollTo({ top: Math.max(0, top), behavior: opts.smooth ? 'smooth' : 'auto' });
        if (opts.flash) {
            el.classList.remove('bb-flash');
            void el.offsetWidth;
            el.classList.add('bb-flash');
            setTimeout(() => el.classList.remove('bb-flash'), 2600);
        }
    }

    /** The verse at the top of the screen, for "where you stopped". */
    function topVerse() {
        const limit = (main() ? main().getBoundingClientRect().top : 0) + topbarH() + 12;
        const els = $$('.vt', textEl());
        for (const el of els) {
            if (el.getBoundingClientRect().bottom > limit) return Number(el.dataset.v);
        }
        return 1;
    }

    let lastScroll = 0;
    const settle = BB.debounce(() => {
        if (BB.state.view !== 'read' || !BB.state.data) return;
        const v = topVerse();
        if (v !== BB.state.verse) {
            BB.state.verse = v;
            BB.saveState();
        }
    }, 700);

    function onScroll() {
        const m = main();
        if (!m) return;
        const st = m.scrollTop;
        const page = $('#bbPage');
        if (page && BB.state.view === 'read') {
            const start = page.offsetTop - topbarH();
            const span = Math.max(1, page.offsetHeight - m.clientHeight + topbarH());
            const pct = Math.max(0, Math.min(1, (st - start) / span));
            $('#bbProgress').style.width = (pct * 100).toFixed(1) + '%';
        }
        // Immersive reading on phones: the bar slides away while you read
        // down, and comes back the moment you scroll up.
        const app = document.getElementById('bibleApp');
        if (app && window.innerWidth < 768 && BB.state.view === 'read') {
            const dy = st - lastScroll;
            if (dy > 10 && st > 140) app.classList.add('bb-chrome-hidden');
            else if (dy < -10 || st < 80) app.classList.remove('bb-chrome-hidden');
        } else if (app) {
            app.classList.remove('bb-chrome-hidden');
        }
        lastScroll = st;
        positionBar();
        settle();
    }

    // ------------------------------------------------------------------ "read" tracking
    // A chapter counts as read when its end has been on screen and the member
    // spent a believable time on it: about a third of the time it takes to
    // read at 250 words a minute, at least 8 seconds, never more than a minute.

    function minSeconds(data) {
        const words = data.verses.reduce((n, v) => n + v.t.split(/\s+/).length, 0);
        return Math.max(8, Math.min(60, (words / 250) * 60 * 0.35));
    }

    function maybeMarkRead() {
        const data = BB.state.data;
        if (!data || readSent || !endVisible || BB.state.view !== 'read') return;
        const wait = minSeconds(data) * 1000 - (Date.now() - shownAt);
        clearTimeout(endTimer);
        if (wait > 0) {
            endTimer = setTimeout(maybeMarkRead, wait + 50);
            return;
        }
        markRead('read');
    }

    async function markRead(source) {
        const data = BB.state.data;
        if (!data) return;
        if (!BB.ready) {
            if (source === 'manual') BB.toast('Reading history is being set up — try again after the next update.', 'info');
            return;
        }
        if (readSent && source !== 'manual') return;
        readSent = true;
        setReadButton(true);
        data.read_today = true;
        try {
            const res = await BB.api.post('mark_read', { t: data.translation, b: data.book, c: data.chapter, source: source });
            if (res.streak) BB.emit('streak', res.streak);
            if (res.completed_days && res.completed_days.length) {
                BB.emit('plans-changed');
                const day = res.completed_days[0];
                BB.toast('Day ' + day.day + ' of your plan is complete. Well done!');
            } else if (source === 'manual' && res.new) {
                BB.toast(data.ref + ' marked as read');
            }
        } catch (err) {
            readSent = false;
            setReadButton(false);
            if (source === 'manual') BB.toast(err.message, 'error');
        }
    }

    let endObserver = null;
    function observeEnd() {
        const end = document.getElementById('bbEnd');
        if (!end || !('IntersectionObserver' in window)) return;
        if (!endObserver) {
            endObserver = new IntersectionObserver((entries) => {
                entries.forEach((e) => {
                    endVisible = e.isIntersecting;
                    if (endVisible) maybeMarkRead();
                });
            }, { root: main(), rootMargin: '0px 0px 120px 0px' });
        }
        endObserver.disconnect();
        endObserver.observe(end);
    }

    // ------------------------------------------------------------------ selection

    function offsetIn(vt, node, offset) {
        const r = document.createRange();
        r.setStart(vt, 0);
        try { r.setEnd(node, offset); } catch (e) { return 0; }
        return BB.cpLen(r.toString());
    }

    /**
     * The current selection as positions in the chapter, or null.
     * @return {{vs:number, os:number, ve:number, oe:number, text:string}|null}
     */
    function selectionAnchor() {
        const sel = window.getSelection();
        const root = textEl();
        if (!sel || sel.rangeCount === 0 || sel.isCollapsed || !root) return null;
        const range = sel.getRangeAt(0);
        let vts = $$('.vt', root).filter((vt) => range.intersectsNode(vt));
        if (!vts.length) return null;

        let first = vts[0];
        let last = vts[vts.length - 1];
        let os = first.contains(range.startContainer) ? offsetIn(first, range.startContainer, range.startOffset) : 0;
        let oe = last.contains(range.endContainer) ? offsetIn(last, range.endContainer, range.endOffset) : BB.cpLen(last.textContent);

        // A selection that only grazes a neighbouring verse at its edge.
        if (vts.length > 1 && os >= BB.cpLen(first.textContent)) { vts = vts.slice(1); first = vts[0]; os = 0; }
        if (vts.length > 1 && oe === 0) { vts = vts.slice(0, -1); last = vts[vts.length - 1]; oe = BB.cpLen(last.textContent); }

        const vs = Number(first.dataset.v);
        const ve = Number(last.dataset.v);
        if (vs === ve && oe <= os) return null;

        const texts = {};
        (BB.state.data ? BB.state.data.verses : []).forEach((v) => { texts[v.v] = v.t; });
        const parts = [];
        for (let n = vs; n <= ve; n++) {
            if (texts[n] == null) continue;
            const ch = BB.chars(texts[n]);
            parts.push(ch.slice(n === vs ? os : 0, n === ve ? oe : ch.length).join(''));
        }
        const text = parts.join(' ').replace(/\s+/g, ' ').trim();
        if (!text) return null;
        return { vs: vs, os: os, ve: ve, oe: oe, text: text };
    }

    function selectVerse(v) {
        const el = verseEl(v);
        if (!el) return;
        const r = document.createRange();
        r.selectNodeContents(el);
        const sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(r);
        showBarSoon();
    }

    // ------------------------------------------------------------------ selection bar

    const bar = () => document.getElementById('bbSelBar');
    let barAnchor = null;

    function buildBar() {
        const holder = document.getElementById('bbSelColors');
        if (!holder || holder.childElementCount) return;
        holder.innerHTML = BB.colors.map((c) =>
            '<button type="button" data-color="' + c + '" aria-label="Highlight ' + c + '" title="Highlight ' + c + '"><span class="bb-sw-' + c + '"></span></button>'
        ).join('');
    }

    function hideBar() {
        const b = bar();
        if (b) b.hidden = true;
        barAnchor = null;
    }

    function positionBar() {
        const b = bar();
        if (!b || b.hidden) return;
        if (isTouch() || window.innerWidth < 768) { b.style.left = ''; b.style.top = ''; return; }
        const sel = window.getSelection();
        if (!sel || sel.rangeCount === 0) return;
        const rect = sel.getRangeAt(0).getBoundingClientRect();
        if (!rect || (rect.width === 0 && rect.height === 0)) return;
        const w = b.offsetWidth;
        const h = b.offsetHeight;
        const minTop = topbarH() + (main() ? main().getBoundingClientRect().top : 0) + 6;
        let top = rect.top - h - 12;
        if (top < minTop) top = rect.bottom + 12;
        // A selection scrolled partly off screen still gets a reachable bar.
        top = Math.max(minTop, Math.min(window.innerHeight - h - 12, top));
        const left = Math.max(8, Math.min(window.innerWidth - w - 8, rect.left + rect.width / 2 - w / 2));
        b.style.left = left + 'px';
        b.style.top = top + 'px';
    }

    function showBar() {
        if (BB.state.view !== 'read') return hideBar();
        const anchor = selectionAnchor();
        if (!anchor) return hideBar();
        barAnchor = anchor;
        buildBar();
        const b = bar();
        const wasHidden = b.hidden;
        b.hidden = false;
        positionBar();
        // Phones dock the bar at the bottom; nudge the page so the words you
        // just chose are not hidden underneath it.
        if (wasHidden && (isTouch() || window.innerWidth < 768)) {
            const sel = window.getSelection();
            const m = main();
            if (sel && sel.rangeCount && m) {
                const rect = sel.getRangeAt(0).getBoundingClientRect();
                const barTop = b.getBoundingClientRect().top;
                if (rect.bottom > barTop - 12) m.scrollBy({ top: rect.bottom - barTop + 28, behavior: 'smooth' });
            }
        }
    }
    const showBarSoon = BB.debounce(showBar, 140);

    async function paint(color) {
        const a = barAnchor;
        const d = BB.state.data;
        if (!a || !d) return;
        if (!BB.ready) {
            BB.toast('Highlights are being set up — try again after the next update.', 'info');
            return;
        }
        try {
            const list = await BB.api.post('highlight', {
                t: d.translation, b: d.book, c: d.chapter, vs: a.vs, os: a.os, ve: a.ve, oe: a.oe, color: color,
            });
            d.highlights = list;
            window.getSelection().removeAllRanges();
            hideBar();
            render(d, {});
            BB.emit('study-changed');
        } catch (err) {
            BB.toast(err.message, 'error');
        }
    }

    function selectionCitation(a) {
        const d = BB.state.data;
        return BB.ref(d.book, d.chapter, a.vs, a.ve) + ' ' + d.translation;
    }

    async function copy(a) {
        const text = '“' + a.text + '” — ' + selectionCitation(a);
        try {
            await navigator.clipboard.writeText(text);
            BB.toast('Copied');
        } catch (e) {
            BB.toast('Could not copy on this device', 'error');
        }
        window.getSelection().removeAllRanges();
        hideBar();
    }

    function onBarClick(e) {
        const btn = e.target.closest('button');
        if (!btn || !barAnchor) return;
        const a = barAnchor;
        const d = BB.state.data;
        if (btn.dataset.color) return paint(btn.dataset.color);
        const act = btn.dataset.act;
        if (act === 'erase') return paint(null);
        if (act === 'copy') return copy(a);
        window.getSelection().removeAllRanges();
        hideBar();
        if (act === 'note' && BB.study) {
            BB.study.openNoteForAnchor({
                translation: d.translation, book: d.book, chapter: d.chapter,
                vs: a.vs, os: a.os, ve: a.ve, oe: a.oe, text: a.text,
            });
        } else if (act === 'share' && BB.share) {
            BB.share.open({ text: a.text, ref: BB.ref(d.book, d.chapter, a.vs, a.ve), translation: d.translation });
        } else if (act === 'listen' && BB.voice) {
            BB.voice.start({ from: a.vs });
        }
    }

    // ------------------------------------------------------------------ input

    let pointerStartedWithSelection = false;

    function bind() {
        const root = textEl();
        const page = document.getElementById('bbPage');
        const m = main();

        if (m) m.addEventListener('scroll', onScroll, { passive: true });
        window.addEventListener('resize', () => positionBar());
        document.addEventListener('selectionchange', showBarSoon);

        const b = bar();
        if (b) {
            // Keep the selection alive while tapping the bar.
            b.addEventListener('mousedown', (e) => e.preventDefault());
            b.addEventListener('click', onBarClick);
        }

        document.addEventListener('pointerdown', (e) => {
            const sel = window.getSelection();
            pointerStartedWithSelection = !!(sel && !sel.isCollapsed) || (bar() && !bar().hidden);
            if (bar() && !bar().contains(e.target) && !(root && root.contains(e.target))) hideBar();
        }, true);

        root.addEventListener('click', (e) => {
            const marker = e.target.closest('.nm');
            if (marker) {
                e.preventDefault();
                if (BB.study) BB.study.openNote(Number(marker.dataset.note));
                return;
            }
            const num = e.target.closest('.vn, .bb-dropcap');
            if (num) {
                selectVerse(Number(num.dataset.v));
                return;
            }
            // Phones: tapping a verse selects it (drag the handles to trim);
            // tapping again anywhere just clears the selection.
            if (isTouch()) {
                const sel = window.getSelection();
                if (pointerStartedWithSelection) { if (sel) sel.removeAllRanges(); hideBar(); return; }
                const vt = e.target.closest('.vt');
                if (vt && sel && sel.isCollapsed) selectVerse(Number(vt.dataset.v));
            }
        });

        // Swipe between chapters.
        let sx = 0, sy = 0, st = 0, tracking = false;
        page.addEventListener('touchstart', (e) => {
            if (e.touches.length !== 1) { tracking = false; return; }
            const t = e.touches[0];
            sx = t.clientX; sy = t.clientY; st = Date.now();
            tracking = true;
        }, { passive: true });
        page.addEventListener('touchend', (e) => {
            if (!tracking) return;
            tracking = false;
            const t = e.changedTouches[0];
            const dx = t.clientX - sx;
            const dy = t.clientY - sy;
            const sel = window.getSelection();
            if (sel && !sel.isCollapsed) return;
            if (Date.now() - st > 650 || Math.abs(dx) < 70 || Math.abs(dx) < Math.abs(dy) * 2) return;
            go(dx < 0 ? 'next' : 'prev');
        }, { passive: true });

        document.addEventListener('keydown', (e) => {
            if (BB.state.view !== 'read' || e.altKey || e.ctrlKey || e.metaKey) return;
            const tag = (e.target && e.target.tagName) || '';
            if (/INPUT|TEXTAREA|SELECT/.test(tag) || (e.target && e.target.isContentEditable)) return;
            if (document.documentElement.classList.contains('app-modal-open')) return;
            if (e.key === 'ArrowRight') { e.preventDefault(); go('next'); }
            else if (e.key === 'ArrowLeft') { e.preventDefault(); go('prev'); }
            else if (e.key === 'Escape') { const s = window.getSelection(); if (s) s.removeAllRanges(); hideBar(); }
        });

        $('#bbPrev').addEventListener('click', () => go('prev'));
        $('#bbNext').addEventListener('click', () => go('next'));
        $('#bbPrevSide').addEventListener('click', () => go('prev'));
        $('#bbNextSide').addEventListener('click', () => go('next'));
        $('#bbMarkRead').addEventListener('click', () => {
            if ($('#bbMarkRead').classList.contains('is-done')) {
                BB.toast('Already counted for today');
                return;
            }
            markRead('manual');
        });

        // Re-render when the layout or verse numbers change.
        BB.on('prefs', () => { if (BB.state.data) render(BB.state.data, {}); });
    }

    function invalidate() {
        cache.clear();
    }

    BB.reader = {
        load: load,
        render: render,
        go: go,
        scrollToVerse: scrollToVerse,
        verseEl: verseEl,
        topVerse: topVerse,
        markRead: markRead,
        hideBar: hideBar,
        invalidate: invalidate,
        bind: bind,
        selectionAnchor: selectionAnchor,
    };
})();
