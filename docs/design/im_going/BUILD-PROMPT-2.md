# Build prompt 2 — finishing the "I'm going" card v2

**How to use this.** Start a new chat in Arena against this same repo
(`tomblakeasaah196/hodlekki`), with **PR #69 merged to `main` first** — a new
session's branch is cut from `main`, so nothing here exists without that merge.
Then paste everything below the line.

**Read-before-you-start:** `AGENTS.md` (repo rules — binding), `CLAUDE.md`,
`docs/engineering_guide.md` §13.15, §14.3 and §14.4, then
`docs/design/im_going/README.md` (the design spec) and
`docs/design/im_going/BUILD-PROMPT.md` (what was already asked for).

---

## THE PROMPT

You are working in `tomblakeasaah196/hodlekki`, a PHP 8.3 + MySQL church
management system on a cPanel shared host. Read `AGENTS.md` and `CLAUDE.md`
first — they are binding.

**The build is done. Do not rebuild it.** Tasks 1–5 of
`docs/design/im_going/BUILD-PROMPT.md` landed in PR #69 and are on `main`.
Read that prompt and the design README for the *why*, then read the code:

| Path | State |
|---|---|
| `assets/se/templates/im_going.svg` | Jubilee medallion, 1080 × 1920. Carries `se__slot__photo`, one `se__if__`/`se__ifnot__has_photo` pair, the crest twice, the verse. |
| `assets/se/templates/im_going_square.svg` | The same direction at 1080 × 1080, recomposed (not cropped). |
| `assets/se/js/portal/card.js` | Three buttons — Add, Remove, Save — and never more than two on screen. The medallion is the `<button>`, placed from the template's `se__slot__photo` rect (no hardcoded geometry). Save asks Story/Square *afterwards*. |
| `assets/se/js/portal/photo.js` | In-ring fitter: drag, pinch, clamped to the cover fit, emits the fitted square as the JPEG data URI for `se__image__photo`. The photo never leaves the device. |
| `includes/special_events/cards.php` | Payload carries `hero` (path of the ≤ 480 px variant), `text.doors`, the Psalm 16:11 defaults, `images.logo`, and `fonts.card_display` / `fonts.card_body`. PR #67's phone path is intact. |
| `assets/se/js/core/svg.js` | `embedFontCss()` takes `{family, italic}` and filters Google's subsets to Latin. |
| `tests/special_events/js/card_template.test.mjs` | 8 structural checks per format. Suite is **215 tests**. |
| `docs/design/im_going/proof-*.png` | Proof renders, both formats × both photo states. |

### Your job, in this order

**1. Draw the doors line — ask first.** `cards.php` sends `text.doors`
("Doors open 4:30 PM") and **neither template declares `se__text__doors`**, so
it travels unread. The approved story foot is full (date · time / venue / url /
signature at y 1728–1850) and §3.2 specifies the square's foot as one line
without one — but `direction-g-jubilee.png` shows the story's last two lines as
`Sat 24 Oct · 5:00 PM · HOD Lekki Centre` then `DOORS OPEN 4:30 PM ·
hodlc.lc.cm/e/chara`. Put that choice to the user as **one** question —
(a) leave it payload-only, (b) fold doors + url onto the story's last line as
the render shows, or (c) add it to the square too — and recommend (b). Then
implement, and extend `card_template.test.mjs` so the new token's box is held
inside the safe margin like every other one.

**2. The device smoke test — you cannot run it.** The sandbox has no browser
and no phone, and the definition of done is a guest doing it on a handset. Ask
the user to run this list and report back:

- open the portal on a phone, reach the success screen or the manage link, tap
  **Make your "I'm going" card**;
- the card draws with the hero as atmosphere and the crest visible;
- tap the circle → the picker offers **camera and gallery**;
- the photo lands in the ring; drag moves it, pinch zooms, and no empty edge is
  reachable;
- only two buttons are ever on screen; Add and Remove swap in place;
- **Save** → Story/Square sheet → choosing one opens the phone's share sheet
  with a 1080-px card;
- on a desktop: no pinch, the zoom slider appears, and the fallback line reads
  **Download instead**;
- post a Story to WhatsApp Status: no name cut off, verse legible, QR scans.

**3. Fix only what the smoke test finds**, in the smallest commit that fixes it.

### Do not start these without being asked

