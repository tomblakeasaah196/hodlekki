# AGENTS.md — guide for AI coding agents working on hodlekki

**Owner:** Tom-Blake Asaah (asah.tomf@gmail.com).
**Repository:** https://github.com/tomblakeasaah196/hodlekki
**Production host:** cPanel shared host, PHP 8.3 (ea-php83), LiteSpeed.

## Project overview

Household of David Lekki Centre's Church Management System with a public
website. It runs departments, finance, an LMS, and structured new-converts
follow-up on a plain PHP 8.3 + MySQL stack.

## Repo layout

| Folder            | Purpose                                                                  |
| ----------------- | ------------------------------------------------------------------------ |
| `api/`            | JSON endpoints. One `<name>_api.php` per module. Session-guarded.        |
| `assets/`         | Static CSS/JS/images. `assets/uploads/` is runtime media, git-ignored.   |
| `auth/`           | Login, logout, first-time password setup.                                |
| `cron/`           | CLI-only long-running jobs (currently just `sms_queue_worker.php`).      |
| `includes/`       | Shared PHP: `db.php`, `header.php`, `functions.php`, PDF helpers, SMS.   |
| `modules/`        | ERP screens. Each has `index.php` requiring `../../includes/header.php`. |
| `uploads/`        | Member uploads (audio, images, PDFs). Git-ignored.                       |
| `vendor/`         | Composer packages. Git-ignored.                                          |
| Root `*.php`      | Public entry points and cPanel config files (`php.ini`, `.htaccess`).    |

## Coding conventions (mirror what already exists)

- **Indentation:** 4 spaces, LF line endings, no trailing whitespace.
- **File header:** every PHP file starts with a `// /path/to/file.php`
  comment on line 2 identifying itself.
- **Database access:** PDO only. Prepared statements are mandatory
  (`PDO::ATTR_EMULATE_PREPARES = false` is set globally in
  `includes/db.php`). Never build SQL with string concatenation of user
  input; never call `mysql_*` or `mysqli_*` — they are not used anywhere.
- **Session bootstrap:** files that touch `$_SESSION` guard with
  `if (session_status() === PHP_SESSION_NONE) session_start();`.
- **Auth pattern:**
  - Page views (`modules/*/index.php`, root pages) require
    `includes/header.php`, which redirects unauthenticated users to
    `auth_login_url_for_request()` — `/auth/login.php?next=<this page>` — so
    they land back on it after signing in (see "Sign-in" below). A page that
    gates itself before header.php must redirect the same way.
  - API endpoints (`api/*_api.php`) require `../includes/db.php`, set
    `Content-Type: application/json`, then check `$_SESSION['user_id']`
    and often `$_SESSION['active_role']` + department membership. See
    `api/reach_api.php` for the canonical shape.
  - **`includes/db.php` runs `security_enforce_session($pdo)` on every
    non-CLI request.** If the account has been suspended/revoked, the
    session individually revoked, or "sign out everywhere" pressed, it
    silently destroys the session — it never redirects or prints, so the
    existing `!isset($_SESSION['user_id'])` guard in each page/endpoint
    does the rest. Do not add output or redirects to that function.
- **RBAC roles** (values of `$_SESSION['active_role']`):
  `Super_Admin`, `Resident_Pastor`, `Assoc_Pastor`, `Church_Member`, plus
  department-based clearance via the `user_departments` table.
- **JSON responses:** `echo json_encode(['status' => 'success'|'error', 'message' => ..., 'data' => ...])`.
- **HTML escaping:** always `htmlspecialchars()` when echoing session or
  DB values into markup (see `includes/header.php:16-18`).
- **File naming:** snake_case for PHP files, `<module>_api.php` for
  endpoints, `<Module>` PascalCase not used.
- **Routing:** none. It is file-per-URL — no framework, no router, no
  autoloader beyond Composer's for `vendor/`.
- **Front-end:** Tailwind via CDN in most pages, custom colours
  `hodBlue: #0A0E17` and `hodRed: #D11920`. jQuery for AJAX, Toastify
  for notifications. Do not introduce React / Vue / Alpine unless asked.
- **Timezone:** `Africa/Lagos` at PHP level, `+01:00` at MySQL level,
  both set in `includes/db.php`.

## How to run locally

- **Serve the site:** point Apache / XAMPP / LiteSpeed at the project
  root so `http://localhost/` resolves to `index.php`. On XAMPP, put the
  folder in `htdocs/`.
- **Tail the error log:**
  ```powershell
  Get-Content includes/error_log -Wait -Tail 50
  ```
  (On the production cPanel host it's the same path, and cPanel also
  surfaces it under Metrics → Errors.)
- **Run the SMS cron once for testing:**
  ```bash
  php cron/sms_queue_worker.php
  ```
  It refuses to run under a web SAPI (`PHP_SAPI !== 'cli'` → 403).
- **Tests:** CI runs `php -l` over the whole tree and the Special Events suite
  (`php tests/special_events/run.php`). The Assimilation Excel export has its
  own suite — run `php tests/assimilation_export_test.php` whenever you touch
  `includes/assimilation_export_excel.php`; its workbook half needs
  `composer install` and reports itself as skipped without it. The PWA/push
  suite is `php tests/pwa/run.php` (no database, no network) — run it after
  touching `includes/push_*.php`, `includes/pwa_*.php`, `manifest.php` or
  `sw.js`. (It is not in the workflow yet — but see the note below: an agent
  CAN add the step itself now.) The Sunday ladder suite is
  `php tests/status_ladder_test.php` (SQLite, in CI) — run it whenever you touch
  `includes/status_ladder.php`, `embrace_pipeline_sql()`, or anything that
  writes or reports `users.spiritual_status`. `php tests/dashboard_metrics_test.php`
  and `php tests/auth_helpers_test.php` (SQLite, in CI) cover the home
  dashboard's numbers and the sign-in helpers — run them after touching
  `includes/dashboard_metrics.php`, `includes/auth_helpers.php`,
  `api/auth_api.php` or `assim_attendance_union_sql()`. On a machine with no PHP at all,
  `python3 tests/php_structure_check.py <file>` still catches unbalanced
  brackets and unterminated strings. There is no browser/UI test suite, so
  always smoke the page you touched by hand. Touching Charis AWOL Monitoring
  means running `php tests/charis_awol_engine_test.php` (see the contract
  below) as well as `python3 tests/charis_awol_test.py`.

## Charis AWOL Monitoring contract

AWOL detection counts a **consecutive-absence streak**, never a total inside
a rolling window. The distinction is the whole point of the feature:

- A member's counter is anchored on the last day they were actually present.
  **Any attendance resets it to zero.** An absence that happened *before* the
  last visit must never count — that bug had a member standing in church on
  4 October still reading "3 MISSED".
- Service types are **asymmetric**, configured per service in
  `charis_awol_service_rules()`: Sunday absence advances the counter, midweek
  absence never does, and midweek *presence* still clears it. Members travel
  far and miss midweek for work; flagging them for it buried the real cases.
  Do not "simplify" this into one list of qualifying services.
- A member who has never attended is anchored on their registration date, so
  new members get their grace period for free. No config field exists for it
  and none should be added.
- A service nobody attended did not hold: it is **skipped**, advancing and
  resetting nothing, and logged to `charis_awol_skipped_services`.
- Returning closes any open case automatically and writes a system-authored
  note (`charis_welfare_notes.is_system = 1`, `author_id` NULL). Workers file
  no paperwork; the trace lives in the archive.

