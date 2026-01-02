# Creating MCP Services with Tiknix

Tiknix acts as an **MCP Gateway/Proxy** that aggregates tools from multiple backend MCP servers into a single endpoint. This guide explains how to create and register MCP services.

## Architecture Overview

```
Claude Code ──► Tiknix Gateway ──► Backend MCP Servers
                    │
                    ├── Built-in Tiknix tools (tiknix:*)
                    ├── Your Custom Server (myserver:*)
                    ├── Shopify MCP (shopify:*)
                    └── GitHub MCP (github:*)
```

**Benefits:**
- Single endpoint configuration for AI clients
- Centralized authentication via API keys
- Per-user access control to specific backends
- Tool namespacing to avoid collisions (`server:tool` format)
- Usage logging and analytics

---

## Option 1: Add Built-in Tools to Tiknix

The simplest approach - add tools directly to the Tiknix MCP controller.

### Step 1: Define the Tool Schema

Edit `controls/Mcp.php` and add your tool to the `$tools` array:

```php
private array $tools = [
    // ... existing tools ...

    'my_tool' => [
        'description' => 'Describe what this tool does. Be specific for AI understanding.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'param1' => [
                    'type' => 'string',
                    'description' => 'What this parameter does'
                ],
                'param2' => [
                    'type' => 'integer',
                    'description' => 'Optional number parameter'
                ]
            ],
            'required' => ['param1']  // List required params
        ]
    ]
];
```

### Step 2: Add the Handler

Add a case to `executeTool()` and implement the handler method:

```php
private function executeTool(string $name, array $args): string {
    switch ($name) {
        // ... existing cases ...

        case 'my_tool':
            return $this->toolMyTool($args);
    }
}

/**
 * My tool - does something useful
 */
private function toolMyTool(array $args): string {
    $param1 = $args['param1'] ?? '';
    $param2 = $args['param2'] ?? 0;

    if (empty($param1)) {
        throw new \Exception("param1 is required");
    }

    // Your logic here
    $result = "Processed: {$param1}";

    // Return string or JSON
    return json_encode([
        'success' => true,
        'result' => $result
    ], JSON_PRETTY_PRINT);
}
```

### Step 3: Test

```bash
curl -X POST https://tiknix.com/mcp/message \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"tiknix:my_tool","arguments":{"param1":"test"}}}'
```

---

## Option 2: Create a Standalone MCP Server

Create your own MCP server and register it with Tiknix as a backend.

### Step 1: Create MCP Endpoint

Create a new controller (e.g., `controls/MyMcp.php`):

```php
<?php
namespace app;

use \Flight as Flight;

class MyMcp extends BaseControls\Control {

    private array $tools = [
        'do_something' => [
            'description' => 'Does something useful',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'input' => ['type' => 'string', 'description' => 'Input value']
                ],
                'required' => ['input']
            ]
        ]
    ];

    public function __construct() {
        $this->logger = Flight::get('log');
    }

    /**
     * MCP message endpoint
     * POST /mymcp/message
     */
    public function message($params = null): void {
        header('Content-Type: application/json');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->sendError(-32600, 'Method not allowed', null);
            return;
        }

        $request = json_decode(file_get_contents('php://input'), true);
        $method = $request['method'] ?? '';
        $id = $request['id'] ?? null;
        $params = $request['params'] ?? [];

        switch ($method) {
            case 'initialize':
                $this->sendResult($id, [
                    'protocolVersion' => '2024-11-05',
                    'capabilities' => ['tools' => ['listChanged' => false]],
                    'serverInfo' => ['name' => 'my-mcp', 'version' => '1.0.0']
                ]);
                break;

            case 'tools/list':
                $toolList = [];
                foreach ($this->tools as $name => $config) {
                    $toolList[] = [
                        'name' => $name,
                        'description' => $config['description'],
                        'inputSchema' => $config['inputSchema']
                    ];
                }
                $this->sendResult($id, ['tools' => $toolList]);
                break;

            case 'tools/call':
                $toolName = $params['name'] ?? '';
                $args = $params['arguments'] ?? [];

                try {
                    $result = $this->executeTool($toolName, $args);
                    $this->sendResult($id, [
                        'content' => [['type' => 'text', 'text' => $result]],
                        'isError' => false
                    ]);
                } catch (\Exception $e) {
                    $this->sendResult($id, [
                        'content' => [['type' => 'text', 'text' => 'Error: ' . $e->getMessage()]],
                        'isError' => true
                    ]);
                }
                break;

            default:
                $this->sendError(-32601, "Method not found: {$method}", $id);
        }
    }

    private function executeTool(string $name, array $args): string {
        switch ($name) {
            case 'do_something':
                return "Result: " . ($args['input'] ?? 'nothing');
            default:
                throw new \Exception("Unknown tool: {$name}");
        }
    }

    private function sendResult($id, $result): void {
        echo json_encode([
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result
        ]);
    }

    private function sendError($code, $message, $id): void {
        echo json_encode([
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => ['code' => $code, 'message' => $message]
        ]);
    }
}
```

