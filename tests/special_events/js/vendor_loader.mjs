// /tests/special_events/js/vendor_loader.mjs
//
// A Node module-resolution hook that teaches `node --test` the same bare
// specifiers the browser learns from the import map (guide §8.6.1).
//
// In a browser, `import { qrcode } from 'qrcode-generator'` is resolved by
// se_import_map() to /assets/se/vendor/qrcode-generator-1.5.0/qrcode.mjs.
// Node has no import map, and the vendored libraries are deliberately NOT
// npm dependencies (the cPanel host has no Node, §2.3), so a test that
// imports module code with a bare specifier needs this hook.
//
// Keep the table in step with se_import_map() in includes/special_events/
// util.php — only the entries the tests actually need have to be here.

import { pathToFileURL } from 'node:url';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const repo = join(dirname(fileURLToPath(import.meta.url)), '..', '..', '..');

const VENDOR = {
    'qrcode-generator': 'assets/se/vendor/qrcode-generator-1.5.0/qrcode.mjs',
    'preact': 'assets/se/vendor/preact-10.27.2/preact.module.js',
    'preact/hooks': 'assets/se/vendor/preact-10.27.2/hooks.module.js',
    '@preact/signals-core': 'assets/se/vendor/preact-10.27.2/signals-core.module.js',
    '@preact/signals': 'assets/se/vendor/preact-10.27.2/signals.module.js',
    'htm': 'assets/se/vendor/htm-3.1.1/htm.module.js',
    'canvas-confetti': 'assets/se/vendor/canvas-confetti-1.9.3/confetti.module.mjs',
};

export function resolve(specifier, context, nextResolve) {
    if (specifier in VENDOR) {
        return { url: pathToFileURL(join(repo, VENDOR[specifier])).href, shortCircuit: true };
    }
    if (specifier.startsWith('@se/')) {
        return { url: pathToFileURL(join(repo, 'assets/se/js', specifier.slice(4))).href, shortCircuit: true };
    }

    return nextResolve(specifier, context);
}