- Leaving the list permanently is the `users.awol_exempt` flag, not a status.
  Exclusion used to be inferred from `attendance_status`, which the Events
  profile editor, the Congregation editor and Embrace all overwrite, so a
  member marked "Attends Another Church" silently returned to the list the
  next time anyone saved their profile. `charis_set_awol_exemption()` is the
  only place the flag is written; `charis_should_keep_attendance_status()`
  stops the other screens clobbering a departed member's status. Do not
  reintroduce an exclusion that depends only on `attendance_status`.
- `Unknown` means hard to reach, not departed: it never grants an exemption.

`tests/charis_awol_engine_test.php` executes the engine against in-memory
SQLite and pins every one of these. `tests/charis_awol_test.py` is static
string-matching only — it passed happily while the window bug was live, so
never treat it as proof the engine behaves.

## NEVER-TOUCH list

- `uploads/` — real member uploads, sermon audio, event banners. Not in
  git; never rewrite, delete, or commit.
- `vendor/` — Composer output. Regenerate with `composer install`.
- `.env` — production/local secrets. Never read its values back to the
  user in chat; never commit; never echo into logs.
- The live production database (`smartqaq_hodlc`). Read-only queries at
  most, and only when the user explicitly asks.
- Do not embed credentials, API keys, or vault keys directly in PHP. All
  secrets go through `$_ENV[...]` (loaded by `includes/db.php`).
- Do not re-add `api/june28th.php` (deleted 2026-09-26; contained real
  member PII from a one-time import). If a similar import script is
  needed, keep it out of git or stub it before committing.

## Where new features go

- **New public page:** add a top-level `foo.php` at the project root,
  following the pattern of `sermons.php` / `testimonies.php`. Guard with
  session logic only if it's members-only.
- **New ERP screen:** `modules/<name>/index.php`, must require
  `../../includes/header.php` on line 3.
- **New JSON endpoint:** `api/<name>_api.php`, following the auth gate
  pattern from `api/reach_api.php`.
- **New DB tables / columns:** add a file to `db/migrations/` named
  `YYYYMMDDhhmmss_<slug>.sql`. It runs automatically on the next deploy
  via `db/migrate.php`. Rules:
  - Forward-only. To reverse a change, ship another forward migration.
  - Plain `;`-terminated SQL. No `DELIMITER` blocks (PDO::exec can't
    parse them — split stored procedures across files if needed).
  - Each file runs inside a transaction, but MySQL auto-commits DDL, so
    a file failing mid-DDL stays half-applied. Any failure stops the deploy.
  - Prefer idempotent DDL (`CREATE TABLE IF NOT EXISTS`,
    `INSERT ... ON DUPLICATE KEY UPDATE`).
  - Do NOT edit or delete a migration file that has already been
    applied in production — it's tracked by filename in the
    `schema_migrations` table.
  - See `db/migrations/README.md` for examples.
- **Reach module changes MUST update `modules/reach/how_to_use.md`**
  in the same PR. Any diff touching `modules/reach/*.php`,
  `api/reach_*.php`, or `reach.php` without a diff to `how_to_use.md`
  fails CI (the first step of the lint job in `.github/workflows/deploy.yml`).
- **Assimilation module changes MUST update
  `modules/assimilation/how_to_use.md`** in the same PR, enforced by the
  same lint job. Any diff touching `modules/assimilation/*`,
  `api/assimilation_*.php` or `assimilation.php` without a diff to
  `how_to_use.md` fails CI.
- **New Reach / Embrace features:** extend `modules/reach/` and
  `modules/embrace/` respectively; both already have their own
  department-based clearance checks in the matching API file.
- **Anything that asks "was this person in church that day?"** must go
  through `assim_attendance_union_sql()` in
  `includes/assimilation_helpers.php` — the de-duplicated union of
  `checkins` and `attendance`, one row per person per calendar day. Do not
  query either table directly for attendance history; the two disagree on
  their own.
- **New cron job:** `cron/<name>.php`, CLI-only guard, and document the
  crontab line in the README.

## Deployment (agents: know this before you push)

- Every push to `main` triggers `.github/workflows/deploy.yml`:
  1. PHP lint over the whole tree (`php -l`).
  2. Secret sweep (`define('SMS_VAULT_KEY', ...)`, hardcoded `DB_PASS=`,
     or a tracked `.env` all fail the run).
  3. POST to the deploy webhook at
     `https://hodlc.lpc.cm/webhook/deploy.php` with header
     `X-Deploy-Token: <DEPLOY_WEBHOOK_SECRET>`. Output streams back
     into the Actions log.
- **Single source of truth: [bin/deploy.sh](bin/deploy.sh).** Both the
  webhook and cPanel's manual "Deploy HEAD Commit" invoke it. The
  script:
  1. `git fetch origin main && git reset --hard origin/main` in
     `/home/smartqaq/repositories/hodlekki`.
  2. Copy repo → `/home/smartqaq/public_html/hodlc.lpc.cm/` by streaming
     through **`tar`** (rsync is not installed on this host), applying
     `--exclude-from=.deployignore` so `.env`, root `.htaccess`,
     `.user.ini`, `php.ini`, `uploads/`, `assets/uploads/`, and
     `error_log` files survive. Nested `.htaccess` files (e.g.
     `webhook/.htaccess`) DO deploy, because the docroot entry is
     written root-anchored as `./.htaccess`.
     **The copy never deletes.** A file removed or renamed in the repo
     lingers in the docroot until someone deletes it by hand.
     `.deployignore` is read by tar, not rsync: root-only entries are
     written `./name`, bare names match at any depth, and a pattern that
     starts or ends with `/` is silently ignored. The lint job's
     "Deploy exclusions must hold under tar" step enforces this — read
     the comment at the top of `.deployignore` before editing it.
  3. `composer install --no-dev --optimize-autoloader` using
     `/home/smartqaq/composer.phar` (needs `-d allow_url_fopen=On`
     because CLI php.ini disables it on this host).
  4. `php db/migrate.php` — applies pending SQL migrations.
- **Concurrency:** `bin/deploy.sh` holds `/home/smartqaq/.deploy.lock`
  via `flock -n`, so overlapping deploys refuse rather than collide.
- **Full log** of every deploy is appended to
  `/home/smartqaq/deploy.log`. Useful when Actions times out or the
  caller disconnected mid-run.
- **Webhook security:** POST-only, `X-Deploy-Token` compared with
  `hash_equals()`, rejected requests logged to PHP's `error_log`.
  `webhook/.htaccess` allows only `deploy.php` in that directory.
- **Don't edit `bin/deploy.sh` or `webhook/deploy.php` casually.** They
  run unattended in prod. Test any change by triggering a manual deploy
  from cPanel first (Git Version Control → Manage → Deploy HEAD Commit).
- **Emergency manual deploy:** cPanel → Git Version Control → Manage
  → Pull or Deploy → `Update from Remote`, then `Deploy HEAD Commit`.
  Or from cPanel → Terminal:
  `bash /home/smartqaq/repositories/hodlekki/bin/deploy.sh`.
- **`.github/workflows/` CAN be pushed by an agent** (verified 2026-10-17: the
  GitHub App has `workflows` permission). Earlier notes in this file said it
  could not and told the owner to add CI steps by hand — that was true once and
  is not any more. So when a module arrives with a test suite, add the step in
  the same pull request rather than handing it back. Two suites are still
  missing a step for that reason: `tests/pwa/run.php` and
  `tests/assimilation_export_test.php`.
