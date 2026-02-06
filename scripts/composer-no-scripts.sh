#!/usr/bin/env bash
set -euo pipefail

if ! command -v composer >/dev/null 2>&1; then
  echo "composer not found on PATH" >&2
  exit 1
fi

exec composer "$@" --no-scripts
