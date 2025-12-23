<?php
/**
 * MCP (Model Context Protocol) Server Controller
 *
 * Implements the MCP HTTP Streamable transport for AI tool integration.
 * This allows Claude Code and other MCP clients to interact with your application.
 *
 * Endpoint: POST /mcp/message
 *
 * Authentication methods (in order of precedence):
 * 1. Basic Auth: Authorization: Basic base64(username:password)
 * 2. Bearer Token: Authorization: Bearer <token>
 * 3. Custom Header: X-MCP-Token: <token>
 *
 * @see https://modelcontextprotocol.io/
 */

namespace app;

use \Flight as Flight;
use \RedBeanPHP\R as R;

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
        ]
    ];

    /**
     * Authenticated member for this request
     */
    private ?object $authMember = null;

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

        // Authenticate the request
        if (!$this->authenticate()) {
            $this->sendError(-32000, 'Authentication required', null, 401);
            return;
        }

        // Parse JSON-RPC request
        $rawBody = file_get_contents('php://input');
        $request = json_decode($rawBody, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->sendError(-32700, 'Parse error: Invalid JSON', null);
            return;
        }

        $this->logger->debug('MCP request received', [
            'method' => $request['method'] ?? 'unknown',
            'member_id' => $this->authMember->id ?? 0
        ]);

        // Route to appropriate handler
        $method = $request['method'] ?? '';
        $id = $request['id'] ?? null;
        $params = $request['params'] ?? [];

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
        R::store($this->authMember);

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
     */
    private function handleToolsList(mixed $id): void {
        $toolList = [];

        foreach ($this->tools as $name => $config) {
            $toolList[] = [
                'name' => $name,
                'description' => $config['description'],
                'inputSchema' => $config['inputSchema']
            ];
        }

        $this->sendResult($id, ['tools' => $toolList]);
    }

    /**
     * Handle tools/call request
     */
    private function handleToolsCall(mixed $id, array $params): void {
        $toolName = $params['name'] ?? '';
        $arguments = $params['arguments'] ?? [];

        if (!isset($this->tools[$toolName])) {
            $this->sendError(-32602, "Unknown tool: {$toolName}", $id);
            return;
        }

        $this->logger->info('MCP tool call', [
            'tool' => $toolName,
            'member_id' => $this->authMember->id ?? 0
        ]);

        try {
            $result = $this->executeTool($toolName, $arguments);
            $this->sendToolResult($id, $result);
        } catch (\Exception $e) {
            $this->logger->error('MCP tool error', [
                'tool' => $toolName,
                'error' => $e->getMessage()
            ]);
            $this->sendToolResult($id, "Error: " . $e->getMessage(), true);
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

        $users = R::find('member', 'ORDER BY id LIMIT ?', [$limit]);

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
        $member = R::findOne('member', 'username = ? OR email = ?', [$username, $username]);

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
     * Token can be stored in member.api_token field
     */
    private function authenticateBearer(): bool {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';

        if (!preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
            return false;
        }

        $token = trim($matches[1]);

        // Find member by API token
        $member = R::findOne('member', 'api_token = ? AND api_token IS NOT NULL', [$token]);

        if (!$member) {
            $this->logger->warning('MCP auth failed: invalid bearer token');
            return false;
        }

        $this->authMember = $member;
        $this->logger->debug('MCP authenticated via Bearer token', ['member_id' => $member->id]);
        return true;
    }

    /**
     * Custom Header: X-MCP-Token: <token>
     */
    private function authenticateCustomHeader(): bool {
        $token = $_SERVER['HTTP_X_MCP_TOKEN'] ?? '';

        if (empty($token)) {
            return false;
        }

        // Find member by API token
        $member = R::findOne('member', 'api_token = ? AND api_token IS NOT NULL', [$token]);

        if (!$member) {
            $this->logger->warning('MCP auth failed: invalid X-MCP-Token');
            return false;
        }

        $this->authMember = $member;
        $this->logger->debug('MCP authenticated via X-MCP-Token', ['member_id' => $member->id]);
        return true;
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
