// /tests/special_events/js/studio_handlers.test.mjs
//
// The Studio's form components (TextInput, TextArea, Select, Switch in
// studio/ui.js) call their handler with the value, not the browser event.
// A handler written as `(e) => setText(e.currentTarget.value)` therefore
// throws on the first keystroke and the box never fills: that is how the
// karaoke song import and the message editors stopped accepting typing.
// This scan fails on any such handler.

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { dirname, join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';

const repo = join(dirname(fileURLToPath(import.meta.url)), '..', '..', '..');
const VALUE_COMPONENTS = ['TextInput', 'TextArea', 'Select', 'Switch'];

function jsFiles(dir) {
    return readdirSync(dir).flatMap((name) => {
        const path = join(dir, name);
        if (statSync(path).isDirectory()) return jsFiles(path);
        return path.endsWith('.js') ? [path] : [];
    });
}

/** [file:line, component] for every value-component handler that reads an event. */
function eventHandlersOnValueComponents(source, file) {
    const found = [];
    const handler = /on[A-Z]\w*=\$\{\(?(\w+)\)?\s*=>[^}]*?\b\1\.(?:currentTarget|target)\b/g;
    let match;
    while ((match = handler.exec(source)) !== null) {
        const before = source.slice(0, match.index);
        const tags = [...before.matchAll(/<(\$\{(\w+)\}|[a-z][\w-]*)/g)];
        const tag = tags.length ? tags[tags.length - 1] : null;
        if (tag && VALUE_COMPONENTS.includes(tag[2])) {
            const line = before.split('\n').length;
            found.push(`${file}:${line} <${tag[2]}>`);
        }
    }
    return found;
}

test('the scan spots an event handler on a value component', () => {
    const bad = 'html`<${TextArea} name="x" onInput=${(e) => setText(e.currentTarget.value)} />`';
    const good = 'html`<${TextArea} name="x" onInput=${setText} /> <input onInput=${(e) => setQ(e.currentTarget.value)} />`';
    assert.deepEqual(eventHandlersOnValueComponents(bad, 'probe.js'), ['probe.js:1 <TextArea>']);
    assert.deepEqual(eventHandlersOnValueComponents(good, 'probe.js'), []);
});

test('no Studio handler expects an event from a value component', () => {
    const root = join(repo, 'assets/se/js/studio');
    const problems = jsFiles(root).flatMap((path) =>
        eventHandlersOnValueComponents(readFileSync(path, 'utf8'), relative(repo, path)));
    assert.deepEqual(problems, []);
});
