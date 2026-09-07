#!/usr/bin/env bash
# Standard work-package verification: PHP 8.2 full suite, PHP 8.3 full
# suite, acceptance suite, git diff --check, git status --short — in
# that fixed order. No arguments accepted.
set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"
FAILED=0

echo "=== 1/5 PHP 8.2 full suite ==="
"${SCRIPT_DIR}/test-82.sh" | tail -3
[ "${PIPESTATUS[0]:-0}" -eq 0 ] || FAILED=1

echo "=== 2/5 PHP 8.3 full suite ==="
"${SCRIPT_DIR}/test-83.sh" | tail -3
[ "${PIPESTATUS[0]:-0}" -eq 0 ] || FAILED=1

echo "=== 3/5 acceptance suite ==="
"${SCRIPT_DIR}/acceptance.sh" | tail -3
[ "${PIPESTATUS[0]:-0}" -eq 0 ] || FAILED=1

echo "=== 4/5 git diff --check ==="
cd "${ROOT_DIR}"
git diff --check || FAILED=1

echo "=== 5/5 git status --short ==="
git status --short

if [ "${FAILED}" -ne 0 ]; then
	echo "verify-all.sh: one or more checks failed" >&2
	exit 1
fi

echo "verify-all.sh: all checks passed"
exit 0
