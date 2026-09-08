# Sprint 0.3A Phase 2 — Final Exit-Gate Closure Report

**Date:** 2026-09-08
**Branch:** `sprint/0.3-security-ci`
**Starting commit (this session):** `e10df5a`
**Companion documents:** `docs/ARCHITECTURE.md` §13, `docs/audits/BUG-GAP-REGISTER.md`

This report records the closure pass performed on top of the already-committed Phase 2
mutation-engine hardening work (`e10df5a` and everything before it). It does not
re-litigate what that prior work already established — see `docs/ARCHITECTURE.md` §13
for the full pipeline narrative. It records only what changed in this session and the
resulting honest exit-gate classification.

## 1. Commits created this session

| Commit | Summary |
|---|---|
| `de7af99` | `fix(mutation): fault-inject AuditLogger's own persistence layer` — closes fault-injection matrix item L |
| `45b1399` | `test(multisite): BUG-006 shim correction + real site-separated write isolation coverage` |
| `2d15116` | `test(security): add symlink-escape regression coverage for PathGuard` |
| *(pending)* | `docs(phase2): final exit-gate truth report` — this document + doc updates |

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

| Suite | Before this session (baseline) | After this session |
|---|---|---|
| PHP 8.2 native suite | 442/442 | **452/452**, 0 failures |
| PHP 8.3 native suite | 442/442 | **452/452**, 0 failures |
| Acceptance suite | 13/13 | **13/13** |

Targeted re-runs (audit fault injection, repository fault injection, crash recovery,
journal multisite isolation, PathGuard) all green individually as well as inside the
full run. `git status --short` clean after every commit; no debug leftovers, no external
scratch files; `.tools/` absent from every commit's diff.

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
- **Medium:** 0 (the local PHP toolchain misconfiguration in §2 was an environment defect,
  not a plugin/security defect — it blocked local verification, it did not represent a
  shipped vulnerability)
- Real bugs found and fixed in *code* this session: 0 (BUG-006's fix was to test
  infrastructure, `tests/shim/wp-functions.php`, not to any `src/` production file;
  `AuditLogRepository`'s interface widening is a non-behavioral refactor)

## 17. External validation gates (unchanged, restated for completeness)

- Real WordPress/MySQL execution — CI-CONFIGURED-NOT-RUN.
- Static analysis (PHPStan + PHPCS) — CONFIGURED-NOT-RUN.
- PHP 8.4 — best-effort CI job exists, never executed (unrelated to this session).

## 18. Exit-gate decision

| Classification | Value |
|---|---|
| `PHASE_2_IMPLEMENTATION_STATUS` | PARTIAL (unchanged from `docs/ARCHITECTURE.md` §13's own status — real, tested pipeline + durable persistence + crash-recovery journal; no automatic mid-flight continuation; not AI-facing by design) |
| `PHASE_2_LOCAL_VALIDATION` | COMPLETE (452/452 on PHP 8.2 and 8.3, 13/13 acceptance, 0 failures; every locally-closable gap from this pass's mandate closed) |
| `REAL_DB_VALIDATION` | CI-CONFIGURED-NOT-RUN |
| `STATIC_ANALYSIS` | CONFIGURED-NOT-RUN |
| `MULTISITE_WRITE_VALIDATION` | SHIM-VALIDATED (upgraded from the prior pass's honest "shim cannot prove this" state; not REAL-DB-VALIDATED) |
| `PHASE_3_ENTRY_READINESS` | **NO** |

**Exact reason:** every locally closable Phase 2 security/mutation-safety gap this
session was scoped to close (audit fault injection, BUG-006 shim limitation, filesystem
symlink attack-matrix coverage) is now closed, and the full local suite is green on both
required PHP versions plus acceptance. The sole remaining blocker is external: real
WordPress/MySQL execution has never been observed to run, so `dbDelta` migration
behavior, real row-locking CAS semantics, and genuine multi-table MySQL isolation remain
unverified outside the shim. Per this program's own stated security policy (Section 20 of
the governing task), `PHASE_3_ENTRY_READINESS` stays `NO` — classified as
`EXTERNAL_VALIDATION_GATE_ONLY` — unless the project's security policy is explicitly
changed to permit Phase 3 internal-only development ahead of that validation. This
session did not relax that bar.

**If NO — exact minimal remaining blocker:** a single green run of
`.github/workflows/ci.yml`'s `test-real-wp-mysql` job (requires a `git push` to a branch
GitHub Actions runs on, which this session's constraints explicitly prohibit) — nothing
else is locally actionable. Static analysis (§11) is a second, independent
CI-CONFIGURED-NOT-RUN gate but is not itself blocking `PHASE_3_ENTRY_READINESS` under the
policy in §20 of the governing task, which names real DB validation as the specific bar.

**If YES:** N/A — not reached this pass.

## 19. Honest completion estimate

Phase 2 (mutation pipeline specifically): mechanically and locally ~90% complete — every
implemented piece is real and tested, not stubbed; the remaining ~10% is entirely the
external real-database validation gate plus the (out of this session's scope) automatic
crash-recovery continuation engine, which was always a deliberately deferred, separately
scoped follow-up, not a gap in what was promised for this pass.

This report intentionally does not restate a "TOTAL roadmap completion %" figure — that
requires weighing Phase 2 against the full `docs/roadmap/ENTERPRISE-ROADMAP.md` scope
(Phase 3+ items untouched by this pass, and a pre-existing tracker/roadmap staleness this
session did not attempt to reconcile — see the final report's closing note). Stating a
single number here would understate the amount of judgment that rolls into it and is
better made explicitly by the project owner with the roadmap open.
