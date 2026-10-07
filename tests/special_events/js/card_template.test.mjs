// /tests/special_events/js/card_template.test.mjs
//
// The two "I'm going" templates (§14.3, §14.4, build prompt task 5).
//
// svg_tokens.test.mjs proves the ENGINE resolves tokens; this file proves the
// ARTWORK keeps the promises the builder now leans on. The builder reads
// se__slot__photo to place the in-ring cropper, so a template whose slot and
// photo clip disagree would put a draggable circle somewhere the guest's face
// is not — a bug no amount of engine testing would catch.
//
// No DOM is needed: the checks are structural, so this parses the SVG with a
// small tag scanner rather than pulling in the engine's harness.

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const repo = join(dirname(fileURLToPath(import.meta.url)), '..', '..', '..');
const read = (file) => readFileSync(join(repo, 'assets/se/templates', file), 'utf8');

/** The two templates the "I'm going" card is rendered from. */
const TEMPLATES = {
    'im_going.svg': { width: 1080, height: 1920, margin: 64 },
    'im_going_square.svg': { width: 1080, height: 1080, margin: 48 },
};

/** Every text field the card payload fills, on both formats. */
const TEXT_FIELDS = [
    'title', 'edition', 'headline', 'first_name',
    'date', 'time', 'venue', 'url', 'organizer', 'signature',
    'verse_text', 'verse_ref',
];

const num = (value) => parseFloat(value || '0');

/**
 * A tag scanner, not a parser: enough structure to ask "which element has this
 * id, and what are its attributes". It also reports unbalanced markup, which
 * is the one thing that would make an SVG unusable in a browser.
 */
function scan(source) {
    const clean = source.replace(/<\?[\s\S]*?\?>/g, '').replace(/<!--[\s\S]*?-->/g, '');
    const nodes = [];
    const stack = [];
    const errors = [];
    const tag = /<(\/?)([a-zA-Z][\w:.-]*)((?:"[^"]*"|[^>"])*?)(\/?)>/g;

    let match;
    while ((match = tag.exec(clean)) !== null) {
        const [, closing, name, attrText, selfClosing] = match;

        if (closing) {
            const open = stack.pop();
            if (!open) errors.push(`</${name}> closes nothing`);
            else if (open.name !== name) errors.push(`</${name}> closes <${open.name}>`);
            continue;
        }

        const attrs = {};
        for (const [, key, value] of attrText.matchAll(/([a-zA-Z_:][\w:.-]*)\s*=\s*"([^"]*)"/g)) {
            attrs[key] = value;
        }

        const node = { name, attrs, children: [], parent: stack[stack.length - 1] || null };
        if (node.parent) node.parent.children.push(node);
        nodes.push(node);
        if (!selfClosing) stack.push(node);
    }

    for (const open of stack) errors.push(`<${open.name}> is never closed`);

    return { nodes, errors };
}

const byId = (nodes, id) => nodes.find((node) => node.attrs.id === id) || null;
const countId = (nodes, id) => nodes.filter((node) => node.attrs.id === id).length;

