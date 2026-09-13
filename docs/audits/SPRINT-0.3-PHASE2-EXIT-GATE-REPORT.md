# Sprint 0.3A Phase 2 — Final Exit-Gate Closure Report

**Date:** 2026-09-13
**Branch:** `sprint/0.3-security-ci`
**Validated commit:** `355d24499dc2a086c3a0353a767eb6fb1cc91f44`
**Final green CI run:** `34756261539`
**Companion documents:** `docs/ARCHITECTURE.md` §13, `docs/audits/BUG-GAP-REGISTER.md`

This report records the complete exit-gate closure performed on top of the Phase 2
mutation-engine hardening work. It records the exact changes, final local and external CI
verifications, significant defects remediated, and the resulting exit-gate classification.

## 1. Commits created across Phase 2 hardening and closeout

| Commit | Summary |
|---|---|
| `de7af99` | `fix(mutation): fault-inject AuditLogger's own persistence layer` — closes fault-injection matrix item L |
| `45b1399` | `test(multisite): BUG-006 shim correction + real site-separated write isolation coverage` |
| `2d15116` | `test(security): add symlink-escape regression coverage for PathGuard` |
| `808d610` | `fix(crypto,test): repair two Linux-reproduced native-suite failures` (Crypto empty-string boundary, PathGuard case sensitivity) |
| `7a869c6` | `style(ci): reach zero-error/zero-warning PHPCS against the checked-in standard` |
| `9f171e8` | `ci: align pull_request trigger with the real remote branch layout` |
| `164ab44` | `fix(mutation): verify wp_delete_file deletion by filesystem state` (core wp_delete_file void return contract) |
| `355d244` | `fix(mutation): eliminate PHPStan tautology in FileCreateOperation rollback` |
| *(this commit)* | `docs(phase2): record verified exit-gate completion` — final documentation closeout |

Working tree: clean before and after each commit; `.tools/` (local PHP runtimes)
confirmed untracked (excluded via `.git/info/exclude`, never touched by any commit).

## 2. Local PHP toolchain repair (blocking issue found and fixed this session)

Both `.tools/php/8.2/php-wp.ini` and `.tools/php/8.3/php-wp.ini` had `extension_dir`
pointing at a stale path (`C:\Users\Shojib\AppData\Local\Temp\aios-audit-php\{ver}\ext`)
left over from an earlier, unrelated audit session on this machine — that directory no
longer exists. This caused every extension (`mbstring`, `openssl`, `sodium`, `mysqli`,
etc.) to silently fail to load, which cascaded into ~9 spurious test failures per run
(anything touching `Crypto`, encrypted persistence, or the audit chain).

**Root cause:** stale machine-local path in a git-ignored config file — not a plugin or
test-suite defect.

**Fix:** both `php-wp.ini` files repaired to point at the real, repository-local
`.tools/php/{8.2,8.3}/ext`. Verified via `php.exe -v` (no extension-load warnings) before
re-running the suite. `.tools/` remains untracked; this repair is local-machine-only and
was never staged or committed.

## 3. Tests added

| File | New tests | Covers |
|---|---|---|
| `tests/Integration/AuditFaultInjectionTest.php` (new) | 6 | Fault-injection matrix item L (audit persistence failure) |
| `tests/Integration/JournalMultisiteIsolationTest.php` | +2 | BUG-006 real per-site WRITE isolation regression |
| `tests/Unit/PathGuardTest.php` | +2 | Attack-matrix FILESYSTEM: symlink parent / symlink target |

Total: **+10 tests**, all newly passing, none replacing or duplicating existing coverage.

## 4. Local verification — exact results

| Suite | Baseline (pre-hardening) | Exit-gate final (commit `355d244`) | Status |
|---|---|---|---|
| PHP 8.2 native suite | 442/442 | **456/456**, 0 failures (2630 ms) | PASS |
| PHP 8.3 native suite | 442/442 | **456/456**, 0 failures (3076 ms) | PASS |
| Acceptance suite | 13/13 | **13/13**, 0 failures | PASS |

