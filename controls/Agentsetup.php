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
use \app\Feature;
use app\BaseControls\Control;
use app\mcptools\ToolLoader;

class Agentsetup extends Control {

    private string $toolsDir;
    private string $hooksDir;
    private string $settingsFile;

    /**
     * May this member open Agent Setup and manage MCP servers?
     *
     * The same `mcp` grant that governs API keys and the tool list, deliberately not a
     * flag of its own: this hub IS the MCP server/tool registry, so a separate switch
     * would create a half-granted state — able to mint a key, unable to see the servers
     * that key talks to.
     *
     * It replaces the ADMIN tier ONLY. Everything that writes executable PHP into
     * mcptools/ or scripts/hooks/ still requires ROOT, and must: scripts/hooks holds
     * security-sandbox.php, the PreToolUse control that confines agents. A grant is
     * permission to configure MCP, never a promotion to editing the thing that contains
     * the agents.
     */
    private function mayConfigure(): bool {
        return Feature::allows('mcp', (int) $this->member->id, (int) $this->member->level);
    }

    private function denyConfigure(): void {
        $this->logger->warning('Ungranted member attempted to reach Agent Setup', [
            'member_id' => $this->member->id, 'member_level' => $this->member->level, 'feature' => 'mcp',
        ]);
        http_response_code(403);   // see Mcptools: status() alone is not flushed
        Flight::renderView('error/403', ['title' => '403 - Forbidden']);
    }

    public function __construct() {
        parent::__construct();
        $this->toolsDir = dirname(__DIR__) . '/mcptools';
        $this->hooksDir = dirname(__DIR__) . '/scripts/hooks';
        $this->settingsFile = dirname(__DIR__) . '/.claude/settings.json';
        $this->requireBuilderTools('Agent Setup');
    }

    /**
     * Main tabbed interface
     */
    public function index($params = []) {
        if (!$this->mayConfigure()) { $this->denyConfigure(); return; }

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

    /** Flash a message and redirect back to an agent-setup tab (optionally its edit view). */
    private function flashTo(string $tab, string $type, string $message, ?string $edit = null): void {
        $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
        Flight::redirect('/agent-setup?tab=' . $tab . ($edit !== null ? '&edit=' . urlencode($edit) : ''));
    }

    /** Add or update an MCP server via Mcp:: and flash the outcome. $mode = 'add' | 'update'. */
    private function saveServer(string $mode, string $slug, array $config): void {
        try {
            $ok = $mode === 'add' ? Mcp::addServer($slug, $config) : Mcp::updateServer($slug, $config);
            $this->flashTo('servers', $ok ? 'success' : 'error',
                $ok ? 'Server ' . ($mode === 'add' ? 'added' : 'updated') . ': ' . $slug : 'Failed to save');
        } catch (Exception $e) {
            $this->flashTo('servers', 'error', 'Error: ' . $e->getMessage());
        }
    }

    /** Create a NEW managed PHP file (tool/hook): reject if it exists, validate, write. */
    private function createManagedFile(string $tab, string $label, string $filePath, string $code, string $kind, bool $chmodExec = false): void {
        if (file_exists($filePath)) { $this->flashTo($tab, 'error', 'File already exists'); return; }
        $errors = PhpValidator::validateAll($code, $kind)['errors'] ?? [];
        if (!empty($errors)) { $this->flashTo($tab, 'error', implode(', ', array_column($errors, 'message'))); return; }
        try {
            file_put_contents($filePath, $code);
            if ($chmodExec) chmod($filePath, 0755);
            $this->flashTo($tab, 'success', $label . ' created: ' . basename($filePath));
        } catch (Exception $e) {
            $this->flashTo($tab, 'error', 'Error: ' . $e->getMessage());
        }
    }

    /** Update an EXISTING managed PHP file (tool/hook): require it, validate, back up, write. */
    private function updateManagedFile(string $tab, string $label, ?string $filePath, string $editName, string $code, string $kind): void {
        if (!$filePath || !file_exists($filePath)) { $this->flashTo($tab, 'error', $label . ' not found'); return; }
        $errors = PhpValidator::validateAll($code, $kind)['errors'] ?? [];
        if (!empty($errors)) { $this->flashTo($tab, 'error', implode(', ', array_column($errors, 'message')), $editName); return; }
        try {
            copy($filePath, $filePath . '.bak.' . date('Ymd_His'));
            file_put_contents($filePath, $code);
            $this->flashTo($tab, 'success', $label . ' updated');
        } catch (Exception $e) {
            $this->flashTo($tab, 'error', 'Error: ' . $e->getMessage());
        }
    }

    /** Soft-delete a managed file (rename to .deleted.<ts>); the caller has already vetted it. */
    private function softDeleteFile(string $tab, string $label, string $filePath): void {
        try {
            rename($filePath, $filePath . '.deleted.' . date('Ymd_His'));
            $this->flashTo($tab, 'success', $label . ' deleted');
        } catch (Exception $e) {
            $this->flashTo($tab, 'error', 'Error: ' . $e->getMessage());
        }
    }

    /** Store new MCP server */
    public function storeServer($params = []) {
        if (!$this->mayConfigure()) { $this->denyConfigure(); return; }
        if (!$this->validatePost()) return;

        $slug = $this->sanitize($this->getParam('slug', ''));
        if (empty($slug) || !preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug)) { $this->flashTo('servers', 'error', 'Invalid server name'); return; }
        if (in_array($slug, ['tiknix', 'playwright'])) { $this->flashTo('servers', 'error', 'Cannot use reserved name'); return; }

        $this->saveServer('add', $slug, $this->buildServerConfig());
    }

