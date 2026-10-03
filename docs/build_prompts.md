# Special Events — build prompts

This file holds the full instructions for building the Special Events module in eight pull requests. The spec is [`engineering_guide.md`](engineering_guide.md). The plan and the live progress tracker are in **§28** of the guide.

---

## How to use this file (for the owner)

1. Build in order: **PR0 → PR1 → PR2 → PR3 → PR4 → PR5 → PR6 → PR7**. Start the next chat only after the previous PR is merged into `main`. Two exceptions are safe: PR0 can run alongside anything, and PR7 can run alongside PR6 once PR5 is merged.
2. For each PR, open a **new Claude Code chat** on `tomblakeasaah196/hodlekki` and paste its short prompt (below). Use the strongest model you have for PR1, PR2, PR3 and PR5 (foundations, concurrency and realtime). The others are fine on any strong model.
3. When the chat finishes, review the pull request, try the smoke test it lists, and merge it. Then start the next chat.
4. If a chat stops early (context, time), open a new chat with the same prompt plus: "Continue the open pull request for PR*n* on branch `<branch>`."

### Short prompts (copy and paste)

> **PR0** — Build PR0 (deploy exclusions fix) of the Special Events plan. Follow `docs/build_prompts.md` (Shared rules + PR0) exactly. Start from the latest `main`, update the progress tracker in `docs/engineering_guide.md` §28.3, then open a pull request to `main` and get CI green. Don't merge it.

> **PR1** — Build PR1 (foundation & Studio core) of the Special Events module. Follow `docs/build_prompts.md` (Shared rules + PR1) exactly, with `docs/engineering_guide.md` as the spec. Start from the latest `main`, update §28.3, then open a pull request to `main` and get CI green. Don't merge it.

> **PR2** — Build PR2 (public portal & registration) of the Special Events module. Follow `docs/build_prompts.md` (Shared rules + PR2) exactly, with `docs/engineering_guide.md` as the spec. Start from the latest `main`, update §28.3, then open a pull request to `main` and get CI green. Don't merge it.

> **PR3** — Build PR3 (check-in, teams & live backbone) of the Special Events module. Follow `docs/build_prompts.md` (Shared rules + PR3) exactly, with `docs/engineering_guide.md` as the spec. Start from the latest `main`, update §28.3, then open a pull request to `main` and get CI green. Don't merge it.

> **PR4** — Build PR4 (programme, karaoke & reminders) of the Special Events module. Follow `docs/build_prompts.md` (Shared rules + PR4) exactly, with `docs/engineering_guide.md` as the spec. Start from the latest `main`, update §28.3, then open a pull request to `main` and get CI green. Don't merge it.

> **PR5** — Build PR5 (games I: engine, decks & quiz games) of the Special Events module. Follow `docs/build_prompts.md` (Shared rules + PR5) exactly, with `docs/engineering_guide.md` as the spec. Start from the latest `main`, update §28.3, then open a pull request to `main` and get CI green. Don't merge it.

> **PR6** — Build PR6 (games II: party games & finale) of the Special Events module. Follow `docs/build_prompts.md` (Shared rules + PR6) exactly, with `docs/engineering_guide.md` as the spec. Start from the latest `main`, update §28.3, then open a pull request to `main` and get CI green. Don't merge it.

> **PR7** — Build PR7 (after the event) of the Special Events module. Follow `docs/build_prompts.md` (Shared rules + PR7) exactly, with `docs/engineering_guide.md` as the spec. Start from the latest `main`, update §28.3, then open a pull request to `main` and get CI green. Don't merge it.

---

## Shared rules (every PR reads these first)

### 1. Sources of truth

- `AGENTS.md` governs the whole repository. Read it completely before writing code.
- `docs/engineering_guide.md` is the specification. Read §0–§3 (how to read, summary, background, decisions) and §28 (build plan, progress, deviations) first, then every section your PR lists under **Read**. Appendix A is the schema; Appendix E is the per-event settings contract.
- **MUST/SHOULD/MAY** in the guide are normative (§0). Where the guide is silent, follow `AGENTS.md` and the patterns of existing code (`api/reach_api.php`, `includes/reach_helpers.php`, `modules/sms_studio/`).
- If the guide is wrong, ambiguous or impossible, make the smallest sensible decision, implement it, **fix the guide section** and log it in §28.4. Ask the owner only for product decisions (wording, numbers, who may do what).

### 2. Before you start

1. Work from the latest `main`. Check §28.3: every PR yours depends on must be ✅, and `git log` must confirm it is merged. If not, stop and tell the owner.
2. Read the previous PR's row in §28.3 (notes for the next PR) and the §28.4 log.
3. Use the branch your session gives you. Commit and push often (the container is temporary).

### 3. Scope

- Build exactly your PR's **Build** list. Do not start the next PR's features. Leave clean extension points instead (a hook, a stub returning `FEATURE_NOT_READY`, a hidden tab).
- Code shipped before a later migration MUST degrade safely when that table is missing (`se_table_exists()`, §9.4). The tree is copied to production before migrations run.
- Every surface you add must work on a mid-range Android phone first (§13.15), with reduced motion respected and WCAG 2.2 AA (§13.14).

