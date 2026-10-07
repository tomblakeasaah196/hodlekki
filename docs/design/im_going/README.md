# The "I'm going" card, v2 — Envision design proposal

**Status:** design agreed in principle; three decisions left open (see *Open
questions* at the foot of this file).
**Scope:** the *I'm going* share card on the public portal — its artwork
(the output) and its builder (the UI/UX). The welcome, team and My Night
cards keep their current layouts until this one is signed off, then inherit
the same shell.

Renders of everything described here: `story-1080x1920.png`,
`square-1080x1080.png`, `card-concept.jpg`, `builder-flow.jpg`.

**Tap-through prototype: `prototype.html`** — open it on a phone (or a narrow
desktop window) and try the flow: tap the ring to add a photo, drag and pinch
inside it, press *Save / share*, choose a shape. What is real in it: the
flow, the fit, and the artwork. What is a stand-in: choosing a shape
downloads the *concept render* beside it rather than a live render of your
photo — the real builder rasterises the token SVG with the photo composited.
Nothing is uploaded, here or there.

Sample material in the renders: the hero is this repo's
`assets/images/celebration-charis.webp`, the crest is
`assets/images/hod_logo.svg` (baked to `crest-512.png`), and the guest
portrait is a **placeholder**, not a real attendee. Everything else —
name, date, venue — is the Chara 2026 test data already used across the
module's fixtures.

---

## 1. What exists today

The builder is **`assets/se/js/portal/card.js`** (137 lines) and the cropper
is **`assets/se/js/portal/photo.js`** (222 lines). Both are correct and
careful — the photo never leaves the device, the template engine is the
§14.4 token engine, and everything is lazy-imported so the portal does not
pay for it. The problem is the *shape* of the flow, not its code.

Opened from three places (`sheet.js:653` after registering, `manage.js:105`,
`checkin.js:366`), the sheet currently shows, at once:

| # | Control | Where it comes from |
|---|---|---|
| 1, 2 | **Story** / **Square** radio buttons | `card.js` shape toggle, *above* the preview |
| 3 | **📷 Add your photo (optional)** | `photo.js` file label |
| 4 | **Reset** | `photo.js`, after a photo is added |
| 5 | **Remove photo** | `photo.js`, after a photo is added |
| 6 | **Zoom** slider | `photo.js`, after a photo is added |
| 7 | **Save / share** | `card.js` |
| 8 | **Close** | `card.js` |

So the guest meets five controls before doing anything, the shape choice is
asked *before* they have seen why it matters, the photo is fitted in a
*separate* circular canvas that duplicates the card's own circle, and the
result is never shown until it is saved. That is the whole of the complaint,
and it is fixable without losing a single capability.

The artwork (`assets/se/templates/im_going.svg` + `im_going_square.svg`) is
a flat three-stop gradient with a coloured glow. It is competent and it is
generic: nothing in it says *Chara*, and nothing in it could only be ours.

---

## 2. The idea — "Spotlight"

> The event's own hero photograph, turned to pure atmosphere behind a deep
> veil. A circle of light in the middle of the night. The guest's face is the
> only subject, and the composition is built so that nothing — not the
> photograph, not the type, not the crest — ever competes with it.

Three moves carry it:

1. **The hero becomes light, not a picture.** The frame is the event's
   uploaded hero (Studio → Assets → `hero`), drawn so soft that it reads as
   colour and movement; the palette's primary and secondary glows sit on top
   of it; a vignette darkens the centre so the face in the ring is the
   brightest thing in the composition. Recognisably "that night"; never a
   second subject.
2. **The ring is architecture, not decoration.** A thick ring in the event's
   raw primary, a hairline of accent inside it, a warm halo outside it —
   the same three-part ring on both formats, and on the team card, so the
   family of cards is recognisable at a glance in a crowded group chat.
3. **One verse, quoted, at the foot.** KJV, verbatim (D26), never typed by
   AI, with the crest of the house near it. The last thing the eye reaches is
   scripture, not a marketing line.

---

## 3. The card, specified

### 3.1 Story — 1080 × 1920

