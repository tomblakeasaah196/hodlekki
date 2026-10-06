// /tests/special_events/js/svg_tokens.test.mjs
//
// The share-card template engine (guide §14.3, §14.4, §22.1).
//
// The engine runs in a browser, so the test gives it the pieces of DOM it
// actually uses — DOMParser, XMLSerializer, document.createElementNS and a
// canvas 2D context for text measurement — and nothing else. That keeps the
// test honest about the API surface: if @se/core/svg.js ever reaches for
// something heavier, this file stops running and says so.

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { register } from 'node:module';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const repo = join(dirname(fileURLToPath(import.meta.url)), '..', '..', '..');

// @se/core/svg.js imports 'qrcode-generator' as a bare specifier, which the
// browser resolves through se_import_map(). Node needs to be told the same
// thing before the module is loaded.
register('./vendor_loader.mjs', import.meta.url);

// --------------------------------------------------------------------------
// A very small XML DOM, enough for the engine
// --------------------------------------------------------------------------

class Node {
    constructor(name, attrs = {}) {
        this.nodeName = name;
        this.tagName = name;
        this.attrs = new Map(Object.entries(attrs));
        this.childNodes = [];
        this.parentNode = null;
        this.textContent = '';
    }

    get id() { return this.attrs.get('id') || ''; }
    get firstChild() { return this.childNodes[0] || null; }

    getAttribute(name) { return this.attrs.has(name) ? this.attrs.get(name) : null; }
    setAttribute(name, value) { this.attrs.set(name, String(value)); }
    setAttributeNS(ns, name, value) { this.attrs.set(name.replace(/^.*:/, ''), String(value)); }
    removeChild(child) {
        this.childNodes = this.childNodes.filter((n) => n !== child);
        child.parentNode = null;
        return child;
    }
    appendChild(child) {
        child.parentNode = this;
        this.childNodes.push(child);
        return child;
    }
    insertBefore(child, ref) {
        const at = ref ? this.childNodes.indexOf(ref) : 0;
        child.parentNode = this;
        this.childNodes.splice(at < 0 ? 0 : at, 0, child);
        return child;
    }
    replaceChild(fresh, old) {
        const at = this.childNodes.indexOf(old);
        if (at >= 0) { this.childNodes[at] = fresh; fresh.parentNode = this; old.parentNode = null; }
        return old;
    }
    remove() { this.parentNode?.removeChild(this); }

    descendants() {
        const out = [];
        for (const child of this.childNodes) {
            out.push(child, ...child.descendants());
        }
        return out;
    }
    querySelectorAll(selector) {
        if (selector === '[id]') return this.descendants().filter((n) => n.attrs.has('id'));
        return this.descendants().filter((n) => n.nodeName === selector);
    }
    querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }
    getElementsByTagName(name) { return this.querySelectorAll(name); }
}

/** Parse a tiny subset of SVG: elements, attributes, text, self-closing. */
function parseXml(source) {
    const root = new Node('#document');
    const stack = [root];
    const tokens = source.replace(/<\?[^?]*\?>|<!--[\s\S]*?-->/g, '').matchAll(/<([^>]+)>|([^<]+)/g);

    for (const [, tag, text] of tokens) {
        if (text !== undefined) {
            const trimmed = text.trim();
            if (trimmed) stack[stack.length - 1].textContent += trimmed;
            continue;
        }
        if (tag.startsWith('/')) { stack.pop(); continue; }

        const selfClosing = tag.endsWith('/');
        const body = selfClosing ? tag.slice(0, -1) : tag;
        const name = body.match(/^[^\s]+/)[0];

        const attrs = {};
        for (const [, key, value] of body.slice(name.length).matchAll(/([a-zA-Z_:][\w:.-]*)\s*=\s*"([^"]*)"/g)) {
            attrs[key] = value;
        }

        const node = new Node(name, attrs);
        stack[stack.length - 1].appendChild(node);
        if (!selfClosing) stack.push(node);
    }

    const element = root.childNodes[0];
    root.documentElement = element;
    root.createElementNS = (ns, n) => new Node(n);

    return root;
}

