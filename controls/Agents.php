<?php
/**
 * Agents Controller
 *
 * Manages AI agent profiles: create, edit, delete, test connections.
 * Each agent can be linked to a member (bot account) and optionally
 * exposed as an MCP tool.
 *
 * Providers: claude_cli, ollama, openai, custom
 */

namespace app;

use \Flight as Flight;
use \app\Bean;
use \Exception as Exception;
use app\BaseControls\Control;

class Agents extends Control {

    public function __construct() {
        parent::__construct();
    }

    /**
     * List all agent profiles for the current member
     */
    public function index($params = []) {
        if (!$this->requireLogin()) return;

        $this->viewData['title'] = 'Agents';

        // Get agents created by this member
        $agents = Bean::find('agent', 'created_by = ? ORDER BY name ASC', [$this->member->id]);

        $this->viewData['agents'] = $agents;
        $this->render('agents/index', $this->viewData);
    }

    /**
     * Show create agent form
     */
    public function create($params = []) {
        if (!$this->requireLogin()) return;

        $this->viewData['title'] = 'Create Agent';
        $this->viewData['providers'] = \Model_Agent::getValidProviders();
        $this->render('agents/create', $this->viewData);
    }

    /**
     * Store a new agent profile
     */
    public function store($params = []) {
        if (!$this->requireLogin()) return;

        $request = Flight::request();
        if ($request->method !== 'POST') {
            Flight::redirect('/agents');
            return;
        }

        if (!Flight::csrf()->validateRequest()) {
            $this->flash('error', 'Invalid CSRF token');
            Flight::redirect('/agents/create');
            return;
        }

        $name = trim($this->getParam('name', ''));
        $description = trim($this->getParam('description', ''));
        $provider = trim($this->getParam('provider', 'claude_cli'));
        $systemPrompt = trim($this->getParam('system_prompt', ''));

        if (empty($name)) {
            $this->flash('error', 'Agent name is required');
            Flight::redirect('/agents/create');
            return;
        }

        if (strlen($name) < 2 || strlen($name) > 255) {
            $this->flash('error', 'Agent name must be between 2 and 255 characters');
            Flight::redirect('/agents/create');
            return;
        }

        try {
            $agent = Bean::dispense('agent');
            $agent->name = $name;
            $agent->description = $description;
            $agent->provider = $provider;
            $agent->systemPrompt = $systemPrompt;
            $agent->createdBy = $this->member->id;
            $agent->isActive = 1;
            Bean::store($agent);

            $this->logger->info('Agent created', [
                'agent_id' => $agent->id,
                'agent_name' => $name,
                'provider' => $provider,
                'created_by' => $this->member->id
            ]);

            $this->flash('success', 'Agent created successfully');
            Flight::redirect('/agents/edit?id=' . $agent->id);

        } catch (\InvalidArgumentException $e) {
            $this->flash('error', $e->getMessage());
            Flight::redirect('/agents/create');
        } catch (Exception $e) {
            $this->logger->error('Failed to create agent', ['error' => $e->getMessage()]);
            $this->flash('error', 'Failed to create agent');
            Flight::redirect('/agents/create');
        }
    }

    /**
     * Show edit agent form with tab-based interface
     */
    public function edit($params = []) {
        if (!$this->requireLogin()) return;

        $agentId = (int)$this->getParam('id');
        if (!$agentId) {
            Flight::redirect('/agents');
            return;
        }

        $agent = Bean::load('agent', $agentId);
        if (!$agent->id) {
            $this->flash('error', 'Agent not found');
            Flight::redirect('/agents');
            return;
        }

        // Only the creator can edit
        if ((int)$agent->createdBy !== (int)$this->member->id && (int)$this->member->level > LEVELS['ADMIN']) {
            $this->flash('error', 'You do not have permission to edit this agent');
            Flight::redirect('/agents');
            return;
        }

        $this->viewData['title'] = 'Edit Agent - ' . $agent->name;
        $this->viewData['agent'] = $agent;
        $this->viewData['providers'] = \Model_Agent::getValidProviders();
        $this->viewData['tab'] = $this->getParam('tab', 'general');

        // Decode JSON fields for the form
        $this->viewData['providerConfig'] = json_decode($agent->providerConfig ?: '{}', true);
        $this->viewData['capabilities'] = json_decode($agent->capabilities ?: '[]', true);
        $this->viewData['mcpServers'] = json_decode($agent->mcpServers ?: '{}', true);
        $this->viewData['hooks'] = json_decode($agent->hooks ?: '{}', true);

        $this->render('agents/edit', $this->viewData);
    }

