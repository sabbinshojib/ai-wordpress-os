# Sprint 0.1 — Stabilization Report

**Baseline commit:** `84d3c97bcd33d4d237a6e21593b6cecddbe36403`
**Branch:** `sprint/0.1-stabilization` (not merged to `main`)
**Date:** 2026-09-06
**Source of truth:** `docs/audits/ENTERPRISE-READINESS-AUDIT.md`, `docs/audits/BUG-GAP-REGISTER.md`, `docs/roadmap/ENTERPRISE-ROADMAP.md`

## Scope

Fix the five confirmed Phase-1 correctness blockers identified by the enterprise readiness audit (BUG-001..005), plus the minimum supporting changes required to make the existing system robust and testable — and, pulled forward from Sprint 0.3, close the "permanently dead capability constant" gap around `ai_os_use`/`ai_os_approve` since it directly affects whether the plugin is usable immediately after activation. No Phase-2 capability (Transaction Engine, ChangeSet, Snapshot, Rollback) was added. No architecture was redesigned beyond what each confirmed defect required.

## Commits (chronological, all on `sprint/0.1-stabilization`)

| Commit | Message | What it did |
|---|---|---|
| `5a98744` | `docs(audit): add enterprise readiness findings` | Committed the four audit documents (no source change). |
| `a16f359` | `fix(pathguard): normalize Windows paths safely` | BUG-001. |
| `9bd693a` | `fix(rest): preserve canonical tool identifiers` | BUG-002. |
| `61d614c` | `test(phpunit): repair test bootstrap` | BUG-003. |
| `598ffea` | `fix(runtime): add environment capability guards` | BUG-004. |
| `b85feb0` | `fix(multisite): harden activation and uninstall lifecycle` | BUG-005. |
| `c1fbf34` | `fix(capabilities): establish safe default role grants` | Capability usability (ARCH-005, partial). |

## Bugs fixed and root causes

### BUG-001 — Windows `PathGuard` self-rejection (Critical)

**Root cause:** `PathGuard::isInsideRoot()` re-derived an absolute path via `toAbsolute()` even when already given one; `toAbsolute()` unconditionally rejected any path beginning with a Windows drive letter. Since a guard root is itself a drive-letter path on Windows, the guard's own internal round-trip rejected itself.

**Fix:** `toAbsolute()` now classifies any absolute-looking input (POSIX, Windows drive-letter, or UNC) as "use as-is" instead of rejecting drive letters outright; legitimacy is decided uniformly downstream by the existing root-confinement check. Added case-insensitive comparison helpers (`pathsEqual()`/`pathStartsWith()`) so Windows's case-insensitive filesystem semantics can't be used to bypass a directory-qualified protected-path entry.

**Files:** `src/Security/PathGuard.php`.
**Tests added:** 9 new cases in `tests/Unit/PathGuardTest.php` (Windows absolute path within root, mixed separators, drive-letter mismatch, sibling-prefix escape, case variation on protected and allowed files, percent-encoded traversal, UNC rejection, POSIX-relative baseline).

### BUG-002 — REST `/tools/execute` `sanitize_key` defect (Critical)

