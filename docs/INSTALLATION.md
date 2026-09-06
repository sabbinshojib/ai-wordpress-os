# Installation Guide

## Requirements

### Hard requirements (activation is refused without these)

| Requirement | Why |
|---|---|
| WordPress 6.9+ | Modern REST/REST-infrastructure APIs |
| PHP 8.2+ | Typed properties, enums-ready syntax, `readonly`, match |
| HTTPS | Required for the MCP endpoint when non-local |
| MySQL/MariaDB | Standard WordPress database (four custom tables are created) |

No Composer, no Node.js, no Docker, no Redis, no shell access are required at runtime. The plugin is a single self-contained ZIP.

### Optional PHP extensions (the plugin runs and activates without these — see below for exactly what each one affects)

| Extension | What it's used for | What happens without it |
|---|---|---|
| `mbstring` | Character-accurate string length/truncation for stored fields (labels, audit entries, approval previews) | Falls back to a byte-based equivalent (`AIOS\Support\Strings`). Length limits still apply; on non-ASCII content the cut can land one character earlier than with `mbstring`, and the fallback is careful never to leave a broken multi-byte sequence at the boundary. |
| `sodium` **or** `openssl` (at least one) | Secret encryption backend for `AIOS\Support\Crypto` | Nothing in the current (Phase 1) feature set calls this — API keys are hashed with PHP's built-in `hash()`, not encrypted. If neither extension is present, `Crypto::encrypt()`/`decrypt()` refuse to run with a clear, catchable error the moment a future feature needs them, instead of silently falling back to an insecure method. |

`sodium` ships enabled by default on virtually every PHP 8.2+ build (it has been bundled since PHP 7.2), and `openssl` is close to universal on WordPress hosts, so hitting the "neither is available" case in practice is very unlikely — but it is checked and reported, not assumed.

Check `wp ai-os status` (below) or **AI OS → Dashboard** for a live report of any degraded optional capability on your specific host. A degraded capability never blocks activation or shows as an error — it appears as a non-blocking warning notice.

## Install

1. **Plugins → Add New → Upload Plugin** → choose `ai-wordpress-os.zip` → **Install Now**.
2. **Activate.** Activation runs environment checks, creates the four database tables (`wp_ai_os_audit_logs`, `wp_ai_os_tool_executions`, `wp_ai_os_approvals`, `wp_ai_os_api_keys`), writes default settings (Safe mode), schedules the daily maintenance cron.
3. The **onboarding wizard** opens automatically on your next dashboard visit.

## Onboarding

| Step | What happens |
|---|---|
| 1. Security mode | **Safe** (recommended: AI reads + safe writes; sensitive/destructive require approval), **Balanced** (level 2 auto, 3+ approval), **Advanced** (level 3 auto — development sites only) |
| 2. API key (optional) | Issues an `aios_…` key shown exactly once. Bound to your user, level 1 max, revocable any time |
| 3. First site scan | Builds the structured site knowledge map agents use |

You can re-run setup any time under **AI OS → Onboarding**, or change everything later in **AI OS → Settings**.

## Verifying the install

```bash
wp ai-os status
# AI WordPress OS 1.0.0
# Security mode:  safe
# MCP endpoint:   https://yoursite.com/wp-json/ai-os/v1/mcp
# Tools:          31 available / 31 registered
# Migrations:     up to date
# Environment:    all optional capabilities available
```

If an optional extension is missing, the last line instead lists exactly what is degraded, e.g. `Environment: 1 degraded capability/ies (never fatal): - The "mbstring" PHP extension is not loaded. ...`.

Or check **AI OS → Dashboard** in wp-admin: mode, tool counts, DB state and MCP endpoint are on the status cards.

## Updating

Upload the new ZIP over the existing plugin. Database changes ship as versioned migrations and run automatically; `wp ai-os migrate` forces them if needed. Your settings, API keys and audit history persist.

## Uninstall

Deactivation keeps all data. **Deleting** the plugin removes transients and cron events always; audit logs, approvals, API keys and settings are only removed when "Remove all AI OS data on uninstall" is enabled in Settings. Otherwise tables persist for a future reinstall (they contain no secrets — keys are hashes, arguments are redacted).

## Troubleshooting installation

| Symptom | Fix |
|---|---|
| "requires PHP 8.2+" notice | Upgrade PHP; the plugin refuses to boot on older stacks rather than fatal |
| Activation shows a migration error | Run `wp ai-os migrate`; the error message names the failing version |
| MCP returns 401 | Authenticate (Application Password or X-AI-OS-Key header) — see MCP-SETUP.md |
| MCP returns 400 https required | Enable TLS, or (local dev only) disable "Require HTTPS" in Settings |
