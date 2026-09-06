# Tool Catalog (Phase 1)

All tools are exposed via MCP `tools/list` and the REST `/tools` endpoint. Every tool declares: name, description, category, input schema (JSON Schema subset), output schema, required permission level, risk level, confirmation policy, integration, version and availability condition. Third parties can append tools via the `ai_os_register_tool` filter (see API.md).

Risk levels: **0 READ · 1 SAFE WRITE · 2 SENSITIVE · 3 DESTRUCTIVE · 4 DEPLOYMENT**.

## site

| Tool | Risk | Approval | Description |
|---|---|---|---|
| `site.get_info` | 0 | — | Site title/description/URL, language, timezone, theme, WP+PHP versions, content counts |
| `site.get_health` | 0 | — | WordPress Site Health checks and issues |
| `site.get_environment` | 0 | — | PHP version/extensions, database version, debug flags, HTTPS, cron + cache setup (no secrets) |

## content

| Tool | Risk | Approval | Description |
|---|---|---|---|
| `content.list_posts` | 0 | — | List posts (status filter, search, ordering, pagination) |
| `content.get_post` | 1* | — | Single post with content (untrusted-wrapped), terms, metadata |
| `content.create_post` | 1 | — | Create post (title, content, excerpt, slug, status; publishing degrades to pending without the publish cap) |
| `content.update_post` | 1 | — | Partial update of an existing post |
| `content.delete_post` | 2 | ✅ required | Move post to **trash** (restorable; permanent deletion is not offered in Phase 1) |
| `content.list_pages` | 0 | — | List pages |
| `content.get_page` | 0 | — | Single page incl. template |
| `content.create_page` | 1 | — | Create page (incl. template assignment) |
| `content.update_page` | 1 | — | Partial update of an existing page |

\* `content.get_post` is risk 0 (read); drafting/publishing rules apply only to writes.

## media

| Tool | Risk | Approval | Description |
|---|---|---|---|
| `media.list` | 0 | — | List media (mime filter, search, pagination) |
| `media.get` | 0 | — | One media item: URL, dimensions, alt text, metadata |
| `media.upload` | 1 | — | Upload from **base64 content** (URL sideload is intentionally not offered — SSRF defense), extension allowlist, 20 MB cap, alt text |
| `media.update` | 1 | — | Update title/caption/description/alt text |
| `media.delete` | 3 | ✅ required | Permanently delete attachment + files |

## theme (read-only in Phase 1)

| Tool | Risk | Approval | Description |
|---|---|---|---|
| `theme.get_info` | 0 | — | Active theme details, features, template list |
| `theme.list_files` | 0 | — | Inspectable files of a theme (bounded recursive scan) |
| `theme.read_file` | 0 | — | Read one theme file — secret-redacted, untrusted-wrapped, 512 KB cap, protected-list enforced |

File **writing** (`theme.write_file`, etc.) arrives in Phase 2 together with snapshots, diffs and rollback. Nothing pretends otherwise.

## plugin

| Tool | Risk | Approval | Description |
|---|---|---|---|
| `plugin.list` | 0 | — | Installed plugins with versions and active/network status |

`plugin.activate/install/update` ship in Phase 2 behind explicit risk gates.

## users

| Tool | Risk | Approval | Description |
|---|---|---|---|
| `users.list` | 0 | — | Id, login, name, roles (emails only with the `list_users` capability) |
| `users.get` | 0 | — | One account (own profile readable; password hashes never exposed) |

User create/update/delete do not exist in Phase 1, and the executor blocklist refuses them even if a third-party tool tried to register them.

## menus

| Tool | Risk | Approval | Description |
|---|---|---|---|
| `menus.list` | 0 | — | Navigation menus with locations and counts |
| `menus.get` | 0 | — | Full item tree of one menu |

## system

| Tool | Risk | Approval | Description |
|---|---|---|---|
| `system.cron.list` | 0 | — | Scheduled WP-Cron events (args redacted) |
| `system.cache.flush` | 2 | ✅ required | Flush object cache + AI OS transients |
| `system.get_options_subset` | 0 | — | Fixed allowlist of public options (identity, permalinks, sizing) — never arbitrary options |

## logs

| Tool | Risk | Approval | Description |
|---|---|---|---|
| `logs.list` | 0 | — | Query the audit log (filters: user, tool, status, risk, date) |
| `logs.get` | 0 | — | One audit entry in full (redacted) |

Both require `manage_options` or `ai_os_approve`.

## context

| Tool | Risk | Approval | Description |
|---|---|---|---|
| `context.get_site_map` | 0 | — | The structured site knowledge tree: WordPress meta, theme, plugins, integrations detected, content types, taxonomies, counts, menus, REST namespaces, shortcodes |

## MCP resources & prompts

Resources: `aios://site/context`, `aios://security/permissions`.
Prompts: `site-audit`, `redesign-plan`.
