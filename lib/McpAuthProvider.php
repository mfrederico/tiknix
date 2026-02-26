<?php
/**
 * McpAuthProvider - Bridges tiknix's API key auth into fastmcphp
 *
 * Implements Fastmcphp\Server\Auth\AuthProviderInterface to validate
 * requests using tiknix's existing apikey table and member records.
 *
 * Auth priority:
 *  1. Bearer token (Authorization: Bearer <token>)
 *  2. X-MCP-Token header
 *  3. Basic Auth (username:password)
 *  4. Query param (?key=<token>)
 *
 * For Bearer/X-MCP-Token/query, checks apikey table first, then falls
 * back to legacy member.api_token field.
 */

namespace app;

use Fastmcphp\Server\Auth\AuthProviderInterface;
use Fastmcphp\Server\Auth\AuthRequest;
use Fastmcphp\Server\Auth\AuthResult;
use Fastmcphp\Server\Auth\AuthenticatedUser;

class McpAuthProvider implements AuthProviderInterface
{
    public function authenticate(AuthRequest $request): AuthResult
    {
        // Try Bearer token
        $token = $request->getBearerToken();
        if ($token) {
            return $this->authenticateToken($token);
        }

        // Try X-MCP-Token header
        $mcpToken = $request->getHeader('x-mcp-token');
        if ($mcpToken) {
            return $this->authenticateToken($mcpToken);
        }

        // Try Basic Auth
        $auth = $request->getAuthorization();
        if ($auth && stripos($auth, 'basic ') === 0) {
            return $this->authenticateBasic($auth);
        }

        // Try query param
        $queryToken = $request->getApiKeyFromQuery('key');
        if ($queryToken) {
            return $this->authenticateToken($queryToken);
        }

        return AuthResult::unauthenticated();
    }

    /**
     * Authenticate via token (apikey table first, then legacy member.api_token)
     */
    private function authenticateToken(string $token): AuthResult
    {
        $token = trim($token);
        if (empty($token)) {
            return AuthResult::unauthenticated();
        }

        // Try apikey table first
        $key = Bean::findOne('apikey', 'token = ? AND is_active = 1', [$token]);

        if ($key) {
            // Check expiration
            if ($key->expiresAt && strtotime($key->expiresAt) < time()) {
                return AuthResult::failed('API key expired');
            }

            // Load associated member
            $member = Bean::load('member', $key->memberId);
            if (!$member->id) {
                return AuthResult::failed('API key member not found');
            }

            // Update usage stats
            $key->lastUsedAt = date('Y-m-d H:i:s');
            $key->lastUsedIp = $_SERVER['REMOTE_ADDR'] ?? null;
            $key->usageCount = ($key->usageCount ?? 0) + 1;
            Bean::store($key);

            $scopes = json_decode($key->scopes, true) ?: [];

            // Translate tiknix scopes to fastmcphp scope format
            $scopes = $this->translateScopes($scopes);

            return AuthResult::success(new AuthenticatedUser(
                id: (string) $member->id,
                name: $member->username ?? $member->firstName ?? null,
                email: $member->email ?? null,
                level: (int) ($member->level ?? 100),
                scopes: $scopes,
                extra: [
                    'member_id' => (int) $member->id,
                    'apikey_id' => (int) $key->id,
                    'apikey_name' => $key->name,
                    'allowed_servers' => json_decode($key->allowedServers, true) ?: [],
                ],
            ));
        }

        // Fall back to legacy member.api_token
        $member = Bean::findOne('member', 'api_token = ? AND api_token IS NOT NULL', [$token]);

        if (!$member) {
            return AuthResult::failed('Invalid token');
        }

        return AuthResult::success(new AuthenticatedUser(
            id: (string) $member->id,
            name: $member->username ?? $member->firstName ?? null,
            email: $member->email ?? null,
            level: (int) ($member->level ?? 100),
            scopes: ['tools:*', 'resources:*', 'prompts:*'], // Legacy auth gets full access
            extra: [
                'member_id' => (int) $member->id,
                'apikey_id' => 0,
                'legacy_auth' => true,
            ],
        ));
    }

    /**
     * Authenticate via Basic Auth (Authorization: Basic base64(username:password))
     */
    private function authenticateBasic(string $authHeader): AuthResult
    {
        if (!preg_match('/^Basic\s+(.+)$/i', $authHeader, $matches)) {
            return AuthResult::unauthenticated();
        }

        $decoded = base64_decode($matches[1]);
        if (!$decoded || strpos($decoded, ':') === false) {
            return AuthResult::failed('Invalid Basic Auth credentials');
        }

        [$username, $password] = explode(':', $decoded, 2);

        $member = Bean::findOne('member', 'username = ? OR email = ?', [$username, $username]);

        if (!$member) {
            return AuthResult::failed('Invalid credentials');
        }

        if (!password_verify($password, $member->password)) {
            return AuthResult::failed('Invalid credentials');
        }

        return AuthResult::success(new AuthenticatedUser(
            id: (string) $member->id,
            name: $member->username ?? $member->firstName ?? null,
            email: $member->email ?? null,
            level: (int) ($member->level ?? 100),
            scopes: ['mcp:*'], // Basic auth gets full access
            extra: [
                'member_id' => (int) $member->id,
                'apikey_id' => 0,
                'basic_auth' => true,
            ],
        ));
    }

    /**
     * Translate tiknix scope format to fastmcphp scope format.
     *
     * Tiknix uses: mcp:tools, mcp:*
     * fastmcphp uses: tools:*, resources:*, prompts:*
     *
     * @param array<string> $scopes
     * @return array<string>
     */
    private function translateScopes(array $scopes): array
    {
        $translated = [];

        foreach ($scopes as $scope) {
            switch ($scope) {
                case 'mcp:*':
                    $translated[] = 'tools:*';
                    $translated[] = 'resources:*';
                    $translated[] = 'prompts:*';
                    break;
                case 'mcp:tools':
                    $translated[] = 'tools:*';
                    break;
                case 'mcp:resources':
                    $translated[] = 'resources:*';
                    break;
                case 'mcp:prompts':
                    $translated[] = 'prompts:*';
                    break;
                default:
                    // Pass through scopes already in fastmcphp format
                    $translated[] = $scope;
                    break;
            }
        }

        return array_unique($translated);
    }
}
