#!/usr/bin/env bash
# /bin/edge_lock.sh
#
# Origin lock: only Cloudflare may talk to this site.
#
# Every hodlekki.org name (hodlekki.org, www, connect) is proxied by Cloudflare,
# so a request that did not come through Cloudflare has skipped its protection.
# This server shows the visitor's own address as REMOTE_ADDR (LiteSpeed takes it
# from Cloudflare's header), so Cloudflare's address ranges cannot be told apart
# here. Instead Cloudflare adds a secret header, X-Hodlekki-Edge, to every request
# it forwards (a Transform Rule in the hodlekki.org zone), and anything without
# it is refused with 403.
#
# The secret is EDGE_TOKEN in the docroot .env. This script writes it into a
# marked block at the top of the docroot .htaccess (a server-managed file, never
# deployed: see .deployignore), or removes the block when .env has no EDGE_TOKEN.
# It never prints the token. /.well-known/ stays open for certificate checks.
#
# Order matters, or everyone is locked out (the deploy webhook included):
#   1. Cloudflare's Transform Rule sends the header,
#   2. then EDGE_TOKEN goes into .env,
#   3. then this script runs (bin/deploy.sh calls it on every deploy).
# To unlock in an emergency: delete the EDGE_TOKEN line from .env and run
#   bash /home/smartqaq/repositories/hodlekki/bin/edge_lock.sh
# (or remove the block between the marker lines in .htaccess by hand).
#
# Usage: edge_lock.sh [docroot]   (default /home/smartqaq/public_html/hodlekki)

set -euo pipefail

DOCROOT="${1:-/home/smartqaq/public_html/hodlekki}"
HTACCESS="$DOCROOT/.htaccess"
ENVFILE="$DOCROOT/.env"
BEGIN='# hod-edge-lock-begin'
END='# hod-edge-lock-end'

token=''
if [ -f "$ENVFILE" ]; then
    token=$(grep -E '^[[:space:]]*EDGE_TOKEN[[:space:]]*=' "$ENVFILE" | tail -1 | cut -d= -f2- | tr -d "\"' \r\t" || true)
fi

touch "$HTACCESS"
tmp=$(mktemp "$DOCROOT/.htaccess.edge.XXXXXX")
trap 'rm -f "$tmp"' EXIT

# The current file without any earlier lock block.
rest=$(awk -v b="$BEGIN" -v e="$END" '$0 == b {skip = 1; next} $0 == e {skip = 0; next} !skip' "$HTACCESS")

if [ -n "$token" ]; then
    if ! printf '%s' "$token" | grep -Eq '^[A-Za-z0-9]{32,128}$'; then
        echo "[edge-lock] EDGE_TOKEN in .env must be 32-128 letters and digits; lock NOT changed" >&2
        exit 1
    fi
    {
        echo "$BEGIN"
        echo "# Written by bin/edge_lock.sh from EDGE_TOKEN in .env. Do not edit by hand."
        echo "RewriteEngine On"
        echo 'RewriteCond %{REQUEST_URI} !^/\.well-known/'
        echo "RewriteCond %{HTTP:X-Hodlekki-Edge} !^${token}\$"
        echo 'RewriteRule .* - [F,L]'
        echo "$END"
        printf '%s\n' "$rest"
    } > "$tmp"
    state='ON'
else
    printf '%s\n' "$rest" > "$tmp"
    state='OFF (no EDGE_TOKEN in .env)'
fi

chmod 644 "$tmp"
mv -f "$tmp" "$HTACCESS"
trap - EXIT
echo "[edge-lock] origin lock $state in $HTACCESS"