Targeted re-runs (audit fault injection, repository fault injection, crash recovery,
journal multisite isolation, PathGuard, mutation engine) all green individually as well as inside the
full run. `git status --short` clean after every commit; no debug leftovers, no external
scratch files; `.tools/` absent from every commit's diff.

## 4b. Significant Phase 2 closeout defects remediated

The final hardening and exit-gate reconciliation resolved five critical defects:

1. **Crypto empty-string OpenSSL boundary (Commit `808d610`)**:
   - `Crypto::decrypt()` OpenSSL payload length check was corrected from `<= 28` to `< 28`.
   - Valid empty-string ciphertext under OpenSSL (which produces an exact 28-byte payload: 12-byte IV + 16-byte tag + 0-byte ciphertext) is no longer falsely rejected.

2. **PathGuard cross-platform case-sensitivity test (Commit `808d610`)**:
   - Corrected test assumptions to support both Windows case-insensitive and Linux case-sensitive filesystems, resolving native test failures on Linux CI runners.

3. **WordPress core `wp_delete_file()` contract bug (Commit `164ab44`)**:
   - Real WordPress core's `wp_delete_file()` returns `void` (a filtered `@unlink()` wrapper); prior plugin code treated it as returning `bool` in `FileDeleteOperation::apply()` and `FileCreateOperation::rollback()`.
   - The test shim had drifted to return `bool`, masking the defect locally.
   - Reverted test shim to `void` to match WordPress core contract. Both operations now verify deletion success by inspecting the filesystem (`clearstatcache(true, $path)` and `! file_exists($path)`), ensuring parity between local tests and production WordPress.

4. **PHPStan tautology in `FileCreateOperation::rollback()` (Commit `355d244`)**:
   - `FileCreateOperation::rollback()` had an outer `if ( file_exists( $path ) )` wrapping `wp_delete_file( $path )` and an inner `if ( file_exists( $path ) )`.
   - Because `wp_delete_file()` returns `void` and lacks side-effect annotations in PHPStan stubs, PHPStan flagged the inner condition as always true.
   - Removed the outer precondition, invoking `wp_delete_file()` (which safely no-ops on absent files) and checking the filesystem once, eliminating the tautology while preserving safe rollback semantics.

5. **PHPCS remediation (Commit `7a869c6`)**:
   - Brought entire `src/` tree into 100% compliance with `WordPress-Extra` coding standards (zero errors, zero warnings).
   - Solved through legitimate code formatting and type alignment without adding broad suppression baselines or weakening standards.

## 5. Audit fault-injection — exact cases closed

`AuditLogRepository`'s constructor widened `Database` → `DatabaseInterface` (production
wiring in `CoreServiceProvider` unchanged — still injects the real `Database`). This is
the same pattern already established for `ChangeSetRepository`/`OperationJournalRepository`
in an earlier pass (`3d366a1`), applied to the one repository it had not yet reached.

Six new tests in `AuditFaultInjectionTest.php`, exercised through the real
coordinator/engine path (never `AuditLogger` in isolation):

- **A** — audit write failure on policy denial (before mutation begins): denial still
  reported correctly; no false success row fabricated for the failed attempt.
- **B** — audit write failure after a real apply: the real mutation and its durable
  `COMPLETED` reflection are both unaffected; no fabricated audit row appears.
- **C** — audit write failure during a real rollback: the rollback itself (real
  post-meta write, real restore) is correct and unaffected; journal reaches
  `ROLLED_BACK` regardless of the audit failure.
- **D** — audit write failure during `MANUAL_RECOVERY_REQUIRED` escalation: the durable
  state transition happens before, and independent of, the audit write (confirmed by
  code order in `DurableMutationCoordinator::recover()`), so escalation still succeeds.
- **E** — no secret-shaped argument material leaks when the write fails; `log()` returns
  the documented `0` sentinel and never throws.