    /**
     * Update agent profile (handles tab-based updates)
     */
    public function update($params = []) {
        if (!$this->requireLogin()) return;

        $request = Flight::request();
        if ($request->method !== 'POST') {
            Flight::redirect('/agents');
            return;
        }

        $agentId = (int)$this->getParam('id');
        if (!$agentId) {
            Flight::redirect('/agents');
            return;
        }

        $agent = Bean::load('agent', $agentId);
        if (!$agent->id) {
            $this->flash('error', 'Agent not found');
            Flight::redirect('/agents');
            return;
        }

        if ((int)$agent->createdBy !== (int)$this->member->id && (int)$this->member->level > LEVELS['ADMIN']) {
            $this->flash('error', 'You do not have permission to edit this agent');
            Flight::redirect('/agents');
            return;
        }

        if (!Flight::csrf()->validateRequest()) {
            $this->flash('error', 'Invalid CSRF token');
            Flight::redirect('/agents/edit?id=' . $agentId);
            return;
        }

        $tab = $this->getParam('tab', 'general');

        try {
            switch ($tab) {
                case 'general':
                    $this->updateGeneral($agent);
                    break;
                case 'provider':
                    $this->updateProvider($agent);
                    break;
                case 'mcp':
                    $this->updateMcp($agent);
                    break;
                case 'hooks':
                    $this->updateHooks($agent);
                    break;
                case 'capabilities':
                    $this->updateCapabilities($agent);
                    break;
                case 'workstation':
                    $this->updateWorkstation($agent);
                    break;
                default:
                    $this->flash('error', 'Unknown update tab');
                    Flight::redirect('/agents/edit?id=' . $agentId);
                    return;
            }

            Bean::store($agent);

            $this->logger->info('Agent updated', [
                'agent_id' => $agentId,
                'tab' => $tab,
                'updated_by' => $this->member->id
            ]);

            $this->flash('success', 'Agent updated successfully');
            Flight::redirect('/agents/edit?id=' . $agentId . '&tab=' . $tab);

        } catch (\InvalidArgumentException $e) {
            $this->flash('error', $e->getMessage());
            Flight::redirect('/agents/edit?id=' . $agentId . '&tab=' . $tab);
        } catch (Exception $e) {
            $this->logger->error('Failed to update agent', ['error' => $e->getMessage()]);
            $this->flash('error', 'Failed to update agent');
            Flight::redirect('/agents/edit?id=' . $agentId . '&tab=' . $tab);
        }
    }

    /**
     * View agent profile (read-only)
     */
    public function view($params = []) {
        if (!$this->requireLogin()) return;

        // JSON list mode: return all active agents for modals/pickers
        $format = $this->getParam('format');
        if ($format === 'json') {
            $agents = Bean::find('agent', 'is_active = 1 ORDER BY name ASC');
            $list = [];
            foreach ($agents as $a) {
                $list[] = [
                    'id' => (int)$a->id,
                    'name' => $a->name,
                    'slug' => $a->slug,
                    'description' => $a->description,
                    'provider' => $a->provider,
                ];
            }
            Flight::json(['success' => true, 'agents' => $list]);
            return;
        }

        $agentId = (int)$this->getParam('id');
        if (!$agentId) {
            Flight::redirect('/agents');
            return;
        }

        $agent = Bean::load('agent', $agentId);
        if (!$agent->id) {
            $this->flash('error', 'Agent not found');
            Flight::redirect('/agents');
            return;
        }

        $this->viewData['title'] = $agent->name;
        $this->viewData['agent'] = $agent;
        $this->viewData['capabilities'] = json_decode($agent->capabilities ?: '[]', true);
        $this->viewData['mcpServers'] = json_decode($agent->mcpServers ?: '{}', true);

        $this->render('agents/view', $this->viewData);
    }

    /**
     * Delete (soft-delete) an agent
     */
    public function delete($params = []) {
        if (!$this->requireLogin()) return;

        $request = Flight::request();
        if ($request->method !== 'POST') {
            Flight::redirect('/agents');
            return;
        }

        $agentId = (int)$this->getParam('id');
        if (!$agentId) {
            Flight::redirect('/agents');
            return;
        }

        $agent = Bean::load('agent', $agentId);
        if (!$agent->id) {
            $this->flash('error', 'Agent not found');
            Flight::redirect('/agents');
            return;
        }

        if ((int)$agent->createdBy !== (int)$this->member->id && (int)$this->member->level > LEVELS['ADMIN']) {
            $this->flash('error', 'You do not have permission to delete this agent');
            Flight::redirect('/agents');
            return;
        }

        if (!Flight::csrf()->validateRequest()) {
            $this->flash('error', 'Invalid CSRF token');
            Flight::redirect('/agents/edit?id=' . $agentId);
            return;
        }

        try {
            // Soft delete: deactivate agent and its linked member
            $agent->isActive = 0;
            $agent->updatedAt = date('Y-m-d H:i:s');
            Bean::store($agent);

            if ($agent->memberId) {
                $member = Bean::load('member', (int)$agent->memberId);
                if ($member->id) {
                    $member->status = 'inactive';
                    Bean::store($member);
                }
            }

            $this->logger->info('Agent deleted (soft)', [
                'agent_id' => $agentId,
                'deleted_by' => $this->member->id
            ]);

            $this->flash('success', 'Agent deactivated');
            Flight::redirect('/agents');

        } catch (Exception $e) {
            $this->logger->error('Failed to delete agent', ['error' => $e->getMessage()]);
            $this->flash('error', 'Failed to delete agent');
            Flight::redirect('/agents');
        }
    }

