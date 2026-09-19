# P3-B — Repository Intelligence: Specification

**Status:** SEEDED (spec only — no implementation yet)
**Date:** 2026-09-19
**Branch:** `sprint/0.3-security-ci`
**Baseline commit for this spec:** `6eda320` (P3-A: task planner, schemas, capability model)
**Companion documents:** `docs/roadmap/IMPLEMENTATION-TRACKER.md` (Phase 3+ tracker seed), `docs/roadmap/ENTERPRISE-ROADMAP.md` §Phase 2 closeout note, `docs/ARCHITECTURE.md` §12–§13, `src/Developer/Capability/DeveloperCapability.php`, `src/Developer/Plan/DeveloperTaskPlanner.php`

This document formally seeds P3-B. It does not implement it. It exists because
`InternalTaskPlanInput.php` (P3-A) explicitly defers to "P3-B repository
intelligence" in its own doc comment, and because `DeveloperTaskPlanner::plan()`
currently always returns an **empty operations array** for any external
`DeveloperTaskRequest` — the planner has no way today to look at the real
repository and turn a high-level objective/scope into anything concrete. That gap
is P3-B.

## 1. Objective

Give `AIOS\Developer\Plan\DeveloperTaskPlanner` a real, read-only way to inspect
the actual plugin repository (filesystem contents within `PathGuard`'s root, and
git metadata) so that a `DeveloperTaskRequest` can be resolved against real
repository state instead of always producing an empty-operation, unconditionally
approved plan. P3-B produces **information**, not mutations: no file is written,
no git ref is changed, no `OperationSpecification` is auto-applied.

## 2. Scope

P3-B implements exactly the four already-whitelisted, already-`LEVEL_READ`
developer capabilities that have no implementation yet:

- `DeveloperCapability::INSPECT_REPO` (`developer.repo.inspect`)
- `DeveloperCapability::INSPECT_GIT` (`developer.git.inspect`)
- `DeveloperCapability::DIAGNOSE_FAILURE` (`developer.failure.diagnose`)
- `DeveloperCapability::PROPOSE_REPAIR` (`developer.repair.propose`)

And wires their output into the existing `CREATE_PLAN` path
(`DeveloperTaskPlanner::plan()`), so an external `DeveloperTaskRequest` with a
real `scope` produces a plan whose data reflects the actual repository instead
of an always-empty stub.

In scope, concretely:

- Enumerating files/directories under a `DeveloperTaskRequest::scope()` path,
  bounded by `PathGuard`'s configured root.
- Reading file contents/metadata (size, mtime, PHP namespace/class name where
  parseable) for files inside that scope.
- Read-only git plumbing: current branch, `HEAD` SHA, working-tree status
  (clean/dirty, per-file state), and `log`/`diff` output for a bounded ref range
  — via a fixed, whitelisted set of read-only git subcommands, never arbitrary
  shell.
- Turning a raw diagnostic payload (e.g. PHPUnit/PHPStan/PHPCS output text
  **handed to the diagnoser by the caller**, not executed by P3-B itself) into a
  structured, typed diagnosis (failing test/file/line, category, likely cause).
- Producing a **read-only, non-binding repair proposal**: a structured
  suggestion (e.g. "file X likely needs a patch near line Y because Z") that is
  explicitly not an `OperationSpecification` and cannot be applied without a
  separate, later, human-authored/approved mutation task.
- Updating `DeveloperTaskPlanner::plan()` so that a request with a non-empty
  `scope()` returns a plan populated with real `INSPECT_REPO`/`INSPECT_GIT`
  findings in `metadata()`/derived fields, while `operations` remains empty
  (P3-B never emits operations — see Non-goals).

## 3. Non-goals

The following are explicitly **out of scope** for P3-B, even though they are
adjacent, because the repository evidence does not make them a P3-B dependency:

- **Code editing / mutation.** `PATCH_FILE`, `COMMIT_GIT`, `PUSH_GIT` are
  already-defined capabilities but at `LEVEL_SENSITIVE`/`LEVEL_DEPLOYMENT` —
  P3-B never constructs, applies, or auto-fills an `OperationSpecification`. A
  repair proposal is descriptive text/structured data, not an executable
  operation.
- **QA automation / test execution.** `RUN_TEST` already exists as a capability
  but is a distinct concern (invoking PHPUnit itself). `DIAGNOSE_FAILURE` in
  P3-B consumes diagnostic output the caller already produced; it does not run
  tests. Wiring an actual test-runner is a separate, later task.
- **Git release automation.** No branch creation, commit, merge, push, or tag
  operation. `INSPECT_GIT` is read-only.
