<?php
/**
 * Agent Setup Controller
 *
 * Central hub for managing Claude Code agent configuration:
 * - MCP Servers
 * - MCP Tools
 * - Claude Hooks
 *
 * Uses tabbed interface for unified management experience.
 */

namespace app;

use \Flight as Flight;
use \Exception as Exception;
use app\BaseControls\Control;
use app\mcptools\ToolLoader;

class Agentsetup extends Control {

    private string $toolsDir;
    private string $hooksDir;
    private string $settingsFile;

    public function __construct() {
        parent::__construct();
        $this->toolsDir = dirname(__DIR__) . '/mcptools';
        $this->hooksDir = dirname(__DIR__) . '/scripts/hooks';
        $this->settingsFile = dirname(__DIR__) . '/.claude/settings.json';
    }

    /**
     * Main tabbed interface
     */
    public function index($params = []) {
        if (!$this->requireLevel(LEVELS['ADMIN'])) return;

        $activeTab = $this->getParam('tab', 'servers');
        $isRoot = ($this->viewData['member']['level'] ?? 100) <= 1;

        // Load MCP Servers data
        $servers = Mcp::getAvailableServers();
        $systemServers = [];
        $userServers = [];
        foreach ($servers as $slug => $server) {
            if ($server['source'] === 'system') {
                $systemServers[$slug] = $server;
            } else {
                $userServers[$slug] = $server;
            }
        }

        // Load MCP Tools data (ROOT only)
        $tools = [];
        if ($isRoot) {
            $toolLoader = new ToolLoader($this->toolsDir);
            $definitions = $toolLoader->getDefinitions();
            foreach ($definitions as $def) {
                $name = $def['name'] ?? '';
                $filePath = $this->findToolFile($name);
                $tools[] = [
                    'name' => $name,
                    'description' => $def['description'] ?? '',
                    'inputSchema' => $def['inputSchema'] ?? [],
                    'file' => $filePath ? basename($filePath) : null,
                    'modTime' => $filePath && file_exists($filePath) ? filemtime($filePath) : null
                ];
            }
            usort($tools, fn($a, $b) => strcmp($a['name'], $b['name']));
        }

        // Load Hooks data (ROOT only)
        $hookFiles = [];
        $hookConfig = [];
        if ($isRoot) {
            $files = glob($this->hooksDir . '/*.php');
            foreach ($files as $file) {
                $hookFiles[] = [
                    'name' => basename($file, '.php'),
                    'file' => basename($file),
                    'modTime' => filemtime($file),
                    'size' => filesize($file)
                ];
            }
            usort($hookFiles, fn($a, $b) => strcmp($a['name'], $b['name']));

            $settings = $this->loadSettings();
            $hookConfig = $settings['hooks'] ?? [];
        }

        $this->viewData['title'] = 'Agent Setup';
        $this->viewData['activeTab'] = $activeTab;
        $this->viewData['isRoot'] = $isRoot;
        $this->viewData['systemServers'] = $systemServers;
        $this->viewData['userServers'] = $userServers;
        $this->viewData['tools'] = $tools;
        $this->viewData['hookFiles'] = $hookFiles;
        $this->viewData['hookConfig'] = $hookConfig;
        $this->viewData['csrf'] = SimpleCsrf::getTokenArray();

        $this->render('agentsetup/index', $this->viewData);
    }

    // ==================== MCP SERVER ACTIONS ====================

    /**
     * Store new MCP server
     */
    public function storeServer($params = []) {
        if (!$this->requireLevel(LEVELS['ADMIN'])) return;

        if (!$this->validatePost()) return;

        $slug = $this->sanitize($this->getParam('slug', ''));
        $serverConfig = $this->buildServerConfig();

        if (empty($slug) || !preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug)) {
            $_SESSION['flash'][] = ['type' => 'error', 'message' => 'Invalid server name'];
            Flight::redirect('/agent-setup?tab=servers');
            return;
        }

        if (in_array($slug, ['tiknix', 'playwright'])) {
            $_SESSION['flash'][] = ['type' => 'error', 'message' => 'Cannot use reserved name'];
            Flight::redirect('/agent-setup?tab=servers');
            return;
        }

