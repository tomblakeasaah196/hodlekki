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
DEPLOYPATH=/home/smartqaq/public_html/hodlc.lpc.cm
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
# --no-overwrite-dir: without it, the archive's "." entry copies the repo
# root's 700 mode onto the docroot, LiteSpeed (group nobody) can no longer
# enter it, and the whole site returns 403/404.
tar cf - --exclude-from="$REPO/.deployignore" -C "$REPO" . \
  | ( cd "$DEPLOYPATH" && tar xpf - --no-overwrite-dir )
echo "[deploy] tar -> $DEPLOYPATH done"

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

echo "============================================================"
echo "[deploy] $(date -Iseconds) OK"
echo "============================================================"
