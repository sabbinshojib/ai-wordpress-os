# AI WordPress OS — Architecture

> Phase 1 Foundation — design document. This file describes the architecture that is
> **implemented** in this release. Anything marked `Phase 2+` is designed-for but not
> implemented yet (interfaces/slots exist, no fake UI and no dead buttons), with one
> partial exception: §13's Phase 2 mutation pipeline has a real, tested, in-process
> implementation (`AIOS\Mutation\*`) as of 2026-09-07 — but no durable persistence
> layer, and it is not wired to any AI-facing tool/REST/MCP surface. See §13 for the
> exact implemented-vs-not split; never round this up to "Phase 2 complete."

## 1. Product shape

AI WordPress OS (`ai-wordpress-os`) turns a WordPress site into an **AI-operable platform**:

```
ChatGPT / Claude / Claude Code / Cursor / any MCP client
        │  JSON-RPC 2.0 over HTTP (Streamable HTTP transport)
        ▼
┌─────────────────────────────────────────────────────────┐
│  AI WordPress OS (this plugin)                          │
│  ┌────────────┐   ┌───────────────┐   ┌──────────────┐  │
│  │ MCP Server │──▶│ Tool Registry │──▶│   Abilities  │  │
│  └────────────┘   └───────┬───────┘   └──────┬───────┘  │
│  ┌────────────┐   ┌───────▼───────┐   ┌──────▼───────┐  │
│  │ REST API   │──▶│ Tool Executor │──▶│  WordPress   │  │
│  └────────────┘   └───────┬───────┘   └──────────────┘  │
│  ┌────────────┐   ┌───────▼───────┐   ┌──────────────┐  │
│  │  Admin UI  │   │  Permission   │   │    Audit     │  │
│  │ (React SPA)│   │    Engine     │   │     Log      │  │
│  └────────────┘   └───────┬───────┘   └──────────────┘  │
│  ┌────────────┐   ┌───────▼───────┐   ┌──────────────┐  │
│  │  Approvals │   │    Context    │   │  Rate        │  │
│  │   Queue    │   │    Engine     │   │  Limiter     │  │
│  └────────────┘   └───────────────┘   └──────────────┘  │
└─────────────────────────────────────────────────────────┘
        │  WordPress-native APIs only (REST, WPDB, Filesystem, Cron)
        ▼
   WordPress 6.9+ / PHP 8.2+
```

## 2. Layer map (spec §2)

| Layer | Class(es) | Responsibility |
|---|---|---|
| WordPress | — | Host. Never bypassed: all writes go through WP APIs. |
| Abilities | `Abilities\*` | Canonical capability units: name, schema, permission callback, execution callback. Single source of business logic. |
| Tool Registry | `Tools\*` | MCP-facing catalog: every tool = an ability + risk level + approval policy + JSON Schema. Dynamic; extensible via `ai_os_register_tool`. |
| MCP Layer | `Mcp\*` | JSON-RPC 2.0 server, stateless Streamable HTTP transport, tool/resource/prompt exposure. |
| Permission Engine | `Security\PermissionEngine` | 5 levels (0 READ … 4 DEPLOY), 3 modes (safe/balanced/advanced), per-user grants, capability mapping. |
| Context Engine | `Context\*` | Structured site knowledge tree (theme, plugins, CPTs, taxonomies, menus, REST routes, shortcodes). Cached via transients. |
| Agent Engine | `Phase 2+` | `Agents\AgentInterface` slot exists; no implementation shipped. |
| Task Engine | `Phase 2+` | `tasks` table arrives with Phase 2 migration. |
| Testing Engine | `Phase 2+` | — |
| Rollback Engine | `Phase 2+` | — (snapshots table arrives with Phase 2 migration; no rollback UI shipped now). |
| Integration Adapters | `Phase 3+` | `Integrations\IntegrationInterface` slot exists; adapters load only when target plugin present. |

**Phase 1 ships the foundation vertical slice, fully functional:** MCP ↔ tools ↔ abilities ↔ permission ↔ approval ↔ audit, plus site/content/media inspection & safe writes.

## 3. Directory tree (Phase 1)