- **MCP/REST exposure.** No new REST controller or MCP tool surface. P3-B is a
  `src/Developer/*` library layer only, consistent with §13 of
  `ARCHITECTURE.md` noting the mutation pipeline itself is "not exposed to any
  AI-facing tool, REST endpoint, or MCP surface" at this stage.
- **Multi-agent orchestration.** Not referenced by any P3-A/P3-B artifact;
  roadmap item 19 (Phase 2 closeout note) places it far later, gated on Jobs
  (item 9) which is also not started.
- **Any other Phase 3 roadmap item** (Gutenberg/Elementor/WooCommerce/ACF/SEO/
  forms adapters) — unrelated to repository intelligence.

## 4. Dependencies

- **P3-A** (`DONE`, commit `6eda320`): `DeveloperTaskRequest`, `DeveloperTaskPlan`,
  `DeveloperTaskPlanner`/`DeveloperTaskPlannerInterface`, `DeveloperCapability`,
  `DeveloperPolicy`, `JsonSafeValidator`, `DeveloperTestIdentifier`. P3-B extends
  these; it does not replace them.
- **`AIOS\Security\PathGuard`** (Phase 1/0.1, `DONE`): all filesystem enumeration
  and reads must be bounded by the existing root-resolution/traversal guard —
  P3-B must not introduce a second, parallel path-safety mechanism.
- **`AIOS\Security\PermissionEngine`** (existing): capability level checks for
  the four capabilities above are already mapped (`LEVEL_READ` for all four);
  P3-B implementation classes must be invoked only behind `DeveloperPolicy`
  checks, never bypass them.
- **`AIOS\Mutation\OperationSpecification`** (Phase 2, `DONE`): referenced only
  as the type a repair proposal explicitly is **not**, to keep the boundary
  unambiguous in code and tests.
- **`AIOS\Support\JsonSafeValidator`** (P3-A): any structured output (scan
  results, diagnosis, proposal) that crosses into `DeveloperTaskPlan::metadata()`
  must be JSON-safe per the existing convention.
- No dependency on Sprint 0.4–0.8 tracker items (T-016..T-040): none of those
  rows are prerequisites for read-only repository/git inspection.

## 5. Architecture boundaries

- New namespace: `AIOS\Developer\Repository` (mirrors the existing
  `AIOS\Developer\Plan`, `AIOS\Developer\Capability`, `AIOS\Developer\Support`
  layout under `src/Developer/`).
- P3-B classes are **read-only by construction**: no class in this phase may
  call any `AIOS\Mutation\*` apply path, any WordPress write API
  (`wp_insert_*`, `update_option`, filesystem write functions), or any git
  subcommand outside an explicit whitelist (`status`, `log`, `diff`,
  `rev-parse`, `branch --show-current` — no `commit`, `push`, `merge`, `reset`,
  `clean`, `checkout -- <path>`, or arbitrary refspec interpolation).
- Git plumbing must shell out (if at all) through a single, narrow adapter
  class with a fixed argv whitelist and no string-concatenated user input into
  the command line — consistent with `DeveloperCapability::isSupported()`
  already structurally rejecting `shell`/`eval`/`sql` substrings elsewhere in
  this module.
- All filesystem access goes through `PathGuard`; no direct `fopen`/`glob`/
  `RecursiveDirectoryIterator` outside a `PathGuard`-resolved root.
- `DeveloperTaskPlanner::plan()` remains the single integration point: P3-B
  supplies it data, it does not gain a second, competing entry path.
- Output types (`RepositoryScanResult`, `GitInspectionResult`,
  `FailureDiagnosis`, `RepairProposal` — exact class names decided at
  implementation time) are immutable value objects, matching the existing
  `DeveloperTaskPlan`/`DeveloperTaskRequest` style (private properties,
  constructor validation, explicit accessors, `toArray()`/`fromArray()`).

## 6. Exact tracked tasks

Seeded in `docs/roadmap/IMPLEMENTATION-TRACKER.md` under a new "Phase 3 — P3-B
Repository Intelligence" section:

| Task ID | Task |
|---|---|
| T-110 | `RepositoryScanner`: enumerate files/directories under a `PathGuard`-bounded scope path; return file metadata (path, size, mtime, PHP namespace/class where parseable) |
| T-111 | `GitInspector`: read-only git adapter (branch, `HEAD` SHA, working-tree status, bounded `log`/`diff`) via a fixed argv whitelist, no shell string interpolation |
| T-112 | `FailureDiagnoser`: parse a caller-supplied PHPUnit/PHPStan/PHPCS output payload into a structured `FailureDiagnosis` (file, line, category, message) |
| T-113 | `RepairProposer`: given a `FailureDiagnosis`, produce a structured, non-binding `RepairProposal` (explicitly not an `OperationSpecification`) |
| T-114 | Wire T-110/T-111 into `DeveloperTaskPlanner::plan()` so a request with non-empty `scope()` populates real scan/git findings in the returned `DeveloperTaskPlan` (operations remain empty) |