for (const [file, spec] of Object.entries(TEMPLATES)) {
    const source = read(file);
    const { nodes, errors } = scan(source);

    test(`${file} is well-formed SVG at ${spec.width} x ${spec.height}`, () => {
        assert.deepEqual(errors, [], 'the markup must be balanced');
        assert.ok(nodes.length > 0, 'the file must contain elements');

        const root = nodes[0];
        assert.equal(root.name, 'svg');
        assert.equal(num(root.attrs.width), spec.width);
        assert.equal(num(root.attrs.height), spec.height);
        assert.equal(root.attrs.viewBox, `0 0 ${spec.width} ${spec.height}`);
        assert.equal(root.attrs.xmlns, 'http://www.w3.org/2000/svg');
    });

    test(`${file} declares exactly one has_photo pair and one photo slot`, () => {
        assert.equal(countId(nodes, 'se__if__has_photo'), 1, 'exactly one with-photo group');
        assert.equal(countId(nodes, 'se__ifnot__has_photo'), 1, 'exactly one photo-less group');
        assert.equal(countId(nodes, 'se__slot__photo'), 1, 'exactly one slot the builder can read');

        // Exactly one of the two layouts may survive when the flag resolves,
        // so the photo the guest fits has to live inside the with-photo group.
        const slot = byId(nodes, 'se__slot__photo');
        assert.equal(slot.name, 'rect', 'the slot is a rect the builder measures');
    });

    test(`${file} puts its photo inside the medallion the slot describes`, () => {
        const slot = byId(nodes, 'se__slot__photo');
        const clip = nodes.find((node) => node.name === 'clipPath' && node.attrs.id === 'se-photo-clip');
        const circle = clip?.children.find((node) => node.name === 'circle');

        assert.ok(circle, 'se-photo-clip must clip to a circle');

        const centreX = num(slot.attrs.x) + num(slot.attrs.width) / 2;
        const centreY = num(slot.attrs.y) + num(slot.attrs.height) / 2;
        const radius = num(slot.attrs.width) / 2;

        assert.equal(num(slot.attrs.width), num(slot.attrs.height), 'the slot is a square');
        assert.equal(num(circle.attrs.cx), centreX, 'the clip is centred on the slot');
        assert.equal(num(circle.attrs.cy), centreY, 'the clip is centred on the slot');
        assert.equal(num(circle.attrs.r), radius, 'the clip fills the slot exactly');
    });

    test(`${file} carries the crest and the verse`, () => {
        assert.equal(countId(nodes, 'se__image__logo'), 1, 'the masthead crest');
        assert.equal(countId(nodes, 'se__image__logo--2'), 1, 'the 5% corner ghost');

        const ghost = byId(nodes, 'se__image__logo--2');
        assert.equal(num(ghost.attrs.opacity), 0.05, 'the ghost stays almost subliminal');

        // The engine renders whichever text arrives, so the artwork has to
        // carry a readable fallback for both of the verse's tokens.
        assert.match(source, /se__text__verse_text/);
        assert.match(source, /Thou wilt shew me the path of life/);
        assert.match(source, /PSALM 16:11/);
    });

    test(`${file} declares the fields the card payload fills`, () => {
        for (const field of TEXT_FIELDS) {
            assert.ok(byId(nodes, `se__text__${field}`), `missing se__text__${field}`);
        }
    });

    test(`${file} draws its display lines in the card's own face`, () => {
        // The payload embeds Fraunces (`fonts.card_display`), so a template
        // that stopped asking for it would silently fall back to the event's
        // face — which is exactly the bug this build fixes.
        for (const field of ['title', 'headline', 'verse_text']) {
            const node = byId(nodes, `se__text__${field}`);
            assert.match(node.attrs['font-family'] || '', /Fraunces/,
                `se__text__${field} must name Fraunces first`);
        }

        assert.match(byId(nodes, 'se__text__headline').attrs['font-style'] || '', /italic/,
            '"I&#8217;m going!" is Fraunces italic in the approved design');
        assert.match(byId(nodes, 'se__text__verse_text').attrs['font-style'] || '', /italic/,
            'the verse is Fraunces italic in the approved design');
    });

    test(`${file} keeps every text box inside the ${spec.margin} px safe margin`, () => {
        const boxes = nodes.filter((node) => /^se__box__/.test(node.attrs.id || ''));

        assert.ok(boxes.length >= TEXT_FIELDS.length - 2, 'every fitted line needs a box');
        for (const box of boxes) {
            const x = num(box.attrs.x);
            const y = num(box.attrs.y);
            const right = x + num(box.attrs.width);
            const bottom = y + num(box.attrs.height);

            assert.ok(num(box.attrs.width) > 0 && num(box.attrs.height) > 0, `${box.attrs.id} has no area`);
            assert.ok(x >= spec.margin, `${box.attrs.id} starts at x=${x}, inside the margin`);
            assert.ok(y >= 0, `${box.attrs.id} starts above the artboard`);
            assert.ok(right <= spec.width - spec.margin, `${box.attrs.id} runs to x=${right}, past the margin`);
            assert.ok(bottom <= spec.height - spec.margin, `${box.attrs.id} runs to y=${bottom}, past the margin`);
        }
    });

    test(`${file} has no script, foreignObject or external reference (§14.4)`, () => {
        const markup = source.replace(/<!--[\s\S]*?-->/g, '');
        assert.doesNotMatch(markup, /<script/i);
        assert.doesNotMatch(markup, /foreignObject/i);
        assert.doesNotMatch(markup, /xlink:href\s*=\s*"https?:/i);
        assert.doesNotMatch(markup, /\son[a-z]+\s*=/i);
    });
}

test('both formats fill the same tokens, so the builder needs no per-size map', () => {
    const fieldsOf = (file) => scan(read(file)).nodes
        .map((node) => node.attrs.id)
        .filter((id) => id && /^se__text__/.test(id))
        .sort();

    const story = fieldsOf('im_going.svg');
    const square = fieldsOf('im_going_square.svg');

    assert.deepEqual(story, square, 'the square is a recomposition, not a different card');
});
