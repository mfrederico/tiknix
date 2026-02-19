<?php
/**
 * MCP Configuration Controller
 *
 * Admin interface for managing MCP servers in the project's .mcp.json file.
 * Allows adding, editing, and removing MCP server configurations that will
 * be available for workspace tasks.
 */

namespace app;

use \Flight as Flight;
use \Exception as Exception;
use app\BaseControls\Control;

class Mcpconfig extends Control {

    /**
     * List all MCP servers from .mcp.json
     */
    public function index($params = []) {
        if (!$this->requireLevel(LEVELS['ADMIN'])) return;

        $servers = Mcp::getAvailableServers();

        // Separate system vs user servers
        $systemServers = [];
        $userServers = [];
        foreach ($servers as $slug => $server) {
            if ($server['source'] === 'system') {
                $systemServers[$slug] = $server;
            } else {
                $userServers[$slug] = $server;
            }
        }

        $this->viewData['title'] = 'MCP Servers';
        $this->viewData['systemServers'] = $systemServers;
        $this->viewData['userServers'] = $userServers;
        $this->viewData['csrf'] = SimpleCsrf::getTokenArray();

        $this->render('mcpconfig/index', $this->viewData);
    }

    /**
     * Show create form for new MCP server
     */
    public function create($params = []) {
        if (!$this->requireLevel(LEVELS['ADMIN'])) return;

        $this->viewData['title'] = 'Add MCP Server';
        $this->viewData['server'] = null;
        $this->viewData['slug'] = '';
        $this->viewData['csrf'] = SimpleCsrf::getTokenArray();

        $this->render('mcpconfig/form', $this->viewData);
    }

    /**
     * Show edit form for existing MCP server
     */
    public function edit($params = []) {
        if (!$this->requireLevel(LEVELS['ADMIN'])) return;

        $slug = $this->getParam('slug', '');
        if (empty($slug)) {
            Flight::redirect('/mcpconfig');
            return;
        }

        $config = Mcp::loadProjectMcpConfig();
        $servers = $config['mcpServers'] ?? [];

        if (!isset($servers[$slug])) {
            $_SESSION['flash'][] = ['type' => 'error', 'message' => 'Server not found'];
            Flight::redirect('/mcpconfig');
            return;
        }

        $this->viewData['title'] = 'Edit MCP Server';
        $this->viewData['server'] = $servers[$slug];
        $this->viewData['slug'] = $slug;
        $this->viewData['csrf'] = SimpleCsrf::getTokenArray();

        $this->render('mcpconfig/form', $this->viewData);
    }

    /**
     * Save new MCP server
     */
    public function store($params = []) {
        if (!$this->requireLevel(LEVELS['ADMIN'])) return;

        $request = Flight::request();
        if ($request->method !== 'POST') {
            Flight::redirect('/mcpconfig');
            return;
        }

        if (!SimpleCsrf::validate()) {
            $_SESSION['flash'][] = ['type' => 'error', 'message' => 'CSRF validation failed'];
            Flight::redirect('/mcpconfig');
            return;
        }

        $slug = $this->sanitize($this->getParam('slug', ''));
        $serverConfig = $this->buildServerConfig();

        if (empty($slug)) {
            $_SESSION['flash'][] = ['type' => 'error', 'message' => 'Server name is required'];
            Flight::redirect('/mcpconfig/create');
            return;
        }

        // Validate slug format (lowercase, no spaces, alphanumeric + dash)
        if (!preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug)) {
            $_SESSION['flash'][] = ['type' => 'error', 'message' => 'Server name must be lowercase alphanumeric with dashes'];
            Flight::redirect('/mcpconfig/create');
            return;
        }

        // Reserved names
        if (in_array($slug, ['tiknix', 'playwright'])) {
            $_SESSION['flash'][] = ['type' => 'error', 'message' => 'Cannot use reserved name: ' . $slug];
            Flight::redirect('/mcpconfig/create');
            return;
        }

        // Check if exists
        $config = Mcp::loadProjectMcpConfig();
        if (isset($config['mcpServers'][$slug])) {
            $_SESSION['flash'][] = ['type' => 'error', 'message' => 'Server already exists: ' . $slug];
            Flight::redirect('/mcpconfig/create');
            return;
        }

        if (!$this->validateServerConfig($serverConfig)) {
            Flight::redirect('/mcpconfig/create');
            return;
        }

        try {
            if (Mcp::addServer($slug, $serverConfig)) {
                $_SESSION['flash'][] = ['type' => 'success', 'message' => 'MCP server added: ' . $slug];
            } else {
                $_SESSION['flash'][] = ['type' => 'error', 'message' => 'Failed to save configuration'];
            }
        } catch (Exception $e) {
            $_SESSION['flash'][] = ['type' => 'error', 'message' => 'Error: ' . $e->getMessage()];
        }

