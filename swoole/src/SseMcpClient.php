<?php
/**
 * SSE MCP Client for OpenSwoole
 *
 * Uses the SSE transport (/sse endpoint) which is more reliable than
 * the streamable HTTP transport for Playwright MCP.
 *
 * SSE Transport flow:
 * 1. GET /sse - Opens SSE stream, receives session URL
 * 2. POST to session URL - Send JSON-RPC messages
 * 3. Responses come back via SSE stream
 */

namespace Tiknix\Swoole;

use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\Http\Client;
use OpenSwoole\Coroutine\Channel;

class SseMcpClient
{
    private string $host;
    private int $port;
    private string $basePath;
    private bool $ssl;
    private ?string $authToken;
    private ?string $authHeader;

    private ?string $sessionUrl = null;
    private ?Client $sseClient = null;
    private Channel $responseChannel;
    private array $pendingRequests = [];
    private bool $connected = false;
    private bool $initialized = false;
    private int $lastActivity;
    private int $messageId = 0;

    public function __construct(array $config)
    {
        $parsed = parse_url($config['url']);
        $this->host = $parsed['host'] ?? 'localhost';
        $this->port = $parsed['port'] ?? ($parsed['scheme'] === 'https' ? 443 : 80);
        $this->basePath = rtrim($parsed['path'] ?? '', '/');
        $this->ssl = ($parsed['scheme'] ?? 'http') === 'https';
        $this->authToken = $config['auth_token'] ?? null;
        $this->authHeader = $config['auth_header'] ?? 'Authorization';
        $this->responseChannel = new Channel(100);
        $this->lastActivity = time();
    }

    /**
     * Connect to SSE endpoint and initialize session
     */
    public function connect(): bool
    {
        if ($this->connected && $this->sessionUrl) {
            return true;
        }

        echo "[SSE] Connecting to {$this->host}:{$this->port}...\n";

        // Start SSE connection in a coroutine
        $sessionChannel = new Channel(1);

        Coroutine::create(function () use ($sessionChannel) {
            $this->runSseListener($sessionChannel);
        });

        // Wait for session URL (max 10 seconds)
        $sessionUrl = $sessionChannel->pop(10);
        if (!$sessionUrl) {
            echo "[SSE] Failed to get session URL\n";
            return false;
        }

        $this->sessionUrl = $sessionUrl;
        $this->connected = true;
        echo "[SSE] Session URL: {$this->sessionUrl}\n";

        // Initialize MCP session
        if (!$this->initialize()) {
            echo "[SSE] Failed to initialize MCP session\n";
            return false;
        }

        $this->initialized = true;
        return true;
    }

