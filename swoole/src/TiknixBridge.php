<?php
/**
 * Tiknix Bridge for OpenSwoole - Multi-Tenant Support
 *
 * Handles multi-tenant configuration based on subdomain:
 * - Config: conf/config.{{subdomain}}.ini
 * - Master DB: For tenant registry and initial auth
 * - Tenant DB: database/{{subdomain}}.db (after login)
 *
 * Flow:
 * 1. Request comes to tenant1.tiknix.com
 * 2. Extract subdomain → "tenant1"
 * 3. Load conf/config.tenant1.ini
 * 4. Pre-login: Use master DB for auth
 * 5. Post-login: Switch to tenant's SQLite DB
 */

namespace Tiknix\Swoole;

use RedBeanPHP\R;

class TiknixBridge
{
    private array $config = [];
    private ?string $subdomain = null;
    private bool $initialized = false;
    private bool $tenantDbActive = false;
    private array $serverCache = [];
    private array $apiKeyCache = [];

    // Master database connection key
    private const MASTER_DB_KEY = 'master';
    private const TENANT_DB_KEY = 'tenant';

    // Built-in server slug
    public const BUILTIN_SERVER_SLUG = 'tiknix';

    /**
     * Built-in Tiknix tools available without external MCP servers
     */
    private const BUILTIN_TOOLS = [
        'hello' => [
            'name' => 'hello',
            'description' => 'Returns a friendly greeting. Use this to test the MCP connection.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string', 'description' => 'Name to greet (optional)']
                ],
                'required' => []
            ]
        ],
        'echo' => [
            'name' => 'echo',
            'description' => 'Echoes back the provided message. Useful for testing.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'message' => ['type' => 'string', 'description' => 'Message to echo back']
                ],
                'required' => ['message']
            ]
        ],
        'get_time' => [
            'name' => 'get_time',
            'description' => 'Returns the current server date and time.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'timezone' => ['type' => 'string', 'description' => 'Timezone (e.g., "America/New_York", "UTC"). Defaults to server timezone.'],
                    'format' => ['type' => 'string', 'description' => 'Date format (PHP date format string). Defaults to "Y-m-d H:i:s".']
                ],
                'required' => []
            ]
        ],
        'add_numbers' => [
            'name' => 'add_numbers',
            'description' => 'Adds two numbers together and returns the result.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'a' => ['type' => 'number', 'description' => 'First number'],
                    'b' => ['type' => 'number', 'description' => 'Second number']
                ],
                'required' => ['a', 'b']
            ]
        ],
        'list_users' => [
            'name' => 'list_users',
            'description' => 'Lists users in the system (requires authentication).',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'limit' => ['type' => 'integer', 'description' => 'Maximum number of users to return (default: 10)']
                ],
                'required' => []
            ]
        ],
        'list_mcp_servers' => [
            'name' => 'list_mcp_servers',
            'description' => 'Lists registered MCP servers from the Tiknix registry.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'status' => ['type' => 'string', 'description' => 'Filter by status', 'enum' => ['active', 'inactive', 'deprecated', 'all']],
                    'include_tools' => ['type' => 'boolean', 'description' => 'Include tool definitions'],
                    'limit' => ['type' => 'integer', 'description' => 'Maximum servers to return (default: 50)']
                ],
                'required' => []
            ]
        ],
        'validate_php' => [
            'name' => 'validate_php',
            'description' => 'Validate PHP syntax for one or more files. Returns syntax errors if any.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'file' => ['type' => 'string', 'description' => 'Path to PHP file or directory to validate']
                ],
                'required' => ['file']
            ]
        ],
        'security_scan' => [
            'name' => 'security_scan',
            'description' => 'Scan PHP code for security vulnerabilities (OWASP Top 10). Returns issues grouped by severity.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'file' => ['type' => 'string', 'description' => 'Path to PHP file or directory to scan']
                ],
                'required' => ['file']
            ]
        ],
        'check_redbean' => [
            'name' => 'check_redbean',
            'description' => 'Check PHP code for RedBeanPHP convention violations (bean naming, associations, R::exec usage).',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'file' => ['type' => 'string', 'description' => 'Path to PHP file or directory to check']
                ],
                'required' => ['file']
            ]
        ],
        'check_flightphp' => [
            'name' => 'check_flightphp',
            'description' => 'Check PHP code for FlightPHP pattern compliance (controller conventions, routing).',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'file' => ['type' => 'string', 'description' => 'Path to PHP file or directory to check']
                ],
                'required' => ['file']
            ]
        ],
        'full_validation' => [
            'name' => 'full_validation',
            'description' => 'Run all validators (PHP syntax, security, RedBeanPHP, FlightPHP) on code.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'file' => ['type' => 'string', 'description' => 'Path to PHP file or directory to validate']
                ],
                'required' => ['file']
            ]
        ],
        'list_tasks' => [
            'name' => 'list_tasks',
            'description' => 'List workbench tasks visible to the authenticated user.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'status' => ['type' => 'string', 'description' => 'Filter by status', 'enum' => ['pending', 'queued', 'running', 'completed', 'failed', 'paused']],
                    'team_id' => ['type' => 'integer', 'description' => 'Filter by team ID'],
                    'limit' => ['type' => 'integer', 'description' => 'Maximum tasks to return (default: 20)']
                ],
                'required' => []
            ]
        ],
        'get_task' => [
            'name' => 'get_task',
            'description' => 'Get details of a specific workbench task.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'task_id' => ['type' => 'integer', 'description' => 'The task ID']
                ],
                'required' => ['task_id']
            ]
        ],
        'update_task' => [
            'name' => 'update_task',
            'description' => 'Update a workbench task. Use to report progress, set status, or record results.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'task_id' => ['type' => 'integer', 'description' => 'The task ID'],
                    'status' => ['type' => 'string', 'description' => 'New status', 'enum' => ['running', 'completed', 'failed', 'paused']],
                    'branch_name' => ['type' => 'string', 'description' => 'Git branch name'],
                    'pr_url' => ['type' => 'string', 'description' => 'Pull request URL'],
                    'progress_message' => ['type' => 'string', 'description' => 'Progress update message'],
                    'error_message' => ['type' => 'string', 'description' => 'Error message (for failed status)']
                ],
                'required' => ['task_id']
            ]
        ],
        'complete_task' => [
            'name' => 'complete_task',
            'description' => 'Report task work is done and await further instructions. Task remains open for user review.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'task_id' => ['type' => 'integer', 'description' => 'The task ID'],
                    'pr_url' => ['type' => 'string', 'description' => 'Pull request URL'],
                    'branch_name' => ['type' => 'string', 'description' => 'Git branch name'],
                    'summary' => ['type' => 'string', 'description' => 'Summary of what was accomplished']
                ],
                'required' => ['task_id']
            ]
        ],
        'add_task_log' => [
            'name' => 'add_task_log',
            'description' => 'Add a log entry to a task.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'task_id' => ['type' => 'integer', 'description' => 'The task ID'],
                    'level' => ['type' => 'string', 'description' => 'Log level', 'enum' => ['debug', 'info', 'warning', 'error']],
                    'message' => ['type' => 'string', 'description' => 'Log message']
                ],
                'required' => ['task_id', 'message']
            ]
        ],
        'ask_question' => [
            'name' => 'ask_question',
            'description' => 'Ask the user a clarifying question. The question will be shown in the task UI and the task will be set to awaiting status until the user responds.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'task_id' => ['type' => 'integer', 'description' => 'The task ID'],
                    'question' => ['type' => 'string', 'description' => 'The question to ask the user'],
                    'context' => ['type' => 'string', 'description' => 'Optional context or explanation for why this question is needed'],
                    'options' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Optional list of suggested answers/options']
                ],
                'required' => ['task_id', 'question']
            ]
        ],
    ];

    /**
     * Initialize bridge with subdomain detection
     *
     * @param string|null $subdomain Subdomain or null to auto-detect
     * @param string $masterConfigFile Fallback/master config file
     */
    public function __construct(?string $subdomain = null, string $masterConfigFile = 'conf/config.ini')
    {
        $this->subdomain = $subdomain;

        // Load tenant-specific config if subdomain provided
        if ($subdomain) {
            $tenantConfig = BASE_PATH . "/conf/config.{$subdomain}.ini";
            if (file_exists($tenantConfig)) {
                $this->loadConfig($tenantConfig);
            } else {
                // Fall back to master config
                $this->loadConfig(BASE_PATH . '/' . $masterConfigFile);
            }
        } else {
            $this->loadConfig(BASE_PATH . '/' . $masterConfigFile);
        }

        // Initialize master database connection
        $this->initMasterDatabase();
        $this->initialized = true;
    }

    /**
     * Extract subdomain from host header
     *
     * @param string $host Full host (e.g., "tenant1.tiknix.com")
     * @return string|null Subdomain or null if root domain
     */
    public static function extractSubdomain(string $host): ?string
    {
        // Remove port if present
        $host = preg_replace('/:\d+$/', '', $host);

        // Split by dots
        $parts = explode('.', $host);

        // Need at least 3 parts for subdomain (sub.domain.tld)
        if (count($parts) >= 3) {
            // Return first part as subdomain
            return $parts[0];
        }

        // Check for localhost variants
        if (preg_match('/^([^.]+)\.localhost/', $host, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Load configuration from INI file
     */
    private function loadConfig(string $configFile): void
    {
        if (!file_exists($configFile)) {
            throw new \RuntimeException("Config file not found: {$configFile}");
        }

        $this->config = parse_ini_file($configFile, true);

        if ($this->config === false) {
            throw new \RuntimeException("Failed to parse config: {$configFile}");
        }
    }

    /**
     * Initialize master database connection
     * Used for tenant registry, authentication, and cross-tenant data
     */
    private function initMasterDatabase(): void
    {
        // Check if master database already exists (shared across tenant bridges)
        try {
            R::selectDatabase(self::MASTER_DB_KEY);
            // Already initialized, just select it
            return;
        } catch (\Exception $e) {
            // Database doesn't exist, create it
        }

        $dbConfig = $this->config['database'] ?? [];

        if (empty($dbConfig)) {
            throw new \RuntimeException('Database configuration missing');
        }

        $type = $dbConfig['type'] ?? 'sqlite';

        if ($type === 'sqlite') {
            $dbPath = $dbConfig['path'] ?? 'database/tiknix.db';
            $fullPath = BASE_PATH . '/' . $dbPath;

            // Ensure directory exists
            $dir = dirname($fullPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            R::addDatabase(self::MASTER_DB_KEY, "sqlite:{$fullPath}");
            R::selectDatabase(self::MASTER_DB_KEY);
        } else {
            $host = $dbConfig['host'] ?? 'localhost';
            $port = $dbConfig['port'] ?? 3306;
            $name = $dbConfig['name'] ?? 'tiknix';
            $user = $dbConfig['user'] ?? 'root';
            $pass = $dbConfig['pass'] ?? '';

            R::addDatabase(self::MASTER_DB_KEY, "{$type}:host={$host};port={$port};dbname={$name}", $user, $pass);
            R::selectDatabase(self::MASTER_DB_KEY);
        }

        // Freeze in production
        $freeze = ($this->config['app']['environment'] ?? 'development') === 'production';
        R::freeze($freeze);
    }

    /**
     * Switch to tenant-specific database
     * Called after successful authentication
     *
     * @param string|null $tenantId Override tenant ID (default: use subdomain)
     */
    public function switchToTenantDatabase(?string $tenantId = null): void
    {
        $tenant = $tenantId ?? $this->subdomain;

        if (!$tenant) {
            throw new \RuntimeException('No tenant specified for database switch');
        }

        $dbPath = BASE_PATH . "/database/{$tenant}.db";

        // Ensure directory exists
        $dir = dirname($dbPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Add and switch to tenant database
        R::addDatabase(self::TENANT_DB_KEY, "sqlite:{$dbPath}");
        R::selectDatabase(self::TENANT_DB_KEY);

        $this->tenantDbActive = true;

        // Freeze in production
        $freeze = ($this->config['app']['environment'] ?? 'development') === 'production';
        R::freeze($freeze);
    }

    /**
     * Switch back to master database
     */
    public function switchToMasterDatabase(): void
    {
        R::selectDatabase(self::MASTER_DB_KEY);
        $this->tenantDbActive = false;
    }

    /**
     * Check if currently using tenant database
     */
    public function isTenantDbActive(): bool
    {
        return $this->tenantDbActive;
    }

    /**
     * Get current subdomain
     */
    public function getSubdomain(): ?string
    {
        return $this->subdomain;
    }

    /**
     * Get configuration value
     */
    public function getConfig(string $section, ?string $key = null, $default = null)
    {
        if ($key === null) {
            return $this->config[$section] ?? $default;
        }
        return $this->config[$section][$key] ?? $default;
    }

    /**
     * Get all registered MCP servers
     */
    public function getMcpServers(): array
    {
        if (!empty($this->serverCache)) {
            return $this->serverCache;
        }

        try {
            $servers = R::find('mcpserver', " status = 'active' OR is_proxy_enabled = 1 ");

            foreach ($servers as $server) {
                $slug = $server->slug;
                $this->serverCache[$slug] = [
                    'url' => $server->endpoint_url ?? $server->url ?? 'http://localhost:3000/mcp',
                    'auth_token' => $server->backend_auth_token ?? $server->auth_token ?? null,
                    'auth_header' => $server->backend_auth_header ?? 'Authorization',
                    'tools' => json_decode($server->tools_cache ?? $server->tools ?? '[]', true),
                    // Startup command for auto-start feature
                    'startup_command' => $server->startup_command ?? null,
                    'startup_args' => $server->startup_args ?? null,
                    'startup_working_dir' => $server->startup_working_dir ?? null,
                    'startup_port' => $server->startup_port ?? null,
                ];
            }
        } catch (\Exception $e) {
            echo "[TiknixBridge] Warning: Could not load MCP servers: " . $e->getMessage() . "\n";
        }

        return $this->serverCache;
    }

    /**
     * Authenticate API key
     *
     * @param string|null $apiKey The API key to authenticate
     * @return array ['valid' => bool, 'api_key_id' => int|null, 'member_id' => int|null, 'error' => string|null]
     */
    public function authenticateApiKey(?string $apiKey): array
    {
        if (empty($apiKey)) {
            return ['valid' => false, 'error' => 'API key required'];
        }

        // Check cache first
        if (isset($this->apiKeyCache[$apiKey])) {
            return $this->apiKeyCache[$apiKey];
        }

        try {
            // Look up by token field
            $keyRecord = R::findOne('apikey', ' token = ? AND is_active = ? ', [$apiKey, 1]);

            if (!$keyRecord) {
                // Try with hash comparison for hashed keys
                $keys = R::find('apikey', ' is_active = ? ', [1]);
                foreach ($keys as $key) {
                    if ($key->token === $apiKey ||
                        (isset($key->key_hash) && password_verify($apiKey, $key->key_hash))) {
                        $keyRecord = $key;
                        break;
                    }
                }
            }

            if (!$keyRecord) {
                $result = ['valid' => false, 'error' => 'Invalid API key'];
            } else {
                // Update last used timestamp
                $keyRecord->last_used_at = date('Y-m-d H:i:s');
                R::store($keyRecord);

                $result = [
                    'valid' => true,
                    'api_key_id' => (int)$keyRecord->id,
                    'member_id' => (int)($keyRecord->member_id ?? 0),
                    'name' => $keyRecord->name ?? 'Unknown',
                    'permissions' => json_decode($keyRecord->permissions ?? '[]', true),
                ];
            }

            // Cache result
            $this->apiKeyCache[$apiKey] = $result;
            return $result;

        } catch (\Exception $e) {
            return ['valid' => false, 'error' => 'Authentication error: ' . $e->getMessage()];
        }
    }

    /**
     * Check if API key can access a specific MCP server
     */
    public function canAccessServer(int $apiKeyId, string $serverSlug): bool
    {
        // For now, allow all authenticated keys to access all servers
        // TODO: Implement per-key server access control
        return true;
    }

    /**
     * Get available tools for an API key
     * Includes built-in tiknix tools + tools from registered MCP servers
     */
    public function getAvailableTools(?string $apiKey): array
    {
        $allTools = [];

        // Add built-in tiknix tools first
        foreach (self::BUILTIN_TOOLS as $name => $tool) {
            $tool['server'] = self::BUILTIN_SERVER_SLUG;
            $tool['fullName'] = self::BUILTIN_SERVER_SLUG . ":{$tool['name']}";
            $allTools[] = $tool;
        }

        // Add tools from registered MCP servers
        $servers = $this->getMcpServers();
        foreach ($servers as $slug => $server) {
            $tools = $server['tools'] ?? [];
            foreach ($tools as $tool) {
                $tool['server'] = $slug;
                $tool['fullName'] = "{$slug}:{$tool['name']}";
                $allTools[] = $tool;
            }
        }

        return $allTools;
    }

    /**
     * Get built-in tools only
     */
    public function getBuiltinTools(): array
    {
        return self::BUILTIN_TOOLS;
    }

    /**
     * Find which server a tool belongs to
     * Returns server slug or null if not found
     */
    public function findToolServer(string $toolName): ?string
    {
        // Check built-in tools first
        if (isset(self::BUILTIN_TOOLS[$toolName])) {
            return self::BUILTIN_SERVER_SLUG;
        }

        // Check registered MCP servers
        $servers = $this->getMcpServers();
        foreach ($servers as $slug => $server) {
            $tools = $server['tools'] ?? [];
            foreach ($tools as $tool) {
                if (($tool['name'] ?? '') === $toolName) {
                    return $slug;
                }
            }
        }

        return null;
    }

    /**
     * Check if a tool is a built-in tiknix tool
     */
    public function isBuiltinTool(string $toolName): bool
    {
        return isset(self::BUILTIN_TOOLS[$toolName]);
    }

    /**
     * Execute a built-in tiknix tool
     *
     * @param string $toolName The tool name (without server prefix)
     * @param array $arguments Tool arguments
     * @param array $authContext Authentication context ['api_key_id' => int, 'member_id' => int]
     * @return array Result with 'content' key or 'error' key
     */
    public function executeBuiltinTool(string $toolName, array $arguments, array $authContext = []): array
    {
        if (!$this->isBuiltinTool($toolName)) {
            return ['error' => "Unknown built-in tool: {$toolName}"];
        }

        try {
            switch ($toolName) {
                case 'hello':
                    $name = $arguments['name'] ?? 'World';
                    return ['content' => [['type' => 'text', 'text' => "Hello, {$name}! Welcome to Tiknix MCP."]]];

                case 'echo':
                    $message = $arguments['message'] ?? '';
                    return ['content' => [['type' => 'text', 'text' => $message]]];

                case 'get_time':
                    $tz = $arguments['timezone'] ?? date_default_timezone_get();
                    $format = $arguments['format'] ?? 'Y-m-d H:i:s';
                    try {
                        $dt = new \DateTime('now', new \DateTimeZone($tz));
                        return ['content' => [['type' => 'text', 'text' => $dt->format($format)]]];
                    } catch (\Exception $e) {
                        return ['error' => "Invalid timezone: {$tz}"];
                    }

                case 'add_numbers':
                    $a = $arguments['a'] ?? 0;
                    $b = $arguments['b'] ?? 0;
                    $result = $a + $b;
                    return ['content' => [['type' => 'text', 'text' => (string)$result]]];

                case 'list_users':
                    return $this->executeListUsers($arguments, $authContext);

                case 'list_mcp_servers':
                    return $this->executeListMcpServers($arguments);

                case 'validate_php':
                    return $this->executeValidatePhp($arguments);

                case 'security_scan':
                    return $this->executeSecurityScan($arguments);

                case 'check_redbean':
                    return $this->executeCheckRedbean($arguments);

                case 'check_flightphp':
                    return $this->executeCheckFlightphp($arguments);

                case 'full_validation':
                    return $this->executeFullValidation($arguments);

                case 'list_tasks':
                    return $this->executeListTasks($arguments, $authContext);

                case 'get_task':
                    return $this->executeGetTask($arguments, $authContext);

                case 'update_task':
                    return $this->executeUpdateTask($arguments, $authContext);

                case 'complete_task':
                    return $this->executeCompleteTask($arguments, $authContext);

                case 'add_task_log':
                    return $this->executeAddTaskLog($arguments, $authContext);

                case 'ask_question':
                    return $this->executeAskQuestion($arguments, $authContext);

                default:
                    return ['error' => "Tool '{$toolName}' not implemented"];
            }
        } catch (\Exception $e) {
            return ['error' => "Tool execution error: " . $e->getMessage()];
        }
    }

    /**
     * Log an MCP request
     */
    public function logMcpRequest(int $apiKeyId, string $serverSlug, string $toolName, array $arguments, array $result): void
    {
        try {
            $log = R::dispense('mcplog');
            $log->apikey_id = $apiKeyId;
            $log->server_slug = $serverSlug;
            $log->tool_name = $toolName;
            $log->arguments = json_encode($arguments);
            $log->result = json_encode($result);
            $log->created_at = date('Y-m-d H:i:s');
            $log->success = !isset($result['error']);
            R::store($log);
        } catch (\Exception $e) {
            echo "[TiknixBridge] Warning: Could not log MCP request: " . $e->getMessage() . "\n";
        }
    }

    /**
     * Clear caches (useful after config changes)
     */
    public function clearCaches(): void
    {
        $this->serverCache = [];
        $this->apiKeyCache = [];
    }

    // =========================================================================
    // TOOL IMPLEMENTATION METHODS
    // =========================================================================

    /**
     * Execute list_users tool
     */
    private function executeListUsers(array $arguments, array $authContext): array
    {
        $limit = min($arguments['limit'] ?? 10, 100);

        try {
            $users = R::findAll('member', ' ORDER BY id DESC LIMIT ? ', [$limit]);
            $result = [];
            foreach ($users as $user) {
                $result[] = [
                    'id' => (int)$user->id,
                    'email' => $user->email,
                    'name' => $user->name ?? $user->firstName ?? 'Unknown',
                    'level' => (int)($user->level ?? 100),
                    'created_at' => $user->createdAt ?? $user->created_at ?? null,
                ];
            }
            return ['content' => [['type' => 'text', 'text' => json_encode($result, JSON_PRETTY_PRINT)]]];
        } catch (\Exception $e) {
            return ['error' => 'Failed to list users: ' . $e->getMessage()];
        }
    }

    /**
     * Execute list_mcp_servers tool
     */
    private function executeListMcpServers(array $arguments): array
    {
        $status = $arguments['status'] ?? 'active';
        $includeTools = $arguments['include_tools'] ?? false;
        $limit = min($arguments['limit'] ?? 50, 100);

        try {
            $sql = $status === 'all' ? ' 1=1 ' : ' status = ? ';
            $params = $status === 'all' ? [] : [$status];
            $sql .= " ORDER BY featured DESC, sort_order ASC LIMIT {$limit}";

            $servers = R::find('mcpserver', $sql, $params);
            $result = [];

            foreach ($servers as $server) {
                $data = [
                    'slug' => $server->slug,
                    'name' => $server->name,
                    'description' => $server->description ?? '',
                    'status' => $server->status,
                    'featured' => (bool)$server->featured,
                    'endpoint_url' => $server->endpointUrl ?? $server->endpoint_url ?? '',
                ];

                if ($includeTools) {
                    $data['tools'] = json_decode($server->toolsCache ?? $server->tools ?? '[]', true);
                } else {
                    $tools = json_decode($server->toolsCache ?? $server->tools ?? '[]', true);
                    $data['tool_count'] = count($tools);
                }

                $result[] = $data;
            }

            return ['content' => [['type' => 'text', 'text' => json_encode($result, JSON_PRETTY_PRINT)]]];
        } catch (\Exception $e) {
            return ['error' => 'Failed to list MCP servers: ' . $e->getMessage()];
        }
    }

    /**
     * Execute validate_php tool
     */
    private function executeValidatePhp(array $arguments): array
    {
        $file = $arguments['file'] ?? '';
        if (empty($file)) {
            return ['error' => 'File path required'];
        }

        // Security: Only allow paths within project
        $basePath = realpath(BASE_PATH);
        $fullPath = realpath($file) ?: $file;

        if (strpos($fullPath, $basePath) !== 0 && !file_exists($fullPath)) {
            // Try relative to BASE_PATH
            $fullPath = $basePath . '/' . ltrim($file, '/');
        }

        if (!file_exists($fullPath)) {
            return ['error' => "File not found: {$file}"];
        }

        $errors = [];

        if (is_dir($fullPath)) {
            $files = glob($fullPath . '/*.php');
            foreach ($files as $f) {
                $result = $this->validatePhpSyntax($f);
                if ($result !== true) {
                    $errors[basename($f)] = $result;
                }
            }
        } else {
            $result = $this->validatePhpSyntax($fullPath);
            if ($result !== true) {
                $errors[basename($fullPath)] = $result;
            }
        }

        if (empty($errors)) {
            return ['content' => [['type' => 'text', 'text' => 'All files validated successfully. No syntax errors found.']]];
        }

        return ['content' => [['type' => 'text', 'text' => json_encode(['errors' => $errors], JSON_PRETTY_PRINT)]]];
    }

    private function validatePhpSyntax(string $file): string|bool
    {
        $output = [];
        $returnCode = 0;
        exec("php -l " . escapeshellarg($file) . " 2>&1", $output, $returnCode);

        if ($returnCode !== 0) {
            return implode("\n", $output);
        }
        return true;
    }

    /**
     * Execute security_scan tool
     */
    private function executeSecurityScan(array $arguments): array
    {
        $file = $arguments['file'] ?? '';
        if (empty($file)) {
            return ['error' => 'File path required'];
        }

        $basePath = realpath(BASE_PATH);
        $fullPath = realpath($file) ?: $basePath . '/' . ltrim($file, '/');

        if (!file_exists($fullPath)) {
            return ['error' => "File not found: {$file}"];
        }

        $issues = [];
        $patterns = [
            'sql_injection' => [
                'pattern' => '/\$_(GET|POST|REQUEST|COOKIE)\s*\[[^\]]+\]\s*[^;]*(?:R::exec|R::getAll|mysql_query|mysqli_query|->query)/i',
                'severity' => 'critical',
                'message' => 'Potential SQL injection - user input in query'
            ],
            'command_injection' => [
                'pattern' => '/\$_(GET|POST|REQUEST)\s*\[[^\]]+\]\s*[^;]*(exec|system|shell_exec|passthru|popen|proc_open)/i',
                'severity' => 'critical',
                'message' => 'Potential command injection - user input in shell command'
            ],
            'path_traversal' => [
                'pattern' => '/\$_(GET|POST|REQUEST)\s*\[[^\]]+\]\s*[^;]*(file_get_contents|fopen|include|require|readfile)/i',
                'severity' => 'high',
                'message' => 'Potential path traversal - user input in file operation'
            ],
            'xss' => [
                'pattern' => '/echo\s+\$_(GET|POST|REQUEST|COOKIE)\s*\[/i',
                'severity' => 'high',
                'message' => 'Potential XSS - unescaped user input in output'
            ],
            'hardcoded_secrets' => [
                'pattern' => '/(password|secret|api_key|apikey)\s*=\s*[\'"][^\'"]{8,}[\'"]/i',
                'severity' => 'medium',
                'message' => 'Potential hardcoded secret'
            ],
        ];

        $files = is_dir($fullPath) ? glob($fullPath . '/*.php') : [$fullPath];

        foreach ($files as $f) {
            $content = file_get_contents($f);
            $lines = explode("\n", $content);

            foreach ($patterns as $type => $check) {
                foreach ($lines as $lineNum => $line) {
                    if (preg_match($check['pattern'], $line)) {
                        $issues[] = [
                            'file' => basename($f),
                            'line' => $lineNum + 1,
                            'type' => $type,
                            'severity' => $check['severity'],
                            'message' => $check['message'],
                            'code' => trim($line)
                        ];
                    }
                }
            }
        }

        if (empty($issues)) {
            return ['content' => [['type' => 'text', 'text' => 'No security issues found.']]];
        }

        // Group by severity
        $grouped = ['critical' => [], 'high' => [], 'medium' => [], 'low' => []];
        foreach ($issues as $issue) {
            $grouped[$issue['severity']][] = $issue;
        }

        return ['content' => [['type' => 'text', 'text' => json_encode(['issues' => $grouped, 'total' => count($issues)], JSON_PRETTY_PRINT)]]];
    }

    /**
     * Execute check_redbean tool
     */
    private function executeCheckRedbean(array $arguments): array
    {
        $file = $arguments['file'] ?? '';
        if (empty($file)) {
            return ['error' => 'File path required'];
        }

        $basePath = realpath(BASE_PATH);
        $fullPath = realpath($file) ?: $basePath . '/' . ltrim($file, '/');

        if (!file_exists($fullPath)) {
            return ['error' => "File not found: {$file}"];
        }

        $issues = [];
        $files = is_dir($fullPath) ? glob($fullPath . '/*.php') : [$fullPath];

        foreach ($files as $f) {
            $content = file_get_contents($f);
            $lines = explode("\n", $content);

            foreach ($lines as $lineNum => $line) {
                // Check for invalid bean names (uppercase or underscores)
                if (preg_match('/R::dispense\s*\(\s*[\'"]([a-zA-Z_]+)[\'"]\s*\)/', $line, $matches)) {
                    $beanName = $matches[1];
                    if (preg_match('/[A-Z_]/', $beanName)) {
                        $issues[] = [
                            'file' => basename($f),
                            'line' => $lineNum + 1,
                            'type' => 'invalid_bean_name',
                            'severity' => 'error',
                            'message' => "Invalid bean name '{$beanName}' - must be all lowercase, no underscores",
                            'suggestion' => 'Use Bean::dispense() which auto-normalizes names'
                        ];
                    }
                }

                // Check for R::exec used for simple CRUD
                if (preg_match('/R::exec\s*\(\s*[\'"](?:INSERT|UPDATE|DELETE)\s+/i', $line)) {
                    $issues[] = [
                        'file' => basename($f),
                        'line' => $lineNum + 1,
                        'type' => 'exec_for_crud',
                        'severity' => 'warning',
                        'message' => 'R::exec used for CRUD - prefer bean operations',
                        'suggestion' => 'Use R::dispense/R::store/R::trash instead'
                    ];
                }

                // Check for manual FK assignment
                if (preg_match('/->(\w+)_id\s*=/', $line)) {
                    $issues[] = [
                        'file' => basename($f),
                        'line' => $lineNum + 1,
                        'type' => 'manual_fk',
                        'severity' => 'info',
                        'message' => 'Manual FK assignment - consider using associations',
                        'suggestion' => 'Use $parent->ownChildList[] = $child instead'
                    ];
                }
            }
        }

        if (empty($issues)) {
            return ['content' => [['type' => 'text', 'text' => 'No RedBeanPHP issues found.']]];
        }

        return ['content' => [['type' => 'text', 'text' => json_encode(['issues' => $issues, 'total' => count($issues)], JSON_PRETTY_PRINT)]]];
    }

    /**
     * Execute check_flightphp tool
     */
    private function executeCheckFlightphp(array $arguments): array
    {
        $file = $arguments['file'] ?? '';
        if (empty($file)) {
            return ['error' => 'File path required'];
        }

        $basePath = realpath(BASE_PATH);
        $fullPath = realpath($file) ?: $basePath . '/' . ltrim($file, '/');

        if (!file_exists($fullPath)) {
            return ['error' => "File not found: {$file}"];
        }

        $issues = [];
        $files = is_dir($fullPath) ? glob($fullPath . '/*.php') : [$fullPath];

        foreach ($files as $f) {
            $content = file_get_contents($f);

            // Check for controller extending BaseControls\Control
            if (strpos($f, '/controls/') !== false) {
                if (!preg_match('/extends\s+BaseControls\\\\Control/', $content)) {
                    $issues[] = [
                        'file' => basename($f),
                        'type' => 'controller_inheritance',
                        'severity' => 'warning',
                        'message' => 'Controller should extend BaseControls\\Control'
                    ];
                }
            }

            // Check for direct superglobal access
            if (preg_match('/\$_(GET|POST|REQUEST)\s*\[/', $content)) {
                $issues[] = [
                    'file' => basename($f),
                    'type' => 'superglobal_access',
                    'severity' => 'info',
                    'message' => 'Direct superglobal access - prefer $this->getParam()'
                ];
            }
        }

        if (empty($issues)) {
            return ['content' => [['type' => 'text', 'text' => 'No FlightPHP issues found.']]];
        }

        return ['content' => [['type' => 'text', 'text' => json_encode(['issues' => $issues, 'total' => count($issues)], JSON_PRETTY_PRINT)]]];
    }

    /**
     * Execute full_validation tool
     */
    private function executeFullValidation(array $arguments): array
    {
        $results = [
            'php_syntax' => $this->executeValidatePhp($arguments),
            'security' => $this->executeSecurityScan($arguments),
            'redbean' => $this->executeCheckRedbean($arguments),
            'flightphp' => $this->executeCheckFlightphp($arguments),
        ];

        $summary = [];
        foreach ($results as $check => $result) {
            if (isset($result['error'])) {
                $summary[$check] = ['status' => 'error', 'message' => $result['error']];
            } else {
                $text = $result['content'][0]['text'] ?? '';
                $hasIssues = strpos($text, '"issues"') !== false || strpos($text, '"errors"') !== false;
                $summary[$check] = ['status' => $hasIssues ? 'issues_found' : 'pass', 'details' => $text];
            }
        }

        return ['content' => [['type' => 'text', 'text' => json_encode($summary, JSON_PRETTY_PRINT)]]];
    }

    /**
     * Execute list_tasks tool
     */
    private function executeListTasks(array $arguments, array $authContext): array
    {
        $memberId = $authContext['member_id'] ?? 0;
        $status = $arguments['status'] ?? null;
        $teamId = $arguments['team_id'] ?? null;
        $limit = min($arguments['limit'] ?? 20, 100);

        try {
            $sql = ' member_id = ? ';
            $params = [$memberId];

            if ($status) {
                $sql .= ' AND status = ? ';
                $params[] = $status;
            }

            if ($teamId !== null) {
                $sql .= ' AND team_id = ? ';
                $params[] = $teamId;
            }

            $sql .= " ORDER BY created_at DESC LIMIT {$limit}";

            $tasks = R::find('workbenchtask', $sql, $params);
            $result = [];

            foreach ($tasks as $task) {
                $result[] = [
                    'id' => (int)$task->id,
                    'title' => $task->title,
                    'status' => $task->status,
                    'priority' => $task->priority ?? 'medium',
                    'created_at' => $task->createdAt ?? $task->created_at,
                    'team_id' => $task->teamId ?? $task->team_id ?? null,
                ];
            }

            return ['content' => [['type' => 'text', 'text' => json_encode($result, JSON_PRETTY_PRINT)]]];
        } catch (\Exception $e) {
            return ['error' => 'Failed to list tasks: ' . $e->getMessage()];
        }
    }

    /**
     * Execute get_task tool
     */
    private function executeGetTask(array $arguments, array $authContext): array
    {
        $taskId = $arguments['task_id'] ?? 0;
        $memberId = $authContext['member_id'] ?? 0;

        try {
            $task = R::findOne('workbenchtask', ' id = ? AND member_id = ? ', [$taskId, $memberId]);

            if (!$task) {
                return ['error' => 'Task not found or access denied'];
            }

            $result = [
                'id' => (int)$task->id,
                'title' => $task->title,
                'description' => $task->description ?? '',
                'status' => $task->status,
                'priority' => $task->priority ?? 'medium',
                'branch_name' => $task->branchName ?? $task->branch_name ?? null,
                'pr_url' => $task->prUrl ?? $task->pr_url ?? null,
                'created_at' => $task->createdAt ?? $task->created_at,
                'updated_at' => $task->updatedAt ?? $task->updated_at ?? null,
            ];

            return ['content' => [['type' => 'text', 'text' => json_encode($result, JSON_PRETTY_PRINT)]]];
        } catch (\Exception $e) {
            return ['error' => 'Failed to get task: ' . $e->getMessage()];
        }
    }

    /**
     * Execute update_task tool
     */
    private function executeUpdateTask(array $arguments, array $authContext): array
    {
        $taskId = $arguments['task_id'] ?? 0;
        $memberId = $authContext['member_id'] ?? 0;

        try {
            $task = R::findOne('workbenchtask', ' id = ? AND member_id = ? ', [$taskId, $memberId]);

            if (!$task) {
                return ['error' => 'Task not found or access denied'];
            }

            if (isset($arguments['status'])) {
                $task->status = $arguments['status'];
            }
            if (isset($arguments['branch_name'])) {
                $task->branchName = $arguments['branch_name'];
            }
            if (isset($arguments['pr_url'])) {
                $task->prUrl = $arguments['pr_url'];
            }
            if (isset($arguments['progress_message'])) {
                $task->progressMessage = $arguments['progress_message'];
            }
            if (isset($arguments['error_message'])) {
                $task->errorMessage = $arguments['error_message'];
            }

            $task->updatedAt = date('Y-m-d H:i:s');
            R::store($task);

            return ['content' => [['type' => 'text', 'text' => json_encode(['success' => true, 'task_id' => (int)$task->id])]]];
        } catch (\Exception $e) {
            return ['error' => 'Failed to update task: ' . $e->getMessage()];
        }
    }

    /**
     * Execute complete_task tool
     * Sets status to 'awaiting' (not 'completed') - user must explicitly complete
     */
    private function executeCompleteTask(array $arguments, array $authContext): array
    {
        $taskId = $arguments['task_id'] ?? 0;
        $memberId = $authContext['member_id'] ?? 0;

        try {
            $task = R::findOne('workbenchtask', ' id = ? AND member_id = ? ', [$taskId, $memberId]);

            if (!$task) {
                return ['error' => 'Task not found or access denied'];
            }

            // Set to 'awaiting' - only user can mark as truly 'completed'
            $task->status = 'awaiting';
            $task->updatedAt = date('Y-m-d H:i:s');

            if (isset($arguments['pr_url'])) {
                $task->prUrl = $arguments['pr_url'];
            }
            if (isset($arguments['branch_name'])) {
                $task->branchName = $arguments['branch_name'];
            }
            if (isset($arguments['summary'])) {
                $task->resultsJson = json_encode(['summary' => $arguments['summary']]);
            }

            R::store($task);

            // Log the status change
            $log = R::dispense('tasklog');
            $log->taskId = $taskId;
            $log->memberId = $memberId;
            $log->logLevel = 'info';
            $log->logType = 'status_change';
            $log->message = 'Work completed - awaiting review/further instructions';
            $log->createdAt = date('Y-m-d H:i:s');
            R::store($log);

            return ['content' => [['type' => 'text', 'text' => json_encode([
                'success' => true,
                'task_id' => (int)$task->id,
                'status' => 'awaiting',
                'message' => 'Task work reported. Awaiting user review or further instructions.',
                'pr_url' => $task->prUrl ?? null
            ], JSON_PRETTY_PRINT)]]];
        } catch (\Exception $e) {
            return ['error' => 'Failed to complete task: ' . $e->getMessage()];
        }
    }

    /**
     * Execute add_task_log tool
     */
    private function executeAddTaskLog(array $arguments, array $authContext): array
    {
        $taskId = $arguments['task_id'] ?? 0;
        $memberId = $authContext['member_id'] ?? 0;
        $level = $arguments['level'] ?? 'info';
        $message = $arguments['message'] ?? '';

        try {
            $task = R::findOne('workbenchtask', ' id = ? AND member_id = ? ', [$taskId, $memberId]);

            if (!$task) {
                return ['error' => 'Task not found or access denied'];
            }

            // Use 'tasklog' table to match PHP-FPM web app
            $log = R::dispense('tasklog');
            $log->taskId = $taskId;
            $log->memberId = $memberId;
            $log->logLevel = $level;
            $log->logType = 'progress';
            $log->message = $message;
            $log->createdAt = date('Y-m-d H:i:s');
            R::store($log);

            return ['content' => [['type' => 'text', 'text' => json_encode(['success' => true, 'log_id' => (int)$log->id])]]];
        } catch (\Exception $e) {
            return ['error' => 'Failed to add task log: ' . $e->getMessage()];
        }
    }

    /**
     * Execute ask_question tool
     * Posts a question as a comment and sets task to awaiting status
     */
    private function executeAskQuestion(array $arguments, array $authContext): array
    {
        $taskId = $arguments['task_id'] ?? 0;
        $memberId = $authContext['member_id'] ?? 0;
        $question = trim($arguments['question'] ?? '');

        if (empty($question)) {
            return ['error' => 'Question is required'];
        }

        try {
            $task = R::findOne('workbenchtask', ' id = ? AND member_id = ? ', [$taskId, $memberId]);

            if (!$task) {
                return ['error' => 'Task not found or access denied'];
            }

            // Build the question message
            $message = "**Question from Claude:**\n\n" . $question;

            if (!empty($arguments['context'])) {
                $message .= "\n\n*Context:* " . $arguments['context'];
            }

            if (!empty($arguments['options']) && is_array($arguments['options'])) {
                $message .= "\n\n*Suggested options:*\n";
                foreach ($arguments['options'] as $option) {
                    $message .= "- " . $option . "\n";
                }
            }

            // Store as a comment from Claude
            $comment = R::dispense('taskcomment');
            $comment->taskId = $taskId;
            $comment->memberId = $memberId;
            $comment->content = $message;
            $comment->isFromClaude = 1;
            $comment->isInternal = 0;
            $comment->createdAt = date('Y-m-d H:i:s');
            R::store($comment);

            // Update task status to awaiting
            $task->status = 'awaiting';
            $task->updatedAt = date('Y-m-d H:i:s');
            R::store($task);

            // Log the question
            $log = R::dispense('tasklog');
            $log->taskId = $taskId;
            $log->memberId = $memberId;
            $log->logLevel = 'info';
            $log->logType = 'question';
            $log->message = 'Claude asked: ' . substr($question, 0, 100) . (strlen($question) > 100 ? '...' : '');
            $log->createdAt = date('Y-m-d H:i:s');
            R::store($log);

            return ['content' => [['type' => 'text', 'text' => json_encode([
                'success' => true,
                'task_id' => (int)$taskId,
                'status' => 'awaiting',
                'message' => 'Question posted. Waiting for user response.',
                'comment_id' => (int)$comment->id
            ], JSON_PRETTY_PRINT)]]];
        } catch (\Exception $e) {
            return ['error' => 'Failed to ask question: ' . $e->getMessage()];
        }
    }
}