    /**
     * AJAX endpoint: get capabilities for a given provider
     */
    public function getcapabilities($params = []) {
        if (!$this->requireLogin()) return;

        $provider = trim($this->getParam('provider', ''));

        $capabilities = $this->getProviderCapabilities($provider);

        Flight::json([
            'success' => true,
            'provider' => $provider,
            'capabilities' => $capabilities
        ]);
    }

    /**
     * AJAX endpoint: test provider connection with given config
     */
    public function testconnection($params = []) {
        if (!$this->requireLogin()) return;

        $provider = trim($this->getParam('provider', ''));
        $configJson = $this->getParam('config', '{}');

        $config = json_decode($configJson, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            Flight::jsonError('Invalid config JSON', 400);
            return;
        }

        $result = $this->testProviderConnection($provider, $config);

        Flight::json([
            'success' => $result['connected'],
            'message' => $result['message'],
            'details' => $result['details'] ?? null
        ]);
    }

    // =========================================================================
    // Private tab update methods
    // =========================================================================

    /**
     * Update general settings tab
     */
    private function updateGeneral($agent): void {
        $name = trim($this->getParam('name', ''));
        $description = trim($this->getParam('description', ''));
        $systemPrompt = trim($this->getParam('system_prompt', ''));
        $isActive = (int)$this->getParam('is_active', 1);

        if (empty($name)) {
            throw new \InvalidArgumentException('Agent name is required');
        }

        $agent->name = $name;
        $agent->description = $description;
        $agent->systemPrompt = $systemPrompt;
        $agent->isActive = $isActive;

        // Regenerate slug if name changed
        $currentSlug = $agent->slug;
        $expectedSlug = $this->generateSlug($name);
        if (strpos($currentSlug, $expectedSlug) !== 0) {
            $agent->slug = '';  // Model will regenerate
        }
    }

    /**
     * Update provider config tab
     */
    private function updateProvider($agent): void {
        $provider = trim($this->getParam('provider', ''));
        $configJson = trim($this->getParam('provider_config', '{}'));

        if (!empty($provider)) {
            $agent->provider = $provider;
        }

        // Validate JSON
        json_decode($configJson);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \InvalidArgumentException('Invalid provider config JSON');
        }