| Element | Spec |
|---|---|
| Veil | Full bleed. `#0B0D13` at 86 % → 56 % (y ≈ 20 %) → 66 % (y ≈ 50 %) → 100 % at the foot |
| Hero atmosphere | Full bleed, `slice`, **drawn from a ~96 px thumbnail** (§3.3), then flat `bg` at 26 % and flat `primary` at 30 % over it |
| Light | Primary bloom r 640 at the circle's centre; accent→secondary halo r 450; blurred bokeh lights in the upper third (8 circles, `stdDeviation 7`, 50 %) |
| Vignette | Ellipse 780 × 720 at (540, 880) so the centre is quiet |
| Crest ghost | 520 × 520 at (770, 1660), **opacity 0.05** — bleeds off the corner |
| Masthead crest | 64 × 64 at (508, 116), full opacity |
| `ENVISION PRESENTS` | Inter 600, 23 px, letter-spacing 11, white 66 %, centred, y 232 |
| Event wordmark | Unbounded 800, 138 px, `se__text__title`, y 400 |
| Edition | Unbounded 500, 46 px, letter-spacing 16, `se__text__edition`, y 474 |
| Guest circle | Outer ring r 278, stroke 16 px `primary`; hairline r 264, 3 px `accent` at 50 %; photo clipped to r 256 |
| Headline | Unbounded 800, 102 px, `se__text__headline`, y 1222 |
| First name | Inter 700, 54 px, letter-spacing 13, `accent`, `se__text__first_name`, y 1310 |
| Details plate | x 84, y 1364, 912 × 240, r 44, `bg` at 58 % + 1 px white 12 % border |
| Date · time | Inter 700, 46 px, y 1452 — `se__text__date`, `se__text__time` in one line |
| Venue | Inter 500, 34 px, white 66 %, y 1512 |
| Doors line | Inter 500, 26 px, white 45 %, y 1566 — **new field**, see §5 |
| QR | 120 × 120 at (836, 1396), white fill, label `JOIN ME` 21 px at y 1548 |
| Verse rule | 380 × 2 at (350, 1678), accent gradient fading at both ends |
| Verse | Inter 400, 31 px, white 84 %, two lines, y 1742 / 1786, fit box 900 × 80, `--wrap-2` |
| Verse reference | Inter 600, 23 px, letter-spacing 7, `accent` 90 %, y 1836 |
| Short URL | Inter 600, 28 px, white 55 %, y 1898 |

### 3.2 Square — 1080 × 1080

Not a crop of the story: recomposed around the circle, so no name is ever
cut and the QR gets its own corner.

| Element | Spec |
|---|---|
| Header | Crest 56 px at (64, 56); kicker 21 px, letter-spacing 8, at (136, 92) |
| QR | 112 × 112 at (904, 48) with `SCAN TO JOIN ME` 18 px below it |
| Wordmark | Unbounded 800, 88 px, y 290; edition Unbounded 500, 28 px, letter-spacing 12, y 336 |
| Circle | Ring r 166 stroke 12; hairline r 158 stroke 2.5; photo r 150 |
| Headline | Unbounded 800, 60 px, y 790; name Inter 700, 31 px, letter-spacing 11, y 842 |
| Verse | Rule 300 × 2 at (390, 874); verse Inter 400, 25 px, two lines, y 926 / 958; reference 19 px, letter-spacing 6, y 1000 |
| Where and when | One line, Inter 500, 24 px, white 60 %, y 1048: date · time · venue · short URL |
| Crest ghost | 420 × 420 at (810, 840), opacity 0.05 |

### 3.3 The hero as atmosphere — and why it is cheap

Safari will not fetch anything from inside an SVG that is drawn to a canvas,
so the hero has to travel as a data URI (the technique `program_poster.js`
already uses at §14.2b step 3). Two problems follow: a full-size hero is a
300–600 KB data URI, and a real Gaussian blur over 1080 × 1920 costs real
milliseconds on a low-end phone.