- **The deployed copy of this workflow is a fossil — do not read it.**
  `.deployignore` excludes `./.github`, and `bin/deploy.sh` never deletes, so
  `/public_html/hodlc.lpc.cm/.github/workflows/deploy.yml` is frozen at
  whatever was there before that exclusion was added. It is missing whole jobs.
  The file in git is the only one that runs; editing from the docroot copy
  silently deletes everything added since.
- Full pipeline docs, secrets/vars list, rollback: `DEPLOY.md`.

## PR conventions

- Base branch: `main`.
- Branch names: `feature/<slug>`, `fix/<slug>`, `chore/<slug>`, or
  `claude/<slug>` for AI-generated branches.
- Commit style: short imperative subject line, optional body explaining
  the *why*. Examples:
  - `feat: add embrace SMS blast to 1st timers`
  - `fix: correct pastor visitation filter in reach_api`
  - `chore: bump dompdf to 3.1.1`
- PR description: what changed, why, screenshots for UI, and manual
  smoke-test steps. There is no PR template file yet — feel free to add
  one under `.github/PULL_REQUEST_TEMPLATE.md`.
- Do not open a PR that pushes secrets, member uploads, `.env`, or SQL
  dumps. Run `git status` before every commit.

## Account security (added 2026-09-30)

Schema: `db/migrations/20261005090000_security_core.sql`.
Helpers: `includes/security_helpers.php` (every function no-ops safely if
that migration has not been applied yet — the tree is rsynced to prod
*before* `php db/migrate.php` runs, so nothing may hard-fail in that gap).

- **`users` columns:** `account_status` (`active` / `suspended` /
  `revoked`), `status_reason`, `status_changed_at/by`,
  `must_change_password`, `password_changed_at`, `sessions_valid_from`,
  `last_login_at/ip`, `failed_login_count`, `locked_until`.
- **New tables:** `security_audit_log`, `user_sessions` (keyed by
  `sha256(session_id)` — never store the raw session id), `login_attempts`.
- **Password policy lives in ONE place:** `security_password_problems()`.
  Every entry point calls it (self-service change, admin reset, first-time
  setup). Do not re-implement length/complexity checks inline.
- **Never write `users.password_hash` without also** setting
  `password_changed_at`, clearing `failed_login_count` / `locked_until`,
  and calling `security_revoke_sessions()`. A credential change must kill
  existing sessions.
- **Revoking freezes roles** (`user_roles.is_frozen = 1`) and
  `api/auth_api.php` excludes frozen rows when building
  `$_SESSION['roles']`. That flag used to be dead — `roles_api.php`
  wrote it and nothing read it. If you touch role loading, keep the
  `COALESCE(ur.is_frozen, 0) = 0` filter.
- **Guard rails** are server-side in `security_guard_target()`: no acting
  on yourself, a `Resident_Pastor` cannot act on a `Super_Admin`, and the
  last active `Super_Admin` cannot be suspended or revoked. Reuse it for
  any new privileged action rather than re-checking by hand.
- **`/auth/setup_password.php` is FIRST-TIME ONLY.** It is public, so it
  refuses any account whose `password_hash` is already set, and is
  rate-limited per IP. Do not loosen this — before 2026-09-30 anyone who
  knew a member's first name and phone number could take over their
  account from the open internet.
- **`/auth/change_password.php` must never include `includes/header.php`.**
  header.php redirects to it whenever `must_change_password` is set;
  including header.php there would be an infinite redirect loop.
- Security Centre changes should update `modules/security/how_to_use.md`
  (same courtesy as Reach / Assimilation, though CI does not enforce it
  for this module yet).

## Sign-in: "return to" and "stay signed in" (added 2026-10-22)

Helpers: `includes/auth_helpers.php`. Schema:
`db/migrations/20261124090000_auth_remember_tokens.sql`. Tests:
`tests/auth_helpers_test.php`. A tapped push notification used to end on the
dashboard: header.php dropped the page, and PHP's session dies after ~24 idle
minutes (`php.ini`, which the deploy never copies), so nearly every tap met the
login screen.

- **"Return to" goes through `auth_safe_next()` and nothing else.** It accepts
  only a same-origin screen (`/modules/<name>/…`, `/e/<slug>…`, or a root
  `*.php` other than `/index.php`) and refuses `//`, backslashes, dot segments
  and anything under `/api/` or `/auth/`. It is the open-redirect guard: never
  redirect to a `next` that has not been through it. `/index.php` returns null
  on purpose so the role-based landing in `auth_api.php` still applies.
  `login.php` stores it in `$_SESSION['post_login_next']` (and clears a stale
  one when visited without it); `auth_api.php` re-checks and consumes it and
  answers `returning: true` so the page re-attaches the URL `#fragment`, which
  the server never sees. A forced password change leaves it for
  `change_password.php`.
- **"Keep me signed in on this device for 30 days"** is ticked by default. The
  cookie `hod_remember` is `selector:validator`; only sha256(validator) is
  stored. `auth_remember_boot()` runs in `includes/db.php` just BEFORE
  `security_enforce_session()`, so a resumed session is vetted like any other.
  That is why `includes/header.php` now requires `db.php` before its "signed
  in?" check — do not move the check back above it.
- **The token answers to the Security Centre.** `security_revoke_sessions()`
  deletes tokens (keeping only the browser `$exceptHash` names); the
  single-device revoke in `api/security_api.php` deletes that device's token;
  `auth/logout.php` deletes the current one; and `auth_remember_resume()`
  re-checks account status, `sessions_valid_from` and whether the device's
  `user_sessions` row was revoked. Any NEW way of ending a session must end the
  token too, or the device signs itself straight back in.
- **One `user_sessions` row per device.** A resume moves the device's row to
  the new session id instead of inserting another, and `auth_remember_sync()`
  keeps the token pointed at the live session id. Revoking that row is
  revoking the device.
- **Session data is built in one place:** `auth_start_session()`, used by both
  the password sign-in and a resume. Keep the `is_frozen` filter in
  `auth_load_roles()`.

## Home dashboard numbers (added 2026-10-22)

`includes/dashboard_metrics.php`, served by `api/dashboard_api.php` (summary,
plus `?action=detail&card=…` for the card modals; a card outside the member's
tier is refused). Tests: `tests/dashboard_metrics_test.php`. Each card is the
SAME number the module it summarises shows, asked the same way:

- **Workforce** = Departments' live headcount (`is_active = 1 AND
  roster_status = 'Active'`, distinct people) — not the `Worker` label.
- **New Pipeline** = `embrace_pipeline_sql()`; **Follow-ups Due** = people on
  it whose LATEST follow-up is not final.
- **Sunday** = distinct people on the latest `Sunday_Service` day ≤ today:
  `assim_attendance_union_sql()` for people with a record (the helper grew an
  optional `$dates` filter for this) plus QR check-ins with no `user_id`, one
  per phone. On a Sunday it is today's service, refreshed every minute, with
  no "vs last week" change until the day is over.
- **Unread Alerts** = `push_unread_count()`, the bell's own count.

`modules/dashboard/index.php` is an older copy of the dashboard that still
reads the summary's `metrics` keys; keep those keys when changing the API.

## Special Events module (added 2026-10)

Envision's one-off events (Chara: karaoke and games night) live in their own
standalone module. Design and build plan: `docs/engineering_guide.md`; the
per-PR instructions are in `docs/build_prompts.md`.

**Where things are**

| Path | What |
| ---- | ---- |
| `includes/special_events/` | All module PHP. `bootstrap.php` is the only entry point; it requires the rest. |
| `api/special_events_api.php` | Studio (ERP session + capability). |
| `api/special_events_public_api.php` | Public (device cookie / manage token). |
| `api/special_events_live_api.php` | Crew live ops. |
| `api/special_events_display_api.php` | Stage and lobby displays (key in the URL fragment). |
| `modules/special_events/index.php` | The Studio screen. |
| `e/` | Public router: `/e/<slug>` and its sub-paths. |
| `live/` | Runtime JSON snapshots. Git-ignored except `.htaccess` and `.keep`. |
| `assets/se/` | Vendored libraries, the compiled CSS and the module's JS. |
| `uploads/se/<public_id>/` | Uploaded media. Git-ignored, never deleted by a deploy. |

All tables are prefixed `se_`. Endpoints bootstrap with
`require_once '../includes/db.php';` then
`require_once '../includes/special_events/bootstrap.php';` — in that order,
because `db.php` owns the session, the PDO handle and the security gate.

**Module-only front-end rules.** Preact + htm + `@preact/signals`, GSAP and
the precompiled Tailwind are allowed **inside this module only**
(`assets/se/**`, `e/`, the Studio page). The repo-wide
"no React / Vue / Alpine" rule stands everywhere else. Libraries are
**vendored** under `assets/se/vendor/<name>-<version>/` and listed in
`VENDOR.md` with their SHA-256 — never loaded from a CDN at runtime, because
the CSP in §19.7 allows only `'self'` (Google Fonts is the one exception).

**No `style="…"` attributes in server-rendered HTML.** The module's CSP sets
`style-src` to `'self'` plus a nonce, and a nonce does not cover style
*attributes* — browsers drop them silently. Use classes, and put per-event or
per-team values in the nonced `<style>` block via `se_theme_css_vars()`. The
`style` prop inside a Preact component is fine (it goes through the CSSOM).

**The SE CSS freshness check — a committed artifact, scoped to SE changes.**
`assets/se/css/se.css` is a COMPILED, COMMITTED file (the cPanel host has no Node —
production serves the committed bytes). CI rebuilds it
(`bin/build_se_css.sh --check`) and fails on any diff, but ONLY when your changes touch
SE paths (`assets/se/`, `e/`, `includes/special_events/`, `modules/special_events/`,
`api/special_events_*`, `bin/build_se_css.sh`, `tests/special_events/`), and the build
scans ONLY the `@source` dirs in `assets/se/css/se.input.css` (auto-detection is disabled
via `source(none)`). Consequences:
- Work outside those paths can NEVER fail this check. Nothing to do.
- If you touch SE sources: after your markup/JS changes run
  `bash bin/build_se_css.sh --check`. "stale" means run `bash bin/build_se_css.sh`
  (no flag) and commit the regenerated `assets/se/css/se.css` AND `assets/se/BUILD` in the
  SAME commit as your change. Never hand-edit `se.css`; never open the PR with a stale
  artifact to fix later — every CI run fails until it is rebuilt.
- Sandbox cannot download the standalone binary (GitHub release assets blocked — EOF/SSL)?
  Use the npm CLI at the SAME pinned version, from the repo root:
    cd "$(git rev-parse --show-toplevel)"
    npx -y @tailwindcss/cli@$(tr -d '[:space:]' < assets/se/css/TAILWIND_VERSION) \
        -i assets/se/css/se.input.css -o assets/se/css/se.css --minify
    printf '%s %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$(git rev-parse --short HEAD)" \
        > assets/se/BUILD
- Never commit the `tailwindcss-linux-x64` binary (~121MB, git-ignored).
- Merge conflict on `se.css`: rebuild on the merged tree — never resolve it by hand.

**Rules that are not negotiable**

- Seats, teams, player numbers and queue numbers are allocated **only** inside
  `se_lock_event()` transactions, and uniqueness comes from unique keys —
  never check-then-insert.
- Scores are a **ledger** (`se_score_events`): never UPDATE points; void the
  row and re-award.
- After any state change that affects a screen, call `se_live_publish()`.
  **Never** put personal names, phone numbers, unrevealed answers or charades
  phrases in `public.json`. Names go only in key-protected snapshots, as
  "Ada O."; phrases only through `me` or the console.
- The module **never writes** `events`, `event_registrations`, `checkins`,
  `attendance` or `users`. The single exception is the Embrace insert in the
  PR7 hand-off (guide §17.4).
- SMS only through SMS Studio campaigns and its queue — never write `sms_log`.
  The module adds one merge field, `{{link}}` (guide §21.2).
- AI only through `se_ai()`. **No attendee personal data in a prompt, ever**,
  and nothing an AI returns is applied without human review.
- Scripture text comes only from the Bible service (`se_bible_lookup()`) or
  `kjv_bundle.php`, which is generated verbatim from the same KJV — never typed
  into code. Ready-made questions cut verses from it with
  `se_chara_verse_payload()`; `tests/special_events/starter_content_test.php`
  checks every ready-made item.
- **Response schemas stay small.** Gemini compiles `responseSchema` into a
  constrained decoder and refuses anything with too many states ("The
  specified schema produces a constraint that has too many states for
  serving", HTTP 400) — a bounded array of nested objects with several
  optional properties is the usual trigger. So in
  `includes/special_events/prompts/*.schema.json`: no `maxItems` on arrays of
  objects (cap the list in PHP and say the limit in the prompt), and prefer
  `required` + `"nullable": true` over optional properties. `se_ai()` already
  retries once without `responseSchema` when a 400 names the schema, and
  always validates the answer with `se_schema_validate()` — but that fallback
  is a safety net, not a licence to grow a schema.
- Diagnosing an AI failure: `se_ai_requests.http_status` now records a real
  status for every attempt (`0` = the request never reached Google), one
  `error_log` line per failed attempt says task/model/status/attempt, and
  `php bin/se_ai_probe.php` makes the same text and image calls from the CLI
  and prints the raw status, curl errno and Google's message.
- Code shipped before a later migration MUST degrade safely: ask
  `se_table_exists()` first and return `FEATURE_NOT_READY`, never a fatal.
  The tree is copied to production *before* migrations run.
- Hand-off overrides (`se_handoff_push()`) may only send a person the rules
  marked **ready** to the other team or hold them back. Consent, opt-outs,
  membership and an earlier hand-off are never overridable.
- Public API calls take the phone number as a **string**
  (`normalizePhone(raw).e164`), never the `{e164, sms, display}` object —
  sending the object made every self check-in fail with `INVALID_PHONE`.
- A game round moves only through the engine in `games_engine.php` /
  `party_games.php` (`se_round_*_live`, `se_feud_update`, `se_charades_*`,
  `se_buzz_judge_party`). Correct a result by voiding the round (with a
  reason); the next round replays the same question.

**Requirements and operations**

- `SE_HASH_PEPPER` (64 hex) is **required** in `.env`. Without it the module
  refuses to issue tokens. Rotating it invalidates every device cookie and
  manage link.
- Cron, every 5 minutes: `php /home/smartqaq/public_html/hodlc.lpc.cm/cron/special_events.php`
  (reminders, retention, purges). Arrives with PR4.
- After every deploy, check **Studio → Settings → Health → Schema**.

**Docs.** Any change under `modules/special_events/`, `api/special_events_`,
`includes/special_events/`, `e/` or `assets/se/js/` MUST update
`modules/special_events/how_to_use.md` in the same PR — CI enforces it, as it
does for Reach and Assimilation.

**Checks CI runs for this module** (run them before pushing):

- `php tests/special_events/run.php` — unit tests, including
  `function_calls_test.php`: every plain function call in the module must be a
  PHP built-in or defined in the repo (`php -l` cannot see a missing function).
- `node --check` over every file in `assets/se/js` (CI copies each to a temp
  `.mjs`); locally `node --experimental-default-type=module --check <file>`.
  A stray backtick inside an ``html`…` `` template blanks the whole surface.
- `node --test "tests/special_events/js/*.test.mjs"` and
  `bin/build_se_css.sh --check`.
- The integration suite on **MySQL 8.0 and MariaDB 10.11** (the production
  engine is unconfirmed): `php tests/special_events/db_setup.php --host=… --db=se_it --fresh --seed`,
  then `SE_TEST_DB_HOST=… SE_TEST_DB_NAME=se_it php tests/special_events/integration/run.php`.
  MariaDB rejects `GROUP BY t.id` when other `t` columns are selected — list
  them in the `GROUP BY`.

## Installable app (PWA) + Web Push (added 2026-10-12)

Full guide: `docs/pwa_push.md`. Schema:
`db/migrations/20261012090000_pwa_push.sql` (tables `push_subscriptions`,
`push_queue`, `push_config`, `push_user_prefs`).

**Every `INSERT INTO system_notifications` is already a push notification.**
Do not add a push call next to one. `push_fanout()` reads rows newer than
`push_config.fanout_cursor_id`, expands the audience and queues them; adding
a second path would double-send. If you need a push that is NOT a
notification, call `push_send_to_user()` or `push_enqueue()` directly.

- **`includes/db.php` arms a shutdown hook** (`push_register_dispatcher()`)
  on POST/PUT/PATCH/DELETE and `/api/` requests. It runs NO query during the
  request; the sending happens after `fastcgi_finish_request()`. Do not move
  it, and do not make it do work before the response is released.
- **Everything degrades to `FEATURE_NOT_READY`.** The tree reaches production
  before `db/migrate.php` runs, so every entry point asks `push_ready()` or
  `push_table_exists()` first. Nothing in `includes/push_*.php` may throw out
  of a page request — that hook runs on every POST the platform handles.
- **`includes/push_crypto.php` is verified against RFC 8291 §5.** Run
  `php tests/pwa/run.php` after ANY edit to it. A silent break there means
  every browser discards every notification and nothing says why.
  Deliberately no Composer package: `minishlink/web-push` needs ext-gmp or
  ext-bcmath, neither guaranteed on this host, and `vendor/` is rebuilt on
  every deploy.
- **A notification's `link_url` can never leave this site.**
  `push_message_from_notification()` rejects absolute URLs, `//host`,
  backslashes and `javascript:`, falling back to `/index.php`. Keep that
  guard — 60 call sites write that column.
- **`sw.js` must never cache HTML.** Every page is rendered against a session
  and full of member data; Cache Storage outlives sign-out. Navigations are
  network-only with a static `/offline.html` fallback, and `/api/`, `/auth/`,
  `/live/`, `/e/`, `/webhook/` and `/uploads/` are passed straight through.
  `/live/` and `/e/` in particular belong to Special Events, which polls for
  real-time state under its own CSP.
- **Rotating `VAPID_PUBLIC_KEY` invalidates every subscription** and nobody is
  told to opt in again. Treat it like `SE_HASH_PEPPER`.
- **A row that names `user_id` is addressed to that member alone**; its
  `department_id` / `role_id` / `region_id` are context. `regions_api.php`
  writes one row per member of a region carrying both, and expanding both
  turns a 200-member broadcast into 40,000 queue writes.
- **`assets/pwa/` is a committed build artifact** (no Node on the host, same
  as `assets/se/css/se.css`). Regenerate with `php bin/build_pwa_icons.php`
  after changing `assets/images/logo_hod.png`, and commit the output. There
  is no CI freshness check for it.
- **New page wants the app?** Two includes: `includes/pwa_head.php` inside
  `<head>`, `includes/pwa_boot.php` as the first element in `<body>`. Both
  idempotent. Two optional variables set immediately before the require:
  `$pwaNonce` on any page whose CSP has no `'unsafe-inline'` (without it the
  preloader styles and boot script are dropped SILENTLY — `/e/` sets it from
  `se_csp_nonce()`), and `$pwaQuiet` on a live/critical surface, which keeps
  push delivery but drops the install banner, the opt-in sheet and
  pull-to-refresh. `e/index.php` sets both on its portal and hub; its 404 is
  left alone. An element can opt its subtree out of the pull gesture with
  `data-no-pull`.
- **The Alerts Centre** (`modules/alerts/`, `api/push_admin_api.php`) is gated
  by `security_is_admin()` against the live roles table, like the Security
  Centre. Never gate it on `$_SESSION['active_role']` — the member switches
  that themselves. Its broadcast writes `system_notifications` rows rather
  than pushing directly, so quiet hours and opt-outs are honoured by the
  normal fan-out; keep it that way. Every broadcast and switch flip goes to
  `security_audit_log`. Member guide: `modules/alerts/how_to_use.md`.
- **Special Events registration alerts** live in `se_registration_notify()`
  (`includes/special_events/capacity.php`), called from `se_register()` so
  every channel is covered. `registration.notify_crew` defaults to `digest`:
  the first registration goes immediately, then a summary at most every
  `SE_REG_NOTICE_MIN_GAP_MINUTES`. Do not make `every` the default — a Chara
  night is 300 registrations, and that is how a congregation learns to ignore
  notifications. State is `reg_notice` rows in `se_audit_log`.
- **Checks CI does not run yet** (nobody has added the step; see the note on
  `.github/workflows/` below — an agent can add it, it no longer has to be the
  owner. Same situation as the Assimilation Excel suite):
  - `php tests/pwa/run.php` — 176 checks, no database, no network.
  - `node --experimental-default-type=module --check assets/js/pwa.js`
  - `node --experimental-default-type=module --check sw.js`
  - `php tests/pwa/integration/run.php --host=… --db=push_it` — 51 checks
    against a REAL database, on **both** MySQL 8 and MariaDB 10.11. This is
    the only thing that covers the fan-out SQL, the audience expansion, the
    unique key that stops a double send and the cursor. It found a live bug
    the unit suite could not: push_config_set() did not write through the
    cache push_config_get() reads, so a second fan-out in one process read a
    stale null cursor, concluded it was the first run ever, and would have
    silently dropped everything queued in between.
- **Operations:** cron every minute,
  `php /home/smartqaq/public_html/hodlc.lpc.cm/cron/push_worker.php`.
  `php cron/push_worker.php --status` prints readiness, queue depth, device
  count and the cursor. Kill switches live in `push_config` (`enabled`,
  `inline_dispatch`).

## The Bible module (added 2026-10-13)

Reading, exact-word highlights, private notes and a study journal, search,
reading plans with streaks, a voice reader and verse images — open to every
signed-in member. Member and setup guide: `modules/bible/how_to_use.md`.

| Path | What |
| ---- | ---- |
| `includes/bible/` | All module PHP. `bootstrap.php` is the only entry point (after `includes/db.php`). |
| `includes/bible/kjv/` | The KJV text, one file per book + `counts.php` + `MANIFEST`. **Generated.** |
| `api/bible_api.php` | JSON endpoint (session + `X-Bible-CSRF` on POST). |
| `api/bible_export.php` | PDF of notes / journal / highlights (dompdf). |
| `modules/bible/index.php` | The reader. JS in `assets/js/bible/*.js` (classic scripts on `window.HODBible`), CSS in `assets/css/bible.css`. |
| `bin/build_bible_kjv.php` | Regenerates `includes/bible/kjv/` from the public-domain OSIS file. |
| `db/migrations/20261114090000_bible_core.sql` | All `bible_*` tables. |

**Rules that are not negotiable**

- **Scripture is never typed into code.** The KJV comes only from
  `includes/bible/kjv/`, generated by `bin/build_bible_kjv.php` from the same
  `eng-kjv.osis.xml` that bible-api.com serves (and that
  `includes/special_events/kjv_bundle.php` was copied from). Never hand-edit a
  data file: `MANIFEST` pins each file's SHA-256 and `tests/bible/run.php`
  fails on any drift, and also checks every Special Events bundled verse
  word for word against it.
- **NKJV, NIV and AMP are copyrighted and come only through API.Bible**
  (`includes/bible/api_bible.php`). Never import, scrape or seed their text.
  Licence rules the code enforces and you must keep:
  - `bible_chapter_cache` rows are never served after `BIBLE_CACHE_DAYS`
    (29); a stale row that cannot be refreshed is **deleted**, not shown.
  - Requests carry `fums-version=3`; the `fumsToken` is stored with the row
    and the browser reports it (`BB.fums()` in `assets/js/bible/core.js`) on
    **every display**, cache hit or not — but not on a repaint of the same
    chapter (a new highlight).
  - The chapter's copyright line is always shown; licensed words are never
    copied into `bible_highlights.quote` / `bible_notes.quote` (KJV only), and
    the PDF export prints at most 400 cached licensed verses with the notice.
  - Every API.Bible request is counted in `bible_api_usage` and refused past
    `BIBLE_API_MONTHLY_CAP` (default 4,500 of the Starter plan's ~5,000).
    Licensed search is also capped per member per day
    (`BIBLE_LICENSED_SEARCHES_PER_DAY`). Never add a code path that calls
    API.Bible without going through `bible_api_get()`.
  - The `api-key` header is only ever sent over https, and redirects are
    not followed (they would resend the key).
- **Everything a member writes is private.** Every function in
  `includes/bible/study.php` and `plans.php` takes the caller's user id, and
  every query filters on it — updates and deletes included (`… AND user_id
  = ?`) — or on a note/plan row already proven to be theirs (tag links, plan
  progress). There is no admin view of members' notes; do not add one.
- **Positions are (verse, code-point offset) into that translation's verse
  text**, end exclusive. Each verse's words live in exactly one
  `<span class="vt">`, and nothing that carries text (verse numbers, drop cap,
  note markers) may go inside it — otherwise selection offsets drift. Range
  arithmetic is `bible_hl_plan()`; it is pure and tested.
- **Degrade before the migration.** The tree reaches production before
  `db/migrate.php` runs: KJV reading must work with no tables at all, and
  every DB action answers `FEATURE_NOT_READY` until `bible_ready()`.
- The module's dialogs follow the shared modal contract (ids contain
  `Modal`, closed only by a visible button). `modal-manager.js` cancels clicks
  outside the open dialog, so anything clicked programmatically while one is
  open (e.g. a download link) must be placed **inside** it.
- SQL in the module stays portable to SQLite (the test DB): no
  `ON DUPLICATE KEY`, no `INSERT IGNORE`; LIKE escapes with `ESCAPE '!'`.

**Checks** (CI runs both in the lint job, step "The Bible tests"):

- `php tests/bible/run.php` — 1,100+ checks, no MySQL, no network: the
  reference parser, the KJV data and its manifest, highlight arithmetic, the
  API.Bible HTML parser against recorded responses, plan schedules, streaks,
  and the whole module against SQLite built from the real migration
  (privacy between members, the 29-day cache, quota, FUMS tokens).
- `node --check` each file in `assets/js/bible/`.
- There is no browser suite in the repo; smoke `modules/bible/index.php` by
  hand (select words → colour, note, search, a plan, the voice reader).

**Regenerating the KJV** (only if the source ever changes):

```bash
curl -L -o /tmp/eng-kjv.osis.xml https://raw.githubusercontent.com/seven1m/open-bibles/master/eng-kjv.osis.xml
php bin/build_bible_kjv.php /tmp/eng-kjv.osis.xml
php tests/bible/run.php
```

## The Hymnary (added 2026-10-17)

Household of David's own song library: 511 public-domain hymns (305 with
four-part scores), the songs the music team adds, two reading modes
(Congregation and Choir), generated tonic sol-fa, and a Studio with an AI
reader. Member and office guide: `modules/hymnal/how_to_use.md`.

| Path | What |
| ---- | ---- |
| `includes/hymnal/` | All module PHP. `bootstrap.php` is the only entry point (after `includes/db.php`). |
| `includes/hymnal/corpus/` | The 511 hymns, sharded 50 per file, + `index.php`, `meta.php`, `MANIFEST`, `NUMBERS`. **Generated.** |
| `includes/hymnal/solfa.php` | Tonic sol-fa from a score. Pure arithmetic, heavily tested. |
| `includes/hymnal/collections.php` | The shelves. Code, not data — adding one needs no migration. |
| `api/hymnal_api.php` | JSON endpoint (session + `X-Hymnal-CSRF` on POST; `ai_read` is multipart). |
| `modules/hymnal/index.php` | The reader. `studio.php` is included only for holders of the Studio. |
| `assets/js/hymnal/` | Classic scripts on `window.HODHymnal`. CSS in `assets/css/hymnal.css`. |
| `assets/hymnal/` | Vendored abcjs + the self-hosted soundfont. See `assets/hymnal/VENDOR.md`. |
| `bin/hymnal_parse_abc.mjs` | Stage one of the corpus build (node; uses abcjs). |
| `bin/build_hymnal.php` | Stage two: writes `includes/hymnal/corpus/`. |
| `db/migrations/20261120090000_hymnal_core.sql` | All `hymnal_*` tables. |

**Rules that are not negotiable**

- **No hymn text, tune or harmony is ever typed into this repository.** The
  corpus comes only from `bin/build_hymnal.php`, and `MANIFEST` pins a SHA-256
  per file — `tests/hymnal/run.php` fails on any drift, so a hand edit cannot
  hide. Same contract as `includes/bible/kjv/`.
- **Nothing enters the corpus unless its words AND tune AND arrangement are
  each, separately, public domain.** A hymn being old is not a licence: a
  modern four-part setting of an 1840s hymn is still somebody's copyright, and
  `rightsGate()` exists to stop exactly that. `tune: null` means the status was
  not recorded, which is a gap in the evidence, not an absence of claim — those
  songs are held back and listed in `corpus/meta.php`. Licensed songs belong in
  the Studio, each with its own licence record, never in the corpus.
- **A published score and a published chord chart can carry the same tune under
  DIFFERENT WORDS.** `scoreTextAgreement()` refuses a score below 0.35
  agreement with the words (median across the corpus is 0.98; the five real
  mismatches sit at 0.03–0.21). Do not relax that threshold without re-reading
  why it is there: the alternative is the wrong words printed under the staff
  and a choir rehearsing a text the congregation is not reading.
- **A HOD number is permanent and never reused.** It is read out from the
  pulpit. The corpus owns 1–1999 through the committed, append-only `NUMBERS`
  ledger; `hymnal_songs` allocates from `HYMNAL_DB_NUMBER_FLOOR` (2000) up,
  inside a transaction, with uniqueness from the unique key — never
  check-then-insert. A retired song keeps its number reserved. Never renumber,
  never sort `NUMBERS` by hand.
- **Sol-fa is generated, never typed, and there is ONE implementation.**
  `hymnal_solfa_song()` is the only place syllables are produced — the corpus
  build and the Studio both call it. The notes it works from are extracted by
  `assets/js/hymnal/abc_voices.js`, which is deliberately shared: node
  `require()`s it for the build, the browser loads it in the Studio. Writing
  either twice is how the two drift, and wrong sol-fa is worse for a chorister
  than none.
- **One doh serves a whole song**, taken from the melody, so the parts read
  vertically as a printed sol-fa hymn does and a syllable means the same pitch
  in every part. Movable doh also means **transposition does not change a
  single syllable** — only the "doh = …" heading. Do not "fix" the commas on a
  bass line by giving each voice its own doh.
- **Everything a member writes is private.** Favourites, reading position and
  display settings are scoped to the caller's user id on reads, updates AND
  deletes. There is no view of one member's favourites for anyone else, pastors
  included; do not add one.
- **The Studio is gated on live roles, never `$_SESSION['active_role']`** (the
  member switches that themselves). River of Life (department resolved by name,
  4 in production) plus `Super_Admin` / `Resident_Pastor` / `Assoc_Pastor` may
  edit; only the pastoral roles may publish. `api/hymnal_api.php` re-checks on
  every write — a hidden button is not a permission.
- **AI only through `hymnal_ai()`, and it decides nothing.** No personal data
  in a prompt, ever — the only thing sent is the song. A call produces a
  `hymnal_ai_jobs` row and nothing else; it becomes a song only when a person
  has read it in the Studio form and pressed save, and the job records which
  song it became. The model is asked to report what it could not read, and that
  note is shown above the form. It is a sibling of `se_ai()` rather than a
  caller: that function logs to `se_ai_requests`, counts against an event's
  limits, and lives behind a bootstrap demanding `SE_HASH_PEPPER`.
- **Degrade before the migration.** The tree reaches production before
  `db/migrate.php` runs, so all 511 hymns must be readable with no tables at
  all. Every DB action asks `hymnal_ready()`/`hymnal_table_exists()` first and
  answers `FEATURE_NOT_READY`.
- SQL stays portable to SQLite (the test DB): no `ON DUPLICATE KEY`, no
  `INSERT IGNORE`, LIKE escapes with `ESCAPE '!'`, and columns one per line
  ending in `ENGINE=` so `tests/hymnal/lib.php` can translate the real
  migration.
- `_hymnal_` is a required marker in `tests/special_events/db_setup.php`, so
  the migration must apply against MySQL 8 and MariaDB 10.11 in the integration
  job. A migration whose filename carries no required marker is reported as
  "skipped" when it fails and the job still goes green — which is correct for
  the two that alter platform columns the harness's stand-ins lack, and silent
  for one that is simply broken. Add your module's marker when you add a
  module, and verify it on BOTH engines first.
- The module's dialogs follow the shared modal contract (ids contain `Modal`,
  closed only by a visible button).
- **Share as a card is open to every published song**, CCLI songs included.
  That is the church's decision (2026-10-17). What the card owes the writers
  is on the card: the writer's name, and a deliberately faint rights line at
  the foot (`rightsLine()` in `assets/js/hymnal/share.js`, with the CCLI song
  number where there is one). Keep that line in any redesign. `refusal()`
  still refuses unpublished drafts and songs with no words.
- **The reader's menus are popovers, not dialogs** (`.hy-menu`, opened by the
  button whose `aria-controls` names them, run by `app.js`). Their ids must not
  contain "modal": modal-manager.js treats any fixed element whose id does as a
  dialog, portals it and traps focus in it.
- **Playback is synthesised from the score**, so it exists for exactly the songs
  that have one; where there is none, choir mode hides the controls and says the
  notes are not available. Never offer a play button that does nothing.
- abcjs and the FluidR3 soundfont are **vendored** under `assets/hymnal/` with
  their SHA-256 in `VENDOR.md` — never a CDN. abcjs only applies its 3x volume
  boost to its own default URL, so the reader passes
  `soundFontVolumeMultiplier: 3.0` explicitly; without it the same samples play
  back almost inaudibly.

**Checks** (CI runs them in the lint job, step "The Hymnary tests"; run them
before pushing anyway):

- `php tests/hymnal/run.php` — 264 checks, no MySQL, no network: the sol-fa
  arithmetic against music theory, the corpus and its manifest, the licence of
  every one of the 511 hymns, search and browsing, the module against SQLite
  built from the real migration (privacy between members, numbering, the
  licence gate at publish), and that every function the module calls exists.
- `node --check` each file in `assets/js/hymnal/` and `bin/hymnal_parse_abc.mjs`.
- There is no browser suite; smoke `modules/hymnal/index.php` by hand (open a
  hymn, switch to Choir, solo a part, transpose, play, then the Studio).

**Regenerating the corpus** (only if the sources change), from the repo root:

```bash
node bin/hymnal_parse_abc.mjs <abc-dir> <source>/parsed
php  bin/build_hymnal.php --source=<source> --verbose
php  tests/hymnal/run.php
```

Commit everything under `includes/hymnal/corpus/`, `MANIFEST` and `NUMBERS`
included. Stage one refuses to emit a score that still warns and lists it in
`_excluded.json`; stage two carries that through to `meta.php`, so a newly
malformed source shows up as a hymn without notes and a recorded reason rather
than a silently broken staff.

## Sunday ladder (added 2026-10-18)

1st Timer → 2nd → 3rd → Visitor, driven by Sunday attendance. Engine:
`includes/status_ladder.php`. Schema: `db/migrations/20261122090000_user_status_history.sql`
(`user_status_history`). Before this, nothing ever changed a person's timer
status: someone clocked in three times still read "1st Timer".

- **One "time" is one calendar day present at a SUNDAY service** (decided with
  the church). Midweek and special events never count. 1 Sunday = 1st, 2 = 2nd,
  3 = 3rd, 4+ = Visitor. Counting goes through
  `assim_attendance_union_sql(['Sunday_Service'], $ids)` — the shared helper
  grew an optional category and user filter for this; never query `attendance`
  or `checkins` directly.
- **Forward only.** Only current 1st/2nd/3rd Timers are touched. Member, Worker,
  Pastor, Non_Member and Visitor never are, nor is anyone Relocated / Attends
  Another Church / `awol_exempt`. A status staff set by hand is never lowered.
  The one exception: `clock_out` (withdrawing a clock-in) calls
  `ladder_rollback_user()`, which undoes a promotion **the ladder itself made**
  and only while the history still ends in ladder rows — a hand edit afterwards
  is never rolled back, and nobody drops below where the ladder found them.
- **Hooks:** `events_api.php` `mark_attendance` / `clock_out`, and both QR
  actions in `checkin_api.php`, call `ladder_advance_user()`. It never throws.
  `bin/deploy.sh` step 6 runs `db/backfill_status_ladder.php` (`--dry-run`
  lists who would move) on every deploy as the catch-up and safety net; it is
  idempotent and never fails the deploy.
- **Every writer of `users.spiritual_status` must record itself.** Read
  `ladder_status_of()` before the UPDATE and call `ladder_note_change()` after
  it (see `events_api.php` `update_person_details`, `congregation_api.php`,
  `embrace_api.php` `promote_member`, `academy_api.php`). A writer that skips
  this makes event reports and the Embrace list wrong without any error.
  Initial statuses on INSERT need no history row.
- **Event reports read status AS AT THE EVENT'S DAY**, never `u.spiritual_status`:
  use `ladder_status_at_sql($pdo, 'u', 'ev.event_date')` (a change made on the
  day counts as already happened). Otherwise promoting people shrinks the
  first-timer count on every past service. Done in `event_analytics_helpers.php`
  and `events_api.php` `attendance_kpis`; do it in any new per-event report.
- **Embrace's active list is `embrace_pipeline_sql()`, not a status list.** It is
  the three timer statuses **plus** a Visitor the ladder graduated (their latest
  history row is the ladder's move to Visitor) whose latest follow-up is not a
  final report (`EMBRACE_FINAL_FOLLOWUP_STATUSES`: Completed, Unreachable) —
  including someone never assigned a worker. An ordinary Visitor is never pulled
  in. `fetch_visitors`, `fetch_archive`, `update_visitor` and the Excel register
  all use it; the Archive is its complement. Do not hard-code
  `IN ('1st_Timer','2nd_Timer','3rd_Timer')` for "in Embrace".
- **Degrade before the migration.** The tree reaches production before
  `db/migrate.php`. `ladder_ready()` is false until `user_status_history`
  exists: nothing moves, nothing is logged, reports read the current status,
  and Embrace shows just the timers. Every move must be recorded, so the ladder
  deliberately does nothing rather than promote unrecorded.
- No `user_status_history` foreign key (the migration says why).
  `congregation_api.php` `delete_member` calls `ladder_forget_user()`.
- The migration is not on the required-marker list in
  `tests/special_events/db_setup.php`, so a failure would show up there as a
  "skipped" line rather than a red job. It was checked by hand against that
  output on the PR that added it (#92): it applied on MySQL 8.0 and MariaDB 10.11,
  and applied again cleanly on the second pass. It is forward-only and must not
  be edited once applied, so add a marker only if you ship a new migration for
  this table.

## Departments module (ministry years)

`modules/departments/` is ministry-year aware, and its rules are worth knowing
before touching anything that reads `user_departments`:

- Every roster row belongs to one **ministry year** (`ministry_years`), and one
  person has at most **one** row per department per year. The old
  `UNIQUE(user_id, department_id)` key is gone — do not reintroduce a unique key
  that spans years.
- `user_departments.membership_type` is `Primary` | `Secondary`, and
  `roster_status` is `Active` | `Removed` *inside that year*. Removed rows are
  never rendered anywhere in the module; the record is kept for audit and is
  reactivated (not duplicated) if the person is added back.
- `is_active` still means **"serving now, in the live year"** and is what the
  rest of the platform should keep reading (nav clearance, special_events,
  charis, reach). Archiving a year clears it on that year's rows.
- `includes/department_helpers.php` is the only place that builds roster data;
  `api/department_api.php`, `api/department_export_excel.php` and
  `assets/js/department_export.js` all consume it. See
  `modules/departments/how_to_use.md`.
- The A4 roster JPEG is a **canvas renderer**, not a DOM capture (same reasoning
  as `assets/js/celebrants_export.js`): geometry is arithmetic, names are
  measured with `ctx.measureText`, and the fitter guarantees one A4 page.

## Common pitfalls spotted during onboarding

- **A dialog must fit above the on-screen keyboard.** On Android Chrome and iOS
  Safari the keyboard shrinks only the *visual* viewport; `100dvh` does not
  change, so a dialog sized to it runs behind the keyboard and its lower fields
  and its Next/Save button cannot be reached or scrolled to (the Attendance
  "Can't find them?" wizard hid Phone and Next this way). `modal-manager.js`
  now sets `html.app-keyboard-open`, `--app-vv-height` and `--app-vv-top` while a
  dialog is open and the visual viewport is >120px shorter than the layout
  viewport, and the shared CSS in `includes/header.php` fits every
  `.app-modal-overlay` / `.app-modal-panel` into it. It also scrolls the focused
  field to the middle. So: keep a dialog's scrolling inside `.app-modal-panel`
  (give the form area `overflow-y-auto flex-1`, header and footer `shrink-0`),
  never size a dialog with `vh`/`dvh` yourself, and if it has a tall header use
  `html.app-keyboard-open` to compact it (see `.att-wiz-head`).
- **Wizard steps must refuse an incomplete starred field.** In the Attendance
  add-person wizard a step cannot be left while a required field is empty or
  invalid: Next marks every failing field, puts the cursor in the first one and
  scrolls it into view (`attWizardValidateStep` / `attWizardFocusField`, fields
  listed in `ATT_WIZ_STEP_FIELDS`, wording in `attWizFieldProblem`). The address
  is required on both paths because `users.physical_address` cannot hold NULL —
  an empty one used to fail the INSERT with a generic error. When the server
  answers with a `field`, `attWizardCreated` sends the person back to that
  field (`ATT_WIZ_FIELD_HOME`) rather than showing a banner. A new required
  field goes in all three places.
- **The Quick Search dropdown belongs to its field.** It closes on Escape, on
  blur, and on any pointer that goes down outside it (touch included — a swipe
  that starts outside counts). A pointer down *inside* it sets `attPopTouching`
  so the field's blur does not close it before a tap's click lands (iOS never
  focuses a tapped button). Do not go back to a `mousedown`-only outside handler:
  touch scrolling never fires it.

- `includes/auth_middleware.php` is currently an **empty file**. Do not
  rely on it as a guard; every page/API rolls its own session check.
  The one shared hook that *does* run everywhere is
  `security_enforce_session()` in `includes/db.php` (account status and
  session revocation only — it does not do RBAC). If you consolidate
  auth further, wire the new middleware into every endpoint in the same
  PR — otherwise gates go missing silently.
- The Geoapify API key is hardcoded in four places (`connect.php`,
  `modules/congregation/index.php`, `modules/embrace/index.php`,
  `modules/profile/index.php`). Long-term this should be replaced with
  a server-side variable interpolated into the page from
  `$_ENV['GEOAPIFY_API_KEY']`. Don't scatter it further in the meantime.
- `sms_functions.php` decrypts with `SMS_VAULT_KEY` (loaded from `.env`
  via `includes/sms_vault_key.php`). Rotating the key invalidates every
  encrypted row in `sms_settings`; re-encrypt or reset those tokens
  before rotating.
- SMS sends go through `sms_send_one()` and delivery reports through
  `sms_apply_dlr()` (both in `includes/sms_functions.php`). Never write
  `sms_log.status` directly: those two record every movement in
  `sms_status_events` (the History timeline) and refuse to move a
  delivered message backwards. Never retry a billable BulkSMS POST.
- Cron jobs must set `$_SERVER['DOCUMENT_ROOT']` before requiring
  `includes/db.php` (it loads `.env` from there, which is empty under
  CLI) — see any file in `cron/`.
- `.htaccess`, `.user.ini`, and `php.ini` in the repo mirror the cPanel
  MultiPHP INI Editor output. Editing them by hand can break the
  production PHP handler configuration. Only `php.ini` is committed at
  present; `.htaccess` and `.user.ini` are git-ignored.
- `$_SESSION['active_role']` is set at login and can be switched from
  the profile screen. Never assume it equals the user's *primary* role —
  always read from `$_SESSION` at request time, not cache it.
- `PDO::ATTR_ERRMODE = EXCEPTION` is set globally, so unhandled PDO
  errors will crash the JSON response. Wrap DB calls in try/catch and
  return `['status' => 'error']` to keep API contracts intact.
