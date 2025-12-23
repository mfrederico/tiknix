<?php
/**
 * MCP Server Registry Controller
 *
 * Admin CRUD for managing registered MCP servers.
 * Provides a registry of MCP servers that can be queried via the MCP tool.
 */

namespace app;

use \Flight as Flight;
use \app\Bean;
use \Exception as Exception;
use app\BaseControls\Control;

class Mcpregistry extends Control {

    const ADMIN_LEVEL = 50;

    public function __construct() {
        parent::__construct();

        // Allow public access to API endpoint
        $url = Flight::request()->url;
        if (strpos($url, '/mcpregistry/api') !== false) {
            return; // Public endpoint, no auth required
        }

        // Check if user is logged in
        if (!Flight::isLoggedIn()) {
            Flight::redirect('/auth/login?redirect=' . urlencode($url));
            exit;
        }

        // Check if user has admin level
        if ($this->member->level > self::ADMIN_LEVEL) {
            $this->logger->warning('Unauthorized MCP Registry access attempt', [
                'member_id' => $this->member->id,
                'member_level' => $this->member->level,
                'ip' => Flight::request()->ip
            ]);
            Flight::redirect('/');
            exit;
        }
    }

    /**
     * List all MCP servers
     */
    public function index($params = []) {
        $this->viewData['title'] = 'MCP Server Registry';

        $request = Flight::request();

        // Handle delete action
        if ($request->query->delete && is_numeric($request->query->delete)) {
            $server = Bean::load('mcpserver', $request->query->delete);
            if ($server->id) {
                $this->logger->info('MCP server deleted', [
                    'id' => $server->id,
                    'name' => $server->name,
                    'deleted_by' => $this->member->id
                ]);
                Bean::trash($server);
            }
            Flight::redirect('/mcpregistry');
            return;
        }

        // Filter by status if provided
        $status = $request->query->status ?? '';
        if ($status && in_array($status, ['active', 'inactive', 'deprecated'])) {
            $servers = Bean::find('mcpserver', 'status = ? ORDER BY sort_order ASC, name ASC', [$status]);
        } else {
            $servers = Bean::findAll('mcpserver', 'ORDER BY sort_order ASC, name ASC');
        }

        $this->viewData['servers'] = $servers;
        $this->viewData['statusFilter'] = $status;

        $this->render('mcp_registry/index', $this->viewData);
    }

    /**
     * Add new MCP server
     */
    public function add($params = []) {
        $request = Flight::request();

        if ($request->method === 'POST') {
            if (!Flight::csrf()->validateRequest()) {
                $this->viewData['error'] = 'Invalid CSRF token';
            } else {
                $result = $this->processServerForm($request);
                if ($result['success']) {
                    Flight::redirect('/mcpregistry');
                    return;
                }
                $this->viewData['error'] = $result['error'];
            }
        }

        $this->viewData['title'] = 'Add MCP Server';
        $this->viewData['server'] = null;
        $this->render('mcp_registry/form', $this->viewData);
    }

    /**
     * Edit existing MCP server
     */
    public function edit($params = []) {
        $request = Flight::request();
        $serverId = $request->query->id ?? null;

        if (!$serverId) {
            Flight::redirect('/mcpregistry');
            return;
        }

        $server = Bean::load('mcpserver', $serverId);
        if (!$server->id) {
            Flight::redirect('/mcpregistry');
            return;
        }

        if ($request->method === 'POST') {
            if (!Flight::csrf()->validateRequest()) {
                $this->viewData['error'] = 'Invalid CSRF token';
            } else {
                $result = $this->processServerForm($request, $server);
                if ($result['success']) {
                    $this->viewData['success'] = 'MCP server updated successfully';
                    // Reload server to get updated data
                    $server = Bean::load('mcpserver', $serverId);
                } else {
                    $this->viewData['error'] = $result['error'];
                }
            }
        }

        $this->viewData['title'] = 'Edit MCP Server';
        $this->viewData['server'] = $server;
        $this->render('mcp_registry/form', $this->viewData);
    }

