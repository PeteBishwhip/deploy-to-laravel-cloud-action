#!/usr/bin/env bash
set -euo pipefail

PHP_VERSION="${PHP_VERSION:-8.5}"
EXTENSIONS="${EXTENSIONS:-curl,openssl,mbstring,json,zip,phar}"
WORK_DIR="${WORK_DIR:-tools/static-php-cli}"
OUTPUT_PATH="${OUTPUT_PATH:-bin/php-static}"

if [ ! -d "${WORK_DIR}" ]; then
  git clone --depth=1 https://github.com/crazywhalecc/static-php-cli.git "${WORK_DIR}"
fi

pushd "${WORK_DIR}" >/dev/null

if [ ! -f "composer.json" ]; then
  echo "static-php-cli not found at ${WORK_DIR}" >&2
  exit 1
fi

composer install --no-interaction --no-progress

php bin/spc download --with-php="${PHP_VERSION}" --for-extensions="${EXTENSIONS}"

IFS=',' read -r -a EXT_ARRAY <<< "${EXTENSIONS}"
php bin/spc build --build-cli --output="${OUTPUT_PATH}" "${EXT_ARRAY[@]}"

popd >/dev/null

if [ ! -x "${OUTPUT_PATH}" ]; then
  echo "Failed to build PHP binary at ${OUTPUT_PATH}" >&2
  exit 1
fi

chmod +x "${OUTPUT_PATH}"

echo "Built PHP binary: ${OUTPUT_PATH}"