Both disappear with one trick: **the atmosphere is a ~96 px thumbnail.**
Fetch the smallest hero variant (`480` from `se_asset_srcset`'s variant
list, already on disk), draw it to a 96 px-wide canvas, `toDataURL('image/jpeg', 0.72)`
— a **2–4 KB** data URI — then place it `slice` in the full-bleed box. The
browser's own smooth upscaling gives the soft, dreamy falloff a 20 px blur
would, with no SVG filter, nothing for Safari to choke on, and a payload
small enough to inline without thinking. A very light `feGaussianBlur 4`
may be layered on top if the first build looks too crisp; measure it on the
device before deciding.

No hero uploaded yet? The gradients alone, as today. Never a broken frame.

### 3.4 The verse

Hardcoded in the template as **live text** (per D26: KJV, verbatim, never
AI-written). Default in the artwork:

> *"And the streets of the city shall be full of boys and girls playing in
> the streets thereof."* — **ZECHARIAH 8:5**

Wired as tokens (`se__text__verse_text` / `se__text__verse_ref`) with the
artwork's own text as the fallback, so the guided alternative — the event's
approved welcome verses (§10.9, Studio → Check-in) — can take over later
without touching the artwork again. See question 2.

### 3.5 The crest, and the house mark

Two placements, one of them almost subliminal:

- **Masthead** (64 px) immediately above `ENVISION PRESENTS` — the church
  signs the invitation, the ministry presents it.
- **A ghost** at 5 % opacity, 520 px, bleeding off a bottom corner. At a
  glance it is a light artefact in the photograph; when it is pointed out,
  it is unmistakably the crest. It survives re-posting and re-compression,
  it never distracts, and it cannot be cropped out without destroying the
  composition.

The church crest lives at `/assets/images/hod_logo.svg`, which is a
5053-byte base64 PNG inside SVG (white line art on transparency — correct
for dark artwork, but as a raster inside an SVG inside a canvas it is a
portability risk). **Build step:** bake the crest once to a small WebP/PNG
data URI (`docs/design/im_going/crest-512.png`) and inline that, falling
back to the event's own Studio `logo` asset when one is uploaded. See
question 3.

### 3.6 Type, rings and grain

- Display face: the event's `font_display` (Unbounded by default); body:
  `font_body` (Inter). Both already embedded by `embedFontCss()`.
- **Centring trap:** `letter-spacing` on `text-anchor="middle"` adds the
  spacing *after* the last glyph, biasing the visual centre left by half the
  tracking. Every centred tracked line in the artwork is compensated (`dx`
  of half the tracking) so it is truly centred in Chrome, Safari and the
  renderer we proof with.
- Grain: a small tiled noise PNG at 5 %, `mix-blend-mode: overlay` — never
  `feTurbulence` in the shipped template (slow, and inconsistent across
  engines). Optional; drop it if the first device test disagrees.

---

## 4. The builder, specified — three buttons

One sheet, one canvas, one row of actions. **The preview is the interface.**

### 4.1 Screen 1 — idle

```
              Your "I'm going" card
   ┌───────────────────────────────┐
   │            CHARA              │
   │            2026               │
   │         ╭─────────╮           │    ← the circle is the button
   │         │    ＋   │           │      (same action as "Add photo")
   │         │ Add photo│          │
   │         ╰─────────╯           │
   │         I'm going!            │
   │             ADA               │
   │      Sat 24 Oct · 5:00 PM     │
   │  "…verse…"  ZECHARIAH 8:5     │
   └───────────────────────────────┘
     [  Add photo  ] [ Save / share ]
```

- **[Add photo]** — `<input type="file" accept="image/*">` (still no
  `capture`, so camera *and* gallery), EXIF-corrected, downscaled to
  1600 px, exactly as today.
- **[Save / share]** — renders at 1080 and opens the format ask (§4.3).

### 4.2 Screen 2 — fitting the photo

The photo lands **inside the card's own ring**, live. Drag to move, pinch to
zoom, clamped so the circle can never show an empty edge; the card above
updates as it moves (throttled to a frame). The separate cropper screen, the
`Reset` button and the always-on zoom slider are gone.

- **[Remove photo]** — replaces `Add photo` in place, once there is one.
- **[Save / share]** — unchanged.
- The circle itself is a `<button>`: tap it again to swap the photo.
- Desktop/mouse: a **zoom slider appears only for `pointer: fine`** (no
  pinch available), and the circle is draggable with the mouse. Keyboard
  users reach the slider by tab; every control has a focus ring.

