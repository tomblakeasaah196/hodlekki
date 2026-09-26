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
#
# Only one deploy runs at a time (flock on /home/smartqaq/.deploy.lock).
# Full log always mirrored to /home/smartqaq/deploy.log so you can review
# the last run from cPanel Terminal even if the caller disconnected.

set -euo pipefail

REPO=/home/smartqaq/repositories/hodlekki
DEPLOYPATH=/home/smartqaq/public_html/hodlc.lpc.cm
PHP=/usr/local/bin/ea-php83
COMPOSER=/home/smartqaq/composer.phar
LOCKFILE=/home/smartqaq/.deploy.lock
LOGFILE=/home/smartqaq/deploy.log

# Serialise deploys. If another deploy is in flight, refuse rather than wait.
exec 9>"$LOCKFILE"
if ! flock -n 9; then
    echo "[deploy] another deploy is already running; refusing to start a second one" >&2
    exit 75   # EX_TEMPFAIL
fi

# Mirror stdout+stderr to LOGFILE.
exec > >(tee -a "$LOGFILE") 2>&1

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

# 2. Sync the repo tree into the live docroot, preserving runtime files.
if [ ! -f "$REPO/.deployignore" ]; then
    echo "[deploy] $REPO/.deployignore missing — aborting" >&2
    exit 1
fi
/bin/rsync -a --delete --exclude-from="$REPO/.deployignore" "$REPO/" "$DEPLOYPATH/"
echo "[deploy] rsync -> $DEPLOYPATH done"

# 3. Refresh Composer dependencies. -d allow_url_fopen=On because this host's
#    CLI php.ini disables it and Composer needs it to reach packagist.
cd "$DEPLOYPATH"
"$PHP" -d allow_url_fopen=On "$COMPOSER" install \
    --no-dev --optimize-autoloader --no-interaction 2>&1 | tail -40
echo "[deploy] composer install done"

# 4. Apply any pending SQL migrations.
"$PHP" db/migrate.php

echo "============================================================"
echo "[deploy] $(date -Iseconds) OK"
echo "============================================================"
