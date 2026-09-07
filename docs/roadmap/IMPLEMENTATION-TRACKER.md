# AI WordPress OS — Implementation Tracker

**Baseline commit:** `84d3c97bcd33d4d237a6e21593b6cecddbe36403`
**Status legend:** `TODO` (not started), `IN_PROGRESS`, `BLOCKED`, `DONE`.
**Evidence column:** points back to the audit/register finding ID that justifies the task.
**Commit column:** left blank until a fix lands; fill with the commit hash that closes the task.

**2026-09-06 update:** Sprint 0.1 is complete on branch `sprint/0.1-stabilization` (based on baseline `84d3c97`; not yet merged to `main`). All four Sprint-0.1 rows below are `DONE`, plus one item pulled forward from Sprint 0.3 (T-015, capability usability — see the Sprint 0.1 row added below the original four). Full narrative: `docs/audits/SPRINT-0.1-STABILIZATION-REPORT.md`.

**2026-09-07 update:** Sprint 0.3A is complete on branch `sprint/0.3-security-ci` (not yet merged to `main`). All of Sprint 0.3 (T-010..T-015) is `DONE`, plus T-026/T-027/T-028 pulled forward from Sprint 0.6 (CI workflow, PHPCS, PHPStan configs). Full narrative: `docs/audits/SPRINT-0.3-SECURITY-CI-REPORT.md`.

## Sprint 0.1 — Existing-system stabilization

