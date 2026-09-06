# API Reference

## REST API (namespace `ai-os/v1`)

Base: `/wp-json/ai-os/v1/`. All endpoints validate + sanitize args and enforce permission callbacks.

| Route | Method | Permission | Purpose |
|---|---|---|---|
| `/site` | GET | `ai_os` read | Condensed site card |
| `/status` | GET | `ai_os` read | Dashboard health: mode, tool counts, activity, audit stats, pending approvals, MCP endpoint |
| `/context` | GET | `ai_os` read | Structured site map (`?refresh=1` rebuilds) |
| `/tools` | GET | `ai_os` read | Full tool catalog (`?available=true` filters) |
| `/tools/execute` | POST | `ai_os` use + per-tool engine checks | Execute a tool: `{tool, arguments}` → the standard executor pipeline |
| `/mcp` | POST | authenticator-first | MCP JSON-RPC endpoint (GET → 405) |
| `/approvals` | GET | `ai_os_approve` | Pending queue + decision history |
| `/approvals/{id}/approve` | POST | `ai_os_approve` + nonce | Claim + execute an approved action |
| `/approvals/{id}/reject` | POST | `ai_os_approve` + nonce | Reject a request |
| `/approvals/approve-safe` | POST | `ai_os_approve` + nonce | Bulk-approve everything ≤ your ceiling |
| `/logs` | GET | `ai_os_approve` | Audit log with filters (user_id, tool, status, risk, since, search, limit, page) |
| `/settings` | GET/POST | `manage_options` (+nonce on POST) | Read/update settings |
| `/keys` | GET/POST | `manage_options` (+nonce on POST) | List/issue API keys (raw key returned exactly once) |
| `/keys/{id}/revoke` | POST | `manage_options` + nonce | Revoke a key |

Permission capabilities: `ai_os_use`, `ai_os_approve` (granted to admins via the engine's defaults; editors can read/use level-1 tools through their WP caps).

Example:

```bash
curl -s https://yoursite.com/wp-json/ai-os/v1/status \
  -H 'Authorization: Basic <…>'
```

## Developer APIs (extensibility, spec §66)

### Register an MCP tool

```php
add_filter( 'ai_os_register_tool', function ( array $tools ): array {
    $tools[] = AIOS\Tools\Tool::make( array(
        'name'            => 'myorg.tell_joke',
        'description'     => 'Stores a joke as a draft post.',
        'category'        => 'myorg',
        'inputSchema'     => array(
            'type'       => 'object',
            'properties' => array( 'joke' => array( 'type' => 'string' ) ),
            'required'   => array( 'joke' ),
        ),
        'riskLevel'       => 1,
        'permissionLevel' => 1,
        'confirmation'    => 'never',   // or 'approval'
    ) );
    return $tools;
} );
```

### Register the backing ability (business logic)

```php
add_filter( 'ai_os_register_ability', function ( array $abilities ): array {
    $abilities[] = AIOS\Abilities\Ability::make( array(
        'name'        => 'myorg.tell_joke',
        'description' => 'Stores a joke as a draft post.',
        'level'       => 1,
        'inputSchema' => array( 'type' => 'object', 'required' => array( 'joke' ) ),
        'permissionCallback' => fn( $user, array $args ): bool => $user->has_cap( 'edit_posts' ),
        'executeCallback'    => fn( array $args, $user ): AIOS\Abilities\AbilityResult =>
            AIOS\Abilities\AbilityResult::success( array( 'stored' => true ) ),
    ) );
    return $abilities;
} );
```

The tool and the ability share the name; the executor routes one to the other. Registering only a tool yields `tool.ability_missing` at call time.

### Other extension points

| Hook | Purpose |
|---|---|
| `ai_os_register_tool` / `ai_os_register_ability` | Append/replace catalog entries (higher version wins) |
| `ai_os_requires_approval` | Force stricter approval rules (cannot weaken the engine's) |
| `ai_os_protected_files` | Extend the always-protected path list |
| `ai_os_user_level_grants` | Adjust per-user grant ceilings (lowering-only at read time) |
| `ai_os_container_build` | Replace core services with decorated implementations |
| `ai_os_tools_booted` | Inspect the sealed registry (diagnostics) |

### Programmatic execution

```php
$executor = ai_wp_os()->container()->get( AIOS\Tools\ToolExecutor::class );
$result   = $executor->execute( 'content.create_post', array( 'title' => 'Hi' ), $user, 'my-integration' );
$result->ok();              // bool
$result->data();            // array payload
$result->error()?->toArray();   // structured error
$result->isApprovalRequest();  // approval queued?
```

## WP-CLI

```
wp ai-os status      # version, mode, endpoint, tools, migration state
wp ai-os migrate     # run pending migrations
wp ai-os tools [--available]   # list the catalog
```

## Data model (custom tables)

`{prefix}ai_os_`: `audit_logs`, `tool_executions`, `approvals`, `api_keys` — see ARCHITECTURE.md §4 for column-level detail.

## Internal services (for integration authors)

| Service | Class |
|---|---|
| DI container | `AIOS\Core\Container` (bind/instance/get) |
| Settings | `AIOS\Settings\Settings` |
| Permissions | `AIOS\Security\PermissionEngine` |
| Audit | `AIOS\Audit\AuditLogger` |
| Context | `AIOS\Context\ContextEngine` |
| File reads | `AIOS\Files\FileReader` (forTheme/forRoot) |
| Path guard | `AIOS\Security\PathGuard` |