### 4. House rules (from `AGENTS.md`, plus the module rules in §21.6)

- PHP 8.3, 4 spaces, LF, no trailing whitespace, `// /path/to/file.php` on line 2 of every PHP file.
- PDO prepared statements only; wrap DB work in `try/catch` and return the JSON envelope `{status, message, data}` (+ `code`, §12.1).
- `htmlspecialchars()` for every echoed value. No secrets in code (only `$_ENV[...]`). Never read, print or commit `.env` values.
- Never touch `uploads/`, `vendor/`, `.env`, the production database, `bin/deploy.sh` or `webhook/deploy.php`.
- The module never writes `events`, `event_registrations`, `checkins`, `attendance` or `users`. The one exception is the Embrace insert in the PR7 hand-off (§17.4).
- SMS only through SMS Studio campaigns and queue (§16.1); never write `sms_log`. AI only through `se_ai()` (§15.1), never with attendee personal data, always with human review before apply.
- Seats, teams, player numbers and queue numbers are allocated only inside `se_lock_event()` transactions. Scores are a ledger (void and re-award, never UPDATE points). Never put names, phones, unrevealed answers or charades phrases in `public.json`.
- Preact + htm + signals, GSAP and the precompiled Tailwind are allowed **only** in this module (`assets/se/**`, `e/`, the Studio page). No CDNs at runtime except Google Fonts.

### 5. Migrations

- Copy the SQL for your PR **verbatim** from Appendix A. Name each file with the real UTC timestamp when you create it (`YYYYMMDDhhmmss_<slug>.sql`), keep the order inside your PR, and make sure it sorts after the newest file in `db/migrations/`. Update the file names in Appendix A and the §9.4 table to match.
- Follow the Appendix A rules: idempotent DDL, no semicolons in SQL comments, and no `CASCADE`/`SET NULL` foreign key on a generated column's base column.
- Apply your files, and then apply them again, on **both** MySQL 8.0 and MariaDB 10.x (tips below). Never edit a migration that is already on `main`. Ship a new forward migration instead.

### 6. Front-end

- No build step except CSS. Vendored ES modules under `assets/se/vendor/` (listed in `VENDOR.md` with version, source, SHA-256 and licence). After changing any class names, run `bin/build_se_css.sh` and commit `assets/se/css/se.css` (CI checks freshness).
- Keep `SE_PRELOAD` (§8.6.1) in sync with each surface's imports (a unit test checks it).
- If two branches both changed `se.css`, never merge it by hand. Rebuild it.

### 7. Testing

- `php -l` every changed PHP file. Run `php tests/special_events/run.php` and `node --test tests/special_events/js/`, and add the tests your PR lists (§22.1).
- Run the integration tests your PR lists (§22.2) against a local database. PR1 creates the local harness (`tests/special_events/db_setup.php`). It creates minimal stand-ins for the existing tables the module reads (`users`, `user_roles`, `user_departments`, `departments`, `system_notifications`, `sms_campaigns`, `sms_queue`, `reach_*` …), derived from how the code uses them, then applies `db/migrations/*`.
- Do a manual smoke test of every surface you touched (Appendix H.2), with a throwaway local `.env` that points to your local database. Never commit that `.env`. Put the steps you ran, and their results, in the PR description. Add screenshots of new UI (Chromium and Playwright are available in Claude Code cloud sessions).
- Run a security review of your diff before opening the PR (for example `/security-review`).

**Local databases in a Claude Code cloud container** (tested on 2026-10-03):
- MariaDB: `apt-get install -y mariadb-server`, then `mkdir -p /run/mysqld && chown mysql:mysql /run/mysqld` and start `mariadbd --user=mysql --socket=/run/mysqld/mysqld.sock` in the background.
- MySQL 8.0: `cdn.mysql.com` is blocked, so use apt. Run `apt-get download mysql-server-core-8.0 libevent-pthreads-2.1-7t64 libprotobuf-lite32t64 libaio1t64`, unpack each with `dpkg -x <deb> <dir>`, and set `LD_LIBRARY_PATH=<dir>/usr/lib/x86_64-linux-gnu`. Run `<dir>/usr/sbin/mysqld --no-defaults --initialize-insecure --user=root --datadir=/var/tmp/mysql8data`. Then start it with the same flags plus `--socket=/var/tmp/mysql8run/mysqld.sock --port=3308 --mysqlx=OFF --secure-file-priv=""`.
- Use strict `sql_mode` (`ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION`) and `time_zone = '+01:00'`.

### 8. Docs in the same PR

- `docs/engineering_guide.md`:
  - **§28.3:** set your own row to ✅ Merged, with the PR link, today's date and notes for the next PR. The row only reaches `main` when your PR merges, so `main` is always accurate. If the merge happens on a later day, the next PR corrects the date.
  - **§28.4:** log every deviation.
  - Fix any section whose behaviour you changed.
