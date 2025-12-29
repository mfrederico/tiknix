#!/usr/bin/env php
<?php
/**
 * Unified OpenSwoole Server for Tiknix
 *
 * Single server handling:
 * - Web routes (/) → FlightPHP controllers
 * - MCP routes (/mcp/*) → Native MCP proxy with SSE
 * - SSE routes (/sse/*) → Server-Sent Events
 *
 * Benefits:
 * - Single process/deployment
 * - Persistent MCP connections
 * - Shared database connections
 * - Coroutine-based async I/O
 *
 * Usage: php swoole/bin/unified-server.php [--port=9501] [--workers=4]
 */

use OpenSwoole\Http\Server;
use OpenSwoole\Http\Request;
use OpenSwoole\Http\Response;
use OpenSwoole\Timer;
use OpenSwoole\Coroutine;
use OpenSwoole\Runtime;

// Enable coroutine hooks for blocking operations (curl, file I/O, etc.)
Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

// Parse command line options
$options = getopt('', ['port:', 'workers:', 'help', 'daemon', 'ssl', 'ssl-cert:', 'ssl-key:']);

if (isset($options['help'])) {
    echo "Unified OpenSwoole Server for Tiknix\n\n";
    echo "Usage: php swoole/bin/unified-server.php [options]\n\n";
    echo "Options:\n";
    echo "  --port=PORT        Port to listen on (default: 9501)\n";
    echo "  --workers=NUM      Number of worker processes (default: 4)\n";
    echo "  --daemon           Run as daemon\n";
    echo "  --ssl              Enable SSL\n";
    echo "  --ssl-cert=PATH    SSL certificate file\n";
    echo "  --ssl-key=PATH     SSL key file\n";
    echo "  --help             Show this help message\n";
    exit(0);
}

// Change to project root
chdir(dirname(__DIR__, 2));
define('BASE_PATH', getcwd());

// Load configuration
$config = require __DIR__ . '/../config/server.php';
$port = (int)($options['port'] ?? $config['port'] ?? 9501);
$workers = (int)($options['workers'] ?? $config['swoole']['worker_num'] ?? 4);

echo "=== Tiknix Unified OpenSwoole Server ===\n";
echo "Port: {$port}\n";
echo "Workers: {$workers}\n";
echo "Base Path: " . BASE_PATH . "\n";
echo "=========================================\n\n";

// Load Swoole session manager early (before server starts)
// OpenSwoole Tables MUST be created before workers fork to share memory
require_once BASE_PATH . '/swoole/src/Session/SwooleSessionManager.php';
\Tiknix\Swoole\Session\SwooleSessionManager::initTable(4096);
echo "[MAIN] Session table initialized (shared across workers)\n";

// Determine server mode
$serverMode = SWOOLE_PROCESS;
$socketType = SWOOLE_SOCK_TCP;

if (isset($options['ssl'])) {
    $socketType |= SWOOLE_SSL;
    echo "[SSL] Enabled\n";
}

// Create HTTP server
$server = new Server('0.0.0.0', $port, $serverMode, $socketType);

$serverSettings = [
    'worker_num' => $workers,
    'max_request' => $config['swoole']['max_request'] ?? 10000,
    'dispatch_mode' => $config['swoole']['dispatch_mode'] ?? 2,
    'enable_coroutine' => true,
    'max_coroutine' => $config['swoole']['max_coroutine'] ?? 10000,
    'package_max_length' => $config['swoole']['package_max_length'] ?? 2 * 1024 * 1024,
    'daemonize' => isset($options['daemon']),
    'log_file' => BASE_PATH . '/log/unified-server.log',
    'pid_file' => BASE_PATH . '/log/unified-server.pid',
    'buffer_output_size' => 32 * 1024 * 1024,
    'open_http2_protocol' => false,
];

// SSL settings
if (isset($options['ssl'])) {
    $serverSettings['ssl_cert_file'] = $options['ssl-cert'] ?? BASE_PATH . '/ssl/cert.pem';
    $serverSettings['ssl_key_file'] = $options['ssl-key'] ?? BASE_PATH . '/ssl/key.pem';
}

$server->set($serverSettings);

// Worker-local state
$connectionManager = null;
$tiknixBridge = null;
$flightInitialized = false;

$server->on('start', function (Server $server) use ($port) {
    cli_set_process_title('tiknix-master');
    echo "[MASTER] Server started on port {$port}\n";
    echo "[MASTER] PID: " . $server->master_pid . "\n";
});