    /**
     * Process server form (create or update)
     */
    private function processServerForm($request, $server = null): array {
        $isNew = ($server === null);
        if ($isNew) {
            $server = Bean::dispense('mcpserver');
        }

        // Validate required fields
        $name = trim($request->data->name ?? '');
        $slug = trim($request->data->slug ?? '');
        $endpointUrl = trim($request->data->endpointUrl ?? '');

        if (empty($name)) {
            return ['success' => false, 'error' => 'Name is required'];
        }

        if (strlen($name) < 2 || strlen($name) > 100) {
            return ['success' => false, 'error' => 'Name must be between 2 and 100 characters'];
        }

        if (empty($endpointUrl)) {
            return ['success' => false, 'error' => 'Endpoint URL is required'];
        }

        if (!filter_var($endpointUrl, FILTER_VALIDATE_URL)) {
            return ['success' => false, 'error' => 'Invalid endpoint URL'];
        }

        // Generate slug if not provided
        if (empty($slug)) {
            $slug = $this->generateSlug($name);
        }

        // Validate slug format
        if (!preg_match('/^[a-z0-9-]+$/', $slug)) {
            return ['success' => false, 'error' => 'Slug must contain only lowercase letters, numbers, and hyphens'];
        }

        // Check for duplicate slug
        if ($isNew) {
            $existing = Bean::findOne('mcpserver', 'slug = ?', [$slug]);
        } else {
            $existing = Bean::findOne('mcpserver', 'slug = ? AND id != ?', [$slug, $server->id]);
        }

        if ($existing) {
            return ['success' => false, 'error' => 'Slug already exists'];
        }

        // Validate optional URLs
        $authorUrl = trim($request->data->authorUrl ?? '');
        if (!empty($authorUrl) && !filter_var($authorUrl, FILTER_VALIDATE_URL)) {
            return ['success' => false, 'error' => 'Invalid author URL'];
        }

        $documentationUrl = trim($request->data->documentationUrl ?? '');
        if (!empty($documentationUrl) && !filter_var($documentationUrl, FILTER_VALIDATE_URL)) {
            return ['success' => false, 'error' => 'Invalid documentation URL'];
        }

        $iconUrl = trim($request->data->iconUrl ?? '');
        if (!empty($iconUrl) && !filter_var($iconUrl, FILTER_VALIDATE_URL)) {
            return ['success' => false, 'error' => 'Invalid icon URL'];
        }

        // Validate JSON fields
        $tools = trim($request->data->tools ?? '[]');
        if (!empty($tools) && json_decode($tools) === null && $tools !== '[]') {
            return ['success' => false, 'error' => 'Invalid tools JSON'];
        }

        $tags = trim($request->data->tags ?? '[]');
        if (!empty($tags) && json_decode($tags) === null && $tags !== '[]') {
            return ['success' => false, 'error' => 'Invalid tags JSON'];
        }

        // Set fields
        $server->name = $name;
        $server->slug = $slug;
        $server->description = trim($request->data->description ?? '');
        $server->endpointUrl = $endpointUrl;
        $server->version = trim($request->data->version ?? '1.0.0');
        $server->status = $request->data->status ?? 'active';
        $server->author = trim($request->data->author ?? '');
        $server->authorUrl = $authorUrl;
        $server->tools = $tools;
        $server->authType = $request->data->authType ?? 'none';
        $server->documentationUrl = $documentationUrl;
        $server->iconUrl = $iconUrl;
        $server->tags = $tags;
        $server->featured = (int)($request->data->featured ?? 0);
        $server->sortOrder = (int)($request->data->sortOrder ?? 0);

        if ($isNew) {
            $server->createdAt = date('Y-m-d H:i:s');
            $server->createdBy = $this->member->id;
        } else {
            $server->updatedAt = date('Y-m-d H:i:s');
        }

        try {
            Bean::store($server);
            $this->logger->info($isNew ? 'MCP server created' : 'MCP server updated', [
                'id' => $server->id,
                'name' => $name,
                'by' => $this->member->id
            ]);
            return ['success' => true, 'server' => $server];
        } catch (Exception $e) {
            $this->logger->error('Failed to save MCP server', [
                'name' => $name,
                'error' => $e->getMessage()
            ]);
            return ['success' => false, 'error' => 'Error saving server: ' . $e->getMessage()];
        }
    }

    /**
     * Generate slug from name
     */
    private function generateSlug(string $name): string {
        $slug = strtolower($name);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');
        return $slug;
    }

    /**
     * Fetch tools from remote MCP server (AJAX)
     */
    public function fetchTools($params = []) {
        header('Content-Type: application/json');

        $endpointUrl = $this->getParam('url');

        if (empty($endpointUrl) || !filter_var($endpointUrl, FILTER_VALIDATE_URL)) {
            echo json_encode(['success' => false, 'error' => 'Invalid URL']);
            return;
        }

        try {
            // Send tools/list request to remote MCP server
            $request = json_encode([
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/list',
                'params' => []
            ]);

            $ch = curl_init($endpointUrl);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $request,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_TIMEOUT => 10
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($error) {
                echo json_encode(['success' => false, 'error' => "Connection error: {$error}"]);
                return;
            }

            if ($httpCode !== 200) {
                echo json_encode(['success' => false, 'error' => "HTTP {$httpCode}"]);
                return;
            }

            $data = json_decode($response, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                echo json_encode(['success' => false, 'error' => 'Invalid JSON response']);
                return;
            }

            $tools = $data['result']['tools'] ?? [];

            echo json_encode(['success' => true, 'tools' => $tools]);

        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Public JSON API - returns active MCP servers
     */
    public function api($params = []) {
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');

        // Only return active servers
        $servers = Bean::find('mcpserver', 'status = ? ORDER BY featured DESC, sort_order ASC, name ASC', ['active']);

        $result = [];
        foreach ($servers as $server) {
            $result[] = [
                'slug' => $server->slug,
                'name' => $server->name,
                'description' => $server->description,
                'endpoint_url' => $server->endpointUrl,
                'version' => $server->version,
                'author' => $server->author,
                'author_url' => $server->authorUrl,
                'tools' => json_decode($server->tools, true) ?: [],
                'auth_type' => $server->authType,
                'documentation_url' => $server->documentationUrl,
                'icon_url' => $server->iconUrl,
                'tags' => json_decode($server->tags, true) ?: [],
                'featured' => (bool)$server->featured
            ];
        }

        echo json_encode([
            'success' => true,
            'count' => count($result),
            'servers' => $result
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