        try {
            if (Mcp::addServer($slug, $serverConfig)) {
                $_SESSION['flash'][] = ['type' => 'success', 'message' => 'Server added: ' . $slug];
            } else {
                $_SESSION['flash'][] = ['type' => 'error', 'message' => 'Failed to save'];
            }
        } catch (Exception $e) {
            $_SESSION['flash'][] = ['type' => 'error', 'message' => 'Error: ' . $e->getMessage()];
        }

        Flight::redirect('/agent-setup?tab=servers');
    }

    /**
     * Update MCP server
     */
    public function updateServer($params = []) {
        if (!$this->requireLevel(LEVELS['ADMIN'])) return;

        if (!$this->validatePost()) return;

        $slug = $this->sanitize($this->getParam('slug', ''));
        $serverConfig = $this->buildServerConfig();

        if (in_array($slug, ['tiknix'])) {
            $_SESSION['flash'][] = ['type' => 'error', 'message' => 'Cannot modify system server'];
            Flight::redirect('/agent-setup?tab=servers');
            return;
        }

        try {
            if (Mcp::updateServer($slug, $serverConfig)) {
                $_SESSION['flash'][] = ['type' => 'success', 'message' => 'Server updated: ' . $slug];
            } else {
                $_SESSION['flash'][] = ['type' => 'error', 'message' => 'Failed to save'];
            }
        } catch (Exception $e) {
            $_SESSION['flash'][] = ['type' => 'error', 'message' => 'Error: ' . $e->getMessage()];
        }

        Flight::redirect('/agent-setup?tab=servers');
    }

    /**
     * Delete MCP server
     */
    public function deleteServer($params = []) {
        if (!$this->requireLevel(LEVELS['ADMIN'])) return;

        if (!$this->validatePost()) return;

        $slug = $this->sanitize($this->getParam('slug', ''));

        if (in_array($slug, ['tiknix', 'playwright'])) {
            $_SESSION['flash'][] = ['type' => 'error', 'message' => 'Cannot delete system server'];
            Flight::redirect('/agent-setup?tab=servers');
            return;
        }

        try {
            if (Mcp::removeServer($slug)) {
                $_SESSION['flash'][] = ['type' => 'success', 'message' => 'Server removed: ' . $slug];
            }
        } catch (Exception $e) {
            $_SESSION['flash'][] = ['type' => 'error', 'message' => 'Error: ' . $e->getMessage()];
        }

        Flight::redirect('/agent-setup?tab=servers');
    }

    // ==================== MCP TOOL ACTIONS ====================

    /**
     * Store new tool
     */
    public function storeTool($params = []) {
        if (!$this->requireLevel(LEVELS['ROOT'])) return;

        if (!$this->validatePost()) return;

        $code = $this->getParam('code', '');
        $fileName = $this->sanitize($this->getParam('file_name', ''));

        if (empty($code) || !preg_match('/^[A-Z][a-zA-Z0-9]*Tool\.php$/', $fileName)) {
            $_SESSION['flash'][] = ['type' => 'error', 'message' => 'Invalid file name (must be PascalCaseTool.php)'];
            Flight::redirect('/agent-setup?tab=tools');
            return;
        }

        $filePath = $this->toolsDir . '/' . $fileName;
        if (file_exists($filePath)) {
            $_SESSION['flash'][] = ['type' => 'error', 'message' => 'File already exists'];
            Flight::redirect('/agent-setup?tab=tools');
            return;
        }

        $validation = PhpValidator::validateAll($code, 'tool');
        if (!empty($validation['errors'])) {
            $_SESSION['flash'][] = ['type' => 'error', 'message' => implode(', ', array_column($validation['errors'], 'message'))];
            Flight::redirect('/agent-setup?tab=tools');
            return;
        }

        try {
            file_put_contents($filePath, $code);
            $_SESSION['flash'][] = ['type' => 'success', 'message' => 'Tool created: ' . $fileName];
        } catch (Exception $e) {
            $_SESSION['flash'][] = ['type' => 'error', 'message' => 'Error: ' . $e->getMessage()];
        }

        Flight::redirect('/agent-setup?tab=tools');
    }

    /**
     * Update tool
     */
    public function updateTool($params = []) {
        if (!$this->requireLevel(LEVELS['ROOT'])) return;

        if (!$this->validatePost()) return;

        $code = $this->getParam('code', '');
        $name = $this->sanitize($this->getParam('name', ''));

        $filePath = $this->findToolFile($name);
        if (!$filePath) {
            $_SESSION['flash'][] = ['type' => 'error', 'message' => 'Tool not found'];
            Flight::redirect('/agent-setup?tab=tools');
            return;
        }

        $validation = PhpValidator::validateAll($code, 'tool');
        if (!empty($validation['errors'])) {
            $_SESSION['flash'][] = ['type' => 'error', 'message' => implode(', ', array_column($validation['errors'], 'message'))];
            Flight::redirect('/agent-setup?tab=tools&edit=' . urlencode($name));
            return;
        }

        try {
            copy($filePath, $filePath . '.bak.' . date('Ymd_His'));
            file_put_contents($filePath, $code);
            $_SESSION['flash'][] = ['type' => 'success', 'message' => 'Tool updated'];
        } catch (Exception $e) {
            $_SESSION['flash'][] = ['type' => 'error', 'message' => 'Error: ' . $e->getMessage()];
        }

        Flight::redirect('/agent-setup?tab=tools');
    }

    /**
     * Delete tool
     */
    public function deleteTool($params = []) {
        if (!$this->requireLevel(LEVELS['ROOT'])) return;

        if (!$this->validatePost()) return;

        $name = $this->sanitize($this->getParam('name', ''));
        $filePath = $this->findToolFile($name);

        if (!$filePath || in_array(basename($filePath), ['BaseTool.php', 'ToolLoader.php'])) {
            $_SESSION['flash'][] = ['type' => 'error', 'message' => 'Cannot delete'];
            Flight::redirect('/agent-setup?tab=tools');
            return;
        }

        try {
            rename($filePath, $filePath . '.deleted.' . date('Ymd_His'));
            $_SESSION['flash'][] = ['type' => 'success', 'message' => 'Tool deleted'];
        } catch (Exception $e) {
            $_SESSION['flash'][] = ['type' => 'error', 'message' => 'Error: ' . $e->getMessage()];
        }

        Flight::redirect('/agent-setup?tab=tools');
    }

    // ==================== HOOK ACTIONS ====================

    /**
     * Store new hook
     */
    public function storeHook($params = []) {
        if (!$this->requireLevel(LEVELS['ROOT'])) return;

        if (!$this->validatePost()) return;

        $code = $this->getParam('code', '');
        $fileName = $this->sanitize($this->getParam('file_name', ''));

        if (empty($code) || !preg_match('/^[a-z][a-z0-9-]*\.php$/', $fileName)) {
            $_SESSION['flash'][] = ['type' => 'error', 'message' => 'Invalid file name'];
            Flight::redirect('/agent-setup?tab=hooks');
            return;
        }

        $filePath = $this->hooksDir . '/' . $fileName;
        if (file_exists($filePath)) {
            $_SESSION['flash'][] = ['type' => 'error', 'message' => 'File already exists'];
            Flight::redirect('/agent-setup?tab=hooks');
            return;
        }

        $validation = PhpValidator::validateAll($code, 'hook');
        if (!empty($validation['errors'])) {
            $_SESSION['flash'][] = ['type' => 'error', 'message' => implode(', ', array_column($validation['errors'], 'message'))];
            Flight::redirect('/agent-setup?tab=hooks');
            return;
        }

        try {
            file_put_contents($filePath, $code);
            chmod($filePath, 0755);
            $_SESSION['flash'][] = ['type' => 'success', 'message' => 'Hook created: ' . $fileName];
        } catch (Exception $e) {
            $_SESSION['flash'][] = ['type' => 'error', 'message' => 'Error: ' . $e->getMessage()];
        }

        Flight::redirect('/agent-setup?tab=hooks');
    }

    /**
     * Update hook
     */
    public function updateHook($params = []) {
        if (!$this->requireLevel(LEVELS['ROOT'])) return;

        if (!$this->validatePost()) return;

        $code = $this->getParam('code', '');
        $name = $this->sanitize($this->getParam('name', ''));

        $filePath = $this->hooksDir . '/' . $name . '.php';
        if (!file_exists($filePath)) {
            $_SESSION['flash'][] = ['type' => 'error', 'message' => 'Hook not found'];
            Flight::redirect('/agent-setup?tab=hooks');
            return;
        }

        $validation = PhpValidator::validateAll($code, 'hook');
        if (!empty($validation['errors'])) {
            $_SESSION['flash'][] = ['type' => 'error', 'message' => implode(', ', array_column($validation['errors'], 'message'))];
            Flight::redirect('/agent-setup?tab=hooks&edit=' . urlencode($name));
            return;
        }

        try {
            copy($filePath, $filePath . '.bak.' . date('Ymd_His'));
            file_put_contents($filePath, $code);
            $_SESSION['flash'][] = ['type' => 'success', 'message' => 'Hook updated'];
        } catch (Exception $e) {
            $_SESSION['flash'][] = ['type' => 'error', 'message' => 'Error: ' . $e->getMessage()];
        }

        Flight::redirect('/agent-setup?tab=hooks');
    }

    /**
     * Delete hook
     */
    public function deleteHook($params = []) {
        if (!$this->requireLevel(LEVELS['ROOT'])) return;

        if (!$this->validatePost()) return;

        $name = $this->sanitize($this->getParam('name', ''));
        $filePath = $this->hooksDir . '/' . $name . '.php';

        if (!file_exists($filePath)) {
            $_SESSION['flash'][] = ['type' => 'error', 'message' => 'Hook not found'];
            Flight::redirect('/agent-setup?tab=hooks');
            return;
        }

        try {
            rename($filePath, $filePath . '.deleted.' . date('Ymd_His'));
            $_SESSION['flash'][] = ['type' => 'success', 'message' => 'Hook deleted'];
        } catch (Exception $e) {
            $_SESSION['flash'][] = ['type' => 'error', 'message' => 'Error: ' . $e->getMessage()];
        }

        Flight::redirect('/agent-setup?tab=hooks');
    }

    /**
     * Save hook configuration
     */
    public function saveHookConfig($params = []) {
        if (!$this->requireLevel(LEVELS['ROOT'])) return;

        if (!$this->validatePost()) return;

        $hooksJson = $this->getParam('hooks_json', '{}');
        $hooks = json_decode($hooksJson, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $_SESSION['flash'][] = ['type' => 'error', 'message' => 'Invalid JSON'];
            Flight::redirect('/agent-setup?tab=hooks');
            return;
        }

        $settings = $this->loadSettings();
        $settings['hooks'] = $hooks;

        try {
            copy($this->settingsFile, $this->settingsFile . '.bak.' . date('Ymd_His'));
            file_put_contents($this->settingsFile, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
            $_SESSION['flash'][] = ['type' => 'success', 'message' => 'Hook configuration saved'];
        } catch (Exception $e) {
            $_SESSION['flash'][] = ['type' => 'error', 'message' => 'Error: ' . $e->getMessage()];
        }

        Flight::redirect('/agent-setup?tab=hooks');
    }

    // ==================== HELPERS ====================

    private function validatePost(): bool {
        if (Flight::request()->method !== 'POST') {
            Flight::redirect('/agent-setup');
            return false;
        }
        if (!SimpleCsrf::validate()) {
            $_SESSION['flash'][] = ['type' => 'error', 'message' => 'CSRF validation failed'];
            Flight::redirect('/agent-setup');
            return false;
        }
        return true;
    }

    private function buildServerConfig(): array {
        $type = $this->getParam('type', 'stdio');
        $config = ['type' => $type];

        if ($type === 'stdio') {
            $config['command'] = $this->getParam('command', '');
            $args = json_decode($this->getParam('args', '[]'), true);
            if (!empty($args)) $config['args'] = $args;
            $env = json_decode($this->getParam('env', '{}'), true);
            if (!empty($env)) $config['env'] = $env;
        } else {
            $config['url'] = $this->getParam('url', '');
            $headers = json_decode($this->getParam('headers', '{}'), true);
            if (!empty($headers)) $config['headers'] = $headers;
        }

        return $config;
    }

    private function findToolFile(string $name): ?string {
        $parts = explode('_', $name);
        $className = implode('', array_map('ucfirst', $parts)) . 'Tool.php';

        $path = $this->toolsDir . '/' . $className;
        if (file_exists($path)) return $path;

        $path = $this->toolsDir . '/workbench/' . $className;
        if (file_exists($path)) return $path;

        return null;
    }

    private function loadSettings(): array {
        if (!file_exists($this->settingsFile)) return ['hooks' => []];
        $content = file_get_contents($this->settingsFile);
        $settings = json_decode($content, true);
        return is_array($settings) ? $settings : ['hooks' => []];
    }
}
