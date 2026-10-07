# Build prompt — Special Events "I'm going" card v2

**How to use this.** Start a new chat in Arena against this same repo
(`tomblakeasaah196/hodlekki`) and paste everything below the line. It is
written to be the *only* thing that new session needs. It assumes zero memory
of the design conversation, because all of that is already committed in the
repo and the prompt points at it.

**Read-before-you-start, in this order:** `AGENTS.md` (repo rules),
`CLAUDE.md`, `docs/engineering_guide.md` §13.15, §14.3 and §14.4, and
`docs/design/im_going/README.md` (the full design spec and the decisions log).

---

## THE PROMPT

You are working in `tomblakeasaah196/hodlekki`, a PHP 8.3 + MySQL church
management system on a cPanel shared host. Read `AGENTS.md` and `CLAUDE.md`
first — they are binding. Then read `docs/engineering_guide.md` §13.15
(performance budgets), §14.3 (personal share cards) and §14.4 (SVG template
authoring rules), and `docs/design/im_going/README.md`, which is the complete
design spec for the work below.

**An approved design already exists. Do not redesign it.** Renders are
committed in `docs/design/im_going/`. The decisions are final:

| Decision | Value |
|---|---|
| Direction | **G · Jubilee medallion** (`direction-g-jubilee.png` — the approved render) |
| Display + verse face | **Fraunces** (already in `SE_FONT_PAIRS`) |
| Details face | **Inter** |
| Verse | **Psalm 16:11**, KJV, verbatim, hardcoded as the token's default |
| Ring | Gilt medallion: deep primary band, gold ring, pale hairline |
| Crest | Masthead (`/assets/images/hod_logo.svg`) + 5 % ghost bleeding off a bottom corner |
| Hero | The event's hero photo, **blurred into atmosphere**, never a second subject |
| Palette | The event's own `brand_primary` / `brand_secondary` / `brand_accent` via `se_event_theme()` |
| Frame | Full frame (no Instagram safe-box composition) |
| Reveal | One light sweep plus a confetti drift, silent, off under reduced motion |

### Current state of the work

- `assets/se/templates/im_going.svg` — **rewritten and committed** to the
  approved direction. Proofed at 1080×1920 in both photo states.
- `assets/se/templates/im_going_square.svg` — **not yet started.**
- `assets/se/js/portal/card.js` and `assets/se/js/portal/photo.js` —
  **not yet changed.** They still implement the old builder (a Story/Square
  toggle at the top, a separate cropper screen, Reset, a zoom slider, Close).
- `includes/special_events/cards.php` — **not yet changed.** It does not send
  the hero path or the doors line to the card.
- CSS — **not yet changed**, so `assets/se/css/se.css` has not been rebuilt.

### Task 1 — the square template

Create `assets/se/templates/im_going_square.svg` at 1080 × 1080 to match
`square-v2-1080x1080.png` and `square-1080x1080.png` in the design folder: a
recomposition, not a crop. QR top-right, crest + `ENVISION PRESENTS` top-left,
wordmark and the same gilt medallion centred, `I'm going!` in Fraunces italic,
Psalm 16:11 in Fraunces italic, and one line of date · time · venue · short URL
at the foot. Safe margin is 48 px on squares (§14.4). Same tokens as the story
template, including `se__slot__photo`.

### Task 2 — the builder: three buttons, six jobs

Rewrite `assets/se/js/portal/card.js` and `assets/se/js/portal/photo.js` to the
flow in `docs/design/im_going/builder-flow.jpg` and §4 of the README. The whole
point is that there are **three buttons — Add photo, Remove photo, Save — and
never more than two on screen at once.**

1. **One sheet, one canvas.** No Story/Square toggle at the top of the sheet.
   No separate cropper screen. No Reset button. No Close button (drag the
   handle down, tap the backdrop, or press Escape — `openSheet()` in
   `assets/se/js/portal/dom.js` already does all three).
2. **The circle is the button.** The medallion in the live card preview is a
   `<button>`. Tapping it opens the file picker. Find its position and size by
   reading the template's `se__slot__photo` rect (an inert `se__` token the
   engine ignores — the new template already carries it) and projecting it
   through the preview's pixel size into CSS pixels. Do not hardcode geometry.
3. **Fit in the ring, live.** The photo lands inside the card's own medallion.
   Drag with one pointer; pinch to zoom with two, clamped so the circle can
   never show an empty edge. Re-emit the fitted square as the
   `se__image__photo` data URI, throttled to a frame. **The photo never leaves
   the device** — no upload, no storage, no fetch. Keep that promise in the
   code and in one short line of UI copy.
4. **Add / Remove swap in place.** `<input type="file" accept="image/*">` with
   no `capture` attribute, so camera *and* gallery stay available. Decode with
   `createImageBitmap(file, { imageOrientation: 'from-image' })`, downscale to
   1600 px. On a mouse (`pointer: fine`) only, show a zoom slider, because
   there is no pinch.
5. **Save asks the format — afterwards, never before.** Tapping Save opens a
   second, lighter sheet with two big cards: **Story** (1080 × 1920 — WhatsApp
   Status, IG/FB Story, TikTok) and **Square** (1080 × 1080 — WhatsApp DP,
   group chat, IG feed), each with its size and the places it goes. Choosing
   one switches the preview shape live, rasterises with the existing
   `rasterise()`, and hands the **File** to `navigator.share({ files })` via the
   existing `shareOrDownload()`. Where there is no share sheet, a quiet text
   link says `Download instead` — a link, not a third button.