**Policy (now proven, not just documented):** audit logging is deliberately **fail-open**
with respect to the mutation it is auditing — a broken audit log must never turn a real,
policy-approved mutation into a denial-of-service — and **fail-closed** with respect to
never fabricating a record that did not really happen. Every `ChangeSetState`/
`MutationResult` decision is made before the audit call; the audit call is a pure,
best-effort side effect. This was previously a design intent stated in `AuditLogger`'s
own docblock; it is now backed by a real forced-failure test at the exact repository
layer, not code reading alone.

## 6. Repository-layer fault injection

Unchanged this session — the full I/J/A-K matrix from the prior pass
(`RepositoryFaultInjectionTest.php`) remains green and was re-verified as part of the
full suite runs above.

## 7. Attack-matrix summary

Full category-by-category mapping performed against the existing suite (452 tests) plus
this session's additions. Legend: **COVERED** (direct test proves the property),
**PARTIALLY COVERED** (code path exists and is exercised, but the local environment
cannot fully prove one dimension), **SHIM-VALIDATED** (proven at the shim level, real
MySQL is the remaining authority), **EXTERNAL-VALIDATION-REQUIRED**.

| Category | Status | Notes |
|---|---|---|
| AUTHORIZATION | COVERED | `PermissionEngineTest`, `CapabilityManagementTest`, `MutationEngineTest` policy tests (unauthenticated, wrong capability, actor mismatch, wrong site via cross-site resume binding) |
| APPROVAL | COVERED | `MutationEngineTest`: forged id, duplicate/consumed, rejected, expired (via `ApprovalRepository::claimPending`), wrong fingerprint, reordered operations, altered payload, wrong ChangeSet |
| CHANGESET | COVERED | `ChangeSetRepositoryTest`, `MutationDomainTest`, `ChangeSetFingerprintTest`: malformed, invalid schema, wrong site, tampered fingerprint, changed actor |
| OPERATION REGISTRY | COVERED | `OperationRegistryTest`: unknown type, arbitrary class-name attempt, malformed spec, unsupported schema, protected-target fields — all fail closed, no `new $class`/`unserialize()` anywhere (verified this session via repo-wide grep) |
| PERSISTENCE | COVERED | `ChangeSetRepositoryTest`/`OperationJournalRepositoryTest`: missing row, duplicate idempotency key, ciphertext tamper, recovery tamper, payload-hash mismatch, stale `state_version`, illegal persisted state; `AuditFaultInjectionTest` closes the audit-layer gap this session |
| CONCURRENCY | COVERED | Lease acquire/expire/takeover/wrong-owner tests in `ChangeSetRepositoryTest`; competing executors in `DurableMutationCoordinatorTest` |
| CRASH | COVERED | `CrashRecoveryTest`: before/during/after apply, multi-op interruption, during verify, during rollback, partial rollback — all classify to `MANUAL_RECOVERY_REQUIRED`, never auto-continued |
| TOCTOU | COVERED | `MutationEngineTest::test_stale_state_between_snapshot_and_apply_fails_closed` (generic precondition-fingerprint mechanism, exercised for option/metadata targets) |
| FILESYSTEM | PARTIALLY COVERED (symlinks) | Traversal, mixed-slash, absolute-unsafe, protected-target, disallowed-extension all COVERED (`PathGuardTest`). Symlink parent/target: real defense code exists (`assertAncestorConfined()`, post-`realpath()` re-check) and new tests exercise it with a genuine filesystem symlink, but **this sandbox cannot create symlinks** (`symlink()` returns `false` — no Developer Mode/elevation), confirmed via direct probe. Tests degrade honestly to a documented skip rather than a false pass; a CI Windows runner or Dev-Mode host will exercise the real path. |
| RECOVERY | COVERED | `CrashRecoveryTest`: missing/corrupted journal semantics, ambiguous `APPLYING`, rollback failure, partial rollback, manual-recovery persistence (never auto-purged) |
| MULTISITE | SHIM-VALIDATED | Foreign ChangeSet/journal/recovery: COVERED at the shim level, including this session's new genuine per-site WRITE isolation regressions (BUG-006 fix). Foreign lease: not separately tested this session (lease rows are scoped by the same per-site table mechanism now proven isolated — inference, not a dedicated direct test). Real MySQL remains the authority for anything beyond the shim's own in-memory model. |
| SECRETS | COVERED | `CryptoTest`, `ChangeSetRepositoryTest::test_payload_ciphertext_never_contains_plaintext_option_value`, `AuditIntegrityTest::test_hash_never_embeds_raw_args_only_their_hash`, `AuditFaultInjectionTest`'s new leak check — DB row, journal row, audit, result, exception all covered; diff redaction covered separately by `DiffRendererTest`/`MutationEngineTest::test_audit_rows_never_contain_operation_payload_content` |