// Per-worker tenant bridges cache
$tenantBridges = [];

// Worker-local app config
$appConfig = [];

$server->on('workerStart', function (Server $server, int $workerId) use (&$connectionManager, &$tenantBridges, &$flightInitialized, &$appConfig, $config) {
    cli_set_process_title("tiknix-worker-{$workerId}");
    echo "[WORKER {$workerId}] Started (PID: " . posix_getpid() . ")\n";

    // Load composer autoloader first (for RedBeanPHP, etc.)
    require_once BASE_PATH . '/vendor/autoload.php';

    // Load Swoole classes
    require_once BASE_PATH . '/swoole/src/McpConnectionManager.php';
    require_once BASE_PATH . '/swoole/src/TiknixBridge.php';
    require_once BASE_PATH . '/swoole/src/Session/SwooleSessionManager.php';
    require_once BASE_PATH . '/swoole/src/View/ViewRenderer.php';
    require_once BASE_PATH . '/swoole/src/Handlers/BaseHandler.php';
    require_once BASE_PATH . '/swoole/src/Handlers/AuthHandler.php';

    // Session table is already initialized in main process (shared)
    echo "[WORKER {$workerId}] Using shared session table\n";

    // Initialize connection manager (shared across tenants)
    $connectionManager = new \Tiknix\Swoole\McpConnectionManager();

    // Initialize default (master) Tiknix bridge
    $masterBridge = new \Tiknix\Swoole\TiknixBridge(null, 'conf/config.ini');
    $tenantBridges['_master'] = $masterBridge;

    // Load app config for handlers
    $configFile = BASE_PATH . '/conf/config.ini';
    if (file_exists($configFile)) {
        $appConfig = parse_ini_file($configFile, true);
    }

    // Register MCP servers from master database
    $servers = $masterBridge->getMcpServers();
    foreach ($servers as $slug => $serverConfig) {
        $connectionManager->registerServer($slug, $serverConfig);
        echo "[WORKER {$workerId}] Registered MCP: {$slug}\n";
    }

    // Initialize FlightPHP (once per worker) - for fallback routes
    initializeFlight();
    $flightInitialized = true;
    echo "[WORKER {$workerId}] FlightPHP initialized (fallback)\n";

    // Periodic session cleanup
    Timer::tick(300000, function () use ($connectionManager, $workerId) {
        $cleaned = $connectionManager->cleanupExpiredSessions();
        if ($cleaned > 0) {
            echo "[WORKER {$workerId}] Cleaned {$cleaned} MCP sessions\n";
        }
        // Also clean up Swoole sessions
        $sessionsCleaned = \Tiknix\Swoole\Session\SwooleSessionManager::cleanupExpired();
        if ($sessionsCleaned > 0) {
            echo "[WORKER {$workerId}] Cleaned {$sessionsCleaned} HTTP sessions\n";
        }
    });
});

/**
 * Get or create TiknixBridge for a specific tenant (subdomain)
 */
function getTenantBridge(?string $subdomain, array &$tenantBridges): \Tiknix\Swoole\TiknixBridge
{
    $key = $subdomain ?? '_master';

    if (!isset($tenantBridges[$key])) {
        $tenantBridges[$key] = new \Tiknix\Swoole\TiknixBridge($subdomain, 'conf/config.ini');
        echo "[TENANT] Created bridge for: {$key}\n";
    }

    return $tenantBridges[$key];
}