That is **three buttons in the whole builder** — Add, Remove, Save — with
never more than two on screen at once.

### 4.3 Screen 3 — the format ask (after Save)

A second, lighter sheet: two big cards, chosen after the guest has decided
to keep the card — never before.

```
              Save or share as…
   ┌────────────────────────────────────┐
   │ ▯  Story                           │  1080 × 1920
   │    WhatsApp Status, IG/FB Story, TikTok
   ├────────────────────────────────────┤
   │ ▭  Square                          │  1080 × 1080
   │    WhatsApp DP, group chat, IG feed
   └────────────────────────────────────┘
     Your phone's share sheet saves it — or Download instead
```

- Choice → `rasterise()` → `navigator.share({files})` if available, else a
  direct download. Filename unchanged: `<slug>-<edition>-<card>-<name>.png`.
- `Download instead` is a quiet **text** link, shown only where the share
  sheet does not exist (desktop), so the button count stays at three.

### 4.4 What disappears, and why that is safe

| Removed | Why it is safe |
|---|---|
| Story/Square toggle at the top | The choice is asked once, at Save, when the guest knows what they are choosing. The preview still switches shape live inside the format ask. |
| The separate cropper screen | The card's own ring is the mask; the guest sees the real result while fitting, not a proxy. |
| `Reset` | One gesture replaces `fit()`: pinch out to minimum. |
| Zoom slider (on touch) | Pinch. Desktop keeps the slider, where pinch does not exist. |
| `Close` button | Drag the handle down, tap the backdrop, or press Escape — all already implemented in `openSheet()`. |
| "Make your card" hand-off tile on the ticket | The card opens from the ticket, the manage page and check-in; the tile stays, the second sheet stops asking twice. |

Nothing that a guest can *do* is removed — only the number of things they
must read before the pretty part starts.

### 4.5 Motion, sound, accessibility

- One reveal, once (§4 of the open questions): a warm light sweeps across
  the ring as the sheet opens, and the ring ignites when a photo lands.
  Nothing loops. All of it is behind `prefers-reduced-motion` and
  `motionEnabled()`.
- No sound on the portal (stage only, §13.1.6).
- Focus trap, Escape, `role="dialog"`, live-region toasts — all inherited
  from `openSheet()`; the file input keeps a real `<label>`; the ring
  button is keyboard-focusable with a visible focus ring; contrast of every
  text token against its own background is checked at build (§13.2).
- The privacy line stays, one short line under the actions: *"Your photo
  stays on your phone."* The card is a personal photo going into a church
  group chat; saying so is the feature (question 4).

---

## 5. Build plan (the mechanical part)

**Artwork** — rewrite `assets/se/templates/im_going.svg` and
`im_going_square.svg` to the specs in §3. Tokens used (all §14.4 legal):

| Token | Kind | Purpose |
|---|---|---|
| `se__image__hero` | image | the atmosphere (low-res data URI) |
| `se__image__logo` / `se__image__crest` | image | masthead + ghost |
| `se__text__verse_text`, `se__text__verse_ref` | text | the verse |
| `se__box__verse_text--wrap-2` | box | verse fit box |
| `se__text__title`, `se__text__edition`, `se__text__headline`, `se__text__first_name`, `se__text__date`, `se__text__time`, `se__text__venue`, `se__text__organizer`, `se__text__url` | text | existing fields |
| `se__fill__*`, `se__stroke__*`, `se__stop__*` | colour | existing palette roles |
| `se__qr__ref_url` | qr | the guest's referral link |
| `se__if__has_photo` / `se__ifnot__has_photo` | flag | exactly one layout survives |

**Server** — `includes/special_events/cards.php`: add `hero` (path of the
preferred ≤ 480 px variant) and `doors` (already derivable from
`se_event_days()`) to the `im_going` payload, plus the default
`verse_text` / `verse_ref` so the Studio can override one day. No behaviour
change when a hero is absent.

