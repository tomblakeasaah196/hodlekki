#!/usr/bin/env bash
# bin/build_se_css.sh — compile the Special Events stylesheet (guide §23.2).
#
# The cPanel host has no Node, so assets/se/css/se.css is COMPILED HERE and
# COMMITTED. CI runs this script with --check and fails if the committed file
# differs from a fresh build, which is what stops a class name being used in
# JavaScript without the utility reaching production.
#
# Usage:
#   bin/build_se_css.sh            build and write se.css + assets/se/BUILD
#   bin/build_se_css.sh --check    build to a temp file and diff; writes nothing
#
# The Tailwind standalone binary is pinned by assets/se/css/TAILWIND_VERSION
# and is git-ignored: it is a build tool, not a deliverable.

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"

CHECK=0
[ "${1:-}" = "--check" ] && CHECK=1

VERSION_FILE="assets/se/css/TAILWIND_VERSION"
INPUT="assets/se/css/se.input.css"
OUTPUT="assets/se/css/se.css"
BUILD_STAMP="assets/se/BUILD"

[ -f "$VERSION_FILE" ] || { echo "::error::$VERSION_FILE is missing" >&2; exit 1; }
[ -f "$INPUT" ]        || { echo "::error::$INPUT is missing" >&2; exit 1; }

TAILWIND_VERSION="$(tr -d '[:space:]' < "$VERSION_FILE")"

# Pick the right standalone build for this machine. Linux x64 is what CI and
# the dev container use; the others let a developer on a Mac run it too.
case "$(uname -s)-$(uname -m)" in
  Linux-x86_64)   ASSET="tailwindcss-linux-x64" ;;
  Linux-aarch64)  ASSET="tailwindcss-linux-arm64" ;;
  Darwin-arm64)   ASSET="tailwindcss-macos-arm64" ;;
  Darwin-x86_64)  ASSET="tailwindcss-macos-x64" ;;
  *) echo "::error::no Tailwind standalone build for $(uname -s)-$(uname -m)" >&2; exit 1 ;;
esac

BIN="./${ASSET}"

if [ ! -x "$BIN" ]; then
  echo "[se-css] fetching Tailwind ${TAILWIND_VERSION} (${ASSET})"
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
  echo "::error::$BIN is v${ACTUAL_VERSION} but $VERSION_FILE pins ${TAILWIND_VERSION}. Delete $BIN and re-run." >&2
  exit 1
fi

if [ "$CHECK" -eq 1 ]; then
  TMP="$(mktemp -t se_css.XXXXXX.css)"
  trap 'rm -f "$TMP"' EXIT

  "$BIN" -i "$INPUT" -o "$TMP" --minify >/dev/null 2>&1

  if [ ! -f "$OUTPUT" ]; then
    echo "::error file=${OUTPUT}::${OUTPUT} is not committed. Run bin/build_se_css.sh and commit it." >&2
    exit 1
  fi

  if ! diff -q "$OUTPUT" "$TMP" >/dev/null; then
    echo "::error file=${OUTPUT}::${OUTPUT} is stale. Run bin/build_se_css.sh and commit the result." >&2
    echo "--- committed vs fresh build (first 40 lines of diff) ---" >&2
    diff "$OUTPUT" "$TMP" | head -40 >&2 || true
    exit 1
  fi

  echo "[se-css] OK: ${OUTPUT} matches a fresh build ($(wc -c < "$OUTPUT") bytes)"
  exit 0
fi

"$BIN" -i "$INPUT" -o "$OUTPUT" --minify

# The build stamp versions the optional service worker's cache (§8.6.1).
# Keep it to one line: it is read, not parsed.
GIT_SHA="$(git rev-parse --short HEAD 2>/dev/null || echo nogit)"
printf '%s %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$GIT_SHA" > "$BUILD_STAMP"

echo "[se-css] wrote ${OUTPUT} ($(wc -c < "$OUTPUT") bytes) and ${BUILD_STAMP}"
