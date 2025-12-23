<?php
/**
 * API Keys Controller
 *
 * Allows members to manage their own API keys for MCP server access.
 * Keys can be scoped to specific servers and have expiration dates.
 */

namespace app;

use \Flight as Flight;
use \RedBeanPHP\R as R;
use \Exception as Exception;
use app\BaseControls\Control;

class Apikeys extends Control {

    public function __construct() {
        parent::__construct();

        // Require login
        if (!Flight::isLoggedIn()) {
            Flight::redirect('/auth/login?redirect=' . urlencode(Flight::request()->url));
            exit;
        }
    }

    /**
     * List user's API keys
     */
    public function index($params = []) {
        $this->viewData['title'] = 'API Keys';

        // Get only keys belonging to current user
        $keys = R::find('apikey', 'member_id = ? ORDER BY created_at DESC', [$this->member->id]);

        $this->viewData['keys'] = $keys;
        $this->viewData['mcpServers'] = $this->getActiveMcpServers();

        $this->render('apikeys/index', $this->viewData);
    }

    /**
     * Add new API key
     */
    public function add($params = []) {
        $request = Flight::request();

        if ($request->method === 'POST') {
            if (!Flight::csrf()->validateRequest()) {
                $this->viewData['error'] = 'Invalid CSRF token';
            } else {
                $result = $this->processKeyForm($request);
                if ($result['success']) {
                    // Show the token once - it won't be shown again
                    $_SESSION['new_api_key'] = $result['key']->token;
                    $_SESSION['new_api_key_name'] = $result['key']->name;
                    Flight::redirect('/apikeys');
                    return;
                }
                $this->viewData['error'] = $result['error'];
            }
        }

        $this->viewData['title'] = 'Create API Key';
        $this->viewData['key'] = null;
        $this->viewData['mcpServers'] = $this->getActiveMcpServers();
        $this->viewData['availableScopes'] = $this->getAvailableScopes();

        $this->render('apikeys/form', $this->viewData);
    }

    /**
     * Edit existing API key
     */
    public function edit($params = []) {
        $request = Flight::request();
        $keyId = $request->query->id ?? null;

        if (!$keyId) {
            Flight::redirect('/apikeys');
            return;
        }

        // Load key and verify ownership
        $key = R::load('apikey', $keyId);
        if (!$key->id || $key->memberId != $this->member->id) {
            $this->viewData['error'] = 'API key not found';
            Flight::redirect('/apikeys');
            return;
        }

        if ($request->method === 'POST') {
            if (!Flight::csrf()->validateRequest()) {
                $this->viewData['error'] = 'Invalid CSRF token';
            } else {
                $result = $this->processKeyForm($request, $key);
                if ($result['success']) {
                    $this->viewData['success'] = 'API key updated successfully';
                    $key = R::load('apikey', $keyId);
                } else {
                    $this->viewData['error'] = $result['error'];
                }
            }
        }

        $this->viewData['title'] = 'Edit API Key';
        $this->viewData['key'] = $key;
        $this->viewData['mcpServers'] = $this->getActiveMcpServers();
        $this->viewData['availableScopes'] = $this->getAvailableScopes();

        $this->render('apikeys/form', $this->viewData);
    }

    /**
     * Delete API key
     */
    public function delete($params = []) {
        $request = Flight::request();
        $keyId = $request->query->id ?? null;

        if (!$keyId) {
            Flight::redirect('/apikeys');
            return;
        }

        // Load key and verify ownership
        $key = R::load('apikey', $keyId);
        if (!$key->id || $key->memberId != $this->member->id) {
            Flight::redirect('/apikeys');
            return;
        }

        $this->logger->info('API key deleted', [
            'key_id' => $key->id,
            'key_name' => $key->name,
            'member_id' => $this->member->id
        ]);

        R::trash($key);
        Flight::redirect('/apikeys');
    }

    /**
     * Regenerate API key token
     */
    public function regenerate($params = []) {
        $request = Flight::request();

        if ($request->method !== 'POST') {
            Flight::redirect('/apikeys');
            return;
        }

        $keyId = $request->data->id ?? null;

        if (!$keyId) {
            Flight::jsonError('Key ID required', 400);
            return;
        }

        // Load key and verify ownership
        $key = R::load('apikey', $keyId);
        if (!$key->id || $key->memberId != $this->member->id) {
            Flight::jsonError('API key not found', 404);
            return;
        }

        // Generate new token
        $newToken = $this->generateToken();
        $key->token = $newToken;
        $key->updatedAt = date('Y-m-d H:i:s');
        R::store($key);

        $this->logger->info('API key regenerated', [
            'key_id' => $key->id,
            'key_name' => $key->name,
            'member_id' => $this->member->id
        ]);

        // Return the new token (show once)
        Flight::json([
            'success' => true,
            'token' => $newToken,
            'message' => 'Token regenerated. Copy it now - it won\'t be shown again!'
        ]);
    }

