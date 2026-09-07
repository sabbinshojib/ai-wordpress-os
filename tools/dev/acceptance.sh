#!/usr/bin/env bash
# Run the acceptance suite on the project-local PHP 8.2 (WP-like) runtime.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"

PHP_BIN="${ROOT_DIR}/.tools/php/8.2/php.exe"
PHP_INI="${ROOT_DIR}/.tools/php/8.2/php-wp.ini"

cd "${ROOT_DIR}"
"${PHP_BIN}" -c "${PHP_INI}" tests/acceptance.php
