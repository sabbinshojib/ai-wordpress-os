# Sprint 0.3A — Security Hardening + CI Foundation Report

**Baseline:** Sprint 0.1 stabilization (`f6deb50`), plus SEC-M1/M2/M3/M4 already committed at the start of this sprint's working session
**Branch:** `sprint/0.3-security-ci` (not merged to `main`)
**Date:** 2026-09-07
**Source of truth:** `docs/audits/BUG-GAP-REGISTER.md`, `docs/roadmap/IMPLEMENTATION-TRACKER.md`, `docs/roadmap/ENTERPRISE-ROADMAP.md`

## Scope

Close the five Medium-severity security findings from the original audit (SEC-M1..M5), finish the admin-grantable capability layer (the remaining half of ARCH-005), and establish a CI/static-analysis foundation (pulled forward from Sprint 0.6). **No Phase 2 mutation-engine code was written this sprint** — see "Phase 2 status" below; an earlier draft of this sprint's documentation incorrectly described Phase 2 foundation work as already started, which was a documentation error, corrected before this report was finalized (never true, and never actually acted on in code).

## Commits (chronological, all on `sprint/0.3-security-ci`)

| Commit | Message | What it did |
|---|---|---|
| `b093e84` | `fix(security): atomic database-backed rate limiter` | SEC-M1 |
| `5eee086` | `fix(security): remove request-header trust from HTTPS decisions` | SEC-M2 |
| `765f2e1` | `fix(security): add nonce defense-in-depth to /tools/execute (SEC-M3)` | SEC-M3 |
| `f3d7aa3` | `feat(audit): add tamper-evident integrity chain (SEC-M4)` | SEC-M4 |
| `3f4e246` | `fix(crypto): add versioned ciphertext and key rotation semantics` | SEC-M5 |
| `ea452db` | `feat(security): add conservative admin-controlled capability management` | ARCH-005 remainder |
| `8efb2de` | `build(ci): add CI foundation — PHPUnit bridge, PHPStan, PHPCS, GitHub Actions` | REL-001/REL-002, T-026/027/028 pulled forward |
| *(this commit)* | `docs(sprint-0.3a): update roadmap/audit docs for Sprint 0.3A` | This report + register/tracker/roadmap/architecture/installation updates |

The first four commits (SEC-M1..M4) were already on the branch when this session's working checkpoint began ("PHP 8.2/8.3 WP-like, 233 tests, 0 failures"); their documentation had not yet been updated to reflect them, which this sprint's docs pass also corrects (see "Documentation debt closed" below).

## SEC-M1 — Rate limiter race condition (Medium)

**Root cause:** `RateLimiter::allow()` did `get_transient()` then `set_transient()` as two separate operations. Two concurrent requests for the same principal could both read the pre-increment count and both conclude they were under the limit.

**Fix:** Replaced the transient-based counter with an atomic, database-backed one (`RateLimitRepository`, a single SQL statement per check rather than a read-then-write pair).

**Files:** `src/Security/RateLimiter.php`, `src/Database/Repositories/RateLimitRepository.php`, a new migration.
**Tests:** `tests/Integration/RateLimiterTest.php`.

## SEC-M2 — HTTPS exemption trusted the `Host` header (Medium)

**Root cause:** `RestTransport::isLocalDevelopment()` treated any request whose `HTTP_HOST` contained `localhost`/`127.0.0.1`/`.local`/`.test` as exempt from the HTTPS requirement — a client-supplied header, spoofable behind a misconfigured proxy.

**Fix:** The local-development exemption now derives solely from `wp_get_environment_type()` (a server-side, non-client-controlled signal). No request header is inspected for this decision.

**Files:** `src/Mcp/Transports/RestTransport.php`.
**Tests:** `tests/Integration/RestTransportHostTrustTest.php`.

## SEC-M3 — `/tools/execute` missing defense-in-depth nonce (Medium)

