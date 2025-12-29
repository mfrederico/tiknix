<?php
/**
 * MCP Connection Manager with Persistent SSE Connections
 *
 * Manages persistent connections to MCP servers like Playwright.
 * Each API key + server combination gets its own persistent SSE client
 * that maintains a long-lived connection for tool calls.
 *
 * Uses SSE transport (/sse endpoint) which is more reliable than
 * the streamable HTTP transport for servers like Playwright MCP.
 */

namespace Tiknix\Swoole;

use OpenSwoole\Table;

require_once __DIR__ . '/SseMcpClient.php';

class McpConnectionManager
{
    private Table $sessionInfo;
    private array $clients = [];
    private array $serverConfigs = [];

    public function __construct()
    {
        // Session info table for statistics (shared across workers)
        $this->sessionInfo = new Table(256);
        $this->sessionInfo->column('session_id', Table::TYPE_STRING, 64);
        $this->sessionInfo->column('server_slug', Table::TYPE_STRING, 64);
        $this->sessionInfo->column('created_at', Table::TYPE_INT);
        $this->sessionInfo->column('last_used', Table::TYPE_INT);
        $this->sessionInfo->column('request_count', Table::TYPE_INT);
        $this->sessionInfo->create();
    }

    /**
     * Register an MCP server configuration
     *
     * URL should point to the base (e.g., http://localhost:3000)
     * The SSE client will use /sse endpoint automatically
     */
    public function registerServer(string $slug, array $config): void
    {
        // Normalize URL - remove /mcp or /sse suffix if present
        $url = $config['url'] ?? 'http://localhost:3000';
        $url = preg_replace('#/(mcp|sse)/?$#', '', $url);

        $this->serverConfigs[$slug] = [
            'url' => $url,
            'auth_token' => $config['auth_token'] ?? null,
            'auth_header' => $config['auth_header'] ?? 'Authorization',
            // Startup command for auto-start feature
            'startup_command' => $config['startup_command'] ?? null,
            'startup_args' => $config['startup_args'] ?? null,
            'startup_working_dir' => $config['startup_working_dir'] ?? null,
            'startup_port' => $config['startup_port'] ?? null,
        ];
    }

    /**
     * Try to auto-start an MCP server using its configured startup command
     * Returns true if server was started successfully
     */
    private function tryStartServer(string $slug): bool
    {
        $config = $this->serverConfigs[$slug] ?? null;
        if (!$config || empty($config['startup_command'])) {
            echo "[MCP] No startup command configured for: {$slug}\n";
            return false;
        }

        // Whitelist of allowed commands for security
        $allowedCommands = ['npx', 'node', 'php', 'python', 'python3', 'ruby', 'java', 'go', 'deno', 'bun'];
        $command = trim($config['startup_command']);

        if (!in_array($command, $allowedCommands)) {
            echo "[MCP] Command not allowed: {$command}\n";
            return false;
        }

        // Build the full command
        $args = trim($config['startup_args'] ?? '');
        $fullCommand = escapeshellcmd($command);
        if (!empty($args)) {
            $argParts = preg_split('/\s+/', $args);
            foreach ($argParts as $arg) {
                $fullCommand .= ' ' . escapeshellarg($arg);
            }
        }

        // Add nohup and output redirection
        $logFile = '/tmp/mcp-server-' . $slug . '.log';
        $pidFile = '/tmp/mcp-server-' . $slug . '.pid';
        $fullCommand = 'nohup ' . $fullCommand . ' > ' . escapeshellarg($logFile) . ' 2>&1 & echo $! > ' . escapeshellarg($pidFile);

        // Working directory
        $workingDir = trim($config['startup_working_dir'] ?? '');
        if (!empty($workingDir) && is_dir($workingDir)) {
            $fullCommand = 'cd ' . escapeshellarg($workingDir) . ' && ' . $fullCommand;
        }

        // Check if already running
        if (file_exists($pidFile)) {
            $existingPid = (int)trim(file_get_contents($pidFile));
            if ($existingPid > 0 && file_exists("/proc/{$existingPid}")) {
                echo "[MCP] Server already running (PID {$existingPid}): {$slug}\n";
                usleep(500000);
                return true;
            }
        }

        echo "[MCP] Auto-starting server: {$slug} ({$command} {$args})\n";
        exec($fullCommand, $output, $returnCode);

        // Wait for server to become available (up to 15 seconds)
        $startTime = time();
        $maxWait = 15;
        $url = $config['url'];

        while ((time() - $startTime) < $maxWait) {
            usleep(500000); // 0.5 seconds

            // Try to connect using initialize request
            $ch = curl_init($url . '/mcp');
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
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 3,
                CURLOPT_CONNECTTIMEOUT => 2,
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode >= 200 && $httpCode < 400) {
                echo "[MCP] Server started successfully: {$slug} (took " . (time() - $startTime) . "s)\n";
                return true;
            }

            echo "[MCP] Waiting for server {$slug}... (HTTP {$httpCode})\n";
        }

