# AI WordPress OS — Architecture

> Phase 1 Foundation — design document. This file describes the architecture that is
> **implemented** in this release. Anything marked `Phase 2+` is designed-for but not
> implemented yet (interfaces/slots exist, no fake UI and no dead buttons), with one
> partial exception: §13's Phase 2 mutation pipeline has a real, tested implementation
> (`AIOS\Mutation\*`) as of 2026-09-07 — including durable, encrypted persistence, a
> DB-backed execution lease, durable replay protection, and a per-operation
> crash-recovery journal with classification-only recovery (no automatic mid-flight
> continuation) — but real WordPress/MySQL execution is CI-CONFIGURED-NOT-RUN (shim-only
> so far), and none of it is wired to any AI-facing tool/REST/MCP surface. See §13 for
> the exact implemented-vs-not split; never round this up to "Phase 2 complete."

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

**Status (2026-09-07): PARTIAL — real, tested pipeline mechanics, a real, tested
durable persistence layer, AND a real, tested per-operation crash-recovery journal
(classification only — no automatic mid-flight continuation); no AI-facing
exposure.** `AIOS\Mutation\*` exists: the in-process pipeline (`ChangeOperationInterface`,
`ChangeSet`, `Snapshot`, `MutationDiff`/`OperationDiff`/`DiffRenderer`,
`ChangeSetFingerprint`, `ChangeSetState`, `VerificationResult`, `RollbackRecord`,
`MutationResult`, `MutationEngine`), six concrete operations (`Operations\FileCreateOperation`,
`FilePatchOperation`, `FileDeleteOperation`, `OptionUpdateOperation`,
`PostContentUpdateOperation`, `MetadataUpdateOperation`), the durable layer
(`ChangeSetRepository`, `OperationRegistry`, `DurableMutationCoordinator`), the
per-operation journal (`OperationJournalState`, `OperationJournalRepository`), and a
typed Planner boundary (`MutationPlannerInterface`, `TypedChangeSetBuilder`,
`OperationSpecification`). **Not exposed to any AI-facing tool, REST endpoint, or MCP
surface** — everything here is container-bindable and directly tested, nothing else
can reach it yet (verified this pass via a repository-wide grep for `DurableMutationCoordinator`/
`OperationJournalRepository`/`MutationEngine` under `src/Rest`, `src/Mcp`, `src/Tools`: no matches).

**Implemented and tested** (452 tests as of this update, PHP 8.2 and 8.3 both green,
13/13 acceptance):
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
  transition — consulted by `ChangeSetRepository::transition()` before every durable
  write, not merely defined in isolation.
