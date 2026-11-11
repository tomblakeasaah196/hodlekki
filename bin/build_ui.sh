#!/usr/bin/env bash
# bin/build_ui.sh — build the members' app front end (docs/UI.md).
#
# The cPanel host has no Node, so everything here is BUILT HERE and COMMITTED:
#   assets/ui/app.css                 Tailwind v3 (tokens, components, utilities)
#   assets/ui/icons.svg               the icon sprite, from assets/ui/src/icons/*.svg
#   includes/ui/asset_manifest.php    content hashes, so every URL is ?v=<hash>
#                                     and the files can be cached for a year
#
# CI runs this with --check and fails if any committed output differs from a
# fresh build — that is what stops a class used in markup from reaching
# production without its CSS. Nearly every page is scanned (see the content
# list in assets/ui/src/tailwind.config.js), so after changing markup, a
# script under assets/ui/js or an icon: run this, commit the outputs in the
# SAME commit.
#
# Usage:
#   bin/build_ui.sh            build and write the outputs
#   bin/build_ui.sh --check    build to a temp dir and compare; writes nothing
#
# Needs: bash, curl (first run only), php on PATH (or PHP=/path/to/php).
# The Tailwind standalone binary is pinned by assets/ui/src/TAILWIND_VERSION
# and git-ignored.

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"

CHECK=0
[ "${1:-}" = "--check" ] && CHECK=1

PHP_BIN="${PHP:-php}"
VERSION_FILE="assets/ui/src/TAILWIND_VERSION"
CONFIG="assets/ui/src/tailwind.config.js"
INPUT="assets/ui/src/app.css"
CSS_OUT="assets/ui/app.css"
SPRITE_OUT="assets/ui/icons.svg"
MANIFEST_OUT="includes/ui/asset_manifest.php"

for f in "$VERSION_FILE" "$CONFIG" "$INPUT"; do
  [ -f "$f" ] || { echo "::error::$f is missing" >&2; exit 1; }
done

TAILWIND_VERSION="$(tr -d '[:space:]' < "$VERSION_FILE")"

case "$(uname -s)-$(uname -m)" in
  Linux-x86_64)                 ASSET="tailwindcss-linux-x64" ;;
  Linux-aarch64)                ASSET="tailwindcss-linux-arm64" ;;
  Darwin-arm64)                 ASSET="tailwindcss-macos-arm64" ;;
  Darwin-x86_64)                ASSET="tailwindcss-macos-x64" ;;
  MINGW*-x86_64|MSYS*-x86_64|CYGWIN*-x86_64) ASSET="tailwindcss-windows-x64.exe" ;;
  *) echo "::error::no Tailwind standalone build for $(uname -s)-$(uname -m)" >&2; exit 1 ;;
esac

# The binary is shared with nothing else: the Special Events build pins v4
# and names its download the same way, so keep ours apart.
BIN_DIR=".tailwind/v${TAILWIND_VERSION}"
BIN="${BIN_DIR}/${ASSET}"
if [ ! -x "$BIN" ]; then
  mkdir -p "$BIN_DIR"
  echo "[ui] fetching Tailwind ${TAILWIND_VERSION} (${ASSET})"
  URL="https://github.com/tailwindlabs/tailwindcss/releases/download/v${TAILWIND_VERSION}/${ASSET}"
  if ! curl -fsSL --retry 3 --retry-delay 2 -o "$BIN" "$URL"; then
    echo "::error::could not download ${URL}" >&2
    rm -f "$BIN"
    exit 1
  fi
  chmod +x "$BIN"
fi

ACTUAL_VERSION="$("$BIN" --help 2>&1 | grep -oE 'tailwindcss v[0-9.]+' | head -1 | sed 's/tailwindcss v//' || true)"
if [ -n "$ACTUAL_VERSION" ] && [ "$ACTUAL_VERSION" != "$TAILWIND_VERSION" ]; then
  echo "::error::$BIN is v${ACTUAL_VERSION} but $VERSION_FILE pins ${TAILWIND_VERSION}. Delete it and re-run." >&2
  exit 1
fi

build_into() {
  # $1 = directory that receives app.css, icons.svg and asset_manifest.php
  local out="$1"
  "$BIN" -c "$CONFIG" -i "$INPUT" -o "$out/app.css" --minify >/dev/null 2>"$out/tailwind.log" || {
    cat "$out/tailwind.log" >&2
    echo "::error::Tailwind build failed" >&2
    return 1
  }
  "$PHP_BIN" bin/build_ui_icons.php "$out/icons.svg"
  # The manifest hashes the FRESH css and sprite, plus the committed scripts.
  "$PHP_BIN" bin/build_ui_manifest.php "$out/asset_manifest.php" "$out/app.css" "$out/icons.svg"
}

# Line endings: a Windows checkout has CRLF text files; compare on LF.
same_file() {
  cmp -s <(tr -d '\r' < "$1") <(tr -d '\r' < "$2")
}

if [ "$CHECK" -eq 1 ]; then
  TMP="$(mktemp -d)"
  trap 'rm -rf "$TMP"' EXIT
  build_into "$TMP"

  fail=0
  for pair in "app.css:$CSS_OUT" "icons.svg:$SPRITE_OUT" "asset_manifest.php:$MANIFEST_OUT"; do
    fresh="$TMP/${pair%%:*}"
    committed="${pair#*:}"
    if [ ! -f "$committed" ]; then
      echo "::error file=${committed}::${committed} is not committed. Run bin/build_ui.sh and commit it." >&2
      fail=1
    elif ! same_file "$committed" "$fresh"; then
      echo "::error file=${committed}::${committed} is stale. Run bin/build_ui.sh and commit the result." >&2
      if [ "${pair%%:*}" = "app.css" ]; then
        echo "--- classes in a fresh build but not in the committed file (first 30) ---" >&2
        diff <(tr -d '\r' < "$committed" | tr '}' '\n') <(tr -d '\r' < "$fresh" | tr '}' '\n') | grep '^>' | head -30 >&2 || true
      fi
      fail=1
    fi
  done
  [ "$fail" -eq 0 ] && echo "[ui] OK: app.css ($(wc -c < "$CSS_OUT") bytes), icons.svg and the asset manifest match a fresh build"
  exit $fail
fi

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
build_into "$TMP"
cp "$TMP/app.css" "$CSS_OUT"
cp "$TMP/icons.svg" "$SPRITE_OUT"
cp "$TMP/asset_manifest.php" "$MANIFEST_OUT"

gz() { gzip -9 -c "$1" | wc -c | tr -d ' '; }
echo "[ui] wrote ${CSS_OUT} ($(wc -c < "$CSS_OUT") bytes, $(gz "$CSS_OUT") gzipped), ${SPRITE_OUT} ($(wc -c < "$SPRITE_OUT") bytes), ${MANIFEST_OUT}"