- `modules/special_events/how_to_use.md`: a plain-language guide for Envision and the crew, covering every feature you added. From PR1 on, CI fails a PR that touches the module without updating it.
- `AGENTS.md`: update it when a convention changes. `README.md`: update it for new cron lines or env keys.

### 9. Commits and the pull request

- Commits: short imperative subjects in house style (`feat: …`, `fix: …`, `chore: …`, `docs: …`).
- PR title: `feat(special-events): PR<n> — <name>` (PR0: `chore: …`).
- PR description:
  - what changed and why;
  - migrations included;
  - screenshots;
  - the smoke-test steps with results;
  - deviations (copied from §28.4);
  - anything deferred.
- Open the PR against `main` and get CI green. **Do not merge.** The owner reviews and merges.

### 10. Definition of done

All of these:
- every item in **Build** is done, or explicitly deferred in §28.4 with a reason;
- every **Acceptance** check passes, with its evidence in the PR;
- CI is green;
- the docs are updated;
- `git status` is clean, with no `.env`, uploads, dumps or secrets.

---

## PR0 — Deploy exclusions fix (open item O16)

**Goal.** Make `.deployignore` actually work with `bin/deploy.sh`, which copies with `tar`, so that `.git`, `.github`, tests and repo-only docs stop reaching the public docroot.

**Needs.** Nothing. It can run in parallel with PR1.

**Read.** `AGENTS.md` (Deployment), `bin/deploy.sh`, `.deployignore`, `DEPLOY.md`, guide §21.3 and §27 (O16).

**Build.**
1. Rewrite every entry in tar form. tar compares patterns with member names such as `./tests/x.php`, and it **ignores entries that start or end with `/`** (verified with GNU tar 1.35).
   - Root-only entries become `./<name>`: `./.git`, `./.github`, `./.cpanel.yml`, `./.deployignore`, `./.htaccess`, `./.user.ini`, `./php.ini`, `./uploads`, `./assets/uploads`, `./vendor`, `./DEPLOY.md`, `./AGENTS.md`, `./tests`, `./docs`.
   - Any-depth entries stay bare names: `.env`, `.env.local`, `error_log`, `node_modules`, `.DS_Store`, …
2. Rewrite the file's header comment, which still describes rsync.
3. Add a CI guard to the lint job in `.github/workflows/deploy.yml`. It runs the same `tar` pipeline as `bin/deploy.sh` against the checkout and fails if `.git`, `.github`, `tests`, `docs`, `AGENTS.md` or `DEPLOY.md` would be copied.
4. Fix `AGENTS.md` and `DEPLOY.md` wherever they say `rsync -a --delete`. The deploy uses tar and never deletes files.
5. Do **not** change `bin/deploy.sh` or `webhook/deploy.php`.

**PR description must include, for the owner:**
- the before/after list of copied paths from a local tar dry run;
- manual cleanup after merge (tar never deletes): in cPanel File Manager, delete these from `public_html/hodlc.lpc.cm/` if present: `.git/`, `.github/`, `tests/`, `docs/`, `AGENTS.md`, `DEPLOY.md`;
- a check that `https://hodlc.lpc.cm/.git/config` returns 403 or 404;
- a behaviour note: with `./php.ini` excluded, edits to the repo's `php.ini` no longer deploy (the docroot copy is authoritative, as the file intends). Ask the owner to confirm.

**Acceptance.** The tar dry run excludes all of the paths above. The CI guard fails on a deliberately broken pattern and passes on the real file. CI is green.

**Docs.** Mark O16 in §27 as fixed by this PR, and update §28.3.

---

## PR1 — Foundation & Studio core

**Goal.** The module exists in the ERP. Envision and managers can create, edit, clone, brand (hex colours with AI palette suggestions) and publish an event, set every registration and capacity option, and manage crew and the brand kit. `/e/<slug>` resolves to a branded first-paint page. There is no public registration yet (PR2).

**Needs.** The guide merged to `main`. PR0 recommended.

**Read.**
- §0–§9 in full.
- Events and days: §10.1, §10.2, §10.10.
- API: §12.1, and the §12.5 rows Events, Brand, Registration, Days, Assets, Crew and Ops.
- Front-end: §13.1, §13.2, §13.13, §13.14.
- Assets and AI: §14.1, §14.4, §15.1, §15.2.
- Rules and setup: §19–§21 in full, §22.1, §23.1–§23.3.
- Appendices: A.1–A.4, D.1, E, F, H.1.

