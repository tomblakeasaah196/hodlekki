#!/usr/bin/env bash
# /bin/fetch_hymnal_soundfont.sh
#
# Re-downloads the vendored soundfont for the Hymnary module's choir mode.
#
# abcjs loads one mp3 per note, as <soundfont>/<instrument>-mp3/<Note>.mp3, and
# by default it fetches them from paulrosen.github.io. We serve our own copies
# instead: the CSP allows only 'self', the module has to work offline once the
# app is installed, and nobody outside should see which hymn a chorister is
# rehearsing.
#
# Only needed when adding an instrument or repairing a corrupt sample. The
# files are committed, so a deploy never runs this.
#
# Usage: bash bin/fetch_hymnal_soundfont.sh [instrument ...]

set -euo pipefail

cd "$(git rev-parse --show-toplevel)"

BASE="https://raw.githubusercontent.com/paulrosen/midi-js-soundfonts/master/FluidR3_GM"
OUT="assets/hymnal/soundfont"
if [ "$#" -gt 0 ]; then
    INSTRUMENTS=("$@")
else
    INSTRUMENTS=(acoustic_grand_piano choir_aahs)
fi

# MIDI 33 (A1) to 84 (C6): the SATB span plus headroom. abcjs names notes with
# flats, never sharps (src/synth/pitch-to-note-name.js).
NAMES=(C Db D Eb E F Gb G Ab A Bb B)
notes=()
for midi in $(seq 33 84); do
    notes+=("${NAMES[$(((midi - 12) % 12))]}$((midi / 12 - 1))")
done

for inst in "${INSTRUMENTS[@]}"; do
    mkdir -p "$OUT/$inst-mp3"
    got=0
    for note in "${notes[@]}"; do
        dest="$OUT/$inst-mp3/$note.mp3"
        [ -s "$dest" ] && continue
        curl -fsS --max-time 60 -o "$dest" "$BASE/$inst-mp3/$note.mp3" || {
            echo "FAILED $inst $note" >&2
            rm -f "$dest"
            exit 1
        }
        got=$((got + 1))
    done
    echo "$inst: ${#notes[@]} notes present ($got downloaded)"
done

echo "Update the table in assets/hymnal/VENDOR.md if an instrument was added."
