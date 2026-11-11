#!/usr/bin/env bash
# /bin/uploads_guard.sh
#
# Uploaded files are data, never code. This writes a marked block into
# uploads/.htaccess and assets/uploads/.htaccess (runtime folders, never
# deployed: see .deployignore) that refuses to serve any script file, so even a
# .php that slipped past an upload check could never run. The rules are the
# ones uploads/se/.htaccess has used on this server since the Special Events
# work, with every letter case matched (.PHP, .Phtml ...).
#
# The first layer is includes/upload_guard.php (allow-listed extensions,
# random names). bin/deploy.sh runs this on every deploy; it is safe to run
# again: the block is replaced, anything else in the file is kept.
#
# Usage: uploads_guard.sh [docroot]   (default /home/smartqaq/public_html/hodlekki)

set -euo pipefail

DOCROOT="${1:-/home/smartqaq/public_html/hodlekki}"
BEGIN='# hod-uploads-guard-begin'
END='# hod-uploads-guard-end'

for dir in "$DOCROOT/uploads" "$DOCROOT/assets/uploads"; do
    if [ ! -d "$dir" ]; then
        continue
    fi
    ht="$dir/.htaccess"
    touch "$ht"
    tmp=$(mktemp "$dir/.htaccess.guard.XXXXXX")
    # The current file without any earlier guard block.
    rest=$(awk -v b="$BEGIN" -v e="$END" '$0 == b {skip = 1; next} $0 == e {skip = 0; next} !skip' "$ht")
    {
        echo "$BEGIN"
        echo "# Written by bin/uploads_guard.sh on every deploy. Do not edit by hand."
        echo "Options -Indexes"
        echo '<FilesMatch "\.([Pp][Hh]([Pp][0-9]?|[Tt][Mm][Ll]?|[Aa][Rr]|[Tt]|[Pp][Ss])|[Pp][Ll]|[Pp][Yy]|[Cc][Gg][Ii]|[Ss][Hh]|[Ss][Hh][Tt][Mm][Ll]?)$">'
        echo "  Require all denied"
        echo "</FilesMatch>"
        echo '<Files ".user.ini">'
        echo "  Require all denied"
        echo "</Files>"
        echo "<IfModule mod_headers.c>"
        echo '  Header always set X-Content-Type-Options "nosniff"'
        echo "</IfModule>"
        echo "$END"
        if [ -n "$rest" ]; then
            printf '%s\n' "$rest"
        fi
    } > "$tmp"
    chmod 644 "$tmp"
    mv -f "$tmp" "$ht"
    echo "[uploads-guard] scripts refused in ${dir#"$DOCROOT"/}"
done