**Build.**
1. **Migrations** A.1–A.4 (`se_core`, `se_people`, `se_ops` including `se_message_runs`, `se_seed_settings`).
2. **Core libraries** in `includes/special_events/` (§8.7):
   - `bootstrap.php`;
   - `constants.php` (§20.4);
   - `util.php`: ids, base32, JSON, hashing, time, `se_markdown()` (§19.5);
   - `security.php`:
     - `se_module_access()` and `se_require_capability()` with the §6.2 matrix;
     - the `X-SE-Request`, Origin, `Sec-Fetch-Site` and `X-SE-CSRF` checks (§19.3);
     - `se_rate_limit()` (§19.4), masking, and page headers with the CSP nonce (§19.7);
   - `settings.php`: module settings (§20.2) and `se_settings_normalize()` against Appendix E;
   - `events.php`:
     - CRUD with `row_version`, the publish checklist (H.1), unpublish/cancel/archive and `se_event_delete_draft()` (§9.5);
     - `se_event_phase()` (§10.1), days, `se_slug_change()` and reclaim (§10.2);
     - clone (§10.10) for the tables that exist so far;
   - `theme.php`: the OKLCH engine, contrast and Marquee tokens (§13.2), plus a JS twin in `@se/core/theme.js` sharing test vectors;
   - `assets.php`: roles and limits (§14.1), magic bytes, GD re-encode, WebP variants, the SVG sanitiser and `uploads/se/.htaccess` (§19.6);
   - `ai.php`: `se_ai()` (§15.1) plus `prompts/palette_suggest.md` (D.1);
   - the audit helper (§19.10), `se_table_exists()`, `se_lock_event()` and `se_schema_check()` (Appendix A, rule 3).
3. **Studio API** `api/special_events_api.php`:
   - Events, Brand, Days and Assets (including template upload with sanitising);
   - Registration settings: form fields, capacity, overrides (the engine that enforces them is PR2);
   - Crew: list, add with notification, revoke, user search (display keys come in PR3);
   - Ops: audit, health with the schema check, module settings.
4. **Studio UI**: `modules/special_events/index.php` (requires `header.php` on line 3) and `assets/se/js/studio/**`, in the ERP look (§13.13).
   - Home (cards, filters), New event, Clone.
   - Workspace tabs:
     - **Overview:** readiness ring with H.1, key dates, quick links.
     - **Details:** days and live slug validation.
     - **Brand:** paste-friendly hex inputs, contrast report, AI palette grid, fonts, asset pickers.
     - **Registration:** every capacity behaviour, consent mode, custom fields, overrides.
     - **Assets**, **Crew**, **Settings** (module settings, Health → Schema).
   - Live preview drawer (§13.13).
   - Tabs belonging to later PRs stay hidden until their tables exist.
5. **`e/` router and shell** (§8.8, §19.7):
   - `e/.htaccess` and `e/index.php`;
   - lowercase and alias redirects (301); drafts return 404 unless the viewer is crew or has `?preview=`;
   - headers with the CSP nonce, the theme `<style nonce>` and the boot JSON;
   - a server-rendered first-paint hero and a branded 404;
   - `privacy`, `calendar.ics` and the hub `/e/`.

   The full portal and registration are PR2.
6. **Front-end tooling** (§8.6, §23.2):
   - vendored libraries plus `VENDOR.md`, and `assets/se/.htaccess`;
   - the Tailwind v4 standalone build: `bin/build_se_css.sh`, `se.input.css`, the committed `se.css`, `TAILWIND_VERSION`, `BUILD`;
   - `@se/core`: `api.js`, `store.js`, `boot.js`, `html.js`, `theme.js`;
   - the design tokens (§13.1).
7. **Existing files** (§21): `includes/header.php` (nav, Ctrl/Cmd+K, titles, §21.1), the CI steps (§21.5), the new `AGENTS.md` section (§21.6), and `README.md` plus `.env.example` (§21.7: `SE_HASH_PEPPER`, model overrides, realtime driver, Bible API base).
8. **Local test harness**: `tests/special_events/db_setup.php`, which creates the local test database with stand-ins for the existing tables and applies the migrations (Shared rules §7). Add a small dev router for `php -S` that mimics `e/.htaccess`, for local smoke tests.
9. **Unit tests** (§22.1): the `run.php` harness; slug, theme (PHP + JS parity), markdown, SVG sanitiser, settings normaliser, schema (palette).

**Acceptance.**
- The migrations apply, and then re-apply, cleanly on MySQL 8 and MariaDB.
- A producer creates "Chara" (`chara`, one day, hex brand colours, capacity 120) in under 30 minutes.
  - They get at least four valid AI palettes, or a clear message when AI is off.
  - They see contrast warnings.
  - They can publish only once H.1 is green.
- `/e/chara` shows the branded hero once published. A draft returns 404 without crew access or `?preview=`. `/e/CHARA` returns a 301.
- Clone produces a new draft with the chosen options.
- The Studio authorisation matrix (actions × roles, §6.2) holds. Foreign-origin POSTs are rejected.
- Polyglot uploads and malicious SVGs are neutralised, and EXIF data is stripped.
- `php -l`, the unit tests (PHP and Node) and the CSS freshness check pass. With later tables missing, nothing breaks.

**Docs.** `modules/special_events/how_to_use.md` v1 (create, clone, brand, registration settings, crew, assets, publish), plus §28.3 and §28.4.

---

## PR2 — Public portal & registration (registration goes live)

**Goal.** Registration opens at `/e/<slug>`, built for a guest to fall in love with in the first five seconds:
- the "Marquee" portal (§13.3);
- phone-first registration for members, returning guests and new guests;
- every capacity behaviour, including the waitlist;
- the manage link and SMS links;
- the "I'm going" card with the optional photo circle.

