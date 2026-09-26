# Deployment

Every push to `main` triggers a GitHub Actions workflow (`.github/workflows/deploy.yml`)
that:

1. Runs `php -l` across the tree and a small secret sweep (fails on `.env`,
   hardcoded DB creds, or a hardcoded `SMS_VAULT_KEY`).
2. Calls the cPanel UAPI over HTTPS on port 2083 to:
   a. `git pull` the repo at `/home/smartqaq/repositories/hodlekki`.
   b. Queue a deployment, which runs `.cpanel.yml`.
3. `.cpanel.yml` on the server:
   a. `rsync`s the repo → `/home/smartqaq/public_html/hodlc.lpc.cm/`,
      preserving `.env`, `.htaccess`, `.user.ini`, `uploads/`, and
      `error_log` via `.deployignore`.
   b. `composer install --no-dev --optimize-autoloader`.
   c. `php db/migrate.php` — applies any new files in `db/migrations/`.
4. Actions polls the deployment queue until it drains, then marks the run
   green (or red).

No incoming SSH is used — everything runs over the cPanel HTTPS API from
GitHub's runners.

## One-time setup

### 1. On the cPanel server

- **Confirm the repo is registered** in cPanel → Git Version Control at
  `/home/smartqaq/repositories/hodlekki` pointing at
  `https://github.com/tomblakeasaah196/hodlekki.git`. It's already there
  from the manual clone test.
- **Install Composer once** into the home directory (outside the docroot):
  ```bash
  # In cPanel → Terminal:
  cd ~
  curl -sS https://getcomposer.org/installer | /usr/local/bin/ea-php83 -- --install-dir=/home/smartqaq --filename=composer.phar
  ```
  The `.cpanel.yml` recipe expects `/home/smartqaq/composer.phar`.
- **Create a cPanel API token** at cPanel → Manage API Tokens. Give it a
  memorable name (`github-actions-deploy`) and no expiry (or 1 year, then
  set a reminder to rotate). Copy the token value once — cPanel does not
  show it again.

### 2. In GitHub

Go to https://github.com/tomblakeasaah196/hodlekki → Settings.

**Secrets and variables → Actions → Secrets tab:**

| Name                | Value                                           |
| ------------------- | ----------------------------------------------- |
| `CPANEL_API_TOKEN`  | The token from step 1.                          |

**Secrets and variables → Actions → Variables tab:**

| Name                | Value                                              |
| ------------------- | -------------------------------------------------- |
| `CPANEL_HOST`       | `srv-web-ns9.newtoncorp.fr`                        |
| `CPANEL_USER`       | `smartqaq`                                         |
| `CPANEL_REPO_ROOT`  | `/home/smartqaq/repositories/hodlekki`             |

**Environments → New environment → `production`** (optional but
recommended): add yourself as a required reviewer if you want deploys to
pause for a click-through. The workflow references `environment: production`.

### 3. First deploy sanity check

After the secrets/vars are in place, push any small change to `main` and
watch the run at Actions → CI + Deploy. If everything is wired correctly
you should see:

```
Deploy queue empty — deployment finished.
```

Then verify on the site itself.

## Rollback

Git Version Control keeps every deployed HEAD in cPanel's history. To
roll back:

1. cPanel → Git Version Control → **Manage** on `hodlekki` → **Pull or
   Deploy** tab.
2. Find the previous commit in the history, click **Deploy HEAD Commit**
   against the earlier SHA (or `git reset --hard <sha>` in cPanel
   Terminal, then click Deploy).
3. DB rollbacks are **not automatic**. If a migration needs undoing,
   write a new forward migration that reverses it.

## Emergency: skip the pipeline

If GitHub is down or the workflow is broken, you can deploy manually:

1. cPanel → Git Version Control → Manage on `hodlekki` → **Pull or
   Deploy** tab.
2. Click **Update from Remote** (does the `git pull`).
3. Click **Deploy HEAD Commit** (runs `.cpanel.yml`).

Same recipe, no GitHub involvement.
