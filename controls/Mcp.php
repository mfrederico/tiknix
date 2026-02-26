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
 *   Claude Code (single config) --> Tiknix Gateway --> Backend MCP Servers
 *                                     |
 *                                     |-- Built-in Tiknix tools (via fastmcphp)
 *                                     |-- Shopify MCP
 *                                     |-- GitHub MCP
 *                                     +-- Custom MCP servers
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
 * LAYER 2: Controller-Level (API Key Auth via McpAuthProvider)
 * - tools/call requires valid API key (enforced by fastmcphp Server)
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
use \app\McpAuthProvider;
use \app\mcptools\ToolLoader;
use \app\mcptools\FastMcpToolAdapter;
use Fastmcphp\Server\Server;
use Fastmcphp\Server\Auth\AuthRequest;
use Fastmcphp\Server\Auth\AuthenticatedUser;
use Fastmcphp\Protocol\JsonRpc;
use Fastmcphp\Protocol\JsonRpcException;
use Fastmcphp\Protocol\ErrorCodes;
use Fastmcphp\Tools\FunctionTool;

class Mcp extends BaseControls\Control {

    private const SERVER_NAME = 'tiknix-mcp';
    private const SERVER_VERSION = '2.0.0';

    /** @var float Request start time for duration tracking */
    private float $requestStartTime = 0;

    /** @var bool Whether to use plain JSON responses instead of SSE */
    private bool $useJsonResponse = false;

    /** @var string Current request body for logging */
    private string $currentRequestBody = '';

    /** @var int Current server ID for proxied requests */
    private int $currentServerId = 0;

    /** @var array Cached MCP session IDs per server (serverSlug => sessionId) */
    private array $mcpSessions = [];

    /** @var string Current MCP session ID for this gateway */
    private string $gatewaySessionId = '';

    /** @var ToolLoader Tool loader instance */
    private ?ToolLoader $toolLoader = null;

    /** @var Server fastmcphp server instance */
    private Server $server;

    /** @var AuthenticatedUser|null Current authenticated user */
    private ?AuthenticatedUser $authUser = null;

    /** @var object|null Authenticated member bean (for proxy/logging compatibility) */
    private ?object $authMember = null;

    /** @var object|null API key bean (for proxy/logging compatibility) */
    private ?object $authApiKey = null;

    public function __construct() {
        // Skip parent constructor to avoid session/CSRF for API endpoints
        $this->logger = Flight::get('log');

        // Initialize tool loader
        $this->toolLoader = new ToolLoader(dirname(__DIR__) . '/mcptools');
        $this->toolLoader->setMcp($this);

        // Initialize fastmcphp server
        $this->server = new Server(
            name: self::SERVER_NAME,
            version: self::SERVER_VERSION,
            logger: $this->logger,
        );

        // Set auth provider - auth required for non-public methods
        $this->server->setAuthProvider(new McpAuthProvider(), required: true);

        // Register built-in tools from ToolLoader as fastmcphp tools
        $this->registerBuiltinTools();
    }

    /**
     * Register all BaseTool classes from ToolLoader as fastmcphp Tool adapters
     */
    private function registerBuiltinTools(): void {
        foreach ($this->toolLoader->getNames() as $toolName) {
            $tool = $this->toolLoader->get($toolName);
            if ($tool) {
                $className = get_class($tool);
                $adapter = new FastMcpToolAdapter($className, 'tiknix', $this);
                $this->server->addTool($adapter);
            }
        }
    }

    /**
     * Get the tool loader instance
     */
    public function getToolLoader(): ToolLoader {
        return $this->toolLoader;
    }

    /**
     * Main MCP message endpoint
     * POST /mcp/message
     */
    public function message($params = null): void {
        $this->requestStartTime = microtime(true);

        // Detect content type preference from Accept header
        $acceptHeader = $_SERVER['HTTP_ACCEPT'] ?? 'text/event-stream';
        $hasJson = strpos($acceptHeader, 'application/json') !== false;
        $hasSse = strpos($acceptHeader, 'text/event-stream') !== false;
        $this->useJsonResponse = $hasJson && !$hasSse;

        if ($this->useJsonResponse) {
            header('Content-Type: application/json; charset=utf-8');
        } else {
            header('Content-Type: text/event-stream; charset=utf-8');
        }
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');

        // Handle MCP session ID
        $incomingSessionId = $_SERVER['HTTP_MCP_SESSION_ID'] ?? null;
        $this->gatewaySessionId = $incomingSessionId ?: $this->generateUuid4();
        header('mcp-session-id: ' . $this->gatewaySessionId);

        // Handle CORS preflight
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            $this->setCorsHeaders();
            http_response_code(200);
            exit;
        }

        $this->setCorsHeaders();

