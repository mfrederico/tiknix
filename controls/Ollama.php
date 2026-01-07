<?php
/**
 * Ollama Controller
 *
 * Provides UI and API for local LLM inference using Ollama
 * with MCP tool integration.
 */

namespace app;

use \Flight as Flight;
use app\BaseControls\Control;
use app\OllamaClient;
use app\mcptools\ToolLoader;

class Ollama extends Control
{
    private OllamaClient $client;
    private ToolLoader $toolLoader;

    public function __construct()
    {
        parent::__construct();

        // Get Ollama URL from config or use default
        $ollamaUrl = Flight::get('ollama.url') ?? 'http://localhost:11434';
        $this->client = new OllamaClient($ollamaUrl);

        // Set up tool loader
        $toolsDir = dirname(__DIR__) . '/mcptools';
        $this->toolLoader = new ToolLoader($toolsDir);
        $this->client->setToolLoader($this->toolLoader);
    }

    /**
     * Main chat interface
     * GET /ollama
     */
    public function index($params = [])
    {
        if (!Flight::isLoggedIn()) {
            Flight::redirect('/auth/login?redirect=/ollama');
            return;
        }

        $isAvailable = $this->client->isAvailable();
        $models = $isAvailable ? $this->client->listModels() : [];
        $tools = $this->toolLoader->getDefinitions();

        // Get preferred model from session or default
        $preferredModel = $_SESSION['ollama_model'] ?? null;

        $this->viewData['title'] = 'Ollama Chat';
        $this->viewData['isAvailable'] = $isAvailable;
        $this->viewData['models'] = $models;
        $this->viewData['tools'] = $tools;
        $this->viewData['preferredModel'] = $preferredModel;
        $this->viewData['ollamaUrl'] = Flight::get('ollama.url') ?? 'http://localhost:11434';
        $this->viewData['csrf'] = SimpleCsrf::getTokenArray();

        $this->render('ollama/index', $this->viewData);
    }

    /**
     * Chat API endpoint
     * POST /ollama/chat
     */
    public function chat($params = [])
    {
        if (!Flight::isLoggedIn()) {
            Flight::jsonError('Unauthorized', 401);
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true);

        $model = $input['model'] ?? null;
        $messages = $input['messages'] ?? [];
        $enableTools = $input['enable_tools'] ?? true;
        $options = $input['options'] ?? [];

        if (!$model || empty($messages)) {
            Flight::jsonError('Model and messages required', 400);
            return;
        }

        // Save preferred model
        $_SESSION['ollama_model'] = $model;

        try {
            if (!$this->client->isAvailable()) {
                Flight::jsonError('Ollama server not available', 503);
                return;
            }

            // Use chatWithTools for full tool execution loop
            if ($enableTools) {
                $toolResults = [];
                $result = $this->client->chatWithTools(
                    $model,
                    $messages,
                    $options,
                    10,
                    function($toolName, $args, $result) use (&$toolResults) {
                        $toolResults[] = [
                            'tool' => $toolName,
                            'arguments' => $args,
                            'result' => $result
                        ];
                    }
                );

                Flight::json([
                    'success' => true,
                    'message' => $result['message'],
                    'tool_results' => $result['tool_results'],
                    'iterations' => $result['iterations'],
                    'model' => $result['model']
                ]);
            } else {
                // Simple chat without tools
                $result = $this->client->chat($model, $messages, $options, false);

                Flight::json([
                    'success' => true,
                    'message' => $result['message'],
                    'model' => $result['model']
                ]);
            }

        } catch (\Exception $e) {
            $this->logger->error('Ollama chat error', [
                'error' => $e->getMessage(),
                'model' => $model
            ]);
            Flight::jsonError($e->getMessage(), 500);
        }
    }

    /**
     * List available models
     * GET /ollama/models
     */
    public function models($params = [])
    {
        if (!Flight::isLoggedIn()) {
            Flight::jsonError('Unauthorized', 401);
            return;
        }

        try {
            if (!$this->client->isAvailable()) {
                Flight::json(['success' => false, 'error' => 'Ollama not available', 'models' => []]);
                return;
            }

            $models = $this->client->listModels();

            Flight::json([
                'success' => true,
                'models' => $models
            ]);

        } catch (\Exception $e) {
            Flight::json(['success' => false, 'error' => $e->getMessage(), 'models' => []]);
        }
    }

    /**
     * List available tools
     * GET /ollama/tools
     */
    public function tools($params = [])
    {
        if (!Flight::isLoggedIn()) {
            Flight::jsonError('Unauthorized', 401);
            return;
        }

        $tools = $this->toolLoader->getDefinitions();

        // Also return Ollama-formatted tools for reference
        $ollamaFormat = $this->client->getOllamaTools();

        Flight::json([
            'success' => true,
            'tools' => $tools,
            'ollama_format' => $ollamaFormat,
            'count' => count($tools)
        ]);
    }

    /**
     * Check Ollama server status
     * GET /ollama/status
     */
    public function status($params = [])
    {
        $isAvailable = $this->client->isAvailable();
        $ollamaUrl = Flight::get('ollama.url') ?? 'http://localhost:11434';

        $data = [
            'available' => $isAvailable,
            'url' => $ollamaUrl
        ];

        if ($isAvailable) {
            $models = $this->client->listModels();
            $data['model_count'] = count($models);
            $data['models'] = array_map(fn($m) => $m['name'], $models);
        }

        Flight::json($data);
    }

    /**
     * Execute a single tool (for testing)
     * POST /ollama/execute-tool
     */
    public function executeTool($params = [])
    {
        if (!Flight::isLoggedIn()) {
            Flight::jsonError('Unauthorized', 401);
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true);

        $toolName = $input['tool'] ?? null;
        $arguments = $input['arguments'] ?? [];

        if (!$toolName) {
            Flight::jsonError('Tool name required', 400);
            return;
        }

        try {
            $result = $this->client->executeTool($toolName, $arguments);

            Flight::json([
                'success' => true,
                'tool' => $toolName,
                'arguments' => $arguments,
                'result' => $result
            ]);

        } catch (\Exception $e) {
            Flight::jsonError($e->getMessage(), 500);
        }
    }

    /**
     * Pull a model
     * POST /ollama/pull
     */
    public function pull($params = [])
    {
        if (!Flight::hasLevel(LEVELS['ADMIN'])) {
            Flight::jsonError('Admin required', 403);
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true);
        $model = $input['model'] ?? null;

        if (!$model) {
            Flight::jsonError('Model name required', 400);
            return;
        }

        try {
            $result = $this->client->pullModel($model);

            $this->logger->info('Ollama model pulled', [
                'model' => $model,
                'by' => $this->member->id
            ]);

            Flight::json([
                'success' => true,
                'model' => $model,
                'result' => $result
            ]);

        } catch (\Exception $e) {
            Flight::jsonError($e->getMessage(), 500);
        }
    }

    /**
     * Get model details
     * GET /ollama/model?name=qwen2.5-coder:7b
     */
    public function model($params = [])
    {
        if (!Flight::isLoggedIn()) {
            Flight::jsonError('Unauthorized', 401);
            return;
        }

        $name = $this->getParam('name');
        if (!$name) {
            Flight::jsonError('Model name required', 400);
            return;
        }

        $info = $this->client->getModel($name);

        if ($info) {
            Flight::json(['success' => true, 'model' => $info]);
        } else {
            Flight::jsonError('Model not found', 404);
        }
    }
}