        Flight::redirect('/mcpconfig');
    }

    /**
     * Update existing MCP server
     */
    public function update($params = []) {
        if (!$this->requireLevel(LEVELS['ADMIN'])) return;

        $request = Flight::request();
        if ($request->method !== 'POST') {
            Flight::redirect('/mcpconfig');
            return;
        }

        if (!SimpleCsrf::validate()) {
            $_SESSION['flash'][] = ['type' => 'error', 'message' => 'CSRF validation failed'];
            Flight::redirect('/mcpconfig');
            return;
        }

        $slug = $this->sanitize($this->getParam('slug', ''));
        $serverConfig = $this->buildServerConfig();

        if (empty($slug)) {
            $_SESSION['flash'][] = ['type' => 'error', 'message' => 'Server name is required'];
            Flight::redirect('/mcpconfig');
            return;
        }

        // Reserved names cannot be edited
        if (in_array($slug, ['tiknix', 'playwright'])) {
            $_SESSION['flash'][] = ['type' => 'error', 'message' => 'Cannot modify system server: ' . $slug];
            Flight::redirect('/mcpconfig');
            return;
        }

        // Check if exists
        $config = Mcp::loadProjectMcpConfig();
        if (!isset($config['mcpServers'][$slug])) {
            $_SESSION['flash'][] = ['type' => 'error', 'message' => 'Server not found: ' . $slug];
            Flight::redirect('/mcpconfig');
            return;
        }

        if (!$this->validateServerConfig($serverConfig)) {
            Flight::redirect('/mcpconfig/edit?slug=' . urlencode($slug));
            return;
        }

        try {
            if (Mcp::updateServer($slug, $serverConfig)) {
                $_SESSION['flash'][] = ['type' => 'success', 'message' => 'MCP server updated: ' . $slug];
            } else {
                $_SESSION['flash'][] = ['type' => 'error', 'message' => 'Failed to save configuration'];
            }
        } catch (Exception $e) {
            $_SESSION['flash'][] = ['type' => 'error', 'message' => 'Error: ' . $e->getMessage()];
        }

        Flight::redirect('/mcpconfig');
    }

    /**
     * Delete MCP server
     */
    public function delete($params = []) {
        if (!$this->requireLevel(LEVELS['ADMIN'])) return;

        $request = Flight::request();
        if ($request->method !== 'POST') {
            Flight::redirect('/mcpconfig');
            return;
        }

        if (!SimpleCsrf::validate()) {
            $_SESSION['flash'][] = ['type' => 'error', 'message' => 'CSRF validation failed'];
            Flight::redirect('/mcpconfig');
            return;
        }

        $slug = $this->sanitize($this->getParam('slug', ''));

        if (empty($slug)) {
            $_SESSION['flash'][] = ['type' => 'error', 'message' => 'Server name is required'];
            Flight::redirect('/mcpconfig');
            return;
        }

        // Reserved names cannot be deleted
        if (in_array($slug, ['tiknix', 'playwright'])) {
            $_SESSION['flash'][] = ['type' => 'error', 'message' => 'Cannot delete system server: ' . $slug];
            Flight::redirect('/mcpconfig');
            return;
        }

        try {
            if (Mcp::removeServer($slug)) {
                $_SESSION['flash'][] = ['type' => 'success', 'message' => 'MCP server removed: ' . $slug];
            } else {
                $_SESSION['flash'][] = ['type' => 'error', 'message' => 'Failed to remove server'];
            }
        } catch (Exception $e) {
            $_SESSION['flash'][] = ['type' => 'error', 'message' => 'Error: ' . $e->getMessage()];
        }

        Flight::redirect('/mcpconfig');
    }

    /**
     * Build server config from form data
     */
    private function buildServerConfig(): array {
        $type = $this->getParam('type', 'stdio');
        $config = ['type' => $type];

        if ($type === 'stdio') {
            $command = $this->getParam('command', '');
            $argsJson = $this->getParam('args', '[]');
            $envJson = $this->getParam('env', '{}');

            $config['command'] = $command;

            // Parse args as JSON array
            $args = json_decode($argsJson, true);
            if (is_array($args) && !empty($args)) {
                $config['args'] = $args;
            }

            // Parse env as JSON object
            $env = json_decode($envJson, true);
            if (is_array($env) && !empty($env)) {
                $config['env'] = $env;
            }
        } else {
            // HTTP type
            $url = $this->getParam('url', '');
            $headersJson = $this->getParam('headers', '{}');

            $config['url'] = $url;

            // Parse headers as JSON object
            $headers = json_decode($headersJson, true);
            if (is_array($headers) && !empty($headers)) {
                $config['headers'] = $headers;
            }
        }

        return $config;
    }

    /**
     * Validate server configuration
     */
    private function validateServerConfig(array $config): bool {
        $type = $config['type'] ?? '';

        if ($type === 'stdio') {
            if (empty($config['command'])) {
                $_SESSION['flash'][] = ['type' => 'error', 'message' => 'Command is required for stdio servers'];
                return false;
            }
        } elseif ($type === 'http') {
            if (empty($config['url'])) {
                $_SESSION['flash'][] = ['type' => 'error', 'message' => 'URL is required for HTTP servers'];
                return false;
            }
            if (!filter_var($config['url'], FILTER_VALIDATE_URL)) {
                $_SESSION['flash'][] = ['type' => 'error', 'message' => 'Invalid URL format'];
                return false;
            }
        } else {
            $_SESSION['flash'][] = ['type' => 'error', 'message' => 'Invalid server type'];
            return false;
        }

        return true;
    }

    /**
     * Preview the generated .mcp.json configuration
     */
    public function preview($params = []) {
        if (!$this->requireLevel(LEVELS['ADMIN'])) return;

        $config = Mcp::loadProjectMcpConfig();

        Flight::json([
            'success' => true,
            'config' => $config
        ]);
    }
}
