#!/usr/bin/env bash
# Run the native WP-like suite on both PHP 8.2 and 8.3, reporting each
# runtime's exact final test-count line.
set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

echo "=== PHP 8.2 ==="
OUT82="$("${SCRIPT_DIR}/test-82.sh" 2>&1)"
STATUS82=$?
echo "${OUT82}" | tail -3

echo "=== PHP 8.3 ==="
OUT83="$("${SCRIPT_DIR}/test-83.sh" 2>&1)"
STATUS83=$?
echo "${OUT83}" | tail -3

if [ "${STATUS82}" -ne 0 ] || [ "${STATUS83}" -ne 0 ]; then
	echo "test-all.sh: at least one runtime reported failures" >&2
	exit 1
fi

exit 0
