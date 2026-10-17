/* /assets/js/hymnal/share.js
 *
 * Share as a card: the verses a member picks, drawn on a <canvas> as a
 * Household of David picture, square for a WhatsApp post or 9:16 for a Status,
 * and handed to the phone's own share sheet (downloaded where there is none).
 *
 * Built the way the Bible's verse cards are (assets/js/bible/share.js), with
 * the same six backgrounds, so a hymn and a verse shared side by side look as
 * if they came from one church. The layout is a hymn book's: the number in a
 * pill, the title in Garamond, verse numbers hanging in the margin and the
 * refrain in italic with a rule beside it, as on the page.
 *
 * Most hymns are too long for one picture, so the member chooses what goes on
 * it. It opens on verse 1 and the refrain, and the type shrinks to fit; when it
 * still will not fit, the card says so with an ellipsis and the dialog says
 * what to do, rather than cutting a line off silently.
 */
(function (window, document) {
    'use strict';

    var HY = window.HODHymnal;
    if (!HY) {
        return;
    }
    var esc = HY.esc;

    /* `badge` is the ink on the accent-coloured number pill. */
    var THEMES = {
        paper:   { label: 'Paper',   bg: ['#FBF6EA', '#EFE4CC'], ink: '#2B261F', accent: '#9E1B1B', badge: '#FFFFFF', soft: 'rgba(43,38,31,.55)',    logo: 'color' },
        night:   { label: 'Night',   bg: ['#0B1426', '#1D356A'], ink: '#F1E6C8', accent: '#E2B65C', badge: '#0B1426', soft: 'rgba(241,230,200,.6)',  logo: 'white' },
        royal:   { label: 'Royal',   bg: ['#1D356A', '#7F1D1D'], ink: '#FFFFFF', accent: '#FCD34D', badge: '#1D356A', soft: 'rgba(255,255,255,.7)',  logo: 'white' },
        sunrise: { label: 'Sunrise', bg: ['#F59E0B', '#BE185D'], ink: '#FFFFFF', accent: '#FFF7ED', badge: '#BE185D', soft: 'rgba(255,255,255,.78)', logo: 'white' },
        forest:  { label: 'Forest',  bg: ['#064E3B', '#134E4A'], ink: '#ECFDF5', accent: '#FCD34D', badge: '#064E3B', soft: 'rgba(236,253,245,.7)',  logo: 'white' },
        rose:    { label: 'Rose',    bg: ['#FDF2F8', '#FBCFE8'], ink: '#4A1D2F', accent: '#BE185D', badge: '#FFFFFF', soft: 'rgba(74,29,47,.55)',    logo: 'color' }
    };

    var SERIF = '"Literata", Georgia, "Times New Roman", serif';
    var TITLE = '"EB Garamond", Georgia, "Times New Roman", serif';
    var SANS = '"Inter", system-ui, -apple-system, "Segoe UI", sans-serif';
    var BRAND = 'HOUSEHOLD OF DAVID · LEKKI';

    /* Which songs may leave the building as a picture.
     *
     * Every one of the 511 hymns is public domain, words and tune alike. A song
     * the music team adds carries its own licence, and a CCLI licence covers
     * singing and projecting the words in church — not posting them online —
     * so those songs, and anything used by someone's written permission, stay
     * off the share sheet. A song written for this church, or under Creative
     * Commons with credit, may go. */
    var SHAREABLE = { 'PD': true, 'CHURCH': true, 'CC-BY': true, 'CC-BY-SA': true };

    var st = {
        song: null,        /* the song the dialog was opened for */
        lang: '',
        picked: [],        /* indexes into its sections */
        theme: 'paper',
        format: 'square',
        brand: true,
        logo: null,
        logoTried: false,
        drawToken: 0,
        drawing: Promise.resolve(),
        overflow: false
    };

    /* ------------------------------------------------------------ the rule */

    /* Why this song may not be shared, in words for the member, or '' if it
     * may. Asked when the buttons are painted and again when one is pressed. */
    function refusal(song) {
        if (!song) {
            return 'Open a hymn first.';
        }
        if (song.origin === 'studio' && song.status && song.status !== 'published') {
            return 'This song has not been published to the congregation yet, so it cannot be shared.';
        }
        var code = (song.licence && song.licence.code) || '';
        if (!SHAREABLE[code]) {
            return code === 'CCLI'
                ? 'This song is sung under the church’s CCLI licence, which covers singing it in church but not posting the words online.'
                : 'This song is used by permission, and that permission does not cover posting the words online.';
        }
        var words = HY.words(song);
        if (!words || !words.sections || !words.sections.length) {
            return 'The words for this song have not been added yet.';
        }
        return '';
    }

    /* The two share buttons say whether they will work, and why not. They stay
     * pressable, so a tap explains itself instead of doing nothing. */
    function paint(song) {
        var why = refusal(song);
        Array.prototype.forEach.call(document.querySelectorAll('[data-share]'), function (btn) {
            if (why) {
                btn.setAttribute('aria-disabled', 'true');
                btn.title = why;
            } else {
                btn.removeAttribute('aria-disabled');
                btn.title = 'Share as a card';
            }
        });
    }

    /* ------------------------------------------------------------- content */

    function sections() {
        var words = st.song ? HY.words(st.song) : null;
        return (words && words.sections) || [];
    }

    function isRefrain(sec) {
        return sec.kind === 'chorus' || /refrain|chorus/i.test(sec.label || '');
    }

    function chipLabel(sec, i) {
        if (sec.kind === 'verse') {
            return 'Verse ' + (sec.num || i + 1);
        }
        if (sec.label) {
            return sec.label;
        }
        var k = String(sec.kind || 'part');
        return k.charAt(0).toUpperCase() + k.slice(1);
    }

    /* Verse 1 and the refrain: what a hymn board would show, and what most
     * people mean by "send me that hymn". */
    function defaultPick(secs) {
        var verse = -1;
        var refrain = -1;
        secs.forEach(function (sec, i) {
            if (verse === -1 && sec.kind === 'verse') {
                verse = i;
            }
            if (refrain === -1 && isRefrain(sec)) {
                refrain = i;
            }
        });
        var out = [];
        if (verse !== -1) {
            out.push(verse);
        }
        if (refrain !== -1) {
            out.push(refrain);
        }
        if (!out.length && secs.length) {
            out.push(0);
        }
        return out.sort(function (a, b) { return a - b; });
    }

    function credit(song) {
        var bits = [];
        var who = song.writer || song.attribution;
        if (who) {
            bits.push(who);
        }
        if (song.year) {
            bits.push(String(song.year));
        }
        var code = (song.licence && song.licence.code) || '';
        if (code === 'CC-BY' || code === 'CC-BY-SA') {
            bits.push(code.replace('-', ' '));
        }
        return bits.join(' · ');
    }

    /* --------------------------------------------------------------- canvas */

    function setSpacing(ctx, px) {
        if ('letterSpacing' in ctx) {
            ctx.letterSpacing = px + 'px';
        }
    }

    function wrap(ctx, text, maxWidth) {
        var words = String(text).split(/\s+/).filter(Boolean);
        var lines = [];
        var line = '';
        words.forEach(function (w) {
            var test = line ? line + ' ' + w : w;
            if (line && ctx.measureText(test).width > maxWidth) {
                lines.push(line);
                line = w;
            } else {
                line = test;
            }
        });
        if (line) {
            lines.push(line);
        }
        return lines.length ? lines : [''];
    }

    /* A word that closes a phrase: a hymn line is best broken there. */
    var PHRASE_END = /[,;:!?.\u2014\u2013]['"\u2019\u201d)\]]*$/;

    /* One stored line onto the card. Many hymns store two sung lines as one
     * ("Take up thy cross," the Savior said, "If thou wouldst My disciple
     * be;), so when a line is too wide it is broken once, after the
     * punctuation nearest the middle, and the second half starts flush like
     * any other line. Only when no such break fits does it fall back to
     * wrapping word by word, with the run-on lines indented and counted as
     * `ragged` so the fit can prefer a size that avoids them. */
    function breakLine(ctx, text, width, hang) {
        if (ctx.measureText(text).width <= width) {
            return { parts: [text], hang: false, ragged: 0 };
        }
        var words = String(text).split(/\s+/).filter(Boolean);
        var best = null;
        for (var i = 1; i < words.length; i++) {
            if (!PHRASE_END.test(words[i - 1])) {
                continue;
            }
            var a = words.slice(0, i).join(' ');
            var b = words.slice(i).join(' ');
            var wa = ctx.measureText(a).width;
            var wb = ctx.measureText(b).width;
            if (wa > width || wb > width) {
                continue;
            }
            var score = Math.abs(wa - wb);
            if (!best || score < best.score) {
                best = { score: score, parts: [a, b] };
            }
        }
        if (best) {
            return { parts: best.parts, hang: false, ragged: 0 };
        }
        var first = wrap(ctx, text, width - hang);
        return { parts: first, hang: true, ragged: first.length - 1 };
    }

    /* One string on one line, shrinking the type and then ellipsising, for the
     * credit line under the verses. */
    function fitLine(ctx, text, weight, size, minSize, family, maxWidth) {
        for (; size >= minSize; size -= 1) {
            ctx.font = weight + ' ' + size + 'px ' + family;
            if (ctx.measureText(text).width <= maxWidth) {
                return text;
            }
        }
        var cut = text;
        while (cut.length > 1 && ctx.measureText(cut + '…').width > maxWidth) {
            cut = cut.slice(0, -1);
        }
        return cut.replace(/\s+$/, '') + '…';
    }

    /* Lay the chosen sections out at one type size. Positions are relative to
     * the top-left of the text block; draw() centres the block on the card. */
    function layoutBody(ctx, blocks, size, maxW) {
        var lh = Math.round(size * 1.42);
        var gap = Math.round(size * 0.9);
        var labelSize = Math.max(16, Math.round(size * 0.46));
        var labelH = Math.round(labelSize * 2);
        var indent = Math.round(size * 0.95);
        var hang = Math.round(size * 0.8);
        var numbered = blocks.some(function (b) { return b.kind === 'verse' && b.num; });
        var gutter = numbered ? Math.round(size * 1.2) : 0;
        var rows = [];
        var rules = [];
        var y = 0;
        var width = 0;
        var ragged = 0;

        blocks.forEach(function (b, bi) {
            if (bi) {
                y += gap;
            }
            var refrain = isRefrain(b);
            var x = refrain ? indent : 0;
            if (b.kind !== 'verse') {
                rows.push({ t: 'label', text: String(b.label || b.kind || '').toUpperCase(), x: x, y: y, size: labelSize });
                y += labelH;
            }
            ctx.font = (refrain ? 'italic ' : '') + '400 ' + size + 'px ' + SERIF;
            var top = y;
            (b.lines || []).forEach(function (line, li) {
                var broken = breakLine(ctx, line, maxW - gutter - x, hang);
                ragged += broken.ragged;
                broken.parts.forEach(function (part, pi) {
                    var px = x + (pi && broken.hang ? hang : 0);
                    width = Math.max(width, px + ctx.measureText(part).width);
                    rows.push({
                        t: 'line',
                        text: part,
                        x: px,
                        y: y,
                        italic: refrain,
                        num: li === 0 && pi === 0 && b.kind === 'verse' ? String(b.num || '') : ''
                    });
                    y += lh;
                });
            });
            if (refrain && y > top) {
                rules.push({ x: Math.round(indent * 0.35), y0: top + Math.round(lh * 0.12), y1: y - Math.round(lh * 0.22) });
            }
        });

        return { rows: rows, rules: rules, height: y, width: width + gutter, gutter: gutter, size: size, lh: lh, ragged: ragged };
    }

    /* The largest type that fits the box — first looking for one where every
     * line breaks at a phrase (down to 70% of the largest), then for any.
     * Below the floor it keeps the floor and drops lines from the end, closing
     * with an ellipsis, and the dialog says so. */
    function fitBody(ctx, blocks, maxSize, minSize, maxW, boxH) {
        var lay = null;
        var size;
        var tidyFloor = Math.max(minSize, Math.round(maxSize * 0.7));
        for (size = maxSize; size >= tidyFloor; size -= 1) {
            lay = layoutBody(ctx, blocks, size, maxW);
            if (lay.height <= boxH && lay.ragged === 0) {
                lay.overflow = false;
                return lay;
            }
        }
        for (size = maxSize; size >= minSize; size -= 1) {
            lay = layoutBody(ctx, blocks, size, maxW);
            if (lay.height <= boxH) {
                lay.overflow = false;
                return lay;
            }
        }
        lay = layoutBody(ctx, blocks, minSize, maxW);
        var keep = [];
        for (var i = 0; i < lay.rows.length; i++) {
            var r = lay.rows[i];
            if (r.y + lay.lh * 2 > boxH) {
                break;
            }
            keep.push(r);
        }
        var last = keep.length ? keep[keep.length - 1] : null;
        var y = last ? last.y + (last.t === 'label' ? Math.round(last.size * 2) : lay.lh) : 0;
        keep.push({ t: 'line', text: '…', x: last ? last.x : 0, y: y, italic: !!(last && last.italic), num: '' });
        lay.rows = keep;
        lay.rules = lay.rules.map(function (rule) {
            return { x: rule.x, y0: rule.y0, y1: Math.min(rule.y1, y + lay.lh) };
        }).filter(function (rule) { return rule.y0 < y; });
        lay.height = Math.min(boxH, y + lay.lh);
        lay.overflow = true;
        return lay;
    }

    function loadFonts() {
        if (!document.fonts || !document.fonts.load) {
            return Promise.resolve();
        }
        return Promise.all([
            document.fonts.load('600 64px "EB Garamond"'),
            document.fonts.load('400 40px "Literata"'),
            document.fonts.load('italic 400 40px "Literata"'),
            document.fonts.load('600 26px "Inter"'),
            document.fonts.load('700 26px "Inter"')
        ]).catch(function () {
            /* A system serif will do. */
        });
    }

    function loadLogo() {
        if (st.logo || st.logoTried) {
            return Promise.resolve(st.logo);
        }
        return new Promise(function (resolve) {
            var img = new Image();
            img.onload = function () {
                st.logo = img;
                st.logoTried = true;
                resolve(img);
            };
            img.onerror = function () {
                st.logoTried = true;
                resolve(null);
            };
            img.src = HY.cfg.logo || '/assets/images/logo_hod.png';
        });
    }

    /* The logo as a white silhouette, for the dark backgrounds. */
    function whiteLogo(img, w, h) {
        var c = document.createElement('canvas');
        c.width = w;
        c.height = h;
        var x = c.getContext('2d');
        x.drawImage(img, 0, 0, w, h);
        x.globalCompositeOperation = 'source-in';
        x.fillStyle = '#ffffff';
        x.fillRect(0, 0, w, h);
        return c;
    }

    function roundRect(ctx, x, y, w, h, r) {
        ctx.beginPath();
        ctx.moveTo(x + r, y);
        ctx.arcTo(x + w, y, x + w, y + h, r);
        ctx.arcTo(x + w, y + h, x, y + h, r);
        ctx.arcTo(x, y + h, x, y, r);
        ctx.arcTo(x, y, x + w, y, r);
        ctx.closePath();
    }

    function render(canvas) {
        var song = st.song;
        var story = st.format === 'story';
        var W = 1080;
        var H = story ? 1920 : 1080;
        canvas.width = W;
        canvas.height = H;
        var ctx = canvas.getContext('2d');
        var t = THEMES[st.theme] || THEMES.paper;
        var words = HY.words(song) || {};
        var secs = sections();
        var blocks = st.picked.map(function (i) { return secs[i]; }).filter(Boolean);

        /* Background, and a soft light from the top. */
        var g = ctx.createLinearGradient(0, 0, W, H);
        g.addColorStop(0, t.bg[0]);
        g.addColorStop(1, t.bg[1]);
        ctx.fillStyle = g;
        ctx.fillRect(0, 0, W, H);
        var light = ctx.createRadialGradient(W * 0.25, H * 0.08, 10, W * 0.25, H * 0.08, W * 0.95);
        light.addColorStop(0, 'rgba(255,255,255,0.16)');
        light.addColorStop(1, 'rgba(255,255,255,0)');
        ctx.fillStyle = light;
        ctx.fillRect(0, 0, W, H);

        /* A fine frame, as on a hymn-book page. */
        ctx.strokeStyle = t.soft;
        ctx.globalAlpha = 0.45;
        ctx.lineWidth = 2;
        roundRect(ctx, 36, 36, W - 72, H - 72, 28);
        ctx.stroke();
        ctx.globalAlpha = 1;

        var pad = 104;
        var maxW = W - pad * 2;
        var cx = W / 2;
        var y = story ? 250 : 100;
        ctx.textAlign = 'center';
        ctx.textBaseline = 'alphabetic';

        /* The number, in a pill. */
        ctx.font = '700 24px ' + SANS;
        setSpacing(ctx, 5);
        var badge = 'HYMN ' + song.n;
        var bw = Math.round(ctx.measureText(badge).width) + 48;
        ctx.fillStyle = t.accent;
        roundRect(ctx, cx - bw / 2, y, bw, 50, 25);
        ctx.fill();
        ctx.fillStyle = t.badge;
        /* letter-spacing trails the last glyph; shift by half to stay centred. */
        ctx.fillText(badge, cx + 2.5, y + 34);
        setSpacing(ctx, 0);
        y += 50 + 34;

        /* The title: two lines at most if the type can shrink to make it so. */
        var title = String(words.title || song.title || '');
        var tSize = story ? 76 : 66;
        var tLines = [];
        for (; tSize >= 44; tSize -= 2) {
            ctx.font = '600 ' + tSize + 'px ' + TITLE;
            tLines = wrap(ctx, title, maxW);
            if (tLines.length <= 2) {
                break;
            }
        }
        ctx.fillStyle = t.ink;
        tLines.slice(0, 3).forEach(function (line) {
            ctx.fillText(line, cx, y + Math.round(tSize * 0.78));
            y += Math.round(tSize * 1.1);
        });

        /* An ornament under the title: rule, diamond, rule. */
        y += 18;
        ctx.strokeStyle = t.accent;
        ctx.fillStyle = t.accent;
        ctx.lineWidth = 2;
        ctx.beginPath();
        ctx.moveTo(cx - 96, y);
        ctx.lineTo(cx - 16, y);
        ctx.moveTo(cx + 16, y);
        ctx.lineTo(cx + 96, y);
        ctx.stroke();
        ctx.beginPath();
        ctx.moveTo(cx, y - 7);
        ctx.lineTo(cx + 7, y);
        ctx.lineTo(cx, y + 7);
        ctx.lineTo(cx - 7, y);
        ctx.closePath();
        ctx.fill();
        y += story ? 70 : 46;

        /* The foot: who wrote it, then the church. */
        var footBase = H - (story ? 190 : 92);
        var creditBase = st.brand ? footBase - 62 : footBase;
        var bodyBottom = creditBase - (story ? 96 : 64);

        /* The verses, as large as the space allows. */
        var lay = fitBody(ctx, blocks, story ? 60 : 52, story ? 26 : 24, maxW, Math.max(80, bodyBottom - y));
        st.overflow = lay.overflow;
        var x0 = Math.round((W - lay.width) / 2) + lay.gutter;
        var top = y + Math.max(0, Math.round((bodyBottom - y - lay.height) / 2));
        var size = lay.size;
        var ascent = Math.round(size * 1.0);

        ctx.textAlign = 'left';
        lay.rows.forEach(function (r) {
            if (r.t === 'label') {
                ctx.font = '700 ' + r.size + 'px ' + SANS;
                setSpacing(ctx, Math.round(r.size * 0.22));
                ctx.fillStyle = t.accent;
                ctx.fillText(r.text, x0 + r.x, top + r.y + Math.round(r.size * 1.25));
                setSpacing(ctx, 0);
                return;
            }
            ctx.font = (r.italic ? 'italic ' : '') + '400 ' + size + 'px ' + SERIF;
            ctx.fillStyle = t.ink;
            ctx.fillText(r.text, x0 + r.x, top + r.y + ascent);
            if (r.num) {
                ctx.font = '700 ' + Math.round(size * 0.62) + 'px ' + SANS;
                ctx.fillStyle = t.accent;
                ctx.textAlign = 'right';
                ctx.fillText(r.num, x0 - Math.round(size * 0.4), top + r.y + ascent);
                ctx.textAlign = 'left';
            }
        });
        ctx.strokeStyle = t.accent;
        ctx.globalAlpha = 0.6;
        ctx.lineWidth = 3;
        lay.rules.forEach(function (rule) {
            ctx.beginPath();
            ctx.moveTo(x0 + rule.x, top + rule.y0);
            ctx.lineTo(x0 + rule.x, top + rule.y1);
            ctx.stroke();
        });
        ctx.globalAlpha = 1;

        /* Credit. */
        ctx.textAlign = 'center';
        var who = credit(song);
        if (who) {
            ctx.fillStyle = t.soft;
            var line = fitLine(ctx, who, '500', 26, 20, SANS, maxW);
            ctx.fillText(line, cx, creditBase);
        }

        return lay;
    }

    function drawBrand(canvas, img) {
        if (!st.brand) {
            return;
        }
        var ctx = canvas.getContext('2d');
        var t = THEMES[st.theme] || THEMES.paper;
        var W = canvas.width;
        var H = canvas.height;
        var by = H - (st.format === 'story' ? 190 : 92);
        ctx.textAlign = 'center';
        ctx.fillStyle = t.soft;
        ctx.font = '600 24px ' + SANS;
        setSpacing(ctx, 5);
        if (img && img.naturalWidth) {
            var lhH = 54;
            var lw = Math.round(img.naturalWidth * (lhH / img.naturalHeight));
            var tw = ctx.measureText(BRAND).width;
            var lx = W / 2 - (lw + 22 + tw) / 2;
            ctx.globalAlpha = 0.9;
            ctx.drawImage(t.logo === 'white' ? whiteLogo(img, lw, lhH) : img, lx, by - lhH + 14, lw, lhH);
            ctx.globalAlpha = 1;
            ctx.fillText(BRAND, lx + lw + 22 + tw / 2, by);
        } else {
            ctx.fillText(BRAND, W / 2, by);
        }
        setSpacing(ctx, 0);
    }

    /* Draw the card. Fonts and the logo arrive once and are reused; a newer
     * draw supersedes an older one still waiting on them. */
    function draw() {
        var token = ++st.drawToken;
        st.drawing = Promise.all([loadFonts(), loadLogo()]).then(function (got) {
            if (token !== st.drawToken || !st.song) {
                return;
            }
            var canvas = document.getElementById('hyShareCanvas');
            if (!canvas) {
                return;
            }
            render(canvas);
            drawBrand(canvas, got[1]);
            paintState();
        });
        return st.drawing;
    }

    /* ------------------------------------------------------------- dialog */

    function paintState() {
        var none = !st.picked.length;
        var hint = document.getElementById('hyShareHint');
        if (hint) {
            if (none) {
                hint.textContent = 'Choose at least one verse to put on the card.';
            } else if (st.overflow) {
                hint.textContent = st.format === 'story'
                    ? 'Not all of that fits. Choose fewer verses.'
                    : 'Not all of that fits. Choose fewer verses, or try Status / Story.';
            } else {
                hint.textContent = '';
            }
        }
        ['hyShareGo', 'hyShareDownload'].forEach(function (id) {
            var b = document.getElementById(id);
            if (b) {
                b.disabled = none;
            }
        });
        var canvas = document.getElementById('hyShareCanvas');
        if (canvas && st.song) {
            var secs = sections();
            var what = st.picked.map(function (i) { return secs[i] ? chipLabel(secs[i], i).toLowerCase() : ''; })
                .filter(Boolean).join(', ');
            canvas.setAttribute('aria-label', 'Preview of the card: Hymn ' + st.song.n + ', '
                + ((HY.words(st.song) || {}).title || st.song.title) + (what ? ' — ' + what : ''));
        }
    }

    function paintChips() {
        var host = document.getElementById('hyShareSections');
        if (!host) {
            return;
        }
        host.innerHTML = sections().map(function (sec, i) {
            var on = st.picked.indexOf(i) !== -1;
            return '<button type="button" class="hy-chip' + (on ? ' is-on' : '') + '" data-sec="' + i + '"'
                + ' aria-pressed="' + (on ? 'true' : 'false') + '">' + esc(chipLabel(sec, i)) + '</button>';
        }).join('');
    }

    function paintThemes() {
        var host = document.getElementById('hyShareThemes');
        if (!host) {
            return;
        }
        host.innerHTML = '';
        Object.keys(THEMES).forEach(function (k) {
            var t = THEMES[k];
            var b = document.createElement('button');
            b.type = 'button';
            b.setAttribute('data-theme', k);
            b.setAttribute('aria-pressed', String(k === st.theme));
            b.className = k === st.theme ? 'is-on' : '';
            b.style.background = 'linear-gradient(135deg,' + t.bg[0] + ',' + t.bg[1] + ')';
            b.style.color = t.ink;
            b.textContent = t.label;
            host.appendChild(b);
        });
    }

    function paintSeg(id, attr, value) {
        Array.prototype.forEach.call(document.querySelectorAll('#' + id + ' [' + attr + ']'), function (b) {
            var on = b.getAttribute(attr) === value;
            b.classList.toggle('is-on', on);
            b.setAttribute('aria-pressed', String(on));
        });
    }

    function open() {
        var song = HY.state.song;
        var why = refusal(song);
        if (why) {
            HY.say(why, 'error');
            return;
        }
        /* The same song in the same language keeps what was picked last time;
         * anything else starts again from verse 1 and the refrain. */
        if (st.song !== song || st.lang !== HY.state.lang) {
            st.song = song;
            st.lang = HY.state.lang;
            st.picked = defaultPick(sections());
        }
        paintChips();
        paintThemes();
        paintSeg('hyShareFormat', 'data-format', st.format);
        var brand = document.getElementById('hyShareBrand');
        if (brand) {
            brand.checked = st.brand;
        }
        paintState();
        if (HY.openModal) {
            HY.openModal('hyShareModal');
        }
        draw();
    }

    /* ---------------------------------------------------------- the image */

    function fileName() {
        var title = String((HY.words(st.song) || {}).title || st.song.title || '');
        var slug = title.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 60);
        return 'hymn-' + st.song.n + (slug ? '-' + slug : '') + '.png';
    }

    function toBlob() {
        return st.drawing.then(function () {
            return new Promise(function (resolve) {
                var canvas = document.getElementById('hyShareCanvas');
                if (!canvas || !canvas.toBlob) {
                    resolve(null);
                    return;
                }
                canvas.toBlob(function (b) { resolve(b); }, 'image/png');
            });
        });
    }

    function save(blob) {
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = fileName();
        /* Inside the open dialog: modal-manager.js cancels clicks on anything
         * outside it, and that would swallow the download. */
        (document.getElementById('hyShareModal') || document.body).appendChild(a);
        a.click();
        a.remove();
        window.setTimeout(function () { URL.revokeObjectURL(url); }, 4000);
    }

    function download() {
        if (!st.song || !st.picked.length) {
            return;
        }
        toBlob().then(function (blob) {
            if (!blob) {
                HY.say('This browser could not make the picture.', 'error');
                return;
            }
            save(blob);
        });
    }

    function share() {
        if (!st.song || !st.picked.length) {
            return;
        }
        var title = (HY.words(st.song) || {}).title || st.song.title;
        toBlob().then(function (blob) {
            if (!blob) {
                HY.say('This browser could not make the picture.', 'error');
                return null;
            }
            var file = null;
            try {
                file = new File([blob], fileName(), { type: 'image/png' });
            } catch (e) {
                file = null;
            }
            if (file && navigator.canShare && navigator.canShare({ files: [file] })) {
                return navigator.share({
                    files: [file],
                    title: 'Hymn ' + st.song.n + ' · ' + title,
                    text: 'Hymn ' + st.song.n + ' · ' + title + ' — Household of David, Lekki'
                }).catch(function (err) {
                    if (err && err.name === 'AbortError') {
                        return;
                    }
                    save(blob);
                    HY.say('Picture saved — share it from your downloads.');
                });
            }
            save(blob);
            HY.say('Picture saved — share it from your downloads.');
            return null;
        });
    }

    /* ------------------------------------------------------------- wiring */

    HY.on('hyShareSections', 'click', function (e) {
        var chip = e.target.closest('[data-sec]');
        if (!chip) {
            return;
        }
        var i = Number(chip.getAttribute('data-sec'));
        var at = st.picked.indexOf(i);
        if (at === -1) {
            st.picked.push(i);
            st.picked.sort(function (a, b) { return a - b; });
        } else {
            st.picked.splice(at, 1);
        }
        var on = at === -1;
        chip.classList.toggle('is-on', on);
        chip.setAttribute('aria-pressed', String(on));
        paintState();
        draw();
    });

    HY.on('hyShareThemes', 'click', function (e) {
        var b = e.target.closest('[data-theme]');
        if (!b) {
            return;
        }
        st.theme = b.getAttribute('data-theme');
        Array.prototype.forEach.call(e.currentTarget.children, function (x) {
            x.classList.toggle('is-on', x === b);
            x.setAttribute('aria-pressed', String(x === b));
        });
        draw();
    });

    HY.on('hyShareFormat', 'click', function (e) {
        var b = e.target.closest('[data-format]');
        if (!b) {
            return;
        }
        st.format = b.getAttribute('data-format');
        paintSeg('hyShareFormat', 'data-format', st.format);
        draw();
    });

    HY.on('hyShareBrand', 'change', function (e) {
        st.brand = e.target.checked;
        draw();
    });

    HY.on('hyShareGo', 'click', share);
    HY.on('hyShareDownload', 'click', download);

    HY.share = {
        open: open,
        paint: paint,
        refusal: refusal
    };
})(window, document);
