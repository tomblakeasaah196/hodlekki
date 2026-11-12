#!/usr/bin/env bash
# Deploy hodlekki to the live docroot.
#
# Runs the same steps regardless of who calls it:
#   - webhook/deploy.php (triggered by GitHub Actions on push to main)
#   - .cpanel.yml (triggered by clicking "Deploy HEAD Commit" in cPanel)
#   - a human running `bash bin/deploy.sh` from cPanel Terminal
#
# Steps:
#   1. git fetch + hard reset to origin/main (no local commits ever survive here)
#   2. rsync repo -> docroot, applying .deployignore
#   3. composer install --no-dev
#   4. php db/migrate.php
#   5. php db/backfill_embrace_sunday_checkins.php
#   6. php db/backfill_status_ladder.php
#
# Only one deploy runs at a time (flock on /home/smartqaq/.deploy.lock).
# Full log always mirrored to /home/smartqaq/deploy.log so you can review
# the last run from cPanel Terminal even if the caller disconnected.

LOGFILE=/home/smartqaq/deploy.log

# Re-exec ourselves through a plain pipe to tee so everything we print is
# BOTH shown to the caller AND appended to LOGFILE. This host does not
# expose /dev/fd, so `exec > >(tee ...)` (process substitution) fails
# with "/dev/fd/63: No such file or directory". A plain pipe works
# everywhere. PIPESTATUS preserves the script's real exit code.
if [ -z "${DEPLOY_LOG_WRAPPED:-}" ]; then
    export DEPLOY_LOG_WRAPPED=1
    set -o pipefail
    bash "$0" "$@" 2>&1 | tee -a "$LOGFILE"
    exit "${PIPESTATUS[0]}"
fi

set -euo pipefail

# The webhook runs us from LiteSpeed's PHP, which doesn't set HOME, and
# Composer refuses to start without HOME or COMPOSER_HOME.
export HOME="${HOME:-/home/smartqaq}"

REPO=/home/smartqaq/repositories/hodlekki
DEPLOYPATH=/home/smartqaq/public_html/hodlekki
PHP=/usr/local/bin/ea-php83
COMPOSER=/home/smartqaq/composer.phar
LOCKFILE=/home/smartqaq/.deploy.lock

# Serialise deploys. If another deploy is in flight, refuse rather than wait.
exec 9>"$LOCKFILE"
if ! flock -n 9; then
    echo "[deploy] another deploy is already running; refusing to start a second one" >&2
    exit 75   # EX_TEMPFAIL
fi

echo ""
echo "============================================================"
echo "[deploy] $(date -Iseconds) starting"
echo "[deploy] caller uid=$(id -u) whoami=$(whoami)"
echo "============================================================"

# 1. Refresh the git checkout to whatever is on origin/main right now.
cd "$REPO"
echo "[deploy] pre-pull HEAD: $(git rev-parse HEAD)"
git fetch origin main --quiet
git reset --hard origin/main
echo "[deploy] post-pull HEAD: $(git rev-parse HEAD)"
git log -1 --pretty='[deploy] commit: %h %s (%an)'

# 2. Copy the repo tree into the live docroot, honouring .deployignore.
#    rsync is not installed on this cPanel host, so we stream through tar.
#    tar's --exclude-from uses the same simple pattern format .deployignore
#    already uses. NOTE: this does NOT do rsync's --delete — files removed
#    from the repo will linger in the docroot. Runtime files (.env,
#    .htaccess, uploads/, error_log, ...) are still protected because
#    they're never copied over in the first place.
if [ ! -f "$REPO/.deployignore" ]; then
    echo "[deploy] $REPO/.deployignore missing — aborting" >&2
    exit 1
fi
mkdir -p "$DEPLOYPATH"
# The tree is unpacked into a staging folder in the account's home (never
# under public_html, so nothing in it can be served), then each file is MOVED
# into place. A move on the same disk is atomic, so a page or cron job never
# reads a half-written file. Writing straight into the docroot let a request
# or a cron run that overlapped the copy hit "Unclosed '('" or a missing
# include for a second (the monitor recorded those). Library files
# (includes/, assets/, db/) land before the pages and crons that load them.
# Existing folders keep their modes: only missing ones are created (0755),
# so the docroot itself is never touched (a 700 docroot is a site-wide 403).
STAGE="${DEPLOY_STAGE:-$(cd "$REPO/../.." && pwd)/.hodlekki_deploy_stage}"
rm -rf "$STAGE"
mkdir -p "$STAGE"
tar cf - --exclude-from="$REPO/.deployignore" -C "$REPO" . \
  | ( cd "$STAGE" && tar xpf - )
