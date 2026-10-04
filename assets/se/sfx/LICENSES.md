# Stage sound effects

One sprite, `se-sfx.mp3`, with the offsets in `se-sfx.json` (guide §13.1.6).
Loudness-normalised to −16 LUFS, total ≤ 600 KB.

The sprite is played only by the stage display, through Web Audio, after the
"Click to start" overlay has unlocked the `AudioContext`. No other surface
makes a sound.

## Cues

`tick` · `arm` · `reveal` · `correct` · `wrong` · `buzz` · `strike` · `ding` ·
`team_name` · `fanfare` · `applause` · `drumroll` · `whoosh`

## Sources

| Cue | Source | Licence |
|---|---|---|
| _all_ | **Pending** — to be produced in-house by Envision | — |

Until the sprite is delivered, `@se/core/sfx.js` fails quietly: a missing or
404 sprite disables sound and leaves every other part of the stage working.
Record the real source and licence of every cue in the table above before the
file is committed — a royalty-free download with no recorded licence is not
acceptable.