**Root cause:** Every other mutating AI OS REST endpoint (`ApprovalsController`, `SettingsController`, `KeysController`) calls `verifyNonce()`; `ToolsController::execute()` did not, relying solely on core's own cookie-auth nonce enforcement.

**Fix:** Added the same `verifyNonce()` call for parity. Application-password and API-key auth remain exempt (not CSRF-exposed), matching every other controller's existing behavior.

**Files:** `src/Rest/Controllers/ToolsController.php`.
**Tests:** `tests/Integration/ToolsControllerNonceTest.php` (6 cases: cookie auth without/with invalid/with valid nonce, application-password exemption, API-key exemption, unauthenticated rejection).

## SEC-M4 — Audit log had no tamper-evidence (Medium)

**Root cause:** Audit rows could be edited or deleted at the database layer with no way to detect it.

**Fix:** New `AIOS\Audit\AuditIntegrity`: each row is HMAC-SHA256-chained to its predecessor (key domain-separated from `Crypto`'s own key derivation), covering the row's own redacted content + the predecessor's `record_hash` + `chain_seq`. Migration `202509060002` adds four nullable columns (`integrity_version`, `chain_seq`, `prev_hash`, `record_hash`) — nullable because every pre-migration row has none of them, and that "legacy" state is reported explicitly by `verifyChain()`, never silently treated as verified.

**This is tamper-*evidence*, not tamper-*immutability*.** The `audit_logs` table is an ordinary, physically-mutable MySQL table — nothing prevents a row from being edited or deleted at the database layer. What the hash chain provides is *detection*: `AuditLogger::verifyIntegrity()` / `wp ai-os audit verify` will report a broken chain link if that happens. Do not describe this as "immutable audit logs" anywhere customer-facing.

**Files:** new `src/Audit/AuditIntegrity.php`, `src/Database/Schema/Migration_202509060002_AuditIntegrity.php`, `src/Audit/AuditLogger.php`, `src/Database/Repositories/AuditLogRepository.php`.
**Tests:** `tests/Unit/AuditIntegrityTest.php` (22 cases), `tests/Integration/AuditChainTest.php`.

## SEC-M5 — `Crypto` had no key-rotation strategy (Medium)

**Root cause:** `Crypto`'s ciphertext was a single version byte + payload, with exactly one key (environment-derived or a dedicated key passed to the constructor). Rotating the underlying key material (e.g., `AUTH_KEY`/`AUTH_SALT`) would silently and permanently break decryption of anything already encrypted — no versioning, no previous-key support, no detectable failure mode distinct from "wrong data."

**Fix:** Replaced the wire format with an explicit, parseable envelope: `aios{version}:{backend}:{key_version}:{base64 payload}`. The envelope carries only non-secret metadata (format version, `sodium`/`openssl` backend id, an opaque key-version integer) — never a key or derived key. Legacy (pre-envelope) ciphertext is still detected unambiguously (it can never contain `:` — base64's alphabet excludes it) and decrypted against the current key and then every configured previous key, always flagged `legacy` in the result. Explicit, distinct failure codes: `malformed`, `unsupported_version`, `unknown_key_version`, `auth_failed`, `backend_unavailable` — corrupted/tampered ciphertext never returns a plaintext-shaped result. `reencrypt()` migrates any decryptable ciphertext (current, previous-key, or legacy) to the current key version; plaintext exists only in a local variable for the duration of that one call, never logged, returned, or persisted by the method itself.

**Files:** `src/Support/Crypto.php` (full rewrite of the ciphertext format; constructor gains two optional, backward-compatible parameters for previous-key versions and the current key-version id).
**Tests:** `tests/Unit/CryptoTest.php`, expanded from 5 to 31 cases: current-version round-trip, envelope parsing, malformed envelope (5 shapes), unsupported envelope version, unsupported legacy version byte, wrong key, unknown key version, tamper detection, auth-tag failure, corrupted-ciphertext-never-returns-plaintext, legacy decrypt + flagging, legacy wrong-key failure, previous-key decrypt (with and without registration), re-encrypt (current-key and legacy sources), re-encrypt-of-undecryptable-source returns null, no-plaintext-in-exceptions, no-key-in-exceptions, sodium path, OpenSSL path (hand-built envelope, exercised even when sodium is preferred), no-backend behavior (catchable `RuntimeException`, confirmed under bare `php -n`), multisite key-derivation sharing (`AUTH_KEY`/`AUTH_SALT` are network-wide wp-config.php constants), `keyHash`/`equals` (unchanged API).
**Still latent, as before:** no call site persists `Crypto`-encrypted data yet. The rotation mechanism exists and is tested; nothing in Phase 1 currently exercises it end-to-end against real stored ciphertext.

## ARCH-005 remainder — admin-grantable `ai_os_approve`/`ai_os_use` (Medium)

**Root cause:** Sprint 0.1 granted both capabilities to the `administrator` role at activation (a safe default) but left no mechanism for an admin to grant `ai_os_approve` to one specific non-administrator user — real multi-approver workflows had no path.

**Fix:** New `AIOS\Security\CapabilityManager`: a conservative, whitelist-only (`ai_os_use`, `ai_os_approve` — nothing else, ever) grant/revoke layer using real `WP_User::add_cap()`/`remove_cap()` (the same per-site mechanism `Activator` already uses at the role level), so every existing `has_cap()` gate (`PermissionEngine::canUse()`, `AbstractController::canApprove()`) picks up a change with zero other code touched. Enforces: acting user must hold `manage_options`; no self-escalation (an acting admin can never target their own user id, grant or revoke, through this mechanism — administrators already satisfy every gate unconditionally); every change audited.

REST surface (`AIOS\Rest\Controllers\CapabilitiesController`, wired through `RestApi`/the container): `GET /capabilities/{user_id}` (current grant state), `POST /capabilities/grant`, `POST /capabilities/revoke` — all `manage_options` + nonce, following the existing `KeysController` pattern exactly.

**Files:** new `src/Security/CapabilityManager.php`, new `src/Rest/Controllers/CapabilitiesController.php`, `src/Rest/RestApi.php`, `src/Core/CoreServiceProvider.php`.
**Test infrastructure:** the shim's `WP_User` gained real `add_cap()`/`remove_cap()`, persisted per-site (`$GLOBALS['__wp_shim']['user_caps'][blog_id][user_id]`) so `get_userdata()` reflects grants made on the current blog only — the same per-site isolation real WordPress usermeta has. `get_userdata()` extended to also recognize ids 3/4 (mirroring `TestCase::contributorUser()`/`anonymousUser()`).
**Tests:** `tests/Integration/CapabilityManagementTest.php`, 21 cases: grant/revoke both capabilities, idempotency, whitelist enforcement (rejects e.g. `manage_options`), target-not-found, no self-escalation (grant and revoke), unauthorized-caller rejection (contributor, anonymous), **the actual point of the feature** — an explicitly granted non-admin user passes the real `ApprovalsController::canApprove()` gate, an equivalent ungranted user does not — audit-row assertions (grant and revoke both produce a row; a *rejected* attempt produces none), `state()` reporting, multisite isolation (a grant on site 1 does not leak to site 2, and survives a round-trip through site 2 and back), and the full REST surface (nonce requirement, end-to-end grant→state→revoke, self-escalation via REST returns 403, `permission_callback` rejects a non-admin before the handler runs).

## CI / static-analysis foundation (REL-001, REL-002 — pulled forward from Sprint 0.6)

Added:
- Composer scripts: `test` (existing native runner, unchanged), `test:phpunit` (real PHPUnit bridge via `phpunit.xml.dist`), `acceptance` (existing, unchanged), `lint` (existing, unchanged), `stan` (PHPStan), `cs` / `cs:fix` (PHPCS/PHPCBF), and an aggregate `ci` script running all of the above in sequence.
- `phpstan.neon.dist`: level 5, `src/` only, WordPress-aware via `szepeviktor/phpstan-wordpress` + `php-stubs/wordpress-stubs` (require-dev additions). No baseline-suppression file is checked in.
- `phpcs.xml.dist`: `WordPress-Extra`, `src/` only, one structural exclusion (`WordPress.Files.FileName` — this is a Composer/PSR-4 codebase with StudlyCase class-name filenames, not a classic WP `includes/` tree) plus the correct `text_domain` (`ai-wordpress-os`, matching every `__()`/`_e()` call site). No ratcheted baseline file is checked in.
- `.github/workflows/ci.yml`: a required PHP 8.2/8.3 matrix (`composer validate --strict`, install, lint, native suite, PHPUnit bridge, acceptance), a separate best-effort/non-blocking PHP 8.4 job, and a `static-analysis` job (PHPStan + PHPCS).

**What could not be verified in this sandbox, and why:** no `composer` binary, no `vendor/`, no `composer.lock`, and no network access were available in the authoring session (confirmed: `where composer` finds nothing; a network reachability check was declined). `composer install`, `phpstan`, and `phpcs` have therefore **never actually been run** against this codebase. Every config file was instead validated structurally — `composer.json` via `json_decode()` + error check, `phpcs.xml.dist` via `DOMDocument::load()` (well-formed XML), `phpstan.neon.dist` and `.github/workflows/ci.yml` checked for tab characters (invalid in YAML/NEON) and hand-reviewed for consistent indentation. The native WP-like suite and acceptance suite were re-run and stayed green (see the test matrix below) after every change to confirm nothing in the CI/composer.json edits broke the parts of the toolchain that *could* be exercised locally.

**The honest baseline is whatever the first real GitHub Actions run of the `static-analysis` job reports.** Since this codebase has never been statically analyzed before, any findings from that first run are new information, not a regression — do not treat them as blocking without triage, and do not add a suppression file to make them disappear without reading them first.

## Phase 2 status: **not started — zero code**

No `AIOS\Mutation\*` namespace, and none of `ChangeSet`, `ChangeOperation`, `Snapshot`, `Diff`, `VerificationResult`, or `RollbackRecord` exist anywhere in this repository as of this sprint. `docs/ARCHITECTURE.md` §13 records the *target* pipeline design (`User Request → Planner → Policy → Snapshot → ChangeSet → Diff → Approval → Apply → Verify → Audit → Rollback`) for when that work actually starts — it is explicitly labeled "design only" there, not an implementation status.

An earlier working draft of this sprint's documentation (since corrected, before being committed) incorrectly stated that a Phase 2 "foundation" had been started. That was a documentation-only error: it was never true, and Sprint 0.3A did not touch, create, or modify any file under a `Mutation` namespace or equivalent. This report exists partly to make that correction visible and durable, rather than silently fixing the wording and moving on.

## Documentation debt closed

`docs/audits/BUG-GAP-REGISTER.md` and `docs/roadmap/IMPLEMENTATION-TRACKER.md` had not been updated after SEC-M1..M4 landed earlier in this branch's history (all four commits predate this session's working checkpoint) — both still showed `TODO`/`OPEN` for items that were already fixed and tested. This sprint's docs pass corrected that alongside documenting the SEC-M5/capability/CI work, so the tracker and register now reflect reality for all of SEC-M1..M5, the ARCH-005 remainder, and REL-001/REL-002.

## Test matrix

| Environment | Before this sprint's session (checkpoint) | After (this report) |
|---|---|---|
| PHP 8.2, WP-like ini, `tests/run.php` | 233 tests, 0 failures | **280 tests, 0 failures** |
| PHP 8.3, WP-like ini, `tests/run.php` | 233 tests, 0 failures | **280 tests, 0 failures** |
| PHP 8.2, bare `-n` (no extensions) | Not re-verified this session prior to SEC-M5 | `Crypto::encrypt()` throws a catchable `RuntimeException` with no plaintext leak; `decrypt()` on malformed input returns `null`, not a fatal — confirmed directly (see SEC-M5 section) |
| `tests/acceptance.php` | 13/13 (Sprint 0.1 baseline) | **13/13 pass**, unchanged |
| Real PHPUnit (`vendor/bin/phpunit`) | N/A — no `vendor/` in this sandbox | **Not executed this sprint** — no composer/vendor/network available; `phpunit.xml.dist`'s native-bridge suite is unchanged from Sprint 0.1 and was not modified |
| `php -l` syntax lint, every touched file | — | **0 errors**, PHP 8.2 and 8.3, on every file touched this sprint |
| `git diff --check` | — | **Clean** at every commit boundary (only line-ending advisory warnings, no whitespace/conflict-marker errors) |
| PHP 8.4 | Unusable on this host (VC++ runtime mismatch) | **Unchanged — still unusable on this host** (confirmed again this sprint: `php.exe -v` exits 1 with "VCRUNTIME140.dll ... not compatible"). Not a plugin defect. Included in `.github/workflows/ci.yml` as a best-effort, non-blocking job since GitHub's hosted runners provision PHP 8.4 correctly via `setup-php`, independent of this local host's toolchain gap. |

## Security regression checks performed

Re-verified green after every commit in this sprint, not merely at the end: authentication, API-key validation, `PermissionEngine` ceiling enforcement, approval gating, `PathGuard` confinement, secret redaction, tool-identifier rejection, multisite lifecycle (activation/uninstall/new-site provisioning), and capability lifecycle (Sprint 0.1's role-default grants) — all remained green throughout via the full native suite. No `eval`, shell-exec-family function, `unserialize()`, or file-write function was introduced anywhere in `src/` (unchanged from every prior sprint's sweep).

## Multisite behavior (verified this sprint)

- `CapabilityManager` grants/revokes are per-site: a grant made on blog 1 does not appear on blog 2, and survives a `switch_to_blog(2)` → `restore_current_blog()` round-trip back to blog 1 unchanged (`test_capability_grants_are_isolated_per_site`).
- `Crypto`'s key derivation is unaffected by multisite: `AUTH_KEY`/`AUTH_SALT` are wp-config.php constants shared network-wide, so two `Crypto` instances relying on environment-derived keys (no dedicated key passed) remain mutually decryptable regardless of which site issued them (`test_key_derivation_uses_shared_auth_constants`).
- No change to Sprint 0.1's existing multisite activation/uninstall/new-site-provisioning behavior — `MultisiteLifecycleTest`'s 11 cases remain green, unmodified.

## Remaining blockers (beyond this sprint's scope)

1. Sprints 0.4 (DB/migration hardening), 0.5 (REST/MCP contract hardening against a real transport), 0.7 (real WordPress integration testing), and 0.8 (admin UX/observability) remain fully open.
2. `composer install`/PHPStan/PHPCS have never actually been executed against this codebase — the first real CI run is the true baseline, and may surface real findings that need triage.
3. `composer audit`/`npm audit`/lockfiles (REL-003, REL-004, T-029/030/031) — explicitly out of this sprint's scope, tracked for Sprint 0.6.
4. PHP 8.4 remains unverified locally (host toolchain limitation, not a plugin defect) — the CI workflow's best-effort job is the first real chance to establish whether it is actually clean.
5. Phase 2 (mutation engine) has not started — see "Phase 2 status" above.

## Sprint 0.3A completion

All originally-scoped Sprint 0.3 items (SEC-M1..M5, ARCH-005 remainder) plus the pulled-forward CI foundation (REL-001, REL-002) are complete, tested, and committed. **Sprint 0.3A is closed** as of this report.