**Root cause:** The `tool` REST argument used `sanitize_key()`, which strips every character outside `[a-z0-9_-]` — including the `.` every real tool name requires (`Tool::make()`'s own naming grammar mandates at least one dot). Every REST-submitted tool name was silently mangled into a nonexistent identifier before reaching the registry.

**Fix:** Added `Tool::NAME_PATTERN` as the single canonical grammar, used by both `Tool::make()` and two new `ToolsController` methods: `sanitizeToolIdentifier()` (trim + lowercase only — meaning-preserving, never destructive) and `validateToolIdentifier()` (rejects malformed/control-character/injection-shaped input with a structured 400 before the executor ever sees it).

**Files:** `src/Tools/Tool.php`, `src/Rest/Controllers/ToolsController.php`.
**Tests added:** `tests/Unit/ToolIdentifierTest.php` (16 cases: identity preservation, case-folding, whitespace, control characters, path-/code-injection-shaped payloads) + 2 integration regressions confirming all 31 catalog tools survive sanitization unchanged and remain invokable afterward.

### BUG-003 — PHPUnit bootstrap defect (High)

**Root cause:** `phpunit.xml.dist`'s bootstrap never loaded `tests/TestCase.php`; real PHPUnit fataled immediately with `Class "AIOS\Tests\TestCase" not found`. Fixing that alone exposed a deeper issue: every native `*Test` class extends this project's own hand-rolled `TestCase`, not `PHPUnit\Framework\TestCase`, so PHPUnit still couldn't discover any test methods (confirmed: one "does not extend PHPUnit\Framework\TestCase" warning per class, zero tests executed).

**Fix:** `tests/bootstrap.php` now requires `TestCase.php`. Rather than rewriting ~150 test methods into a second, parallel PHPUnit-native implementation (or making the shared base class unsafely extend both frameworks' incompatible execution models — a non-throwing, failure-accumulating assert style vs. PHPUnit's throw-on-failure style, which would risk a recorded native failure being silently reported as a PHPUnit pass), added `tests/PHPUnit/NativeSuiteBridgeTest.php`: a real `PHPUnit\Framework\TestCase` with one data-provider-driven test per native class, each running that class's own `run()` and asserting zero failures, printing every native failure message verbatim on mismatch. Also removed `phpunit.xml.dist`'s three deprecated `convert*ToExceptions` attributes (confirmed via PHPUnit's own `--migrate-configuration`, run only against a scratch copy under `%TEMP%`, never the repository file).

**Files:** `tests/bootstrap.php`, `phpunit.xml.dist`, new `tests/PHPUnit/NativeSuiteBridgeTest.php`.
**Result:** `vendor/bin/phpunit -c phpunit.xml.dist` → `OK (17 tests, 51 assertions)`, zero warnings/deprecations, on both PHP 8.2 and 8.3. `tests/run.php` (the detailed, per-method runner) and `tests/acceptance.php` are both unaffected and remain the primary tools.

### BUG-004 — Missing PHP extension/runtime guards (High)

**Root cause:** `mb_strlen()`/`mb_substr()` were called directly in a dozen places with no `mbstring`-loaded check; `Crypto::encrypt()`/`decrypt()` called `openssl_encrypt()`/`openssl_decrypt()` directly with no `function_exists()` guard when `sodium` was unavailable. Confirmed directly: `php -n tests/run.php` (bare PHP, no optional extensions) produced 54 failures, every one an uncaught `Error: Call to undefined function`.

**Fix — graceful degradation, not a hard activation block, for the extension that has a safe fallback:**
- New `AIOS\Support\Strings::length()`/`truncate()`, preferring `mb_strlen()`/`mb_substr()` and falling back to a portable byte-based equivalent. The fallback is a documented approximation, never a security control: `length()`'s fallback (byte count) is always ≥ the true character count for UTF-8, so a `maxLength` check can only be stricter than intended; `truncate()`'s fallback scans backward from the cut point for the last UTF-8 sequence's lead byte and drops it whole if it would otherwise be left incomplete, so it can never return a malformed byte sequence. Every direct `mb_strlen()`/`mb_substr()` call in `src/` now goes through this helper.
- `Crypto::encrypt()` now throws a clear, catchable `RuntimeException` (not an uncaught fatal) when neither `sodium` nor `openssl` is available; `decrypt()` returns `null` (consistent with its existing "can't process this input" contract). No plaintext fallback for encryption exists or should ever exist.

**Fix — centralized diagnostics for the one thing that genuinely has no fallback:**
- New `AIOS\Core\EnvironmentGuard`: reports degraded (never blocking) optional capabilities. Wired into `Activator::activateSingleSite()` (records to a transient), `AdminPages::environmentWarningNotice()` (non-blocking `notice-warning`), `CliCommands::status()`, and the REST `/status` endpoint — one source of truth for all three surfaces.

**Files:** new `src/Support/Strings.php`, new `src/Core/EnvironmentGuard.php`, `src/Support/Crypto.php`, `src/Support/Validator.php`, `src/Support/Sanitize.php`, `src/Security/ApiKeyManager.php`, all four `src/Database/Repositories/*.php`, `src/Audit/AuditLogger.php`, `src/Tools/Catalog/ContentTools.php`, `src/Core/Activator.php`, `src/Admin/AdminPages.php`, `src/Cli/CliCommands.php`, `src/Rest/Controllers/SiteController.php`, `docs/INSTALLATION.md`.
**Tests added:** `tests/Unit/StringsTest.php` (a real bug was caught and fixed while writing this: the first `truncateFallback()` draft only stripped trailing UTF-8 continuation bytes and missed a truncated multi-byte *lead* byte with no continuation at all), `tests/Unit/EnvironmentGuardTest.php`.
**Result:** bare `php -n`: 183 pass, 4 fail (all `CryptoTest` — see "Remaining, intentionally-unsupported case" below). Any WP-realistic extension set: 187/187.

### BUG-005 — Multisite activation/uninstall lifecycle gap (High)

**Root cause:** `Activator::activate(bool $network_wide)` accepted but never read `$network_wide` — network activation only ever provisioned whichever site initiated the request. `uninstall.php` dropped tables using the bare current-site `$wpdb->prefix` with no loop over the network.

**Fix (chose full per-site provisioning over rejecting network activation):**
- `Activator::activate()` now loops `get_sites()`/`switch_to_blog()`/`restore_current_blog()` over every site when `$network_wide` is true, provisioning each via a new `activateSingleSite()` (the original method body, unchanged in substance, extracted so it can run once per site or once for a plain single-site activation).
- `uninstall.php` gets the identical loop for both the table/option removal and the always-run transient/cron cleanup, via a new `ai_os_uninstall_current_site()` function. Retention (`remove_data_on_uninstall`) is read from *each site's own* settings — one site's opt-in never overrides another's opt-out.
- `Deactivator` gets the same loop for symmetry (lower risk — cron/transient clearing only — but the identical missing-parameter defect existed there too).
- New `Activator::provisionNewSite()`, hooked to `wp_insert_site` in `CoreServiceProvider::boot()`, provisions a site added to an *already* network-active install (checked via `is_plugin_active_for_network()`).

**Test infrastructure built to make this verifiable (see "Compatibility implications" below for what it does and does not model):** the shim gained blog-scoped options/transients, `get_sites()`/`switch_to_blog()`/`restore_current_blog()`/`get_current_blog_id()`/`is_plugin_active_for_network()`, `dbDelta()` table-name logging, and `wpdb::get_col()` (a real, separate shim gap this work exposed — `uninstall.php`'s rate-limiter transient scan legitimately calls it, and it simply didn't exist).

**Files:** `src/Core/Activator.php`, `src/Core/Deactivator.php`, `src/Core/CoreServiceProvider.php`, `uninstall.php`, `tests/shim/wp-functions.php`.
**Tests added:** `tests/Integration/MultisiteLifecycleTest.php` (11 cases: network activation provisions every site with independently-tracked migration state, non-network activation touches only the current site, blog-switch balance including on new-site provisioning, network uninstall removes every opted-in site's data while respecting per-site retention, runtime cache always cleared regardless of retention, unrelated options never touched, network deactivation balances its own switches).
**Also fixed as a direct consequence:** six pre-existing tests (`SettingsTest`, `DatabaseTest`, `ToolExecutorTest`, `McpServerTest`, `TestCase::resetPlugin()`) poked `$GLOBALS['__wp_shim']['options']` directly instead of calling `update_option()` — harmless before blog-scoping, would have silently written to the wrong structure after it. Converted to the real accessor function.

### Capability usability — `ai_os_use`/`ai_os_approve` (pulled forward from Sprint 0.3, ARCH-005 partial)

**Investigation finding:** these two capabilities were referenced by `PermissionEngine::canUse()` and `AbstractController::canApprove()` but never granted to any role. **Not an active vulnerability** — `manage_options` already satisfies every gate they exist for, and `edit_posts` already satisfies baseline use for Editors/Authors/Contributors — but a real usability/design gap: there was no way for either capability to ever become true for anyone, and no verification that activation/deactivation/uninstall treat them correctly.

**Fix (minimum safe default):** new `Activator::grantDefaultCapabilities()`, called from `activateSingleSite()` (so it runs per-site under network activation too): grants both capabilities to the `administrator` role **only**. No other role is touched. `uninstall.php`'s opt-in data-removal branch removes exactly these two capabilities from the administrator role — never anything else. `Deactivator` is intentionally untouched: capabilities are only ever removed on explicit uninstall opt-in, never on deactivation.

**Files:** `src/Core/Activator.php`, `uninstall.php`, `tests/shim/wp-functions.php` (minimal `WP_Role`/`get_role()`), `src/Security/PermissionEngine.php` and `src/Rest/Controllers/AbstractController.php` (doc comments only).
**Tests added:** `tests/Integration/CapabilityLifecycleTest.php` (9 cases: administrator gets both capabilities and nothing extra, lower roles get neither, the grant is idempotent, deactivation never touches capabilities, uninstall-with-retention leaves them granted, uninstall-with-removal strips only the two plugin-owned capabilities, and the end-to-end consequence — an administrator can use and approve immediately, a contributor can use but never approve).
**Still open (Sprint 0.3, roadmap T-015):** an admin UI/API for granting `ai_os_approve` to a non-administrator user or role.

## Files changed (summary)

41 files touched (excluding the audit/roadmap docs themselves, which were committed separately in `5a98744`): 15 source files under `src/`, 2 root files (`uninstall.php`, `phpunit.xml.dist`), 1 doc (`docs/INSTALLATION.md`), 1 new `.gitignore`, and 13 test files (5 new: `MultisiteLifecycleTest.php`, `CapabilityLifecycleTest.php`, `StringsTest.php`, `EnvironmentGuardTest.php`, `ToolIdentifierTest.php`, plus the new bridge `NativeSuiteBridgeTest.php` — 6 new files total; 8 modified). Net: **2,036 insertions, 114 deletions** across the non-doc diff. Full file list is in each commit's message above and visible via `git show --stat <hash>`.

**No unrelated changes.** Every file touched maps directly to one of the six items above (BUG-001..005 + capability usability), its shim-side test-infrastructure prerequisite, or a documentation update describing the fix.

## Security implications

- **No security control was weakened.** PathGuard's confinement got *more* correct (fixed false negatives on Windows without introducing any false positive — the same root-confinement/protected-path/extension checks still gate every path, regardless of how it was classified as absolute). The REST tool-identifier fix *closes* an unsanitized-input class of risk (a fully mangled name could never be exploited, but the fix also adds explicit control-character/injection-payload rejection that didn't exist before). Environment guards *add* graceful failure without ever introducing an insecure fallback (no plaintext-encryption path was added; `Crypto` simply fails cleanly instead of fataling). Multisite provisioning preserves per-site data isolation — no site's data is ever written into, or deleted from, another site's tables/options. Capability grants are strictly additive-to-administrator-only, verified by an explicit test that lower roles receive nothing and that no unrelated capability (`manage_options`, `edit_posts`, etc.) is ever touched by the uninstall removal path.
- **Security regression sweep performed** (see "Security regression checks" below): authentication, API-key validation, `PermissionEngine` enforcement, approval gating, PathGuard confinement, protected-file blocking, secret redaction, and tool-identifier rejection were all re-verified green after every fix, not just at the end.
- **No new attack surface was introduced.** No `eval`, no shell-exec family functions (`exec`/`shell_exec`/`system`/`passthru`/`proc_open`/`popen`), no `unserialize()`, no remote code inclusion, and no file-write functions exist anywhere in `src/` — confirmed by a repo-wide grep as part of this sprint's regression pass (unchanged from the original audit's finding that Phase 1 is genuinely read-only).
- **Deferred, not fixed:** every Medium/Low security finding from the original audit (SEC-M1..M5, SEC-L1..L4 — rate-limiter atomicity, `Host`-header trust, missing defense-in-depth nonce on `/tools/execute`, audit-log tamper-evidence, `Crypto` key-rotation strategy) is explicitly Sprint 0.3 scope and was **not** touched this sprint, per the mission's own boundary ("do not weaken security... do not attempt Sprint 0.3 hardening unless it directly blocks Sprint 0.1 correctness"). None of the BUG-001..005 fixes interact with or depend on any of these Medium findings.

## Compatibility implications

- **Windows and Linux:** BUG-001's fix was designed and tested with both host types in mind — case-insensitive comparison is applied *only* when `DIRECTORY_SEPARATOR === '\\'` (Windows), so POSIX hosts keep exact case-sensitive matching (no loosening there). The `test_posix_style_relative_path_still_resolves` regression test exists specifically to pin this.
- **Single-site:** every existing single-site test (167 of them, pre-dating this sprint) passes unchanged. Blog-scoping options/transients by `current_blog_id` is additive and backward-compatible: every prior test leaves `current_blog_id` at its default of `1` and never calls `switch_to_blog()`, so behavior is bit-for-bit identical to the old flat store.
- **Multisite:** now genuinely supported for the specific lifecycle events audited (activation, deactivation, uninstall, new-site provisioning). **What is still unverified:** real MySQL/MariaDB behavior under multisite table prefixes (`wp_2_ai_os_audit_logs` etc.) — the test shim's `wpdb` deliberately does not become a full SQL-prefix-aware engine (confirmed scope boundary, tracked as the remainder of TEST-002 for Sprint 0.7), and multisite coverage of context caching / per-site rate limiting specifically was not added this sprint.
- **Upgrade path:** a site already running the pre-Sprint-0.1 code and updating to this branch will, on next activation (or the next `wp ai-os migrate` if not reactivated), gain the `ai_os_use`/`ai_os_approve` grant on its administrator role — a strictly additive, idempotent change, not a breaking one.
- **PHP 8.4:** still unverifiable on this development host (VC++ runtime mismatch — vs17/14.44 build vs. the host's installed 14.22; a host limitation, not a plugin defect, unchanged from the original audit). No code path added this sprint uses any PHP-8.4-specific or deprecated-in-8.4 construct as far as static review could confirm; PHP 8.2 and 8.3 are both fully green.

## Test matrix — before (baseline audit) vs. after (this sprint)

| Environment | Before | After |
|---|---|---|
| PHP 8.2, WP-like ini, `tests/run.php` | 128 tests, 7 failures | **187 tests, 0 failures** |
| PHP 8.3, WP-like ini, `tests/run.php` | 128 tests, 7 failures | **187 tests, 0 failures** |
| PHP 8.2, bare `-n` (no optional extensions), `tests/run.php` | 128 tests, 54 failures | **187 tests, 4 failures** (see below — intentional) |
| PHP 8.4 | Unusable on this host (VC++ mismatch) | Unchanged — still unusable on this host |
| `tests/acceptance.php` | 13/13 pass | **13/13 pass** (unchanged — was already fully green) |
| Real PHPUnit (`vendor/bin/phpunit -c phpunit.xml.dist`) | Fatal: `Class "AIOS\Tests\TestCase" not found`, 0 tests run | **`OK (17 tests, 51 assertions)`**, zero warnings/deprecations |
| `php -l` syntax lint, every PHP file | 90 files, 0 errors | **98 files, 0 errors** |
| `git diff --check` | N/A (no diff yet) | **Clean** — no whitespace/conflict-marker errors |

### Remaining, intentionally-unsupported case: bare `php -n`'s 4 `CryptoTest` failures

Under literally zero optional PHP extensions (no `sodium`, no `openssl` — an extremely rare, deliberately-crippled PHP build; `sodium` alone has shipped by default since PHP 7.2), `AIOS\Support\Crypto`'s four round-trip tests fail with a clean, catchable `RuntimeException: ai-os: no encryption backend available`, not an uncaught fatal. This is the **correct, honest** behavior for a feature (secret encryption) that has no safe fallback — there is no way to "gracefully degrade" encryption without either (a) refusing to run, which is what happens, or (b) silently storing plaintext, which must never happen. This is classified as an **intentionally-unsupported runtime for that one feature**, not a portability defect, not a regression, and not "shipped broken" — distinguished explicitly from BUG-004's actual defect (an *uncaught fatal* with no diagnostic), which is fully resolved.

## Multisite behavior (verified)

- Network activation (`$network_wide = true` + `is_multisite()`) provisions all four tables for every site in the network, with independently-tracked migration-applied state per site.
- A plain (non-network) activation on a multisite install touches only the current site — `$network_wide = false` never implicitly becomes "every site."
- `switch_to_blog()`/`restore_current_blog()` calls are verified balanced after both network activation and network deactivation (no dangling blog-switch state left behind, including on the new-site-provisioning path).
- A site added to an already network-active install is auto-provisioned via the `wp_insert_site` hook; a site added while the plugin is *not* network-active is not touched (it provisions itself normally if/when it activates the plugin).
- Network-wide uninstall (with per-site opt-in) removes every opted-in site's tables/options while leaving a non-opted-in site's data fully intact — retention is genuinely per-site, never a global override.
- Runtime cache (transients, cron) is always cleared for every site during uninstall regardless of that site's retention choice — it is never treated as "data" a retention setting applies to.
- Uninstall never touches an option it does not own, on any site (verified with a planted unrelated option).

## Capability behavior (verified)

- Administrator role receives `ai_os_use` and `ai_os_approve` at activation; no other built-in role (editor, author, contributor, subscriber) receives either.
- The grant is idempotent — re-running activation does not error or change state.
- Deactivation never touches any role's capabilities (only cron/transients, per BUG-005's fix).
- Uninstall with retention chosen leaves both capabilities granted; uninstall with data removal chosen strips exactly those two capabilities and nothing else (`manage_options`, `edit_posts`, etc. all verified to survive).
- End-to-end: an administrator can use (`PermissionEngine::canUse()`) and would be able to approve immediately after a plain activation with zero extra setup; a contributor-level account can use the console at its permitted ceiling but can never approve.

## Security regression checks performed

Re-verified green (via the full 187-test suite plus a dedicated repo-wide grep) after every fix in this sprint, not merely at the end:

| Invariant | Status |
|---|---|
| Authentication required for MCP/REST | ✅ Unchanged, all `Authenticator`/`RestTransport::permission()` tests pass |
| API key validation (format, hash lookup, revocation, expiry) | ✅ `ApiKeyTest` (9 cases) unaffected, still green |
| `PermissionEngine` ceiling enforcement (mode × capability × grant × key) | ✅ `PermissionEngineTest` (13 cases) unaffected, still green |
| Approval gating (queue, claim atomicity, execution) | ✅ `ToolExecutorTest` approval-flow cases unaffected, still green |
| PathGuard confinement (traversal, escape, protected files) | ✅ Strengthened — 20/20 `PathGuardTest` cases now pass (was 14/20 with 6 masked-by-extension-absence failures pre-existing at audit time, now genuinely all green post-fix) |
| Protected files (`wp-config.php`, `.env`, etc.) remain blocked | ✅ Explicitly re-verified, including new case-variation and UNC-path regression tests |
| Secrets not logged | ✅ `SanitizeTest` (8 cases) and `test_audit_log_redacts_secret_arguments` unaffected, still green; confirmed no new `error_log`/logging call site was added that bypasses `Sanitize::redact()` |
| Invalid tool identifiers rejected | ✅ New: 16 `ToolIdentifierTest` cases plus 2 integration regressions, all passing |
| No privilege self-escalation | ✅ Confirmed: `ai_os_use` alone (without `manage_options`/`edit_posts`) still yields ceiling `-1` (no access) via `PermissionEngine::capabilityCeilingFor()` — granting it broadly would not itself unlock any tool, and it is not granted broadly regardless |
| No arbitrary file write | ✅ Repo-wide grep confirms zero file-write functions (`file_put_contents`, `fwrite`, `fopen(...,'w')`) anywhere in `src/` |
| No arbitrary shell execution | ✅ Repo-wide grep confirms zero shell-exec-family functions (`exec`, `shell_exec`, `system`, `passthru`, `proc_open`, `popen`, `pcntl_exec`) anywhere in `src/` |
| No `eval` | ✅ Repo-wide grep confirms zero `eval`/`assert()`-as-eval/`create_function` anywhere in `src/` |
| No remote executable code loading | ✅ Repo-wide grep confirms no URL-based `include`/`require`, no `allow_url_include` reliance |
| REST errors do not expose secrets | ✅ `StructuredError` envelope unchanged; no new error path returns raw exception messages or stack traces |

## Remaining failures

None that represent a defect. The only non-passing cases in the entire matrix are the 4 `CryptoTest` cases under a bare `php -n` environment with zero optional extensions — an intentionally-unsupported runtime for the encryption feature specifically (see above), not a portability defect.

## Remaining blockers (for a production release, beyond this sprint's scope)

Everything below was already known at audit time, deliberately deferred, and remains open — nothing was newly discovered as a Sprint-0.1 blocker:

1. Sprint 0.3 Medium-severity security hardening (rate-limiter atomicity, `Host`-header trust, `/tools/execute` defense-in-depth nonce, audit-log tamper-evidence, `Crypto` key-rotation strategy).
2. No CI, static analysis, or dependency-audit tooling exists yet (Sprint 0.6).
3. Real WordPress REST-transport integration testing (Sprint 0.5) and real-MySQL multisite testing (Sprint 0.7) remain open — this sprint closed the *code-level* defects in both areas but not the *deeper integration-test* gaps.
4. Sprint 0.3's full admin-grantable role/capability UI (T-015's non-administrator-approver case) is still open.

## Deferred issues (explicitly out of this sprint's scope, unchanged from the audit)

- Everything in `docs/roadmap/ENTERPRISE-ROADMAP.md` Sprint 0.2 (the parts not pulled forward — see the tracker's superseded-row notes), 0.3 (except the T-015 slice), 0.4 (except T-018/T-019, pulled forward), 0.5, 0.6, 0.7 (except the multisite shim prerequisite), 0.8, and all Phase 2+ items.

## Recommended Sprint 0.2

Given Sprint 0.2's original scope (environment compatibility documentation, multisite posture decision) was substantially completed as part of this sprint's BUG-004/BUG-005 fixes, recommend **skipping directly to Sprint 0.3 (Security hardening)**: the five Medium-severity findings deferred above are the highest-value next step now that the codebase has a genuinely green, multi-runtime-verified baseline to harden. Sprint 0.6 (CI) is the other strong candidate — standing up CI now would let it immediately start protecting the 187-test baseline this sprint just established, before any further work has a chance to regress it silently.
