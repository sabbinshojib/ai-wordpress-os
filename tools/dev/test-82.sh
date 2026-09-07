#!/usr/bin/env bash
# Run the native WP-like test suite on the project-local PHP 8.2 runtime.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"

PHP_BIN="${ROOT_DIR}/.tools/php/8.2/php.exe"
PHP_INI="${ROOT_DIR}/.tools/php/8.2/php-wp.ini"

if [ ! -x "${PHP_BIN}" ] && [ ! -f "${PHP_BIN}" ]; then
	echo "PHP 8.2 runtime not found at ${PHP_BIN}" >&2
	exit 1
fi

cd "${ROOT_DIR}"
"${PHP_BIN}" -c "${PHP_INI}" tests/run.php