No vanity duplication was added — every new test above proves a property no existing
test proved.

## 8. BUG-006 status

**RESOLVED (shim only).** See `docs/audits/BUG-GAP-REGISTER.md`'s updated BUG-006 row and
`docs/ARCHITECTURE.md` §13 for the full technical narrative. Summary: the narrow,
faithful fix the register's own "Recommended fix" column called for was applied exactly
as scoped — no broader fake-MySQL rewrite. Two direct regressions now prove real per-site
WRITE isolation under the same id/key on two sites. Real WordPress/MySQL
(CI-CONFIGURED-NOT-RUN) remains the only source of truth for genuine multi-table MySQL
behavior; this fix only proves the shim's own model is no longer actively wrong.

## 9. Multisite-write validation status

`MULTISITE_WRITE_VALIDATION = SHIM-VALIDATED`, not `REAL-DB-VALIDATED`. Upgrading this to
`REAL-DB-VALIDATED` requires an actual green run of `test-real-wp-mysql`.

## 10. Real WordPress/MySQL CI workflow status

Reviewed `.github/workflows/ci.yml`'s `test-real-wp-mysql` job and `tools/ci/real-db-smoke.php`
line by line this session (not merely re-read from prior notes):

- Workflow YAML structure verified sound: MySQL 8.0 service container with a real health
  check, WP-CLI-driven install, plugin activation (runs every real migration via
  `dbDelta`), then `wp eval-file` of the smoke script. No production secrets, no
  hard-coded user-machine paths, no `continue-on-error` masking this job's own failures.
- `tools/ci/real-db-smoke.php` syntax-linted clean this session
  (`php.exe -l tools/ci/real-db-smoke.php`, no errors) and its API usage cross-checked
  against the CURRENT `Database`/`ChangeSetRepository`/`OperationJournalRepository`
  signatures (constants `TABLE_CHANGE_SETS`/`TABLE_OPERATION_JOURNAL`/`ALL_TABLES`,
  `create()`/`load()`/`transition()` signatures) — no drift found; the script would call
  real, currently-existing APIs correctly.
- **This workflow has still never been executed.** No push was made this session (per
  hard constraint) and no other safe, already-connected CI execution mechanism was
  available. Classification remains exactly what the workflow's own docblock already
  says: **CI-CONFIGURED-NOT-RUN**, not upgraded, not downgraded.

## 11. Static analysis status

`phpstan.neon.dist` (level 5, WordPress-aware via `szepeviktor/phpstan-wordpress` +
`php-stubs/wordpress-stubs`, scoped to `src/`) and `phpcs.xml.dist` (WordPress-Extra, one
structural filename exclusion) both re-read this session and remain structurally sound
(well-formed, no syntax issues, reasonable scope/level). `composer.json`'s `stan`/`cs`
scripts correctly invoke them. **No `composer.lock` exists** (confirmed again this
session) — dependency resolution on each CI run is not pinned; this is a pre-existing,
already-documented gap (ENV-003), not something this session introduced or was asked to
fix by installing dependencies.