        // Handle GET requests for SSE stream
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $this->handleSseStream();
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->sendJsonRpcError(-32600, 'Method not allowed. Use POST or GET.', null, 405);
            return;
        }

        // Parse request body
        $rawBody = file_get_contents('php://input');
        $this->currentRequestBody = $rawBody;

        // Build AuthRequest from PHP superglobals
        $authRequest = $this->buildAuthRequest();

        // Try to resolve auth for proxy/logging (sets authMember, authApiKey)
        $this->resolveAuthContext($authRequest);

        ob_start();

        try {
            // Parse JSON-RPC message via fastmcphp
            $message = JsonRpc::parse($rawBody);

            // Check if this is a proxy tool call (server:tool format)
            if ($message instanceof \Fastmcphp\Protocol\Request
                && $message->method === 'tools/call'
                && $this->isProxyToolCall($message)) {
                $this->handleProxyToolCall($message, $authRequest);
            } else {
                // Register proxy server tools for tools/list
                if ($message instanceof \Fastmcphp\Protocol\Request
                    && $message->method === 'tools/list') {
                    $this->registerProxyToolDefinitions();
                }

                // Delegate to fastmcphp Server
                $response = $this->server->handle($message, $authRequest);

                if ($response !== null) {
                    $this->outputResponse($response);
                }
            }
        } catch (JsonRpcException $e) {
            $this->sendJsonRpcError($e->getCode(), $e->getMessage(), null);
        } catch (\Throwable $e) {
            $this->logger->error('MCP unhandled error', ['error' => $e->getMessage()]);
            $this->sendJsonRpcError(ErrorCodes::INTERNAL_ERROR, $e->getMessage(), null);
        }

        $responseBody = ob_get_flush();
        $httpCode = http_response_code() ?: 200;

        $responseData = json_decode($responseBody, true);
        $error = isset($responseData['error']) ? ($responseData['error']['message'] ?? null) : null;

        // Determine method from request body for logging
        $requestData = json_decode($rawBody, true);
        $method = $requestData['method'] ?? 'unknown';

        $this->logMcpRequest($method, $responseBody, $httpCode, $error);
    }

    // =========================================
    // Proxy / Gateway Logic
    // =========================================

    /**
     * Check if a tools/call request targets a proxy backend
     */
    private function isProxyToolCall(\Fastmcphp\Protocol\Request $request): bool {
        $toolName = $request->getParam('name', '');
        if (strpos($toolName, ':') === false) {
            return false;
        }
        [$serverSlug] = explode(':', $toolName, 2);
        return $serverSlug !== 'tiknix';
    }

    /**
     * Handle a proxied tool call to a backend MCP server
     */
    private function handleProxyToolCall(\Fastmcphp\Protocol\Request $request, AuthRequest $authRequest): void {
        $fullToolName = $request->getParam('name', '');
        $arguments = $request->getParam('arguments', []);

        [$serverSlug, $toolName] = explode(':', $fullToolName, 2);

        // Require authentication for proxy calls
        $authResult = (new McpAuthProvider())->authenticate($authRequest);
        if (!$authResult->isSuccess()) {
            $error = JsonRpc::encodeError($request->id, ErrorCodes::UNAUTHORIZED, 'Authentication required for proxy calls');
            $this->outputResponse($error);
            return;
        }

        $user = $authResult->getUser();
        $startTime = microtime(true);
        $responseStatus = 'success';
        $errorMessage = null;

        $this->logger->info('MCP proxy tool call', [
            'full_tool' => $fullToolName,
            'server' => $serverSlug,
            'tool' => $toolName,
            'member_id' => $user->getExtra('member_id', 0),
        ]);

        try {
            // Check server access
            $allowedServers = $user->getExtra('allowed_servers', []);
            if (!empty($allowedServers) && !in_array($serverSlug, $allowedServers)) {
                if (!$user->hasScope('mcp:*')) {
                    throw new \Exception("Access denied to server: {$serverSlug}");
                }
            }

            $result = $this->proxyToolCall($serverSlug, $toolName, $arguments);

            $response = JsonRpc::encodeResult($request->id, [
                'content' => [['type' => 'text', 'text' => $result]],
            ]);
            $this->outputResponse($response);

        } catch (\Exception $e) {
            $responseStatus = 'error';
            $errorMessage = $e->getMessage();

            $this->logger->error('MCP proxy tool error', [
                'server' => $serverSlug,
                'tool' => $toolName,
                'error' => $e->getMessage(),
            ]);

            $response = JsonRpc::encodeResult($request->id, [
                'content' => [['type' => 'text', 'text' => 'Error: ' . $e->getMessage()]],
                'isError' => true,
            ]);
            $this->outputResponse($response);
        }

        $this->logUsage($serverSlug, $toolName, $arguments, $responseStatus, $errorMessage, $startTime);
    }

    /**
     * Register proxy server tool definitions (for tools/list)
     * Adds FunctionTool stubs so they appear in the tool list
     */
    private function registerProxyToolDefinitions(): void {
        $servers = $this->getAllowedServers();
        foreach ($servers as $server) {
            if ($server->slug === 'tiknix-mcp') {
                continue;
            }

            $serverTools = $this->getServerTools($server);
            foreach ($serverTools as $tool) {
                $name = $server->slug . ':' . $tool['name'];
                $description = '[' . $server->name . '] ' . ($tool['description'] ?? '');
                $schema = $tool['inputSchema'] ?? ['type' => 'object', 'properties' => new \stdClass()];

                // Fix empty properties to be objects
                if (isset($schema['properties']) && is_array($schema['properties']) && empty($schema['properties'])) {
                    $schema['properties'] = new \stdClass();
                }

                // Create a stub FunctionTool for the listing
                $stubTool = new FunctionTool(
                    callable: fn() => 'proxy stub',
                    name: $name,
                    description: $description,
                );

                // Override input schema via reflection (FunctionTool generates from callable)
                $ref = new \ReflectionClass($stubTool);
                $prop = $ref->getProperty('inputSchema');
                $prop->setAccessible(true);
                // readonly can't be set via reflection in PHP 8.2+, use direct tool registration
                // Instead, register a simple wrapper
                $this->server->addTool(new class($name, $description, $schema) implements \Fastmcphp\Tools\Tool {
                    public function __construct(
                        private string $name,
                        private string $description,
                        private array $schema,
                    ) {}
                    public function getName(): string { return $this->name; }
                    public function getDescription(): string { return $this->description; }
                    public function getInputSchema(): array { return $this->schema; }
                    public function execute(array $arguments, ?\Fastmcphp\Server\Context $context = null): \Fastmcphp\Tools\ToolResult {
                        return \Fastmcphp\Tools\ToolResult::error('This tool should be handled by proxy routing');
                    }
                    public function toMcpTool(): array {
                        return [
                            'name' => $this->name,
                            'description' => $this->description,
                            'inputSchema' => $this->schema,
                        ];
                    }
                });
            }
        }
    }

    /**
     * Proxy a tool call to a backend MCP server
     */
    private function proxyToolCall(string $serverSlug, string $toolName, array $arguments): string {
        $server = Bean::findOne('mcpserver', 'slug = ? AND status = ?', [$serverSlug, 'active']);

        if (!$server) {
            throw new \Exception("Server not found: {$serverSlug}");
        }

        if (!$server->isProxyEnabled) {
            throw new \Exception("Proxy disabled for server: {$serverSlug}");
        }

        $baseHeaders = [
            'Content-Type: application/json',
            'Accept: application/json, text/event-stream'
        ];
        if (!empty($server->backendAuthToken)) {
            $headerName = $server->backendAuthHeader ?: 'Authorization';
            $prefix = ($headerName === 'Authorization') ? 'Bearer ' : '';
            $baseHeaders[] = "{$headerName}: {$prefix}{$server->backendAuthToken}";
        }

        $sessionId = $this->getMcpSessionId($serverSlug);
        $needsInit = ($sessionId === null);

        if ($needsInit) {
            $sessionId = $this->initializeMcpSession($server, $baseHeaders);
            if ($sessionId) {
                $this->storeMcpSessionId($serverSlug, $sessionId);
            }
        }

        $request = json_encode([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => [
                'name' => $toolName,
                'arguments' => empty($arguments) ? new \stdClass() : $arguments
            ]
        ]);

        $response = $this->sendProxyRequest($server->endpointUrl, $request, $baseHeaders, $sessionId);

        // If session expired, retry with new session
        if ($response === null) {
            $this->clearMcpSessionId($serverSlug);
            $sessionId = $this->initializeMcpSession($server, $baseHeaders);
            if ($sessionId) {
                $this->storeMcpSessionId($serverSlug, $sessionId);
            }
            $response = $this->sendProxyRequest($server->endpointUrl, $request, $baseHeaders, $sessionId);
        }

        if ($response === null) {
            throw new \Exception("Backend request failed for server: {$serverSlug}");
        }

        // Update session expiry
        if ($sessionId) {
            $this->storeMcpSessionId($serverSlug, $sessionId);
        }

        return $response;
    }

    /**
     * Send a request to a proxy backend and extract the text result
     * Returns null if session needs reinitialization
     */
    private function sendProxyRequest(string $url, string $body, array $baseHeaders, ?string $sessionId): ?string {
        $headers = $baseHeaders;
        if ($sessionId) {
            $headers[] = 'mcp-session-id: ' . $sessionId;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
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

        // Session expired
        if ($httpCode === 404 || $httpCode === 400) {
            return null;
        }

        if ($httpCode !== 200) {
            throw new \Exception("Backend returned HTTP {$httpCode}");
        }

        // Parse SSE or JSON response
        $data = $this->parseBackendResponse($response);

        if (isset($data['error'])) {
            throw new \Exception($data['error']['message'] ?? 'Backend error');
        }

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
     * Parse a backend response (SSE or JSON format)
     */
    private function parseBackendResponse(string $response): ?array {
        if (strpos($response, 'event:') !== false || strpos($response, 'data:') !== false) {
            if (preg_match('/data:\s*(\{.*\})/s', $response, $matches)) {
                return json_decode($matches[1], true);
            }
            return null;
        }
        return json_decode($response, true);
    }

    // =========================================
    // Auth Helper
    // =========================================

    /**
     * Build an AuthRequest from PHP superglobals
     */
    private function buildAuthRequest(): AuthRequest {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (strpos($key, 'HTTP_') === 0) {
                $headerName = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$headerName] = $value;
            }
        }
        // Also check REDIRECT_HTTP_AUTHORIZATION (Apache mod_rewrite)
        if (!isset($headers['authorization']) && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $headers['authorization'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }

        return new AuthRequest(
            headers: $headers,
            query: $_GET,
            body: $this->currentRequestBody,
        );
    }

    /**
     * Resolve auth context for proxy/logging compatibility
     * Sets authMember and authApiKey from the AuthRequest
     */
    private function resolveAuthContext(AuthRequest $authRequest): void {
        $authResult = (new McpAuthProvider())->authenticate($authRequest);
        if ($authResult->isSuccess()) {
            $this->authUser = $authResult->getUser();

            // Load the member bean for proxy compatibility
            $memberId = $this->authUser->getExtra('member_id', 0);
            if ($memberId) {
                $this->authMember = Bean::load('member', $memberId);
            }

            $apikeyId = $this->authUser->getExtra('apikey_id', 0);
            if ($apikeyId) {
                $this->authApiKey = Bean::load('apikey', $apikeyId);
            }
        }
    }

    // =========================================
    // Backend Server Management
    // =========================================

    /**
     * Get all servers the current user/API key has access to
     */
    private function getAllowedServers(): array {
        $servers = Bean::find('mcpserver', 'status = ? AND is_proxy_enabled = ? ORDER BY featured DESC, sort_order ASC', ['active', 1]);

        if ($this->authApiKey) {
            $allowedSlugs = json_decode($this->authApiKey->allowedServers, true) ?: [];
            if (empty($allowedSlugs)) {
                return $servers;
            }
            return array_filter($servers, fn($s) => in_array($s->slug, $allowedSlugs));
        }

        return $servers;
    }

    /**
     * Get tools from a backend server (with caching)
     */
    private function getServerTools($server): array {
        if ($server->toolsCache && $server->toolsCachedAt) {
            $cacheAge = time() - strtotime($server->toolsCachedAt);
            if ($cacheAge < 3600) {
                $cached = json_decode($server->toolsCache, true);
                if ($cached !== null) {
                    return $cached;
                }
            }
        }

        if (strpos($server->endpointUrl, 'http') !== 0) {
            return json_decode($server->tools, true) ?: [];
        }

        try {
            $tools = $this->fetchBackendTools($server);
            $server->toolsCache = json_encode($tools);
            $server->toolsCachedAt = date('Y-m-d H:i:s');
            Bean::store($server);
            return $tools;
        } catch (\Exception $e) {
            $this->logger->warning('Failed to fetch tools from backend', [
                'server' => $server->slug,
                'error' => $e->getMessage()
            ]);
            return json_decode($server->tools, true) ?: [];
        }
    }

    /**
     * Fetch tools from a backend MCP server
     */
    private function fetchBackendTools($server): array {
        $baseHeaders = [
            'Content-Type: application/json',
            'Accept: application/json, text/event-stream'
        ];
        if (!empty($server->backendAuthToken)) {
            $headerName = $server->backendAuthHeader ?: 'Authorization';
            $prefix = ($headerName === 'Authorization') ? 'Bearer ' : '';
            $baseHeaders[] = "{$headerName}: {$prefix}{$server->backendAuthToken}";
        }

        // Initialize session
        $initRequest = json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2024-11-05',
                'capabilities' => new \stdClass(),
                'clientInfo' => ['name' => 'Tiknix MCP Proxy', 'version' => '1.0.0']
            ]
        ]);

        $ch = curl_init($server->endpointUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $initRequest,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_HTTPHEADER => $baseHeaders,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true
        ]);

        $initResponse = curl_exec($ch);
        $initHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $initError = curl_error($ch);
        curl_close($ch);

        if ($initError) {
            throw new \Exception("Connection error: {$initError}");
        }
        if ($initHttpCode < 200 || $initHttpCode >= 300) {
            throw new \Exception("Initialize failed: HTTP {$initHttpCode}");
        }

        $initHeaders = substr($initResponse, 0, $headerSize);
        $sessionId = null;
        if (preg_match('/mcp-session-id:\s*([^\r\n]+)/i', $initHeaders, $matches)) {
            $sessionId = trim($matches[1]);
        }

        // Fetch tools list
        $request = json_encode([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/list',
            'params' => new \stdClass()
        ]);

        $headers = $baseHeaders;
        if ($sessionId) {
            $headers[] = 'mcp-session-id: ' . $sessionId;
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

        $data = $this->parseBackendResponse($response);
        return $data['result']['tools'] ?? [];
    }

    /**
     * Initialize MCP session with backend server
     */
    private function initializeMcpSession($server, array $baseHeaders, bool $isRetry = false): ?string {
        $initRequest = json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2024-11-05',
                'capabilities' => new \stdClass(),
                'clientInfo' => ['name' => 'Tiknix MCP Proxy', 'version' => '1.0.0']
            ]
        ]);

        $ch = curl_init($server->endpointUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $initRequest,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_HTTPHEADER => $baseHeaders,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true
        ]);

        $initResponse = curl_exec($ch);
        $initHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $initError = curl_error($ch);
        curl_close($ch);

        if ($initError && !$isRetry) {
            if ($this->tryStartServer($server)) {
                return $this->initializeMcpSession($server, $baseHeaders, true);
            }
        }

        if ($initError) {
            throw new \Exception("Backend connection error: {$initError}");
        }
        if ($initHttpCode < 200 || $initHttpCode >= 300) {
            throw new \Exception("Backend initialize failed: HTTP {$initHttpCode}");
        }

        $initHeaders = substr($initResponse, 0, $headerSize);
        $sessionId = null;
        if (preg_match('/mcp-session-id:\s*([^\r\n]+)/i', $initHeaders, $matches)) {
            $sessionId = trim($matches[1]);
        }

        return $sessionId;
    }

    /**
     * Try to start an MCP server using its configured startup command
     */
    private function tryStartServer($server, int $maxWaitSeconds = 10): bool {
        if (empty($server->startupCommand)) {
            return false;
        }

        $allowedCommands = ['npx', 'node', 'php', 'python', 'python3', 'ruby', 'java', 'go', 'deno', 'bun'];
        $command = trim($server->startupCommand);
        if (!in_array($command, $allowedCommands)) {
            return false;
        }

        $args = trim($server->startupArgs ?? '');
        $fullCommand = escapeshellcmd($command);
        if (!empty($args)) {
            foreach (preg_split('/\s+/', $args) as $arg) {
                $fullCommand .= ' ' . escapeshellarg($arg);
            }
        }

        $logFile = '/tmp/mcp-server-' . $server->slug . '.log';
        $pidFile = '/tmp/mcp-server-' . $server->slug . '.pid';
        $fullCommand = 'nohup ' . $fullCommand . ' > ' . escapeshellarg($logFile) . ' 2>&1 & echo $! > ' . escapeshellarg($pidFile);

        $workingDir = trim($server->startupWorkingDir ?? '');
        if (!empty($workingDir) && is_dir($workingDir)) {
            $fullCommand = 'cd ' . escapeshellarg($workingDir) . ' && ' . $fullCommand;
        }

        if (file_exists($pidFile)) {
            $existingPid = (int)trim(file_get_contents($pidFile));
            if ($existingPid > 0 && file_exists("/proc/{$existingPid}")) {
                usleep(500000);
                return true;
            }
        }

        $this->logger->info('Auto-starting MCP server', ['server' => $server->slug]);
        exec($fullCommand, $output, $returnCode);

        $startTime = time();
        while ((time() - $startTime) < $maxWaitSeconds) {
            usleep(500000);
            $ch = curl_init($server->endpointUrl);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode([
                    'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
                    'params' => ['protocolVersion' => '2024-11-05', 'capabilities' => new \stdClass(),
                                 'clientInfo' => ['name' => 'Tiknix', 'version' => '1.0.0']]
                ]),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json, text/event-stream'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 3,
                CURLOPT_CONNECTTIMEOUT => 2
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode >= 200 && $httpCode < 300) {
                return true;
            }
        }

        return false;
    }

    // =========================================
    // Session Management
    // =========================================

    private function getMcpSessionId(string $serverSlug): ?string {
        if (isset($this->mcpSessions[$serverSlug])) {
            return $this->mcpSessions[$serverSlug];
        }

        $apiKeyId = $this->authApiKey->id ?? 0;
        if (!$apiKeyId) {
            return null;
        }

        $session = Bean::findOne('mcpsession', 'apikey_id = ? AND server_slug = ? AND expires_at > ?',
            [$apiKeyId, $serverSlug, date('Y-m-d H:i:s')]);

        if ($session) {
            $this->mcpSessions[$serverSlug] = $session->sessionId;
            return $session->sessionId;
        }

        return null;
    }

    private function storeMcpSessionId(string $serverSlug, string $sessionId): void {
        $this->mcpSessions[$serverSlug] = $sessionId;

        $apiKeyId = $this->authApiKey->id ?? 0;
        if (!$apiKeyId) {
            return;
        }

        $session = Bean::findOne('mcpsession', 'apikey_id = ? AND server_slug = ?', [$apiKeyId, $serverSlug]);
        if (!$session) {
            $session = Bean::dispense('mcpsession');
            $session->apikeyId = $apiKeyId;
            $session->serverSlug = $serverSlug;
        }
        $session->sessionId = $sessionId;
        $session->expiresAt = date('Y-m-d H:i:s', time() + 1800);
        $session->updatedAt = date('Y-m-d H:i:s');
        Bean::store($session);
    }

    private function clearMcpSessionId(string $serverSlug): void {
        unset($this->mcpSessions[$serverSlug]);

        $apiKeyId = $this->authApiKey->id ?? 0;
        if (!$apiKeyId) {
            return;
        }

        $session = Bean::findOne('mcpsession', 'apikey_id = ? AND server_slug = ?', [$apiKeyId, $serverSlug]);
        if ($session) {
            Bean::trash($session);
        }
    }

    /**
     * Check if current API key has access to a specific server
     */
    public function hasServerAccess(string $serverSlug): bool {
        if (!$this->authApiKey) {
            return true;
        }
        $scopes = json_decode($this->authApiKey->scopes, true) ?: [];
        $allowedServers = json_decode($this->authApiKey->allowedServers, true) ?: [];

        if (in_array('mcp:*', $scopes)) {
            return true;
        }
        if (empty($allowedServers)) {
            return true;
        }
        return in_array($serverSlug, $allowedServers);
    }

    /**
     * Get current API key scopes
     */
    public function getKeyScopes(): array {
        if (!$this->authApiKey) {
            return ['mcp:*'];
        }
        return json_decode($this->authApiKey->scopes, true) ?: [];
    }

    // =========================================
    // Response Helpers
    // =========================================

    /**
     * Output a JSON-RPC response string in SSE or plain JSON format
     */
    private function outputResponse(string $json): void {
        if ($this->useJsonResponse) {
            echo $json;
        } else {
            echo "event: message\ndata: {$json}\n\n";
        }
        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
    }

    private function sendJsonRpcError(int $code, string $message, mixed $id, int $httpCode = 200): void {
        if ($httpCode !== 200) {
            http_response_code($httpCode);
        }
        $json = JsonRpc::encodeError($id, $code, $message);
        $this->outputResponse($json);
    }

    // =========================================
    // Non-MCP Endpoints
    // =========================================

    /**
     * Handle GET requests for SSE stream
     */
    private function handleSseStream(): void {
        ignore_user_abort(false);
        set_time_limit(0);

        echo ": connected\n\n";
        @ob_flush();
        @flush();

        $startTime = time();
        while (connection_status() === CONNECTION_NORMAL && (time() - $startTime) < 30) {
            echo ": keepalive\n\n";
            @ob_flush();
            @flush();
            sleep(5);
        }
    }

    /**
     * Health check endpoint - GET /mcp/health
     */
    public function health($params = null): void {
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'healthy',
            'server' => self::SERVER_NAME,
            'version' => self::SERVER_VERSION,
            'timestamp' => date('c')
        ]);
    }

    /**
     * Documentation endpoint - GET /mcp/index
     */
    public function index($params = null): void {
        $this->render('mcp/index', [
            'title' => 'MCP Server',
            'serverName' => self::SERVER_NAME,
            'serverVersion' => self::SERVER_VERSION,
            'mcpUrl' => $this->getMcpUrl()
        ]);
    }

    /**
     * MCP Registry - forwards to Mcpregistry controller
     */
    public function registry($params = null): void {
        $instance = new Mcpregistry();
        $method = $params['operation']->name ?? 'index';
        $forwardParams = $params;
        $forwardParams['operation'] = new \stdClass();
        $forwardParams['operation']->name = $params['operation']->type ?? null;

        if (method_exists($instance, $method)) {
            $instance->$method($forwardParams);
        } else {
            $instance->index($forwardParams);
        }
    }

    /**
     * Claude Code configuration endpoint - GET /mcp/config
     */
    public function config($params = null): void {
        header('Content-Type: application/json');
        $this->setCorsHeaders();

        $authRequest = $this->buildAuthRequest();
        $this->resolveAuthContext($authRequest);

        $mcpUrl = $this->getMcpUrl();
        $serverConfig = ['type' => 'http', 'url' => $mcpUrl];
        $response = ['mcpServers' => (object)[self::SERVER_NAME => $serverConfig]];

        if ($this->authMember) {
            $token = null;
            $keyName = null;
            $keyScopes = [];
            $allowedServerSlugs = [];

            if ($this->authApiKey) {
                $token = $this->authApiKey->token;
                $keyName = $this->authApiKey->name;
                $keyScopes = json_decode($this->authApiKey->scopes, true) ?: [];
                $allowedServerSlugs = json_decode($this->authApiKey->allowedServers, true) ?: [];
            } elseif (!empty($this->authMember->api_token)) {
                $token = $this->authMember->api_token;
                $keyName = 'Legacy Token';
                $keyScopes = ['mcp:*'];
            }

            if ($token) {
                $response['mcpServers']->{self::SERVER_NAME}['headers'] = [
                    'Authorization' => 'Bearer ' . $token
                ];
            }

            $servers = $this->getAllowedServers();
            $accessibleServers = [];
            foreach ($servers as $server) {
                $tools = json_decode($server->tools, true) ?: [];
                $accessibleServers[] = [
                    'slug' => $server->slug,
                    'name' => $server->name,
                    'tool_count' => count($tools),
                    'status' => $server->status
                ];
            }

            $response['_meta'] = [
                'authenticated' => true,
                'user' => $this->authMember->username ?? $this->authMember->email,
                'api_key' => [
                    'name' => $keyName,
                    'scopes' => $keyScopes,
                    'server_restrictions' => empty($allowedServerSlugs) ? 'none (full access)' : $allowedServerSlugs
                ],
                'accessible_servers' => $accessibleServers,
            ];
        } else {
            $response['mcpServers']->{self::SERVER_NAME}['headers'] = [
                'Authorization' => 'Bearer YOUR_API_TOKEN'
            ];
            $response['_meta'] = [
                'authenticated' => false,
                'note' => 'Authenticate with Basic Auth or API key to get your personalized config',
                'get_api_key' => rtrim($mcpUrl, '/message') . '/../apikeys'
            ];
        }

        echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Generate API token - POST /mcp/token
     */
    public function token($params = null): void {
        header('Content-Type: application/json');

        $authRequest = $this->buildAuthRequest();
        $this->resolveAuthContext($authRequest);

        if (!$this->authMember) {
            http_response_code(401);
            echo json_encode(['error' => 'Authentication required']);
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Use POST to generate a new token']);
            return;
        }

        $token = bin2hex(random_bytes(32));
        $this->authMember->api_token = $token;
        Bean::store($this->authMember);

        echo json_encode([
            'success' => true,
            'api_token' => $token,
            'config' => [
                'mcpServers' => (object)[
                    self::SERVER_NAME => [
                        'type' => 'http',
                        'url' => $this->getMcpUrl(),
                        'headers' => ['Authorization' => 'Bearer ' . $token]
                    ]
                ]
            ]
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    // =========================================
    // Logging
    // =========================================

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
            $this->logger->warning('Failed to log MCP usage', ['error' => $e->getMessage()]);
        }
    }

    private function logMcpRequest(string $method, string $responseBody, int $httpCode = 200, ?string $error = null): void {
        try {
            $duration = $this->requestStartTime > 0
                ? round((microtime(true) - $this->requestStartTime) * 1000)
                : 0;

            $log = Bean::dispense('mcplog');
            $log->memberId = isset($this->authMember->id) && $this->authMember->id > 0 ? $this->authMember->id : null;
            $log->apiKeyId = isset($this->authApiKey->id) && $this->authApiKey->id > 0 ? $this->authApiKey->id : null;
            $log->serverId = $this->currentServerId > 0 ? $this->currentServerId : null;
            $log->method = $method;
            $log->requestBody = $this->currentRequestBody;
            $log->responseBody = $responseBody;
            $log->httpCode = $httpCode;
            $log->duration = $duration;
            $log->ipAddress = $_SERVER['REMOTE_ADDR'] ?? '';
            $log->userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
            $log->error = $error;
            $log->createdAt = date('Y-m-d H:i:s');
            Bean::store($log);
        } catch (\Exception $e) {
            $this->logger->error('Failed to log MCP request', ['error' => $e->getMessage()]);
        }
    }

    // =========================================
    // Utility
    // =========================================

    private function generateUuid4(): string {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    private function setCorsHeaders(): void {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-MCP-Token');
        header('Access-Control-Max-Age: 86400');
    }

    private function getMcpUrl(): string {
        $baseUrl = Flight::get('app.baseurl') ?? Flight::get('baseurl') ?? '';
        $baseUrl = rtrim($baseUrl, '/');

        if (Flight::get('app.environment') === 'production' && strpos($baseUrl, 'http://') === 0) {
            $baseUrl = 'https://' . substr($baseUrl, 7);
        }

        return $baseUrl . '/mcp/message';
    }

    // =========================================
    // Static Config Management Methods
    // =========================================

    public static function ensureMcpConfig(string $workspacePath, ?string $apiKey = null, ?string $baseUrl = null): bool {
        $configPath = rtrim($workspacePath, '/') . '/.mcp.json';
        $config = self::loadMcpConfig($configPath);

        if (!isset($config['mcpServers']) || !is_array($config['mcpServers'])) {
            $config['mcpServers'] = [];
        }

        $originalServers = $config['mcpServers'];
        ksort($originalServers);

        if (!isset($config['mcpServers']['playwright'])) {
            $config['mcpServers']['playwright'] = [
                'command' => 'npx',
                'args' => ['@playwright/mcp@latest', '--headless']
            ];
        }

        if (!isset($config['mcpServers']['mantic'])) {
            $config['mcpServers']['mantic'] = [
                'command' => 'node',
                'args' => ['/home/mfrederico/development/Mantic.sh/dist/mcp-server.js']
            ];
        }

        if ($apiKey) {
            $config['mcpServers']['tiknix'] = self::generateServerConfig($apiKey, $baseUrl);
        }

        $newServers = $config['mcpServers'];
        ksort($newServers);
        if ($originalServers === $newServers) {
            return false;
        }

        $output = ['mcpServers' => new \stdClass()];
        foreach ($config['mcpServers'] as $name => $serverConfig) {
            $output['mcpServers']->$name = self::arrayToObject($serverConfig);
        }

        return self::saveMcpConfig($configPath, $output);
    }

    public static function loadMcpConfig(string $configPath): array {
        if (!file_exists($configPath)) {
            return ['mcpServers' => []];
        }

        $content = file_get_contents($configPath);
        if ($content === false) {
            return ['mcpServers' => []];
        }

        $config = json_decode($content, true);
        if (!is_array($config)) {
            return ['mcpServers' => []];
        }

        if (isset($config['mcpServers']) && is_object($config['mcpServers'])) {
            $config['mcpServers'] = (array)$config['mcpServers'];
        }

        return $config;
    }

    public static function getAvailableServers(): array {
        $servers = [];

        $servers['tiknix'] = [
            'slug' => 'tiknix',
            'type' => 'http',
            'source' => 'system',
            'description' => 'Tiknix MCP Server - PHP validation, workbench tools',
            'config' => [
                'type' => 'http',
                'url' => self::buildMcpUrl(),
                'headers' => ['Authorization' => 'Bearer {API_KEY}']
            ]
        ];

        $projectRoot = \Flight::get('project.root') ?? dirname(__DIR__);
        $configPath = $projectRoot . '/.mcp.json';
        $config = self::loadMcpConfig($configPath);

        if (!empty($config['mcpServers'])) {
            foreach ($config['mcpServers'] as $slug => $serverConfig) {
                if ($slug === 'tiknix') {
                    continue;
                }
                $servers[$slug] = [
                    'slug' => $slug,
                    'type' => $serverConfig['type'] ?? 'unknown',
                    'source' => 'user',
                    'description' => $serverConfig['description'] ?? '',
                    'config' => $serverConfig
                ];
            }
        }

        return $servers;
    }

    public static function generateServerConfig(string $apiKey, ?string $baseUrl = null): array {
        return [
            'type' => 'http',
            'url' => self::buildMcpUrl($baseUrl),
            'headers' => ['Authorization' => 'Bearer ' . $apiKey]
        ];
    }

    public static function saveMcpConfig(string $configPath, array $config): bool {
        $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        return file_put_contents($configPath, $json) !== false;
    }

    private static function buildMcpUrl(?string $baseUrl = null): string {
        if (!$baseUrl) {
            $baseUrl = Flight::get('app.baseurl') ?? Flight::get('baseurl') ?? 'https://dev.tiknix.com';
        }

        $baseUrl = rtrim($baseUrl, '/');

        $env = Flight::get('app.environment');
        if ($env === 'production' && strpos($baseUrl, 'http://') === 0) {
            $baseUrl = 'https://' . substr($baseUrl, 7);
        }

        return $baseUrl . '/mcp/message';
    }

    private static function arrayToObject(mixed $data): mixed {
        if (!is_array($data)) {
            return $data;
        }

        if (array_keys($data) === range(0, count($data) - 1)) {
            return array_map([self::class, 'arrayToObject'], $data);
        }

        $obj = new \stdClass();
        foreach ($data as $key => $value) {
            $obj->$key = self::arrayToObject($value);
        }
        return $obj;
    }
}
