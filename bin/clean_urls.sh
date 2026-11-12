#!/usr/bin/env bash
# /bin/clean_urls.sh
#
# Clean addresses: no page address ends in .php (owner, Nov 2026).
#
#   hodlekki.org/testimonies           is served by testimonies.php
#   hodlekki.org/modules/mail/         is served by modules/mail/index.php
#   hodlekki.org/testimonies.php       301 -> /testimonies      (query string kept)
#   hodlekki.org/modules/mail/index.php 301 -> /modules/mail/   (query string kept)
#
# Old addresses keep working (WhatsApp links, bookmarks, printed QR codes, the
# notification links already in the database) and whatever people copy from
# then on is clean. The app's own links are already clean, so they never pay
# for a redirect.
#
# Never redirected or rewritten: /api/, /webhook/ (the deploy webhook POSTs
# there), /email/ (one-click unsubscribe and the links in emails already sent),
# /includes/, /cron/, /db/, /bin/, /assets/, /uploads/, /e/ and /live/ (they
# have their own rules), /.well-known/, manifest.php and sw.js. Only GET and
# HEAD are ever redirected, so a POST never loses its body. The redirect reads
# THE_REQUEST (the browser's own request line), so the internal rewrite can
# never loop back into it.
#
# connect.hodlekki.org keeps serving Connect at its root: "/" is index.php
# (DirectoryIndex), which shows Connect on that host; no rule here touches "/".
#
# This writes a marked block into the docroot .htaccess (a server-managed file,
# never deployed: see .deployignore) RIGHT AFTER the origin lock's block, so
# the lock still runs first (bin/edge_lock.sh). Anything else in the file is
# kept. bin/deploy.sh runs it on every deploy, after the edge lock; it is safe
# to run again. To switch clean addresses off: run it with "off" as the second
# argument (the block is removed; old .php links keep working, clean ones 404).
#
# Usage: clean_urls.sh [docroot] [on|off]   (default /home/smartqaq/public_html/hodlekki on)

set -euo pipefail

DOCROOT="${1:-/home/smartqaq/public_html/hodlekki}"
MODE="${2:-on}"
HTACCESS="$DOCROOT/.htaccess"
BEGIN='# hod-clean-urls-begin'
END='# hod-clean-urls-end'
LOCK_END='# hod-edge-lock-end'

touch "$HTACCESS"
tmp=$(mktemp "$DOCROOT/.htaccess.clean.XXXXXX")
trap 'rm -f "$tmp"' EXIT

# The current file without any earlier clean-urls block.
rest=$(awk -v b="$BEGIN" -v e="$END" '$0 == b {skip = 1; next} $0 == e {skip = 0; next} !skip' "$HTACCESS")

block=$(cat <<'RULES'
# hod-clean-urls-begin
# Written by bin/clean_urls.sh. Do not edit by hand.
<IfModule mod_rewrite.c>
RewriteEngine On

# 1. /dir/index.php -> /dir/ (301, GET and HEAD only, query string kept)
RewriteCond %{REQUEST_METHOD} ^(GET|HEAD)$
RewriteCond %{REQUEST_URI} !^/(?:api|webhook|email|includes|cron|db|bin|assets|uploads|e|live|\.well-known)/ [NC]
RewriteCond %{THE_REQUEST} \s/+((?:[^\s?/]+/)*)index\.php[\s?] [NC]
RewriteRule ^ /%1 [R=301,L,NE]

# 2. /page.php -> /page (301, GET and HEAD only, query string kept)
RewriteCond %{REQUEST_METHOD} ^(GET|HEAD)$
RewriteCond %{REQUEST_URI} !^/(?:api|webhook|email|includes|cron|db|bin|assets|uploads|e|live|\.well-known)/ [NC]
RewriteCond %{REQUEST_URI} !^/manifest\.php$ [NC]
RewriteCond %{THE_REQUEST} \s/+([^\s?]+)\.php[\s?] [NC]
RewriteRule ^ /%1 [R=301,L,NE]

# 3. /page is served by page.php when that file exists (query string kept)
RewriteCond %{REQUEST_URI} !^/(?:api|webhook|email|includes|cron|db|bin|assets|uploads|e|live|\.well-known)/ [NC]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteCond %{REQUEST_FILENAME}.php -f
RewriteRule ^(.+)$ $1.php [L]
</IfModule>
# hod-clean-urls-end
RULES
)

if [ "$MODE" = "off" ]; then
    printf '%s\n' "$rest" > "$tmp"
    state='OFF'
else
    # Right after the origin lock's block when there is one, else at the top.
    # (The rules never pass through awk: awk -v would eat their backslashes.)
    if printf '%s\n' "$rest" | grep -qxF "$LOCK_END"; then
        {
            printf '%s\n' "$rest" | awk -v e="$LOCK_END" '{print} $0 == e {exit}'
            printf '%s\n' "$block"
            printf '%s\n' "$rest" | awk -v e="$LOCK_END" 'found {print} $0 == e {found = 1}'
        } > "$tmp"
    else
        { printf '%s\n' "$block"; printf '%s\n' "$rest"; } > "$tmp"
    fi
    state='ON'
fi

chmod 644 "$tmp"
mv -f "$tmp" "$HTACCESS"
trap - EXIT
echo "[clean-urls] clean addresses $state in $HTACCESS"