Crew can see, correct and export attendees.

**Needs.** PR1.

**Read.**
- Decisions: §3 (D3, D7, D8, D15, D16).
- Journeys and identity: §7.2–§7.3, §10.3, §10.4.
- API: §12.1, §12.2 (including §12.2.1).
- Front-end: §13.1, §13.3–§13.5, §13.14, §13.15.
- Share cards and copy: §14.3, §14.4, §15.8.
- Messaging: §16.1, §16.3, §16.5.
- Attribution, export, abuse and privacy: §18.2, §18.6, §19.4, §19.5, §19.8.
- Appendices: D.7, E, F.

**Build.**
1. **Identity** (`identity.php`, §10.3):
   - `se_phone_normalize()`, which delegates Nigerian mobiles to `sms_normalize_phone()`, plus a JS twin;
   - contacts unique by phone, member lookup in `users`, display names ("Ada O.");
   - the device cookie, manage tokens (22 characters, stored as HMACs), claim and transfer;
   - one identity per device (`for: self|other`).
2. **Capacity** (`capacity.php`, §10.4): registration state, online and walk-in pools, auto-close, overrides, the waitlist with in-order auto-promotion, self-cancel rules and seats-left modes. All allocation runs inside `se_lock_event()`.
3. **Registration** (`registration.php`):
   - register, with the honeypot and timing checks, consent modes and the text hash, custom fields, karaoke interest (M3), how-heard, and `?s`/`?r` attribution;
   - cancel, manage, opt-out, wants-visit, request link and claim link.
4. **SMS** (§16.1, §16.3, §16.5):
   - add `{{link}}` to `sms_render()` (§21.2);
   - send single-recipient SMS Studio campaigns for `waitlist_promotion` and `link_on_demand`, with `se_message_runs` run keys.
5. **Public API** (§12.2): `time`, `bootstrap`, `lookup` (register purpose), `register`, `wants_visit`, `request_link`, `claim_link`, `me` (registration fields), `cancel`, `optout`, `card` (`im_going`), `beacon`. Apply the §12.1 rate limits.
6. **Portal UI** (§13.1, §13.3–§13.5):
   - the Marquee portal: a server-rendered hero with GSAP motion, chapters, venue, FAQ and a phase-aware sticky CTA;
   - the registration sheet;
   - the success screen: confetti, ticket, save link, add to calendar;
   - the manage page `/me/<token>` and the privacy view;
   - OG and Twitter meta.

   It must meet the §13.14 accessibility rules and the §13.15 budgets.
7. **Share cards** (§14.3, §14.4):
   - the SVG template engine in `@se/core`: tokens, text fitting, conditional groups, QR, font embedding, rasterising, share or download;
   - the "I'm going" template with `PhotoCircleCropper`. The photo never leaves the device.
8. **Studio**:
   - the Attendees tab: every action in the §12.5 Attendees row, including erase and the Excel export (§18.6);
   - Overview KPIs;
   - the Share kit: a link and QR for each source code (§18.2);
   - the AI copywriting helper for portal text (§15.8).
9. **Tests**:
   - unit: phone (PHP and JS sharing at least 40 fixtures), display name, table-driven capacity state, SMS templates, SVG tokens (JS);
   - integration (§22.2): the seat race, and cancel with promotion.

**Acceptance** (§25 Phase A):
- A guest registers in 30 s or less on a mid-range Android.
- A member is recognised by phone.
- The seat race confirms exactly the capacity.
- Waitlist, self-cancel, seats-left and auto-close behave as configured.
- The manage link works on a second device.
- `request_link` never reveals whether a number is registered.
- The link preview shows the OG card.
- The Lighthouse targets (§13.15) are met.
- The "I'm going" card renders with and without a photo.

**Docs.** In `how_to_use.md`: registration, attendees, share kit, and how to open registration. Update §28.3 and §28.4.

---

## PR3 — Check-in, teams & the live backbone

**Goal.** Everything the doors and the room need on the night:
- poster-QR check-in: self, walk-in and desk-assisted;
- balanced teams with hex colours and player numbers;
- the welcome-verse card;
- live snapshots with a heartbeat;
- the lobby TV, the stage display and the host console (scenes, announcements, sound);
- the printable check-in posters.

**Needs.** PR2.

**Read.**
- Journeys: §7.4–§7.8.
- Architecture: §8.4–§8.5 in full.
- Check-in, teams and verses: §10.5, §10.6, §10.9, §11.12.
- Public API (§12.2): `checkin`, `transfer`, the `lookup` check-in purpose.
- Display API: §12.3.
- Live API (§12.4): `console`, `scene`, `announce`, `sound`, `team_*`, `desk_*`, `publish_now`.
- Front-end: §13.1.6, §13.6, §13.8–§13.11.
- Posters, AI verses and the Bible lookup: §14.2 (posters), §15.7, §15.9.
- Live monitor and existing-file changes: §18.3, §21.3, §21.4.
- Appendices: B, H.2 (check-in, live), H.4.