    /**
     * Run SSE listener coroutine using cURL for streaming
     * This keeps running until $this->connected becomes false
     */
    private function runSseListener(Channel $sessionChannel): void
    {
        $url = "http://{$this->host}:{$this->port}/sse";
        echo "[SSE] Connecting to {$url}...\n";

        $sessionSent = false;
        $buffer = '';

        // Use cURL for SSE - it handles chunked transfer better
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => [
                'Accept: text/event-stream',
                'Cache-Control: no-cache',
            ],
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_TIMEOUT => 0, // No timeout - keep connection open
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_WRITEFUNCTION => function($ch, $data) use (&$buffer, &$sessionSent, $sessionChannel) {
                if (!$this->connected) {
                    return 0; // Abort transfer
                }

                $buffer .= $data;
                $this->lastActivity = time();

                // Parse SSE events
                while (($pos = strpos($buffer, "\n\n")) !== false) {
                    $event = substr($buffer, 0, $pos);
                    $buffer = substr($buffer, $pos + 2);

                    $eventType = null;
                    $eventData = null;

                    foreach (explode("\n", $event) as $line) {
                        if (strpos($line, 'event: ') === 0) {
                            $eventType = trim(substr($line, 7));
                        } elseif (strpos($line, 'data: ') === 0) {
                            $eventData = trim(substr($line, 6));
                        }
                    }

                    echo "[SSE] Event: {$eventType}\n";

                    if ($eventType === 'endpoint' && $eventData && !$sessionSent) {
                        $fullUrl = "http://{$this->host}:{$this->port}{$eventData}";
                        echo "[SSE] Got session URL: {$fullUrl}\n";
                        $sessionChannel->push($fullUrl);
                        $sessionSent = true;
                    } elseif ($eventType === 'message' && $eventData) {
                        $this->handleSseMessage($eventData);
                    }
                }

                return strlen($data);
            },
        ]);

        // This blocks until connection closes or callback returns 0
        // In OpenSwoole coroutine context, this should yield to other coroutines
        $this->connected = true;
        curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if (!$sessionSent) {
            echo "[SSE] Failed: {$error}\n";
            $sessionChannel->push(false);
        }

        echo "[SSE] Listener exited\n";
        $this->connected = false;
    }

    /**
     * Handle incoming SSE message
     */
    private function handleSseMessage(string $data): void
    {
        $msg = json_decode($data, true);
        if (!$msg) return;

        echo "[SSE] << " . ($msg['method'] ?? "id:{$msg['id']}") . "\n";

        // Handle server requests
        if (isset($msg['method'])) {
            if ($msg['method'] === 'roots/list') {
                // Respond to roots/list request
                $this->postMessage([
                    'jsonrpc' => '2.0',
                    'id' => $msg['id'],
                    'result' => ['roots' => []]
                ]);
            }
            return;
        }

        // Handle responses
        if (isset($msg['id'])) {
            $this->responseChannel->push($msg, 0.1);
        }
    }

    /**
     * Initialize MCP session
     */
    private function initialize(): bool
    {
        $this->messageId = 1;

        $this->postMessage([
            'jsonrpc' => '2.0',
            'id' => $this->messageId,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-03-26',
                'capabilities' => [
                    'roots' => ['listChanged' => true],
                    'sampling' => new \stdClass(),
                ],
                'clientInfo' => [
                    'name' => 'Tiknix OpenSwoole MCP Proxy',
                    'version' => '1.0.0',
                ],
            ],
        ]);

        // Wait for response
        $response = $this->responseChannel->pop(10);
        if (!$response || !isset($response['result'])) {
            echo "[SSE] Initialize failed\n";
            return false;
        }

        echo "[SSE] Initialized: " . ($response['result']['serverInfo']['name'] ?? 'unknown') . "\n";

        // Send initialized notification
        $this->postMessage([
            'jsonrpc' => '2.0',
            'method' => 'notifications/initialized',
            'params' => new \stdClass(),
        ]);

        return true;
    }

    /**
     * Post a message to the session URL
     */
    private function postMessage(array $message): bool
    {
        if (!$this->sessionUrl) {
            return false;
        }

        $parsed = parse_url($this->sessionUrl);
        $client = new Client($this->host, $this->port, $this->ssl);
        $client->set(['timeout' => 30]);

        $headers = ['Content-Type' => 'application/json'];
        if ($this->authToken) {
            $prefix = ($this->authHeader === 'Authorization') ? 'Bearer ' : '';
            $headers[$this->authHeader] = $prefix . $this->authToken;
        }
        $client->setHeaders($headers);

        $path = $parsed['path'] . '?' . ($parsed['query'] ?? '');
        $client->post($path, json_encode($message));

        $success = $client->statusCode === 202 || $client->statusCode === 200;
        $client->close();

        if ($success) {
            echo "[SSE] >> " . ($message['method'] ?? 'response') . " - HTTP {$client->statusCode}\n";
        }

        return $success;
    }

    /**
     * Call an MCP tool
     */
    public function callTool(string $toolName, array $arguments = []): array
    {
        if (!$this->connected || !$this->initialized) {
            if (!$this->connect()) {
                return ['error' => 'Failed to connect to MCP server'];
            }
        }

        $this->messageId++;
        $id = $this->messageId;

        echo "[SSE] Calling tool: {$toolName}\n";

        $this->postMessage([
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => 'tools/call',
            'params' => [
                'name' => $toolName,
                'arguments' => empty($arguments) ? new \stdClass() : $arguments,
            ],
        ]);

        // Wait for response (up to 120 seconds for browser operations)
        $timeout = 120;
        $start = time();

        while ((time() - $start) < $timeout) {
            $response = $this->responseChannel->pop(1);
            if ($response && isset($response['id']) && $response['id'] === $id) {
                $this->lastActivity = time();
                return [
                    'success' => !isset($response['error']),
                    'result' => $response['result'] ?? null,
                    'error' => $response['error'] ?? null,
                ];
            }
        }

        return ['error' => 'Timeout waiting for tool response'];
    }

    /**
     * List available tools
     */
    public function listTools(): array
    {
        if (!$this->connected || !$this->initialized) {
            if (!$this->connect()) {
                return ['error' => 'Failed to connect'];
            }
        }

        $this->messageId++;
        $id = $this->messageId;

        $this->postMessage([
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => 'tools/list',
            'params' => new \stdClass(),
        ]);

        $response = $this->responseChannel->pop(10);
        if ($response && isset($response['result']['tools'])) {
            return $response['result']['tools'];
        }

        return [];
    }

    /**
     * Disconnect
     */
    public function disconnect(): void
    {
        $this->connected = false;
        $this->initialized = false;

        if ($this->sseClient) {
            $this->sseClient->close();
            $this->sseClient = null;
        }

        $this->sessionUrl = null;
        echo "[SSE] Disconnected\n";
    }

    /**
     * Check if connected
     */
    public function isConnected(): bool
    {
        return $this->connected && $this->initialized;
    }

    /**
     * Get last activity timestamp
     */
    public function getLastActivity(): int
    {
        return $this->lastActivity;
    }
}
