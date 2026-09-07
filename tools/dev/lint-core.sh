#!/usr/bin/env bash
# Lint an explicit, curated file list for the CURRENT coherent work
# package. This list is maintained by hand as work packages change —
# it deliberately does not accept an arbitrary path from a caller.
set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"
PHP_BIN="${ROOT_DIR}/.tools/php/8.2/php.exe"

# Sprint 0.3A Phase 2 foundation (mutation pipeline) files.
FILES=(
	"src/Mutation/ChangeOperationInterface.php"
	"src/Mutation/ChangeSet.php"
	"src/Mutation/MutationEngine.php"
	"src/Mutation/MutationException.php"
	"src/Mutation/MutationResult.php"
	"src/Mutation/RollbackRecord.php"
	"src/Mutation/Snapshot.php"
	"src/Mutation/VerificationResult.php"
	"src/Mutation/Operations/AbstractOperation.php"
	"src/Mutation/Operations/FileCreateOperation.php"
	"src/Mutation/Operations/FileDeleteOperation.php"
	"src/Mutation/Operations/FilePatchOperation.php"
	"src/Mutation/Operations/MetadataUpdateOperation.php"
	"src/Mutation/Operations/OptionUpdateOperation.php"
	"src/Mutation/Operations/PostContentUpdateOperation.php"
	"src/Security/PathGuard.php"
	"src/Core/CoreServiceProvider.php"
	"tests/shim/wp-functions.php"
	"tests/Unit/MutationDomainTest.php"
	"tests/Integration/MutationEngineTest.php"
)

FAILED=0
cd "${ROOT_DIR}"
for FILE in "${FILES[@]}"; do
	if [ ! -f "${FILE}" ]; then
		echo "skip (not present): ${FILE}"
		continue
	fi
	"${PHP_BIN}" -l "${FILE}" || FAILED=1
done

if [ "${FAILED}" -ne 0 ]; then
	echo "lint-core.sh: one or more files failed to lint" >&2
	exit 1
fi

echo "lint-core.sh: all listed files pass"
exit 0
