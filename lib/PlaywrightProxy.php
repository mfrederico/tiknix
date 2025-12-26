<?php
/**
 * PlaywrightProxy - Proxy to External Playwright MCP Server
 *
 * Proxies MCP tool calls to an external Playwright server for browser automation.
 * This allows Claude to perform UI/UX testing through Tiknix's MCP gateway.
 *
 * Configuration in conf/playwright.php or environment variables:
 * - PLAYWRIGHT_MCP_URL: The URL of the Playwright MCP server
 * - PLAYWRIGHT_MCP_TOKEN: Optional auth token for the Playwright server
 */

namespace app;

class PlaywrightProxy {

    private string $serverUrl;
    private ?string $authToken;
    private int $timeout;

    /**
     * Create a new PlaywrightProxy instance
     *
     * @param string|null $serverUrl Override server URL
     * @param string|null $authToken Override auth token
     */
    public function __construct(?string $serverUrl = null, ?string $authToken = null) {
        // Load from config or environment
        $this->serverUrl = $serverUrl
            ?? getenv('PLAYWRIGHT_MCP_URL')
            ?: \Flight::get('playwright.mcp_url')
            ?: 'http://localhost:3000';

        $this->authToken = $authToken
            ?? getenv('PLAYWRIGHT_MCP_TOKEN')
            ?: \Flight::get('playwright.mcp_token')
            ?: null;

        $this->timeout = (int)(getenv('PLAYWRIGHT_TIMEOUT') ?: 30);
    }

    /**
     * Get available tools from the Playwright server
     *
     * @return array Tool definitions
     */
    public function getTools(): array {
        try {
            $response = $this->sendRequest('tools/list', []);
            return $response['tools'] ?? [];
        } catch (\Exception $e) {
            error_log("PlaywrightProxy::getTools failed: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Check if Playwright server is available
     *
     * @return bool
     */
    public function isAvailable(): bool {
        try {
            $response = $this->sendRequest('ping', []);
            return isset($response['result']) || isset($response['pong']);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Call a Playwright tool
     *
     * @param string $toolName The tool name
     * @param array $arguments The tool arguments
     * @return array The result
     */
    public function callTool(string $toolName, array $arguments): array {
        return $this->sendRequest('tools/call', [
            'name' => $toolName,
            'arguments' => $arguments
        ]);
    }

    /**
     * Navigate to a URL
     *
     * @param string $url URL to navigate to
     * @param array $options Navigation options
     * @return array Result
     */
    public function navigate(string $url, array $options = []): array {
        return $this->callTool('playwright_navigate', [
            'url' => $url,
            'waitUntil' => $options['waitUntil'] ?? 'load'
        ]);
    }

    /**
     * Take a screenshot
     *
     * @param string $name Screenshot name
     * @param array $options Screenshot options
     * @return array Result with screenshot data
     */
    public function screenshot(string $name, array $options = []): array {
        return $this->callTool('playwright_screenshot', [
            'name' => $name,
            'fullPage' => $options['fullPage'] ?? false,
            'width' => $options['width'] ?? 1280,
            'height' => $options['height'] ?? 720
        ]);
    }

    /**
     * Click an element
     *
     * @param string $selector CSS or XPath selector
     * @return array Result
     */
    public function click(string $selector): array {
        return $this->callTool('playwright_click', [
            'selector' => $selector
        ]);
    }

    /**
     * Fill a form field
     *
     * @param string $selector Field selector
     * @param string $value Value to fill
     * @return array Result
     */
    public function fill(string $selector, string $value): array {
        return $this->callTool('playwright_fill', [
            'selector' => $selector,
            'value' => $value
        ]);
    }

    /**
     * Get page content/snapshot
     *
     * @return array Result with page content
     */
    public function getSnapshot(): array {
        return $this->callTool('playwright_snapshot', []);
    }

    /**
     * Evaluate JavaScript in the page
     *
     * @param string $script JavaScript to evaluate
     * @return array Result
     */
    public function evaluate(string $script): array {
        return $this->callTool('playwright_evaluate', [
            'script' => $script
        ]);
    }

    /**
     * Close the browser session
     *
     * @return array Result
     */
    public function close(): array {
        return $this->callTool('playwright_close', []);
    }

    /**
     * Send a request to the Playwright MCP server
     *
     * @param string $method MCP method
     * @param array $params Parameters
     * @return array Response
     */
    private function sendRequest(string $method, array $params): array {
        $requestId = uniqid('pw-');

        $payload = [
            'jsonrpc' => '2.0',
            'id' => $requestId,
            'method' => $method,
            'params' => $params
        ];

        $ch = curl_init($this->serverUrl);

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        if ($this->authToken) {
            $headers[] = 'Authorization: Bearer ' . $this->authToken;
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new \Exception("Playwright MCP connection failed: {$error}");
        }

        if ($httpCode >= 400) {
            throw new \Exception("Playwright MCP error (HTTP {$httpCode}): {$response}");
        }

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception("Invalid JSON response from Playwright MCP");
        }

        if (isset($data['error'])) {
            throw new \Exception($data['error']['message'] ?? 'Unknown Playwright MCP error');
        }

        return $data['result'] ?? $data;
    }

    /**
     * Get the server URL
     *
     * @return string
     */
    public function getServerUrl(): string {
        return $this->serverUrl;
    }

    /**
     * Get connection status info
     *
     * @return array
     */
    public function getStatus(): array {
        $available = $this->isAvailable();

        return [
            'server_url' => $this->serverUrl,
            'available' => $available,
            'authenticated' => $this->authToken !== null,
            'timeout' => $this->timeout
        ];
    }
}
