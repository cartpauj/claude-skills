#!/usr/bin/env bash
# Usage: setup.sh [work-dir]
# Prints the absolute path of the ready work dir on stdout.
# Verifies ImageMagick is on PATH. Playwright lives alongside render.mjs
# in the skill dir, so no per-work-dir npm install is needed.

set -euo pipefail

WORK="${1:-/tmp/h2i-$(date +%s)}"
mkdir -p "$WORK"

if ! command -v convert >/dev/null 2>&1; then
  echo "ERROR: ImageMagick 'convert' not found on PATH." >&2
  echo "  Debian/Ubuntu: sudo apt install imagemagick" >&2
  echo "  macOS:         brew install imagemagick" >&2
  exit 1
fi

# Ensure the skill's own node_modules/playwright is installed (one-time).
SKILL_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
if [ ! -d "$SKILL_DIR/node_modules/playwright" ]; then
  ( cd "$SKILL_DIR" && \
    { [ -f package.json ] || npm init -y >/dev/null 2>&1; } && \
    npm install playwright --silent >/dev/null )
fi

if ! ls "$HOME/.cache/ms-playwright"/chromium-* >/dev/null 2>&1; then
  ( cd "$SKILL_DIR" && npx --yes playwright install chromium >/dev/null 2>&1 || true )
fi

echo "$WORK"
