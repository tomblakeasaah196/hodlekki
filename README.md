# Household of David Lekki Centre — Church ERP + Public Website

A single-tenant church management platform for Household of David Lekki Centre.
It combines a public-facing website (welcome, connect wizard, sermons,
testimonies, library) with an internal ERP that runs Household Groups,
Finance, a Learning Management System, and structured new-converts follow-up.

## Modules overview

The internal ERP is organised as one directory per module under
`modules/<name>/index.php`, each backed by an endpoint under
`api/<name>_api.php`. High-level groupings:

**Members & structure**
- `congregation` — master member directory and household records.
- `departments`, `roles` — RBAC matrix, department membership, role assignment.
- `regions`, `tribes` — geographic and small-group segmentation of members.
- `profile`, `member_portal`, `parent_portal` — self-service views for
  members, parents of junior-church kids, and staff.

**Attendance & events**
- `events` — Sunday services, meetings, and one-off events.
- `checkin_qr`, `checkin_monitor`, `event_qr` — QR-code check-in flow and
  live attendance dashboard.
- `event_report` — post-event PDF & Excel reports (dompdf, PhpSpreadsheet).

**Public front door & follow-up**
- `Reach` (`modules/reach`) — evangelism logging and outreach follow-up.
- `Embrace` (`modules/embrace`) — new-converts follow-up: 1st-timer capture,
  visitation preferences, prayer requests, invitation source tracking.
- `announcements`, `testimonies` — publish live ministry notices and public
  testimony wall.

**Ministries & content**
- `academy` — Learning Management System (courses, curricula, PDF ebooks).
- `library` — public sermon audio / video / ebook catalogue.
- `media` — sermon and radio media management.
- `junior_church` — kids' ministry curriculum and service files.
- `pastoral`, `zoe` — pastoral care and intercessory prayer requests.
- `charis` — celebrations (birthdays, weddings, anniversaries) with AWOL
  tracking and event report PDFs.
- `envision`, `river_of_life`, `idi`, `mobilization` — ministry-specific
  workflows.

**Finance & operations**
- `finance` — offerings, tithes, and ledger.
- `requisition` — internal purchase requests with PDF export.
- `assets` — church asset register.

**Communication**
- `sms_studio` — BulkSMS-backed transactional and campaign SMS.
  Credentials are encrypted at rest in the `sms_settings` table with
  AES-256-GCM; the vault key lives in `.env`, never in source.
- `contact_extractor` — bulk import of contacts from pasted text.

## Tech stack

- **Runtime:** PHP 8.3 (cPanel `ea-php83`).
- **Database:** MySQL / MariaDB, accessed via PDO with native prepared
  statements (`PDO::ATTR_EMULATE_PREPARES = false`).
- **PHP packages** (`composer.json`):
  - `dompdf/dompdf ^3.1` — PDF export for requisitions, event reports,
    AWOL letters.
  - `phpoffice/phpspreadsheet ^5.7` — Excel export for attendance and
    event reports.
- **Front-end:** Tailwind CSS (CDN in most pages, plus a local
  `tailwindcss-linux-x64` binary + `tailwind.config.js` for
  ahead-of-time builds), jQuery 3.7, Toastify.
- **Third-party services:**
  - Geoapify Geocoder Autocomplete — client-side address search.
  - BulkSMS — SMS delivery (server-side).
  - Google Gemini — LLM helpers (server-side).
- **Server:** Apache / LiteSpeed on cPanel with `.htaccess` +
  `.user.ini` overrides (128 MB memory, 300 s max exec, 128 MB uploads).

## Local setup

Requires PHP 8.1+ (8.3 recommended), MySQL 8, and Composer.

1. Clone the repository:
   ```bash
   git clone https://github.com/tomblakeasaah196/hodlekki.git
   cd hodlekki
   ```
2. Install PHP dependencies:
   ```bash
   composer install
   ```
3. Create your local environment file:
   ```bash
   cp .env.example .env
   ```
   Fill in `DB_*`, `SMS_VAULT_KEY`, `GEMINI_API_KEY`, `GEOAPIFY_API_KEY`.
   Generate a vault key with:
   ```bash
   php -r "echo bin2hex(random_bytes(32));"
   ```
4. Create the database and import the schema (schema files are not stored
   in this repo — request a fresh dump from the maintainer).
5. Point Apache / XAMPP / LiteSpeed at the project root so `/index.php`
   resolves to the site root. On XAMPP, place the folder under
   `htdocs/` and browse to `http://localhost/hodlekki/`.
6. Log in through `/auth/login.php`. First user is provisioned directly
   in the `users` table with `active_role = 'Super_Admin'`.

Tailwind is served via CDN in every page. To rebuild the local bundle
instead, run:

```bash
./tailwindcss-linux-x64 -i input.css -o assets/css/style.css --watch
```

## Deployment

Production runs on a cPanel shared host with MultiPHP set to `ea-php83`
and LiteSpeed. Deploys are automated: every push to `main` triggers a
GitHub Actions workflow (`.github/workflows/deploy.yml`) that lints the
tree, then POSTs (with an `X-Deploy-Token` header) to a webhook on the
live site (`https://hodlc.lpc.cm/webhook/deploy.php`). The webhook runs
[`bin/deploy.sh`](bin/deploy.sh), which does the `git pull`, rsyncs the
repo into the docroot, runs `composer install --no-dev`, and applies
any pending migrations from `db/migrations/`.

The same `bin/deploy.sh` is what cPanel's `.cpanel.yml` runs when you
click **Deploy HEAD Commit** manually, so both automatic and manual
deploys share one code path.

Full pipeline description, first-time wiring steps (webhook secret,
GitHub Secrets / Variables), and rollback instructions live in
[DEPLOY.md](DEPLOY.md).

**Cron (once, in cPanel → Cron Jobs):**
```
* * * * * /usr/local/bin/ea-php83 /home/smartqaq/public_html/hodlc.lpc.cm/cron/sms_queue_worker.php >/dev/null 2>&1
0 7 * * * /usr/local/bin/ea-php83 /home/smartqaq/public_html/hodlc.lpc.cm/cron/reach_lost_souls.php >/dev/null 2>&1
```

**Runtime files preserved across deploys (via `.deployignore`):**
`.env`, `.htaccess`, `.user.ini`, `php.ini`, `uploads/`,
`assets/uploads/`, and any `error_log` files. `vendor/` is regenerated
by `composer install` on the server.

## Database migrations

Every schema change ships as a plain SQL file in `db/migrations/`. The
runner at `db/migrate.php` tracks applied files in a `schema_migrations`
table and executes only new ones during each deploy. Pre-existing prod
schema is preserved via a one-time `0000_baseline` row; nothing that
existed before the first run is re-executed.

Filename convention: `YYYYMMDDhhmmss_short_description.sql`. See
[db/migrations/README.md](db/migrations/README.md) for full rules and
examples.

Locally:

```bash
php db/migrate.php --status    # see applied vs pending
php db/migrate.php --dry-run   # print what would run
php db/migrate.php             # apply pending
```

## Environment variables

Only variable **names** live in git (see `.env.example`). Values live in
the untracked `.env` file.

| Key                | Purpose                                              |
| ------------------ | ---------------------------------------------------- |
| `DB_HOST`          | MySQL host (usually `localhost`).                    |
| `DB_NAME`          | MySQL database name.                                 |
| `DB_USER`          | MySQL user.                                          |
| `DB_PASS`          | MySQL password.                                      |
| `GEMINI_API_KEY`   | Google Gemini API key (server-side LLM calls).       |
| `SMS_VAULT_KEY`    | 64-hex AES-256-GCM key for the SMS Studio vault.     |
| `GEOAPIFY_API_KEY` | Client-side address-autocomplete key (domain-lock in the Geoapify dashboard). |

## Directory structure

```
api/            JSON endpoints, one file per module (auth_api.php, ...).
assets/         Static CSS / JS / images shipped to the browser.
auth/           Login, logout, first-time password setup.
cron/           CLI jobs (sms_queue_worker.php, reach_lost_souls.php daily at 07:00).
includes/       Shared PHP: db.php, header.php, functions.php, PDF helpers,
                sms_functions.php, sms_vault_key.php.
modules/        One folder per ERP module; each has index.php as the view.
uploads/        Runtime member/media uploads (git-ignored).
assets/uploads/ Public event banners and minister photos (git-ignored).
vendor/         Composer dependencies (git-ignored).
```

Top-level PHP files (`index.php`, `connect.php`, `register.php`,
`sermons.php`, `testimonies.php`, `verify.php`, `parent_portal.php`,
`live_radio.php`, `checkin.php`, `idi_mobilization.php`) are the public
entry points.

## Contributing

- Branch naming:
  - `feature/<slug>` — new features.
  - `fix/<slug>` — bug fixes.
  - `chore/<slug>` — refactors, tooling, docs.
  - `claude/<slug>` — branches created by AI agents (Claude Code).
- Commit style: short imperative subject (`fix: correct reach lead
  filter`, `feat: add Zoe intercession queue`).
- Never commit `.env`, live database dumps, member uploads, or anything
  matching `.gitignore`.
- Run a manual smoke of the module you touched before opening the PR.

## License

Proprietary. All rights reserved by Household of David Lekki Centre.
Licence terms to be finalised — placeholder pending owner decision.
