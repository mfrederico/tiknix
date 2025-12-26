<?php
/**
 * MCP Gateway/Proxy Controller
 *
 * Tiknix acts as an MCP Gateway, providing a single endpoint that:
 * 1. Aggregates tools from multiple backend MCP servers
 * 2. Routes tool calls to the appropriate backend
 * 3. Handles authentication and access control
 * 4. Logs all usage for analytics and auditing
 *
 * Endpoint: POST /mcp/message
 *
 * ============================================================================
 * GATEWAY ARCHITECTURE
 * ============================================================================
 *
 *   Claude Code (single config) ──▶ Tiknix Gateway ──▶ Backend MCP Servers
 *                                     │
 *                                     ├── Built-in Tiknix tools
 *                                     ├── Shopify MCP
 *                                     ├── GitHub MCP
 *                                     └── Custom MCP servers
 *
 * Benefits:
 * - Single endpoint configuration for users
 * - Centralized authentication via API keys
 * - Per-user access control to specific backends
 * - SSL/Security termination at gateway
 * - Usage logging and analytics
 * - Tool namespacing to avoid collisions (server:tool format)
 *
 * ============================================================================
 * TOOL NAMESPACING
 * ============================================================================
 *
 * All tools are prefixed with their server slug:
 * - tiknix:hello       - Built-in Tiknix tools
 * - shopify:get_products - Shopify MCP tools
 * - github:list_repos  - GitHub MCP tools
 *
 * ============================================================================
 * SECURITY MODEL - TWO-LAYER AUTHENTICATION
 * ============================================================================
 *
 * LAYER 1: Route-Level (authcontrol table)
 * - Permission mcp::message is PUBLIC (level 101)
 * - This allows MCP clients to reach the endpoint
 *
 * LAYER 2: Controller-Level (API Key Auth)
 * - tools/call requires valid API key
 * - API keys can be restricted to specific backend servers
 * - All calls are logged to mcpusage table
 *
 * PUBLIC methods (no API key required):
 * - initialize, tools/list, ping
 *
 * PROTECTED methods (API key required):
 * - tools/call
 *
 * ============================================================================
 *
 * @see https://modelcontextprotocol.io/
 */

namespace app;

use \Flight as Flight;
use \app\Bean;

class Mcp extends BaseControls\Control {

    private const PROTOCOL_VERSION = '2024-11-05';
    private const SERVER_NAME = 'tiknix-mcp';
    private const SERVER_VERSION = '1.0.0';