| Sprint | Task ID | Task | Priority | Dependency | Security impact | Tests required | Status | Evidence | Commit |
|---|---|---|---|---|---|---|---|---|---|
| 0.1 | T-001 | Fix `PathGuard::isInsideRoot()`/`toAbsolute()` so it does not reject its own internally-resolved absolute paths on Windows | Critical | — | Fixes an availability defect (fail-closed on all file reads); no escalation risk from the fix itself | `PathGuardTest` full suite green; new explicit Windows-drive-letter regression test | **DONE** | BUG-001 | `a16f359` |
| 0.1 | T-002 | Fix `ToolsController` `/tools/execute` `tool` argument sanitize callback (replace `sanitize_key`) | Critical | — | Restores a primary integration surface; must not reintroduce unsanitized input to `ToolRegistry::get()` | New REST-layer test asserting dot-namespaced tool names survive registration/sanitization | **DONE** | BUG-002 | `9bd693a` |
| 0.1 | T-003 | Fix `tests/bootstrap.php` to `require_once TestCase.php`; add `AIOS\Tests\` to `composer.json` `autoload-dev` | High | — | None (test infra only) | `vendor/bin/phpunit -c phpunit.xml.dist` runs to completion with no fatal | **DONE** | BUG-003 | `61d614c` |
| 0.1 | T-004 | Add regression tests for T-001/T-002/T-003 | High | T-001, T-002, T-003 | Prevents silent regression of the three fixes above | The tests themselves | **DONE** | BUG-001/002/003 | `a16f359`, `9bd693a`, `61d614c` |
| 0.1 | T-005b | Environment capability guards: portable `mbstring` fallback (`AIOS\Support\Strings`), `Crypto` graceful failure without an encryption backend, `EnvironmentGuard` diagnostics (admin notice, CLI, REST status) | High | — | Prevents uncaught fatals on security-critical code paths; no plaintext-encryption fallback introduced | `StringsTest`, `EnvironmentGuardTest`; bare `php -n` run confirmed only the expected, gracefully-failing CryptoTest cases remain | **DONE** | BUG-004 | `598ffea` |
| 0.1 | T-009b | Multisite lifecycle: `Activator`/`Deactivator`/`uninstall.php` genuinely loop every site (chose full provisioning over the T-008 "reject network activation" alternative); new-site provisioning via `wp_insert_site` | High | Multisite-capable test shim (built as part of this task, superseding the separate T-018 prerequisite) | Per-site data isolation preserved; retention read per-site; no cross-site data leakage or deletion | `MultisiteLifecycleTest` (11 cases) | **DONE** | BUG-005 | `b85feb0` |
| 0.1 | T-015-early | Pulled forward from Sprint 0.3: wire `ai_os_use`/`ai_os_approve` to the `administrator` role by default (minimum safe default — no other role touched) | Medium | — | Closes a "permanently dead capability constant" gap; no privilege escalation (administrator already satisfied every gate via `manage_options`) | `CapabilityLifecycleTest` (9 cases) | **DONE** | ARCH-005 (partial) | `c1fbf34` |

Original T-005/T-008/T-009 rows (the pre-sprint plan) are superseded by T-005b/T-009b above, which cover the same BUG-004/BUG-005 evidence but reflect what was actually implemented (graceful degradation chosen over a hard activation block for BUG-004; full multisite provisioning chosen over rejection for BUG-005). T-015 itself (full admin-grantable role/capability UI) remains open for Sprint 0.3 — only the "administrator gets a safe default" slice was pulled forward.

## Sprint 0.2 — Environment compatibility and activation diagnostics

| Sprint | Task ID | Task | Priority | Dependency | Security impact | Tests required | Status | Evidence | Commit |
|---|---|---|---|---|---|---|---|---|---|
| 0.2 | T-005 | Extend activation environment guard to check `mbstring` and (`openssl` OR `sodium`) | High | Sprint 0.1 complete | Prevents uncaught fatals on security-critical code paths (`Crypto`, `Validator`, `Sanitize`, `ApiKeyManager`) | Activation-guard test simulating missing extensions | **SUPERSEDED by T-005b (Sprint 0.1, DONE, `598ffea`)** | BUG-004 | |
| 0.2 | T-006 | Add clear admin notice describing the specific failed requirement | High | T-005 | Improves operator ability to diagnose a blocked activation before assuming a security failure | Manual verification + notice-rendering test | **DONE, folded into T-005b** — `AdminPages::environmentWarningNotice()` | BUG-004 | `598ffea` |
| 0.2 | T-007 | Document full environment matrix (PHP versions, extensions, WP versions) in `docs/INSTALLATION.md` | Medium | T-005, T-006 | None (documentation) | N/A | **DONE, folded into T-005b** | ENV-001, ENV-002 | `598ffea` |
| 0.2 | T-008 | Decide multisite posture: full per-site provisioning vs. explicit network-activation rejection | High | Sprint 0.1 complete | Prevents silent data-layer breakage on non-primary network sites | Decision recorded in `docs/ARCHITECTURE.md`; if rejection chosen, a test asserting network activation is refused with a clear message | **DECIDED: full provisioning** (see T-009b, Sprint 0.1, DONE) | BUG-005 | `b85feb0` |
| 0.2 | T-009 | Implement chosen multisite posture from T-008 | High | T-008 | If full provisioning: must not create tables/data outside each site's own prefix (no cross-tenant leakage) | Multisite activation test (requires multisite shim — see T-018) | **SUPERSEDED by T-009b (Sprint 0.1, DONE, `b85feb0`)** | BUG-005 | |

## Sprint 0.3 — Security hardening

| Sprint | Task ID | Task | Priority | Dependency | Security impact | Tests required | Status | Evidence | Commit |
|---|---|---|---|---|---|---|---|---|---|
| 0.3 | T-010 | Replace `HTTP_HOST`-based local-dev HTTPS exemption with a non-client-controlled signal | Medium | — | Closes a potential HTTPS-downgrade path on misconfigured hosts | Unit test: spoofed `Host` header does not trigger exemption when `WP_ENVIRONMENT_TYPE` ≠ `local` | **DONE** | SEC-M2 | `5eee086` |
| 0.3 | T-011 | Add `verifyNonce()` defense-in-depth check to `ToolsController::execute()` | Medium | Sprint 0.1 (T-002 touches same file) | Restores consistency with every other mutating REST endpoint | Test asserting cookie-authenticated execute without a valid nonce is rejected | **DONE** | SEC-M3 | `765f2e1` |
| 0.3 | T-012 | Make rate limiter atomic, or explicitly document it as abuse-deterrence-only in `docs/SECURITY.md` | Medium | — | Determines whether the rate limiter can be relied on as a hard ceiling | If fixed: concurrency test proving the limit holds under parallel `allow()` calls. If documented-only: no test, but `docs/SECURITY.md` update required | **DONE** (fixed, not just documented) | SEC-M1 | `b093e84` |
| 0.3 | T-013 | Add tamper-evidence to the audit log (hash-chaining or equivalent) | Medium | — | Strengthens any compliance/auditability claim | Test asserting a tampered row is detectable | **DONE** — tamper-*evidence* (HMAC chain), not tamper-*immutability*; rows remain physically mutable at the DB layer | SEC-M4 | `f3d7aa3` |
| 0.3 | T-014 | Add key-rotation handling to `Crypto` | Medium | Must land before any Phase-2 task persists `Crypto`-encrypted data | Prevents silent, permanent data loss on routine salt rotation | Test: rotate key material, assert old ciphertext is either still decryptable (versioned) or fails with a clear, detectable error (not silent) | **DONE** | SEC-M5 | `3f4e246` |
| 0.3 | T-015 | Wire `ai_os_use`/`ai_os_approve` to real, admin-grantable roles/capabilities | Medium | — | Enables real non-admin approver workflows; currently only `manage_options` holders can ever pass these gates | Test: a non-admin user granted `ai_os_approve` can approve; one without it cannot | **DONE** — role default landed early in Sprint 0.1 (T-015-early, `c1fbf34`); the admin-grantable per-user layer (`CapabilityManager` + REST surface) landed this sprint | ARCH-005 | `c1fbf34`, `ea452db` |

## Sprint 0.4 — Database/migration hardening

| Sprint | Task ID | Task | Priority | Dependency | Security impact | Tests required | Status | Evidence | Commit |
|---|---|---|---|---|---|---|---|---|---|
| 0.4 | T-016 | Add real MySQL/MariaDB integration test target | High | CI infra (may pull forward from Sprint 0.6) | Validates `dbDelta()` behavior beyond the test double | New CI-run integration suite | TODO | TEST-003 | |
| 0.4 | T-017 | Add schema-upgrade test (migration N applied to fixture on N-1 with real data) | High | T-016 | Prevents data loss on future schema changes | The test itself | TODO | TEST-004 | |
| 0.4 | T-018 | Build a multisite test shim/harness | High | — | Enabler for T-009, T-019, and Sprint 0.7 multisite tests | The harness, plus at least one passing multisite test using it | **DONE early** — built as part of T-009b (Sprint 0.1, `b85feb0`): blog-scoped options/transients, `get_sites()`/`switch_to_blog()`/`restore_current_blog()`, `dbDelta()` table logging. Deliberately does not teach `wpdb` full multisite SQL-prefix parsing — real-MySQL multisite testing is still Sprint 0.7. | TEST-002 (enabler) | `b85feb0` |
| 0.4 | T-019 | Add `uninstall.php` execution tests (both `remove_data_on_uninstall` states) | Medium | T-018 (for multisite variant) | Prevents accidental data loss or accidental data retention | The tests themselves | **DONE early** — `MultisiteLifecycleTest` + `CapabilityLifecycleTest` both invoke `uninstall.php` in-process under both retention states (Sprint 0.1, `b85feb0`/`c1fbf34`) | TEST-005 | `b85feb0` |
| 0.4 | T-020 | Optimize `ApiKeyRepository::revoke()` to direct `WHERE id = %d` lookup | Low | — | None (performance only) | Existing `ApiKeyTest` must remain green | TODO | SEC-L2 | |
| 0.4 | T-021 | Document forward-only migration strategy and rollback plan in `docs/ARCHITECTURE.md` | Low | — | None (documentation) | N/A | TODO | ARCH-002 (doc only) | |

## Sprint 0.5 — REST/MCP contract hardening

| Sprint | Task ID | Task | Priority | Dependency | Security impact | Tests required | Status | Evidence | Commit |
|---|---|---|---|---|---|---|---|---|---|
| 0.5 | T-022 | Build a real `WP_REST_Server`-based integration test harness | High | — | Enabler — this class of harness would have caught BUG-002 | The harness | TODO | TEST-001 | |
| 0.5 | T-023 | Exercise every controller's `args`/`sanitize_callback`/`permission_callback` through the harness | High | T-022 | Closes the exact gap class that produced BUG-002 | Full controller test matrix | TODO | TEST-010 | |
| 0.5 | T-024 | Add MCP idempotency-key support for mutating `tools/call` | Medium | — | Prevents duplicate execution on client retry | Test: identical idempotency key submitted twice executes once | TODO | ARCH-004 | |
| 0.5 | T-025 | Document MCP batch-vs-rate-limit interaction; decide whether to rate-limit per sub-request | Medium | — | Clarifies/optionally closes the batch-amplification nuance on the outer 'mcp' counter | If behavior changes: a test asserting the new per-sub-request limit | TODO | Audit §10 finding | |

## Sprint 0.6 — Coding standards / static analysis / CI

| Sprint | Task ID | Task | Priority | Dependency | Security impact | Tests required | Status | Evidence | Commit |
|---|---|---|---|---|---|---|---|---|---|
| 0.6 | T-026 | Add CI workflow: `composer install` → lint → `tests/run.php` → `vendor/bin/phpunit` | Critical (process) | Sprint 0.1 (T-003) | Prevents regression of every fix above from reaching a release | The workflow itself, green on the current baseline | **DONE, pulled forward into Sprint 0.3A** — `.github/workflows/ci.yml`; not yet executed against a real GitHub Actions runner (no composer/network in the authoring sandbox) — first real run establishes the actual baseline | REL-001 | `8efb2de` |
| 0.6 | T-027 | Add PHPCS + WordPress Coding Standards config, run in CI | Medium | T-026 | None directly; improves review consistency | CI passes (or a documented, ratcheted baseline) | **DONE, pulled forward into Sprint 0.3A** — `phpcs.xml.dist` (WordPress-Extra); no ratcheted baseline file checked in on purpose (see BUG-GAP-REGISTER Sprint 0.3A summary) | REL-002 | `8efb2de` |
| 0.6 | T-028 | Add PHPStan config, run in CI | Medium | T-026 | Can surface latent type-confusion bugs before they become security issues | CI passes (or a documented, ratcheted baseline) | **DONE, pulled forward into Sprint 0.3A** — `phpstan.neon.dist` (level 5, WordPress-aware); no baseline-suppression file checked in on purpose | REL-002 | `8efb2de` |
| 0.6 | T-029 | Add `composer audit` to CI | Medium | `composer.lock` committed | Surfaces known-vulnerable dependency versions | CI passes | TODO | REL-003 | |
| 0.6 | T-030 | Add `npm audit` + JS build-reproducibility check to CI | Medium | JS lockfile committed | Surfaces known-vulnerable JS dependencies; catches undetected build drift (LIKELY-003) | CI passes; diff check fails intentionally when bundle is stale | TODO | REL-003, REL-004 | |
| 0.6 | T-031 | Commit `composer.lock` and a JS lockfile | Medium | — | Reproducible builds | N/A | TODO | ENV-003, ENV-004 | |

## Sprint 0.7 — Real WordPress integration testing

| Sprint | Task ID | Task | Priority | Dependency | Security impact | Tests required | Status | Evidence | Commit |
|---|---|---|---|---|---|---|---|---|---|
| 0.7 | T-032 | Stand up real-WordPress test environment in CI (wp-env or equivalent) | High | Sprint 0.6 CI infra | Enabler for all remaining "unverified functionality" items | Environment boots green in CI | TODO | Audit §4 (enabler) | |
| 0.7 | T-033 | Multisite test coverage (activation, uninstall, context caching, per-site rate limiting) | High | T-018, T-032 | Closes BUG-005 verification gap fully | Full multisite test suite | TODO | TEST-002 | |
| 0.7 | T-034 | Concurrency tests (parallel approval claims, parallel rate-limiter hits, parallel migrations) | Medium | T-032 | Validates/quantifies SEC-M1 and confirms `ApprovalRepository::claimPending()` correctness under real concurrent load | The tests themselves | TODO | TEST-006 | |
| 0.7 | T-035 | WP-CLI command execution tests | Medium | T-032 | None directly; closes an unverified-functionality gap | `status`/`migrate`/`tools` command tests | TODO | TEST-007 | |

## Sprint 0.8 — Admin UX / accessibility / observability hardening

| Sprint | Task ID | Task | Priority | Dependency | Security impact | Tests required | Status | Evidence | Commit |
|---|---|---|---|---|---|---|---|---|---|
| 0.8 | T-036 | Browser-based smoke tests for the React admin console | Medium | — | None directly | Per-screen smoke test suite | TODO | TEST-008 | |
| 0.8 | T-037 | Accessibility pass on `app.jsx` (ARIA, keyboard nav) | Low | — | None | Manual + automated a11y check | TODO | Audit §14 | |
| 0.8 | T-038 | Add request/correlation IDs to audit log entries and structured errors | Medium | — | Improves incident traceability | Test asserting correlation id propagates end-to-end through one full tool-call trace | TODO | Observability gaps | |
| 0.8 | T-039 | Add a dedicated liveness/readiness health-check endpoint | Low | — | None | Endpoint test | TODO | Observability gaps | |
| 0.8 | T-040 | Document/expose metrics exportability for `tool_executions`/`audit_logs` aggregates | Low | — | None | N/A or a smoke test on the export path | TODO | Observability gaps | |

---

## Phase 2+ tracker seed (not started — sequencing only, per roadmap)

| Sprint | Task ID | Task | Priority | Dependency | Security impact | Tests required | Status | Evidence | Commit |
|---|---|---|---|---|---|---|---|---|---|
| 1.0 | T-101 | Transaction Engine | Critical | Sprint 0.x fully green | Foundational — every guarded mutation depends on correct transaction boundaries | Full unit + integration suite before any consumer is built | TODO | Roadmap Phase 2+ #1 | |
| 1.0 | T-102 | ChangeSet model | Critical | T-101 | Determines what a reviewable/approvable unit of change looks like | Unit tests | TODO | Roadmap Phase 2+ #2 | |
| 1.0 | T-103 | Snapshot Engine | High | T-102 | Basis for rollback correctness | Unit + integration tests | TODO | Roadmap Phase 2+ #3 | |
| 1.0 | T-104 | Diff Engine | High | T-103 | Basis for human-reviewable approval previews | Unit tests | TODO | Roadmap Phase 2+ #4 | |
| 1.1 | T-105 | Approval integration (ChangeSet-aware) | Critical | T-101..T-104 | Extends the existing, already-audited approval model — must not weaken it | Full regression of existing approval tests + new ChangeSet approval tests | TODO | Roadmap Phase 2+ #5 | |
| 1.1 | T-106 | Guarded mutation system | Critical | T-105 | Central enforcement point for Phase 2 write safety | Extensive integration tests | TODO | Roadmap Phase 2+ #6 | |
| 1.2 | T-107 | Verification engine | High | T-106 | Confirms a mutation had its intended (and only its intended) effect | Integration tests | TODO | Roadmap Phase 2+ #7 | |
| 1.2 | T-108 | Rollback system | Critical | T-103, T-106 | Must be trustworthy before any destructive Phase-2 capability ships | Integration tests including forced-failure rollback scenarios | TODO | Roadmap Phase 2+ #8 | |
| 1.3 | T-109 | Jobs / background execution | High | T-101 | Required before long-running mutations are safe to expose | Job-queue integration tests | TODO | Roadmap Phase 2+ #9 | |

Later Phase 2+ items (WordPress developer capabilities, Gutenberg, Elementor, WooCommerce, ACF/MetaBox/Pods, forms, SEO, staging/deployment, visual QA, multi-agent orchestration, enterprise RBAC) are intentionally not pre-seeded with task IDs here — they should be broken down at the start of their respective sprint, once the Transaction/ChangeSet/Snapshot/Diff/Rollback foundation (T-101..T-108) is complete and stable, per the dependency ordering in `docs/roadmap/ENTERPRISE-ROADMAP.md`.
