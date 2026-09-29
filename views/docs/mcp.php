<div class="container-fluid py-4">
    <div class="row">
        <div class="col-lg-3 col-md-4">
            <?php
            $activeSection = 'mcp';
            $quickLinks = [
                ['href' => '#overview', 'icon' => 'bi-diagram-3', 'text' => 'Architecture Overview'],
                ['href' => '#builtin-tools', 'icon' => 'bi-tools', 'text' => 'Add Built-in Tools'],
                ['href' => '#standalone-server', 'icon' => 'bi-server', 'text' => 'Standalone MCP Server'],
                ['href' => '#external-servers', 'icon' => 'bi-globe', 'text' => 'External Servers'],
                ['href' => '#schema-reference', 'icon' => 'bi-code-square', 'text' => 'Schema Reference'],
                ['href' => '#testing', 'icon' => 'bi-bug', 'text' => 'Testing & Debugging']
            ];
            include __DIR__ . '/partials/sidebar.php';
            ?>
        </div>

        <div class="col-lg-9 col-md-8">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb bg-light px-3 py-2 rounded shadow-sm">
                    <li class="breadcrumb-item"><a href="/">Home</a></li>
                    <li class="breadcrumb-item"><a href="/docs">Documentation</a></li>
                    <li class="breadcrumb-item active" aria-current="page">MCP Development</li>
                </ol>
            </nav>

            <div class="documentation-content bg-white p-4 rounded shadow-sm">
                <h1><i class="bi bi-plug"></i> Creating MCP Services with Tiknix</h1>

                <div class="alert alert-info">
                    <i class="bi bi-info-circle"></i> Tiknix acts as an <strong>MCP Gateway/Proxy</strong> that aggregates tools from multiple backend MCP servers into a single endpoint.
                    <br><a href="https://modelcontextprotocol.io/" target="_blank">Learn more about the Model Context Protocol</a>
                </div>

                <h2 id="overview">Architecture Overview</h2>

                <pre><code>Claude Code ──► Tiknix Gateway ──► Backend MCP Servers
                    │
                    ├── Built-in Tiknix tools (tiknix:*)
                    ├── Your Custom Server (myserver:*)
                    ├── Shopify MCP (shopify:*)
                    └── GitHub MCP (github:*)</code></pre>

                <div class="row mt-4">
                    <div class="col-md-6">
                        <div class="card border-success">
                            <div class="card-header bg-success text-white">
                                <i class="bi bi-check-circle"></i> Benefits
                            </div>
                            <ul class="list-group list-group-flush">
                                <li class="list-group-item">Single endpoint configuration</li>
                                <li class="list-group-item">Centralized API key authentication</li>
                                <li class="list-group-item">Per-user access control</li>
                                <li class="list-group-item">Tool namespacing (<code>server:tool</code>)</li>
                                <li class="list-group-item">Usage logging & analytics</li>
                            </ul>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card border-primary">
                            <div class="card-header bg-primary text-white">
                                <i class="bi bi-gear"></i> Quick Setup
                            </div>
                            <div class="card-body">
                                <ol class="mb-0">
                                    <li>Get API key at <a href="/apikeys">/apikeys</a></li>
                                    <li>Add to <code>.mcp.json</code></li>
                                    <li>Register servers at <a href="/mcp/registry">/mcp/registry</a></li>
                                </ol>
                            </div>
                        </div>
                    </div>
                </div>

                <h2 id="builtin-tools">Option 1: Add Built-in Tools to Tiknix</h2>

                <p>The simplest approach - add tools directly to the Tiknix MCP controller.</p>

                <h3>Step 1: Define the Tool Schema</h3>
                <p>Edit <code>controls/Mcp.php</code> and add your tool to the <code>$tools</code> array:</p>

                <pre><code class="language-php">private array $tools = [
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
];</code></pre>

                <h3>Step 2: Add the Handler</h3>
                <p>Add a case to <code>executeTool()</code> and implement the handler method:</p>

                <pre><code class="language-php">private function executeTool(string $name, array $args): string {
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
}</code></pre>

                <h2 id="standalone-server">Option 2: Create a Standalone MCP Server</h2>

                <p>Create your own MCP server and register it with Tiknix as a backend.</p>

                <h3>Step 1: Create MCP Endpoint</h3>
                <p>Create a new controller (e.g., <code>controls/MyMcp.php</code>):</p>

                <pre><code class="language-php">&lt;?php
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
     * MCP message endpoint - POST /mymcp/message
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
        echo json_encode(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
    }

    private function sendError($code, $message, $id): void {
        echo json_encode(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]]);
    }
}</code></pre>

                <h3>Step 2: Register in MCP Registry</h3>
                <ol>
                    <li>Go to <a href="/mcp/registry">/mcp/registry</a></li>
                    <li>Click <strong>"Add Server"</strong></li>
                    <li>Fill in: Name, Slug, Endpoint URL (<code>/mymcp/message</code>)</li>
                    <li>Click <strong>"Fetch Tools"</strong> to auto-populate</li>
                    <li>Save</li>
                </ol>

                <h2 id="external-servers">Option 3: Register External MCP Server</h2>

                <p>Register any external MCP server (Shopify, GitHub, etc.):</p>

                <ol>
                    <li>Go to <a href="/mcp/registry">/mcp/registry</a></li>
                    <li>Click <strong>"Add Server"</strong></li>
                    <li>Enter the external endpoint URL</li>
                    <li>Configure authentication under <strong>Gateway/Proxy Settings</strong> if needed</li>
                    <li>Click <strong>"Fetch Tools"</strong> to discover available tools</li>
                    <li>Save</li>
                </ol>

                <div class="alert alert-warning">
                    <i class="bi bi-shield-lock"></i> <strong>Gateway Auth:</strong> If the backend requires authentication, set the <strong>Backend Auth Token</strong> in Gateway/Proxy Settings. Tiknix will forward this token with all proxied requests.
                </div>

                <h2 id="schema-reference">Tool Schema Reference</h2>

                <h3>Input Schema Types</h3>

                <div class="table-responsive">
                    <table class="table table-bordered">
                        <thead class="table-light">
                            <tr>
                                <th>Type</th>
                                <th>Example</th>
                                <th>Description</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><code>string</code></td>
                                <td><code>'type' => 'string'</code></td>
                                <td>Text value</td>
                            </tr>
                            <tr>
                                <td><code>integer</code></td>
                                <td><code>'type' => 'integer'</code></td>
                                <td>Whole number</td>
                            </tr>
                            <tr>
                                <td><code>number</code></td>
                                <td><code>'type' => 'number'</code></td>
                                <td>Integer or float</td>
                            </tr>
                            <tr>
                                <td><code>boolean</code></td>
                                <td><code>'type' => 'boolean'</code></td>
                                <td>True/false</td>
                            </tr>
                            <tr>
                                <td><code>enum</code></td>
                                <td><code>'enum' => ['a', 'b', 'c']</code></td>
                                <td>Limited choices</td>
                            </tr>
                            <tr>
                                <td><code>array</code></td>
                                <td><code>'type' => 'array', 'items' => ['type' => 'string']</code></td>
                                <td>List of items</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <h3>Tool Response Format</h3>
                <pre><code class="language-php">// Simple string