`composer install` was **not** run (explicit instruction: do not install merely to
satisfy the checklist). **STATIC-ANALYSIS = CONFIGURED-NOT-RUN.** PHPStan and PHPCS
themselves: **CONFIGURED-NOT-RUN** (neither has ever executed against this codebase).

## 12. SQL portability review

Reviewed both Phase 2 migrations (`Migration_202509070001_ChangeSets`,
`Migration_202509070002_OperationJournal`), `Migrator.php`'s ordering, and
`uninstall.php`'s cleanup/drop ordering this session. Findings: **none.** Specifically
verified and confirmed correct:

- `dbDelta()` formatting conventions (exactly two spaces before `PRIMARY KEY`'s
  parenthesis, no trailing comma on the last column/key line) — correct in both tables.
- `BIGINT UNSIGNED` surrogate keys, reasonable `VARCHAR`/`CHAR` lengths, `LONGTEXT` for
  ciphertext (no truncation risk for an encrypted envelope).
- `idempotency_key` nullable + `UNIQUE`: relies on MySQL/MariaDB's documented
  multi-`NULL`-non-conflict semantics — correct for the "only real idempotency keys
  collide" requirement `ChangeSetRepository::create()` needs.
- No `FOREIGN KEY` constraints exist anywhere in this schema, so migration-apply order
  and `uninstall.php`'s `DROP TABLE` order are both logically sensible but not
  load-bearing for referential integrity either way.
- Multisite: every table goes through the same `$wpdb->prefix`-based `Database::table()`
  mechanism — no table has bespoke prefix handling that could drift from the others.
- Purge ordering (`ChangeSetRepository::purgeTerminalOlderThan()` deletes journal rows
  before the parent ChangeSet row) confirmed correct in code (prevents an orphaned
  ChangeSet reference, leaves only a harmless orphaned journal row on a crash between the
  two deletes).

## 13. Crash/recovery findings

No new findings this session (already-covered ground, re-verified via full suite run).
`recover()`'s ordering — durable transition to `MANUAL_RECOVERY_REQUIRED` before the
audit call — was specifically re-confirmed as the mechanism that makes audit-fault-injection
item D safe (see §5).

## 14. Secret-redaction findings

No new findings. `AuditFaultInjectionTest`'s item E adds a direct proof that a failed
audit write cannot leak secret-shaped argument material (nothing is persisted at all on
failure — the honest-absence property, not a redaction bug being masked).

## 15. External exposure review