    /** Update MCP server */
    public function updateServer($params = []) {
        if (!$this->mayConfigure()) { $this->denyConfigure(); return; }
        if (!$this->validatePost()) return;

        $slug = $this->sanitize($this->getParam('slug', ''));
        if (in_array($slug, ['tiknix'])) { $this->flashTo('servers', 'error', 'Cannot modify system server'); return; }

        $this->saveServer('update', $slug, $this->buildServerConfig());
    }

    /** Delete MCP server */
    public function deleteServer($params = []) {
        if (!$this->mayConfigure()) { $this->denyConfigure(); return; }
        if (!$this->validatePost()) return;

        $slug = $this->sanitize($this->getParam('slug', ''));
        if (in_array($slug, ['tiknix', 'playwright'])) { $this->flashTo('servers', 'error', 'Cannot delete system server'); return; }

        try {
            if (Mcp::removeServer($slug)) { $this->flashTo('servers', 'success', 'Server removed: ' . $slug); return; }
        } catch (Exception $e) {
            $this->flashTo('servers', 'error', 'Error: ' . $e->getMessage()); return;
        }
        Flight::redirect('/agent-setup?tab=servers');
    }

    // ==================== MCP TOOL ACTIONS ====================

    /** Store new tool */
    public function storeTool($params = []) {
        if (!$this->requireLevel(LEVELS['ROOT'])) return;
        if (!$this->validatePost()) return;

        $code     = $this->getParam('code', '');
        $fileName = $this->sanitize($this->getParam('file_name', ''));
        if (empty($code) || !preg_match('/^[A-Z][a-zA-Z0-9]*Tool\\.php$/', $fileName)) {
            $this->flashTo('tools', 'error', 'Invalid file name (must be PascalCaseTool.php)'); return;
        }

        $this->createManagedFile('tools', 'Tool', $this->toolsDir . '/' . $fileName, $code, 'tool');
    }

    /** Update tool */
    public function updateTool($params = []) {
        if (!$this->requireLevel(LEVELS['ROOT'])) return;
        if (!$this->validatePost()) return;

        $code = $this->getParam('code', '');
        $name = $this->sanitize($this->getParam('name', ''));
        $this->updateManagedFile('tools', 'Tool', $this->findToolFile($name), $name, $code, 'tool');
    }

    /** Delete tool */
    public function deleteTool($params = []) {
        if (!$this->requireLevel(LEVELS['ROOT'])) return;
        if (!$this->validatePost()) return;

        $name     = $this->sanitize($this->getParam('name', ''));
        $filePath = $this->findToolFile($name);
        if (!$filePath || in_array(basename($filePath), ['BaseTool.php', 'ToolLoader.php'])) {
            $this->flashTo('tools', 'error', 'Cannot delete'); return;
        }

        $this->softDeleteFile('tools', 'Tool', $filePath);
    }

    // ==================== HOOK ACTIONS ====================

    /** Store new hook */
    public function storeHook($params = []) {
        if (!$this->requireLevel(LEVELS['ROOT'])) return;
        if (!$this->validatePost()) return;

        $code     = $this->getParam('code', '');
        $fileName = $this->sanitize($this->getParam('file_name', ''));
        if (empty($code) || !preg_match('/^[a-z][a-z0-9-]*\\.php$/', $fileName)) {
            $this->flashTo('hooks', 'error', 'Invalid file name'); return;
        }

        $this->createManagedFile('hooks', 'Hook', $this->hooksDir . '/' . $fileName, $code, 'hook', true);
    }

    /** Update hook */
    public function updateHook($params = []) {
        if (!$this->requireLevel(LEVELS['ROOT'])) return;
        if (!$this->validatePost()) return;

        $code = $this->getParam('code', '');
        $name = $this->sanitize($this->getParam('name', ''));
        $this->updateManagedFile('hooks', 'Hook', $this->hooksDir . '/' . $name . '.php', $name, $code, 'hook');
    }

    /** Delete hook */
    public function deleteHook($params = []) {
        if (!$this->requireLevel(LEVELS['ROOT'])) return;
        if (!$this->validatePost()) return;

        $name     = $this->sanitize($this->getParam('name', ''));
        $filePath = $this->hooksDir . '/' . $name . '.php';
        if (!file_exists($filePath)) { $this->flashTo('hooks', 'error', 'Hook not found'); return; }

        $this->softDeleteFile('hooks', 'Hook', $filePath);
    }

    /**
     * Save hook configuration
     */
    public function saveHookConfig($params = []) {
        if (!$this->requireLevel(LEVELS['ROOT'])) return;

        if (!$this->validatePost()) return;

        $hooksJson = $this->getParam('hooks_json', '{}');
        $hooks = json_decode(($hooksJson) ?? '', true);

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
            $args = json_decode(($this->getParam('args', '[]')) ?? '', true);
            if (!empty($args)) $config['args'] = $args;
            $env = json_decode(($this->getParam('env', '{}')) ?? '', true);
            if (!empty($env)) $config['env'] = $env;
        } else {
            $config['url'] = $this->getParam('url', '');
            $headers = json_decode(($this->getParam('headers', '{}')) ?? '', true);
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
        $settings = json_decode(($content) ?? '', true);
        return is_array($settings) ? $settings : ['hooks' => []];
    }
}