    /**
     * Available MCP tools
     */
    private array $tools = [
        'hello' => [
            'description' => 'Returns a friendly greeting. Use this to test the MCP connection.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'name' => [
                        'type' => 'string',
                        'description' => 'Name to greet (optional)'
                    ]
                ],
                'required' => []
            ]
        ],
        'echo' => [
            'description' => 'Echoes back the provided message. Useful for testing.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'message' => [
                        'type' => 'string',
                        'description' => 'Message to echo back'
                    ]
                ],
                'required' => ['message']
            ]
        ],
        'get_time' => [
            'description' => 'Returns the current server date and time.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'timezone' => [
                        'type' => 'string',
                        'description' => 'Timezone (e.g., "America/New_York", "UTC"). Defaults to server timezone.'
                    ],
                    'format' => [
                        'type' => 'string',
                        'description' => 'Date format (PHP date format string). Defaults to "Y-m-d H:i:s".'
                    ]
                ],
                'required' => []
            ]
        ],
        'add_numbers' => [
            'description' => 'Adds two numbers together and returns the result.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'a' => [
                        'type' => 'number',
                        'description' => 'First number'
                    ],
                    'b' => [
                        'type' => 'number',
                        'description' => 'Second number'
                    ]
                ],
                'required' => ['a', 'b']
            ]
        ],
        'list_users' => [
            'description' => 'Lists users in the system (requires authentication).',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'limit' => [
                        'type' => 'integer',
                        'description' => 'Maximum number of users to return (default: 10)'
                    ]
                ],
                'required' => []
            ]
        ],
        'list_mcp_servers' => [
            'description' => 'Lists registered MCP servers from the Tiknix registry. Returns server names, endpoints, versions, and available tools.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'status' => [
                        'type' => 'string',
                        'description' => 'Filter by status: active, inactive, deprecated, or all. Defaults to active only.',
                        'enum' => ['active', 'inactive', 'deprecated', 'all']
                    ],
                    'auth_type' => [
                        'type' => 'string',
                        'description' => 'Filter by authentication type',
                        'enum' => ['none', 'basic', 'bearer', 'apikey']
                    ],
                    'tag' => [
                        'type' => 'string',
                        'description' => 'Filter by tag'
                    ],
                    'featured_only' => [
                        'type' => 'boolean',
                        'description' => 'Return only featured servers'
                    ],
                    'include_tools' => [
                        'type' => 'boolean',
                        'description' => 'Include full tool definitions in response. Defaults to false for brevity.'
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'description' => 'Maximum number of servers to return (default: 50, max: 100)'
                    ]
                ],
                'required' => []
            ]
        ],
        // ==========================================
        // VALIDATION TOOLS
        // ==========================================
        'validate_php' => [
            'description' => 'Validate PHP syntax for one or more files. Returns syntax errors if any.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'file' => [
                        'type' => 'string',
                        'description' => 'Path to PHP file or directory to validate'
                    ]
                ],
                'required' => ['file']
            ]
        ],
        'security_scan' => [
            'description' => 'Scan PHP code for security vulnerabilities (OWASP Top 10). Returns issues grouped by severity.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'file' => [
                        'type' => 'string',
                        'description' => 'Path to PHP file or directory to scan'
                    ]
                ],
                'required' => ['file']
            ]
        ],
        'check_redbean' => [
            'description' => 'Check PHP code for RedBeanPHP convention violations (bean naming, associations, R::exec usage).',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'file' => [
                        'type' => 'string',
                        'description' => 'Path to PHP file or directory to check'
                    ]
                ],
                'required' => ['file']
            ]
        ],
        'check_flightphp' => [
            'description' => 'Check PHP code for FlightPHP pattern compliance (controller conventions, routing).',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'file' => [
                        'type' => 'string',
                        'description' => 'Path to PHP file or directory to check'
                    ]
                ],
                'required' => ['file']
            ]
        ],
        'full_validation' => [
            'description' => 'Run all validators (PHP syntax, security, RedBeanPHP, FlightPHP) on code.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'file' => [
                        'type' => 'string',
                        'description' => 'Path to PHP file or directory to validate'
                    ]
                ],
                'required' => ['file']
            ]
        ],
        // ==========================================
        // WORKBENCH TASK TOOLS
        // ==========================================
        'list_tasks' => [
            'description' => 'List workbench tasks visible to the authenticated user.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'status' => [
                        'type' => 'string',
                        'description' => 'Filter by status',
                        'enum' => ['pending', 'queued', 'running', 'completed', 'failed', 'paused']
                    ],
                    'team_id' => [
                        'type' => 'integer',
                        'description' => 'Filter by team ID (null for personal tasks)'
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'description' => 'Maximum number of tasks to return (default: 20)'
                    ]
                ],
                'required' => []
            ]
        ],
        'get_task' => [
            'description' => 'Get details of a specific workbench task.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'task_id' => [
                        'type' => 'integer',
                        'description' => 'The task ID'
                    ]
                ],
                'required' => ['task_id']
            ]
        ],
        'update_task' => [
            'description' => 'Update a workbench task. Use to report progress, set status, or record results.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'task_id' => [
                        'type' => 'integer',
                        'description' => 'The task ID'
                    ],
                    'status' => [
                        'type' => 'string',
                        'description' => 'New status',
                        'enum' => ['running', 'completed', 'failed', 'paused']
                    ],
                    'branch_name' => [
                        'type' => 'string',
                        'description' => 'Git branch name'
                    ],
                    'pr_url' => [
                        'type' => 'string',
                        'description' => 'Pull request URL'
                    ],
                    'progress_message' => [
                        'type' => 'string',
                        'description' => 'Progress update message'
                    ],
                    'error_message' => [
                        'type' => 'string',
                        'description' => 'Error message (for failed status)'
                    ]
                ],
                'required' => ['task_id']
            ]
        ],
        'complete_task' => [
            'description' => 'Mark a task as completed with results.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'task_id' => [
                        'type' => 'integer',
                        'description' => 'The task ID'
                    ],
                    'pr_url' => [
                        'type' => 'string',
                        'description' => 'Pull request URL (if applicable)'
                    ],
                    'branch_name' => [
                        'type' => 'string',
                        'description' => 'Git branch name'
                    ],
                    'summary' => [
                        'type' => 'string',
                        'description' => 'Summary of what was accomplished'
                    ]
                ],
                'required' => ['task_id']
            ]
        ],
        'add_task_log' => [
            'description' => 'Add a log entry to a task.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'task_id' => [
                        'type' => 'integer',
                        'description' => 'The task ID'
                    ],
                    'level' => [
                        'type' => 'string',
                        'description' => 'Log level',
                        'enum' => ['debug', 'info', 'warning', 'error']
                    ],
                    'message' => [
                        'type' => 'string',
                        'description' => 'Log message'
                    ]
                ],
                'required' => ['task_id', 'message']
            ]
        ],

        // Playwright Browser Automation Tools
        'playwright_status' => [
            'description' => 'Check the status of the Playwright browser automation server.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [],
                'required' => []
            ]
        ],
        'playwright_navigate' => [
            'description' => 'Navigate to a URL in the browser.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'url' => [
                        'type' => 'string',
                        'description' => 'The URL to navigate to'
                    ],
                    'wait_until' => [
                        'type' => 'string',
                        'description' => 'When to consider navigation complete',
                        'enum' => ['load', 'domcontentloaded', 'networkidle']
                    ]
                ],
                'required' => ['url']
            ]
        ],
        'playwright_screenshot' => [
            'description' => 'Take a screenshot of the current page.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'name' => [
                        'type' => 'string',
                        'description' => 'Name for the screenshot'
                    ],
                    'full_page' => [
                        'type' => 'boolean',
                        'description' => 'Capture full scrollable page'
                    ],
                    'width' => [
                        'type' => 'integer',
                        'description' => 'Viewport width'
                    ],
                    'height' => [
                        'type' => 'integer',
                        'description' => 'Viewport height'
                    ]
                ],
                'required' => ['name']
            ]
        ],
        'playwright_click' => [
            'description' => 'Click an element on the page.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'selector' => [
                        'type' => 'string',
                        'description' => 'CSS or XPath selector for the element'
                    ]
                ],
                'required' => ['selector']
            ]
        ],
        'playwright_fill' => [
            'description' => 'Fill a form field with a value.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'selector' => [
                        'type' => 'string',
                        'description' => 'CSS selector for the input field'
                    ],
                    'value' => [
                        'type' => 'string',
                        'description' => 'Value to fill into the field'
                    ]
                ],
                'required' => ['selector', 'value']
            ]
        ],
        'playwright_snapshot' => [
            'description' => 'Get an accessibility snapshot of the current page for understanding page structure.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [],
                'required' => []
            ]
        ],
        'playwright_evaluate' => [
            'description' => 'Execute JavaScript code in the browser context.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'script' => [
                        'type' => 'string',
                        'description' => 'JavaScript code to execute'
                    ]
                ],
                'required' => ['script']
            ]
        ],
        'playwright_close' => [
            'description' => 'Close the browser session.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [],
                'required' => []
            ]
        ]
    ];

    /**
     * Authenticated member for this request
     */
    private ?object $authMember = null;

    /**
     * API key used for authentication (if applicable)
     */
    private ?object $authApiKey = null;

    public function __construct() {
        // Skip parent constructor to avoid session/CSRF for API endpoints
        $this->logger = Flight::get('log');
    }

    /**
     * Main MCP message endpoint
     * POST /mcp/message
     */
    public function message($params = null): void {
        // Set JSON content type
        header('Content-Type: application/json');

        // Handle CORS preflight
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            $this->setCorsHeaders();
            http_response_code(200);
            exit;
        }

        $this->setCorsHeaders();

        // Only accept POST requests
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->sendError(-32600, 'Method not allowed. Use POST.', null, 405);
            return;
        }

        // Parse JSON-RPC request first (before auth check)
        $rawBody = file_get_contents('php://input');
        $request = json_decode($rawBody, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->sendError(-32700, 'Parse error: Invalid JSON', null);
            return;
        }

        // Route to appropriate handler
        $method = $request['method'] ?? '';
        $id = $request['id'] ?? null;
        $params = $request['params'] ?? [];

        // =====================================================================
        // PUBLIC METHODS - No API key required (see security docs at top of file)
        // These are discovery/handshake methods, not execution methods.
        // Modify this list carefully - adding methods here makes them public!
        // =====================================================================
        $publicMethods = ['initialize', 'tools/list', 'ping'];

        // Authenticate for non-public methods
        if (!in_array($method, $publicMethods)) {
            if (!$this->authenticate()) {
                $this->sendError(-32000, 'Authentication required', null, 401);
                return;
            }
        } else {
            // Try to authenticate anyway for personalization, but don't require it
            $this->authenticate();
        }

        $this->logger->debug('MCP request received', [
            'method' => $method,
            'member_id' => $this->authMember->id ?? 0
        ]);

        switch ($method) {
            case 'initialize':
                $this->handleInitialize($id, $params);
                break;

            case 'tools/list':
                $this->handleToolsList($id);
                break;

            case 'tools/call':
                $this->handleToolsCall($id, $params);
                break;

            case 'ping':
                $this->sendResult($id, ['pong' => true]);
                break;

            default:
                $this->sendError(-32601, "Method not found: {$method}", $id);
        }
    }

    /**
     * Health check endpoint
     * GET /mcp/health
     */
    public function health($params = null): void {
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'healthy',
            'server' => self::SERVER_NAME,
            'version' => self::SERVER_VERSION,
            'protocol' => self::PROTOCOL_VERSION,
            'url' => $this->getMcpUrl(),
            'timestamp' => date('c')
        ]);
    }

    /**
     * Documentation endpoint
     * GET /mcp/index
     */
    public function index($params = null): void {
        $this->render('mcp/index', [
            'title' => 'MCP Server',
            'tools' => $this->tools,
            'serverName' => self::SERVER_NAME,
            'serverVersion' => self::SERVER_VERSION,
            'protocolVersion' => self::PROTOCOL_VERSION,
            'mcpUrl' => $this->getMcpUrl()
        ]);
    }

    /**
     * MCP Registry - forwards to Mcpregistry controller
     * GET /mcp/registry[/method]
     */
    public function registry($params = null): void {
        $instance = new Mcpregistry();

        // Get the method from operation, default to index
        $method = $params['operation']->name ?? 'index';

        // Forward the params, shifting operation down
        $forwardParams = $params;
        $forwardParams['operation'] = new \stdClass();
        $forwardParams['operation']->name = $params['operation']->type ?? null;
        $forwardParams['operation']->type = null;

        if (method_exists($instance, $method) && (new \ReflectionMethod($instance, $method))->isPublic()) {
            $instance->$method($forwardParams);
        } else {
            // Default to index if method doesn't exist
            $instance->index($params);
        }
    }

    /**
     * Claude Code configuration endpoint
     * GET /mcp/config
     *
     * Returns a ready-to-use configuration for ~/.claude/settings.json or .mcp.json
     * Requires authentication to include the user's API token and accessible servers
     */
    public function config($params = null): void {
        header('Content-Type: application/json');
        $this->setCorsHeaders();

        // Check if user is authenticated (for personalized config with token)
        $hasAuth = $this->authenticate();

        $mcpUrl = $this->getMcpUrl();

        // Build the MCP server config
        $serverConfig = [
            'type' => 'http',
            'url' => $mcpUrl
        ];

        // Response structure
        $response = [
            'mcpServers' => [
                self::SERVER_NAME => $serverConfig
            ]
        ];

        if ($hasAuth && $this->authMember) {
            // Get API key token (prefer new apikey table, fall back to legacy)
            $token = null;
            $keyName = null;
            $keyScopes = [];
            $allowedServerSlugs = [];

            if ($this->authApiKey) {
                // Using new API key system
                $token = $this->authApiKey->token;
                $keyName = $this->authApiKey->name;
                $keyScopes = json_decode($this->authApiKey->scopes, true) ?: [];
                $allowedServerSlugs = json_decode($this->authApiKey->allowedServers, true) ?: [];
            } elseif (!empty($this->authMember->api_token)) {
                // Legacy api_token
                $token = $this->authMember->api_token;
                $keyName = 'Legacy Token';
                $keyScopes = ['mcp:*'];
            }

            if ($token) {
                $response['mcpServers'][self::SERVER_NAME]['headers'] = [
                    'Authorization' => 'Bearer ' . $token
                ];
            }

            // Get accessible backend servers
            $servers = $this->getAllowedServers();
            $accessibleServers = [];

            foreach ($servers as $server) {
                $tools = json_decode($server->tools, true) ?: [];
                $toolNames = array_map(fn($t) => $t['name'] ?? 'unknown', $tools);

                $accessibleServers[] = [
                    'slug' => $server->slug,
                    'name' => $server->name,
                    'description' => $server->description,
                    'tool_count' => count($tools),
                    'tools' => $toolNames,
                    'status' => $server->status
                ];
            }

            // Add metadata about the authenticated user's access
            $response['_meta'] = [
                'authenticated' => true,
                'user' => $this->authMember->username ?? $this->authMember->email,
                'api_key' => [
                    'name' => $keyName,
                    'scopes' => $keyScopes,
                    'server_restrictions' => empty($allowedServerSlugs) ? 'none (full access)' : $allowedServerSlugs
                ],
                'accessible_servers' => $accessibleServers,
                'total_tools' => count($this->tools) + array_sum(array_column($accessibleServers, 'tool_count')),
                'instructions' => [
                    'global' => 'Save to ~/.claude/settings.json for all projects',
                    'project' => 'Save to .mcp.json in your project root',
                    'copy_config' => 'Copy the "mcpServers" object into your config file'
                ]
            ];
        } else {
            // Unauthenticated - show placeholder
            $response['mcpServers'][self::SERVER_NAME]['headers'] = [
                'Authorization' => 'Bearer YOUR_API_TOKEN'
            ];
            $response['_meta'] = [
                'authenticated' => false,
                'note' => 'Authenticate with Basic Auth or API key to get your personalized config',
                'example' => 'curl -u username:password ' . rtrim($mcpUrl, '/message') . '/config',
                'get_api_key' => rtrim($mcpUrl, '/message') . '/../apikeys'
            ];
        }

        echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Generate API token for current user
     * POST /mcp/token
     */
    public function token($params = null): void {
        header('Content-Type: application/json');

        if (!$this->authenticate()) {
            http_response_code(401);
            echo json_encode(['error' => 'Authentication required']);
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Use POST to generate a new token']);
            return;
        }

        // Generate new token
        $token = bin2hex(random_bytes(32));
        $this->authMember->api_token = $token;
        Bean::store($this->authMember);

        $this->logger->info('MCP token generated', ['member_id' => $this->authMember->id]);

        echo json_encode([
            'success' => true,
            'api_token' => $token,
            'config' => [
                'mcpServers' => [
                    self::SERVER_NAME => [
                        'type' => 'http',
                        'url' => $this->getMcpUrl(),
                        'headers' => [
                            'Authorization' => 'Bearer ' . $token
                        ]
                    ]
                ]
            ]
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    // =========================================
    // MCP Protocol Handlers
    // =========================================

    /**
     * Handle initialize request
     */
    private function handleInitialize(mixed $id, array $params): void {
        $this->sendResult($id, [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities' => [
                'tools' => ['listChanged' => false]
            ],
            'serverInfo' => [
                'name' => self::SERVER_NAME,
                'version' => self::SERVER_VERSION
            ]
        ]);
    }

    /**
     * Handle tools/list request
     * Aggregates tools from built-in Tiknix tools + all proxy-enabled backend servers
     */
    private function handleToolsList(mixed $id): void {
        $toolList = [];

        // Add built-in Tiknix tools (prefixed with tiknix:)
        foreach ($this->tools as $name => $config) {
            $toolList[] = [
                'name' => 'tiknix:' . $name,
                'description' => '[Tiknix] ' . $config['description'],
                'inputSchema' => $config['inputSchema']
            ];
        }

        // Get tools from all proxy-enabled backend servers the user has access to
        $servers = $this->getAllowedServers();
        foreach ($servers as $server) {
            // Skip the built-in tiknix server (already added above)
            if ($server->slug === 'tiknix-mcp') {
                continue;
            }

            $serverTools = $this->getServerTools($server);
            foreach ($serverTools as $tool) {
                $toolList[] = [
                    'name' => $server->slug . ':' . $tool['name'],
                    'description' => '[' . $server->name . '] ' . ($tool['description'] ?? ''),
                    'inputSchema' => $tool['inputSchema'] ?? ['type' => 'object', 'properties' => []]
                ];
            }
        }

        $this->sendResult($id, ['tools' => $toolList]);
    }

    /**
     * Get all servers the current user/API key has access to
     */
    private function getAllowedServers(): array {
        // Get proxy-enabled active servers
        $servers = Bean::find('mcpserver', 'status = ? AND is_proxy_enabled = ? ORDER BY featured DESC, sort_order ASC', ['active', 1]);

        // Filter by API key permissions if applicable
        if ($this->authApiKey) {
            $allowedSlugs = json_decode($this->authApiKey->allowedServers, true) ?: [];

            // If no restrictions, return all
            if (empty($allowedSlugs)) {
                return $servers;
            }

            // Filter to only allowed servers
            return array_filter($servers, fn($s) => in_array($s->slug, $allowedSlugs));
        }

        return $servers;
    }

    /**
     * Get tools from a backend server (with caching)
     */
    private function getServerTools($server): array {
        // Check cache first (1 hour TTL)
        if ($server->toolsCache && $server->toolsCachedAt) {
            $cacheAge = time() - strtotime($server->toolsCachedAt);
            if ($cacheAge < 3600) { // 1 hour cache
                $cached = json_decode($server->toolsCache, true);
                if ($cached !== null) {
                    return $cached;
                }
            }
        }

        // If endpoint is relative (built-in), use stored tools
        if (strpos($server->endpointUrl, 'http') !== 0) {
            return json_decode($server->tools, true) ?: [];
        }

        // Fetch fresh tools from backend
        try {
            $tools = $this->fetchBackendTools($server);

            // Update cache
            $server->toolsCache = json_encode($tools);
            $server->toolsCachedAt = date('Y-m-d H:i:s');
            Bean::store($server);

            return $tools;
        } catch (\Exception $e) {
            $this->logger->warning('Failed to fetch tools from backend', [
                'server' => $server->slug,
                'error' => $e->getMessage()
            ]);
            // Fall back to stored tools
            return json_decode($server->tools, true) ?: [];
        }
    }

    /**
     * Fetch tools from a backend MCP server
     */
    private function fetchBackendTools($server): array {
        $request = json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
            'params' => []
        ]);

        $headers = ['Content-Type: application/json'];
        if (!empty($server->backendAuthToken)) {
            $headerName = $server->backendAuthHeader ?: 'Authorization';
            $prefix = ($headerName === 'Authorization') ? 'Bearer ' : '';
            $headers[] = "{$headerName}: {$prefix}{$server->backendAuthToken}";
        }

        $ch = curl_init($server->endpointUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $request,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new \Exception("Connection error: {$error}");
        }

        if ($httpCode !== 200) {
            throw new \Exception("HTTP {$httpCode}");
        }

        $data = json_decode($response, true);
        return $data['result']['tools'] ?? [];
    }

    /**
     * Handle tools/call request
     * Routes to built-in Tiknix tools or proxies to backend servers
     */
    private function handleToolsCall(mixed $id, array $params): void {
        $fullToolName = $params['name'] ?? '';
        $arguments = $params['arguments'] ?? [];

        // Parse "server:tool" format
        $serverSlug = 'tiknix';
        $toolName = $fullToolName;

        if (strpos($fullToolName, ':') !== false) {
            [$serverSlug, $toolName] = explode(':', $fullToolName, 2);
        }

        $startTime = microtime(true);
        $responseStatus = 'success';
        $errorMessage = null;

        $this->logger->info('MCP tool call', [
            'full_tool' => $fullToolName,
            'server' => $serverSlug,
            'tool' => $toolName,
            'member_id' => $this->authMember->id ?? 0
        ]);

        try {
            // Route to built-in Tiknix tools or proxy to backend
            if ($serverSlug === 'tiknix') {
                // Built-in Tiknix tools
                if (!isset($this->tools[$toolName])) {
                    throw new \Exception("Unknown Tiknix tool: {$toolName}");
                }
                $result = $this->executeTool($toolName, $arguments);
            } else {
                // Proxy to backend server
                $result = $this->proxyToolCall($serverSlug, $toolName, $arguments);
            }

            $this->sendToolResult($id, $result);

        } catch (\Exception $e) {
            $responseStatus = 'error';
            $errorMessage = $e->getMessage();

            $this->logger->error('MCP tool error', [
                'server' => $serverSlug,
                'tool' => $toolName,
                'error' => $e->getMessage()
            ]);
            $this->sendToolResult($id, "Error: " . $e->getMessage(), true);
        }

        // Log usage
        $this->logUsage($serverSlug, $toolName, $arguments, $responseStatus, $errorMessage, $startTime);
    }

    /**
     * Proxy a tool call to a backend MCP server
     */
    private function proxyToolCall(string $serverSlug, string $toolName, array $arguments): string {
        // Find the server
        $server = Bean::findOne('mcpserver', 'slug = ? AND status = ?', [$serverSlug, 'active']);

        if (!$server) {
            throw new \Exception("Server not found: {$serverSlug}");
        }

        // Check access
        if (!$this->hasServerAccess($serverSlug)) {
            throw new \Exception("Access denied to server: {$serverSlug}");
        }

        // Check if proxy is enabled
        if (!$server->isProxyEnabled) {
            throw new \Exception("Proxy disabled for server: {$serverSlug}");
        }

        // Build JSON-RPC request
        $request = json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => $toolName,
                'arguments' => $arguments
            ]
        ]);

        // Set up headers with backend auth
        $headers = ['Content-Type: application/json'];
        if (!empty($server->backendAuthToken)) {
            $headerName = $server->backendAuthHeader ?: 'Authorization';
            $prefix = ($headerName === 'Authorization') ? 'Bearer ' : '';
            $headers[] = "{$headerName}: {$prefix}{$server->backendAuthToken}";
        }

        $this->logger->debug('Proxying to backend', [
            'server' => $serverSlug,
            'endpoint' => $server->endpointUrl,
            'tool' => $toolName
        ]);

        // Make request to backend
        $ch = curl_init($server->endpointUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $request,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new \Exception("Backend connection error: {$error}");
        }

        if ($httpCode !== 200) {
            throw new \Exception("Backend returned HTTP {$httpCode}");
        }

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception("Invalid JSON from backend");
        }

        // Check for error response
        if (isset($data['error'])) {
            throw new \Exception($data['error']['message'] ?? 'Backend error');
        }

        // Extract text content from result
        $content = $data['result']['content'] ?? [];
        $texts = [];
        foreach ($content as $item) {
            if (($item['type'] ?? '') === 'text') {
                $texts[] = $item['text'];
            }
        }

        return implode("\n", $texts) ?: json_encode($data['result'] ?? []);
    }

    /**
     * Log tool call usage to mcpusage table
     */
    private function logUsage(string $serverSlug, string $toolName, array $requestData, string $status, ?string $errorMessage, float $startTime): void {
        try {
            $usage = Bean::dispense('mcpusage');
            $usage->apikeyId = $this->authApiKey->id ?? 0;
            $usage->memberId = $this->authMember->id ?? 0;
            $usage->serverSlug = $serverSlug;
            $usage->toolName = $toolName;
            $usage->requestData = json_encode($requestData);
            $usage->responseStatus = $status;
            $usage->responseTimeMs = (int)((microtime(true) - $startTime) * 1000);
            $usage->errorMessage = $errorMessage;
            $usage->ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
            $usage->createdAt = date('Y-m-d H:i:s');
            Bean::store($usage);
        } catch (\Exception $e) {
            // Don't let logging failures break the request
            $this->logger->warning('Failed to log MCP usage', ['error' => $e->getMessage()]);
        }
    }

    // =========================================
    // Tool Implementations
    // =========================================

    /**
     * Execute a tool by name
     */
    private function executeTool(string $name, array $args): string {
        switch ($name) {
            case 'hello':
                return $this->toolHello($args);

            case 'echo':
                return $this->toolEcho($args);

            case 'get_time':
                return $this->toolGetTime($args);

            case 'add_numbers':
                return $this->toolAddNumbers($args);

            case 'list_users':
                return $this->toolListUsers($args);

            case 'list_mcp_servers':
                return $this->toolListMcpServers($args);

            // Validation tools
            case 'validate_php':
                return $this->toolValidatePhp($args);

            case 'security_scan':
                return $this->toolSecurityScan($args);

            case 'check_redbean':
                return $this->toolCheckRedbean($args);

            case 'check_flightphp':
                return $this->toolCheckFlightphp($args);

            case 'full_validation':
                return $this->toolFullValidation($args);

            // Workbench tools
            case 'list_tasks':
                return $this->toolListTasks($args);

            case 'get_task':
                return $this->toolGetTask($args);

            case 'update_task':
                return $this->toolUpdateTask($args);

            case 'complete_task':
                return $this->toolCompleteTask($args);

            case 'add_task_log':
                return $this->toolAddTaskLog($args);

            // Playwright browser automation tools
            case 'playwright_status':
                return $this->toolPlaywrightStatus($args);

            case 'playwright_navigate':
                return $this->toolPlaywrightNavigate($args);

            case 'playwright_screenshot':
                return $this->toolPlaywrightScreenshot($args);

            case 'playwright_click':
                return $this->toolPlaywrightClick($args);

            case 'playwright_fill':
                return $this->toolPlaywrightFill($args);

            case 'playwright_snapshot':
                return $this->toolPlaywrightSnapshot($args);

            case 'playwright_evaluate':
                return $this->toolPlaywrightEvaluate($args);

            case 'playwright_close':
                return $this->toolPlaywrightClose($args);

            default:
                throw new \Exception("Tool not implemented: {$name}");
        }
    }

    /**
     * Hello tool - returns a greeting
     */
    private function toolHello(array $args): string {
        $name = $args['name'] ?? 'World';
        return "Hello, {$name}! Welcome to the Tiknix MCP server.";
    }

    /**
     * Echo tool - echoes back message
     */
    private function toolEcho(array $args): string {
        $message = $args['message'] ?? '';
        if (empty($message)) {
            throw new \Exception("Message is required");
        }
        return "Echo: {$message}";
    }

    /**
     * Get time tool - returns current server time
     */
    private function toolGetTime(array $args): string {
        $timezone = $args['timezone'] ?? date_default_timezone_get();
        $format = $args['format'] ?? 'Y-m-d H:i:s';

        try {
            $tz = new \DateTimeZone($timezone);
            $dt = new \DateTime('now', $tz);
            return json_encode([
                'datetime' => $dt->format($format),
                'timezone' => $timezone,
                'unix_timestamp' => $dt->getTimestamp()
            ], JSON_PRETTY_PRINT);
        } catch (\Exception $e) {
            throw new \Exception("Invalid timezone: {$timezone}");
        }
    }

    /**
     * Add numbers tool - adds two numbers
     */
    private function toolAddNumbers(array $args): string {
        if (!isset($args['a']) || !isset($args['b'])) {
            throw new \Exception("Both 'a' and 'b' parameters are required");
        }

        $a = (float)$args['a'];
        $b = (float)$args['b'];
        $result = $a + $b;

        return json_encode([
            'a' => $a,
            'b' => $b,
            'operation' => 'addition',
            'result' => $result
        ], JSON_PRETTY_PRINT);
    }

    /**
     * List users tool - lists system users (example of authenticated tool)
     */
    private function toolListUsers(array $args): string {
        // Check if authenticated user has admin level
        if (!$this->authMember || $this->authMember->level > LEVELS['ADMIN']) {
            throw new \Exception("Admin access required for this tool");
        }

        $limit = min((int)($args['limit'] ?? 10), 100);

        $users = Bean::find('member', 'ORDER BY id LIMIT ?', [$limit]);

        $result = [];
        foreach ($users as $user) {
            $result[] = [
                'id' => $user->id,
                'username' => $user->username,
                'email' => $user->email,
                'level' => $user->level
            ];
        }

        return json_encode([
            'count' => count($result),
            'users' => $result
        ], JSON_PRETTY_PRINT);
    }

    /**
     * List MCP servers tool - returns registered MCP servers from registry
     */
    private function toolListMcpServers(array $args): string {
        $status = $args['status'] ?? 'active';
        $authType = $args['auth_type'] ?? null;
        $tag = $args['tag'] ?? null;
        $featuredOnly = $args['featured_only'] ?? false;
        $includeTools = $args['include_tools'] ?? false;
        $limit = min((int)($args['limit'] ?? 50), 100);

        // Build query
        $conditions = [];
        $params = [];

        if ($status !== 'all') {
            $conditions[] = 'status = ?';
            $params[] = $status;
        }

        if ($authType) {
            $conditions[] = 'auth_type = ?';
            $params[] = $authType;
        }

        if ($featuredOnly) {
            $conditions[] = 'featured = 1';
        }

        $sql = '';
        if (!empty($conditions)) {
            $sql = implode(' AND ', $conditions);
        }
        $sql .= ' ORDER BY featured DESC, sort_order ASC, name ASC';
        $sql .= ' LIMIT ' . $limit;

        if (empty($conditions)) {
            $servers = Bean::findAll('mcpserver', 'ORDER BY featured DESC, sort_order ASC, name ASC LIMIT ' . $limit);
        } else {
            $servers = Bean::find('mcpserver', $sql, $params);
        }

        $result = [];
        foreach ($servers as $server) {
            $serverTags = json_decode($server->tags, true) ?: [];

            // Filter by tag if specified
            if ($tag && !in_array($tag, $serverTags)) {
                continue;
            }

            $serverData = [
                'slug' => $server->slug,
                'name' => $server->name,
                'description' => $server->description,
                'endpoint_url' => $server->endpointUrl,
                'version' => $server->version,
                'status' => $server->status,
                'author' => $server->author,
                'auth_type' => $server->authType,
                'documentation_url' => $server->documentationUrl,
                'featured' => (bool)$server->featured,
                'tags' => $serverTags
            ];

            // Include tool definitions if requested
            $tools = json_decode($server->tools, true) ?: [];
            if ($includeTools) {
                $serverData['tools'] = $tools;
            } else {
                // Just include tool count and names
                $serverData['tool_count'] = count($tools);
                $serverData['tool_names'] = array_map(fn($t) => $t['name'] ?? 'unknown', $tools);
            }

            $result[] = $serverData;
        }

        return json_encode([
            'count' => count($result),
            'servers' => $result
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    // =========================================
    // Validation Tools
    // =========================================

    /**
     * Validate PHP syntax for a file or directory
     */
    private function toolValidatePhp(array $args): string {
        $path = $args['path'] ?? null;
        if (!$path) {
            throw new \Exception("Path is required");
        }

        // Resolve path relative to project root
        $projectRoot = \Flight::get('project_root') ?? dirname(__DIR__);
        $fullPath = $this->resolvePath($path, $projectRoot);

        $validator = new \app\ValidationService($projectRoot);
        $result = $validator->validatePhpSyntax($fullPath);

        return json_encode([
            'path' => $path,
            'valid' => $result['valid'],
            'errors' => $result['errors']
        ], JSON_PRETTY_PRINT);
    }

    /**
     * Scan code for security vulnerabilities
     */
    private function toolSecurityScan(array $args): string {
        $path = $args['path'] ?? null;
        $code = $args['code'] ?? null;

        if (!$path && !$code) {
            throw new \Exception("Either 'path' or 'code' is required");
        }

        $projectRoot = \Flight::get('project_root') ?? dirname(__DIR__);
        $validator = new \app\ValidationService($projectRoot);

        if ($path) {
            $fullPath = $this->resolvePath($path, $projectRoot);
            if (!file_exists($fullPath)) {
                throw new \Exception("File not found: {$path}");
            }
            $code = file_get_contents($fullPath);
        }

        $issues = $validator->scanSecurity($code, $path ?? 'inline');

        $totalIssues = count($issues['critical'] ?? [])
            + count($issues['high'] ?? [])
            + count($issues['medium'] ?? [])
            + count($issues['low'] ?? []);

        return json_encode([
            'path' => $path ?? 'inline',
            'has_issues' => $totalIssues > 0,
            'issue_count' => $totalIssues,
            'issues' => $issues
        ], JSON_PRETTY_PRINT);
    }

    /**
     * Check RedBeanPHP conventions
     */
    private function toolCheckRedbean(array $args): string {
        $path = $args['path'] ?? null;
        $code = $args['code'] ?? null;

        if (!$path && !$code) {
            throw new \Exception("Either 'path' or 'code' is required");
        }

        $projectRoot = \Flight::get('project_root') ?? dirname(__DIR__);
        $validator = new \app\ValidationService($projectRoot);

        if ($path) {
            $fullPath = $this->resolvePath($path, $projectRoot);
            if (!file_exists($fullPath)) {
                throw new \Exception("File not found: {$path}");
            }
            $code = file_get_contents($fullPath);
        }

        $result = $validator->checkRedBeanConventions($code, $path ?? 'inline');

        return json_encode([
            'path' => $path ?? 'inline',
            'errors' => $result['errors'] ?? [],
            'warnings' => $result['warnings'] ?? []
        ], JSON_PRETTY_PRINT);
    }

    /**
     * Check FlightPHP patterns
     */
    private function toolCheckFlightphp(array $args): string {
        $path = $args['path'] ?? null;
        $code = $args['code'] ?? null;

        if (!$path && !$code) {
            throw new \Exception("Either 'path' or 'code' is required");
        }

        $projectRoot = \Flight::get('project_root') ?? dirname(__DIR__);
        $validator = new \app\ValidationService($projectRoot);

        if ($path) {
            $fullPath = $this->resolvePath($path, $projectRoot);
            if (!file_exists($fullPath)) {
                throw new \Exception("File not found: {$path}");
            }
            $code = file_get_contents($fullPath);
        }

        $result = $validator->checkFlightPhpPatterns($code, $path ?? 'inline');

        return json_encode([
            'path' => $path ?? 'inline',
            'warnings' => $result['warnings'] ?? [],
            'info' => $result['info'] ?? []
        ], JSON_PRETTY_PRINT);
    }

    /**
     * Run full validation on a path
     */
    private function toolFullValidation(array $args): string {
        $path = $args['path'] ?? null;
        if (!$path) {
            throw new \Exception("Path is required");
        }

        $projectRoot = \Flight::get('project_root') ?? dirname(__DIR__);
        $fullPath = $this->resolvePath($path, $projectRoot);

        $validator = new \app\ValidationService($projectRoot);
        $result = $validator->fullValidation($fullPath);

        return json_encode([
            'path' => $path,
            'valid' => $result['valid'],
            'errors' => $result['errors'],
            'warnings' => $result['warnings'],
            'info' => $result['info']
        ], JSON_PRETTY_PRINT);
    }

    /**
     * Resolve a path relative to project root
     */
    private function resolvePath(string $path, string $projectRoot): string {
        if (strpos($path, '/') === 0) {
            return $path;
        }
        return $projectRoot . '/' . ltrim($path, './');
    }

    // =========================================
    // Workbench Tools
    // =========================================

    /**
     * List tasks for the authenticated user
     */
    private function toolListTasks(array $args): string {
        if (!$this->authMember) {
            throw new \Exception("Authentication required");
        }

        $status = $args['status'] ?? null;
        $teamId = isset($args['team_id']) ? (int)$args['team_id'] : null;
        $limit = min((int)($args['limit'] ?? 20), 100);

        $accessControl = new \app\TaskAccessControl($this->authMember->id);
        $tasks = $accessControl->getVisibleTasks($status, $teamId);

        // Apply limit
        $tasks = array_slice($tasks, 0, $limit);

        $result = [];
        foreach ($tasks as $task) {
            $result[] = [
                'id' => $task->id,
                'title' => $task->title,
                'task_type' => $task->taskType,
                'status' => $task->status,
                'priority' => $task->priority,
                'team_id' => $task->teamId,
                'created_at' => $task->createdAt,
                'updated_at' => $task->updatedAt
            ];
        }

        return json_encode([
            'count' => count($result),
            'tasks' => $result
        ], JSON_PRETTY_PRINT);
    }

    /**
     * Get a specific task
     */
    private function toolGetTask(array $args): string {
        if (!$this->authMember) {
            throw new \Exception("Authentication required");
        }

        $taskId = (int)($args['task_id'] ?? 0);
        if (!$taskId) {
            throw new \Exception("task_id is required");
        }

        $accessControl = new \app\TaskAccessControl($this->authMember->id);
        if (!$accessControl->canView($taskId)) {
            throw new \Exception("Access denied to task {$taskId}");
        }

        $task = Bean::load('workbenchtask', $taskId);
        if (!$task->id) {
            throw new \Exception("Task not found: {$taskId}");
        }

        return json_encode([
            'id' => $task->id,
            'title' => $task->title,
            'description' => $task->description,
            'task_type' => $task->taskType,
            'status' => $task->status,
            'priority' => $task->priority,
            'acceptance_criteria' => $task->acceptanceCriteria,
            'related_files' => json_decode($task->relatedFiles, true) ?: [],
            'tags' => json_decode($task->tags, true) ?: [],
            'member_id' => $task->memberId,
            'team_id' => $task->teamId,
            'branch_name' => $task->branchName,
            'pr_url' => $task->prUrl,
            'created_at' => $task->createdAt,
            'updated_at' => $task->updatedAt
        ], JSON_PRETTY_PRINT);
    }

    /**
     * Update a task's status or progress
     */
    private function toolUpdateTask(array $args): string {
        if (!$this->authMember) {
            throw new \Exception("Authentication required");
        }

        $taskId = (int)($args['task_id'] ?? 0);
        if (!$taskId) {
            throw new \Exception("task_id is required");
        }

        $accessControl = new \app\TaskAccessControl($this->authMember->id);
        if (!$accessControl->canEdit($taskId)) {
            throw new \Exception("No permission to update task {$taskId}");
        }

        $task = Bean::load('workbenchtask', $taskId);
        if (!$task->id) {
            throw new \Exception("Task not found: {$taskId}");
        }

        // Update allowed fields
        $allowedFields = ['status', 'branchName', 'prUrl', 'progressMessage', 'errorMessage'];
        $updated = [];

        foreach ($allowedFields as $field) {
            $argKey = $this->camelToSnake($field);
            if (isset($args[$argKey])) {
                $task->$field = $args[$argKey];
                $updated[] = $argKey;
            }
        }

        if (empty($updated)) {
            throw new \Exception("No valid fields to update");
        }

        $task->updatedAt = date('Y-m-d H:i:s');
        Bean::store($task);

        return json_encode([
            'success' => true,
            'task_id' => $taskId,
            'updated_fields' => $updated
        ], JSON_PRETTY_PRINT);
    }

    /**
     * Mark a task as complete
     */
    private function toolCompleteTask(array $args): string {
        if (!$this->authMember) {
            throw new \Exception("Authentication required");
        }

        $taskId = (int)($args['task_id'] ?? 0);
        if (!$taskId) {
            throw new \Exception("task_id is required");
        }

        $accessControl = new \app\TaskAccessControl($this->authMember->id);
        if (!$accessControl->canEdit($taskId)) {
            throw new \Exception("No permission to complete task {$taskId}");
        }

        $task = Bean::load('workbenchtask', $taskId);
        if (!$task->id) {
            throw new \Exception("Task not found: {$taskId}");
        }

        // Update task
        $task->status = 'completed';
        $task->completedAt = date('Y-m-d H:i:s');
        $task->updatedAt = date('Y-m-d H:i:s');

        if (isset($args['pr_url'])) {
            $task->prUrl = $args['pr_url'];
        }
        if (isset($args['branch_name'])) {
            $task->branchName = $args['branch_name'];
        }
        if (isset($args['results'])) {
            $task->resultsJson = is_string($args['results'])
                ? $args['results']
                : json_encode($args['results']);
        }

        Bean::store($task);

        // Log completion
        $log = Bean::dispense('tasklog');
        $log->taskId = $taskId;
        $log->memberId = $this->authMember->id;
        $log->logLevel = 'info';
        $log->logType = 'status_change';
        $log->message = 'Task completed';
        $log->createdAt = date('Y-m-d H:i:s');
        Bean::store($log);

        return json_encode([
            'success' => true,
            'task_id' => $taskId,
            'status' => 'completed',
            'completed_at' => $task->completedAt,
            'pr_url' => $task->prUrl
        ], JSON_PRETTY_PRINT);
    }

    /**
     * Add a log entry to a task
     */
    private function toolAddTaskLog(array $args): string {
        if (!$this->authMember) {
            throw new \Exception("Authentication required");
        }

        $taskId = (int)($args['task_id'] ?? 0);
        $message = $args['message'] ?? '';
        $level = $args['level'] ?? 'info';
        $type = $args['type'] ?? 'general';

        if (!$taskId || !$message) {
            throw new \Exception("task_id and message are required");
        }

        $accessControl = new \app\TaskAccessControl($this->authMember->id);
        if (!$accessControl->canView($taskId)) {
            throw new \Exception("Access denied to task {$taskId}");
        }

        // Validate level
        $validLevels = ['debug', 'info', 'warning', 'error'];
        if (!in_array($level, $validLevels)) {
            $level = 'info';
        }

        $log = Bean::dispense('tasklog');
        $log->taskId = $taskId;
        $log->memberId = $this->authMember->id;
        $log->logLevel = $level;
        $log->logType = $type;
        $log->message = $message;
        $log->contextJson = isset($args['context'])
            ? json_encode($args['context'])
            : null;
        $log->createdAt = date('Y-m-d H:i:s');
        Bean::store($log);

        return json_encode([
            'success' => true,
            'log_id' => $log->id,
            'task_id' => $taskId
        ], JSON_PRETTY_PRINT);
    }

    /**
     * Convert camelCase to snake_case
     */
    private function camelToSnake(string $input): string {
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $input));
    }

    // =========================================
    // Playwright Browser Automation Tools
    // =========================================

    /**
     * Get Playwright proxy instance
     */
    private function getPlaywrightProxy(): \app\PlaywrightProxy {
        static $proxy = null;
        if ($proxy === null) {
            $proxy = new \app\PlaywrightProxy();
        }
        return $proxy;
    }

    /**
     * Check Playwright server status
     */
    private function toolPlaywrightStatus(array $args): string {
        $proxy = $this->getPlaywrightProxy();
        $status = $proxy->getStatus();

        return json_encode([
            'server_url' => $status['server_url'],
            'available' => $status['available'],
            'message' => $status['available']
                ? 'Playwright server is available'
                : 'Playwright server is not available. Check PLAYWRIGHT_MCP_URL configuration.'
        ], JSON_PRETTY_PRINT);
    }

    /**
     * Navigate to a URL
     */
    private function toolPlaywrightNavigate(array $args): string {
        if (!$this->authMember) {
            throw new \Exception("Authentication required for browser automation");
        }

        $url = $args['url'] ?? null;
        if (!$url) {
            throw new \Exception("URL is required");
        }

        $proxy = $this->getPlaywrightProxy();
        $result = $proxy->navigate($url, [
            'waitUntil' => $args['wait_until'] ?? 'load'
        ]);

        return json_encode([
            'success' => true,
            'url' => $url,
            'result' => $result
        ], JSON_PRETTY_PRINT);
    }

    /**
     * Take a screenshot
     */
    private function toolPlaywrightScreenshot(array $args): string {
        if (!$this->authMember) {
            throw new \Exception("Authentication required for browser automation");
        }

        $name = $args['name'] ?? 'screenshot';

        $proxy = $this->getPlaywrightProxy();
        $result = $proxy->screenshot($name, [
            'fullPage' => $args['full_page'] ?? false,
            'width' => $args['width'] ?? 1280,
            'height' => $args['height'] ?? 720
        ]);

        return json_encode([
            'success' => true,
            'name' => $name,
            'result' => $result
        ], JSON_PRETTY_PRINT);
    }

    /**
     * Click an element
     */
    private function toolPlaywrightClick(array $args): string {
        if (!$this->authMember) {
            throw new \Exception("Authentication required for browser automation");
        }

        $selector = $args['selector'] ?? null;
        if (!$selector) {
            throw new \Exception("Selector is required");
        }

        $proxy = $this->getPlaywrightProxy();
        $result = $proxy->click($selector);

        return json_encode([
            'success' => true,
            'selector' => $selector,
            'result' => $result
        ], JSON_PRETTY_PRINT);
    }

    /**
     * Fill a form field
     */
    private function toolPlaywrightFill(array $args): string {
        if (!$this->authMember) {
            throw new \Exception("Authentication required for browser automation");
        }

        $selector = $args['selector'] ?? null;
        $value = $args['value'] ?? null;

        if (!$selector || $value === null) {
            throw new \Exception("Selector and value are required");
        }

        $proxy = $this->getPlaywrightProxy();
        $result = $proxy->fill($selector, $value);

        return json_encode([
            'success' => true,
            'selector' => $selector,
            'result' => $result
        ], JSON_PRETTY_PRINT);
    }

    /**
     * Get page snapshot
     */
    private function toolPlaywrightSnapshot(array $args): string {
        if (!$this->authMember) {
            throw new \Exception("Authentication required for browser automation");
        }

        $proxy = $this->getPlaywrightProxy();
        $result = $proxy->getSnapshot();

        return json_encode([
            'success' => true,
            'result' => $result
        ], JSON_PRETTY_PRINT);
    }

    /**
     * Evaluate JavaScript
     */
    private function toolPlaywrightEvaluate(array $args): string {
        if (!$this->authMember) {
            throw new \Exception("Authentication required for browser automation");
        }

        $script = $args['script'] ?? null;
        if (!$script) {
            throw new \Exception("Script is required");
        }

        $proxy = $this->getPlaywrightProxy();
        $result = $proxy->evaluate($script);

        return json_encode([
            'success' => true,
            'result' => $result
        ], JSON_PRETTY_PRINT);
    }

    /**
     * Close browser session
     */
    private function toolPlaywrightClose(array $args): string {
        if (!$this->authMember) {
            throw new \Exception("Authentication required for browser automation");
        }

        $proxy = $this->getPlaywrightProxy();
        $result = $proxy->close();

        return json_encode([
            'success' => true,
            'message' => 'Browser session closed',
            'result' => $result
        ], JSON_PRETTY_PRINT);
    }

    // =========================================
    // Authentication
    // =========================================

    /**
     * Authenticate the request using various methods
     */
    private function authenticate(): bool {
        // Try Basic Auth first
        if ($this->authenticateBasic()) {
            return true;
        }

        // Try Bearer token
        if ($this->authenticateBearer()) {
            return true;
        }

        // Try custom header
        if ($this->authenticateCustomHeader()) {
            return true;
        }

        return false;
    }

    /**
     * Basic Auth: Authorization: Basic base64(username:password)
     */
    private function authenticateBasic(): bool {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';

        if (!preg_match('/^Basic\s+(.+)$/i', $authHeader, $matches)) {
            return false;
        }

        $decoded = base64_decode($matches[1]);
        if (!$decoded || strpos($decoded, ':') === false) {
            return false;
        }

        list($username, $password) = explode(':', $decoded, 2);

        // Find member by username or email
        $member = Bean::findOne('member', 'username = ? OR email = ?', [$username, $username]);

        if (!$member) {
            $this->logger->warning('MCP auth failed: user not found', ['username' => $username]);
            return false;
        }

        // Verify password
        if (!password_verify($password, $member->password)) {
            $this->logger->warning('MCP auth failed: invalid password', ['username' => $username]);
            return false;
        }

        $this->authMember = $member;
        $this->logger->debug('MCP authenticated via Basic Auth', ['member_id' => $member->id]);
        return true;
    }

    /**
     * Bearer Token: Authorization: Bearer <token>
     * Checks apikey table first, then falls back to member.api_token
     */
    private function authenticateBearer(): bool {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';

        if (!preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
            return false;
        }

        $token = trim($matches[1]);

        // First, try the new apikey table
        if ($this->authenticateApiKey($token)) {
            return true;
        }

        // Fall back to legacy member.api_token field
        $member = Bean::findOne('member', 'api_token = ? AND api_token IS NOT NULL', [$token]);

        if (!$member) {
            $this->logger->warning('MCP auth failed: invalid bearer token');
            return false;
        }

        $this->authMember = $member;
        $this->logger->debug('MCP authenticated via Bearer token (legacy)', ['member_id' => $member->id]);
        return true;
    }

    /**
     * Custom Header: X-MCP-Token: <token>
     * Checks apikey table first, then falls back to member.api_token
     */
    private function authenticateCustomHeader(): bool {
        $token = $_SERVER['HTTP_X_MCP_TOKEN'] ?? '';

        if (empty($token)) {
            return false;
        }

        // First, try the new apikey table
        if ($this->authenticateApiKey($token)) {
            return true;
        }

        // Fall back to legacy member.api_token field
        $member = Bean::findOne('member', 'api_token = ? AND api_token IS NOT NULL', [$token]);

        if (!$member) {
            $this->logger->warning('MCP auth failed: invalid X-MCP-Token');
            return false;
        }

        $this->authMember = $member;
        $this->logger->debug('MCP authenticated via X-MCP-Token (legacy)', ['member_id' => $member->id]);
        return true;
    }

    /**
     * Authenticate using the new apikey table
     * Validates token, expiration, and updates usage stats
     */
    private function authenticateApiKey(string $token): bool {
        if (empty($token)) {
            return false;
        }

        // Find the API key
        $key = Bean::findOne('apikey', 'token = ? AND is_active = 1', [$token]);

        if (!$key) {
            return false;
        }

        // Check expiration
        if ($key->expiresAt && strtotime($key->expiresAt) < time()) {
            $this->logger->warning('MCP auth failed: API key expired', ['key_id' => $key->id]);
            return false;
        }

        // Load the member associated with this key
        $member = Bean::load('member', $key->memberId);
        if (!$member->id) {
            $this->logger->warning('MCP auth failed: API key member not found', ['key_id' => $key->id]);
            return false;
        }

        // Update usage stats
        $key->lastUsedAt = date('Y-m-d H:i:s');
        $key->lastUsedIp = $_SERVER['REMOTE_ADDR'] ?? null;
        $key->usageCount = ($key->usageCount ?? 0) + 1;
        Bean::store($key);

        $this->authMember = $member;
        $this->authApiKey = $key;
        $this->logger->debug('MCP authenticated via API key', [
            'member_id' => $member->id,
            'key_id' => $key->id,
            'key_name' => $key->name
        ]);

        return true;
    }

    /**
     * Check if current API key has access to a specific server
     */
    public function hasServerAccess(string $serverSlug): bool {
        // If no API key (using legacy auth), allow all
        if (!$this->authApiKey) {
            return true;
        }

        $scopes = json_decode($this->authApiKey->scopes, true) ?: [];
        $allowedServers = json_decode($this->authApiKey->allowedServers, true) ?: [];

        // Full access scope allows everything
        if (in_array('mcp:*', $scopes)) {
            return true;
        }

        // If no server restrictions, allow all
        if (empty($allowedServers)) {
            return true;
        }

        // Check if server is in allowed list
        return in_array($serverSlug, $allowedServers);
    }

    /**
     * Get current API key scopes
     */
    public function getKeyScopes(): array {
        if (!$this->authApiKey) {
            return ['mcp:*']; // Legacy auth has full access
        }

        return json_decode($this->authApiKey->scopes, true) ?: [];
    }

    // =========================================
    // Response Helpers
    // =========================================

    /**
     * Send JSON-RPC success result
     */
    private function sendResult(mixed $id, array $result): void {
        echo json_encode([
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result
        ]);
    }

    /**
     * Send JSON-RPC error
     */
    private function sendError(int $code, string $message, mixed $id, int $httpCode = 200): void {
        http_response_code($httpCode);
        echo json_encode([
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => [
                'code' => $code,
                'message' => $message
            ]
        ]);
    }

    /**
     * Send tool result in MCP format
     */
    private function sendToolResult(mixed $id, string $content, bool $isError = false): void {
        $this->sendResult($id, [
            'content' => [
                [
                    'type' => 'text',
                    'text' => $content
                ]
            ],
            'isError' => $isError
        ]);
    }

    /**
     * Set CORS headers for cross-origin requests
     */
    private function setCorsHeaders(): void {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-MCP-Token');
        header('Access-Control-Max-Age: 86400');
    }

    /**
     * Get the full MCP endpoint URL from config
     */
    private function getMcpUrl(): string {
        $baseUrl = Flight::get('app.baseurl') ?? Flight::get('baseurl') ?? '';

        // Remove trailing slash if present
        $baseUrl = rtrim($baseUrl, '/');

        // Ensure HTTPS in production
        if (Flight::get('app.environment') === 'production' && strpos($baseUrl, 'http://') === 0) {
            $baseUrl = 'https://' . substr($baseUrl, 7);
        }

        return $baseUrl . '/mcp/message';
    }
}