return "Hello, World!";

// JSON response (recommended for complex data)
return json_encode([
    'success' => true,
    'data' => $result,
    'count' => count($result)
], JSON_PRETTY_PRINT);</code></pre>

                <h3>Error Handling</h3>
                <pre><code class="language-php">// Throw exceptions - they're caught and returned properly
if (empty($args['required_param'])) {
    throw new \Exception("required_param is required");
}

// Check authentication
if (!$this->authMember) {
    throw new \Exception("Authentication required");
}

// Check admin level
if ($this->authMember->level > LEVELS['ADMIN']) {
    throw new \Exception("Admin access required");
}</code></pre>

                <h2 id="testing">Testing & Debugging</h2>

                <h3>List Available Tools</h3>
                <pre><code class="language-bash">curl -X POST https://tiknix.com/mcp/message \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}}'</code></pre>

                <h3>Call a Tool</h3>
                <pre><code class="language-bash">curl -X POST https://tiknix.com/mcp/message \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "jsonrpc":"2.0",
    "id":2,
    "method":"tools/call",
    "params":{
      "name":"tiknix:hello",
      "arguments":{"name":"World"}
    }
  }'</code></pre>

                <h3>Check Usage Logs</h3>
                <p>Tool calls are logged to the <code>mcpusage</code> table:</p>
                <pre><code class="language-sql">SELECT * FROM mcpusage ORDER BY id DESC LIMIT 10;</code></pre>

                <h3>Common Issues</h3>
                <div class="table-responsive">
                    <table class="table table-bordered">
                        <thead class="table-light">
                            <tr>
                                <th>Issue</th>
                                <th>Solution</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>Tool not found</td>
                                <td>Check namespacing (<code>server:tool</code> format)</td>
                            </tr>
                            <tr>
                                <td>Authentication failed</td>
                                <td>Verify API key is active and not expired</td>
                            </tr>
                            <tr>
                                <td>Backend error</td>
                                <td>Check backend server logs and auth token</td>
                            </tr>
                            <tr>
                                <td>Fetch Tools fails</td>
                                <td>Ensure endpoint returns valid MCP <code>tools/list</code></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="alert alert-success mt-4">
                    <h5><i class="bi bi-lightbulb"></i> Next Steps</h5>
                    <ul class="mb-0">
                        <li><a href="/apikeys">Create an API Key</a></li>
                        <li><a href="/mcp/registry">View MCP Registry</a></li>
                        <li><a href="/mcp">Test MCP Endpoint</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.documentation-content {
    font-size: 16px;
    line-height: 1.8;
}

.documentation-content h1 {
    color: #2c3e50;
    border-bottom: 3px solid #007bff;
    padding-bottom: 15px;
    margin-bottom: 30px;
}

.documentation-content h2 {
    color: #34495e;
    margin-top: 40px;
    margin-bottom: 20px;
    padding-left: 15px;
    border-left: 5px solid #007bff;
}

.documentation-content h3 {
    color: #495057;
    margin-top: 25px;
    margin-bottom: 15px;
}

.documentation-content pre {
    background-color: #f8f9fa;
    border: 1px solid #e9ecef;
    border-radius: 8px;
    padding: 20px;
    overflow-x: auto;
    margin: 20px 0;
}

.documentation-content code {
    background-color: #fff3cd;
    padding: 3px 8px;
    border-radius: 4px;
    color: #d63384;
}

.documentation-content pre code {
    background-color: transparent;
    padding: 0;
    color: #212529;
}

.table code {
    background-color: #f8f9fa;
    color: #d63384;
}
</style>