function serialize(node) {
    if (node.nodeName === '#text') return node.textContent;

    const attrs = [...node.attrs].map(([k, v]) => ` ${k}="${v}"`).join('');
    const inner = node.childNodes.map(serialize).join('') + (node.textContent || '');

    return `<${node.nodeName}${attrs}>${inner}</${node.nodeName}>`;
}

globalThis.DOMParser = class {
    parseFromString(source) { return parseXml(source); }
};
globalThis.XMLSerializer = class {
    serializeToString(node) { return serialize(node); }
};
globalThis.document = {
    createElementNS: (ns, name) => new Node(name),
    createTextNode: (text) => Object.assign(new Node('#text'), { textContent: text }),
    // Text fitting measures with a canvas; 10 px per character is close
    // enough to exercise the shrink-and-wrap loop deterministically.
    createElement: () => ({
        getContext: () => ({
            font: '',
            measureText(text) {
                const size = parseFloat(/(\d+(?:\.\d+)?)px/.exec(this.font)?.[1] || '16');
                return { width: text.length * size * 0.6 };
            },
        }),
    }),
};

const { readTokens, renderTemplate, templateSize, qrGroup } =
    await import(join(repo, 'assets/se/js/core/svg.js'));

// --------------------------------------------------------------------------
// Tokens
// --------------------------------------------------------------------------

const TEMPLATE = `<svg xmlns="http://www.w3.org/2000/svg" width="1080" height="1920" viewBox="0 0 1080 1920">
  <rect id="se__fill__bg" x="0" y="0" width="1080" height="1920" fill="#000000"/>
  <stop id="se__stop__primary" offset="0" stop-color="#111111"/>
  <text id="se__text__title" x="64" y="200" font-size="100" font-family="Unbounded" font-weight="800">Placeholder</text>
  <rect id="se__box__title--fit" x="64" y="120" width="600" height="140"/>
  <text id="se__text__venue" x="64" y="400" font-size="40" font-family="Inter">Venue</text>
  <rect id="se__box__venue--wrap-3" x="64" y="360" width="400" height="140"/>
  <circle id="se__stroke__primary" cx="540" cy="700" r="250" stroke="#222222"/>
  <g id="se__if__has_photo"><image id="se__image__photo" x="0" y="0" width="10" height="10" href=""/></g>
  <g id="se__ifnot__has_photo"><text id="se__text__cta" x="0" y="0">Come with me</text></g>
  <rect id="se__qr__ref_url" x="712" y="1296" width="192" height="192"/>
  <rect id="se__qr__play_url" x="0" y="0" width="100" height="100"/>
</svg>`;

test('readTokens lists every token a designer declared', () => {
    const tokens = readTokens(TEMPLATE);

    assert.deepEqual(tokens.text.sort(), ['cta', 'title', 'venue']);
    assert.deepEqual(tokens.box.sort(), ['title', 'venue']);
    assert.deepEqual(tokens.fill, ['bg']);
    assert.deepEqual(tokens.stroke, ['primary']);
    assert.deepEqual(tokens.stop, ['primary']);
    assert.deepEqual(tokens.image, ['photo']);
    assert.deepEqual(tokens.qr.sort(), ['play_url', 'ref_url']);
    assert.deepEqual(tokens.if, ['has_photo']);
    assert.deepEqual(tokens.ifnot, ['has_photo']);
});

test('the --N suffix keeps ids unique without changing the role', () => {
    const tokens = readTokens('<svg><rect id="se__fill__primary"/><rect id="se__fill__primary--2"/></svg>');
    assert.deepEqual(tokens.fill, ['primary']);
});

