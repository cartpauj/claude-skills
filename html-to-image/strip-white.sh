#!/usr/bin/env bash
# Usage: strip-white.sh <input> [output.png] [fuzz%]
# Converts a JPG/PNG with a solid white background into a transparent-bg PNG.
# Useful for dropping brand logos onto dark designs.

set -euo pipefail

IN="${1:?usage: strip-white.sh <input> [output.png] [fuzz%]}"
OUT="${2:-${IN%.*}-transparent.png}"
FUZZ="${3:-8}"

convert "$IN" -fuzz "${FUZZ}%" -transparent white -trim +repage "$OUT"
echo "$OUT"