The welcome/team/My Night cards inheriting this shell; a Studio UI for
overriding the verse (the payload default is in place, the UI is not); anything
in the design README's §6 open questions — all of them are answered in §7.

### Hard constraints — breaking these fails CI or the feature

- **The cross-device phone path (PR #67) is load-bearing.** These exact strings
  must survive in `assets/se/js/portal/card.js`:
  `openCardBuilder(kind = 'im_going', options = {})`, `request.phone =
  options.phone;` inside its `options.phone` guard, and
  `call('public', 'card', request)`. In `sheet.js`:
  `openCardBuilder('im_going', { phone: draft?.phone })`. In `cards.php`:
  `se_card_registration_for_phone(`, the `$kind === 'im_going' &&
  array_key_exists('phone', $body)` check, `se_public_limit($pdo, $event,
  'lookup', 20, 400, 600)`, `'lookup_phone'` and `se_public_actor(`.
  `tests/special_events/js/card_reentry.test.mjs` greps for them.
- **`se__slot__photo` must stay in step with the `se-photo-clip` circle** —
  `card_template.test.mjs` fails if they drift, and the builder places the
  draggable circle from the slot.
- **The CSS freshness contract.** Any change under `assets/se/`, `e/`,
  `includes/special_events/`, `modules/special_events/` or `api/special_events_*`
  needs `assets/se/css/se.css` **and** `assets/se/BUILD` rebuilt **in the same
  commit**. Never hand-edit `se.css`.
- **CI also requires** `modules/special_events/how_to_use.md` to be updated in
  the same PR as any `assets/se/js/`, `includes/special_events/`, `e/` or
  `modules/special_events/` change.
- No `style="…"` attributes in server-rendered HTML (inline `style` inside an
  SVG *template* is fine — that SVG is never parsed as HTML).
- Never commit the Tailwind binary, `uploads/`, `live/` or `vendor/`.
- Work on `arena/<your-session-id>`, push only to it, open a PR from it. Do not
  touch `main`.

### Environment traps — this is what went wrong last time

- **The sandbox is recycled between turns. `/tmp` and anything uncommitted is
  gone; only pushed commits survive.** An earlier session lost its scratch
  renderer *and* a template this way, and a later chat re-did finished work
  because its clone predated the push. So: **commit and push after every small
  step**, and keep scratch outside the repo.
- **No PHP in the sandbox.** Syntax-check with `php-wasm` from npm:
  `token_get_all(file_get_contents($f), TOKEN_PARSE)` throws `ParseError` on
  bad syntax — the same check `php -l` makes. Note `php.run()` resolves to an
  *exit code*, not stdout; write results to the virtual FS and read them back
  with `php.readFile(path, { encoding: 'utf8' })`.
- **No browser.** Proof-render artwork *outside* the repo with
  `@resvg/resvg-js` plus `@expo-google-fonts/*` TTFs, at
  `fitTo: { mode: 'width', value: 540 }`, and inline the crest from
  `docs/design/im_going/crest-512.png` (the SVG's own base64 payload will not
  decode in resvg). Never re-render full-size PNGs in a loop.
- **Tailwind**: the standalone binary download is blocked here. Install
  `tailwindcss@4.1.18` **and** `@tailwindcss/cli@4.1.18` with npm *outside* the
  repo, then
  `node <that>/node_modules/@tailwindcss/cli/dist/index.mjs -i assets/se/css/se.input.css -o assets/se/css/se.css --minify`
  followed by the `assets/se/BUILD` stamp — that is what `bin/build_se_css.sh`
  does. Confirm with `bash bin/build_se_css.sh --check` when it can download.
- **Tests:** `node --test "tests/special_events/js/*.test.mjs"` (215, about a
  second) and `node --experimental-default-type=module --check <file>` for
  every file you touch under `assets/se/js`.

### Working style

1. One small step per turn; commit and push after each.
2. Ask before guessing on anything the README does not already answer.
3. **Do not rebuild what exists.** It is built, tested and reviewed; read it
   before you change it.
4. Proof renders are the last step of a task, not a running loop.
5. `gh pr checks` after every push; a stale `se.css` or a missing
   `how_to_use.md` update is the usual red.

### Definition of done

The doors decision is implemented and tested; the suite is green; CI is green
on the PR; and the user has run the phone checklist above and reported nothing
broken.
