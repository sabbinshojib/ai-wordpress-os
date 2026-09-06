# AI WordPress OS

**An AI-native operating system for WordPress.**

AI WordPress OS exposes your site to ChatGPT, Claude, Claude Code, Cursor, VS Code AI agents and every other MCP-compatible client — through a **secure, permission-gated, fully audited capability layer**.

> This is **Phase 1 (Foundation)**: the complete security, approval, audit, MCP and tool pipeline, plus site inspection, content and media tools, and a professional admin console. See [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) for the full design and roadmap.

## What it does

Tell your AI client:

> "Audit this website, then create a draft landing page for my SaaS product."

The client connects over MCP, discovers 30+ structured tools, inspects your site (theme, plugins, content types, menus, REST routes), creates the draft — and when it asks for anything **destructive**, the request lands in a **human approval queue** instead of executing. Every action is audited, rate-limited and permission-checked.

```
ChatGPT / Claude / Claude Code / Cursor
        │  JSON-RPC 2.0 (MCP, Streamable HTTP)
        ▼
┌─ AI WordPress OS ───────────────────────────────┐
│  MCP Server → Tool Registry → Abilities → WP    │
│  Permission Engine (5 levels) │ Approval Queue  │
│  Audit Log │ Rate Limits │ Secret Redaction     │
└─────────────────────────────────────────────────┘
```

## Highlights

- **Real MCP server** — `initialize`, `tools/list`, `tools/call`, resources, prompts, ping; protocol `2025-06-18`/`2025-03-26` compatible, stateless Streamable HTTP at `/wp-json/ai-os/v1/mcp`.
- **5-level permission engine** (READ → SAFE WRITE → SENSITIVE → DESTRUCTIVE → DEPLOYMENT) with **Safe / Balanced / Advanced** modes. The AI can never raise its own ceiling.
- **Human approval queue** — destructive actions are queued, never silently executed; TTL, bulk "approve all safe", full decision history.
- **Abilities architecture** — one capability, one implementation, exposed consistently via MCP, REST and the approval queue.
- **Complete audit trail** — every tool call with redacted arguments, risk level, duration, client, approval linkage; filterable activity feed.
- **Security hardening** — path-traversal confinement, escalation blocklist (no admin creation, no role changes, no self-modification), secret redaction in logs and tool output, prompt-injection wrapping of untrusted site content, HTTPS enforcement, rolling rate limits.
- **Two real auth paths** — WordPress Application Passwords (native) and AI OS API keys (hashed at rest, capability-scoped, revocable).
- **Site intelligence** — a structured knowledge map (theme, plugins, integrations, content types, taxonomies, menus, shortcodes, REST namespaces) agents use to understand your site.
- **Professional admin console** — dark developer-console React UI: Dashboard, Site Intelligence, Tools, Approvals, Activity, MCP & Clients, Security, Settings.
- **Zero frontend impact** — nothing loads on ordinary frontend requests.

## Requirements

- WordPress **6.9+**
- PHP **8.2+**
- HTTPS for external AI clients (enforced, with a local-dev exception)

## Installation

1. Install `ai-wordpress-os.zip` via Plugins → Add New → Upload.
2. The onboarding wizard opens automatically: pick a security mode, optionally issue an API key, run the first site scan.
3. Connect your AI client (see [docs/MCP-SETUP.md](docs/MCP-SETUP.md)).

### Connecting Claude Desktop / Claude Code

```json
{
  "mcpServers": {
    "ai-wordpress-os": {
      "type": "http",
      "url": "https://yoursite.com/wp-json/ai-os/v1/mcp",
      "headers": {
        "Authorization": "Basic <base64(user:application-password)>"
      }
    }
  }
}
```

Create the application password under **Users → Profile → Application Passwords**.

## Documentation

| Doc | Contents |
|---|---|
| [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) | Full architecture, DB schema, security model, data flow |
| [docs/INSTALLATION.md](docs/INSTALLATION.md) | Install, activate, onboarding, troubleshooting install issues |
| [docs/MCP-SETUP.md](docs/MCP-SETUP.md) | ChatGPT, Claude, Claude Code, Cursor, VS Code setup |
| [docs/SECURITY.md](docs/SECURITY.md) | Threat model, permission levels, hardening guide |
| [docs/TOOLS.md](docs/TOOLS.md) | Complete Phase 1 tool catalog |
| [docs/API.md](docs/API.md) | REST API + developer extensibility API |
| [docs/TROUBLESHOOTING.md](docs/TROUBLESHOOTING.md) | Common issues and fixes |

## Development

```bash
# Lint all PHP files
php scripts/lint_plugin.sh   # or: find . -name '*.php' -exec php -l {} \;

# Run the test suite (128 tests, no WordPress needed — includes a WP shim)
php tests/run.php

# End-to-end acceptance run (simulates a full MCP client session)
php tests/acceptance.php

# Rebuild the React admin console (requires Node 18+)
cd build/dashboard-src && npm install && npm run build
```

## Phase roadmap

- **Phase 1 (this release)** — foundation: MCP, permissions, approvals, audit, inspection + content/media tools, admin console.
- **Phase 2** — AI developer: file writing engine, snapshots, diffs, rollback, task engine, testing, code generation.
- **Phase 3** — builder intelligence: Elementor, Gutenberg, WooCommerce, ACF, SEO, forms adapters.
- **Phase 4** — multi-agent orchestration, visual browser verification, external sandboxes, external MCP client, Figma import.

No UI exists for unshipped features — no fake buttons.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
