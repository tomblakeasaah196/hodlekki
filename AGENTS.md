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
- **New DB tables / columns:** add a migration file under
  `db/migrations/YYYY-MM-DD_<slug>.sql` (create the folder if absent);
  keep the SQL runnable top-to-bottom against the current schema.
- **New Reach / Embrace features:** extend `modules/reach/` and
  `modules/embrace/` respectively; both already have their own
  department-based clearance checks in the matching API file.
- **New cron job:** `cron/<name>.php`, CLI-only guard, and document the
  crontab line in the README.

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

## Common pitfalls spotted during onboarding

- `includes/auth_middleware.php` is currently an **empty file**. Do not
  rely on it as a guard; every page/API rolls its own session check.
  If you consolidate auth, wire the new middleware into every endpoint
  in the same PR — otherwise gates go missing silently.
- The Geoapify API key is hardcoded in four places (`connect.php`,
  `modules/congregation/index.php`, `modules/embrace/index.php`,
  `modules/profile/index.php`). Long-term this should be replaced with
  a server-side variable interpolated into the page from
  `$_ENV['GEOAPIFY_API_KEY']`. Don't scatter it further in the meantime.
- `sms_functions.php` decrypts with `SMS_VAULT_KEY` (loaded from `.env`
  via `includes/sms_vault_key.php`). Rotating the key invalidates every
  encrypted row in `sms_settings`; re-encrypt or reset those tokens
  before rotating.
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