**Build.**
1. **Migrations** A.5 (`se_checkin_teams`) and A.7 (`se_live_state`).
2. **Live backbone** (§8.5):
   - `live.php`:
     - the state, scenes and snapshot builders (Appendix B);
     - a debounced, atomic publish (tempnam + rename) to `/live/<public_id>/`;
     - versions, and the tick heartbeat with `GET_LOCK`;
   - `realtime.php`: the driver interface, the poll driver, and an Ably stub behind `SE_REALTIME_DRIVER`;
   - client polling with ETag/304, adaptive intervals, clock sync and the elapsed clamp;
   - `live/.htaccess`, `live/.keep`, and the `.gitignore` entries (§21.3).
3. **Display API** (§12.3), with display keys and one-click rotation. The links appear in Studio → Crew and Overview.
4. **Check-in** (§10.5, §13.6):
   - `/in`: the window countdown, the check-in lookup, confirm, the walk-in short form and the gender prompt;
   - the reveal animation and the welcome card;
   - already checked in: on the same device, or on another device, which becomes read-only;
   - transfer codes and undo, with walk-in pools enforced by PR2's capacity engine.
5. **Teams** (§10.6):
   - assignment under the event lock: size → gender → member/guest mix → round-robin pointer;
   - player numbers, moves with a reason, captains, live renaming;
   - the Studio Teams tab: paste hex codes, labels, names, warnings (including the ring for low contrast), roster;
   - the team-count lock.
6. **Verses** (§10.9, §15.7, §15.9): `bible.php` (KJV lookup with cache), and Studio verses: AI suggestions → fetched text → approve.
7. **Desk mode** (§13.11): search, check-in on behalf, walk-in, transfer code, undo, and an offline queue that syncs later.
8. **Displays**:
   - the lobby display (§13.9);
   - the stage's core scenes (§13.8): start overlay, standby, welcome, teams, announcement, break, blank, with leaderboard and recap placeholders;
   - the SFX sprite (§13.1.6).
9. **Host console** (§13.10): the show tab (scenes, announcements, sound board, `publish_now`), team naming and captains, health dots, and `STALE_VERSION` handling.
10. **Crew entry pages** `/host` and `/desk`, plus the `/dj` shell for PR4: ERP login with `?next=` (§21.4), honouring `must_change_password`.
11. **Check-in posters**: the `qr_poster_a4` and `qr_poster_a3` templates (QR → `/e/<slug>/in`), rendered by the PR2 template engine, with a print PDF via dompdf and a Studio Overview link.
12. **Studio → Live monitor** (§18.3), and the Welcome-verse and Team share cards (§14.3).
13. **Test mode and Reset rehearsal** (§11.13) for the tables that exist so far:
    - the `test_mode` live action (producer), with the toggle in Studio → Live and the host console, and the red TEST MODE ribbon;
    - crew devices may check in outside the window, and registrations and check-ins created in test mode get `is_test = 1`;
    - reset deletes those rows (and contacts created only by test registrations), and applies the counter-reset rule;
    - the tick switches test mode off 15 minutes before doors open.

    Later PRs extend the reset to their own tables.
14. Extend clone to teams and verses.
15. **Tests**:
    - unit: team algorithm (I1–I3 over 100,000 random sequences), timing (clamp), snapshot privacy, preload;
    - integration: the check-in race (§22.2).

**Acceptance.**
- The check-in race passes.
- In a 60-person rehearsal, team sizes and gender counts stay within ±1.
- The lobby animates arrivals.
- Host actions reach phones in 1.5 s or less (p95), and snapshot age stays under 3 s.
- Two consoles acting at once get `STALE_VERSION`, and it is handled.
- `public.json` never contains names.
- The posters print correctly at A4 and A3.
- A rehearsal in test mode leaves no trace after Reset rehearsal.

**Docs.** In `how_to_use.md`: check-in, desk mode, teams, lobby, stage, host console, posters, test mode. Update §28.3 and §28.4.

---

## PR4 — Programme, karaoke & reminders

**Goal.**
- The run-of-show, entered by hand or imported with AI, with live ETAs.
- The karaoke flow from pre-pick to the DJ console.
- Automatic reminder SMS with the module cron.
- The Format Studio.

**Needs.** PR3.

**Read.**
- Journeys: §7.1 (programme and karaoke steps).
- Programme and karaoke logic: §10.7, §10.8.
- Public API (§12.2): `songs`, `pick_song`, `release_song`.
- Live API (§12.4): `program*`, `karaoke*`.
- Studio API (§12.5): the Days & programme, Karaoke and Messages rows, and `render_save`.
- Front-end: §13.10 (run-of-show), §13.12.
- Format Studio and AI: §14.2, §15.3, §15.5.
- Messaging and cron: §16 in full, §23.4.
- Appendices: D.2, D.3, E (messages, karaoke), H.2 (karaoke, messages).