```
ai-wordpress-os/
├── ai-wordpress-os.php          # Bootstrap: constants, PSR-4 autoloader, activation, kernel boot
├── uninstall.php                # Data cleanup (honors "retain data" setting)
├── readme.txt / README.md / LICENSE
├── composer.json                # Dev tooling only (not required at runtime)
├── phpunit.xml.dist
├── docs/                        # ARCHITECTURE, INSTALLATION, MCP-SETUP, SECURITY, API, TOOLS, TROUBLESHOOTING
├── assets/
│   ├── css/admin.css            # Developer-console dark theme
│   └── js/admin-dashboard.js    # Built React app (source in build/dashboard-src/)
├── build/dashboard-src/         # React source + esbuild config (dev pipeline)
├── languages/
├── src/
│   ├── Core/
│   │   ├── Plugin.php           # Kernel: boots container, providers, hooks
│   │   ├── Container.php        # PSR-11 style DI container (singleton + factory binding)
│   │   ├── ServiceProviderInterface.php
│   │   ├── CoreServiceProvider.php
│   │   ├── Activator.php        # Environment checks + migrations + defaults + onboarding flag
│   │   ├── Deactivator.php      # Cron cleanup
│   │   └── ModuleInterface.php  # Contract for optional modules
│   ├── Support/
│   │   ├── Validator.php        # JSON-Schema-subset validator (tool input validation)
│   │   ├── Sanitize.php         # WP-aware sanitization helpers
│   │   ├── StructuredError.php  # {code, message, type, retryable, context} envelopes
│   │   ├── Diff.php               # Unified line diff (for Phase 2 snapshots + admin previews)
│   │   └── Crypto.php           # Versioned-envelope secret encryption (sodium preferred, AES-256-GCM/openssl
│   │                            #     fallback); current/previous key-version rotation, legacy-ciphertext
│   │                            #     detection + reencrypt() (SEC-M5)
│   ├── Settings/Settings.php    # Typed settings model + mode presets
│   ├── Database/
│   │   ├── Database.php         # wpdb wrapper + table prefixing
│   │   ├── Migrator.php         # Versioned, forward-only migration runner
│   │   ├── Schema/              # One class per migration
│   │   └── Repositories/        # AuditLogRepository, ToolExecutionRepository,
│   │                            # ApprovalRepository, ApiKeyRepository
│   ├── Security/
│   │   ├── PermissionEngine.php # Levels 0-4, modes, grants, escalation blocklist
│   │   ├── PathGuard.php        # Canonicalization, traversal blocking, protected files
│   │   ├── Authenticator.php    # Application Passwords (native) + AI OS API keys
│   │   ├── ApiKeyManager.php    # Issue/rotate/revoke; hashed at rest
│   │   ├── RateLimiter.php      # Per-principal rolling window (atomic, DB-backed)
│   │   ├── CapabilityManager.php # Admin-controlled, whitelist-only grant/revoke of
│   │   │                        #     ai_os_use/ai_os_approve per user; no self-escalation, audited
│   │   └── PromptHygiene.php    # Marks untrusted site content in tool output
│   ├── Audit/
│   │   ├── AuditLogger.php      # Append entries, secret redaction, filtering
│   │   └── AuditIntegrity.php   # HMAC hash-chain verification (tamper-evidence, not immutability)
│   ├── Abilities/
│   │   ├── Ability.php          # DTO
│   │   ├── AbilityResult.php    # Success/error envelope
│   │   └── AbilityRegistry.php  # register/get/list + filters
│   ├── Tools/
│   │   ├── Tool.php             # DTO: name, description, category, input/output schema,
│   │   │                        #     permission, risk level, approval policy, callback,
│   │   │                        #     integration, version, availability condition
│   │   ├── ToolResult.php
│   │   ├── ToolRegistry.php     # Dynamic registry + `ai_os_register_tool` filter
│   │   ├── ToolExecutor.php     # validate → permission → approval gate → execute → audit
│   │   └── Catalog/             # Phase 1 tool providers (see §7)
│   ├── Context/
│   │   ├── ContextEngine.php    # Builds + caches the site tree
│   │   └── Inspectors/          # Theme, Plugin, Content, System inspectors
│   ├── Files/FileReader.php     # Read-only file engine (WP Filesystem API)
│   ├── Mcp/
│   │   ├── Server.php           # Method dispatch: initialize/tools/resources/prompts/ping
│   │   ├── Protocol/JsonRpc.php # Request/Response/Error objects per JSON-RPC 2.0
│   │   ├── Protocol/ErrorCode.php
│   │   └── Transports/RestTransport.php  # Stateless Streamable HTTP endpoint
│   ├── Rest/
│   │   ├── RestApi.php
│   │   └── Controllers/         # Site, Tools, Approvals, Logs, Context, Settings, Keys, Capabilities, Mcp
│   ├── Logging/DebugLog.php     # Internal debug logger (WP debug log integration)
│   └── Admin/
│       ├── AdminPages.php       # Menu, screen registration, asset pipeline
│       ├── DashboardApp.php     # React mount + config bootstrap (nonces, endpoints)
│       └── Onboarding.php       # First-run wizard (safe/balanced/advanced)
└── tests/
    ├── bootstrap.php            # WP function shims + autoloader
    ├── Unit/                    # Pure-PHP unit tests
    ├── Integration/             # Tool/MCP functional tests against shims
    └── shim/wp-functions.php    # ~120 shim functions so the kernel runs headless
```