        echo "[MCP] Server failed to start within {$maxWait}s: {$slug}\n";
        return false;
    }

    /**
     * Get session key for a given API key and server
     */
    private function getSessionKey(string $apiKeyId, string $serverSlug): string
    {
        return "{$apiKeyId}:{$serverSlug}";
    }

    /**
     * Get or create a persistent SSE client for a session
     */
    public function getClient(string $apiKeyId, string $serverSlug): ?SseMcpClient
    {
        $sessionKey = $this->getSessionKey($apiKeyId, $serverSlug);

        // Check if we have an existing connected client
        if (isset($this->clients[$sessionKey])) {
            $client = $this->clients[$sessionKey];
            if ($client->isConnected()) {
                return $client;
            }
            // Client disconnected, remove it
            echo "[MCP] Client disconnected, removing: {$sessionKey}\n";
            unset($this->clients[$sessionKey]);
        }

        // Get server config
        $config = $this->serverConfigs[$serverSlug] ?? null;
        if (!$config) {
            echo "[MCP] Server not registered: {$serverSlug}\n";
            return null;
        }

        // Create new SSE client
        echo "[MCP] Creating SSE client for {$sessionKey}\n";
        $client = new SseMcpClient($config);

        if (!$client->connect()) {
            echo "[MCP] Failed to connect SSE client for {$sessionKey}, attempting auto-start\n";

            // Try to auto-start the server
            if ($this->tryStartServer($serverSlug)) {
                // Retry connection after server starts
                $client = new SseMcpClient($config);
                if (!$client->connect()) {
                    echo "[MCP] Still failed to connect after auto-start: {$sessionKey}\n";
                    return null;
                }
            } else {
                return null;
            }
        }

        // Store client for reuse
        $this->clients[$sessionKey] = $client;

        // Update session info
        $this->sessionInfo->set($sessionKey, [
            'session_id' => 'sse-connected',
            'server_slug' => $serverSlug,
            'created_at' => time(),
            'last_used' => time(),
            'request_count' => 0,
        ]);

        return $client;
    }

    /**
     * Call a tool on an MCP server
     *
     * Uses persistent SSE connection for the session.
     * The SSE client handles the MCP protocol properly.
     */
    public function callTool(string $apiKeyId, string $serverSlug, string $toolName, array $arguments = []): array
    {
        $sessionKey = $this->getSessionKey($apiKeyId, $serverSlug);

        // Get or create SSE client
        $client = $this->getClient($apiKeyId, $serverSlug);
        if (!$client) {
            return ['error' => "Failed to connect to MCP server: {$serverSlug}"];
        }

        // Make the tool call
        $result = $client->callTool($toolName, $arguments);

        // Update session info for stats
        $info = $this->sessionInfo->get($sessionKey);
        $count = $info ? $info['request_count'] + 1 : 1;

        $this->sessionInfo->set($sessionKey, [
            'session_id' => 'sse-active',
            'server_slug' => $serverSlug,
            'created_at' => $info ? $info['created_at'] : time(),
            'last_used' => time(),
            'request_count' => $count,
        ]);

        return $result;
    }

    /**
     * Clear a session and disconnect client
     */
    public function clearSession(string $apiKeyId, string $serverSlug): void
    {
        $sessionKey = $this->getSessionKey($apiKeyId, $serverSlug);

        if (isset($this->clients[$sessionKey])) {
            $this->clients[$sessionKey]->disconnect();
            unset($this->clients[$sessionKey]);
        }

        $this->sessionInfo->del($sessionKey);
    }

    /**
     * Get all active sessions
     */
    public function getSessions(): array
    {
        $result = [];
        foreach ($this->sessionInfo as $key => $data) {
            $isConnected = isset($this->clients[$key]) && $this->clients[$key]->isConnected();
            $result[$key] = [
                'session_id' => substr($data['session_id'], 0, 12) . '...',
                'server_slug' => $data['server_slug'],
                'created_at' => date('Y-m-d H:i:s', $data['created_at']),
                'last_used' => date('Y-m-d H:i:s', $data['last_used']),
                'request_count' => $data['request_count'],
                'connected' => $isConnected,
            ];
        }
        return $result;
    }

    /**
     * Cleanup expired/disconnected sessions
     */
    public function cleanupExpiredSessions(int $maxIdleSeconds = 1800): int
    {
        $cleaned = 0;
        $now = time();

        foreach ($this->sessionInfo as $key => $data) {
            $shouldClean = false;

            // Check idle timeout
            if (($now - $data['last_used']) > $maxIdleSeconds) {
                $shouldClean = true;
            }

            // Check if client is disconnected
            if (isset($this->clients[$key]) && !$this->clients[$key]->isConnected()) {
                $shouldClean = true;
            }

            if ($shouldClean) {
                if (isset($this->clients[$key])) {
                    $this->clients[$key]->disconnect();
                    unset($this->clients[$key]);
                }
                $this->sessionInfo->del($key);
                $cleaned++;
                echo "[MCP] Cleaned up session: {$key}\n";
            }
        }

        return $cleaned;
    }
}