$server->on('request', function (Request $request, Response $response) use (&$connectionManager, &$tenantBridges, &$appConfig) {
    $uri = $request->server['request_uri'] ?? '/';
    $method = $request->server['request_method'] ?? 'GET';
    $host = $request->header['host'] ?? 'localhost';

    // Strip query string for routing
    $path = parse_url($uri, PHP_URL_PATH) ?? '/';

    // Extract subdomain for multi-tenant routing
    $subdomain = \Tiknix\Swoole\TiknixBridge::extractSubdomain($host);

    // Get tenant-specific bridge
    $tiknixBridge = getTenantBridge($subdomain, $tenantBridges);

    // Set common headers
    $response->header('X-Powered-By', 'Tiknix/OpenSwoole');
    $response->header('X-Tenant', $subdomain ?? 'master');

    try {
        // Route based on path prefix
        if (str_starts_with($path, '/mcp/registry')) {
            // MCP Registry UI routes - handled by FlightPHP
            handleFlightRequest($request, $response, $subdomain, $tiknixBridge);
        } elseif (str_starts_with($path, '/mcp/')) {
            // MCP proxy routes - native async handling
            handleMcpRoute($request, $response, $path, $connectionManager, $tiknixBridge);
        } elseif ($path === '/sse' || str_starts_with($path, '/sse/')) {
            // SSE routes - streaming responses
            handleSseRoute($request, $response, $path, $connectionManager, $tiknixBridge);
        } elseif ($path === '/health' || $path === '/api/health') {
            // Health check
            handleHealthCheck($response, $connectionManager, $subdomain);
        } elseif (str_starts_with($path, '/auth/')) {
            // Auth routes - native OpenSwoole handling
            handleAuthRoute($request, $response, $path, $appConfig);
        } elseif (str_starts_with($path, '/assets/') || preg_match('/\.(css|js|png|jpg|gif|ico|woff|woff2|ttf|svg)$/', $path)) {
            // Static files
            handleStaticFile($request, $response, $path);
        } else {
            // Everything else goes to FlightPHP (fallback)
            // Pass subdomain for tenant-specific routing
            handleFlightRequest($request, $response, $subdomain, $tiknixBridge);
        }
    } catch (\Throwable $e) {
        echo "[ERROR] [{$subdomain}] " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine() . "\n";
        $response->status(500);
        $response->header('Content-Type', 'application/json');
        $response->end(json_encode([
            'error' => 'Internal Server Error',
            'message' => $e->getMessage(),
        ]));
    }
});

$server->on('workerStop', function (Server $server, int $workerId) {
    echo "[WORKER {$workerId}] Stopping\n";
});

/**
 * Initialize FlightPHP/Tiknix for this worker
 */
function initializeFlight(): void
{
    // Mark as OpenSwoole environment (before loading bootstrap)
    if (!defined('TIKNIX_OPENSWOOLE')) {
        define('TIKNIX_OPENSWOOLE', true);
    }

    // Suppress Flight from auto-starting
    if (!defined('FLIGHT_AUTOSTART')) {
        define('FLIGHT_AUTOSTART', false);
    }

    // Initialize empty $_SESSION for compatibility with CSRF library
    if (!isset($_SESSION)) {
        $_SESSION = [];
    }

    // Load Tiknix bootstrap
    require_once BASE_PATH . '/bootstrap.php';
}

/**
 * Handle requests via FlightPHP/Tiknix Bootstrap
 *
 * @param Request $swooleRequest
 * @param Response $swooleResponse
 * @param string|null $subdomain Current tenant subdomain
 * @param \Tiknix\Swoole\TiknixBridge $bridge Tenant bridge instance
 */
