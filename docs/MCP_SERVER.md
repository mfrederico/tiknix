# MCP Server Integration

This document explains how to connect Claude Code (or any MCP client) to the Tiknix MCP server.

## Overview

MCP (Model Context Protocol) is an open protocol that allows AI assistants to interact with external tools and data sources. Tiknix includes a built-in MCP server that exposes application functionality as tools.

**Endpoint**: `POST /mcp/message`

## Available Tools

| Tool | Description |
|------|-------------|
| `hello` | Returns a friendly greeting (test connectivity) |
| `echo` | Echoes back the provided message |
| `get_time` | Returns current server date/time with timezone support |
| `add_numbers` | Adds two numbers together |
| `list_users` | Lists system users (requires admin access) |

## Connecting Claude Code

### Quick Setup (Recommended)

The easiest way to get your configuration is to use the auto-config endpoint:

```bash
# Get your personalized config with API token
curl -u username:password https://your-domain.com/mcp/config
```

This returns a ready-to-use JSON configuration with your domain and API token.

### Generate API Token

If you need to generate a new API token:

```bash
# Generate new token via API
curl -X POST -u username:password https://your-domain.com/mcp/token
```

Or programmatically:

```php
use \RedBeanPHP\R as R;

$member = R::load('member', $memberId);
$member->api_token = bin2hex(random_bytes(32));
R::store($member);

echo "Your API token: {$member->api_token}";
```

### Configure Claude Code

Add the MCP server to your Claude Code settings. Edit `~/.claude/settings.json`:

```json
{
  "mcpServers": {
    "tiknix": {
      "type": "http",
      "url": "https://your-domain.com/mcp/message",
      "headers": {
        "Authorization": "Bearer YOUR_API_TOKEN"
      }
    }
  }
}
```

Or use Basic Auth with username/password:

```json
{
  "mcpServers": {
    "tiknix": {
      "type": "http",
      "url": "https://your-domain.com/mcp/message",
      "headers": {
        "Authorization": "Basic BASE64_ENCODED_USERNAME_PASSWORD"
      }
    }
  }
}
```

To encode credentials for Basic Auth:
```bash
echo -n "username:password" | base64
```

### 3. Project-Level Configuration

For project-specific MCP servers, create `.mcp.json` in your project root:

```json
{
  "mcpServers": {
    "tiknix": {
      "type": "http",
      "url": "https://your-domain.com/mcp/message",
      "headers": {
        "X-MCP-Token": "YOUR_API_TOKEN"
      }
    }
  }
}
```

## Authentication Methods

The MCP server supports three authentication methods (tried in order):

### 1. Basic Auth (Recommended for development)
```
Authorization: Basic base64(username:password)
```

Uses your existing login credentials.

### 2. Bearer Token
```
Authorization: Bearer <api_token>
```

Uses the `api_token` field from the member table.

### 3. Custom Header
```
X-MCP-Token: <api_token>
```

Alternative to Bearer token for environments where Authorization header is problematic.

## Testing the Connection

### Health Check

```bash
curl https://your-domain.com/mcp/health
```

Expected response:
```json
{
  "status": "healthy",
  "server": "tiknix-mcp",
  "version": "1.0.0",
  "protocol": "2024-11-05",
  "timestamp": "2024-01-15T10:30:00+00:00"
}
```

### Test Tool Call

```bash
curl -X POST https://your-domain.com/mcp/message \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -d '{
    "jsonrpc": "2.0",
    "id": 1,
    "method": "tools/call",
    "params": {
      "name": "hello",
      "arguments": {"name": "Claude"}
    }
  }'
```

Expected response:
```json
{
  "jsonrpc": "2.0",
  "id": 1,
  "result": {
    "content": [
      {
        "type": "text",
        "text": "Hello, Claude! Welcome to the Tiknix MCP server."
      }
    ],
    "isError": false
  }
}
```

### List Available Tools

```bash
curl -X POST https://your-domain.com/mcp/message \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -d '{
    "jsonrpc": "2.0",
    "id": 1,
    "method": "tools/list",
    "params": {}
  }'
```

## Adding Custom Tools

To add your own tools, edit `controls/Mcp.php`:

1. Add the tool definition to the `$tools` array:

```php
private array $tools = [
    // ... existing tools ...

    'my_custom_tool' => [
        'description' => 'Description of what this tool does',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'param1' => [
                    'type' => 'string',
                    'description' => 'First parameter'
                ],
                'param2' => [
                    'type' => 'integer',
                    'description' => 'Second parameter'
                ]
            ],
            'required' => ['param1']
        ]
    ]
];
```

2. Add the case in `executeTool()`:

```php
private function executeTool(string $name, array $args): string {
    switch ($name) {
        // ... existing cases ...

        case 'my_custom_tool':
            return $this->toolMyCustomTool($args);
    }
}
```

3. Implement the tool method:

```php
private function toolMyCustomTool(array $args): string {
    $param1 = $args['param1'];
    $param2 = $args['param2'] ?? 0;

    // Your logic here

    return json_encode([
        'result' => 'success',
        'data' => $someData
    ], JSON_PRETTY_PRINT);
}
```

## Security Considerations

1. **Always use HTTPS** in production
2. **Rotate API tokens** periodically
3. **Check permissions** in tools that access sensitive data (see `list_users` example)
4. **Log tool calls** for audit purposes (already implemented)
5. **Rate limit** if exposing to untrusted clients

## Permissions Setup

### Build Mode (Development)

In build mode (`build_mode = true` in config), permissions are auto-created when endpoints are first accessed. The MCP endpoints will be created with admin-level permissions by default.

### Manual Setup

To allow public API access, update the permissions via admin panel or directly:

```php
use \RedBeanPHP\R as R;

// Make MCP message endpoint public (level 101 = PUBLIC)
$perm = R::findOne('authcontrol', 'control = ? AND method = ?', ['mcp', 'message']);
if (!$perm) {
    $perm = R::dispense('authcontrol');
    $perm->control = 'mcp';
    $perm->method = 'message';
}
$perm->level = 101; // PUBLIC
$perm->description = 'MCP JSON-RPC endpoint';
R::store($perm);

// Same for health check
$health = R::findOne('authcontrol', 'control = ? AND method = ?', ['mcp', 'health']);
if (!$health) {
    $health = R::dispense('authcontrol');
    $health->control = 'mcp';
    $health->method = 'health';
}
$health->level = 101;
$health->description = 'MCP health check';
R::store($health);

// Clear permission cache
\app\PermissionCache::clear();
```

## Troubleshooting

### 401 Unauthorized
- Check that your token is correct
- Verify the Authorization header format
- Ensure the member has an `api_token` set

### 404 Not Found
- Ensure the `/mcp/message` route is accessible
- Check that `controls/Mcp.php` exists
- Verify permissions allow public access to the endpoint

### Tools not appearing in Claude Code
- Restart Claude Code after changing `.mcp.json`
- Check the health endpoint first
- Verify JSON syntax in configuration files

## Protocol Reference

This implementation follows the [Model Context Protocol specification](https://modelcontextprotocol.io/).

Supported methods:
- `initialize` - Handshake and capability exchange
- `tools/list` - List available tools
- `tools/call` - Execute a tool
- `ping` - Connection test
