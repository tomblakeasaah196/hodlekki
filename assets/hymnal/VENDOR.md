# Vendored libraries — Hymnary module

Everything this module loads at runtime is served from this repo. Nothing is
fetched from a CDN, so the module works on a phone with no data once the app
has been installed, and no third party sees who is reading what.

## abcjs 6.7.2 — MIT

Music notation rendering and synthesised playback.
Upstream: https://github.com/paulrosen/abcjs (npm `abcjs@6.7.2`).
Licence text: `vendor/abcjs-6.7.2/LICENSE.md`.

| File | SHA-256 |
| ---- | ------- |
| `vendor/abcjs-6.7.2/LICENSE.md` | `d7bdcd152b978adee22a2f9b92a4d72c4ec2c20a86512766c7c210b462b54702` |
| `vendor/abcjs-6.7.2/abcjs-audio.css` | `975131e6699c949fb68c49a3ee6327018c2e52279739f4f4bbccd888936aff30` |
| `vendor/abcjs-6.7.2/abcjs-basic-min.js` | `3290ef44c918d5e882029d3a47ad3dd63c16c8819da5abe921f3d1e642b4f4f0` |
| `vendor/abcjs-6.7.2/abcjs-basic-min.js.LICENSE` | `57706d6d731055a627cde38fa5202cd1b274a8b1d57f98eb85b7dd5a1c09d247` |

## FluidR3_GM soundfont — CC-BY 3.0

Per-note mp3 samples, the layout abcjs expects
(`<soundfont>/<instrument>-mp3/<Note>.mp3`). Two instruments, MIDI 33 (A1) to
84 (C6) chromatic — the full SATB span with a little headroom either side.
Upstream: https://github.com/paulrosen/midi-js-soundfonts (FluidR3_GM), a fork
of gleitz/midi-js-soundfonts. FluidR3 is by Frank Wen, CC-BY 3.0.

| Instrument | Notes | Bytes |
| ---------- | ----- | ----- |
| `acoustic_grand_piano-mp3` | 52 | 1291212 |
| `choir_aahs-mp3` | 52 | 1330420 |

The samples are byte-identical to upstream; `bin/fetch_hymnal_soundfont.sh`
re-downloads them. abcjs only applies its 3.0 volume multiplier to its own
default URL, so the reader passes `soundFontVolumeMultiplier: 3.0` explicitly —
without it these same samples play back almost inaudibly.
