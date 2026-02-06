#!/usr/bin/env bash
set -euo pipefail

BOX_PATH="${BOX_PATH:-tools/box}"

if [ ! -f "vendor/autoload.php" ]; then
  composer install --no-interaction --no-progress --prefer-dist
fi

rm -f bootstrap/cache/*.php

if [ ! -f "${BOX_PATH}" ]; then
  mkdir -p "$(dirname "${BOX_PATH}")"
  curl -sSLo "${BOX_PATH}" https://github.com/humbug/box/releases/latest/download/box.phar
  chmod +x "${BOX_PATH}"
fi

php "${BOX_PATH}" compile -c box.json --composer-bin "$(pwd)/scripts/composer-no-scripts.sh"

if [ ! -f "dist/laravel-cloud-deploy.phar" ]; then
  echo "PHAR build failed." >&2
  exit 1
fi

echo "Built dist/laravel-cloud-deploy.phar"
