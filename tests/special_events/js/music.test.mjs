// /tests/special_events/js/music.test.mjs
//
// Portal music (guide §13.3 S0b). The engine is almost entirely DOM and Web
// Audio, so — like checkin_suggest.test.mjs — these are source-level guards
// on the promises a regression would silently break:
//
//   1. no sound before a user gesture (a browser rule we must not pretend to
//      beat, and the thing a "fix" for "it doesn't autoplay" would break),
//   2. the control is always reachable and always honest (WCAG 2.1 SC 1.4.2),
//   3. the guest's choice survives a reload,
//   4. the metered-connection rule the hero video already follows,
//   5. no silent-mode detection creeps back in — there is no such API, and
//      the two usual fakes (reading audio.volume, inferring from an
//      AnalyserNode) both report nonsense on a muted phone.
//
//   node --test "tests/special_events/js/*.test.mjs"

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const repo = join(here, '..', '..', '..');

const music = readFileSync(join(repo, 'assets/se/js/portal/music.js'), 'utf8');
const portalJs = readFileSync(join(repo, 'assets/se/js/portal/main.js'), 'utf8');
const portalPhp = readFileSync(join(repo, 'includes/special_events/portal.php'), 'utf8');
const musicPhp = readFileSync(join(repo, 'includes/special_events/music.php'), 'utf8');
const studioTab = readFileSync(join(repo, 'assets/se/js/studio/tabs/music.js'), 'utf8');
const css = readFileSync(join(repo, 'assets/se/css/se.css'), 'utf8');

test('nothing plays before a gesture', () => {
    // Every play() call must sit inside a handler. The one stretch of code
    // that runs on its own is the arming block at the end of startMusic —
    // from the moment the dock is revealed to the moment the listeners go
    // on — so that stretch is the one checked, rather than the whole file.
    const reveal = music.indexOf('dock.hidden = false');
    const listen = music.indexOf('window.addEventListener(type, arm');
    assert.ok(reveal > 0 && listen > reveal, 'the arming block must still look the way this test assumes');

    const armBlock = music.slice(reveal, listen);
    const unguarded = armBlock
        .split('\n')
        // `const arm = async () => { ... }` only DEFINES the handler.
        .filter((line) => /\bplay\(\)/.test(line) && !/const arm|await play\(\)/.test(line));

    assert.deepEqual(unguarded, [],
        'play() must never run at arm time — a browser would refuse it, and a visitor '
        + 'who muted it last visit would be overruled');

    for (const type of ['pointerdown', 'touchstart', 'keydown', 'scroll']) {
        assert.ok(music.includes(`'${type}'`), `${type} must be one of the arming gestures`);
    }

    assert.ok(/addEventListener\(type, arm, \{ passive: true \}\)/.test(music),
        'the gesture listeners must be passive so they cannot block scrolling');

    assert.ok(!music.includes('{ once: true }') || !/arm, \{ once: true \}/.test(music),
        'the arming listeners must survive a refused attempt so the next real tap still works');

    assert.ok(/autoplay/i.test(music), 'the autoplay policy must be explained where the next reader will see it');
});

test('silent-mode detection is not attempted anywhere', () => {
    // Reading these would be the two classic fakes. Neither can work: on iOS
    // audio.volume is read-only and always 1, and an analyser measures our
    // own buffer rather than the speaker.
    assert.ok(!/\baudio\.volume\s*[=!<>]==?/.test(music.replace(/audio\.volume = to;/, '')),
        'audio.volume must never be READ as a signal — it is not the device volume');
    assert.ok(!/isSilent|silentMode|ringerMode|detectMute/i.test(music),
        'there is no silent-mode API; a helper named like one is a bug waiting to ship');
    assert.ok(/CANNOT detect whether a phone is on silent/i.test(music),
        'the limitation must be documented in the file that would be tempted to fake it');
});

test('the control is always there and always honest', () => {
    assert.ok(portalPhp.includes('data-se-music="toggle"'), 'the mute key must exist in server-rendered markup');
    assert.ok(portalPhp.includes('data-se-music="volume"'), 'the volume key must exist in server-rendered markup');

    // Two icons, no words: the dock must carry no visible text nodes.
    const dock = portalPhp.slice(portalPhp.indexOf('<div class="se-music"'), portalPhp.indexOf('</div>\n    <?php'));
    assert.ok(!/>\s*[A-Za-z]{3,}\s*</.test(dock.replace(/<svg[\s\S]*?<\/svg>/g, '')),
        'the dock is two glyphs — no labels, captions or track names on screen');

    // ...which makes the accessible names non-negotiable.
    assert.ok(portalPhp.includes('aria-label="Play the background music"'),
        'the mute key needs an accessible name because it has no visible one');
    assert.ok(portalPhp.includes('aria-label="Music volume"'), 'the slider needs an accessible name');
    assert.ok(portalPhp.includes('aria-pressed'), 'the mute key must report its state');
    assert.ok(music.includes("setAttribute('aria-label', label())"),
        'the accessible name must follow the state, not be set once and left lying');

    assert.ok(music.includes('dock.hidden = false') && music.includes("dataset.ready = '1'"),
        'the dock is revealed as soon as the engine is live, not only once sound starts');
});

