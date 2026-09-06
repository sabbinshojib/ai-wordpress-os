# Security Guide

Security is the core product feature of AI WordPress OS. This document explains the model so you can operate it with confidence.

## Permission levels

| Level | Name | Phase 1 examples |
|---|---|---|
| 0 | READ | site info/health/environment, content listing/reading, theme file reading, plugin inventory, site map |
| 1 | SAFE WRITE | create/update posts, pages, media; media metadata |
| 2 | SENSITIVE | trash content, cache flush |
| 3 | DESTRUCTIVE | permanent media deletion (file writes arrive Phase 2) |
| 4 | DEPLOYMENT | staged deployments (Phase 2) |

## Modes

| Mode | Auto-executes | Approval required at |
|---|---|---|
| safe (default) | ≤ 1 | ≥ 2 |
| balanced | ≤ 2 | ≥ 3 |
| advanced | ≤ 3 | 4 |

The effective ceiling for any principal is `min(mode ceiling, WordPress capability ceiling, per-user grant, API-key max_level)`.

**Key invariant:** the approval queue runs *before* the auto-execution ceiling. That is the whole point — humans authorize what automation may not. When an action is approval-gated, nothing executes until an administrator claims and approves it; the client is told the approval id and that the action did not run.

## What the AI can never do

Enforced by the executor's escalation blocklist — refused and logged as `blocked`, regardless of grants or mode:

- Create administrator accounts
- Change user roles
- Modify AI OS settings, permission grants, or its own migrations state
- Touch its own plugin code or audit trail
- Escalate its own permission ceiling (grants can only *lower* ceilings)

There are simply **no tools** in Phase 1 that could: execute shell commands, fetch arbitrary URLs (SSRF surface), write files, or set arbitrary options. What doesn't exist can't be abused.

## Threat model coverage

| Threat | Defense |
|---|---|
| Path traversal / `../` attacks | `PathGuard` canonicalizes + confines reads to the guarded scope; `..` segments rejected before any filesystem call; realpath re-check defeats symlink pivots |
| Arbitrary file access | `wp-config.php`, `.env`, `.htaccess`, `php.ini`, `.htpasswd` and the AI OS plugin's own directory are always protected; extension allowlist (php/css/js/json/xml/html/svg/md/txt and friends); 512 KB read cap (configurable) |
| Privilege escalation | WP capabilities checked per-object by ability callbacks + level engine + blocklist; REST nonce on cookie-authenticated state changes |
| SQL injection | Every query through `$wpdb->prepare`; repositories are the only DB callers |
| XSS | Output escaped via WordPress APIs; admin console renders through React with structured data |
| CSRF | REST nonce (`X-WP-Nonce`) on all state-changing admin/REST endpoints; application-password/key auth is exempt by design |
| SSRF | No outbound-fetching tools exist in Phase 1 (URL media sideload deliberately deferred) |
| Command injection | No shell/exec anywhere in the codebase |
| Secret exposure | Secrets never returned by tools; `Sanitize::redact` strips secret-shaped patterns from audit logs and file reads; API keys stored as SHA-256 hashes only |
| Prompt injection | Site content returned by tools is wrapped in explicit untrusted-content delimiters; tool output never alters permission state; server `initialize` instructions brief the model on the security posture |
| Malicious MCP clients | Application passwords/API keys with scoped ceilings; rate limits (requests + executions); audit trail; HTTPS required |
| DoS | Rolling-window rate limiter per principal; bounded batch sizes; bounded file reads and scans |

## Authentication

| Method | Storage | Notes |
|---|---|---|
| Application Passwords | WordPress core (hashed) | Recommended; revocable per user in Profile |
| AI OS API keys | SHA-256 hash + 8-char prefix | User-bound, capability-scoped ceiling, optional expiry, last-used tracking, rotation, revocation |

Never store a raw key anywhere: it is displayed exactly once at issue time.

## Audit

Every tool execution — success, error, blocked, approval-required, and approved-execution — writes an immutable-style row with: timestamp, user, client, tool, redacted arguments + hash, risk level, status, error, affected objects, approval linkage, duration, packed IP. Retention is configurable (default 90 days; purged daily by WP-Cron).

## Operational hardening checklist

1. Start in **Safe mode**; raise only on development sites.
2. Issue API keys with the lowest workable `max_level`.
3. Keep "Require HTTPS" on (default).
4. Review **AI OS → Approvals** and **Activity** regularly; watch for `blocked` rows.
5. Rotate/revoke API keys when staff or devices change.
6. Set an audit retention that matches your compliance needs.
