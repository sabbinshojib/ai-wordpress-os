# AI WordPress OS — Enterprise Readiness Audit

**Audit date:** 2026-09-06
**Baseline commit:** `84d3c97bcd33d4d237a6e21593b6cecddbe36403` ("baseline: AI WordPress OS initial build")
**Branch:** `main` (working tree clean, single commit, no history to compare against)
**Method:** Static code review of the complete repository (no line skipped in `src/`) plus dynamic execution of every test entry point the repository itself defines, under multiple real PHP runtimes. No source file was modified to produce these results.

---

## 1. Executive summary

AI WordPress OS is a well-architected Phase-1 skeleton: a service container, a permission engine with five risk levels, an approval queue, an MCP/JSON-RPC server, a REST API, a tool/ability catalog, structured audit logging, and a hand-rolled test framework with 128 tests. The architecture is coherent and the security *intent* (never let the AI escalate its own privileges, always redact secrets, always gate destructive actions behind human approval) is visible and mostly followed in the code, not just the docs.

However, the plugin is **not production-ready**. Verification against real PHP runtimes (not just the bundled test shim) surfaced:

- **One root-cause defect that breaks every file-read tool on Windows-hosted WordPress** (`PathGuard` rejects its own internally-rebuilt absolute paths whenever the guarded root contains a drive letter).
- **One root-cause defect that breaks the direct REST tool-execution endpoint for every single registered tool** (`sanitize_key()` strips the dots that every tool name requires, so no REST-submitted tool name can ever match the registry).
- **The declared PHPUnit test suite (`phpunit.xml.dist`) cannot run at all** — `tests/bootstrap.php` never loads `tests/TestCase.php` and there is no autoload mapping for the `AIOS\Tests\` namespace, so real PHPUnit fails immediately with `Class "AIOS\Tests\TestCase" not found`. The only test entry point that actually works is the bundled custom runner (`tests/run.php`), and even that only reaches 128/128 green when the right optional PHP extensions (`mbstring`, `openssl`) happen to be loaded — which the plugin never verifies at runtime.
- **No CI, no static analysis, no coding-standards enforcement, no dependency audit** exist anywhere in the repository — the four gates a plugin needs before "enterprise" is a fair label are all absent, not merely unconfigured.
- **Multisite network-activation silently only provisions the current site**; every other site in the network is left without its plugin tables until it is used, at which point tool calls will hit missing-table SQL errors.
- The rate limiter and the approval queue have two different concurrency postures: approvals use an atomic conditional `UPDATE ... WHERE status = 'pending'` (correct), while the rate limiter uses a non-atomic transient read-increment-write (an acknowledged, but real, limit-bypass risk under concurrent bursts).

None of this reflects malicious code or an attempt to fake test results — the codebase is honest about its own limits (comments call out the exact tradeoffs made) and the acceptance script passes 13/13 real, meaningful checks end-to-end. The defects found are the kind that a first real-world deployment (a Windows/Local dev box, a multisite network, or a client hitting `/tools/execute` directly) would have surfaced immediately.

## 2. Current maturity score

| Dimension | Score (0–5) | Notes |
|---|---|---|
| Architecture & separation of concerns | 4/5 | Container, providers, single execution pipeline, ability/tool split — clean. |
| Security design intent | 4/5 | Levels, approval gate, escalation blocklist, redaction, structured errors are all present and mostly correct. |
| Security implementation correctness | 2/5 | Windows path bug, HTTPS-bypass trusts client `Host` header, non-atomic rate limiter. |
| Test infrastructure | 2/5 | One functional runner; the declared PHPUnit entry point is broken; no REST/MCP-over-HTTP, multisite, or migration-upgrade tests exist. |
| Database layer | 3/5 | Parameterized queries throughout, idempotent forward migration; no rollback path, no multisite loop, audit log has no tamper-evidence. |
| REST/MCP contract correctness | 2/5 | MCP surface is solid; the REST direct-execute surface is completely broken by a sanitize-callback bug. |
| WordPress lifecycle correctness | 3/5 | Activation/deactivation/uninstall are careful about data retention but ignore `$network_wide` entirely. |
| Admin UX | 4/5 | React console has real loading/error/retry states, nonce-aware writes, no obviously dead controls found. |
| Observability | 2/5 | Structured audit log + execution stats exist; no request/correlation IDs, no tamper-evidence, no health/metrics export. |
| Release engineering | 1/5 | No CI, no lint/static-analysis config, no lockfiles, no packaging script. |

**Overall: Phase-1 architecture is sound; the implementation is pre-production.** Treat this as a strong foundation that needs one focused stabilization pass (Sprint 0.1–0.3 in the roadmap) before any Phase 2 feature work.

## 3. Verified functionality (confirmed working by direct execution)

- Plugin bootstraps headlessly under the bundled WP shim (`tests/bootstrap.php` → `ai-wordpress-os.php` → `Plugin::boot()`), no fatals.
- Service container resolves bindings, factories, singletons, and reflection-based auto-wiring correctly (`ContainerTest`: 6/6 pass).
- `PermissionEngine` ceiling math (mode × capability × grant × key ceiling, min-wins) is correct in every tested combination (`PermissionEngineTest`: 13/13 pass).
- `Sanitize` secret redaction (API keys, bearer tokens, WP salts, `DB_PASSWORD`) matches its patterns correctly (`SanitizeTest`: 8/8 pass).
- `Settings` defaulting/sanitizing/clamping round-trips correctly (`SettingsTest`: 5/5 pass).
- `Validator` JSON-Schema-subset engine (type, enum, numeric bounds, string length/pattern, array items, nested objects, `anyOf`) is correct once `mbstring` is loaded (`ValidatorTest`: 12/12 pass with a real extension set).
- `Crypto` sodium/OpenSSL versioned envelope round-trips, detects tampering, and rejects the wrong key, once `openssl`/`sodium` are loaded (`CryptoTest`: 6/6 pass).
- `ApiKeyManager`/`ApiKeyRepository` issue → authenticate → rotate → revoke → expire lifecycle is correct, hashes are never exposed via `list()` (`ApiKeyTest`: 9/9 pass with real extensions).
- `Migrator`/`Database`/schema migration create all four tables idempotently (`DatabaseTest` migration tests: 3/3 pass).
- Full MCP JSON-RPC surface — `initialize` version negotiation, `ping`, `tools/list`, `tools/call` (success/validation-error/approval/unknown-tool/permission-denied), `resources/*`, `prompts/*`, untrusted-content wrapping — is correct end-to-end (`McpServerTest`: 16/17 pass; the 1 failure is the Windows path bug, not MCP logic).
- The full approval → execute pipeline (validate → permission → escalation blocklist → rate limit → approval-or-execute → audit → execution stats) is correct end-to-end, including bulk "approve safe" and audit redaction (`ToolExecutorTest`: 25/26 pass; the 1 failure is the same Windows path bug).
- **The 13-step acceptance script (`tests/acceptance.php`) passes 13/13** against a realistic extension set: MCP handshake, tool discovery (31 tools), site inspection, structured site map, content creation, destructive-action approval gating, approval execution, audit trail completeness, path-traversal blocking through the MCP surface, secret non-exposure, and unauthenticated denial. This is the strongest evidence the core security model works as designed.

## 4. Unverified functionality (present in code, not exercised by any test or runtime check performed)

- Real MySQL/MariaDB behavior of the four `dbDelta()` schema definitions (only a SQLite/array-based test double is exercised).
- Real WordPress REST transport (routing, `permission_callback` wiring, application-password auth, cookie+nonce auth) — the shim never boots an actual `WP_REST_Server`.
- Real `WP_Filesystem` credentials/FTP fallback path in `FileReader::readViaWpFilesystem()`.
- Real WP-CLI invocation (`wp ai-os status|migrate|tools`) — `WP_CLI`/`WP_CLI_Command` are referenced but never instantiated by any test.
- The React admin console against a live REST backend (build artifact was read, not executed in a browser).
- Multisite behavior of any kind (activation, uninstall, context, rate limiting) — the shim is single-site.
- Cron execution of `ai_os_daily_maintenance` (retention purge) — `wp_schedule_event`/`wp_next_scheduled` are shimmed, never actually fired.
- Every third-party filter extension point (`ai_os_register_tool`, `ai_os_register_ability`, `ai_os_container_build`, `ai_os_protected_files`, `ai_os_requires_approval`, `ai_os_user_level_grants`) — declared and referenced consistently, but no test registers anything through them.

## 5. Broken functionality (confirmed by direct evidence)

1. **File-reading is broken on any Windows-hosted WordPress install.** `PathGuard::isInsideRoot()` re-derives an absolute path via `toAbsolute()` even when it is already given one; `toAbsolute()` unconditionally rejects any path beginning with a drive letter (`/^[a-z]:/i`). Since `ABSPATH` itself is a drive-letter path on Windows, the guard's own internal round-trip trips its own defense, and every `resolveRead()` call throws `E_INVALID` regardless of legitimacy. Confirmed by 6 failing `PathGuardTest` cases and 1 failing `ToolExecutorTest::test_theme_read_file_works_for_real_files` under a fully-extended PHP 8.2/8.3 environment (i.e., not an extension-availability artifact — a real logic bug). Affects `theme.read_file`, `theme.list_files` (indirectly, via any real-path resolution), and the whole `FileReader` class (`forTheme()`, `forRoot()`).
2. **`POST /wp-json/ai-os/v1/tools/execute` cannot execute any real tool.** `ToolsController::register()` sets `'sanitize_callback' => 'sanitize_key'` on the `tool` argument. WordPress's `sanitize_key()` lowercases and strips every character outside `[a-z0-9_-]`, which deletes the `.` that `Tool::make()`'s own name-validation regex (`/^[a-z][a-z0-9_]*(\.[a-z0-9_]+)+$/i`) *requires* every tool name to contain. A request for `tool=content.get_post` arrives at the executor as `contentget_post`, which matches nothing in `ToolRegistry`, and the call always fails with `tool.unknown`. This is a 100%-reproducible, total failure of a documented primary integration surface (`docs/API.md` describes `/tools/execute` as a direct execution endpoint). The MCP surface (`tools/call`) is unaffected — it takes the tool name from the JSON-RPC body directly, with no `sanitize_key()` in the path.
3. **The declared `phpunit.xml.dist` entry point is completely non-functional.** Running real PHPUnit 10 against it fails immediately: `Class "AIOS\Tests\TestCase" not found`, because `tests/bootstrap.php` (the configured bootstrap) never requires `tests/TestCase.php`, and `composer.json`'s PSR-4 map only covers `AIOS\\` → `src/`, not the `AIOS\Tests\` namespace used by every test class. `composer.json`'s own `test` script wisely calls the bundled `tests/run.php` instead of `phpunit`, which masks this from anyone who only runs `composer test` — but the moment a human or a CI job runs `vendor/bin/phpunit` (the tool the repo explicitly declares a dev dependency on and ships a config for), it fails outright.

## 6. Test results

### 6.1 Runtimes exercised

| Runtime | Source | Status |
|---|---|---|
| PHP 8.2.33 NTS | Portable ZIP from windows.php.net, extracted under `%TEMP%\aios-audit-php\8.2` | Working |
| PHP 8.3.33 NTS | Portable ZIP from windows.php.net, extracted under `%TEMP%\aios-audit-php\8.3` | Working |
| PHP 8.4.25 NTS | Portable ZIP from windows.php.net, extracted under `%TEMP%\aios-audit-php\8.4` | **Unusable on this host** — linked against a VC++ runtime (vs17 / VCRUNTIME140 14.44) newer than the one installed on this machine (14.22). No system-wide runtime installs were performed per the audit policy, so PHP 8.4 could not be exercised. This is a host limitation, not a plugin defect, but it is worth noting that the plugin's `composer.json` allows PHP 8.4 (`>=8.2`) and nothing in the code was reviewed for 8.4-specific deprecations. |
| System PHP / Composer | — | **Not installed on this machine at all.** Confirms the plugin cannot assume any PHP tooling pre-exists on a fresh Windows dev box. |

No system-wide installation was performed. All PHP binaries live under `%TEMP%\aios-audit-php\` only.

### 6.2 Exact results per run

| # | Command | Environment | Tests | Failures | Exit code |
|---|---|---|---|---|---|
| 1 | `php -n tests/run.php` | PHP 8.2, **no** `php.ini` (bare CLI defaults) | 128 | **54** | 1 |
| 2 | `php -c php-wp.ini tests/run.php` | PHP 8.2 + `mbstring, openssl, curl, sodium, fileinfo, gd, exif, intl, sqlite3, pdo_sqlite, zip, mysqli, pdo_mysql, opcache` | 128 | **7** | 1 |
| 3 | `php -c php-wp.ini tests/run.php` | PHP 8.3, same extension set | 128 | **7** | 0* |
| 4 | `php -c php-wp.ini tests/acceptance.php` | PHP 8.2, same extension set | 13 checks | **0** | 0 |
| 5 | `phpunit-10.phar -c phpunit.xml.dist` | PHP 8.2, same extension set | 0 (fatal before any test ran) | — | 255 |

\* PHP 8.3's exit code was masked by `tail -20` in the shell pipeline during capture; the printed summary line (`128 tests, 7 failures`) is authoritative and identical to run #2.

### 6.3 Root-cause analysis (not a symptom list)

The 54 failures in run #1 collapse to exactly **one root cause**: three PHP userland functions the plugin calls unconditionally (`openssl_encrypt`/`openssl_decrypt` in `Crypto`, `mb_strlen` in `Validator`/`Sanitize`, `mb_substr` in `ApiKeyManager`/repositories/`Sanitize`) are not part of PHP core — they require the `openssl` and `mbstring` extensions, which are not loaded by a bare `php -n` invocation. **The plugin never checks for these extensions at activation or at runtime** (see §7). Once those two extensions are present (run #2/#3), 47 of the 54 failures disappear.

The remaining **7 failures are one root cause**, described in full in §5.1 above: the Windows-drive-letter bug in `PathGuard::isInsideRoot()` → `toAbsolute()`. It manifests as 6 direct `PathGuardTest` failures plus 1 downstream `ToolExecutorTest` failure — 7 symptoms, 1 bug.

The PHPUnit fatal (run #5) is its own, third root cause, documented in §5.3.

**Total distinct root causes discovered via testing: 3** (missing-extension guard, Windows path bug, broken PHPUnit bootstrap). Everything else in this report was found by static review, not by test failures.

### 6.4 `composer.json` script coverage

`composer.json` declares `test` (→ `tests/run.php`), `acceptance` (→ `tests/acceptance.php`), and `lint` (→ `php -l` over every file). All three were run. `php -l` reported **zero syntax errors across all 90 PHP files** in `ai-wordpress-os.php`, `uninstall.php`, `src/**`, and `tests/**`, on PHP 8.2.

## 7. Environmental assumptions found in code but never checked at runtime

- **`mbstring`** — used in `Validator::checkString()`, `Sanitize::like()`, `ApiKeyManager::issue()`, `ApiKeyRepository`, `ApprovalRepository`, `AuditLogRepository`, `AuditLogger`, `ToolExecutionRepository`. Not a WordPress-bundled guarantee on every host (rare but real on stripped-down/embedded PHP builds); not checked by the bootstrap's `ai_wp_os_environment_failed()`.
- **`openssl`** (or `sodium`) — `Crypto` throws a raw `RuntimeException` (not a `StructuredError`) if `openssl_encrypt()` returns `false` and sodium is unavailable; on a host with neither extension, `Crypto::encrypt()` fatals instead of degrading gracefully.
- **`json`** — used everywhere (`wp_json_encode`, `json_decode`); virtually universal in modern PHP but still not explicitly asserted.
- **`intl`, `zip`, `gd`, `exif`, `fileinfo`, `sqlite3`/`pdo_sqlite`, `mysqli`/`pdo_mysql`** — not referenced directly by plugin source (`MediaTools` relies on WordPress core's own media-handling functions, which themselves may depend on `gd`/`exif`/`fileinfo`, but the plugin does not check for them before calling into `wp_handle_sideload()`/attachment APIs).
- **`ABSPATH` being a POSIX-style, non-drive-letter path** — implicitly assumed throughout `PathGuard`; false on every Windows host, which is the root cause of Finding #1 above.
- **A single-site `$wpdb->prefix`** — implicitly assumed throughout `Database`, `Migrator`, and `uninstall.php`; false on any multisite network with more than one site (see §10).
- **`dbDelta()` availability** — guarded correctly (`is_file($upgrade) && !function_exists('dbDelta')` before `require_once`), a genuinely good defensive pattern, one of the few explicit environment checks in the codebase.
- **The bootstrap's `ai_wp_os_environment_failed()`** only checks `PHP_VERSION >= 8.2.0` and `WP core version >= 6.9`. It does not check for any PHP extension, does not check for `wpdb`/database driver availability, and does not check write-access to any directory (irrelevant today since Phase 1 is read-only, but will matter the moment a write engine ships).

## 8. Architectural findings

- **Container** (`src/Core/Container.php`): minimal, correct PSR-11-style singleton/factory/reflection resolution. One minor inconsistency: `has($id)` returns `true` for any `class_exists($id)` even when the class is abstract/uninstantiable, while `get($id)` would later throw for the same id — a caller that gates on `has()` before calling `get()` can still hit an exception.
- **Service provider boot order** (`Plugin::boot()` → `CoreServiceProvider::register()` immediately, `boot()` deferred to `plugins_loaded` priority 5): correct WordPress idiom, and boot failures are caught and turned into an admin notice rather than a fatal (`failSoft()`) — a good resilience pattern, verified by code reading (no test exercises the failure path itself).
- **Single execution pipeline** (`ToolExecutor::run()`): genuinely a single path for MCP, REST, and approval-triggered execution, as the class's own docblock claims. This is a real architectural strength — permission, escalation, rate-limit, approval, and audit logic cannot drift between surfaces because there is only one implementation.
- **Ability/Tool duplication**: every tool is backed by exactly one ability of the same name (`Tool::abilityName()` defaults to the tool name); no duplicated business logic was found across the ten catalog providers reviewed.
- **Escalation blocklist is currently unreachable in practice.** `ToolExecutor::ESCALATION_BLOCKLIST` targets `users.create`, `users.update`, `settings.update`, `options.update` — none of which exist as registered tools in Phase 1 (`UserTools` registers only `users.list`/`users.get`, both read-only). This is intentional forward-hardening per the code's own comment, not a bug, but it means the blocklist has **zero test coverage against a live dangerous tool** today; the moment a future tool is added under one of those prefixes, its blocklisting behavior will be untested until someone writes that test.
- **No transaction/rollback/snapshot engine exists yet** (by design — Phase 1 is read-only plus safe/approval-gated writes). This is a roadmap item, not a current defect; flagged fully in §22 of the underlying review and reflected in the roadmap document.

## 9. WordPress-specific findings

- **Activation** (`Activator::activate()`) accepts a `$network_wide` parameter and never uses it. On a multisite network activation, WordPress only invokes the activation callback in the context of the site that initiated the action (or, for genuinely per-site provisioning, the developer must explicitly loop `get_sites()` and `switch_to_blog()`); this plugin does neither. **Confirmed gap, not just unverified**: the parameter is accepted and silently discarded.
- **Uninstall** (`uninstall.php`) drops tables using the bare `$wpdb->prefix` (i.e., whichever site the deletion request runs in) with no `switch_to_blog()` loop over `get_sites()`. On a multisite network with "remove data" opted in, every site's tables except the one that performed the deletion are orphaned permanently. The `is_multisite()` branch present in the file only cleans up sitemeta-level rate-limiter transients — it does not touch per-site tables/options.
- **Deactivation** (`Deactivator::deactivate()`) is comparatively low-risk: it only clears one cron hook and one transient, both idempotent and cheap to redo per-site, so the missing multisite loop here is a much smaller issue than the two above.
- **Cron scheduling is idempotent by construction**: both `Activator::activate()` and `CoreServiceProvider::boot()` schedule `ai_os_daily_maintenance`, but both correctly guard with `wp_next_scheduled()` first, so no duplicate cron entries are created despite the double registration.
- **`WP_DEBUG`-gated `error_log()` calls** (in `Plugin::failSoft()`, `DebugLog::log()`, `AuditLogger::log()`'s catch block) are consistently gated and consistently redact context via `Sanitize::redact()` before logging — no raw-secret-to-log-file path was found.
- **Capability model**: `PermissionEngine::CAPABILITY_CEILINGS` maps `manage_options → 4`, `edit_others_posts → 2`, `edit_pages/edit_posts/upload_files → 1`; `PermissionEngine::canUse()` requires `ai_os_use`, `manage_options`, or `edit_posts`. No custom role/capability is registered anywhere (no `add_role`/`add_cap` calls found), meaning `ai_os_use` and `ai_os_approve` — both referenced in permission checks (`AbstractController::canApprove()`) — are **never granted to any role by this plugin**. Only users who already hold `manage_options` (or, for `canApprove`, that same capability) can ever pass those specific gates in a fresh install; the capability names exist purely as extension points for a site owner or another plugin to wire up. This is worth documenting explicitly since it is easy to mistake for a bug — it is really a missing "map `ai_os_use`/`ai_os_approve` to a real role" feature.

## 10. MCP findings

- Full JSON-RPC 2.0 envelope validation is correct: rejects non-`"2.0"` versions, non-string/empty methods, non-string/int ids (floats/bools/objects correctly rejected per spec), non-array params.
- `initialize` negotiates protocol version against `AI_WP_OS_SUPPORTED_MCP_VERSIONS`, defaults to latest when the client's version isn't recognized — correct per the stateless-HTTP MCP profile.
- Batch requests are supported and bounded to 32 sub-requests (`array_slice($batch, 0, 32)`), a sound DoS guard on batch size.
- **Batch requests dilute the outer per-HTTP-request rate limiter.** `RestTransport::rateLimit()` consumes exactly one 'mcp' counter tick per POST regardless of how many JSON-RPC calls are packed into the batch array, so up to 32 tool-call attempts can be submitted per rate-limited HTTP request. This is **not a full bypass**: `ToolExecutor::run()` independently rate-limits every individual tool execution against the `'executions'` counter, so the meaningful throttle (actual WordPress mutations) still applies per-call. The outer 'mcp' counter is best understood as a connection-level throttle that batching can inflate by up to 32×, not as the plugin's real safety net.
- Tool-level errors are correctly returned as successful JSON-RPC responses with `isError: true` in the result payload (not as JSON-RPC protocol errors) — this matches the MCP spec's distinction between "the call itself failed" (protocol error) and "the tool ran and reported failure" (result error), and is exercised correctly by `McpServerTest`.
- Approval-required results are returned as a successful response whose `structuredContent.status` is `approval_required` and whose text content explicitly states the action was **not** executed — correct and unambiguous for a client model to parse.
- `resources/list` + `resources/get` expose exactly two real, working resources (`aios://site/context`, `aios://security/permissions`); no placeholder/fake resources were found.
- No MCP-level idempotency key or replay protection exists for `tools/call` — a client that retries a timed-out request (e.g., `content.create_post`) has no way to signal "this is a retry of request X" and will create a duplicate post. This is an accepted Phase-1 gap (idempotency is explicitly a Phase-2 roadmap item, see §22), not a regression, but it is a real correctness gap for any AI client that retries on timeout.

## 11. REST findings

- Every controller extends `AbstractController`, which centralizes `canRead()` (baseline AI-OS access), `canManage()` (`manage_options`), `canApprove()` (`manage_options` or `ai_os_approve`), and `verifyNonce()` (defense-in-depth on top of WordPress core's own cookie-auth nonce enforcement).
- **`ToolsController::execute()` (`POST /tools/execute`) is the single most powerful REST endpoint in the plugin — it can trigger any registered tool, including content creation — and it is the only mutating endpoint that does not call `verifyNonce()`.** Every other mutating endpoint (`ApprovalsController::approve/reject/approveSafe`, `KeysController::create/revoke`, `SettingsController::update`) explicitly re-verifies the nonce for defense-in-depth even though WordPress core already enforces `X-WP-Nonce` for cookie-authenticated REST requests. The absence here is an inconsistency with the codebase's own established pattern, not (by itself) a full CSRF bypass, since core's `rest_cookie_check_errors()` still runs first.
- **Confirmed, separate from the above: `/tools/execute`'s `tool` parameter uses `sanitize_key()`, which breaks every real tool name** (§5, Finding #2). This is the dominant REST finding — the endpoint is non-functional, not merely under-protected.
- `KeysController::create()` correctly caps an issued key's `max_level` to the **target user's own WordPress capability ceiling** (not the acting admin's), preventing an admin from minting a key more powerful than the account it's bound to — a well-thought-out invariant.
- `LogsController` requires `canApprove()` (not just `canRead()`) to view the audit feed — a deliberately stricter bar for a screen that can contain redacted-but-still-sensitive operational detail; consistent with the plugin's least-privilege posture.
- All list/filter endpoints (`/logs`, `/approvals`) bound `limit`/`per_page` server-side (`max(1, min(200, $limit))` and similar) regardless of what the client requests — no unbounded-query DoS vector found in the REST layer.
- No REST endpoint returns raw stack traces or internal exception messages; all error paths funnel through `StructuredError`.

## 12. Database findings

- Every repository (`ApiKeyRepository`, `ApprovalRepository`, `AuditLogRepository`, `ToolExecutionRepository`) builds SQL exclusively through `Database::prepare()` (a `$wpdb->prepare()` facade) for every interpolated value; table names are internal constants, never user input. **No SQL-injection vector was found in the four repositories reviewed.**
- `ApprovalRepository::claimPending()` uses a correct atomic pattern — `UPDATE ... WHERE id = %d AND status = 'pending'` and checks the affected-row count — so two simultaneous approve/reject requests for the same approval cannot both "win." This is the right way to do it and stands in contrast to the rate limiter (§13).
- The core-tables migration (`Migration_202501010001_CoreTables`) uses `dbDelta()` with `CREATE TABLE IF NOT EXISTS`-style idempotency, correct `PRIMARY KEY  (id)` double-space formatting `dbDelta()` requires, and appropriate indexes (`occurred_at`, `user_id`, `tool`, `status`, `risk` on the audit table; `tool_time`, `success` on executions; `status`, `risk_status` on approvals; `key_hash`, `user_id` on api_keys). IPs are stored packed (`VARBINARY(16)` via `inet_pton()`), which is a genuinely good practice (IPv4/IPv6-agnostic, not trivially human-grep-able in a DB dump).
- **No `down()`/rollback method exists on `MigrationInterface`** — forward-only by design (documented in the interface's own docblock), acceptable for Phase 1 but a real gap the moment a schema needs to change destructively.
- **No optimistic-locking/version column exists on any table** — acceptable today since nothing currently supports concurrent multi-writer edits of the same row outside the already-atomic approval claim.
- **The audit log has no tamper-evidence** (no hash chain, no append-only DB-level constraint, no write-once storage). An administrator (or an attacker with direct DB access) can edit or delete `wp_ai_os_audit_logs` rows without any built-in detection. For a plugin whose core enterprise value proposition is auditability, this is worth prioritizing before claiming "audit trail" as a compliance-relevant feature.
- Minor scalability note: `ApiKeyRepository::revoke()` fetches up to 500 rows via `all(500)` and filters in PHP to find one key by id, instead of a direct `WHERE id = %d` lookup — bounded (capped at 500) so not a DoS vector, but an unnecessary round-trip that will matter once a site accumulates hundreds of keys.
- **Multisite**: no repository, the `Database` class, or `Migrator` is multisite-aware; `Database::table()` always uses the *current* site's `$wpdb->prefix`. Combined with the Activation/Uninstall gaps in §9, this means the data layer itself is single-site by construction — every other finding in this section should be read as "true for the current site only" on a network install.

## 13. Security findings (classified)

### Critical
- **C-1. Windows path-confinement bypass-by-self-rejection** (`src/Security/PathGuard.php`, `isInsideRoot()`/`toAbsolute()`). Not an escalation risk (it fails closed — every read is rejected, not permitted) but it is a Critical **availability/correctness** defect: it makes an entire security-critical feature (all file inspection) totally unusable on the exact class of host (Windows dev boxes, Local by Flywheel, XAMPP/WAMP-on-Windows, IIS-hosted WordPress) where a plugin author is most likely to be developing and testing it. Classified Critical because it is 100% reproducible, has zero workaround short of a code change, and blocks a documented core feature (`docs/TOOLS.md` lists `theme.read_file`).
- **C-2. `/tools/execute` REST endpoint is completely non-functional for every real tool** (`src/Rest/Controllers/ToolsController.php`, `sanitize_key` callback). Classified Critical because it silently and totally defeats a documented, primary integration surface — any client integration built against `docs/API.md`'s description of direct REST tool execution will fail 100% of the time, with an error (`tool.unknown`) that gives no hint the *sanitization*, not the tool name, is at fault.

### High
- **H-1. `phpunit.xml.dist` cannot run** (`tests/bootstrap.php` / `composer.json` autoload map). Classified High rather than Critical because the bundled custom runner (`tests/run.php`) does work and is what `composer test` actually calls — but any engineer, CI system, or IDE integration that runs the standard `vendor/bin/phpunit` (which the repo's own `phpunit.xml.dist` and `composer.json require-dev` both imply is supported) gets an immediate, confusing fatal with no tests run at all. This blocks adopting any standard PHP CI tooling (most WordPress-plugin CI templates assume `phpunit.xml.dist` works) until fixed.
- **H-2. Missing optional-extension runtime guard** (`ai_wp_os_environment_failed()`, `src/Support/Crypto.php`). `mbstring`/`openssl` are hard dependencies of core security code (`Crypto`, `Validator`, `Sanitize`, `ApiKeyManager`) but are never checked at activation. On a host missing them, the plugin does not fail cleanly at activation with a clear admin notice — it fails unpredictably, mid-request, the first time an affected code path runs (a fatal `Error: Call to undefined function`, confirmed directly by the bare-PHP test run). Classified High because the failure mode is an uncaught fatal on a security-critical path, not a graceful degradation.
- **H-3. Multisite network-activation/uninstall data-provisioning gap** (§9). Classified High rather than Critical because it does not expose data across sites or break single-site installs — it produces missing-table SQL errors on non-primary sites of a network install, a serious functional break but not a cross-tenant data leak.

### Medium
- **M-1. Non-atomic rate limiter under concurrency** (`src/Security/RateLimiter.php::allow()`). The read-increment-write on a transient is not atomic; parallel requests within the same window can each observe a count below the limit before any of them persists their increment, allowing the limiter to be exceeded under real concurrency (e.g., an AI client firing several tool calls in parallel connections). The code's own comment acknowledges this ("acceptable here because limits are protective, not billing-precise") — the audit's view is that this framing is fair for abuse-*deterrence* but should not be relied on as a hard *ceiling* in threat modeling (e.g., for cost control against a runaway or malicious client issuing bursts).
- **M-2. HTTPS-requirement bypass trusts client-controlled `Host` header** (`src/Mcp/Transports/RestTransport.php::isLocalDevelopment()`). The `require_https` setting is bypassed whenever `HTTP_HOST` contains `localhost`, `127.0.0.1`, `.local`, or `.test`. On a misconfigured reverse proxy or a server with a permissive default virtual host, `Host` can be attacker-influenced, which would let a client force the "local development" exemption and downgrade a production endpoint to plaintext HTTP. Real-world exploitability depends entirely on the hosting/proxy configuration, hence Medium rather than High.
- **M-3. Missing defense-in-depth nonce check on the most powerful REST endpoint** (`ToolsController::execute()`, §11). Low direct risk today because WordPress core's cookie-auth nonce check already runs first, but it breaks the codebase's own consistent pattern on exactly the endpoint where a lapse matters most.
- **M-4. Audit log has no tamper-evidence** (§12). Relevant to any compliance claim built on "every action is audited."
- **M-5. Encryption key durability tied to `AUTH_KEY`/`AUTH_SALT` rotation** (`src/Support/Crypto.php::deriveKeyFromEnvironment()`). When no dedicated key is configured (the default), the encryption key is derived from the site's `AUTH_KEY` (falling back to `AUTH_SALT`, then a hardcoded development string). If an administrator rotates WordPress salts — a normal, recommended security practice — anything previously encrypted with `Crypto` becomes permanently undecryptable, silently. No decrypt-failure detection or key-versioning-across-rotation exists. (Note: as of this audit, no call site in the repository is actually persisting `Crypto`-encrypted data yet — API keys are hashed, not encrypted — so today this is a latent risk for the first Phase-2 feature that does use `Crypto` for at-rest storage, not a currently-exploitable one.)

### Low
- **L-1. `Container::has()`/`get()` inconsistency for uninstantiable classes** (§8) — an internal-only DX papercut, not attacker-reachable.
- **L-2. `ApiKeyRepository::revoke()` inefficient lookup** (§12) — a performance nit, not a security issue, listed here only because it lives in the security-adjacent key-lifecycle path.
- **L-3. Escalation blocklist has zero live test coverage** (§8) — a coverage gap around a control that is currently unreachable by design, not a present vulnerability.
- **L-4. `PathGuard::ALLOWED_EXTENSIONS` includes `php`** — intentional and documented ("PHP read is allowed only for theme/plugin inspection tools and is risk-gated by the tool itself; execution is never automatic") — flagged here only so the roadmap explicitly re-confirms this is a deliberate, reviewed tradeoff rather than an oversight before any Phase-2 write capability is added.

## 14. UI findings

- The React admin console (`build/dashboard-src/src/app.jsx`, compiled to `assets/js/admin-dashboard.js` via esbuild) implements a consistent `useApi()` hook with real `loading`/`error`/`reload` states on every screen reviewed (Status, Context, Tools, Approvals, Logs, Keys, Settings) — no dead buttons or fake-success placeholders were found in the source.
- Approval actions in the UI surface the real execution outcome ("Approved and executed. Succeeded." vs. "Execution failed: …") rather than assuming success — a good honesty-in-UI pattern.
- The shipped `assets/js/admin-dashboard.js` is a minified esbuild IIFE bundle with no source map; verifying it is byte-for-byte the product of the current `app.jsx` requires running the `build/dashboard-src` esbuild pipeline, which requires `npm install` (blocked by this audit's no-install policy). Given this is a single-commit baseline, the two are presumed in sync, but there is **no build-reproducibility check anywhere in the repo** to catch future drift (no CI step rebuilds and diffs the asset).
- No `package-lock.json` (or any lockfile) exists for `build/dashboard-src`, so the exact `esbuild`/`react`/`react-dom` versions used to produce the shipped bundle are not pinned or reproducible from a clean checkout.
- Accessibility/i18n: all admin-rendered PHP strings (`Onboarding.php`, `AdminPages.php`) correctly use `__()`/`esc_html__()`/`esc_html_e()`; the React app was not exhaustively checked for ARIA attributes or keyboard-navigation completeness (out of scope for a static read without a browser).

## 15. Release findings

- **No CI configuration exists** — no `.github/workflows/`, no other CI provider config found anywhere in the repository.
- **No static analysis configuration exists** — no `phpstan.neon*`, no `phpcs.xml*`, no `.phpcs.xml.dist`.
- **No dependency-audit tooling is wired up** — `composer.json` has no `audit` script; there is no `composer.lock` to audit against in the first place (no `vendor/` was ever installed in this repository, confirmed: `vendor` does not exist).
- **No JS lockfile** (`package-lock.json`/`yarn.lock`) for `build/dashboard-src` — see §14.
- **No changelog file** was found (`readme.txt`'s standard `== Changelog ==` section was not independently verified against `README.md`/version history beyond the single baseline commit).
- `composer.json`'s three scripts (`test`, `acceptance`, `lint`) are the *only* quality gates the repository defines, and all three were run successfully in this audit (modulo the environment-dependent 7 failures documented in §6).

## 16. Production blockers (ranked)

1. `/tools/execute` REST endpoint is non-functional for every tool (C-2).
2. File-reading is non-functional on Windows-hosted WordPress (C-1).
3. No runtime guard for `mbstring`/`openssl`, causing uncaught fatals on affected hosts (H-2).
4. `phpunit.xml.dist` cannot run, blocking standard CI adoption (H-1).
5. Multisite network-activation does not provision non-primary sites; multisite uninstall does not clean them up (H-3).
6. No CI/static-analysis/dependency-audit exists at all (§15) — nothing currently prevents a regression of any of the above from reaching a tagged release.
7. Rate limiter can be exceeded under real concurrency (M-1) — relevant before any cost-sensitive AI-usage billing/quota claim is made.
8. `require_https` can be bypassed via a spoofed `Host` header on misconfigured hosts (M-2).
9. Audit log has no tamper-evidence, undermining any compliance claim built on it (M-4).
10. No idempotency/replay protection on MCP `tools/call` — retried requests can double-execute mutating tools.
11. Encryption key has no rotation strategy (M-5) — latent until a Phase-2 feature persists `Crypto`-encrypted data.
12. `ai_os_use`/`ai_os_approve` capabilities are referenced but never granted to any role — works today only because `manage_options` also satisfies every gate, but is a trap for the first non-admin-approver workflow.

## 17. Recommended remediation order

This intentionally mirrors the roadmap's Sprint 0.1–0.3 sequencing (see `docs/roadmap/ENTERPRISE-ROADMAP.md`):

1. Fix the `PathGuard` Windows bug (C-1) — small, isolated, unblocks the whole file-reading feature and 7 of the current test failures.
2. Fix the `/tools/execute` sanitize-callback bug (C-2) — one-line-scoped fix, unblocks a primary documented integration surface.
3. Fix the PHPUnit bootstrap so `vendor/bin/phpunit -c phpunit.xml.dist` actually runs (H-1) — prerequisite for any CI adoption.
4. Add an explicit `mbstring`/`openssl` activation-time guard with a clear admin notice, mirroring the existing PHP/WP-version guard pattern (H-2).
5. Decide and implement a multisite strategy for activation/uninstall — either genuinely loop every site, or explicitly document and enforce "single-site only" until Phase 2 (H-3).
6. Stand up CI (lint + the working test runner, at minimum) so points 1–5 cannot silently regress (§15/§16.6).
7. Address the Medium-severity items (rate-limiter atomicity, `Host`-header trust, nonce consistency, audit tamper-evidence, key-rotation strategy) as a hardening pass once the above is green.
8. Only then begin Phase 2 architecture (Transaction Engine, ChangeSet, Snapshot/Diff, rollback, etc.) per the roadmap.

Do not begin Phase 2 feature work before steps 1–5 are complete and covered by a passing, CI-enforced test suite — every Phase 2 feature (guarded mutations, rollback, snapshots) depends on the file-security and execution-pipeline correctness this audit found broken.