test('templateSize reads the artboard', () => {
    assert.deepEqual(templateSize(TEMPLATE), { width: 1080, height: 1920 });
    assert.deepEqual(templateSize('<svg viewBox="0 0 1200 630"></svg>'), { width: 1200, height: 630 });
});

// --------------------------------------------------------------------------
// Rendering
// --------------------------------------------------------------------------

function render(overrides = {}) {
    return renderTemplate(TEMPLATE, {
        text: { title: 'Chara', venue: 'HOD Lekki Centre' },
        colors: { bg: '#0B0D13', primary: '#1D356A' },
        qr: { ref_url: 'https://hodlc.lpc.cm/e/chara?r=AB12CD' },
        flags: { has_photo: false },
        ...overrides,
    });
}

test('text tokens are replaced', () => {
    const out = render();
    assert.match(out, /Chara/);
    assert.doesNotMatch(out, /Placeholder/);
});

test('colour tokens set fill, stroke and stop-color', () => {
    const out = render();
    assert.match(out, /id="se__fill__bg"[^>]*fill="#0B0D13"/);
    assert.match(out, /id="se__stroke__primary"[^>]*stroke="#1D356A"/);
    assert.match(out, /id="se__stop__primary"[^>]*stop-color="#1D356A"/);
});

test('a colour the data does not mention is left as the designer drew it', () => {
    const out = renderTemplate(TEMPLATE, { text: {}, colors: {}, flags: {} });
    assert.match(out, /id="se__fill__bg"[^>]*fill="#000000"/);
});

test('the fit box is made invisible so it never prints', () => {
    const out = render();
    assert.match(out, /id="se__box__title--fit"[^>]*fill="none"/);
    assert.match(out, /id="se__box__title--fit"[^>]*stroke="none"/);
});

test('a long single-line value is shrunk to fit its box', () => {
    const out = renderTemplate(TEMPLATE, {
        text: { title: 'An Extremely Long Event Name That Will Never Fit' },
        colors: {}, flags: {},
    });

    const size = Number(/id="se__text__title"[^>]*font-size="([\d.]+)"/.exec(out)[1]);
    assert.ok(size < 100, `expected shrinking from 100, got ${size}`);
    assert.ok(size >= 8, 'must not shrink below the 8px floor');
});

test('a short value keeps the designer\'s size', () => {
    const out = render();
    assert.equal(/id="se__text__title"[^>]*font-size="([\d.]+)"/.exec(out)[1], '100');
});

test('a --wrap-N box wraps onto tspans', () => {
    const out = renderTemplate(TEMPLATE, {
        text: { venue: 'The Household of David Lekki Centre Main Auditorium Ground Floor' },
        colors: {}, flags: {},
    });

    assert.match(out, /<tspan/, 'expected wrapped lines');
    assert.ok((out.match(/<tspan/g) || []).length <= 3, 'must respect the 3-line maximum');
});

test('se__if__ groups survive only when the flag is true', () => {
    const withPhoto = render({ flags: { has_photo: true }, images: { photo: 'data:image/jpeg;base64,AAAA' } });
    assert.match(withPhoto, /se__if__has_photo/);
    assert.doesNotMatch(withPhoto, /se__ifnot__has_photo/);

    const without = render({ flags: { has_photo: false } });
    assert.doesNotMatch(without, /se__if__has_photo/);
    assert.match(without, /se__ifnot__has_photo/);
});

test('an image slot takes the data URI, and is removed when there is none', () => {
    const withPhoto = render({ flags: { has_photo: true }, images: { photo: 'data:image/jpeg;base64,AAAA' } });
    assert.match(withPhoto, /href="data:image\/jpeg;base64,AAAA"/);

    // No photo: the <image> goes with its group, so no empty href ships.
    const without = render({ flags: { has_photo: false } });
    assert.doesNotMatch(without, /se__image__photo/);
});