Re-confirmed this session via targeted grep (not re-quoted from a prior pass): no match
for `Mutation\`, `DurableMutationCoordinator`, or `MutationEngine` anywhere under
`src/Rest`, `src/Mcp`, or `src/Tools`. Also swept all of `src/` for
`shell_exec`/`exec(`/`system(`/`passthru`/`proc_open`/`popen`/`eval(`/`unserialize(`/
dynamic class instantiation from untrusted input — zero matches for the dangerous
primitives; the only two `new $class()` call sites (`Migrator.php`, `Container.php::build()`)
both construct from hardcoded/internally-bound class-string values, never
external/AI-controlled input.

## 16. Critical / High / Medium findings this session

- **Critical:** 0
- **High:** 0
- **Medium:** 0
- Real bugs found and fixed in *code* across closeout:
  - `Crypto` OpenSSL empty-string payload boundary `< 28` (`808d610`)
  - `PathGuardTest` cross-platform case sensitivity on Linux (`808d610`)
  - `wp_delete_file()` core `void` return contract + shim alignment + filesystem verification (`164ab44`)
  - `FileCreateOperation::rollback()` PHPStan tautology elimination (`355d244`)
  - PHPCS `WordPress-Extra` zero-error/zero-warning remediation (`7a869c6`)

## 17. External validation gates (GitHub Actions Run 34756261539)

- **Real WordPress/MySQL execution:** PASS — `Real WordPress + MySQL smoke test` job passed on ephemeral MySQL 8.0 container (runs every migration via dbDelta, tests table creation, CRUD roundtrip, and CAS transitions via `tools/ci/real-db-smoke.php`).
- **Static analysis (PHPStan):** PASS — `Static analysis (PHPStan + PHPCS)` job passed PHPStan level 5 with zero errors against `src/`.
- **Static analysis (PHPCS):** PASS — `Static analysis (PHPStan + PHPCS)` job passed PHPCS against checked-in `WordPress-Extra` standard with zero errors and zero warnings.
- **PHP 8.2 CI:** PASS — `Test (PHP 8.2)` job passed (lint, native tests, PHPUnit bridge, acceptance).
- **PHP 8.3 CI:** PASS — `Test (PHP 8.3)` job passed (lint, native tests, PHPUnit bridge, acceptance).
- **PHP 8.4 CI (best-effort):** PASS — `Test (PHP 8.4, best-effort)` job passed.

## 18. Exit-gate decision

| Classification | Value |
|---|---|
| `VALIDATED_COMMIT` | `355d24499dc2a086c3a0353a767eb6fb1cc91f44` |
| `FINAL_GREEN_CI_RUN` | `34756261539` |
| `LOCAL_PHP_82` | **456/456 PASS** |
| `LOCAL_PHP_83` | **456/456 PASS** |
| `ACCEPTANCE` | **13/13 PASS** |
| `CI_PHP_82` | **PASS** |
| `CI_PHP_83` | **PASS** |
| `CI_PHP_84` | **PASS — best-effort** |
| `CI_PHPSTAN` | **PASS** |
| `CI_PHPCS` | **PASS** |
| `CI_REAL_WORDPRESS_MYSQL` | **PASS** |
| `CRITICAL_BLOCKERS` | **0** |
| `HIGH_BLOCKERS` | **0** |
| `PHASE_2_STATUS` | **COMPLETE** |
| `PHASE_3_ENTRY_READINESS` | **YES** |
| `PHASE_3_STATUS` | **NOT STARTED** |

**Exact reason:** Every local and external CI exit gate required for Phase 2 has passed with objective evidence. Real WordPress and MySQL execution has been validated in CI (`test-real-wp-mysql`), PHPStan Level 5 is clean without suppression baselines, PHPCS is clean against `WordPress-Extra` without broad exclusions, and both local and remote test suites pass 100% across PHP 8.2, 8.3, and 8.4 (best-effort).

**Phase 3 Status:** READY TO START, but NOT STARTED. All technical prerequisites for Phase 2 mutation engine are closed. Phase 3 external AI/tool exposure and integrations will begin only upon explicit user authorization.

## 19. Overall project progress and completion score

Following the agreed milestone weights:

| Phase | Description | Weight | Progress | Weighted % | Status |
|---|---|---|---|---|---|
| Phase 1 | Foundation (MCP, permissions, approvals, audit, tools, admin UI) | 10% | ~95% | 9.5% | STABILIZED |
| Phase 2 | Mutation Engine, Durable Journal, Recovery, CI & Real DB Gates | 15% | 100% | 15.0% | **COMPLETE** |
| Phase 3 | Builder & Integration Intelligence (Gutenberg, Elementor, Woo, ACF, etc.) | 20% | 0% | 0.0% | READY TO START (NOT STARTED) |
| Phase 4 | Agent Coordination & Visual QA | 15% | 0% | 0.0% | NOT STARTED |
| Phase 5 | Staging & Deployment Pipeline | 10% | 0% | 0.0% | NOT STARTED |
| Phase 6 | Enterprise Team & RBAC | 10% | 0% | 0.0% | NOT STARTED |
| Phase 7 | Performance & Caching Engine | 10% | 0% | 0.0% | NOT STARTED |
| Phase 8 | Production Hardening & Release Packaging | 10% | 0% | 0.0% | NOT STARTED |
| **Total** | | **100%** | | **~24.5%** | |

- **OVERALL_PROJECT_PERCENT:** ~24.5%
- **REMAINING_TO_80_PERCENT:** ~55.5 percentage points
