# DB migrations

Forward-only, filename-ordered SQL migrations. Applied by `db/migrate.php`
during every deploy.

## Filename convention

```
YYYYMMDDhhmmss_short_description.sql
```

Examples:

```
20260927150000_add_reach_notes_column.sql
20260930101500_create_embrace_visits_table.sql
```

The timestamp guarantees lexical ordering. Keep the description in
`snake_case`, ideally under 6 words.

## Rules

- **Forward-only.** No down migrations. If you need to reverse a change,
  ship a new migration that does the reversal.
- **Pre-existing schema is baseline.** The very first time the runner
  executes, it inserts a `0000_baseline` row into `schema_migrations`;
  every migration added AFTER that point runs. Do not add a migration
  file called `0000_baseline.sql` or anything that sorts before it —
  the runner will silently skip it.
- **Plain SQL only.** Use `;`-terminated statements. Avoid `DELIMITER`
  blocks (they aren't supported by `PDO::exec`); if you need a stored
  procedure or trigger, put it in its own file and keep the body inline
  with `;`-terminated statements.
- **Transactional per file — for DML only.** Each file runs inside a single
  transaction, but MySQL auto-commits every DDL statement (CREATE / ALTER /
  DROP), so a file that fails halfway through its DDL stays half-applied.
  Keep DDL files to one logical change and make each statement idempotent.
  Any failure still stops the deploy.
- **Idempotency is nice-to-have.** Prefer `CREATE TABLE IF NOT EXISTS`,
  `ADD COLUMN IF NOT EXISTS` (MySQL 8+), and `INSERT ... ON DUPLICATE
  KEY UPDATE` so re-running is safe if something goes wrong mid-way.

## Running locally

```bash
php db/migrate.php            # apply all pending
php db/migrate.php --status   # list applied vs pending
php db/migrate.php --dry-run  # print what would run, don't execute
```

The runner reads `.env` from the project root, connects via PDO, and
tracks applied migrations in the `schema_migrations` table.

## Running in production

The deploy step (`.cpanel.yml`) calls `php db/migrate.php` automatically
after each successful `git pull` + `composer install`. If a migration
fails, the deploy is marked failed in cPanel's Git Version Control log.

## Example

```sql
-- 20260927150000_add_reach_notes_column.sql
-- Adds an internal-notes column visible only to Reach/Evangelism dept.

ALTER TABLE reach_leads
    ADD COLUMN internal_notes TEXT NULL AFTER prayer_requests;

CREATE INDEX idx_reach_leads_status ON reach_leads (status);
```
