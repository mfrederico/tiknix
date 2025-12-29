<?php
/**
 * SwooleSessionManager - Native session management for OpenSwoole
 *
 * Uses cookie-based session IDs with in-memory storage (per worker)
 * For production, use Redis or database-backed storage for shared state
 */

namespace Tiknix\Swoole\Session;

use OpenSwoole\Http\Request;
use OpenSwoole\Http\Response;
use OpenSwoole\Table;

class SwooleSessionManager
{
    private const SESSION_NAME = 'TIKNIX_SESSION';
    private const SESSION_LIFETIME = 86400; // 24 hours

    private static ?Table $sessions = null;
    private static array $sessionData = [];

    private string $sessionId;
    private array $data = [];
    private bool $started = false;

    /**
     * Initialize the shared session table (call once on worker start)
     *
     * Note: Table key max length is 63 bytes by default. We use shorter session IDs.
     */
    public static function initTable(int $size = 1024): void
    {
        if (self::$sessions === null) {
            self::$sessions = new Table($size);
            self::$sessions->column('data', Table::TYPE_STRING, 65535);
            self::$sessions->column('expires', Table::TYPE_INT, 8);
            self::$sessions->column('member_id', Table::TYPE_INT, 8);
            self::$sessions->create();
        }
    }

    /**
     * Create session manager from Swoole request
     */
    public function __construct(Request $request)
    {
        // Get session ID from cookie
        $this->sessionId = $request->cookie[self::SESSION_NAME] ?? '';

        // Validate existing session
        if (!empty($this->sessionId) && $this->isValidSession()) {
            $this->load();
        } else {
            // Generate new session ID
            $this->sessionId = $this->generateSessionId();
            $this->data = [];
        }

        $this->started = true;
    }

    /**
     * Start session and set cookie on response
     */
    public function start(Response $response): void
    {
        $response->cookie(
            self::SESSION_NAME,
            $this->sessionId,
            time() + self::SESSION_LIFETIME,
            '/',
            '',
            false, // secure (set true for HTTPS in production)
            true   // httponly
        );
    }

    /**
     * Get session ID
     */
    public function getId(): string
    {
        return $this->sessionId;
    }

    /**
     * Get session value
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    /**
     * Set session value
     */
    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
        $this->save();
    }

    /**
     * Check if session has key
     */
    public function has(string $key): bool
    {
        return isset($this->data[$key]);
    }

    /**
     * Remove session value
     */
    public function remove(string $key): void
    {
        unset($this->data[$key]);
        $this->save();
    }

    /**
     * Get all session data
     */
    public function all(): array
    {
        return $this->data;
    }

    /**
     * Clear all session data
     */
    public function clear(): void
    {
        $this->data = [];
        $this->save();
    }

    /**
     * Destroy session completely
     */
    public function destroy(): void
    {
        if (self::$sessions !== null && self::$sessions->exists($this->sessionId)) {
            self::$sessions->del($this->sessionId);
        }
        unset(self::$sessionData[$this->sessionId]);
        $this->data = [];
    }

    /**
     * Regenerate session ID (for security after login)
     */
    public function regenerate(): string
    {
        $oldId = $this->sessionId;
        $oldData = $this->data;

        // Delete old session
        $this->destroy();

        // Create new session with same data
        $this->sessionId = $this->generateSessionId();
        $this->data = $oldData;
        $this->save();

        return $this->sessionId;
    }

    /**
     * Set the member data in session (convenience method)
     */
    public function setMember(array $memberData): void
    {
        $this->set('member', $memberData);

        // Update session table with member ID for quick lookups
        // Ensure member_id is always an integer (OpenSwoole Table requirement)
        if (self::$sessions !== null && isset($memberData['id'])) {
            $row = self::$sessions->get($this->sessionId);
            if ($row) {
                self::$sessions->set($this->sessionId, [
                    'data' => $row['data'],
                    'expires' => $row['expires'],
                    'member_id' => (int) $memberData['id'],
                ]);
            }
        }
    }

    /**
     * Get member from session
     */
    public function getMember(): ?array
    {
        return $this->get('member');
    }

    /**
     * Check if user is logged in
     */
    public function isLoggedIn(): bool
    {
        $member = $this->getMember();
        return !empty($member) && isset($member['id']);
    }

    /**
     * Add flash message
     */
    public function flash(string $type, string $message): void
    {
        $flash = $this->get('flash', []);
        $flash[] = ['type' => $type, 'message' => $message];
        $this->set('flash', $flash);
    }

    /**
     * Get and clear flash messages
     */
    public function getFlashMessages(): array
    {
        $messages = $this->get('flash', []);
        $this->remove('flash');
        return $messages;
    }

    /**
     * Check if session is valid
     */
    private function isValidSession(): bool
    {
        // Check memory cache first
        if (isset(self::$sessionData[$this->sessionId])) {
            $data = self::$sessionData[$this->sessionId];
            return $data['expires'] > time();
        }

        // Check table
        if (self::$sessions !== null && self::$sessions->exists($this->sessionId)) {
            $row = self::$sessions->get($this->sessionId);
            return $row['expires'] > time();
        }

        return false;
    }

    /**
     * Load session data
     */
    private function load(): void
    {
        // Try memory cache first
        if (isset(self::$sessionData[$this->sessionId])) {
            $this->data = self::$sessionData[$this->sessionId]['data'];
            return;
        }

        // Load from table
        if (self::$sessions !== null && self::$sessions->exists($this->sessionId)) {
            $row = self::$sessions->get($this->sessionId);
            $this->data = json_decode($row['data'], true) ?? [];

            // Cache in memory for this worker
            self::$sessionData[$this->sessionId] = [
                'data' => $this->data,
                'expires' => $row['expires'],
            ];
        }
    }

    /**
     * Save session data
     */
    private function save(): void
    {
        $expires = time() + self::SESSION_LIFETIME;
        // Ensure member_id is always an integer (OpenSwoole Table requirement)
        $memberId = (int) ($this->data['member']['id'] ?? 0);

        // Save to table
        if (self::$sessions !== null) {
            self::$sessions->set($this->sessionId, [
                'data' => json_encode($this->data),
                'expires' => $expires,
                'member_id' => $memberId,
            ]);
        }

        // Update memory cache
        self::$sessionData[$this->sessionId] = [
            'data' => $this->data,
            'expires' => $expires,
        ];
    }

    /**
     * Generate cryptographically secure session ID
     * Using 24 bytes (48 hex chars) to stay within Table key limits (63 bytes)
     */
    private function generateSessionId(): string
    {
        return bin2hex(random_bytes(24));
    }

    /**
     * Cleanup expired sessions (call periodically via timer)
     */
    public static function cleanupExpired(): int
    {
        $cleaned = 0;
        $now = time();

        // Clean memory cache
        foreach (self::$sessionData as $id => $data) {
            if ($data['expires'] < $now) {
                unset(self::$sessionData[$id]);
                $cleaned++;
            }
        }

        // Clean table
        if (self::$sessions !== null) {
            foreach (self::$sessions as $id => $row) {
                if ($row['expires'] < $now) {
                    self::$sessions->del($id);
                    $cleaned++;
                }
            }
        }

        return $cleaned;
    }
}