    /**
     * Process key form (create or update)
     */
    private function processKeyForm($request, $key = null): array {
        $isNew = ($key === null);
        if ($isNew) {
            $key = R::dispense('apikey');
            $key->memberId = $this->member->id;
            $key->token = $this->generateToken();
            $key->usageCount = 0;
        }

        // Validate name
        $name = trim($request->data->name ?? '');
        if (empty($name)) {
            return ['success' => false, 'error' => 'Name is required'];
        }

        if (strlen($name) < 2 || strlen($name) > 100) {
            return ['success' => false, 'error' => 'Name must be between 2 and 100 characters'];
        }

        // Check for duplicate name for this user
        if ($isNew) {
            $existing = R::findOne('apikey', 'member_id = ? AND name = ?', [$this->member->id, $name]);
        } else {
            $existing = R::findOne('apikey', 'member_id = ? AND name = ? AND id != ?', [$this->member->id, $name, $key->id]);
        }

        if ($existing) {
            return ['success' => false, 'error' => 'You already have an API key with this name'];
        }

        // Process scopes
        $scopes = $request->data->scopes ?? [];
        if (!is_array($scopes)) {
            $scopes = [$scopes];
        }
        $scopes = array_filter($scopes);

        // Process allowed servers
        $allowedServers = $request->data->allowedServers ?? [];
        if (!is_array($allowedServers)) {
            $allowedServers = [$allowedServers];
        }
        $allowedServers = array_filter($allowedServers);

        // Process expiration
        $expiresAt = null;
        $expiresIn = $request->data->expiresIn ?? '';
        if (!empty($expiresIn) && $expiresIn !== 'never') {
            switch ($expiresIn) {
                case '7d':
                    $expiresAt = date('Y-m-d H:i:s', strtotime('+7 days'));
                    break;
                case '30d':
                    $expiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));
                    break;
                case '90d':
                    $expiresAt = date('Y-m-d H:i:s', strtotime('+90 days'));
                    break;
                case '1y':
                    $expiresAt = date('Y-m-d H:i:s', strtotime('+1 year'));
                    break;
                case 'custom':
                    $customDate = $request->data->expiresAtCustom ?? '';
                    if (!empty($customDate)) {
                        $expiresAt = date('Y-m-d 23:59:59', strtotime($customDate));
                    }
                    break;
            }
        }

        // Set fields
        $key->name = $name;
        $key->scopes = json_encode($scopes);
        $key->allowedServers = json_encode($allowedServers);
        $key->expiresAt = $expiresAt;
        $key->isActive = (int)($request->data->isActive ?? 1);

        if ($isNew) {
            $key->createdAt = date('Y-m-d H:i:s');
        } else {
            $key->updatedAt = date('Y-m-d H:i:s');
        }

        try {
            R::store($key);
            $this->logger->info($isNew ? 'API key created' : 'API key updated', [
                'key_id' => $key->id,
                'key_name' => $name,
                'member_id' => $this->member->id
            ]);
            return ['success' => true, 'key' => $key];
        } catch (Exception $e) {
            $this->logger->error('Failed to save API key', [
                'name' => $name,
                'error' => $e->getMessage()
            ]);
            return ['success' => false, 'error' => 'Error saving API key: ' . $e->getMessage()];
        }
    }

    /**
     * Generate a secure API token
     */
    private function generateToken(): string {
        return 'tk_' . bin2hex(random_bytes(32));
    }

    /**
     * Get active MCP servers for scope selection
     */
    private function getActiveMcpServers(): array {
        return R::find('mcpserver', 'status = ? ORDER BY name ASC', ['active']);
    }

    /**
     * Get available scopes
     */
    private function getAvailableScopes(): array {
        return [
            'mcp:*' => 'All MCP Servers (full access)',
            'mcp:read' => 'Read-only MCP access',
            'mcp:tools' => 'Execute MCP tools only',
        ];
    }

    /**
     * Static method to validate an API key token
     * Returns the key bean if valid, null otherwise
     */
    public static function validateToken(string $token, ?string $serverSlug = null): ?object {
        if (empty($token)) {
            return null;
        }

        $key = R::findOne('apikey', 'token = ? AND is_active = 1', [$token]);

        if (!$key) {
            return null;
        }

        // Check expiration
        if ($key->expiresAt && strtotime($key->expiresAt) < time()) {
            return null;
        }

        // Check server restrictions if a server slug is provided
        if ($serverSlug) {
            $allowedServers = json_decode($key->allowedServers, true) ?: [];
            $scopes = json_decode($key->scopes, true) ?: [];

            // If there are allowed servers specified, check if this server is in the list
            if (!empty($allowedServers) && !in_array($serverSlug, $allowedServers)) {
                // Check if they have wildcard scope
                if (!in_array('mcp:*', $scopes)) {
                    return null;
                }
            }
        }

        // Update last used
        $key->lastUsedAt = date('Y-m-d H:i:s');
        $key->lastUsedIp = $_SERVER['REMOTE_ADDR'] ?? null;
        $key->usageCount = ($key->usageCount ?? 0) + 1;
        R::store($key);

        return $key;
    }

    /**
     * Get member associated with an API key
     */
    public static function getMemberByToken(string $token): ?object {
        $key = self::validateToken($token);
        if (!$key) {
            return null;
        }

        return R::load('member', $key->memberId);
    }
}