        $agent->providerConfig = $configJson;
    }

    /**
     * Update MCP server config tab
     */
    private function updateMcp($agent): void {
        $mcpJson = trim($this->getParam('mcp_servers', '{}'));
        $exposeAsMcp = (int)$this->getParam('expose_as_mcp', 0);
        $mcpToolName = trim($this->getParam('mcp_tool_name', ''));

        json_decode($mcpJson);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \InvalidArgumentException('Invalid MCP servers JSON');
        }

        $agent->mcpServers = $mcpJson;
        $agent->exposeAsMcp = $exposeAsMcp;
        $agent->mcpToolName = $mcpToolName ?: null;
    }

    /**
     * Update hooks config tab
     */
    private function updateHooks($agent): void {
        $hooksJson = trim($this->getParam('hooks', '{}'));

        json_decode($hooksJson);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \InvalidArgumentException('Invalid hooks JSON');
        }

        $agent->hooks = $hooksJson;
    }

    /**
     * Update workstation assignment tab
     */
    private function updateWorkstation($agent): void {
        $runnerId = (int)$this->getParam('runner_id', 0);
        $defaultWorkDir = trim($this->getParam('default_work_dir', ''));

        if ($runnerId) {
            // Verify runner exists and is active
            $runner = Bean::findOne('runner', 'id = ? AND is_active = 1', [$runnerId]);
            if (!$runner) {
                throw new \InvalidArgumentException('Selected workstation not found or inactive');
            }
            $agent->runnerId = $runnerId;
        } else {
            $agent->runnerId = null;
        }

        $agent->defaultWorkDir = $defaultWorkDir ?: null;
    }

    /**
     * Update capabilities tab
     */
    private function updateCapabilities($agent): void {
        $capabilitiesJson = trim($this->getParam('capabilities', '[]'));

        json_decode($capabilitiesJson);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \InvalidArgumentException('Invalid capabilities JSON');
        }

        $agent->capabilities = $capabilitiesJson;
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Get capability definitions for a provider
     */
    private function getProviderCapabilities(string $provider): array {
        $defaults = [
            'claude_cli' => [
                'code_generation', 'code_review', 'debugging',
                'documentation', 'testing', 'refactoring',
                'architecture', 'mcp_tools'
            ],
            'ollama' => [
                'code_generation', 'code_review', 'documentation',
                'summarization'
            ],
            'openai' => [
                'code_generation', 'code_review', 'debugging',
                'documentation', 'testing', 'function_calling'
            ],
            'custom' => []
        ];

        return $defaults[$provider] ?? [];
    }

    /**
     * Test connection to a provider
     */
    private function testProviderConnection(string $provider, array $config): array {
        switch ($provider) {
            case 'claude_cli':
                return $this->testClaudeCliConnection($config);
            case 'ollama':
                return $this->testOllamaConnection($config);
            case 'openai':
                return $this->testOpenAiConnection($config);
            default:
                return [
                    'connected' => false,
                    'message' => 'Unknown provider: ' . $provider
                ];
        }
    }

    /**
     * Test Claude CLI availability
     */
    private function testClaudeCliConnection(array $config): array {
        $binary = $config['binary_path'] ?? null;

        // If no explicit path, search common locations
        // PHP-FPM runs with a limited PATH, so claude may not be found via `which`
        if (!$binary) {
            $searchPaths = [
                'claude', // try PATH first
                getenv('HOME') . '/.local/bin/claude',
                '/home/' . get_current_user() . '/.local/bin/claude',
                '/usr/local/bin/claude',
                '/usr/bin/claude',
            ];
            // Also check the user who owns this PHP process
            $homeDir = getenv('HOME') ?: ('/home/' . (getenv('USER') ?: 'mfrederico'));
            $searchPaths[] = $homeDir . '/.local/bin/claude';

            foreach ($searchPaths as $path) {
                $testOutput = [];
                $testCode = 0;
                exec(escapeshellarg($path) . ' --version 2>&1', $testOutput, $testCode);
                if ($testCode === 0) {
                    $binary = $path;
                    break;
                }
            }
        }

        if (!$binary) {
            return [
                'connected' => false,
                'message' => 'Claude CLI not found. Install with: npm install -g @anthropic-ai/claude-code'
            ];
        }

        $output = [];
        $returnCode = 0;
        exec(escapeshellarg($binary) . ' --version 2>&1', $output, $returnCode);

        if ($returnCode === 0) {
            return [
                'connected' => true,
                'message' => 'Claude CLI available: ' . trim(implode(' ', $output)),
                'details' => [
                    'version' => implode("\n", $output),
                    'path' => $binary
                ]
            ];
        }

        return [
            'connected' => false,
            'message' => 'Claude CLI found at ' . $binary . ' but failed to run'
        ];
    }

    /**
     * Test Ollama API connectivity
     */
    private function testOllamaConnection(array $config): array {
        $baseUrl = rtrim($config['base_url'] ?? 'http://localhost:11434', '/');

        $ch = curl_init($baseUrl . '/api/tags');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 3
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            $data = json_decode($response, true);
            $models = array_column($data['models'] ?? [], 'name');
            return [
                'connected' => true,
                'message' => 'Ollama connected (' . count($models) . ' models available)',
                'details' => ['models' => $models]
            ];
        }

        return [
            'connected' => false,
            'message' => 'Cannot reach Ollama at ' . $baseUrl
        ];
    }

    /**
     * Test OpenAI API connectivity
     */
    private function testOpenAiConnection(array $config): array {
        $apiKey = $config['api_key'] ?? '';
        if (empty($apiKey)) {
            return [
                'connected' => false,
                'message' => 'API key is required for OpenAI'
            ];
        }

        $ch = curl_init('https://api.openai.com/v1/models');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiKey
            ]
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            return [
                'connected' => true,
                'message' => 'OpenAI API connected'
            ];
        }

        return [
            'connected' => false,
            'message' => 'OpenAI API returned HTTP ' . $httpCode
        ];
    }

    /**
     * Generate URL-safe slug from name
     */
    private function generateSlug(string $name): string {
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');
        return substr($slug, 0, 50);
    }
}
