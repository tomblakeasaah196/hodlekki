# Deployment

Every push to `main` triggers `.github/workflows/deploy.yml`, which:

1. Runs `php -l` across the tree and a secret sweep (fails on `.env`,
   hardcoded DB creds, or a hardcoded `SMS_VAULT_KEY`).
2. POSTs to a webhook on the live site: `https://hodlc.lpc.cm/webhook/deploy.php`,
   with header `X-Deploy-Token: <DEPLOY_WEBHOOK_SECRET>`.
3. The webhook verifies the token (constant-time compare), then exec's
   [`bin/deploy.sh`](bin/deploy.sh) on the server.
4. `bin/deploy.sh` (held under a `flock` so two deploys can't collide):
   a. `git fetch origin main && git reset --hard origin/main` on
      `/home/smartqaq/repositories/hodlekki`.
   b. Copies the repo → `/home/smartqaq/public_html/hodlc.lpc.cm/` by
      streaming through `tar` (rsync is not installed on this host),
      applying `--exclude-from=.deployignore` so `.env`, root
      `.htaccess`, `.user.ini`, `php.ini`, `uploads/`, `assets/uploads/`,
      and every `error_log` file survive. **The copy never deletes:**
      files removed or renamed in the repo stay in the docroot until
      someone deletes them by hand. `.deployignore` is in tar's pattern
      format, not rsync's — see the comment at the top of that file, and
      the lint job step that verifies it.
   c. `composer install --no-dev --optimize-autoloader`.
   d. `php db/migrate.php` — applies any new files in `db/migrations/`.
   e. `php db/backfill_embrace_sunday_checkins.php` — reconciliation pass,
      runs on *every* deploy (not a one-time migration). Checks every
      active Embrace first-timer record against the Sunday_Service they
      were added for and creates the missing `attendance` row if the
      real-time auto-checkin (see `api/embrace_api.php::add_visitor`)
      ever missed them. See the script's header comment for the exact
      attribution rule and `db/migrations/README.md` for why this isn't
      a normal migration. A failure here is logged, not fatal to the
      deploy.
5. Output is streamed line-by-line back to the Actions log AND mirrored
   to `/home/smartqaq/deploy.log` on the server. If the caller
   disconnects, the deploy still finishes and the full log is available
   in cPanel → Terminal.

## Why a webhook and not cPanel's UAPI

I first wired this against cPanel's `VersionControl::update` UAPI. That
endpoint returns success, but only updates repo metadata — it does NOT
run `git pull`. cPanel Git Version Control is designed around the
assumption that a human clicks "Update from Remote" before every
deploy, which is fine for occasional manual pushes but useless for CI.
The webhook approach owns the `git pull` step itself, so the automation
works end-to-end.

The cPanel Git integration is still wired up as a **manual fallback**:
clicking "Deploy HEAD Commit" in cPanel → Git Version Control → Manage
runs `.cpanel.yml`, which just calls the same `bin/deploy.sh`. Same
recipe, different trigger.

## One-time setup

### 1. On the cPanel server

- **Confirm the repo is registered** in cPanel → Git Version Control at
  `/home/smartqaq/repositories/hodlekki` pointing at
  `https://github.com/tomblakeasaah196/hodlekki.git`. Already done.
- **Install Composer once** if not already at `/home/smartqaq/composer.phar`:
  ```bash
  cd ~ && curl -sS https://getcomposer.org/installer | \
    /usr/local/bin/ea-php83 -d allow_url_fopen=On -- \
    --install-dir=/home/smartqaq --filename=composer.phar
  ```
- **Generate the webhook secret**:
  ```bash
  /usr/local/bin/ea-php83 -r "echo bin2hex(random_bytes(32)) . PHP_EOL;"
  ```
  Copy the 64-char hex string.
- **Add the secret to `.env`** on the server. In cPanel → File Manager
  navigate to `public_html/hodlc.lpc.cm/`, edit `.env`, and append:
  ```
  DEPLOY_WEBHOOK_SECRET="paste-the-64-char-string-here"
  ```
- **Do the first manual deploy** so `bin/deploy.sh`, `webhook/deploy.php`,
  and everything else lands in the docroot:
  1. cPanel → Git Version Control → Manage on `hodlekki` → Pull or
     Deploy tab.
  2. Click **Update from Remote** (fast-forwards the checkout to the
     latest `main`).
  3. Click **Deploy HEAD Commit** (runs `.cpanel.yml`, which invokes
     `bin/deploy.sh`).
  From this point on the webhook is live at
  `https://hodlc.lpc.cm/webhook/deploy.php`.

### 2. In GitHub

Go to https://github.com/tomblakeasaah196/hodlekki → Settings.

**Secrets and variables → Actions → Secrets tab:**

| Name                    | Value                                          |
| ----------------------- | ---------------------------------------------- |
| `DEPLOY_WEBHOOK_SECRET` | The same 64-char hex you just put into `.env`. |

**Secrets and variables → Actions → Variables tab:**

| Name                 | Value                                                     |
| -------------------- | --------------------------------------------------------- |
| `DEPLOY_WEBHOOK_URL` | `https://hodlc.lpc.cm/webhook/deploy.php`                 |

The old `CPANEL_*` variables and the `CPANEL_API_TOKEN` secret are no
longer used and can be deleted (or left — the workflow ignores them).
You can also revoke the `github-actions-deploy` API token in cPanel →
Manage API Tokens; it isn't needed any more.

**Environments tab (optional):** the workflow references
`environment: production`. Create it and add yourself as a required
reviewer if you want every deploy to pause for a click-through approval.

### 3. Verify

Push any small change to `main` (or in GitHub → Actions → the last run
→ Re-run all jobs). Watch:

- Actions → CI + Deploy — the `Deploy via webhook` job should stream
  `[deploy] pre-pull HEAD: ...`, the `[deploy] tar -> ...` line, composer output, and
  finish with `[webhook] DEPLOY OK` + HTTP 200.
- The live site — new commit should be reflected.
- cPanel → Terminal:
  ```bash
  tail -n 200 /home/smartqaq/deploy.log
  ```
  Same log as Actions saw, plus every previous deploy's output.

## Rollback

`bin/deploy.sh` uses `git reset --hard origin/main`, so rollbacks are:

1. In your GitHub repo: `git revert <bad-commit-sha>` and push.
2. Actions will deploy the revert automatically.

DB rollbacks are **not automatic**. If a migration needs undoing, write
a new forward migration that reverses it (see
[db/migrations/README.md](db/migrations/README.md)).

## Manual deploy (if Actions or the webhook is down)

Same recipe, different trigger:

1. cPanel → Git Version Control → Manage on `hodlekki` → **Pull or
   Deploy** tab.
2. **Update from Remote** — brings the checkout to latest `main`.
3. **Deploy HEAD Commit** — runs `.cpanel.yml`, which runs
   `bin/deploy.sh`.

Or from cPanel → Terminal directly:
```bash
bash /home/smartqaq/repositories/hodlekki/bin/deploy.sh
```

## Security notes on the webhook

- POST-only; GET/HEAD/OPTIONS return 405.
- The `X-Deploy-Token` header is compared to the value in `.env` using
  `hash_equals()` (constant-time), so timing attacks can't leak the
  secret one byte at a time.
- The secret lives only in `.env` (untracked) and GitHub Secrets. It is
  never written to any log. curl passes it as an HTTP header, so it
  does not appear in Apache access logs.
- Rejected requests are recorded to PHP's `error_log` with the client
  IP and truncated user-agent so probes are visible.
- `webhook/.htaccess` denies every file under `webhook/` except
  `deploy.php` and disables directory listing.
- Rotate the secret by generating a new 64-char string, updating both
  `.env` on the server and the GitHub Secret, and pushing again. No
  downtime.