## 7. Acceptance criteria per task

- **T-110 (`RepositoryScanner`):** Given a scope path inside the configured
  root, returns every file/directory under it with correct metadata; given a
  scope path that resolves outside the root (via `..`, symlink, or absolute
  escape), throws/fails closed via `PathGuard` rather than silently
  clamping or partially listing. Empty scope returns an empty result, not an
  error. Output is JSON-safe.
- **T-111 (`GitInspector`):** Given a workable git repo, returns the exact
  current branch and `HEAD` SHA matching `git rev-parse --abbrev-ref HEAD` /
  `git rev-parse HEAD` run out-of-band; a dirty working tree is reported with
  the exact set of changed files git itself reports; any subcommand outside the
  whitelist is structurally unreachable (no code path constructs it); no user-
  supplied string is ever concatenated into the argv without validation.
- **T-112 (`FailureDiagnoser`):** Given a representative PHPUnit failure
  payload (from this repo's own historical CI output), extracts the correct
  failing test class/method, file, and line; given a malformed/unparseable
  payload, returns a diagnosis marked "unparseable" rather than throwing or
  guessing.
- **T-113 (`RepairProposer`):** Given a `FailureDiagnosis`, returns a
  `RepairProposal` whose type is provably distinct from
  `OperationSpecification` (a static-analysis/unit-test assertion that no
  `RepairProposal` method returns or wraps an `OperationSpecification`); given
  an "unparseable" diagnosis, returns a proposal that says so rather than
  fabricating a fix.
- **T-114 (planner wiring):** A `DeveloperTaskRequest` with a real, in-root
  `scope()` produces a `DeveloperTaskPlan` whose `metadata()` contains the
  `RepositoryScanner`/`GitInspector` findings; `operations()` is still `array()`
  for every such plan (P3-B never emits operations); existing P3-A tests for
  the empty-scope case continue to pass unmodified.

## 8. Required tests

- `tests/Unit/RepositoryScannerTest.php` — in-root enumeration, out-of-root
  rejection (reuses `PathGuardTest`-style fixtures), empty scope, JSON-safety
  of output.
- `tests/Unit/GitInspectorTest.php` — branch/SHA/status correctness against a
  disposable git fixture (or the real repo in CI, read-only); whitelist
  enforcement (assert unsupported subcommands are unreachable/rejected).
- `tests/Unit/FailureDiagnoserTest.php` — at least one real historical
  PHPUnit-failure fixture (captured from this project's own CI history) plus
  one malformed-payload case.
- `tests/Unit/RepairProposerTest.php` — proposal-vs-`OperationSpecification`
  type-boundary assertion; unparseable-diagnosis passthrough case.
- `tests/Unit/DeveloperTaskPlannerTest.php` — extended (not replaced) with
  cases covering non-empty `scope()` now returning populated metadata, while
  the existing empty-scope/empty-operations assertions remain green.
- Full existing suite (`tests/run.php`, `vendor/bin/phpunit -c phpunit.xml.dist`,
  `tests/acceptance.php`) must remain 100% green — P3-B adds tests, it must not
  break any Phase 1/2/P3-A test.
- PHPCS (`phpcs.xml.dist`, WordPress-Extra) and PHPStan (`phpstan.neon.dist`,
  level 5) must be clean on all new files, matching the standard already met by
  P3-A.

## 9. Security and safety requirements

- No new capability constants are introduced; P3-B implements only the four
  already-whitelisted `LEVEL_READ` capabilities in
  `DeveloperCapability::LEVEL_MAP`. If implementation reveals a need for a new
  capability, that is a spec change requiring explicit sign-off, not an
  in-flight addition.
- Every P3-B class that touches the filesystem must go through `PathGuard`;
  every P3-B class that touches git must go through a single whitelisted-argv
  adapter. Neither may be reachable via `DeveloperCapability::isSupported()`'s
  existing `shell`/`eval`/`sql` substring rejection being the *only* thing
  standing between input and a dangerous call — the whitelist itself must make
  the dangerous call structurally absent from the code, not just filtered at
  runtime.
- No secrets, credentials, or `.env`-shaped file contents may be included
  verbatim in scan/diagnosis output; reuse the existing
  `AIOS\Support\Sanitize::looksLikeSecret()` redaction convention already used
  by the Diff engine (§13 of `ARCHITECTURE.md`) for any file-content excerpt
  surfaced in a result.
- All P3-B entry points must be invoked only after a `DeveloperPolicy::
  canExecuteDeveloperAction()` (or `isCapable()`) check by the caller — P3-B
  classes themselves are not the enforcement point and must not assume they are
  safe to call unguarded from any future REST/MCP wiring.
- No P3-B class may write to the WordPress database, the filesystem, or issue
  a git write command, full stop. This is enforced both by code review and by
  a dedicated test (T-113's type-boundary test, plus an added assertion in
  T-110/T-111 tests that no write-capable method exists on the public API of
  either class).

## 10. Project/client isolation requirements

- All scan/inspection results are scoped to the single repository root
  `PathGuard` is configured against; P3-B has no concept of, and must not
  accept, a path outside that root.
- Results are computed per-`DeveloperTaskRequest` call, not cached or stored
  in any shared/global state — two concurrent requests (potentially for
  different `siteId`/`principalUserId`) must not leak scan/diagnosis data
  between each other. No P3-B class may hold static/shared mutable state
  across requests.
- `DeveloperTaskRequest::siteId()` and `principalUserId()` continue to flow
  through unchanged into the resulting `DeveloperTaskPlan`, preserving the
  existing multisite/actor attribution already implemented in P3-A — P3-B does
  not add, remove, or bypass that attribution.

## 11. Expected deliverables

- `src/Developer/Repository/RepositoryScanner.php`
- `src/Developer/Repository/GitInspector.php`
- `src/Developer/Repository/FailureDiagnoser.php`
- `src/Developer/Repository/RepairProposer.php`
- Supporting immutable value-object classes for each result type (exact names
  decided during implementation, following the `DeveloperTaskPlan`/
  `DeveloperTaskRequest` constructor-validation + `toArray()`/`fromArray()`
  style).
- Updated `src/Developer/Plan/DeveloperTaskPlanner.php` (`plan()` method only —
  `planFromInternal()`'s P3-A behavior is unchanged).
- The five test files listed in §8.
- Updated `docs/roadmap/IMPLEMENTATION-TRACKER.md` (new task rows, this file's
  seeding note) and `docs/ARCHITECTURE.md` (a new subsection once implemented,
  documenting the read-only repository-intelligence layer next to §13's
  mutation-pipeline documentation).

## 12. Verification procedure

1. `composer install` (or confirm `vendor/` already matches `composer.lock`).
2. `vendor/bin/phpcs` — zero errors/warnings on all new/changed files.
3. `vendor/bin/phpstan analyse` — zero errors at the configured level.
4. `vendor/bin/phpunit -c phpunit.xml.dist` — 100% green, including all five
   new P3-B test files.
5. `php tests/run.php` and `php tests/acceptance.php` — both green, matching
   the standard already met at P3-A closeout.
6. Manual/CI grep sweep (mirroring the §13 ARCHITECTURE.md precedent) for any
   `RepositoryScanner`/`GitInspector`/`FailureDiagnoser`/`RepairProposer`
   reference under `src/Rest`, `src/Mcp`, `src/Tools` — must return no matches,
   confirming P3-B stayed a library layer with no AI-facing exposure, per §3
   Non-goals.
7. `git status`/`git diff` review confirming only the declared deliverable
   files changed.

## 13. Definition of Done

P3-B is **DONE** when:

- All five tasks (T-110..T-114) are implemented, tested, and marked `DONE` in
  `docs/roadmap/IMPLEMENTATION-TRACKER.md` with real commit hashes.
- Every acceptance criterion in §7 is met and verified per the §12 procedure.
- `docs/ARCHITECTURE.md` has a new subsection documenting the repository-
  intelligence layer's real, implemented behavior (matching the "implemented
  and tested" evidentiary style already used in §13 for the mutation engine,
  not aspirational language).
- No capability, path-safety, or git-safety boundary from §9 was loosened to
  make a test pass.
- `DeveloperTaskPlanner::plan()`'s existing P3-A behavior for empty-scope
  requests is unchanged and still covered by its original tests.
- No file outside `src/Developer/Repository/*`,
  `src/Developer/Plan/DeveloperTaskPlanner.php`, the five new test files, and
  the two roadmap/architecture docs was touched to close P3-B.

## Known gap (not invented, not resolved here)

The repository evidence does not specify **where** `FailureDiagnoser`'s input
payload is expected to come from at runtime (i.e., what future component
actually runs PHPUnit/PHPStan and hands it the output string). T-112/T-113 as
specified take that payload as a plain caller-supplied argument, which keeps
P3-B decoupled from test execution — but the component that will call
`FailureDiagnoser` in practice (a QA-automation phase, per the Non-goals
section) is not yet scoped anywhere in the tracker or roadmap. This is a
genuine open dependency for whichever future phase wires diagnosis into an
actual test run, not a P3-B blocker.
