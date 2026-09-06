# MCP Client Setup

The MCP server endpoint is:

```
POST https://yoursite.com/wp-json/ai-os/v1/mcp
```

- Transport: **Streamable HTTP, stateless profile** (no session state required).
- Protocol: JSON-RPC 2.0; supported MCP versions `2025-06-18`, `2025-03-26`, `2024-11-05`.
- Methods: `initialize`, `notifications/initialized`, `ping`, `tools/list`, `tools/call`, `resources/list`, `resources/get`, `prompts/list`, `prompts/get`. Batches (JSON-RPC arrays) are supported.
- `GET` on the endpoint returns 405 (by design for stateless servers).

## Authentication (pick one)

### Option A — WordPress Application Password (recommended)

1. **Users → Profile → Application Passwords** → add one named e.g. "Claude".
2. Build the Basic auth header: `base64(username:xxxx xxxx xxxx xxxx xxxx xxxx)`.
   ```bash
   echo -n "admin:xxxx xxxx xxxx xxxx xxxx xxxx" | base64
   ```
3. Send it as `Authorization: Basic <that-string>` on every request.

### Option B — AI OS API key

Issue one under **AI OS → MCP & Clients** (or during onboarding). Send:

```
X-AI-OS-Key: aios_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

Keys are stored only as SHA-256 hashes, carry a permission ceiling (max level), and can be revoked or rotated at any time.

## Client configuration snippets

### Claude Desktop / Claude Code (JSON config)

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

### Cursor

`Settings → MCP → Add server` (or `.cursor/mcp.json`):

```json
{
  "mcpServers": {
    "ai-wordpress-os": {
      "url": "https://yoursite.com/wp-json/ai-os/v1/mcp",
      "headers": {
        "Authorization": "Basic <base64(user:application-password)>"
      }
    }
  }
}
```

### ChatGPT (connectors / custom tools)

Use a custom connector pointing at the endpoint URL with Basic authentication. ChatGPT negotiates the protocol version automatically during `initialize`.

### VS Code / generic HTTP MCP client

Any client that can POST JSON with custom headers works:

```bash
curl -s https://yoursite.com/wp-json/ai-os/v1/mcp \
  -H 'Content-Type: application/json' \
  -H 'Authorization: Basic <…>' \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18"}}'
```

## Quick smoke test

```bash
# 1. initialize
curl -s $ENDPOINT -H 'Content-Type: application/json' -H "$AUTH" \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18"}}'

# 2. list tools
curl -s $ENDPOINT -H 'Content-Type: application/json' -H "$AUTH" \
  -d '{"jsonrpc":"2.0","id":2,"method":"tools/list"}'

# 3. call a tool
curl -s $ENDPOINT -H 'Content-Type: application/json' -H "$AUTH" \
  -d '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"site.get_info","arguments":{}}}'
```

## Interpreting tool results

- **Success**: `result.isError: false`, payload in `structuredContent` plus a text summary in `content[]`.
- **Approval required**: `result.isError: false` and `structuredContent.status: "approval_required"` with an `approval_id`. **The action has NOT run.** Tell the user an administrator must approve it in **WP Admin → AI OS → Approvals** (or via the REST approvals endpoints). Approvals expire after the configured TTL (default 15 minutes).
- **Tool error**: `result.isError: true` with a structured error `{code, message, type, retryable, context}` — never a stack trace.

## Untrusted-content markers

Post/page content returned by tools is wrapped:

```
<<<AI_OS_UNTRUSTED_SITE_CONTENT
(…reminder text…)
…your page content…
AI_OS_UNTRUSTED_SITE_CONTENT>>>
```

This is a prompt-injection defense: site content is data, never instructions.

## Resources & prompts

Read-only context is also exposed as MCP resources:

- `aios://site/context` — the structured site map
- `aios://security/permissions` — permission levels and the active mode

Prompt templates: `site-audit`, `redesign-plan` (see `prompts/list`).

## Notes

- **HTTPS is enforced** for non-local hosts (Settings → Security).
- Rate limits apply per user (default 120 MCP requests/min, 60 tool executions/min).
- The `wp ai-os status` CLI command prints your endpoint and tool counts.
