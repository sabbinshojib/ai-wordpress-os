#!/usr/bin/env bash
# Read-only repository status. No mutation.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"

cd "${ROOT_DIR}"
echo "=== git status --short ==="
git status --short
echo "=== git log --oneline -8 ==="
git log --oneline -8
