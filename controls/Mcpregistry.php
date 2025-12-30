<?php
/**
 * MCP Server Registry Controller
 *
 * Admin CRUD for managing registered MCP servers.
 * Provides a registry of MCP servers that can be queried via the MCP tool.
 */

namespace app;

use \Flight as Flight;
use \app\Bean;
use \Exception as Exception;
use app\BaseControls\Control;

class Mcpregistry extends Control {

    const ADMIN_LEVEL = 50;

    /** @var bool Flag to indicate if auth failed and response was already sent */
    private bool $authHandled = false;

    public function __construct() {
        parent::__construct();

        // Allow public access to index and API endpoints
        $url = Flight::request()->url;

        // Truly public endpoints (no auth at all - for discovery):
        $trulyPublicPaths = [
            '/mcpregistry/api',  // Server listing API for discovery
            '/mcpregistry/checkStatus',  // Server status check (not sensitive)
        ];

        // Endpoints that require API key OR user login:
        $apiKeyOrLoginPaths = [
            '/mcpregistry/fetchTools',
            '/mcpregistry/testConnection',
            '/mcpregistry/fixSlug',
            '/mcpregistry/fixApiKey',
            '/mcpregistry/checkStatus',
            '/mcpregistry/stopServer',
            '/mcpregistry/startServer',
        ];

        // Check if URL exactly matches /mcpregistry or /mcpregistry/
        $isRegistryIndex = preg_match('#^/mcpregistry/?$#', $url);

        // Check if truly public
        $isTrulyPublic = false;
        foreach ($trulyPublicPaths as $path) {
            if (strpos($url, $path) !== false) {
                $isTrulyPublic = true;
                break;
            }
        }

        // Check if requires API key or login
        $requiresApiKeyOrLogin = false;
        foreach ($apiKeyOrLoginPaths as $path) {
            if (strpos($url, $path) !== false) {
                $requiresApiKeyOrLogin = true;
                break;
            }
        }

        // Truly public endpoints - no auth required
        if ($isTrulyPublic) {
            return;
        }

        // Registry index (browsing) requires login
        if ($isRegistryIndex) {
            // Fall through to login check below
        }

        // Detect AJAX/JSON requests - check various header formats
        $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        $isAjax = Flight::request()->ajax ||
                  strpos($accept, 'application/json') !== false ||
                  strpos($contentType, 'application/json') !== false;

        // API key or login protected endpoints (no admin level required)
        if ($requiresApiKeyOrLogin) {
            // Check for API key first
            $apiKey = $this->getApiKeyFromRequest();
            if ($apiKey && $this->validateApiKey($apiKey)) {
                return; // Valid API key, allow access
            }

            // Check if user is logged in (no admin level required for these endpoints)
            if (Flight::isLoggedIn()) {
                return; // Logged in user, allow access
            }

            // Not authenticated
            $this->authHandled = true;
            if ($isAjax) {
                Flight::jsonError('Authentication required', 401);
            } else {
                Flight::redirect('/auth/login?redirect=' . urlencode($url));
            }
            return;
        }

        // All other endpoints require login + admin level
        if (!Flight::isLoggedIn()) {
            $this->authHandled = true;
            if ($isAjax) {
                Flight::jsonError('Authentication required', 401);
            } else {
                Flight::redirect('/auth/login?redirect=' . urlencode($url));
            }
            return;
        }

        // Check if user has admin level
        if ($this->member->level > self::ADMIN_LEVEL) {
            $this->logger->warning('Unauthorized MCP Registry access attempt', [
                'member_id' => $this->member->id,
                'member_level' => $this->member->level,
                'ip' => Flight::request()->ip
            ]);
            $this->authHandled = true;
            if ($isAjax) {
                Flight::jsonError('Access denied', 403);
            } else {
                Flight::redirect('/');
            }
            return;
        }
    }

    /**
     * Check if auth was handled in constructor (and action should not run)
     */
    private function shouldSkipAction(): bool {
        return $this->authHandled;
    }

    /**
     * Get API key from request headers
     * Uses $_SERVER which works in both FPM and CLI/Swoole environments
     */
    private function getApiKeyFromRequest(): ?string {
        // Authorization: Bearer <token> or Basic auth
        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (!empty($auth)) {
            if (preg_match('/Bearer\s+(.+)/i', $auth, $matches)) {
                return $matches[1];
            }
            // Basic auth - extract from base64
            if (preg_match('/Basic\s+(.+)/i', $auth, $matches)) {
                $decoded = base64_decode($matches[1]);
                if (strpos($decoded, ':') !== false) {
                    // username:password format - use password as token
                    return explode(':', $decoded, 2)[1];
                }
            }
        }

        // X-API-Key header (HTTP_X_API_KEY in $_SERVER)
        if (!empty($_SERVER['HTTP_X_API_KEY'])) {
            return $_SERVER['HTTP_X_API_KEY'];
        }

        // X-MCP-Token header (HTTP_X_MCP_TOKEN in $_SERVER)
        if (!empty($_SERVER['HTTP_X_MCP_TOKEN'])) {
            return $_SERVER['HTTP_X_MCP_TOKEN'];
        }

        // Query parameter fallback (for testing)
        if (isset($_GET['api_key'])) {
            return $_GET['api_key'];
        }

        return null;
    }