- **Durable persistence** (migration `202509070001`, table `ai_os_change_sets`):
  `ChangeSetRepository` encrypts the operation payload and a separate recovery/
  snapshot-journal payload via the existing SEC-M5 `Crypto` service (no new crypto
  layer; environment-derived key; this is Crypto's first real durable call site), with
  a sha256 integrity hash checked after every decrypt on top of Crypto's own AEAD tag.
  Every state transition is a compare-and-swap (`state` + `state_version`) — 0 rows
  affected means the caller's view was stale, never silently overwritten.
- **Safe rehydration**: `OperationRegistry` is the *only* place a persisted type string
  becomes a class instance — a closed `match()` over the six known type constants,
  never `new $class`, `unserialize()`, or Reflection from stored/caller data. Unknown
  type, unsupported schema version, and malformed spec all fail closed with distinct
  `MutationException` codes. `serialize()`/`rehydrate()` round-trip a whole ChangeSet
  (including its fingerprint, verified identical after the round trip).
- **Execution lease**: a DB-backed, atomic acquire/release/stale-takeover lease
  (owner token + expiry) on the durable row — never in-memory-only, so two separate
  requests/processes cannot both execute the same ChangeSet.
- **Durable replay protection**: `DurableMutationCoordinator::resume()` refuses
  outright once a row is `COMPLETED`, regardless of how many times it is called —
  holds across process restarts, not just within one request's approval-claim
  atomicity (which was already real before this layer existed).
- **Caller-side simplicity** (Package 9's actual requirement): `DurableMutationCoordinator::resume()`
  takes only a ChangeSet id string — the caller never reconstructs a ChangeSet by hand.
  `MutationEngine::resumeApproved()` itself is unchanged and still requires that,
  remaining available for the pure in-memory (no persistence) use case.
- **Idempotent submit**: a second `submit()` with the same idempotency key returns the
  original outcome (`alreadyCompleted()` once terminal) instead of creating a
  duplicate durable row or re-running the mutation.
- **Retention**: `ChangeSetRepository::purgeTerminalOlderThan()`, wired into the
  existing daily maintenance cron, reusing the audit-log retention window. Filters by
  `ChangeSetState::isTerminal()` in PHP rather than a SQL `IN (...)` clause (a
  deliberate choice — a filtering mistake in a purge path deletes data) and never
  purges `ROLLBACK_FAILED` (manual recovery required) regardless of age.
- **Typed Planner boundary**: `TypedChangeSetBuilder` builds every operation
  exclusively through `OperationRegistry::build()` — the only intended upstream
  construction path for a future caller (Phase 3, not built). Rejects an unknown type,
  a class name disguised as a type, a non-`OperationSpecification` entry, and an empty
  specification list.
- **Per-operation crash-recovery journal** (migration `202509070002`, table
  `ai_os_operation_journal`): one row per `ChangeOperationInterface` instance within a
  ChangeSet, with its own lifecycle (`OperationJournalState`: `PENDING` →
  `SNAPSHOTTED` → `APPLYING` → `APPLIED` → `VERIFYING` → `VERIFIED`, plus
  `ROLLBACK_REQUIRED`/`ROLLING_BACK`/`ROLLED_BACK`/`FAILED`/`ROLLBACK_FAILED`/
  `MANUAL_RECOVERY_REQUIRED`) tracked independently of its siblings and of the
  ChangeSet's own coarse `state` column. `MutationEngine::submit()`/`resumeApproved()`
  accept an optional per-operation `$on_event` hook (`'snapshot_captured'`,
  `'apply_started'`, `'apply_completed'`, `'apply_failed'`, `'verify_started'`,
  `'verify_completed'`, `'stale_state_detected'`, `'rollback_started'`,
  `'rollback_completed'`) that `DurableMutationCoordinator` uses to journal each step
  as it happens — the engine itself knows nothing about persistence. Rows are
  addressed by `(change_set_id, operation_index)`, never operation id: an operation
  rehydrated by `OperationRegistry::rehydrate()` (the `resume()` path) gets a fresh
  random id (`AbstractOperation`'s constructor), so only the operation's fixed
  position in the ChangeSet's operations array survives a round trip — this was a real
  bug this pass found via a failing integration test (the journal row stuck at
  `snapshotted` after a real `resume()`), not merely reasoned about.
- **Crash classification** (`DurableMutationCoordinator::recover()`): given a
  ChangeSet id, inspects its journal rows and classifies into exactly one of two
  unambiguous outcomes — fully clean (every row still `PENDING`/`SNAPSHOTTED`, i.e.
  genuinely awaiting approval/resume, not a crash) or fully terminal (nothing to do) —
  or escalates to `ChangeSetState::MANUAL_RECOVERY_REQUIRED` for everything else,
  including a single operation that applied but was never verified, a multi-op
  ChangeSet where only some operations show progress, and a rollback that itself
  failed. **Deliberately does not attempt automatic mid-flight continuation** — several
  of the six operation types are not safely re-appliable (`FileCreateOperation` once
  the file exists; `FileDeleteOperation` once it is already gone), so this pass
  implements precise, tested classification and fails closed rather than guessing.
  `MANUAL_RECOVERY_REQUIRED` is terminal, is never auto-purged by retention
  regardless of age, and escalating into it is always audited via `AuditLogger`
  (`tool: 'mutation.recover'`) — independent of whether the underlying transition
  actually happened.
- **Retention cascade**: `ChangeSetRepository::purgeTerminalOlderThan()` deletes a
  purged ChangeSet's journal rows with it (`OperationJournalRepository::
  deleteForChangeSet()`), always before the parent row, so a crash between the two
  leaves only a harmless orphaned journal row, never a journal row referencing an
  already-deleted ChangeSet.

- **Repository-layer fault injection** (`tests/Support/FaultInjectingDatabase.php`,
  `FaultInjectingCrypto.php` — never reachable from production; `ChangeSetRepository`/
  `OperationJournalRepository` depend on `AIOS\Database\DatabaseInterface`/
  `AIOS\Support\CryptoInterface`, which `Database`/`Crypto` implement, purely as a test
  seam): deterministically forces an `INSERT`/`UPDATE`/encrypt/decrypt failure at an
  exact point and proves it fails closed — no false success, no plaintext fallback, no
  forced overwrite of a stale row, no mutation applied without its durable journal
  record. This review found and fixed three real, previously-latent gaps rather than
  only adding tests against already-correct code: (1) `ChangeSetRepository::create()`/
  `OperationJournalRepository::create()` discarded `INSERT`'s return value entirely, so
  a real insert failure fell through to a `null` return against a non-nullable `array`
  return type (a `TypeError`, not a controlled failure) — both now throw a stable
  `MutationException` and `DurableMutationCoordinator` converts that to a safe
  `MutationResult`; (2) the journal event hook discarded `saveRecovery()`'s and
  `transition()`'s boolean return values at `'snapshot_captured'`/`'apply_started'`, so
  a real durable-write failure there was silently ignored and live WordPress state
  would still be mutated with no durable record it was about to happen —
  `MutationEngine::applyChangeSet()` now runs `'apply_started'` inside its existing
  apply-failure try/catch (so a thrown journal-write failure there triggers the exact
  same rollback-everything-already-applied path as a real `apply()` failure, not a new
  one); (3) `MutationEngine`'s own `captureSnapshots()`/`applyChangeSet()` only catch
  `MutationException`, so a bare `\RuntimeException` from `Crypto::encrypt()` (its own
  documented contract) could still escape `DurableMutationCoordinator` entirely
  uncaught — `submit()`/`resume()` now wrap every engine call and `loadChangeSet()` in
  a `\Throwable` catch that explicitly marks the row `FAILED` rather than leaving it
  looking untouched.
- **Audit fault injection** (`AuditFaultInjectionTest`, later pass in the same exit-gate
  closure): `AuditLogRepository`'s constructor widened from the concrete `Database` to
  `DatabaseInterface` (production wiring unchanged) so its own persistence layer can be
  fault-injected the same way `ChangeSetRepository`/`OperationJournalRepository` already
  are. Proves, via the real coordinator/engine path (not `AuditLogger` in isolation): a
  failed audit write on policy denial never blocks the denial; a failed audit write after
  a real apply never masks or fabricates the durable `COMPLETED` outcome; a failed audit
  write during a real rollback never corrupts the rollback itself; a failed audit write
  during `MANUAL_RECOVERY_REQUIRED` escalation still leaves the durable state correctly
  transitioned (the transition happens before, and independent of, the audit write); no
  secret-shaped argument material leaks when the write fails. Confirms `AuditLogger`'s
  existing "never throws, returns 0 on failure" contract against a real forced failure,
  not merely by code reading.
- **Per-operation journal multisite isolation**: table-name resolution, row/recovery
  invisibility across a `switch_to_blog()`, and the full `DurableMutationCoordinator::
  submit()` path are all tested. This review also found, and a later pass in the same
  exit-gate closure FIXED (BUG-006, shim-level only — see `docs/audits/BUG-GAP-REGISTER.md`),
  a pre-existing, plugin-wide test-shim limitation: `tests/shim/wp-functions.php`'s fake
  SQL engine could not parse a real multisite table name for any site but the first, and
  its row storage was keyed by table short name regardless of site. The fix widened every
  `wp_([a-z_]+)` table-name regex to `wp_((?:\d+_)?[a-z_]+)` and, because that same
  capturing group now includes the digit blog-id prefix when present, the existing
  single-group `$wpdb->tables[...]` keying automatically became a fully-qualified-table-name
  key with no other call site changed — site 1 keeps its unprefixed short-name key exactly
  as before, while site 2/3 get genuinely separate buckets. `JournalMultisiteIsolationTest`
  now has two direct regressions (`test_journal_writes_succeed_independently_on_two_sites_with_the_same_key`,
  `test_change_set_writes_succeed_independently_on_two_sites_with_the_same_key`) proving
  real per-site WRITE isolation under the SAME id/key on two sites — something no test in
  this codebase could previously do. This proves the SHIM's own in-memory model is no
  longer actively wrong about isolation; it is not a claim about real MySQL — see the
  CI-CONFIGURED-NOT-RUN caveat below for that.

**Not implemented — explicit gaps, not silently deferred:**
- **No automatic crash-recovery continuation.** `recover()` classifies and escalates;
  it never attempts to resume a partially-applied ChangeSet or finish an interrupted
  rollback on its own. This is a deliberate scope boundary (see above), not an
  oversight — resolving a `MANUAL_RECOVERY_REQUIRED` ChangeSet today requires a human
  or a future, separately-scoped, operation-type-aware continuation engine.
- **Fault injection does not cover a genuine mid-transaction failure** (an `INSERT`/
  `UPDATE` that fails partway through a multi-statement sequence at the real MySQL
  driver level) — a real MySQL driver behavior the shim cannot model at all; only the
  CI-CONFIGURED-NOT-RUN job below can. (`AuditLogger` write-failure fault injection
  itself is now covered — see the bullet above.)
- **The test shim now proves per-site WRITE isolation at the shim's own in-memory
  level** (see the multisite finding above) but this is still not real MySQL — table
  charset/collation, index behavior, and real row-locking under genuine concurrent
  connections remain provable only via the CI-CONFIGURED-NOT-RUN job below.
  `MULTISITE_WRITE_VALIDATION` = SHIM-VALIDATED, not REAL-DB-VALIDATED.
- **No real WordPress/MySQL execution — CI-CONFIGURED-NOT-RUN.** Every test above
  (452 on both PHP 8.2 and 8.3, 13/13 acceptance) runs against this repository's own
  PHP-only WordPress shim (`tests/shim/wp-functions.php`), never a real MySQL/MariaDB
  instance or a real WordPress install — no such environment was available in the
  authoring sandbox, and system-wide MySQL/WordPress installation was explicitly out
  of scope for this pass. `.github/workflows/ci.yml`'s `test-real-wp-mysql` job now
  exists (ephemeral MySQL service container + throwaway WP-CLI install + real dbDelta
  migrations + `tools/ci/real-db-smoke.php` round-tripping the durable repositories
  against real MySQL) but has **never been executed** — neither the workflow YAML nor
  the smoke script's correctness has been observed to actually run. Do not read this
  bullet, or any other doc, as claiming real-database validation until an actual CI
  run of that job is green.
- **No AI-facing exposure** — by design, for this pass. Nothing here is reachable from
  any REST endpoint, MCP tool, or admin action yet.

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