**Build.**
1. **Migration** A.6 (`se_program_karaoke`).
2. **Programme** (§10.7):
   - the builder: reorder, durations, explicit start times, public/featured flags, links to games and karaoke;
   - AI import from text, a screenshot or a PDF (§15.3), with review and then apply (replace or append);
   - the ETA engine (planned plus live drift);
   - the console run-of-show: start, finish, skip, undo, move;
   - the stage programme scene, the portal programme chapter, and now/next in `public.json`.
3. **Karaoke** (§10.8):
   - the global library (normalised and unique);
   - import by paste or CSV, with AI for images (§15.5);
   - per-event activation and publishing the list;
   - pre-pick holds on the portal and manage page with unique songs, and hold release;
   - queue numbers at check-in, hooked into PR3's check-in;
   - the DJ console (§13.12), the stage karaoke scene, and the "up next" alert in `me`.
4. **Messages** (§16):
   - `reminder_1` and `reminder_2`, including the multi-day rules;
   - the Messages tab: preview with GSM-7 cleaning and segment count, estimated units, test send, and a run log linking to SMS Studio;
   - ad-hoc messages (§16.6) and the max-lateness skip.
5. **Cron** `cron/special_events.php` (§23.4):
   - CLI only, sets `DOCUMENT_ROOT`, holds `GET_LOCK('se_cron')`, writes `cron_last_run`;
   - jobs: message runs, karaoke hold releases, token/device/rate-limit cleanup, AI source purge;
   - document the crontab line in the README.
6. **Format Studio** (§14.2):
   - the built-in templates: `wa_status`, `ig_square`, `ig_portrait`, `og_card`, `projector`, `table_tent`;
   - Render all, zip download (JSZip in the Studio only), set as OG, use on stage;
   - sanitised custom templates with their tokens listed.

   It is on the cut line: build it last, and if time runs out, defer it and log that in §28.4.
7. Extend clone to the programme and songs, and extend Reset rehearsal to karaoke entries. Delete karaoke rows before registrations (§9.5).
8. **Tests**:
   - unit: ETA, SMS templates for reminders;
   - integration: the karaoke race (10 parallel picks → 1 success), and cron idempotency (run twice → one run).

**Acceptance.**
- Importing a screenshot gives a reviewable table, which applies correctly.
- ETA drift shows on the console and the portal.
- The unique-song race, hold release, queue numbering, DJ statuses and the up-next alert all work.
- Reminders are created once and sent through SMS Studio.
- The Format Studio renders every size with the right fonts and QR codes.

**Docs.** In `how_to_use.md`: programme, karaoke, messages, Format Studio, and the cron line. Update §28.3 and §28.4.

---

## PR5 — Games I: engine, decks & quiz games

**Goal.**
- The game engine and the `/play` portal.
- Decks: manual, AI with KJV, and review.
- Live Quiz, Bible Trivia (captain mode) and the Buzzer family.
- The score ledger and awards, test mode, and the load test.

**Needs.** PR4.

**Read.**
- Journeys and timing: §7.8, §8.5 (scheduled reveal, clamp).
- Games: §11.1–§11.7, §11.11 (leaderboards), §11.13, §11.14.
- Public API (§12.2): `join_games`, `answer`, `suggest`, `buzz`, `me.round`.
- Live API (§12.4): `game_*`, `round_*`, `captain_set`, `buzz_judge`, `score_*`, `test_mode`.
- Studio API: §12.5 (Games).
- Front-end: §13.7, §13.8 (game scenes), §13.10 (game tab).
- AI, Bible lookup and load test: §15.4, §15.9, §22.4.
- Appendices: B, C, D.4, E (games), H.2 (games).

**Build.**
1. **Migration** A.8 (`se_games`).
2. **Decks**:
   - library and event decks;
   - editors for `mcq`, `open`, `emoji` and `verse` (the `clues`, `charade` and `survey` editors can be basic now; PR6 finishes them);
   - AI generation (§15.4) with review, KJV enrichment (§15.9), and usage counters to avoid repeats.
3. **Games setup**: games per event, items, timers, points and weights (§11.14), and the programme link.
4. **Engine** (§11.2, §11.3):
   - the round state machine, a scheduled reveal with preroll, the eligibility snapshot at arm, the elapsed clamp, and the common errors;
   - the console game tab with private data;
   - stage game scenes: question, countdown, distribution, reveal, leaderboard;
   - `/play` (§13.7) with lazy-loaded game views.
5. **Game types**:
   - Live Quiz (§11.4);
   - Bible Trivia with captains and suggestions (§11.5);
   - Buzzer family (§11.7): Bible Buzzer, Finish the Verse, Emoji Bible, with buzz order by effective time and judging.
6. **Scoring** (§11.6):
   - the ledger with idempotency keys, the Kahoot-style formula, team normalisation and weights;
   - voids, and awards/penalties (`score_adjust`, `score_void`) in the host console;
   - team and individual leaderboards in the snapshots.
