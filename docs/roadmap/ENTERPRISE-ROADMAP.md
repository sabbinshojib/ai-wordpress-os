# AI WordPress OS — Enterprise Roadmap

**Baseline commit:** `84d3c97bcd33d4d237a6e21593b6cecddbe36403`
**Source documents:** `docs/audits/ENTERPRISE-READINESS-AUDIT.md`, `docs/audits/BUG-GAP-REGISTER.md`
**Principle:** Every sprint below is a superset dependency of the next. No sprint after 0.1 should start until the sprint before it is green and merged. **Phase 2 (Sprint 1.x onward) does not begin until Sprint 0.x is fully complete and the test suite is green under CI.**

**2026-09-07 status:** Sprints 0.1 and 0.3 (renumbered in practice as "0.3A," see below) are complete on `sprint/0.3-security-ci`. Sprints 0.2 (multisite posture was actually decided/implemented early, in Sprint 0.1 — see T-009b), 0.4, 0.5, 0.7, and 0.8 remain open. **Phase 2 has a real, tested implementation of items 1–8 below (Transaction/ChangeSet/Snapshot/Diff/Approval/Guarded-mutation/Verification/Rollback) — `AIOS\Mutation\*` — now including durable, encrypted persistence, a DB-backed execution lease, durable replay protection, a typed Planner boundary, a per-operation crash-recovery journal with classification-only recovery (fails closed to `MANUAL_RECOVERY_REQUIRED` for anything ambiguous; no automatic mid-flight continuation attempted — see `docs/ARCHITECTURE.md` §13 for why), and repository-layer fault-injection coverage (which found and fixed three real gaps: a discarded INSERT failure that would have produced a TypeError instead of a controlled failure, a discarded journal-write failure that would have let a mutation proceed with no durable record, and a bare RuntimeException from Crypto that could escape the coordinator uncaught). Still missing: real WordPress/MySQL execution (CI job authored, never run — CI-CONFIGURED-NOT-RUN; the test shim itself was found this pass to be unable to genuinely prove per-site WRITE isolation for any table), automatic crash-recovery continuation, mid-transaction-failure and audit-write fault injection specifically, Jobs/background execution (item 9), and any AI-facing tool/REST/MCP exposure.** This is a deliberate deviation from the strict "Phase 2 begins only once Sprint 0.x is fully complete" reading of the principle above, done by explicit stakeholder direction; it does not change the principle for item 9 onward, or for exposing Phase 2 capability externally, which both still wait on Sprint 0.x completing. See `docs/ARCHITECTURE.md` §13 for the exact implemented-vs-not split and `docs/audits/SPRINT-0.3-SECURITY-CI-REPORT.md` for the Sprint 0.3A narrative.

---

## Sprint 0.1 — Existing-system stabilization (correctness)

**Goal:** Fix the confirmed, root-caused bugs that make current Phase-1 features non-functional. No new features. No architecture changes.

