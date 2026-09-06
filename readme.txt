=== AI WordPress OS ===
Contributors: aiwordpressos
Tags: ai, mcp, claude, chatgpt, automation, agent
Requires at least: 6.9
Tested up to: 6.9
Requires PHP: 8.2
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

An AI-native operating system for WordPress: expose your site securely to ChatGPT, Claude, Claude Code, Cursor and every MCP-compatible client.

== Description ==

AI WordPress OS turns your WordPress site into an AI-operable platform. External AI systems connect over the Model Context Protocol (MCP) and work on your site through a **secure, permission-gated, fully audited capability layer**.

= What it provides =

* A real MCP server (JSON-RPC 2.0, Streamable HTTP) at /wp-json/ai-os/v1/mcp
* 30+ structured tools: site inspection, posts/pages CRUD, media, theme file reading, plugin inventory, users, menus, cron, audit logs, a structured site knowledge map
* A five-level permission engine (READ / SAFE WRITE / SENSITIVE / DESTRUCTIVE / DEPLOYMENT) with Safe, Balanced and Advanced modes
* A human approval queue: destructive AI actions never execute silently
* A complete audit trail with secret redaction, rate limits, and an escalation blocklist (the AI can never create admins, change roles, edit its own security settings or touch its audit trail)
* Two authentication paths: WordPress Application Passwords and capability-scoped AI OS API keys (hashed at rest, revocable)
* A professional dark-themed admin console (Dashboard, Site Intelligence, Tools, Approvals, Activity, MCP & Clients, Security, Settings)
* WP-CLI commands: wp ai-os status | migrate | tools

This is Phase 1 (Foundation). Theme/plugin file *writing*, rollback, long-running tasks and builder adapters (Elementor, WooCommerce, ACF, SEO) arrive in later phases — the plugin never ships buttons for unimplemented features.

== Installation ==

1. Upload the plugin zip via Plugins → Add New → Upload Plugin.
2. Activate. Requires PHP 8.2+ and WordPress 6.9+.
3. The onboarding wizard opens automatically: choose a security mode, optionally create an API key, run the first site scan.
4. Create a WordPress Application Password (Users → Profile) and add the MCP endpoint to your AI client. See docs/MCP-SETUP.md.

== Frequently Asked Questions ==

= Is it safe to let an AI work on my site? =

AI WordPress OS was designed security-first: every tool call passes validation, a permission-level check against the active security mode, per-object capability checks, rate limits and the audit log. Destructive actions require explicit human approval. Secrets are never exposed to the model, and site content is returned wrapped in explicit "untrusted data" markers to blunt prompt injection.

= Which AI clients work with it? =

Any MCP client that supports the Streamable HTTP transport: ChatGPT (connectors), Claude Desktop, Claude Code, Cursor, VS Code AI extensions, and custom integrations.

= Does it slow down my site? =

No. Nothing loads on frontend requests; the plugin only boots its real machinery in admin, REST and CLI contexts, and the site context map is cached in a transient.

== Changelog ==

= 1.0.0 =
* Phase 1 Foundation: MCP server, abilities + tool registries, permission engine (5 levels, 3 modes), approval queue with TTL and bulk actions, audit log with redaction, API keys (hashed at rest), site context engine, safe file inspection, content/media tools, React admin console, WP-CLI commands, onboarding wizard, 128 automated tests.