6. **Keep every existing entry point working.** The builder is opened from
   `sheet.js` (after registering), `manage.js` and `checkin.js`. None of those
   call sites should need to change beyond what they already do.

Also load the hero: fetch the smallest hero variant, draw it to a 96 px-wide
canvas, `toDataURL('image/jpeg', 0.72)` and put that **2–4 KB** data URI into
`se__image__hero`. That is why the atmosphere is cheap and Safari-safe — it is
documented in §3.3 of the README. **Never** build a larger hero data URI.

### Task 3 — server

In `includes/special_events/cards.php`:

- Add `hero` to the `im_going` payload: the path of the preferred ≤ 480 px hero
  variant (see `se_asset_srcset()` in `assets.php`), or `null`.
- Add the doors line (`doors`) from `se_event_days()`.
- Add the default `verse_text` / `verse_ref` (Psalm 16:11) so the Studio can
  override them later without touching the artwork.
- **Send the crest.** The artwork has two `se__image__logo` elements (the
  masthead and the corner ghost) and the payload sends no logo today, so the
  engine removes them and the card goes out unsigned. Read the event's
  `logo_asset_id` (falling back to `/assets/images/hod_logo.svg`, inlined as a
  small data URI — the studio poster payload already does this for
  `church_logo`) and pass it as `images.logo`.
- **Send the card's own font families.** `card.js` calls
  `embedFontCss([data.fonts.display, data.fonts.body])`, which inlines the
  *event's* faces — Unbounded and Inter — so the template's Fraunces is not
  embedded and the display lines lose their voice. Add `fonts.card_display`
  (`'Fraunces'`) and `fonts.card_body` (`'Inter'`) to the payload and have
  `card.js` embed those instead. The template already declares
  `font-family="Fraunces, Unbounded, serif"` as a fallback chain, so nothing
  looks broken in the meantime — it simply upgrades to Fraunces once this
  lands.
- No behaviour change when there is no hero: the gradients alone must still
  render, exactly as today. The same goes for a missing logo.

### Task 4 — CSS, and the freshness contract

`.se-card-stage`, `.se-card-preview`, `.se-cropper` and the sheet classes in
`assets/se/css/se.input.css` will change. **After your last markup or JS edit,
rebuild the committed stylesheet in the SAME commit:**

```bash
bash bin/build_se_css.sh          # writes both files
git add assets/se/css/se.css assets/se/BUILD
```

CI compares the committed `se.css` against a fresh build of the pinned
Tailwind and fails on any diff. If the sandbox cannot download the standalone
binary, use the npm CLI at the pinned version, from the repo root:

```bash
npx -y @tailwindcss/cli@$(tr -d '[:space:]' < assets/se/css/TAILWIND_VERSION) \
    -i assets/se/css/se.input.css -o assets/se/css/se.css --minify
printf '%s %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$(git rev-parse --short HEAD)" > assets/se/BUILD
```

Never hand-edit `se.css`. Never open the PR with a stale artifact.

### Task 5 — tests and proof renders

- Add `tests/special_events/js/card_template.test.mjs` beside the existing
  `svg_tokens.test.mjs`: both templates parse as SVG, each declares exactly one
  `se__if__has_photo` / `se__ifnot__has_photo` pair and one `se__slot__photo`,
  both carry the crest and the verse tokens, and every text token's box lands
  inside the safe margin.
- Confirm the existing suite still passes; CI parses every module under
  `assets/se/js` with `node --check`.
- Commit final proof renders of both formats, both photo states, into
  `docs/design/im_going/`, and update the README's status line.

### House rules that will bite you

- **AGENTS.md is binding.** 4-space indent, LF, a `// /path/to/file.php` header
  on line 2 of every PHP file, PDO prepared statements only.
- **No `style="…"` attributes in server-rendered HTML.** The module CSP sets
  `style-src 'self'` plus a nonce, and a nonce does not cover style
  *attributes* — browsers drop them silently. Use classes, or the nonced
  `<style>` block. (Inline `style="…"` inside an SVG *template* is fine: that
  SVG is never parsed as HTML.)
- The CSP allows `font-src` from `https://fonts.gstatic.com` and
  `connect-src` to `https://fonts.googleapis.com`, so `embedFontCss()`'s
  fetch-and-inline of Fraunces and Inter is already permitted.
- **Never commit** the Tailwind binary, `uploads/`, `live/`, or `vendor/`.
- Work on the branch `arena/<your-session-id>`, push only to it, and open a PR
  from it. Do not touch `main`.

### Working style — this matters

The previous session ran long turns and regenerated large PNGs repeatedly,
which caused timeouts and, twice, a sandbox reset that wiped its scratch
toolchain. Do not repeat that:

1. **Small steps.** One task per turn. Commit and push after each.
2. **Keep scratch work out of the repo and out of long-lived paths.** If you
   need a renderer to proof artwork, install `@resvg/resvg-js` plus the
   `@expo-google-fonts/*` packages into a scratch directory and render at a
   **reduced width** (e.g. `fitTo: { mode: 'width', value: 540 }`). Never
   re-render full-size PNGs in a loop.
3. **Proof renders are the last step of a task, not a running loop.**
4. If a command times out, do not re-run it unchanged — narrow it.
5. Ask before guessing on anything the README does not already answer.

### Definition of done

A guest on a phone opens the portal, taps once, and taps once more. The photo
lands in the medallion, they drag it until the face sits right, they press
Save, they choose Story or Square, and their phone's share sheet opens with a
1080-px card that looks like `direction-g-jubilee.png`. At no point do they see
more than two buttons, and at no point does their photo leave the device.