### Step 2: Register in MCP Registry

1. Go to **https://tiknix.com/mcp/registry**
2. Click **"Add Server"**
3. Fill in:
   - **Name**: My Custom MCP
   - **Slug**: `my-mcp` (auto-generated)
   - **Endpoint URL**: `/mymcp/message` (or full URL)
   - **Status**: Active
   - **Auth Type**: None (or Bearer if you add auth)
4. Click **"Fetch Tools"** to auto-populate tools
5. Save

### Step 3: Configure Gateway Access

If your backend requires authentication:
1. Edit the server in MCP Registry
2. Under **Gateway/Proxy Settings**:
   - **Backend Auth Header**: `Authorization` (or custom)
   - **Backend Auth Token**: Your backend's API key
   - **Enable Proxy**: Yes

---

## Option 3: Register External MCP Server

Register any external MCP server (Shopify, GitHub, etc.):

1. Go to **https://tiknix.com/mcp/registry**
2. Click **"Add Server"**
3. Enter the external endpoint URL
4. Configure authentication if needed
5. Click **"Fetch Tools"** to discover available tools
6. Save

---

## Tool Schema Reference

### Input Schema Types

```php
'inputSchema' => [
    'type' => 'object',
    'properties' => [
        // String
        'name' => [
            'type' => 'string',
            'description' => 'User name'
        ],

        // Number (integer or float)
        'count' => [
            'type' => 'integer',
            'description' => 'Number of items'
        ],
        'price' => [
            'type' => 'number',
            'description' => 'Price in dollars'
        ],

        // Boolean
        'active' => [
            'type' => 'boolean',
            'description' => 'Is active?'
        ],

        // Enum (limited choices)
        'status' => [
            'type' => 'string',
            'description' => 'Status filter',
            'enum' => ['active', 'inactive', 'all']
        ],

        // Array
        'tags' => [
            'type' => 'array',
            'items' => ['type' => 'string'],
            'description' => 'List of tags'
        ],

        // Object
        'options' => [
            'type' => 'object',
            'properties' => [
                'key' => ['type' => 'string']
            ],
            'description' => 'Additional options'
        ]
    ],
    'required' => ['name', 'count']  // Required parameters
]
```

### Tool Response Format

Tools should return a string. For complex data, use JSON:

```php
// Simple string
return "Hello, World!";

// JSON response
return json_encode([
    'success' => true,
    'data' => $result,
    'count' => count($result)
], JSON_PRETTY_PRINT);
```

### Error Handling

Throw exceptions for errors - they're caught and returned properly:

```php
if (empty($args['required_param'])) {
    throw new \Exception("required_param is required");
}

if (!$this->authMember) {
    throw new \Exception("Authentication required");
}

if ($this->authMember->level > LEVELS['ADMIN']) {
    throw new \Exception("Admin access required");
}
```

---

## API Key Access Control

### Restrict API Key to Specific Servers

When creating an API key at `/apikeys/add`:
1. Under **"Server Access"**, select specific servers
2. Only those servers' tools will be available to this key

### Scopes

- `mcp:*` - Full access to all MCP operations
- `mcp:read` - Read-only (tools/list, but not tools/call)
- `mcp:call` - Can call tools

---

## Testing Your MCP Server

### List Available Tools

```bash
curl -X POST https://tiknix.com/mcp/message \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}}'
```

### Call a Tool

```bash
curl -X POST https://tiknix.com/mcp/message \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "jsonrpc":"2.0",
    "id":2,
    "method":"tools/call",
    "params":{
      "name":"myserver:do_something",
      "arguments":{"input":"test value"}
    }
  }'
```

### Test from Claude Code

Once configured in `.mcp.json`, ask Claude:
- "List available MCP tools"
- "Use myserver:do_something with input 'hello'"

---

## Debugging

### Check Logs

Tool calls are logged to the `mcpusage` table:

```sql
SELECT * FROM mcpusage ORDER BY id DESC LIMIT 10;
```

### Common Issues

1. **Tool not found**: Check the namespacing (`server:tool` format)
2. **Authentication failed**: Verify API key is active and not expired
3. **Backend error**: Check backend server logs and auth token
4. **Fetch Tools fails**: Ensure endpoint returns valid MCP `tools/list` response

---

## MCP Protocol Reference

- **Protocol Version**: `2024-11-05`
- **Transport**: HTTP POST with JSON-RPC 2.0
- **Spec**: https://modelcontextprotocol.io/

### Required Methods

| Method | Description |
|--------|-------------|
| `initialize` | Handshake, returns capabilities |
| `tools/list` | Returns available tools |
| `tools/call` | Executes a tool |

### Response Format

```json
{
  "jsonrpc": "2.0",
  "id": 1,
  "result": {
    "content": [
      {"type": "text", "text": "Tool output here"}
    ],
    "isError": false
  }
}
```
