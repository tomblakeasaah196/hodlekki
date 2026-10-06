#!/usr/bin/env python3
"""Generate the demo loop for tests/special_events/music_preview.html.

Synthesised from scratch with the standard library, so the preview needs no
audio file in the repository and raises no rights question of its own — which
is rather the point of the feature it is previewing.

    python3 tests/special_events/make_preview_track.py

Writes uploads/se/preview/music/demo-loop.wav (git-ignored), matching the
path shape a real uploaded track gets: /uploads/se/<public_id>/music/<file>.
"""

import math
import os
import struct
import wave

SR = 22050
BPM = 92.0                      # matches the bpm in the preview's boot payload
BEAT = 60.0 / BPM
BEATS = 16
DUR = BEAT * BEATS

OUT = os.path.join(
    os.path.dirname(os.path.abspath(__file__)), "..", "..",
    "uploads", "se", "preview", "music", "demo-loop.wav",
)

# A soft Am9 pad. Deliberately dull: a background bed has to be easy to read
# over, and anything bright enough to enjoy on its own is too loud for this.
CHORD = [220.00, 261.63, 329.63, 392.00, 493.88]   # A3 C4 E4 G4 B4


def main() -> None:
    frames = []

    for i in range(int(SR * DUR)):
        t = i / SR
        beat_pos = (t % BEAT) / BEAT

        # Fast attack, long decay — this swell is what the AnalyserNode sees,
        # and therefore what the ring on the button breathes to.
        pulse = math.exp(-beat_pos * 3.4)
        bar_pos = (t % (BEAT * 4)) / (BEAT * 4)
        accent = 1.0 + 0.45 * math.exp(-bar_pos * 9.0)

        s = 0.0
        for k, f in enumerate(CHORD):
            detune = 1.0 + 0.0016 * math.sin(2 * math.pi * 0.07 * t + k)
            s += (math.sin(2 * math.pi * f * detune * t)
                  + 0.22 * math.sin(2 * math.pi * f * 2 * detune * t)) * (0.55 ** k)

        s += 0.9 * math.sin(2 * math.pi * 55.0 * t) * pulse      # body on the beat

        env = 0.30 + 0.70 * pulse * accent
        edge = min(1.0, t / 0.35, (DUR - t) / 0.35)              # seamless loop

        frames.append(math.tanh(s * env * 0.11 * edge * 1.2))

    peak = max(abs(v) for v in frames) or 1.0
    data = b"".join(
        struct.pack("<h", int(max(-1.0, min(1.0, v / peak * 0.82)) * 32767))
        for v in frames
    )

    os.makedirs(os.path.dirname(OUT), exist_ok=True)
    with wave.open(OUT, "wb") as w:
        w.setnchannels(1)
        w.setsampwidth(2)
        w.setframerate(SR)
        w.writeframes(data)

    print(f"wrote {os.path.relpath(OUT)} — {DUR:.1f}s at {BPM:.0f} BPM, {len(data) / 1024:.0f} KB")


if __name__ == "__main__":
    main()