7. **Test mode for games** (§11.13):
   - extend Reset rehearsal to rounds, answers, buzzes and the ledger (void the `TEST` rows);
   - add the Studio → Games → **Rehearse** entry;
   - let the cron also switch test mode off 15 minutes before doors open (PR3's tick already does).
8. **Load test** `tests/special_events/load/quiz.k6.js` (§22.4).
9. Extend clone to games, with library decks reused.
10. **Tests**:
    - unit: scoring, timing (buzz order), schema (deck payloads, Appendix C);
    - integration: scoring idempotency.

**Acceptance.**
- Each game type in this PR runs end-to-end in test mode.
- Concurrent `round_score` calls write one set of ledger rows.
- Voids, awards and penalties work, and the leaderboards are correct.
- The load test passes the §22.4 thresholds.
- Reset rehearsal removes all test data.

**Docs.** In `how_to_use.md`: decks, running each game, scoring, test mode. Update §28.3 and §28.4.

---

## PR6 — Games II: party games & the finale

**Goal.** Who Am I?, Bible Charades and Bible Family Feud, then leaderboards, MVPs, awards and the finale. At the end of this PR the event is ready for the dress rehearsal.

**Needs.** PR5.

**Read.**
- Games: §11.8–§11.12.
- Public API (§12.2): `survey`, `me.presenter`.
- Live API (§12.4): `charades_*`, `clue_next`, `feud_*`.
- Front-end: §13.7, §13.8, §13.10.
- AI clustering: §15.6.
- Appendices: C (clues, charade, survey), D.5, G, H.2, H.3.

**Build.**
1. **Who Am I?** (§11.8): clue progression and clue points.
2. **Charades** (§11.9):
   - the presenter is picked by player number or at random;
   - the phrase appears only on the presenter's phone (`me.presenter`);
   - timer, correct/pass marking, team turns.
3. **Family Feud** (§11.10):
   - survey collection on the portal and manage page ("play ahead", the `survey` action);
   - AI clustering (§15.6) with a manual fallback, then board approval;
   - face-off, control, reveal, strikes, steal, and bank with multipliers;
   - the stage board.
4. **Leaderboards, MVP and awards** (§11.11), the finale scene, and the recap data PR7 will use.
5. **Chara starter content** (Appendix G), the clue/charade/survey deck editors, and Reset rehearsal for survey responses.
6. **Dress-rehearsal pass**: run H.3 in test mode, fix what breaks, and log the fixes in §28.4.

   Optional if time allows: the service worker (§8.6.1, cut line item 4).
7. **Tests**:
   - unit: scoring (Who Am I? clue points, Feud bank with multipliers);
   - privacy: the charades phrase never appears in any snapshot.

**Acceptance.**
- Every game type runs end-to-end.
- The Feud board is built from real survey answers.
- The MVP is correct.
- The finale runs.
- The H.3 checklist passes.

**Docs.** In `how_to_use.md`: the three games, the finale, and the rehearsal checklist. Update §28.3 and §28.4.

---

## PR7 — After the event

**Goal.** Close the loop:
- a thank-you SMS with the recap and feedback;
- insights and reports;
- the hand-off wizard to Reach and Embrace;
- archive, slug reclaim and retention.

**Needs.** PR6. It can start alongside PR6 once PR5 is merged.

**Read.**
- Journeys and lifecycle: §7.9, §10.1, §10.2 (archive, reclaim).
- Share card, AI narrative and SMS: §14.3 (My Night), §15.10, §16.2 (`thank_you`).
- Hand-off, insights and privacy: §17 and §18 in full, §19.8 (retention, rights).
- Existing-file note: §21.7 (Reach how-to).
- Appendices: A.9, A.10, D.8, H.5.

**Build.**
1. **Migrations** A.9 (`se_post_event`) and A.10 (`se_reach_campaign_type`).
2. **Thank-you and feedback**:
   - the `thank_you` message kind, sent by cron;
   - the recap (`#recap`) with the My Night card;
   - feedback: `#feedback`, the `feedback` action, NPS.
3. **Insights** (§18.1, §18.4, §18.7):
   - the dashboard: Chart.js, with a table view for every chart;
   - series insights and the Hall of Fame;
   - follow-up attendance only through `assim_attendance_union_sql()`.
4. **Reports**: the PDF (§18.5), using dompdf with the AI narrative and its fallback, and the full Excel export (§18.6).
5. **Hand-off wizard** (§17):
   - rules, a preview with overrides, and batched pushes;
   - a Reach campaign of type `Special_Event` with its leads;
   - Embrace first-timers, using the §17.4 mapping and notifications;
   - idempotency, history and re-runs;
   - the Reach how-to courtesy note (§21.7).
6. **Archive and retention**: archive (freeze), slug reclaim (§10.2), and the retention anonymisation and cleanup jobs in cron (§19.8). Verify the erasure path.
7. **Tests**:
   - integration: a hand-off re-run creates nothing new;
   - the PDF contains no PII;
   - insight numbers match direct SQL counts.

**Acceptance** (§25 Phase D):
- The thank-you SMS goes out the next morning.
- The report is generated.
- The hand-off creates the Reach campaign and leads, and the Embrace first-timers, with zero duplicates on a re-run.
- The insight numbers match the database.

**Docs.** In `how_to_use.md`: after the event, reports, hand-off, archive. Update §28.3 and §28.4.