**Client** — `assets/se/js/portal/card.js`: three screens, live in-ring
cropper; `photo.js`: emit the *fitted circle box* rather than a standalone
canvas, and keep the drag/pinch math. `sheet.js` / `manage.js` / `checkin.js`
entry points do not change.

**CSS** — the builder's class list changes, so `se.input.css` must be
updated and **`assets/se/css/se.css` + `assets/se/BUILD` rebuilt in the same
commit** (`bash bin/build_se_css.sh`; the sandbox fallback with
`npx @tailwindcss/cli@4.1.18` is documented in AGENTS.md).

**Tests** — a new `tests/special_events/js/card_template.test.mjs` alongside
the existing `svg_tokens.test.mjs`: both templates must parse, declare
exactly one `se__if__has_photo` pair, contain the crest and the verse, and
keep every text token inside its safe margin. Proof renders for both
formats before and after a photo, committed to `docs/design/im_going/`.

**Guide** — a decision-log row and a §14.3/§14.4 update come with the build
PR, not with this proposal.

---

## 7. Decisions taken (running log)

| # | Decision | Answer | Date |
|---|---|---|---|
| 1 | Hero boldness | **Blurred atmosphere** — the hero is light, not a second subject | 2026-10-07 |
| 2 | The verse | **Psalm 16:11** (KJV): "Thou wilt shew me the path of life: in thy presence is fulness of joy; at thy right hand there are pleasures for evermore." | 2026-10-07 |
| 3 | Crest | **Masthead + 5 % corner ghost** | 2026-10-07 |
| 4 | Ring | **Go further** — the flat ring is rejected as too plain; see `directions-round3.jpg` | 2026-10-07 |
| 5 | Safe frame | **Full frame.** The story keeps the full-frame composition; the safe-box study (`safe-frame.jpg`) stays on record as the fallback if Instagram proves the point in the wild | 2026-10-07 |
| 6 | Reveal | **One light sweep + a confetti drift**, once, silent, off under reduced-motion | 2026-10-07 |
| 7 | Verse voice | **A script/serif voice for the verse** — Fraunces italic, not body sans | 2026-10-07 |
| — | Palette | **The event's own brand colours**, always. `se_event_theme()` reads `brand_primary` / `brand_secondary` / `brand_accent`; Chara happens to sit on the HOD blues because that is how it is configured. The church crest, by contrast, is with the church's brand | 2026-10-07 |

### Why the safe frame was overruled

The user's own experience is the evidence: a full-frame card posted to
WhatsApp Status looks right, because Status shows the image whole. The
overlay study still stands as the reference for the day a card is posted to
Instagram specifically — at that point the safe-box variant of the same
artwork is a token-compatible drop-in, nothing more to design.

### The type finding

`SE_FONT_PAIRS` (constants.php) already ships six display faces — **Unbounded,
Syne, Bricolage Grotesque, Space Grotesk, Fraunces, Monoton** — and the
programme poster (§14.2b) uses `data.fonts.display` / `data.fonts.body` from
the event. **The card templates hardcode `Unbounded` three times each**, so
the card has never once honoured the event's font choice. That is the
mechanical reason the poster reads more beautifully than the card.

`type-specimen.jpg` shows all six on the card's own lines. Fraunces — a
"soft-serif with wonk", a true italic with swash terminals and old-style
figures — is the face whose beauty is hard to explain: it makes the verse
read as scripture and "I'm going!" read as hand-set. It is already in the
event's font list, so no new dependency is needed.


---

## 6. Open questions

| # | Question | Recommendation |
|---|---|---|
| 1 | How bold should the hero be? | **Blurred atmosphere (as rendered)** |
| 2 | Which verse is hardcoded? | **Zechariah 8:5**, or the Studio's approved list |
| 3 | Where does the crest go? | **Masthead + 5 % ghost on the corner** |
| 4 | The ring treatment | **Primary ring + accent hairline + halo** |
| 5 | Instagram/WhatsApp safe bands | **Full frame** (safe-box study kept on record) |
| 6 | The reveal moment | **One light sweep + a confetti drift** |

Answers are recorded in §7 as they arrive, then the build starts.
