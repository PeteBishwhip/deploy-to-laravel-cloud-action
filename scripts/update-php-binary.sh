#!/usr/bin/env bash
set -euo pipefail

PHP_VERSION="${PHP_VERSION:-8.5}"
EXTENSIONS="${EXTENSIONS:-curl,openssl,mbstring,json,zip,phar}"
WORK_DIR="${WORK_DIR:-tools/static-php-cli}"
OUTPUT_PATH="${OUTPUT_PATH:-bin/php}"

if [ ! -d "${WORK_DIR}" ]; then
  git clone --depth=1 https://github.com/crazywhalecc/static-php-cli.git "${WORK_DIR}"
fi

pushd "${WORK_DIR}" >/dev/null

if [ ! -f "composer.json" ]; then
  echo "static-php-cli not found at ${WORK_DIR}" >&2
  exit 1
fi

composer install --no-interaction --no-progress

cat > craft.yml <<EOF
php-version: ${PHP_VERSION}
extensions: "${EXTENSIONS}"
sapi:
  - cli
download-options:
  prefer-pre-built: true
EOF

php bin/spc craft

popd >/dev/null

rm -rf "${WORK_DIR}"

BUILD_OUTPUT="${WORK_DIR}/buildroot/bin/php"
if [ ! -x "${BUILD_OUTPUT}" ]; then
  echo "Failed to build PHP binary at ${BUILD_OUTPUT}" >&2
  exit 1
fi

cp "${BUILD_OUTPUT}" "${OUTPUT_PATH}"
chmod +x "${OUTPUT_PATH}"

echo "Built PHP binary: ${OUTPUT_PATH}"
