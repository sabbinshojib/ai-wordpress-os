# Troubleshooting

## The plugin will not activate

| Symptom | Cause / Fix |
|---|---|
| "requires PHP 8.2+ and WordPress 6.9+" notice | The environment guard refused to boot. Upgrade the stack; the plugin deactivates itself rather than fatal. |
| Activation shows a migration error | Run `wp ai-os migrate`. The notice names the failing migration version. |
| White screen after activation | Check `WP_DEBUG` — the kernel fails soft (admin notice) rather than taking the site down; a boot error is shown to admins. |

## MCP / clients

| Symptom | Cause / Fix |
|---|---|
| 401 on the MCP endpoint | Missing/invalid credentials. Use an Application Password (Basic) or `X-AI-OS-Key` header. Two-Factor-protected accounts must use application passwords, not the main password. |
| 400 "requires HTTPS" | Enable TLS, or — local development only — disable **Require HTTPS** in AI OS → Settings. |
| 403 "not permitted to use AI WordPress OS" | The WordPress user lacks relevant capabilities or `ai_os_use`. Editors/authors work by default; subscribers do not. |
| 429 rate limit | Defaults: 120 MCP requests/min, 60 tool executions/min per user. Raise in Settings if legitimate. |
| Client says "Method not found" | The MCP method is not one of: initialize, ping, tools/list, tools/call, resources/list, resources/get, prompts/list, prompts/get (plus notifications). |
| Tool returns `tool.unavailable` | The tool's availability condition failed (e.g. media tools on an unusual host). Check `wp ai-os tools --available`. |
| Tool returns `permission.level_denied` | Expected under Safe mode for level ≥ 2 tools when auto-approval paths are exhausted — the intended route is the approval queue, not a ceiling change. If you want level-2 actions to run unattended, switch to Balanced mode. |

## Approvals

| Symptom | Cause / Fix |
|---|---|
| Approval vanished from the queue | Approvals expire (default 15 min). The AI client is told the expiry time; it can re-request. |
| "already decided" on approve/reject | Another admin claimed it first (claims are atomic). |
| Approved action failed to execute | The stored arguments are re-validated at decision time; site state may have changed (e.g. post already trashed). The result payload explains the failure. |
| Bulk "approve all safe" approved nothing | It only touches pending requests at or below your permission ceiling. |

## Admin console

| Symptom | Cause / Fix |
|---|---|
| Console shows "Request failed (403)" | REST nonce expired — reload the page. |
| Context map looks stale | Click **Refresh** on Site Intelligence (rebuilds) or wait for the 15-min cache TTL. Cache invalidates automatically on theme switch / plugin changes. |
| Console blank after a plugin update | Hard-refresh; the JS bundle is version-busted by cache. |

## Data & maintenance

| Symptom | Cause / Fix |
|---|---|
| Audit log growing | Retention purge runs daily (default 90 days). Lower it in Settings. |
| API key no longer works | Keys expire and revoke; check `last_used_at` in MCP & Clients. Rotate if compromised — old key is revoked atomically. |
| Tables missing after a manual DB restore | `wp ai-os migrate` recreates them (migrations are idempotent). |

## Debugging aids

- `WP_DEBUG` + `WP_DEBUG_LOG`: internal errors (including tool exceptions) are logged with the `[AI WordPress OS/…]` prefix; external clients only ever see structured errors.
- `wp ai-os status` prints endpoint/mode/tool counts.
- **AI OS → Activity** with status/risk filters shows blocked and failed calls.