| Task | Addresses | Depends on |
|---|---|---|
| Fix `PathGuard::isInsideRoot()`/`toAbsolute()` Windows drive-letter self-rejection | BUG-001 | — |
| Fix `ToolsController`'s `/tools/execute` `sanitize_key` → dot-safe sanitizer | BUG-002 | — |
| Fix `tests/bootstrap.php` to require `TestCase.php`; add `AIOS\Tests\` to `autoload-dev` | BUG-003 | — |
| Add regression tests for the three fixes above | BUG-001/002/003 | The three fixes |

**Exit criteria:** `tests/run.php` reports 0 failures under a WordPress-realistic extension set on PHP 8.2 and 8.3. `phpunit.xml.dist` runs to completion (even if not yet wired into CI). `tests/acceptance.php` still 13/13.

## Sprint 0.2 — Environment compatibility and activation diagnostics

**Goal:** The plugin must fail loudly and cleanly at activation on any host it cannot support, instead of fataling mid-request later.

| Task | Addresses | Depends on |
|---|---|---|
| Extend `ai_wp_os_environment_failed()` (or a sibling check) to verify `mbstring` and (`openssl` OR `sodium`) at activation | BUG-004 | Sprint 0.1 |
| Add activation-time admin notice describing exactly which requirement failed | BUG-004 | Above |
| Document the full environment matrix (PHP versions, required/optional extensions, WP versions) in `docs/INSTALLATION.md` | ENV-001/ENV-002 | — |
| Decide and implement the multisite posture: either (a) genuine per-site provisioning in `Activator`/`uninstall.php`, or (b) explicit rejection of network-wide activation with a clear message, until (a) is scheduled | BUG-005 | Sprint 0.1 |
| If (b) chosen: add the rejection + message; track full multisite provisioning as its own later task once a multisite test shim exists | BUG-005 | — |

**Exit criteria:** Activating on a host missing a required extension produces a clear, actionable admin notice and does not proceed. Multisite network activation either works correctly on every site or is explicitly and safely refused.

## Sprint 0.3 (0.3A) — Security hardening — **COMPLETE** (2026-09-07, `sprint/0.3-security-ci`)

**Goal:** Close the Medium-severity findings from the audit before any compliance or enterprise-security claim is made.

| Task | Addresses | Depends on |
|---|---|---|
| Remove `HTTP_HOST`-based "local development" HTTPS exemption; replace with a non-client-controlled signal | SEC-M2 | — |
| Add `verifyNonce()` defense-in-depth check to `ToolsController::execute()` for parity with every other mutating endpoint | SEC-M3 | Sprint 0.1 (BUG-002 fix touches the same file) |
| Replace the rate limiter's non-atomic check-and-increment with an atomic primitive, or explicitly document it as abuse-deterrence-only (not a hard ceiling) in `docs/SECURITY.md` | SEC-M1 | — |
| Add tamper-evidence to the audit log (hash-chain each row to the previous, or equivalent) | SEC-M4 | — |
| Add key-rotation handling to `Crypto` (versioned key support, or a documented rotation runbook) before any Phase-2 feature persists encrypted data | SEC-M5 | Must land before any task in Sprint 1.x that calls `Crypto::encrypt()` for durable storage |
| Wire `ai_os_use`/`ai_os_approve` to real, grantable roles/capabilities (admin UI to assign them) instead of capability constants nothing ever grants | ARCH-005 (partial) | — |

**Exit criteria:** All Medium findings in `BUG-GAP-REGISTER.md` are either fixed or explicitly, permanently downgraded with a documented rationale in `docs/SECURITY.md`. — **Met.** SEC-M1..M5 and the ARCH-005 remainder are all `RESOLVED` (see `BUG-GAP-REGISTER.md`). T-026/T-027/T-028 (CI workflow, PHPCS, PHPStan config) were pulled forward from Sprint 0.6 into this sprint as well, since a from-scratch CI foundation was in scope for this pass.

## Sprint 0.4 — Database/migration hardening

**Goal:** The data layer must be trustworthy under real MySQL/MariaDB and under multisite before Phase 2 adds any mutation-heavy feature.

| Task | Addresses | Depends on |
|---|---|---|
| Add a real-MySQL/MariaDB integration test target (Docker-based or CI-service-based; out of scope for local no-install audit tooling) | TEST-003 | CI infrastructure (Sprint 0.6) may be needed first for this to run anywhere |
| Add a schema-upgrade test: apply migration N to a fixture already on N-1 with real data | TEST-004 | — |
| Add `uninstall.php` execution tests for both `remove_data_on_uninstall` states | TEST-005 | — |
| Full multisite data-layer provisioning (if Sprint 0.2 deferred it) | BUG-005 | Multisite test shim |
| Optimize `ApiKeyRepository::revoke()` to a direct `WHERE id = %d` lookup | SEC-L2 | — |
| Document the forward-only migration strategy and the explicit absence of `down()`/rollback in `docs/ARCHITECTURE.md`, with a plan for when rollback becomes necessary | ARCH-002 (documentation only at this stage) | — |

**Exit criteria:** Migration, upgrade, and uninstall behavior are covered by tests that do not depend on the hand-rolled shim alone.

## Sprint 0.5 — REST/MCP contract hardening

**Goal:** Make the two external-facing protocol surfaces (REST, MCP) verifiably correct against a real transport, not just the shim.

| Task | Addresses | Depends on |
|---|---|---|
| Build (or adopt) a real `WP_REST_Server`-based integration test harness | TEST-001 | — |
| Exercise every controller's declared `args`/`sanitize_callback`/`permission_callback` through that harness | TEST-010 | Above |
| Add MCP idempotency-key support for mutating `tools/call` requests (client-supplied key, server dedups within a TTL window) | ARCH-004 | — |
| Document the MCP batch-vs-rate-limit interaction (§10 of the audit) in `docs/MCP-SETUP.md` and decide whether the outer 'mcp' counter should also be applied per-sub-request | (audit §10 finding) | — |

**Exit criteria:** A REST integration suite exists and is green; the specific class of bug that produced BUG-002 has a permanent regression barrier.

## Sprint 0.6 — Coding standards / static analysis / CI

**Goal:** Nothing in Sprints 0.1–0.5 should be able to silently regress after this sprint.

| Task | Addresses | Depends on |
|---|---|---|
| Add `.github/workflows/ci.yml` (or equivalent) running: `composer install`, `php -l` lint, `tests/run.php`, `vendor/bin/phpunit` | REL-001 | **DONE, pulled forward into Sprint 0.3A** (`8efb2de`) — Sprint 0.1 (PHPUnit must actually run) |
| Add PHPCS + WordPress Coding Standards config, run in CI | REL-002 | **DONE, pulled forward into Sprint 0.3A** (`8efb2de`) |
| Add PHPStan config at a pragmatic starting level, run in CI (non-blocking initially if the baseline is noisy, then ratchet) | REL-002 | **DONE, pulled forward into Sprint 0.3A** (`8efb2de`) — level 5, no baseline-suppression file; first real CI run establishes the true starting point |
| Add `composer audit` to CI | REL-003 | Requires `composer.lock` to exist (i.e., `composer install` run at least once) |
| Add `npm audit` + a JS build-reproducibility check (rebuild `admin-dashboard.js`, diff against committed artifact) to CI | REL-003, REL-004, LIKELY-003 | Requires a JS lockfile |
| Commit `composer.lock` and a JS lockfile | ENV-003, ENV-004 | — |

**Exit criteria:** A pull request that reintroduces any Sprint 0.1–0.5 bug fails CI automatically.

## Sprint 0.7 — Real WordPress integration testing

**Goal:** Close the remaining test gaps that require a genuine WordPress+database runtime rather than the hand-rolled shim.

| Task | Addresses | Depends on |
|---|---|---|
| Stand up a real-WordPress test environment (wp-env, or an equivalent Docker-based harness) in CI | TEST-001..TEST-007 (enabler) | Sprint 0.6 CI infrastructure |
| Multisite test coverage (activation, uninstall, context caching, rate limiting per site) | TEST-002 | Multisite shim/harness |
| Concurrency tests: parallel approval claims, parallel rate-limiter hits, parallel migration runs | TEST-006 | Real DB harness |
| WP-CLI command execution tests (`status`, `migrate`, `tools`) | TEST-007 | Real WP-CLI environment |

**Exit criteria:** The "unverified functionality" list in the audit (§4) is empty or has been moved to "verified."

## Sprint 0.8 — Admin UX / accessibility / observability hardening

**Goal:** Bring the admin console and operational visibility up to an enterprise bar before Phase 2 adds more surface area to it.

| Task | Addresses | Depends on |
|---|---|---|
| Browser-based UI test coverage for the React console (at least smoke tests per screen) | TEST-008 | — |
| Accessibility pass (ARIA, keyboard navigation) on `app.jsx` | UI findings §14 | — |
| Add request/correlation IDs to audit log entries and structured errors | Observability gaps | — |
| Add a health-check endpoint distinct from `/status` (liveness vs. readiness semantics) | Observability gaps | — |
| Add metrics exportability (at minimum, a documented way to scrape `tool_executions`/`audit_logs` aggregates externally) | Observability gaps | — |

**Exit criteria:** Green baseline, CI-enforced, with real WordPress integration coverage and a documented, tested multisite posture. **This is the gate for Phase 2.**

---

## Phase 2+ (only after Sprint 0.x is fully green under CI)

Dependency-ordered, per the mandated sequencing:

> **2026-09-07:** items 1–8 below (Transaction Engine through Rollback system) have a real, tested implementation including durable encrypted persistence, an execution lease, durable replay protection, a typed Planner boundary, a per-operation crash-recovery journal (classification-only recovery — fails closed to manual review, no automatic continuation), and repository-layer fault-injection coverage — not exposed to any AI-facing surface, real WordPress/MySQL execution is CI-CONFIGURED-NOT-RUN (see `docs/ARCHITECTURE.md` §13 for the exact split, including a shim limitation found this pass that means per-site WRITE isolation for any table can only be proven by that real-DB job, never the shim). Item 9 (Jobs/background execution) and items 10–20 have not started. See the status note at the top of this document.

1. **Transaction Engine** — foundational; every item below depends on it.
2. **ChangeSet model** — depends on Transaction Engine.
3. **Snapshot Engine** — depends on ChangeSet model (a snapshot is a serialized ChangeSet boundary).
4. **Diff Engine** — depends on Snapshot Engine (diffs compare snapshots).
5. **Approval integration** (extending the existing approval queue to cover ChangeSets, not just single tool calls) — depends on 1–4.
6. **Guarded mutation system** — depends on 1–5; this is where Phase 1's single-tool-call model becomes a multi-step, reviewable mutation model.
7. **Verification engine** (post-mutation assertions) — depends on 6.
8. **Rollback system** — depends on 3 (Snapshot) + 6 (Guarded mutation).
9. **Jobs / background execution** — can be developed in parallel with 6–8 once 1 (Transaction Engine) exists; required before any long-running mutation (bulk content migration, staging sync) is safe to expose.
10. **WordPress developer capabilities** (code-level scaffolding, custom post types/blocks generation) — depends on 6–9.
11. **Gutenberg integration** — depends on 10.
12. **Elementor adapter** — depends on 6 (guarded mutation) + the integration-detection groundwork already in `PluginInspector::INTEGRATION_PROBES`.
13. **WooCommerce adapter** — same dependency shape as 12.
14. **ACF / MetaBox / Pods adapters** — same dependency shape as 12.
15. **Form integrations** (WPForms, Gravity Forms, CF7) — depends on 6.
16. **SEO integrations** (Yoast, Rank Math) — depends on 6.
17. **Staging / deployment** — depends on 1 (Transaction Engine) + 8 (Rollback), since deploying without a rollback path is unacceptable at enterprise scale.
18. **Visual / browser QA** — depends on 17 (most valuable once there is a staging target to check against).
19. **Multi-agent orchestration** — depends on 1, 6, 9 (needs transactions, guarded mutations, and background jobs to coordinate multiple concurrent agents safely).
20. **Enterprise team / RBAC functionality** — can start in parallel with 6 once Sprint 0.3's `ai_os_use`/`ai_os_approve` role-wiring lands, but full maturity depends on 1 (per-team transaction isolation) and 19 (per-team agent scoping).

Each Phase 2+ item must ship with its own test coverage before merge — the exact failure mode this audit found (a broken feature shipped without a test that would have caught it) is what Sprint 0.1–0.8 exists to prevent from recurring.