## 4. Database schema (Phase 1)

Custom tables via versioned migrations (never `wp_options` for bulk data). Prefix: `{wp_prefix}ai_os_`.
Charset/collation follows the site (`$wpdb->get_charset_collate()`). Migrations are forward-only;
version stored in `wp_options:ai_os_db_version`.

| Table | Columns (abridged) | Purpose |
|---|---|---|
| `audit_logs` | `id BIGINT PK, occurred_at DATETIME, user_id BIGINT, client VARCHAR(64), principal_type VARCHAR(20), tool VARCHAR(190), action VARCHAR(190), args_hash CHAR(64), args_json LONGTEXT, risk TINYINT, status VARCHAR(20), error TEXT, affected_objects LONGTEXT, affected_files LONGTEXT, approval_id BIGINT, rollback_id BIGINT NULL, duration_ms INT, ip VARBINARY(16), integrity_version TINYINT NULL, chain_seq BIGINT NULL, prev_hash CHAR(64) NULL, record_hash CHAR(64) NULL` | Append-mostly audit trail. The last four columns (added by migration `202509060002`, SEC-M4) are a **tamper-evidence** chain (`AIOS\Audit\AuditIntegrity`, HMAC-SHA256 per row over its own redacted content + the predecessor's `record_hash` + `chain_seq`) — this makes tampering *detectable*, not *prevented*: the table is not append-only/WORM at the storage layer, and rows written before this migration have all four columns `NULL` (reported as `legacy`, never silently treated as verified). Indexes: `(occurred_at)`, `(user_id)`, `(tool)`, `(status)`, `(risk)`, `(chain_seq)`. |
| `tool_executions` | `id, occurred_at, tool, user_id, client, success TINYINT, duration_ms, error_code VARCHAR(120)` | Observability aggregates (usage stats, error rates). Indexes: `(tool, occurred_at)`, `(success)`. |
| `approvals` | `id, created_at, expires_at, user_id, client, tool, args_json LONGTEXT, risk TINYINT, reason TEXT, status ENUM-ish VARCHAR(20) pending/approved/rejected/expired, decided_by BIGINT NULL, decided_at DATETIME NULL, execution_status VARCHAR(20), execution_result LONGTEXT, execution_log_id BIGINT` | Approval queue. Indexes: `(status)`, `(risk, status)`. |
| `api_keys` | `id, created_at, label VARCHAR(190), key_prefix VARCHAR(12), key_hash CHAR(64), user_id BIGINT, capabilities LONGTEXT (JSON array), max_level TINYINT, last_used_at DATETIME NULL, last_ip VARBINARY(16) NULL, revoked_at DATETIME NULL, expires_at DATETIME NULL` | AI OS API keys. Lookup by SHA-256 of presented key; `key_prefix` for UI identification. Index: `(key_hash)`. |

Phase 2 migrations will add `tasks`, `snapshots`, `changes`; Phase 3 adds `context_index` (full-text
search over indexed site artifacts). This is stated so the migration sequence is planned, not promised.

## 5. Security model

### 5.1 Permission levels (spec §7)

| Level | Name | Examples |
|---|---|---|
| 0 | READ | site info, list posts, read theme files, plugin inventory |
| 1 | SAFE WRITE | create/update posts, pages, media metadata |
| 2 | SENSITIVE | update/delete content, user data (Phase 1: delete content) |
| 3 | DESTRUCTIVE | delete files, plugins, admin-level changes (Phase 2+: file writes) |
| 4 | DEPLOYMENT | deploy staged changes (Phase 2+) |

### 5.2 Modes (spec §59)

| Mode | Max auto-executable level | Approval required |
|---|---|---|
| safe (default) | 1 | ≥ 2 |
| balanced | 2 | ≥ 3 |
| advanced | 3 | 4 + anything flagged destructive |

Any principal's effective ceiling = `min(mode ceiling, user grants, API key max_level)`.
**The AI can never raise its own ceiling**: grants are set by authenticated admins through the
REST/admin UI only, guarded by `manage_options` + nonces. Self-escalation attempts (e.g. a tool
call that tries to modify `ai_os_settings`, create admins, or revoke its own audit trail) are
blocklisted at the executor level and audited as `blocked`.

### 5.3 Authentication (spec §41)

Two real mechanisms in Phase 1, both non-plain-text at rest:

1. **WordPress Application Passwords** (native, recommended for ChatGPT/Claude/Claude Code/Cursor).
   Basic auth over HTTPS; revocable per user; pairs with core REST permission system.
2. **AI OS API Keys** — `aios_` + 43 chars base64url (32 bytes CSPRNG). Stored as SHA-256 hash.
   Scoped: WP user binding, capability allowlist, `max_level`, expiry, last-used tracking, rotation.

HTTPS is enforced for MCP + REST when the site is not local (filterable for development).
Secrets are never returned by tools; `AuditLogger` + `FileReader` redact secret-shaped patterns.

### 5.4 Threat model highlights (spec §47/48/49)

- **Path traversal**: `PathGuard` canonicalizes + confines reads to `ABSPATH` roots minus protected list; `..` segments rejected before any filesystem call.
- **Privilege escalation**: WP caps (`manage_options`, `edit_posts`, …) checked by permission callbacks *and* the engine's level check; REST nonces for cookie-based admin sessions.
- **SQL injection**: all queries through `$wpdb->prepare`; repositories only.
- **CSRF**: nonce verification on all state-changing admin/REST endpoints.
- **Prompt injection**: site content returned by tools is wrapped in clearly delimited untrusted blocks by `PromptHygiene`; tool output never alters permissions.
- **Secret exposure**: `wp-config.php`, `.env`, `.htaccess`, salts, API keys — protected list + regex redaction in logs. `Crypto`'s error paths never include plaintext or key material in exception messages (SEC-M5).
- **SSRF / command injection**: Phase 1 has **no** outbound-fetching or shell-executing tools. WP-CLI shell execution ships in Phase 2 behind an allowlist, never by default.
- **Rate limiting**: per-principal window, backed by an atomic DB counter (`RateLimitRepository`, SEC-M1 — not a non-atomic transient read-increment-write) — default 120 MCP calls/min, 60 tool executions/min.
- **Audit tamper-evidence**: every audit row is HMAC-SHA256-chained to its predecessor (SEC-M4, `AuditIntegrity`). This detects tampering after the fact; it does not make the table append-only or immutable at the storage layer — do not describe the audit log as "immutable" in any external-facing material.
- **Key rotation**: `Crypto` ciphertext carries an explicit, non-secret envelope (format version, backend, key version). Rotating the underlying key registers the old key as "previous" so already-encrypted values keep decrypting, and `reencrypt()` migrates them to the current key version without the caller ever handling plaintext directly (SEC-M5).

## 6. MCP architecture

- **Transport**: Streamable HTTP, **stateless** profile (spec `2025-03-26`/`2025-06-18` compatible): single `POST /wp-json/ai-os/v1/mcp`, `Accept: application/json`. No session affinity required; `Mcp-Session-Id` honored if the client sends one, echoed for compatibility, but never trusted for auth. `GET` returns `405` as allowed for stateless servers.
- **Auth**: HTTP Basic (application password) or `X-AI-OS-Key` header → `Authenticator` resolves a `WP_User` before the protocol layer runs. Unauthenticated JSON-RPC = `-32600` (or `401` before protocol).
- **Methods**: `initialize`, `notifications/initialized`, `ping`, `tools/list`, `tools/call`, `resources/list`, `resources/get`, `prompts/list`, `prompts/get`.
- **Tool result mapping**: `ToolResult` → MCP `content[]` (text) + `structuredContent` (schema-conformant) + `isError` flag. Tool-level errors are *result-level* errors (MCP style), protocol errors only for malformed RPC.
- **Version negotiation**: server advertises `2025-06-18`; if client requests a different version we echo the client version when it is a known-supported one, else respond with ours.
- **Approval interplay**: a tool needing approval returns a *successful RPC response* whose tool result states `status: approval_required` with `approval_id` + diff preview where applicable. Nothing is executed pre-approval.

## 7. Tool catalog (Phase 1, all genuinely implemented)

| Category | Tools |
|---|---|
| site | `site.get_info`, `site.get_health`, `site.get_environment` |
| content | `content.list_posts`, `content.get_post`, `content.create_post`, `content.update_post`, `content.delete_post`, `content.list_pages`, `content.get_page`, `content.create_page`, `content.update_page` |
| media | `media.list`, `media.get`, `media.upload`, `media.update`, `media.delete` |
| theme | `theme.get_info`, `theme.list_files`, `theme.read_file` (read-only in Phase 1; write engine is Phase 2) |
| plugin | `plugin.list` |
| users | `users.list`, `users.get` (no create/update in Phase 1) |
| menus | `menus.list`, `menus.get` |
| system | `system.cron.list`, `system.cache.flush`, `system.get_options_subset` |
| logs | `logs.list`, `logs.get` (AI OS audit log) |
| context | `context.get_site_map` (structured tree) |

Every tool declares: name, description, category, input schema (JSON Schema subset), output schema,
required permission, risk level (0-4), confirmation policy, callback, integration slug, version,
availability condition (e.g. `media.upload` requires `upload_files`).

Third parties extend via:

```php
add_filter( 'ai_os_register_tool', function ( array $tools ): array {
    $tools[] = AIOS\Tools\Tool::make( [ ... ] );
    return $tools;
} );
```

or the `ai_os_register_ability` filter at the abilities layer.

## 8. Data flow: a tool call end-to-end

```
MCP client POSTs tools/call {name:"content.create_post", arguments:{...}}
  → RestTransport (REST permission callback runs: authenticated + ai_os_use)
  → Authenticator (resolves WP_User via app password / API key)
  → RateLimiter (per principal)
  → JsonRpc::parse (protocol validation)
  → Server::dispatch → ToolRegistry::get
  → ToolExecutor::execute
      1. resolve tool + availability                               → structured error
      2. baseline access (PermissionEngine::canUse)                → 403-style error
      3. escalation blocklist (AI may never touch AI OS security
         state: settings, grants, roles, own audit trail)          → blocked + audited
      4. Validator::validate(tool.inputSchema, args)               → structured error
      5. RateLimiter (executions window)
      6. APPROVAL GATE (risk level vs mode threshold) — runs BEFORE
         the auto-execution ceiling: the ceiling governs what runs
         unattended; the approval queue is how humans authorize
         beyond it. API-key ceilings still cap approval requests.
         Key-scope or ability-callback failures → denied.
         Otherwise → approvals row, NOTHING executed, response
         says approval_required with the approval id + TTL.
      7. auto-execution ceiling check (mode ← WP caps ← grants ← key)
      8. ability permission callback (per-object caps)
      9. ability callback (WordPress APIs only)                    → AbilityResult
     10. AuditLogger::log + ToolExecutionRepository::record
  → ToolResult → MCP content envelope → JSON-RPC response
```

Approval decisions (admin UI or REST, `ai_os_approve` + nonce): claim →
`executeApproved()` re-validates stored args and checks the DECIDING user's
**capability ceiling** (approval authorizes beyond the mode ceiling, never
beyond real WordPress capabilities) → execute → audit → complete record.

## 9. REST API (Phase 1)

Namespace `ai-os/v1`. All endpoints: permission callback, args validation + sanitization, schema in `OPTIONS` (via core).

| Route | Methods | Permission |
|---|---|---|
| `/site` | GET | `ai_os_read` |
| `/context` | GET | `ai_os_read` |
| `/tools` | GET | `ai_os_read` |
| `/tools/execute` | POST | `ai_os_use` + per-tool engine check |
| `/mcp` | POST (GET→405) | authenticator-first |
| `/approvals` | GET | `ai_os_approve` |
| `/approvals/(?P<id>\d+)/approve` | POST | `ai_os_approve` + nonce |
| `/approvals/(?P<id>\d+)/reject` | POST | `ai_os_approve` + nonce |
| `/approvals/approve-safe` | POST (bulk) | `ai_os_approve` + nonce |
| `/logs` | GET (filters) | `ai_os_read` |
| `/settings` | GET/POST | `manage_options` + nonce |
| `/keys` | GET/POST/DELETE | `manage_options` + nonce (API key lifecycle) |
| `/capabilities/(?P<user_id>\d+)` | GET | `manage_options` (current `ai_os_use`/`ai_os_approve` grant state) |
| `/capabilities/grant` | POST | `manage_options` + nonce (whitelist-only; no self-escalation) |
| `/capabilities/revoke` | POST | `manage_options` + nonce |
| `/status` | GET | `ai_os_read` (dashboard health card) |

## 10. Extensibility API

- `ai_os_register_tool` (filter) — append `Tool` DTOs.
- `ai_os_register_ability` (filter) — append `Ability` DTOs.
- `ai_os_permission_level_map` (filter) — adjust level mapping (never lowers blocklist).
- `ai_os_protected_files` (filter) — extend protected path list.
- `AIOS\Core\Container` — service override via `ai_os_container_build`.
- `AIOS\Tools\Tool::make()` / `AIOS\Abilities\Ability::make()` — public constructors for DTOs.

## 11. Performance (spec §38)

- Frontend requests: **zero** plugin work — everything hooks admin/REST/CLI contexts; no frontend assets, no frontend hooks.
- Admin: single enqueue on AI OS screens only.
- Context tree cached in a transient (TTL 15 min, manual invalidation hook on option updates).
- MCP/REST: no boot of the React app; server path is pure PHP.
- Background: audit retention purge via daily WP-Cron.

## 12. Phase roadmap alignment

- **Phase 2** (AI Developer): file writing engine + snapshots + diffs + rollback, task engine (long-running, resumable), testing engine, code generation.
- **Phase 3** (Builder Intelligence): Elementor / Gutenberg / WooCommerce / ACF / SEO / forms adapters.
- **Phase 4** (Advanced AI OS): multi-agent orchestration, visual browser service, external sandbox providers, external MCP client, Figma import, deployment engine.

Each later phase lands as additional migrations + modules behind the same executor/permission/audit pipeline.

## 13. Phase 2 mutation pipeline (hard workflow — PARTIAL, in-process only)

Every Phase 2+ mutation — anything that changes site state beyond what Phase 1's tool
catalog already covers (content/media CRUD) — is required to pass through this exact
sequence. No step may be skipped, reordered, or bypassed by a shortcut path:

```
User Request
  → Planner     (turns a request into a typed ChangeSet — no free-form code/shell/SQL)
  → Policy      (PermissionEngine-equivalent check: is this ChangeSet allowed at all,
                 for this principal, before anything touches disk or the DB)
  → Snapshot    (capture pre-state for every target the ChangeSet will touch)
  → ChangeSet   (the reviewable, typed unit of change — see AIOS\Mutation\ChangeSet)
  → Diff        (human-readable before/after, computed from the Snapshot + ChangeSet)
  → Approval    (reuses the existing approval-queue model, ChangeSet-aware)
  → Apply       (execute each ChangeOperation; Policy checked per-operation, not just
                 per-ChangeSet)
  → Verify      (assert the mutation had its intended — and only its intended — effect)
  → Audit       (AuditLogger, same tamper-evidence chain as every other action)
  → Rollback    (RollbackRecord derived from the Snapshot; available on Verify failure
                 or explicit operator request)
```

**Status (2026-09-07): PARTIAL — real, tested pipeline mechanics; no durable state
layer.** `AIOS\Mutation\*` exists (`ChangeOperationInterface`, `ChangeSet`, `Snapshot`,
`MutationDiff`/`OperationDiff`/`DiffRenderer`, `ChangeSetFingerprint`, `ChangeSetState`,
`VerificationResult`, `RollbackRecord`, `MutationResult`, `MutationEngine`) plus six
concrete operations (`Operations\FileCreateOperation`, `FilePatchOperation`,
`FileDeleteOperation`, `OptionUpdateOperation`, `PostContentUpdateOperation`,
`MetadataUpdateOperation`). **Not exposed to any AI-facing tool, REST endpoint, or MCP
surface** — `MutationEngine` is container-bindable and directly tested, nothing else
can reach it.

**Implemented and tested** (346 tests as of this update):
- The full in-memory pipeline: Policy → Snapshot → Diff → Approval → Apply → Verify →
  Audit → Rollback, in that order, for a single synchronous request.
- Diff is a real, redacted before/after (`DiffRenderer`, reusing `AIOS\Support\Diff`
  for the line-diff algorithm) — text diff for file operations, canonical-JSON value
  diff for option/post/meta operations, secret-shaped values redacted to a hash
  (`AIOS\Support\Sanitize::looksLikeSecret()`), binary content redacted to a hash,
  content over 256 KiB truncated with hashes retained. Computed after Snapshot, before
  Approval.
- Approval is bound to a canonical `ChangeSetFingerprint` (id, site, actor, ordered
  operations' type/target/payload-fingerprint, risk, diff hash) stored in the existing
  `ApprovalRepository` row. `resumeApproved()` recomputes the fingerprint from the
  ChangeSet the caller hands back and rejects on any mismatch (reordered/retargeted/
  repayloaded operations, or a ChangeSet that plainly does not belong to that approval).
- Stale-state/TOCTOU: each operation's live `currentPreconditionFingerprint()` is
  compared, immediately before `apply()`, against the precondition captured at
  submission time (stored alongside the approval) — a target that drifted during the
  approval wait fails closed (`stale_state`) rather than applying against different
  state than was reviewed.
- Cross-site binding: `MutationEngine` switches to the ChangeSet's own site for
  Snapshot/Diff/Apply/Rollback when it differs from the current request's site, so a
  resume arriving in a different site's request context cannot mutate the wrong site.
- Multi-operation ChangeSets: apply in order; a failure at any step rolls back every
  already-applied operation in reverse order; a rollback failure is reported per
  operation, never silently swallowed or reported as success.
- `ChangeSetState`: the transition-legality authority (`PLANNED` → … → `COMPLETED`,
  plus `FAILED`/`ROLLBACK_REQUIRED`/`ROLLING_BACK`/`ROLLED_BACK`/`ROLLBACK_FAILED`/
  `EXPIRED`/`CANCELLED`/`STALE`), fails closed via `MutationException` on an illegal
  transition — defined and tested, but **not yet consulted by anything durable**, since
  no durable ChangeSet record exists yet (see below).

**Not implemented — explicit gaps, not silently deferred:**
- **No durable ChangeSet persistence.** A ChangeSet's operations live only in the
  PHP request that constructed them; only the approval row (id, fingerprint, risk,
  preview, submission-time preconditions) is durable. `resumeApproved()` requires the
  caller to reconstruct and pass back the identical ChangeSet — safe (the fingerprint
  check catches a mismatch) but not self-sufficient across a real multi-request
  workflow without an external store of the ChangeSet's own operations.
- **No encryption-at-rest for operation payloads** — there is nothing durable to
  encrypt yet; `AIOS\Support\Crypto` (SEC-M5) is the intended mechanism once there is.
- **No recovery journal.** A process crash mid-`apply()` for a multi-operation
  ChangeSet is not durably recorded at per-operation granularity; only the in-memory
  rollback-on-failure path (same request) is implemented.
- **No safe-operation registry/rehydration** (a closed type-string → operation-class
  whitelist for deserializing a persisted ChangeSet) — unnecessary today since nothing
  is persisted, but required before persistence lands, to avoid ever instantiating a
  class from untrusted stored data.
- **No durable replay/idempotency or execution lease** beyond the atomic, already-real
  `ApprovalRepository::claimPending()` (one approval can only ever be claimed once —
  tested). A `COMPLETED`-state short-circuit and a cross-process execution lock both
  depend on the durable store above.
- **No retention/purge job** for mutation records (none exist to purge yet).
- **No typed Planner boundary** — callers construct `ChangeSet`/operations directly;
  a `MutationPlannerInterface`-style typed builder that is the *only* legitimate
  upstream construction path (so Phase 3 cannot bypass validation) does not exist yet.

See `docs/audits/SPRINT-0.3-SECURITY-CI-REPORT.md` for the full narrative and the
Phase 2 hardening pass's adversarial-review findings.

Invariants enforced by construction (verified, not aspirational):

- No direct AI → filesystem write, unrestricted shell, unrestricted SQL, or `eval` —
  every mutation is a typed `ChangeOperation`, never a raw command string.
- Policy is checked before `Apply`, not after.
- A `Snapshot` is captured before `Apply`, for every target, unconditionally.
- `Approval` is required per the same risk-level rules Phase 1 already uses, and is
  bound to a fingerprint of exactly what was reviewed.
- `Audit` records every attempt, not just successes.
- `RollbackRecord`s are generated at `Snapshot` time, before any mutation — never
  reconstructed after the fact from partial state.