test('the guest keeps their choice', () => {
    assert.ok(music.includes('localStorage'), 'the preference must persist across pages and visits');
    assert.ok(/savePref\(publicId, \{ muted: true \}\)/.test(music), 'muting must be remembered');
    assert.ok(/savePref\(publicId, \{ volume \}\)/.test(music), 'the volume must be remembered');
    assert.ok(music.includes('if (!wanted) return;'),
        'someone who muted it last time must not be re-started on their next visit');
    assert.ok(/Math\.min\(100, Math\.max\(0, pref\.volume\)\)/.test(music),
        'localStorage is user-writable, so the stored volume must be clamped');
});

test('it respects data, attention and other audio', () => {
    assert.ok(music.includes('saveData') && music.includes("'2g'"),
        'music must follow the same metered-connection rule as the hero video');
    assert.ok(music.includes("preload = 'none'"), 'nothing may be downloaded before they ask for it');
    assert.ok(music.includes('visibilitychange'), 'a hidden tab must go quiet');
    assert.ok(/ramp\(target\(\) \* 0\.15/.test(music), 'other media must duck the bed rather than fight it');
    assert.ok(portalJs.includes("import('./music.js')"),
        'the engine must be lazy — most visits have no playlist at all');
});

test('the pulse follows the music and yields to a motion preference', () => {
    assert.ok(music.includes('getByteTimeDomainData'), 'the ring must follow the real waveform');
    assert.ok(music.includes('--se-music-level'), 'the level must reach CSS as a variable, not as inline styles');
    assert.ok(music.includes('prefersReducedMotion'), 'reduced motion must stop the ring');
    assert.ok(/reduced \|\| !graph \|\| raf/.test(music), 'the animation frame must not even start under reduced motion');
    assert.ok(music.includes('bpmFallback'), 'a browser with no analyser still gets a tempo-timed pulse');

    assert.ok(css.includes('--se-music-level'), 'the compiled stylesheet must carry the pulse variable');
    assert.ok(css.includes('.se-music-ring'), 'the compiled stylesheet must carry the ring');
});

test('the rights tick cannot be skipped from the Studio', () => {
    assert.ok(/disabled=\$\{!rights \|\| !file\}/.test(studioTab),
        'the upload button stays disabled until the box is ticked');
    assert.ok(studioTab.includes('rights_confirmed: true'), 'the tick must travel with the upload');
    assert.ok(musicPhp.includes("'rights_confirmed' => 'Tick the box"),
        'the server must refuse an unattested track regardless of what the UI did');
    assert.ok(/rights_confirmed_by|rights_confirmed_at/.test(musicPhp),
        'who ticked it and when must be recorded, not just that it was ticked');
});

test('the preview harness has not drifted from the real dock', () => {
    // music_preview.html exists so the dock can be checked without a
    // database. A copy that has quietly fallen behind is worse than none,
    // so the two are compared rather than trusted to a comment.
    const preview = readFileSync(join(repo, 'tests/special_events/music_preview.html'), 'utf8');

    const cut = (s, start, end) => s.slice(s.indexOf(start), s.indexOf(end));
    const squash = (s) => s.replace(/\s+/g, ' ').trim();

    const real = squash(cut(portalPhp, '<div class="se-music"', '</div>\n    <?php'))
        // The one real difference: PHP echoes the producer's volume where
        // the harness hard-codes the default.
        .replace(/value="<\?= \(int\) \$music\['volume'\] \?>"/, 'value="30"');
    const copy = squash(cut(preview, '<div class="se-music"', '</div>\n\n<script type="module">'));

    assert.equal(copy, real,
        'tests/special_events/music_preview.html must match se_portal_music_dock() exactly');
});

test('the Studio tells producers the truth about autoplay and silent mode', () => {
    assert.ok(/blocks? audible autoplay/i.test(studioTab),
        'producers must be told why the music does not start on its own');
    assert.ok(/cannot tell whether a phone is on silent/i.test(studioTab),
        'producers must be told we cannot detect silent mode, before they ask');
});