function handleFlightRequest(Request $swooleRequest, Response $swooleResponse, ?string $subdomain = null, $bridge = null): void
{
    // Initialize $_SESSION from Swoole session (for session sharing with auth routes)
    $swooleSession = new \Tiknix\Swoole\Session\SwooleSessionManager($swooleRequest);
    $_SESSION = $swooleSession->all();

    // Populate PHP superglobals from Swoole request
    $_GET = $swooleRequest->get ?? [];
    $_POST = $swooleRequest->post ?? [];
    $_COOKIE = $swooleRequest->cookie ?? [];
    $_FILES = $swooleRequest->files ?? [];
    $_SERVER = [];

    // Map Swoole server vars to PHP format
    foreach ($swooleRequest->server as $key => $value) {
        $_SERVER[strtoupper($key)] = $value;
    }

    // Add headers
    foreach ($swooleRequest->header ?? [] as $key => $value) {
        $headerKey = 'HTTP_' . strtoupper(str_replace('-', '_', $key));
        $_SERVER[$headerKey] = $value;
    }

    $_SERVER['REQUEST_URI'] = $swooleRequest->server['request_uri'] ?? '/';
    $_SERVER['REQUEST_METHOD'] = $swooleRequest->server['request_method'] ?? 'GET';
    $_SERVER['QUERY_STRING'] = $swooleRequest->server['query_string'] ?? '';
    $_SERVER['REMOTE_ADDR'] = $swooleRequest->server['remote_addr'] ?? '127.0.0.1';
    $_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
    $_SERVER['DOCUMENT_ROOT'] = BASE_PATH . '/public';
    $_SERVER['SCRIPT_FILENAME'] = BASE_PATH . '/public/index.php';
    $_SERVER['HTTP_HOST'] = $swooleRequest->header['host'] ?? 'localhost';

    // Handle raw body for POST/PUT
    if (in_array($_SERVER['REQUEST_METHOD'], ['POST', 'PUT', 'PATCH'])) {
        $rawContent = $swooleRequest->rawContent();
        $GLOBALS['HTTP_RAW_POST_DATA'] = $rawContent;

        // Parse JSON body into $_POST if content-type is JSON
        $contentType = $swooleRequest->header['content-type'] ?? '';
        if (stripos($contentType, 'application/json') !== false && $rawContent) {
            $jsonData = json_decode($rawContent, true);
            if (is_array($jsonData)) {
                $_POST = array_merge($_POST, $jsonData);
            }
        }
    }

    // Capture output
    ob_start();

    try {
        // CRITICAL: Clear FlightPHP view state between requests
        // FlightPHP's View object persists in Swoole workers, causing
        // error/success messages from previous requests to leak into new ones
        if (class_exists('Flight', false)) {
            try {
                \Flight::view()->clear();
            } catch (\Throwable $e) {
                // View may not be initialized yet on first request
            }
        }

        // Determine config file based on subdomain
        $configFile = 'conf/config.ini';
        if ($subdomain) {
            $tenantConfig = "conf/config.{$subdomain}.ini";
            if (file_exists(BASE_PATH . '/' . $tenantConfig)) {
                $configFile = $tenantConfig;
            }
        }

        // Initialize Tiknix Bootstrap for this request
        $app = new \app\Bootstrap($configFile);

        // Load routes based on request URI
        $routePath = BASE_PATH . '/routes';
        $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
        $segments = explode('/', trim(parse_url($requestUri, PHP_URL_PATH), '/'));
        $firstSegment = (!empty($segments[0])) ? $segments[0] : 'index';

        // Load specific route file if exists, otherwise default
        $specificRoute = $routePath . '/' . $firstSegment . '.php';
        if (file_exists($specificRoute)) {
            require $specificRoute;
        } else {
            require $routePath . '/default.php';
        }

        // Run the application
        $app->run();

        // Save $_SESSION changes back to Swoole session (for CSRF tokens, etc.)
        foreach ($_SESSION as $key => $value) {
            $swooleSession->set($key, $value);
        }

    } catch (\Throwable $e) {
        ob_end_clean();
        $swooleResponse->status(500);
        $swooleResponse->header('Content-Type', 'text/html');
        $swooleResponse->end('<h1>500 Internal Server Error</h1><p>' . htmlspecialchars($e->getMessage()) . '</p>');
        echo "[ERROR] " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine() . "\n";
        return;
    }

    $output = ob_get_clean();

    // Set session cookie on response
    $swooleSession->start($swooleResponse);

    // Get response info from Flight
    $flightResponse = \Flight::response();

    // Set status code
    $statusCode = 200;
    if (method_exists($flightResponse, 'status')) {
        $statusCode = $flightResponse->status() ?: 200;
    }
    $swooleResponse->status($statusCode);

    // Copy headers from Flight
    $headers = [];
    if (method_exists($flightResponse, 'getHeaders')) {
        $headers = $flightResponse->getHeaders();
    } elseif (method_exists($flightResponse, 'headers')) {
        $headers = $flightResponse->headers();
    }

    foreach ($headers as $name => $value) {
        if (is_array($value)) {
            $value = implode(', ', $value);
        }
        $swooleResponse->header($name, $value);
    }

    // Send response
    $swooleResponse->end($output);
}

/**
 * Handle MCP proxy routes
 */
