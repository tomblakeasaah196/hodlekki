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
    `/auth/login.php`.
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
- **Tests:** there is no automated test suite. Do a manual smoke of the
  module you touched before declaring done.

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

**Rebuild the CSS when classes change.** `bin/build_se_css.sh` compiles
`assets/se/css/se.css`, which is committed because the host has no Node. CI
runs it with `--check` and fails on a stale file. If two branches both changed
`se.css`, rebuild it rather than merging it by hand.

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
- Code shipped before a later migration MUST degrade safely: ask
  `se_table_exists()` first and return `FEATURE_NOT_READY`, never a fatal.
  The tree is copied to production *before* migrations run.

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

## Common pitfalls spotted during onboarding

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