test('the photo is a data URI — nothing is ever fetched', () => {
    const out = render({ flags: { has_photo: true }, images: { photo: 'data:image/jpeg;base64,AAAA' } });
    assert.doesNotMatch(out, /href="https?:/);
});

test('a QR placeholder becomes real modules in the same box', () => {
    const out = render();
    assert.doesNotMatch(out, /se__qr__ref_url/, 'the placeholder rect must be gone');
    assert.match(out, /<path[^>]*d="M7\d\d/, 'expected modules inside x=712…904');
    assert.match(out, /fill="#FFFFFF"/, 'expected the quiet-zone background');
});

test('a QR with no value is removed rather than drawn empty', () => {
    const out = render();
    assert.doesNotMatch(out, /se__qr__play_url/);
});

test('qrGroup places its modules inside the rect it replaces', () => {
    const group = qrGroup('https://example.test/x', { x: 100, y: 200, width: 150, height: 150 });
    const path = group.childNodes.find((n) => n.nodeName === 'path').getAttribute('d');

    for (const [, x, y] of path.matchAll(/M([\d.]+) ([\d.]+)/g)) {
        assert.ok(Number(x) >= 100 && Number(x) <= 250, `x ${x} outside the box`);
        assert.ok(Number(y) >= 200 && Number(y) <= 350, `y ${y} outside the box`);
    }
});

test('embedded font CSS is injected as a <style> inside the SVG', () => {
    const out = render({ fontCss: '@font-face{font-family:"Unbounded";src:url(data:font/woff2;base64,AA)}' });
    assert.match(out, /<style>@font-face/);
});

test('invalid SVG is refused with a message a human can read', () => {
    globalThis.DOMParser = class {
        parseFromString() { return { documentElement: null }; }
    };
    assert.throws(() => renderTemplate('nonsense', {}), /not valid SVG/);
    globalThis.DOMParser = class {
        parseFromString(source) { return parseXml(source); }
    };
});

// --------------------------------------------------------------------------
// The shipped templates must actually carry the tokens the card needs
// --------------------------------------------------------------------------

for (const file of ['im_going.svg', 'im_going_square.svg']) {
    test(`${file} declares the tokens the I'm going card fills`, () => {
        const source = readFileSync(join(repo, 'assets/se/templates', file), 'utf8');
        const tokens = readTokens(source);

        for (const field of ['title', 'edition', 'headline', 'first_name', 'date', 'time', 'venue', 'url', 'signature']) {
            assert.ok(tokens.text.includes(field), `missing se__text__${field}`);
        }
        assert.ok(tokens.qr.includes('ref_url'), 'the card must carry the referral QR');
        assert.ok(tokens.image.includes('photo'), 'the photo slot is missing');
        assert.ok(tokens.if.includes('has_photo'), 'the with-photo group is missing');
        assert.ok(tokens.ifnot.includes('has_photo'), 'the photo-less group is missing');
        assert.ok(tokens.fill.includes('bg'), 'the background fill token is missing');
    });

    test(`${file} has no script, foreignObject or external reference (§14.4)`, () => {
        // Comments are stripped first: the file's own header explains these
        // rules, and the header is not markup.
        const source = readFileSync(join(repo, 'assets/se/templates', file), 'utf8')
            .replace(/<!--[\s\S]*?-->/g, '');
        assert.doesNotMatch(source, /<script/i);
        assert.doesNotMatch(source, /foreignObject/i);
        assert.doesNotMatch(source, /xlink:href\s*=\s*"https?:/i);
        assert.doesNotMatch(source, /\son[a-z]+\s*=/i);
    });

    test(`${file} renders end to end`, () => {
        const source = readFileSync(join(repo, 'assets/se/templates', file), 'utf8');
        const out = renderTemplate(source, {
            text: {
                title: 'Chara', edition: '2026', headline: "I'm going!", first_name: 'Ada',
                date: 'Sat 24 Oct', time: '5:00 PM', venue: 'HOD Lekki Centre',
                url: 'hodlc.lpc.cm/e/chara', organizer: 'Envision', signature: 'Chara 2026 by Envision',
            },
            colors: { bg: '#0B0D13', primary: '#1D356A', secondary: '#D11920', accent: '#F5C518' },
            qr: { ref_url: 'https://hodlc.lpc.cm/e/chara?r=AB12CD' },
            flags: { has_photo: false },
        });

        assert.match(out, /Ada/);
        assert.match(out, /Sat 24 Oct/);
        assert.doesNotMatch(out, /Placeholder/);
        assert.doesNotMatch(out, /se__qr__/, 'every QR placeholder must be resolved');
    });
}

// --------------------------------------------------------------------------
// Check-in posters (§14.2): A4, A3 and the 16:9 screen size must all carry
// the same token set, since se_poster_payload() (cards.php) sends them the
// same data regardless of which one a producer presses.
// --------------------------------------------------------------------------

const POSTER_FILES = {
    'qr_poster_a4.svg': { width: 2480, height: 3508 },
    'qr_poster_a3.svg': { width: 3508, height: 4961 },
    'qr_poster_screen.svg': { width: 1920, height: 1080 },
};

const POSTER_DATA = {
    text: {
        organizer: 'Envision', title: 'Chara', edition: '2026', headline: 'Check in here',
        sub: 'Scan with your phone camera', date: 'Sat 24 Oct 2026', time: 'Doors 4:30 PM',
        venue: 'HOD Lekki Centre', url: 'hodlc.lpc.cm/e/chara/in',
        help: 'No phone? The desk will check you in.', signature: 'Chara 2026 by Envision',
    },
    colors: { bg: '#0B0D13', primary: '#1D356A', secondary: '#D11920', accent: '#F5C518' },
    qr: { checkin_url: 'https://hodlc.lpc.cm/e/chara/in' },
    flags: { has_venue: true },
};

for (const [file, size] of Object.entries(POSTER_FILES)) {
    test(`${file} declares the tokens the check-in poster fills`, () => {
        const source = readFileSync(join(repo, 'assets/se/templates', file), 'utf8');
        const tokens = readTokens(source);

        for (const field of [
            'organizer', 'title', 'edition', 'headline', 'sub', 'date', 'time',
            'venue', 'url', 'help', 'signature',
        ]) {
            assert.ok(tokens.text.includes(field), `missing se__text__${field}`);
        }
        assert.ok(tokens.qr.includes('checkin_url'), 'the check-in QR is missing');
        assert.ok(tokens.if.includes('has_venue'), 'the with-venue group is missing');
        assert.ok(tokens.fill.includes('bg'), 'the background fill token is missing');
    });

    test(`${file} has no script, foreignObject or external reference (§14.4)`, () => {
        const source = readFileSync(join(repo, 'assets/se/templates', file), 'utf8')
            .replace(/<!--[\s\S]*?-->/g, '');
        assert.doesNotMatch(source, /<script/i);
        assert.doesNotMatch(source, /foreignObject/i);
        assert.doesNotMatch(source, /xlink:href\s*=\s*"https?:/i);
        assert.doesNotMatch(source, /\son[a-z]+\s*=/i);
    });

    test(`${file} reports its own pixel size for rasterise()`, () => {
        const source = readFileSync(join(repo, 'assets/se/templates', file), 'utf8');
        assert.deepEqual(templateSize(source), size);
    });

    test(`${file} renders end to end`, () => {
        const source = readFileSync(join(repo, 'assets/se/templates', file), 'utf8');
        const out = renderTemplate(source, POSTER_DATA);

        assert.match(out, /Chara/);
        assert.match(out, /Check in here/);
        assert.doesNotMatch(out, /se__qr__/, 'the QR placeholder must be resolved');
        // The has_venue group stays (flag is true above) with the venue
        // drawn inside it; a false flag is covered by the im_going tests'
        // has_photo case, which exercises the same if/ifnot machinery.
        assert.match(out, /HOD Lekki Centre/);
    });
}
