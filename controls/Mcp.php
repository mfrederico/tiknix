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
     * Requires authentication to include the user's API token
     */
    public function config($params = null): void {
        header('Content-Type: application/json');
        $this->setCorsHeaders();

        // Check if user is authenticated (for personalized config with token)
        $hasAuth = $this->authenticate();

        $mcpUrl = $this->getMcpUrl();
        $config = [
            'mcpServers' => [
                self::SERVER_NAME => [
                    'type' => 'http',
                    'url' => $mcpUrl
                ]
            ]
        ];

        // Add auth header if user is authenticated and has a token
        if ($hasAuth && $this->authMember && !empty($this->authMember->api_token)) {
            $config['mcpServers'][self::SERVER_NAME]['headers'] = [
                'Authorization' => 'Bearer ' . $this->authMember->api_token
            ];
        } else {
            // Show placeholder for unauthenticated requests
            $config['mcpServers'][self::SERVER_NAME]['headers'] = [
                'Authorization' => 'Bearer YOUR_API_TOKEN'
            ];
            $config['_note'] = 'Authenticate to get your personalized config with API token';
        }

        echo json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
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