( cd "$STAGE" && find . -mindepth 1 -type d -print0 ) | while IFS= read -r -d '' d; do
    [ -d "$DEPLOYPATH/${d#./}" ] || mkdir -m 0755 -p "$DEPLOYPATH/${d#./}"
done
( cd "$STAGE" && find . \( -path './includes/*' -o -path './assets/*' -o -path './db/*' \) \( -type f -o -type l \) -print0 ) \
  | while IFS= read -r -d '' f; do mv -f "$STAGE/${f#./}" "$DEPLOYPATH/${f#./}"; done
( cd "$STAGE" && find . \( -type f -o -type l \) -print0 ) \
  | while IFS= read -r -d '' f; do mv -f "$STAGE/${f#./}" "$DEPLOYPATH/${f#./}"; done
rm -rf "$STAGE"
echo "[deploy] staged and moved into $DEPLOYPATH"

# 2a. Retired files: tar never deletes, so files removed from the repo are
#     removed from the docroot here, from the reviewed list in
#     bin/retired_files.txt (relative paths only; anything odd is skipped).
if [ -f "$REPO/bin/retired_files.txt" ]; then
    while IFS= read -r rel || [ -n "$rel" ]; do
        rel="${rel%$'\r'}"
        case "$rel" in ''|'#'*) continue ;; esac
        case "$rel" in /*|*..*) echo "[deploy] retired: skipped unsafe path '$rel'" >&2; continue ;; esac
        if [ -f "$DEPLOYPATH/$rel" ]; then
            rm -f -- "$DEPLOYPATH/$rel"
            echo "[deploy] retired: removed $rel"
        fi
    done < "$REPO/bin/retired_files.txt"
fi

# 2b. Origin lock: only Cloudflare may reach the site (bin/edge_lock.sh). On
#     when the docroot .env has EDGE_TOKEN, off when it has none. Run from the
#     repo checkout, so the script itself is always the version just pulled.
bash "$REPO/bin/edge_lock.sh" "$DEPLOYPATH"

# 2c. Uploaded files are data, never code: uploads/ and assets/uploads/ get a
#     marked .htaccess block refusing any script file (bin/uploads_guard.sh).
bash "$REPO/bin/uploads_guard.sh" "$DEPLOYPATH" || echo "[deploy] WARNING: uploads guard failed (not fatal)"

# 3. Refresh Composer dependencies. -d allow_url_fopen=On because this host's
#    CLI php.ini disables it and Composer needs it to reach packagist.
cd "$DEPLOYPATH"
"$PHP" -d allow_url_fopen=On "$COMPOSER" install \
    --no-dev --optimize-autoloader --no-interaction \
    --ignore-platform-req=ext-fileinfo 2>&1 | tail -40
echo "[deploy] composer install done"

# 4. Apply any pending SQL migrations.
"$PHP" db/migrate.php

# 5. Reconciliation pass: check in any Embrace first-timer who slipped
#    through without an attendance record for the Sunday they were added.
#    Unlike step 4 this is NOT a one-time migration — it's idempotent and
#    deliberately re-runs on every single deploy as a standing safety net.
#    A failure here is logged but never fails the deploy (see the script's
#    own exit-code handling), so a data hiccup can't block shipping code.
"$PHP" db/backfill_embrace_sunday_checkins.php

# 6. Sunday ladder catch-up: move any 1st/2nd/3rd Timer to the step their
#    Sunday attendance has earned (forward only; see includes/status_ladder.php).
#    Same standing-safety-net contract as step 5: idempotent, re-runs on every
#    deploy, and nothing that goes wrong in it fails the deploy (the code is
#    already live by now, and the next deploy simply tries again). It must run
#    AFTER step 4 -- it needs user_status_history, and does nothing if that
#    table is not there yet.
"$PHP" db/backfill_status_ladder.php || echo "[deploy] WARNING: status ladder catch-up failed (not fatal) — see output above"

# No cache-clearing step, on purpose. NGINX on this host serves static files
# straight from disk and PHP pages send no-store, so nothing here holds stale
# copies; and uapi cannot run from the webhook's restricted (CageFS) shell
# ("Failed to execute /usr/local/cpanel/cpanel"). Cloudflare bypasses its
# cache for /sw.js through a cache rule, and assets carry ?v= hashes.

echo "============================================================"
echo "[deploy] $(date -Iseconds) OK"
echo "============================================================"
