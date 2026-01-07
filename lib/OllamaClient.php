<?php
/**
 * Ollama API Client
 *
 * Provides integration with local Ollama server for LLM inference
 * with tool calling support using existing MCP tools.
 */

namespace app;

use app\mcptools\ToolLoader;

class OllamaClient
{
    private string $baseUrl;
    private int $timeout;
    private ?ToolLoader $toolLoader = null;
    private array $toolInstances = [];

    public function __construct(string $baseUrl = 'http://localhost:11434', int $timeout = 120)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->timeout = $timeout;
    }

    /**
     * Set the tool loader for tool calling support
     */
    public function setToolLoader(ToolLoader $loader): self
    {
        $this->toolLoader = $loader;
        return $this;
    }

    /**
     * List available models
     */
    public function listModels(): array
    {
        $response = $this->request('GET', '/api/tags');
        return $response['models'] ?? [];
    }

    /**
     * Check if Ollama server is available
     */
    public function isAvailable(): bool
    {
        try {
            $this->listModels();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get model info
     */
    public function getModel(string $model): ?array
    {
        try {
            return $this->request('POST', '/api/show', ['name' => $model]);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Chat completion with optional tool support
     *
     * @param string $model Model name (e.g., 'qwen2.5-coder:7b')
     * @param array $messages Chat messages [['role' => 'user', 'content' => '...']]
     * @param array $options Additional options (temperature, etc.)
     * @param bool $enableTools Enable MCP tool calling
     * @return array Response with 'message' and optionally 'tool_calls'
     */
    public function chat(string $model, array $messages, array $options = [], bool $enableTools = true): array
    {
        $payload = [
            'model' => $model,
            'messages' => $messages,
            'stream' => false,
            'options' => array_merge([
                'temperature' => 0.7,
            ], $options)
        ];

        // Add tools if enabled and loader is set
        if ($enableTools && $this->toolLoader) {
            $payload['tools'] = $this->getOllamaTools();
        }

        $response = $this->request('POST', '/api/chat', $payload);

        return [
            'message' => $response['message'] ?? null,
            'model' => $response['model'] ?? $model,
            'done' => $response['done'] ?? true,
            'total_duration' => $response['total_duration'] ?? null,
            'eval_count' => $response['eval_count'] ?? null,
        ];
    }

    /**
     * Chat with automatic tool execution loop
     *
     * Handles the full conversation including executing tool calls
     * and feeding results back to the model.
     *
     * @param string $model Model name
     * @param array $messages Initial messages
     * @param array $options Model options
     * @param int $maxIterations Max tool call iterations to prevent infinite loops
     * @param callable|null $onToolCall Callback for tool call events: fn(string $toolName, array $args, mixed $result)
     * @return array Final response with full message history
     */
    public function chatWithTools(
        string $model,
        array $messages,
        array $options = [],
        int $maxIterations = 10,
        ?callable $onToolCall = null
    ): array {
        $iteration = 0;
        $toolResults = [];

        while ($iteration < $maxIterations) {
            $response = $this->chat($model, $messages, $options, true);
            $message = $response['message'];

            // Check if model wants to call tools
            if (!empty($message['tool_calls'])) {
                // Add assistant message with tool calls to history
                $messages[] = $message;

                // Execute each tool call
                foreach ($message['tool_calls'] as $toolCall) {
                    $functionName = $toolCall['function']['name'] ?? '';
                    $arguments = $toolCall['function']['arguments'] ?? [];

                    // Execute the tool
                    $result = $this->executeTool($functionName, $arguments);

                    // Track results
                    $toolResults[] = [
                        'tool' => $functionName,
                        'arguments' => $arguments,
                        'result' => $result,
                        'iteration' => $iteration
                    ];

                    // Callback for UI updates
                    if ($onToolCall) {
                        $onToolCall($functionName, $arguments, $result);
                    }

                    // Add tool response to messages
                    $messages[] = [
                        'role' => 'tool',
                        'content' => is_string($result) ? $result : json_encode($result)
                    ];
                }

                $iteration++;
                continue;
            }

            // No tool calls, we're done
            return [
                'message' => $message,
                'messages' => $messages,
                'tool_results' => $toolResults,
                'iterations' => $iteration,
                'model' => $response['model'],
                'total_duration' => $response['total_duration'],
            ];
        }

        // Max iterations reached
        return [
            'message' => $message ?? ['role' => 'assistant', 'content' => 'Max tool iterations reached.'],
            'messages' => $messages,
            'tool_results' => $toolResults,
            'iterations' => $iteration,
            'max_iterations_reached' => true,
            'model' => $model,
        ];
    }

    /**
     * Stream chat completion (generator)
     *
     * @param string $model Model name
     * @param array $messages Chat messages
     * @param array $options Model options
     * @return \Generator Yields response chunks
     */
    public function chatStream(string $model, array $messages, array $options = []): \Generator
    {
        $payload = [
            'model' => $model,
            'messages' => $messages,
            'stream' => true,
            'options' => array_merge([
                'temperature' => 0.7,
            ], $options)
        ];

        $ch = curl_init($this->baseUrl . '/api/chat');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_WRITEFUNCTION => function($ch, $data) use (&$buffer) {
                $buffer .= $data;
                return strlen($data);
            }
        ]);

        $buffer = '';
        curl_exec($ch);
        curl_close($ch);

        // Parse NDJSON response
        $lines = explode("\n", trim($buffer));
        foreach ($lines as $line) {
            if (empty($line)) continue;
            $chunk = json_decode($line, true);
            if ($chunk) {
                yield $chunk;
            }
        }
    }

    /**
     * Simple text generation (non-chat)
     */
    public function generate(string $model, string $prompt, array $options = []): string
    {
        $payload = [
            'model' => $model,
            'prompt' => $prompt,
            'stream' => false,
            'options' => $options
        ];

        $response = $this->request('POST', '/api/generate', $payload);
        return $response['response'] ?? '';
    }

    /**
     * Convert MCP tools to Ollama format
     */
    public function getOllamaTools(): array
    {
        if (!$this->toolLoader) {
            return [];
        }

        $tools = [];
        foreach ($this->toolLoader->getDefinitions() as $def) {
            // Get input schema with defaults
            $inputSchema = $def['inputSchema'] ?? [
                'type' => 'object',
                'properties' => [],
                'required' => []
            ];

            // Ollama expects properties as object/map, not array
            // Convert empty array [] to stdClass for proper JSON encoding as {}
            $properties = $inputSchema['properties'] ?? [];
            if (empty($properties) || (is_array($properties) && array_keys($properties) === range(0, count($properties) - 1))) {
                // Empty or indexed array - convert to stdClass for JSON {}
                $properties = new \stdClass();
            }

            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => $def['name'],
                    'description' => $def['description'],
                    'parameters' => [
                        'type' => $inputSchema['type'] ?? 'object',
                        'properties' => $properties,
                        'required' => $inputSchema['required'] ?? []
                    ]
                ]
            ];
        }

        return $tools;
    }

    /**
     * Execute a tool by name
     */
    public function executeTool(string $name, array $arguments): mixed
    {
        if (!$this->toolLoader) {
            return ['error' => 'Tool loader not configured'];
        }

        // Get or create tool instance
        if (!isset($this->toolInstances[$name])) {
            $tool = $this->toolLoader->get($name);
            if (!$tool) {
                return ['error' => "Tool not found: {$name}"];
            }
            $this->toolInstances[$name] = $tool;
        }

        try {
            $result = $this->toolInstances[$name]->execute($arguments);
            return $result;
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Get list of available tool names
     */
    public function getAvailableToolNames(): array
    {
        if (!$this->toolLoader) {
            return [];
        }

        return array_column($this->toolLoader->getDefinitions(), 'name');
    }

    /**
     * Make HTTP request to Ollama API
     */
    private function request(string $method, string $endpoint, array $data = []): array
    {
        $url = $this->baseUrl . $endpoint;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new \Exception("Ollama connection failed: {$error}");
        }

        if ($httpCode >= 400) {
            $body = json_decode($response, true);
            $message = $body['error'] ?? "HTTP {$httpCode}";
            throw new \Exception("Ollama API error: {$message}");
        }

        return json_decode($response, true) ?? [];
    }

    /**
     * Pull a model from Ollama library
     */
    public function pullModel(string $model): array
    {
        return $this->request('POST', '/api/pull', ['name' => $model, 'stream' => false]);
    }

    /**
     * Delete a model
     */
    public function deleteModel(string $model): bool
    {
        try {
            $this->request('DELETE', '/api/delete', ['name' => $model]);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}