    /**
     * Validate API key
     */
    private function validateApiKey(string $token): bool {
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
            $this->logger->warning('MCP Registry auth failed: API key expired', ['key_id' => $key->id]);
            return false;
        }

        // Update usage stats
        $key->lastUsedAt = date('Y-m-d H:i:s');
        $key->lastUsedIp = $_SERVER['REMOTE_ADDR'] ?? null;
        $key->usageCount = ($key->usageCount ?? 0) + 1;
        Bean::store($key);

        $this->logger->debug('MCP Registry authenticated via API key', [
            'key_id' => $key->id,
            'key_name' => $key->name
        ]);

        return true;
    }

    /**
     * List all MCP servers
     */
    public function index($params = []) {
        if ($this->shouldSkipAction()) return;
        $this->viewData['title'] = 'MCP Server Registry';

        $request = Flight::request();

        // Handle delete action
        if ($request->query->delete && is_numeric($request->query->delete)) {
            $server = Bean::load('mcpserver', $request->query->delete);
            if ($server->id) {
                $this->logger->info('MCP server deleted', [
                    'id' => $server->id,
                    'name' => $server->name,
                    'deleted_by' => $this->member->id
                ]);
                Bean::trash($server);
            }
            Flight::redirect('/mcpregistry');
            return;
        }

        // Filter by status if provided
        $status = $request->query->status ?? '';
        if ($status && in_array($status, ['active', 'inactive', 'deprecated'])) {
            $servers = Bean::find('mcpserver', 'status = ? ORDER BY sort_order ASC, name ASC', [$status]);
        } else {
            $servers = Bean::findAll('mcpserver', 'ORDER BY sort_order ASC, name ASC');
        }

        $this->viewData['servers'] = $servers;
        $this->viewData['statusFilter'] = $status;

        $this->render('mcp_registry/index', $this->viewData);
    }

    /**
     * Add new MCP server
     */
    public function add($params = []) {
        if ($this->shouldSkipAction()) return;
        $request = Flight::request();

        if ($request->method === 'POST') {
            if (!Flight::csrf()->validateRequest()) {
                $this->viewData['error'] = 'Invalid CSRF token';
            } else {
                $result = $this->processServerForm($request);
                if ($result['success']) {
                    Flight::redirect('/mcpregistry');
                    return;
                }
                $this->viewData['error'] = $result['error'];
            }
        }

        $this->viewData['title'] = 'Add MCP Server';
        $this->viewData['server'] = null;
        $this->render('mcp_registry/form', $this->viewData);
    }

    /**
     * Edit existing MCP server
     */
    public function edit($params = []) {
        if ($this->shouldSkipAction()) return;
        $request = Flight::request();
        $serverId = $request->query->id ?? null;

        if (!$serverId) {
            Flight::redirect('/mcpregistry');
            return;
        }

        $server = Bean::load('mcpserver', $serverId);
        if (!$server->id) {
            Flight::redirect('/mcpregistry');
            return;
        }

        if ($request->method === 'POST') {
            if (!Flight::csrf()->validateRequest()) {
                $this->viewData['error'] = 'Invalid CSRF token';
            } else {
                $result = $this->processServerForm($request, $server);
                if ($result['success']) {
                    $this->viewData['success'] = 'MCP server updated successfully';
                    // Reload server to get updated data
                    $server = Bean::load('mcpserver', $serverId);
                } else {
                    $this->viewData['error'] = $result['error'];
                }
            }
        }

        $this->viewData['title'] = 'Edit MCP Server';
        $this->viewData['server'] = $server;
        $this->render('mcp_registry/form', $this->viewData);
    }

    /**
     * Process server form (create or update)
     */
    private function processServerForm($request, $server = null): array {
        $isNew = ($server === null);
        if ($isNew) {
            $server = Bean::dispense('mcpserver');
        }

        // Validate required fields
        $name = trim($request->data->name ?? '');
        $slug = trim($request->data->slug ?? '');
        $endpointUrl = trim($request->data->endpointUrl ?? '');

        if (empty($name)) {
            return ['success' => false, 'error' => 'Name is required'];
        }

        if (strlen($name) < 2 || strlen($name) > 100) {
            return ['success' => false, 'error' => 'Name must be between 2 and 100 characters'];
        }

        if (empty($endpointUrl)) {
            return ['success' => false, 'error' => 'Endpoint URL is required'];
        }

        if (!filter_var($endpointUrl, FILTER_VALIDATE_URL)) {
            return ['success' => false, 'error' => 'Invalid endpoint URL'];
        }

        // Generate slug if not provided
        if (empty($slug)) {
            $slug = $this->generateSlug($name);
        }

        // Validate slug format
        if (!preg_match('/^[a-z0-9-]+$/', $slug)) {
            return ['success' => false, 'error' => 'Slug must contain only lowercase letters, numbers, and hyphens'];
        }

        // Check for duplicate slug
        if ($isNew) {
            $existing = Bean::findOne('mcpserver', 'slug = ?', [$slug]);
        } else {
            $existing = Bean::findOne('mcpserver', 'slug = ? AND id != ?', [$slug, $server->id]);
        }

        if ($existing) {
            return ['success' => false, 'error' => 'Slug already exists'];
        }

        // Validate optional URLs
        $authorUrl = trim($request->data->authorUrl ?? '');
        if (!empty($authorUrl) && !filter_var($authorUrl, FILTER_VALIDATE_URL)) {
            return ['success' => false, 'error' => 'Invalid author URL'];
        }

        $documentationUrl = trim($request->data->documentationUrl ?? '');
        if (!empty($documentationUrl) && !filter_var($documentationUrl, FILTER_VALIDATE_URL)) {
            return ['success' => false, 'error' => 'Invalid documentation URL'];
        }

        $iconUrl = trim($request->data->iconUrl ?? '');
        if (!empty($iconUrl) && !filter_var($iconUrl, FILTER_VALIDATE_URL)) {
            return ['success' => false, 'error' => 'Invalid icon URL'];
        }

        // Validate JSON fields
        $tools = trim($request->data->tools ?? '[]');
        if (!empty($tools) && json_decode($tools) === null && $tools !== '[]') {
            return ['success' => false, 'error' => 'Invalid tools JSON'];
        }

        $tags = trim($request->data->tags ?? '[]');
        if (!empty($tags) && json_decode($tags) === null && $tags !== '[]') {
            return ['success' => false, 'error' => 'Invalid tags JSON'];
        }

        // Set fields
        $server->name = $name;
        $server->slug = $slug;
        $server->description = trim($request->data->description ?? '');
        $server->endpointUrl = $endpointUrl;
        $server->version = trim($request->data->version ?? '1.0.0');
        $server->status = $request->data->status ?? 'active';
        $server->author = trim($request->data->author ?? '');
        $server->authorUrl = $authorUrl;
        $server->tools = $tools;
        $server->authType = $request->data->authType ?? 'none';
        $server->documentationUrl = $documentationUrl;
        $server->iconUrl = $iconUrl;
        $server->tags = $tags;
        $server->featured = (int)($request->data->featured ?? 0);
        $server->sortOrder = (int)($request->data->sortOrder ?? 0);

        // Gateway/Proxy fields
        $backendAuthHeader = $request->data->backendAuthHeader ?? 'Authorization';
        if ($backendAuthHeader === 'custom') {
            $backendAuthHeader = trim($request->data->backendAuthHeaderCustom ?? 'Authorization');
        }
        $server->backendAuthHeader = $backendAuthHeader;
        $server->backendAuthToken = trim($request->data->backendAuthToken ?? '');
        $server->isProxyEnabled = (int)($request->data->isProxyEnabled ?? 1);

        // Startup command fields
        $server->startupCommand = trim($request->data->startupCommand ?? '');
        $server->startupArgs = trim($request->data->startupArgs ?? '');
        $server->startupWorkingDir = trim($request->data->startupWorkingDir ?? '');
        $server->startupPort = (int)($request->data->startupPort ?? 0) ?: null;

        // Registry fields (set defaults for local servers)
        if ($isNew && empty($server->registrySource)) {
            $server->registrySource = 'local';
        }

        if ($isNew) {
            $server->createdAt = date('Y-m-d H:i:s');
            $server->createdBy = $this->member->id;
        } else {
            $server->updatedAt = date('Y-m-d H:i:s');
        }

        try {
            Bean::store($server);
            $this->logger->info($isNew ? 'MCP server created' : 'MCP server updated', [
                'id' => $server->id,
                'name' => $name,
                'by' => $this->member->id
            ]);
            return ['success' => true, 'server' => $server];
        } catch (Exception $e) {
            $this->logger->error('Failed to save MCP server', [
                'name' => $name,
                'error' => $e->getMessage()
            ]);
            return ['success' => false, 'error' => 'Error saving server: ' . $e->getMessage()];
        }
    }

    /**
     * Generate slug from name
     */
    private function generateSlug(string $name): string {
        $slug = strtolower($name);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');
        return $slug;
    }

    /**
     * Fetch tools from remote MCP server (AJAX)
     */
    public function fetchTools($params = []) {
        if ($this->shouldSkipAction()) return;
        // Accept either server ID or direct URL
        $serverId = $this->getParam('id');
        $endpointUrl = $this->getParam('url');

        // If ID provided, look up the server
        if (!empty($serverId)) {
            $server = Bean::load('mcpserver', $serverId);
            if ($server && $server->id) {
                $endpointUrl = $server->endpointUrl;
            }
        }

        if (empty($endpointUrl)) {
            Flight::json(['success' => false, 'error' => 'Server ID or URL is required']);
            return;
        }

        // Handle relative URLs by prepending base URL
        if (strpos($endpointUrl, 'http') !== 0) {
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $endpointUrl = $protocol . '://' . $host . '/' . ltrim($endpointUrl, '/');
        }

        if (!filter_var($endpointUrl, FILTER_VALIDATE_URL)) {
            Flight::json(['success' => false, 'error' => 'Invalid URL format']);
            return;
        }

        try {
            // MCP protocol requires initialization before other calls
            // Step 1: Initialize the MCP session
            $initRequest = json_encode([
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'initialize',
                'params' => [
                    'protocolVersion' => '2024-11-05',
                    'capabilities' => new \stdClass(),
                    'clientInfo' => [
                        'name' => 'Tiknix MCP Registry',
                        'version' => '1.0.0'
                    ]
                ]
            ]);

            $ch = curl_init($endpointUrl);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $initRequest,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER => true,  // Include headers to capture mcp-session-id
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Accept: application/json, text/event-stream'
                ],
                CURLOPT_TIMEOUT => 10
            ]);

            $initResponse = curl_exec($ch);
            $initHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $initError = curl_error($ch);
            curl_close($ch);

            if ($initError) {
                Flight::json(['success' => false, 'error' => "Connection error: {$initError}"]);
                return;
            }

            if ($initHttpCode < 200 || $initHttpCode >= 300) {
                Flight::json(['success' => false, 'error' => "Server initialization failed (HTTP {$initHttpCode})"]);
                return;
            }

            // Extract mcp-session-id from response headers (MCP session tracking)
            $initHeaders = substr($initResponse, 0, $headerSize);
            $sessionId = null;
            if (preg_match('/mcp-session-id:\s*([^\r\n]+)/i', $initHeaders, $matches)) {
                $sessionId = trim($matches[1]);
            }

            // Step 2: Send tools/list request with session ID
            // Note: params must be {} not [] - use stdClass to force object encoding
            $request = json_encode([
                'jsonrpc' => '2.0',
                'id' => 2,
                'method' => 'tools/list',
                'params' => new \stdClass()
            ]);

            // Build headers, include session ID if we have one
            $headers = [
                'Content-Type: application/json',
                'Accept: application/json, text/event-stream'
            ];
            if ($sessionId) {
                $headers[] = 'mcp-session-id: ' . $sessionId;
            }

            $ch = curl_init($endpointUrl);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $request,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_TIMEOUT => 10
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($error) {
                Flight::json(['success' => false, 'error' => "Connection error: {$error}"]);
                return;
            }

            if ($httpCode !== 200) {
                // Check for "Server not initialized" error - indicates stateful MCP server
                $errorData = json_decode($response, true);
                $errorMsg = $errorData['error']['message'] ?? '';
                if (stripos($errorMsg, 'not initialized') !== false) {
                    Flight::json([
                        'success' => false,
                        'error' => 'This MCP server requires persistent sessions. Tools cannot be fetched via HTTP. Please add tools manually or use a WebSocket client.',
                        'stateful' => true
                    ]);
                    return;
                }
                Flight::json(['success' => false, 'error' => "HTTP {$httpCode}"]);
                return;
            }

            // Handle SSE format (event: message\ndata: {...})
            if (strpos($response, 'event:') !== false || strpos($response, 'data:') !== false) {
                if (preg_match('/data:\s*(\{.*\})/s', $response, $matches)) {
                    $data = json_decode($matches[1], true);
                } else {
                    $data = null;
                }
            } else {
                $data = json_decode($response, true);
            }

            if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
                Flight::json(['success' => false, 'error' => 'Invalid JSON response']);
                return;
            }

            $tools = $data['result']['tools'] ?? [];

            // If we have a server ID, save the tools to the database
            if (!empty($serverId) && !empty($server)) {
                $server->tools = json_encode($tools);
                $server->updatedAt = date('Y-m-d H:i:s');
                Bean::store($server);
            }

            Flight::json([
                'success' => true,
                'tools' => $tools,
                'toolCount' => count($tools),
                'saved' => !empty($serverId)
            ]);

        } catch (Exception $e) {
            Flight::json(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Test connection to an MCP server
     * Public endpoint - anyone can test if a server is reachable
     * Accepts either 'id' (server ID) or 'url' (direct URL) parameter
     */
    public function testConnection($params = []) {
        if ($this->shouldSkipAction()) return;

        $serverId = $this->getParam('id');
        $endpointUrl = $this->getParam('url');

        // If ID provided, look up the server
        if (!empty($serverId)) {
            $server = Bean::load('mcpserver', $serverId);
            if (!$server || !$server->id) {
                Flight::json(['success' => false, 'error' => 'Server not found']);
                return;
            }
            $endpointUrl = $server->endpointUrl;
        }

        if (empty($endpointUrl)) {
            Flight::json(['success' => false, 'error' => 'Server ID or URL is required']);
            return;
        }

        // Handle relative URLs
        if (strpos($endpointUrl, 'http') !== 0) {
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $endpointUrl = $protocol . '://' . $host . '/' . ltrim($endpointUrl, '/');
        }

        if (!filter_var($endpointUrl, FILTER_VALIDATE_URL)) {
            Flight::json(['success' => false, 'error' => 'Invalid URL format']);
            return;
        }

        try {
            // Try to ping the server or get tools list
            // Note: capabilities must be {} not [] - use stdClass to force object encoding
            $request = json_encode([
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'initialize',
                'params' => [
                    'protocolVersion' => '2024-11-05',
                    'capabilities' => new \stdClass(),
                    'clientInfo' => [
                        'name' => 'Tiknix MCP Registry',
                        'version' => '1.0.0'
                    ]
                ]
            ]);

            $ch = curl_init($endpointUrl);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $request,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Accept: application/json, text/event-stream'
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_CONNECTTIMEOUT => 5
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($error) {
                Flight::json([
                    'success' => false,
                    'error' => 'Connection failed: ' . $error,
                    'endpoint' => $endpointUrl
                ]);
                return;
            }

            if ($httpCode >= 200 && $httpCode < 300) {
                // Handle SSE format (event: message\ndata: {...})
                if (strpos($response, 'event:') !== false || strpos($response, 'data:') !== false) {
                    // Parse SSE response
                    if (preg_match('/data:\s*(\{.*\})/s', $response, $matches)) {
                        $data = json_decode($matches[1], true);
                    } else {
                        $data = null;
                    }
                } else {
                    // Plain JSON response
                    $data = json_decode($response, true);
                }

                $serverInfo = $data['result']['serverInfo'] ?? null;

                Flight::json([
                    'success' => true,
                    'message' => $serverInfo
                        ? 'Connected to ' . ($serverInfo['name'] ?? 'MCP Server') . ' v' . ($serverInfo['version'] ?? '?')
                        : 'Server is reachable (HTTP ' . $httpCode . ')',
                    'httpCode' => $httpCode,
                    'serverInfo' => $serverInfo
                ]);
            } else {
                Flight::json([
                    'success' => false,
                    'error' => 'Server returned HTTP ' . $httpCode,
                    'httpCode' => $httpCode
                ]);
            }

        } catch (Exception $e) {
            Flight::json(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Start an MCP server using its configured startup command
     * Requires authentication - only admins can start servers
     */
    public function startServer($params = []) {
        if ($this->shouldSkipAction()) return;

        // Require admin access
        if (!Flight::hasLevel(LEVELS['ADMIN'])) {
            Flight::json(['success' => false, 'error' => 'Admin access required']);
            return;
        }

        $serverId = $this->getParam('id');

        if (empty($serverId)) {
            Flight::json(['success' => false, 'error' => 'Server ID is required']);
            return;
        }

        $server = Bean::load('mcpserver', $serverId);
        if (!$server || !$server->id) {
            Flight::json(['success' => false, 'error' => 'Server not found']);
            return;
        }

        if (empty($server->startupCommand)) {
            Flight::json(['success' => false, 'error' => 'No startup command configured for this server']);
            return;
        }

        // Whitelist of allowed commands for security
        $allowedCommands = ['npx', 'node', 'php', 'python', 'python3', 'ruby', 'java', 'go', 'deno', 'bun'];
        $command = trim($server->startupCommand);

        if (!in_array($command, $allowedCommands)) {
            Flight::json(['success' => false, 'error' => 'Command not in allowed list: ' . implode(', ', $allowedCommands)]);
            return;
        }

        // Build the full command
        $args = trim($server->startupArgs ?? '');
        $fullCommand = escapeshellcmd($command);
        if (!empty($args)) {
            // Split args and escape each one
            $argParts = preg_split('/\s+/', $args);
            foreach ($argParts as $arg) {
                $fullCommand .= ' ' . escapeshellarg($arg);
            }
        }

        // Add output redirection to run in background
        $logFile = '/tmp/mcp-server-' . $server->slug . '.log';
        $fullCommand .= ' > ' . escapeshellarg($logFile) . ' 2>&1 &';

        // Change to working directory if specified
        $workingDir = trim($server->startupWorkingDir ?? '');
        if (!empty($workingDir) && is_dir($workingDir)) {
            $fullCommand = 'cd ' . escapeshellarg($workingDir) . ' && ' . $fullCommand;
        }

        try {
            // Execute the command
            exec($fullCommand, $output, $returnCode);

            // Give it a moment to start
            usleep(500000); // 0.5 seconds

            // Check if server is responding
            $endpointUrl = $server->endpointUrl;
            $isRunning = false;

            $ch = curl_init($endpointUrl);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'method' => 'initialize',
                    'params' => [
                        'protocolVersion' => '2024-11-05',
                        'capabilities' => new \stdClass(),
                        'clientInfo' => ['name' => 'Tiknix', 'version' => '1.0.0']
                    ]
                ]),
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Accept: application/json, text/event-stream'
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_CONNECTTIMEOUT => 3
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $isRunning = ($httpCode >= 200 && $httpCode < 300);

            $this->logger->info('MCP server start attempted', [
                'id' => $server->id,
                'name' => $server->name,
                'command' => $command,
                'isRunning' => $isRunning,
                'by' => $this->member->id
            ]);

            Flight::json([
                'success' => true,
                'message' => $isRunning ? 'Server started successfully' : 'Command executed, but server not yet responding',
                'isRunning' => $isRunning,
                'logFile' => $logFile
            ]);

        } catch (Exception $e) {
            Flight::json(['success' => false, 'error' => 'Failed to start server: ' . $e->getMessage()]);
        }
    }

    /**
     * Check if an MCP server is running
     * GET /mcpregistry/checkStatus?id=1
     */
    public function checkStatus($params = []) {
        if ($this->shouldSkipAction()) return;

        $serverId = $this->getParam('id');

        if (empty($serverId)) {
            Flight::json(['success' => false, 'error' => 'Server ID is required']);
            return;
        }

        $server = Bean::load('mcpserver', $serverId);
        if (!$server || !$server->id) {
            Flight::json(['success' => false, 'error' => 'Server not found']);
            return;
        }

        $isRunning = false;
        $pid = null;

        // Check PID file
        $pidFile = '/tmp/mcp-server-' . $server->slug . '.pid';
        if (file_exists($pidFile)) {
            $pid = (int)trim(file_get_contents($pidFile));
            if ($pid > 0 && file_exists("/proc/{$pid}")) {
                $isRunning = true;
            }
        }

        // Also try connecting to the endpoint as a backup check
        if (!$isRunning && !empty($server->endpointUrl)) {
            $ch = curl_init($server->endpointUrl);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'method' => 'initialize',
                    'params' => [
                        'protocolVersion' => '2024-11-05',
                        'capabilities' => new \stdClass(),
                        'clientInfo' => ['name' => 'Tiknix', 'version' => '1.0.0']
                    ]
                ]),
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Accept: application/json, text/event-stream'
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 3,
                CURLOPT_CONNECTTIMEOUT => 2
            ]);
            curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            // Any HTTP response (even error codes) means server is running
            // Only connection failures (code 0) mean server is down
            if ($httpCode > 0) {
                $isRunning = true;
            }
        }

        Flight::json([
            'success' => true,
            'isRunning' => $isRunning,
            'pid' => $pid,
            'hasStartupCommand' => !empty($server->startupCommand)
        ]);
    }

    /**
     * Stop a running MCP server
     * POST /mcpregistry/stopServer?id=1
     */
    public function stopServer($params = []) {
        if ($this->shouldSkipAction()) return;

        $serverId = $this->getParam('id');

        if (empty($serverId)) {
            Flight::json(['success' => false, 'error' => 'Server ID is required']);
            return;
        }

        $server = Bean::load('mcpserver', $serverId);
        if (!$server || !$server->id) {
            Flight::json(['success' => false, 'error' => 'Server not found']);
            return;
        }

        $pidFile = '/tmp/mcp-server-' . $server->slug . '.pid';
        $stopped = false;
        $pid = null;

        if (file_exists($pidFile)) {
            $pid = (int)trim(file_get_contents($pidFile));
            if ($pid > 0 && file_exists("/proc/{$pid}")) {
                // Kill the process
                posix_kill($pid, SIGTERM);
                usleep(500000); // Wait 0.5s

                // Check if still running, force kill if needed
                if (file_exists("/proc/{$pid}")) {
                    posix_kill($pid, SIGKILL);
                    usleep(500000);
                }

                // Verify it's stopped
                $stopped = !file_exists("/proc/{$pid}");

                if ($stopped) {
                    unlink($pidFile);
                }
            } else {
                // PID file exists but process not running - clean up
                unlink($pidFile);
                $stopped = true;
            }
        } else {
            // No PID file - try to find process by startup command/args or endpoint port
            $killed = false;

            // Try to kill by startup args (e.g., @playwright/mcp)
            if (!empty($server->startupArgs)) {
                $pattern = escapeshellarg(trim(explode(' ', $server->startupArgs)[0]));
                exec("pkill -f {$pattern} 2>/dev/null", $output, $returnCode);
                $killed = ($returnCode === 0);
            }

            // If that didn't work and we have a port, try to find and kill by port
            if (!$killed && !empty($server->startupPort)) {
                $port = (int)$server->startupPort;
                // Find PID listening on the port
                exec("lsof -t -i:{$port} 2>/dev/null", $pids, $returnCode);
                if (!empty($pids)) {
                    foreach ($pids as $p) {
                        $p = (int)trim($p);
                        if ($p > 0) {
                            posix_kill($p, SIGTERM);
                            $killed = true;
                        }
                    }
                }
            }

            $stopped = $killed;
        }

        $this->logger->info('MCP server stop attempted', [
            'id' => $server->id,
            'name' => $server->name,
            'pid' => $pid,
            'stopped' => $stopped,
            'by' => $this->member->id
        ]);

        Flight::json([
            'success' => $stopped,
            'message' => $stopped ? 'Server stopped' : 'Failed to stop server',
            'pid' => $pid
        ]);
    }

    /**
     * Public JSON API - returns active MCP servers
     */
    public function api($params = []) {
        if ($this->shouldSkipAction()) return;
        Flight::response()->header('Access-Control-Allow-Origin', '*');

        // Only return active servers
        $servers = Bean::find('mcpserver', 'status = ? ORDER BY featured DESC, sort_order ASC, name ASC', ['active']);

        $result = [];
        foreach ($servers as $server) {
            $result[] = [
                'slug' => $server->slug,
                'name' => $server->name,
                'description' => $server->description,
                'endpoint_url' => $server->endpointUrl,
                'version' => $server->version,
                'author' => $server->author,
                'author_url' => $server->authorUrl,
                'tools' => json_decode($server->tools, true) ?: [],
                'auth_type' => $server->authType,
                'documentation_url' => $server->documentationUrl,
                'icon_url' => $server->iconUrl,
                'tags' => json_decode($server->tags, true) ?: [],
                'featured' => (bool)$server->featured,
                'is_proxy_enabled' => (bool)$server->isProxyEnabled
            ];
        }

        Flight::json([
            'success' => true,
            'count' => count($result),
            'servers' => $result
        ]);
    }

    /**
     * View MCP proxy logs
     * GET /mcpregistry/logs
     */
    public function logs($params = []) {
        if ($this->shouldSkipAction()) return;
        // Require admin access
        if (!Flight::hasLevel(LEVELS['ADMIN'])) {
            Flight::redirect('/auth/login?redirect=' . urlencode('/mcpregistry/logs'));
            return;
        }

        $page = (int)($this->getParam('page') ?? 1);
        $limit = 50;
        $offset = ($page - 1) * $limit;

        // Filters
        $method = $this->getParam('method') ?? '';
        $memberId = $this->getParam('member_id') ?? '';
        $hasError = $this->getParam('has_error') ?? '';

        // Build query
        $where = '1=1';
        $bindings = [];

        if (!empty($method)) {
            $where .= ' AND tool_name = ?';
            $bindings[] = $method;
        }

        if (!empty($memberId)) {
            $where .= ' AND member_id = ?';
            $bindings[] = (int)$memberId;
        }

        if ($hasError === '1') {
            $where .= ' AND (error IS NOT NULL OR http_code >= 400)';
        } elseif ($hasError === '0') {
            $where .= ' AND error IS NULL AND http_code < 400';
        }

        // Get total count
        $total = Bean::count('mcplog', $where, $bindings);
        $totalPages = ceil($total / $limit);

        // Get logs
        $logs = Bean::find('mcplog', "{$where} ORDER BY created_at DESC LIMIT {$limit} OFFSET {$offset}", $bindings);

        // Get unique tool names for filter dropdown
        $methods = \RedBeanPHP\R::getCol('SELECT DISTINCT tool_name FROM mcplog WHERE tool_name IS NOT NULL AND tool_name != \'\' ORDER BY tool_name');

        $this->viewData['title'] = 'MCP Proxy Logs';
        $this->viewData['logs'] = $logs;
        $this->viewData['page'] = $page;
        $this->viewData['totalPages'] = $totalPages;
        $this->viewData['total'] = $total;
        $this->viewData['methods'] = $methods;
        $this->viewData['filters'] = [
            'method' => $method,
            'member_id' => $memberId,
            'has_error' => $hasError
        ];

        $this->render('mcp_registry/logs', $this->viewData);
    }

    /**
     * View single log entry details (AJAX)
     * GET /mcpregistry/logDetail?id=X
     */
    public function logDetail($params = []) {
        if ($this->shouldSkipAction()) return;
        // Require admin access
        if (!Flight::hasLevel(LEVELS['ADMIN'])) {
            Flight::json(['success' => false, 'error' => 'Access denied']);
            return;
        }

        $logId = $this->getParam('id');
        if (empty($logId)) {
            Flight::json(['success' => false, 'error' => 'Log ID required']);
            return;
        }

        $log = Bean::load('mcplog', $logId);
        if (!$log || !$log->id) {
            Flight::json(['success' => false, 'error' => 'Log not found']);
            return;
        }

        Flight::json([
            'success' => true,
            'log' => [
                'id' => $log->id,
                'memberId' => $log->memberId,
                'apiKeyId' => $log->apikeyId,
                'serverId' => $log->serverId,
                'serverSlug' => $log->serverSlug,
                'toolName' => $log->toolName,
                'method' => $log->method,
                'arguments' => $log->arguments,
                'result' => $log->result,
                'requestBody' => $log->requestBody,
                'responseBody' => $log->responseBody,
                'httpCode' => $log->httpCode ?: 200,
                'duration' => $log->duration ?: 0,
                'ipAddress' => $log->ipAddress,
                'userAgent' => $log->userAgent,
                'error' => $log->error,
                'success' => (bool)$log->success,
                'sessionId' => $log->sessionId,
                'createdAt' => $log->createdAt
            ]
        ]);
    }

    /**
     * Clear old logs
     * POST /mcpregistry/clearLogs
     */
    public function clearLogs($params = []) {
        if ($this->shouldSkipAction()) return;
        // Require admin access
        if (!Flight::hasLevel(LEVELS['ADMIN'])) {
            Flight::json(['success' => false, 'error' => 'Access denied']);
            return;
        }

        $days = (int)($this->getParam('days') ?? 7);
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        $deleted = \RedBeanPHP\R::exec('DELETE FROM mcplog WHERE created_at < ?', [$cutoff]);

        $this->logger->info('MCP logs cleared', [
            'days' => $days,
            'deleted' => $deleted,
            'by' => $this->member->id
        ]);

        Flight::json([
            'success' => true,
            'deleted' => $deleted,
            'message' => "Deleted {$deleted} logs older than {$days} days"
        ]);
    }

    /**
     * Fix doubled slug for a server (admin only)
     * GET /mcpregistry/fixSlug?id=1&slug=playwright-mcp
     */
    public function fixSlug($params = []) {
        if ($this->shouldSkipAction()) return;
        // Check session auth or API key
        $isAuthed = Flight::hasLevel(LEVELS['ADMIN']);

        if (!$isAuthed) {
            // Try API key auth
            $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
            if (preg_match('/Bearer\s+(\S+)/', $authHeader, $matches)) {
                $token = $matches[1];
                $apiKey = Bean::findOne('apikey', 'token = ? AND is_active = 1', [$token]);
                if ($apiKey && $apiKey->id) {
                    $member = Bean::load('member', $apiKey->memberId);
                    if ($member && $member->level <= LEVELS['ADMIN']) {
                        $isAuthed = true;
                    }
                }
            }
        }

        if (!$isAuthed) {
            Flight::json(['success' => false, 'error' => 'Admin access required']);
            return;
        }

        $serverId = (int)($this->getParam('id') ?? 0);
        $newSlug = trim($this->getParam('slug') ?? '');

        if (!$serverId || !$newSlug) {
            Flight::json(['success' => false, 'error' => 'Both id and slug parameters are required']);
            return;
        }

        // Validate slug format
        if (!preg_match('/^[a-z0-9-]+$/', $newSlug)) {
            Flight::json(['success' => false, 'error' => 'Slug must contain only lowercase letters, numbers, and hyphens']);
            return;
        }

        $server = Bean::load('mcpserver', $serverId);
        if (!$server || !$server->id) {
            Flight::json(['success' => false, 'error' => 'Server not found']);
            return;
        }

        $oldSlug = $server->slug;
        $server->slug = $newSlug;
        // Clear tools cache so new slug takes effect
        $server->toolsCachedAt = null;
        $server->toolsCache = null;
        // Enable proxy if requested
        if ($this->getParam('enableProxy')) {
            $server->isProxyEnabled = 1;
        }
        Bean::store($server);

        $this->logger->info('MCP server slug fixed', [
            'server_id' => $serverId,
            'old_slug' => $oldSlug,
            'new_slug' => $newSlug,
            'by' => $this->member->id ?? 0
        ]);

        Flight::json([
            'success' => true,
            'server_id' => $serverId,
            'old_slug' => $oldSlug,
            'new_slug' => $newSlug,
            'is_proxy_enabled' => $server->isProxyEnabled
        ]);
    }

    /**
     * Fix API key's allowedServers (admin only)
     * GET /mcpregistry/fixApiKey?id=1&allowedServers=playwright-mcp
     */
    public function fixApiKey($params = []) {
        if ($this->shouldSkipAction()) return;
        // Check session auth or API key
        $isAuthed = Flight::hasLevel(LEVELS['ADMIN']);

        if (!$isAuthed) {
            // Try API key auth
            $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
            if (preg_match('/Bearer\s+(\S+)/', $authHeader, $matches)) {
                $token = $matches[1];
                $apiKey = Bean::findOne('apikey', 'token = ? AND is_active = 1', [$token]);
                if ($apiKey && $apiKey->id) {
                    $member = Bean::load('member', $apiKey->memberId);
                    if ($member && $member->level <= LEVELS['ADMIN']) {
                        $isAuthed = true;
                    }
                }
            }
        }

        if (!$isAuthed) {
            Flight::json(['success' => false, 'error' => 'Admin access required']);
            return;
        }

        $apiKeyId = (int)($this->getParam('id') ?? 0);
        $allowedServers = trim($this->getParam('allowedServers') ?? '');

        if (!$apiKeyId) {
            Flight::json(['success' => false, 'error' => 'API key id is required']);
            return;
        }

        $apiKey = Bean::load('apikey', $apiKeyId);
        if (!$apiKey || !$apiKey->id) {
            Flight::json(['success' => false, 'error' => 'API key not found']);
            return;
        }

        $oldAllowedServers = $apiKey->allowedServers;

        // Parse new allowed servers (comma-separated or empty for no restrictions)
        if (empty($allowedServers)) {
            $apiKey->allowedServers = null;
        } else {
            $serverList = array_map('trim', explode(',', $allowedServers));
            $apiKey->allowedServers = json_encode($serverList);
        }

        Bean::store($apiKey);

        $this->logger->info('API key allowedServers fixed', [
            'apikey_id' => $apiKeyId,
            'old_allowed_servers' => $oldAllowedServers,
            'new_allowed_servers' => $apiKey->allowedServers
        ]);

        Flight::json([
            'success' => true,
            'apikey_id' => $apiKeyId,
            'old_allowed_servers' => $oldAllowedServers,
            'new_allowed_servers' => $apiKey->allowedServers
        ]);
    }
}