function handleMcpRoute(Request $request, Response $response, string $uri, $connectionManager, $tiknixBridge): void
{
    $response->header('Content-Type', 'application/json');
    $response->header('Access-Control-Allow-Origin', '*');
    $response->header('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
    $response->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-API-Key, mcp-session-id');

    $method = $request->server['request_method'] ?? 'GET';

    // Handle preflight
    if ($method === 'OPTIONS') {
        $response->status(204);
        $response->end();
        return;
    }

    // Route MCP endpoints
    $path = substr($uri, 5); // Remove /mcp/

    switch ($path) {
        case 'proxy':
            handleMcpProxy($request, $response, $connectionManager, $tiknixBridge);
            break;

        case 'tools':
            handleMcpTools($request, $response, $connectionManager, $tiknixBridge);
            break;

        case 'sessions':
            $response->end(json_encode(['sessions' => $connectionManager->getSessions()]));
            break;

        case 'message':
            // Standard MCP message endpoint (JSON-RPC)
            handleMcpMessage($request, $response, $connectionManager, $tiknixBridge);
            break;

        default:
            $response->status(404);
            $response->end(json_encode(['error' => 'Unknown MCP endpoint', 'path' => $path]));
    }
}

/**
 * Handle MCP proxy requests
 */
function handleMcpProxy(Request $request, Response $response, $manager, $bridge): void
{
    if ($request->server['request_method'] !== 'POST') {
        $response->status(405);
        $response->end(json_encode(['error' => 'Method not allowed']));
        return;
    }

    $body = json_decode($request->rawContent(), true);
    if (!$body) {
        $response->status(400);
        $response->end(json_encode(['error' => 'Invalid JSON']));
        return;
    }

    // Authenticate
    $apiKey = $request->header['x-api-key'] ?? $request->header['authorization'] ?? null;
    if ($apiKey && str_starts_with($apiKey, 'Bearer ')) {
        $apiKey = substr($apiKey, 7);
    }

    $auth = $bridge->authenticateApiKey($apiKey);
    if (!$auth['valid']) {
        $response->status(401);
        $response->end(json_encode(['error' => $auth['error'] ?? 'Invalid API key']));
        return;
    }

    $apiKeyId = $auth['api_key_id'];
    $serverSlug = $body['server'] ?? null;
    $toolName = $body['tool'] ?? null;
    $arguments = $body['arguments'] ?? [];

    if (!$serverSlug || !$toolName) {
        $response->status(400);
        $response->end(json_encode(['error' => 'Missing server or tool']));
        return;
    }

    if (!$bridge->canAccessServer($apiKeyId, $serverSlug)) {
        $response->status(403);
        $response->end(json_encode(['error' => "Access denied: {$serverSlug}"]));
        return;
    }

    echo "[MCP] {$serverSlug}:{$toolName} (Key: {$apiKeyId})\n";

    $result = $manager->callTool($apiKeyId, $serverSlug, $toolName, $arguments);

    if (isset($result['error'])) {
        $response->status(500);
    }

    $bridge->logMcpRequest($apiKeyId, $serverSlug, $toolName, $arguments, $result);

    $response->end(json_encode($result));
}

/**
 * Handle MCP JSON-RPC message endpoint
 */
function handleMcpMessage(Request $request, Response $response, $manager, $bridge): void
{
    // Debug logging - log every MCP request
    $logFile = '/home/mfrederico/development/tiknix/log/mcp-debug.log';
    $logData = [
        'time' => date('Y-m-d H:i:s'),
        'method' => $request->getMethod(),
        'uri' => $request->server['request_uri'] ?? '',
        'headers' => $request->header,
        'body' => $request->rawContent(),
    ];
    file_put_contents($logFile, json_encode($logData, JSON_PRETTY_PRINT) . "\n---\n", FILE_APPEND);

    // Set common headers
    $response->header('Access-Control-Allow-Origin', '*');
    $response->header('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
    $response->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-API-Key, mcp-session-id');

    // Handle OPTIONS preflight
    if ($request->getMethod() === 'OPTIONS') {
        $response->status(204);
        $response->end();
        return;
    }

    // Handle GET requests - SSE stream for server-to-client notifications
    // Claude Code opens this after initialize to receive server-initiated events
    // This stream should remain open for the duration of the session
    if ($request->getMethod() === 'GET') {
        $sessionId = $request->header['mcp-session-id'] ?? bin2hex(random_bytes(16));

        $response->header('Content-Type', 'text/event-stream');
        $response->header('Cache-Control', 'no-cache');
        $response->header('Connection', 'keep-alive');
        $response->header('mcp-session-id', $sessionId);

        // Send an initial endpoint event to indicate the stream is ready
        $response->write("event: endpoint\ndata: /mcp/message\n\n");

        // Keep the connection alive by NOT calling end()
        // OpenSwoole will keep the connection open until client disconnects
        // Tiknix doesn't have server-initiated events but the stream must remain open
        return;
    }

    // This implements the MCP server protocol for Claude to connect directly
    $body = json_decode($request->rawContent(), true);

    if (!$body || !isset($body['method'])) {
        // Return proper JSON-RPC 2.0 error format
        $response->header('Content-Type', 'text/event-stream');
        $response->end("event: message\ndata: " . json_encode([
            'jsonrpc' => '2.0',
            'id' => null,
            'error' => [
                'code' => -32600,
                'message' => 'Invalid Request: Missing method'
            ]
        ]) . "\n\n");
        return;
    }

    $method = $body['method'];
    $id = $body['id'] ?? null;
    $params = $body['params'] ?? [];

    // Generate or retrieve session ID
    $sessionId = $request->header['mcp-session-id'] ?? bin2hex(random_bytes(16));

    // Helper to send SSE response - ALWAYS use SSE format like Playwright does
    // Even when Accept includes application/json, Playwright returns SSE format
    // Claude Code expects this format for MCP HTTP transport
    $sendResponse = function($data) use ($response, $sessionId) {
        $response->header('Content-Type', 'text/event-stream; charset=utf-8');
        $response->header('Cache-Control', 'no-cache');
        $response->header('mcp-session-id', $sessionId);
        $response->write("event: message\ndata: " . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n");
        $response->end();
    };

    // Handle MCP protocol methods
    switch ($method) {
        case 'initialize':
            // Use the protocol version the client requests, or default to 2024-11-05
            $clientVersion = $params['protocolVersion'] ?? '2024-11-05';
            $sendResponse([
                'result' => [
                    'protocolVersion' => $clientVersion,
                    'capabilities' => ['tools' => new \stdClass()],
                    'serverInfo' => [
                        'name' => 'Tiknix MCP Proxy',
                        'version' => '1.0.0',
                    ],
                ],
                'jsonrpc' => '2.0',
                'id' => $id,
            ]);
            break;

        case 'tools/list':
            // Gateway mode: return ALL tools (builtin + proxied) under tiknix namespace
            $allTools = $bridge->getAvailableTools(null);
            $tools = [];
            foreach ($allTools as $tool) {
                // Strip non-standard fields that might confuse Claude Code
                unset($tool['server']);
                unset($tool['fullName']);
                unset($tool['annotations']);
                // Clean inputSchema of non-standard fields and fix invalid schemas
                if (isset($tool['inputSchema'])) {
                    unset($tool['inputSchema']['$schema']);
                    unset($tool['inputSchema']['additionalProperties']);
                    // FIX: Convert empty array properties to empty object (JSON Schema compliance)
                    // Playwright returns "properties": [] which is invalid - must be object
                    if (isset($tool['inputSchema']['properties']) &&
                        is_array($tool['inputSchema']['properties']) &&
                        empty($tool['inputSchema']['properties'])) {
                        $tool['inputSchema']['properties'] = new \stdClass();
                    }
                }
                $tools[] = $tool;
            }
            $sendResponse([
                'result' => ['tools' => $tools],
                'jsonrpc' => '2.0',
                'id' => $id,
            ]);
            break;

        case 'tools/call':
            // Authenticate for tool calls
            $apiKey = $request->header['x-api-key'] ?? $request->header['authorization'] ?? null;
            if ($apiKey && str_starts_with($apiKey, 'Bearer ')) {
                $apiKey = substr($apiKey, 7);
            }

            $auth = $bridge->authenticateApiKey($apiKey);
            if (!$auth['valid']) {
                $sendResponse([
                    'error' => ['code' => -32000, 'message' => 'Authentication required'],
                    'jsonrpc' => '2.0',
                    'id' => $id,
                ]);
                return;
            }

            $toolName = $params['name'] ?? '';
            $arguments = $params['arguments'] ?? [];

            // Parse server:tool format (explicit routing)
            if (str_contains($toolName, ':')) {
                [$serverSlug, $toolName] = explode(':', $toolName, 2);
            } else {
                // Auto-discover which server owns this tool (gateway mode)
                $serverSlug = $bridge->findToolServer($toolName);
                if ($serverSlug === null) {
                    $sendResponse([
                        'error' => ['code' => -32601, 'message' => "Unknown tool: {$toolName}"],
                        'jsonrpc' => '2.0',
                        'id' => $id,
                    ]);
                    return;
                }
            }

            // Route to appropriate handler
            if ($serverSlug === \Tiknix\Swoole\TiknixBridge::BUILTIN_SERVER_SLUG) {
                // Built-in tiknix tool
                $authContext = [
                    'api_key_id' => $auth['api_key_id'],
                    'member_id' => $auth['member_id'] ?? 0,
                ];
                $result = $bridge->executeBuiltinTool($toolName, $arguments, $authContext);

                // Log the tool call
                $bridge->logMcpRequest(
                    $auth['api_key_id'],
                    \Tiknix\Swoole\TiknixBridge::BUILTIN_SERVER_SLUG,
                    $toolName,
                    $arguments,
                    $result
                );
            } else {
                // Proxy to external MCP server
                $result = $manager->callTool($auth['api_key_id'], $serverSlug, $toolName, $arguments);
            }

            $sendResponse([
                'result' => $result,
                'jsonrpc' => '2.0',
                'id' => $id,
            ]);
            break;

        case 'notifications/initialized':
            $response->status(202);
            $response->end();
            break;

        default:
            $sendResponse([
                'error' => ['code' => -32601, 'message' => "Method not found: {$method}"],
                'jsonrpc' => '2.0',
                'id' => $id,
            ]);
    }
}

/**
 * Handle MCP tools listing
 */
function handleMcpTools(Request $request, Response $response, $manager, $bridge): void
{
    $apiKey = $request->header['x-api-key'] ?? null;
    $tools = $bridge->getAvailableTools($apiKey);
    $response->end(json_encode(['tools' => $tools]));
}

/**
 * Handle auth routes natively
 */
function handleAuthRoute(Request $request, Response $response, string $path, array $config): void
{
    // Extract action from path: /auth/login -> login
    $action = trim(substr($path, 6), '/'); // Remove '/auth/' prefix
    if (empty($action)) {
        $action = 'login';
    }

    // Create session manager
    $session = new \Tiknix\Swoole\Session\SwooleSessionManager($request);

    // Start session cookie
    $session->start($response);

    // Create and run auth handler
    $handler = new \Tiknix\Swoole\Handlers\AuthHandler(
        $request,
        $response,
        $session,
        $config
    );

    $handler->handle($action);
}

/**
 * Handle SSE routes
 */
function handleSseRoute(Request $request, Response $response, string $uri, $manager, $bridge): void
{
    $response->header('Content-Type', 'text/event-stream');
    $response->header('Cache-Control', 'no-cache');
    $response->header('Connection', 'keep-alive');
    $response->header('Access-Control-Allow-Origin', '*');

    // Generate session ID
    $sessionId = bin2hex(random_bytes(16));

    // Send initial endpoint event
    $response->write("event: endpoint\n");
    $response->write("data: /sse?sessionId={$sessionId}\n\n");

    // Keep connection alive for SSE
    // In a real implementation, you'd handle incoming POST requests
    // to this session and stream responses back

    // For now, just keep the connection open
    $response->end();
}

/**
 * Handle health check
 */
function handleHealthCheck(Response $response, $manager, ?string $subdomain = null): void
{
    $response->header('Content-Type', 'application/json');
    $sessions = $manager ? $manager->getSessions() : [];
    $response->end(json_encode([
        'status' => 'ok',
        'server' => 'Tiknix Unified OpenSwoole',
        'version' => '1.0.0',
        'tenant' => $subdomain ?? 'master',
        'mcp_sessions' => count($sessions),
        'uptime' => time(),
        'memory' => round(memory_get_usage(true) / 1024 / 1024, 2) . 'MB',
    ]));
}

/**
 * Handle static files
 */
function handleStaticFile(Request $request, Response $response, string $uri): void
{
    $publicPath = BASE_PATH . '/public';
    $filePath = $publicPath . $uri;

    // Security: prevent directory traversal
    $realPath = realpath($filePath);
    if (!$realPath || !str_starts_with($realPath, $publicPath)) {
        $response->status(404);
        $response->end('Not found');
        return;
    }

    if (!is_file($realPath)) {
        $response->status(404);
        $response->end('Not found');
        return;
    }

    // Set content type
    $mimeTypes = [
        'css' => 'text/css',
        'js' => 'application/javascript',
        'json' => 'application/json',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
    ];

    $ext = strtolower(pathinfo($realPath, PATHINFO_EXTENSION));
    $contentType = $mimeTypes[$ext] ?? 'application/octet-stream';

    $response->header('Content-Type', $contentType);
    $response->header('Cache-Control', 'public, max-age=86400');
    $response->sendfile($realPath);
}

// Start the server
$server->start();
