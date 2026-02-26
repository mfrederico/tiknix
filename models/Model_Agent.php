<?php
/**
 * Agent FUSE Model
 *
 * Enables RedBeanPHP associations for the agent bean:
 * - member: The linked bot member account (via member_id)
 * - createdBy: The member who created this agent (via created_by)
 *
 * JSON columns (stored as TEXT, encode/decode in application):
 * - providerConfig: Provider-specific configuration
 * - capabilities: Array of capability strings
 * - mcpServers: MCP server configuration
 * - hooks: Hook definitions
 *
 * Providers: claude_cli, ollama, openai, custom
 */

use app\Bean;
use RedBeanPHP\R;

class Model_Agent extends \RedBeanPHP\SimpleModel {

    /** @var array Valid provider types */
    private const VALID_PROVIDERS = ['claude_cli', 'ollama', 'openai', 'custom'];

    /**
     * Called before storing (insert or update)
     * Validates required fields, generates slug, sets timestamps
     */
    public function update(): void {
        $bean = $this->bean;

        // Validate required fields
        if (empty(trim($bean->name ?? ''))) {
            throw new \InvalidArgumentException('Agent name is required');
        }

        if (empty($bean->provider)) {
            $bean->provider = 'claude_cli';
        }

        if (!in_array($bean->provider, self::VALID_PROVIDERS, true)) {
            throw new \InvalidArgumentException(
                'Invalid provider: ' . $bean->provider . '. Valid: ' . implode(', ', self::VALID_PROVIDERS)
            );
        }

        // Generate slug from name if not set
        if (empty($bean->slug)) {
            $bean->slug = $this->generateSlug($bean->name);
        }

        // Validate JSON fields
        $this->validateJsonField('providerConfig', '{}');
        $this->validateJsonField('capabilities', '[]');
        $this->validateJsonField('mcpServers', '{}');
        $this->validateJsonField('hooks', '{}');

        // Set timestamps
        $now = date('Y-m-d H:i:s');
        if (!$bean->id) {
            $bean->createdAt = $now;
        }
        $bean->updatedAt = $now;
    }

    /**
     * After storing a new agent, create its linked member account if none exists
     */
    public function after_update(): void {
        $bean = $this->bean;

        // Only auto-create member on first save (no linked member yet)
        if (empty($bean->memberId)) {
            $this->createLinkedMember();
        }
    }

    /**
     * Convert agent bean to array for API responses
     *
     * @return array
     */
    public function toArray(): array {
        $bean = $this->bean;

        return [
            'id'            => (int) $bean->id,
            'name'          => $bean->name,
            'slug'          => $bean->slug,
            'description'   => $bean->description,
            'provider'      => $bean->provider,
            'providerConfig'=> json_decode($bean->providerConfig ?: '{}', true),
            'systemPrompt'  => $bean->systemPrompt,
            'capabilities'  => json_decode($bean->capabilities ?: '[]', true),
            'mcpServers'    => json_decode($bean->mcpServers ?: '{}', true),
            'hooks'         => json_decode($bean->hooks ?: '{}', true),
            'isActive'      => (bool) $bean->isActive,
            'memberId'      => $bean->memberId ? (int) $bean->memberId : null,
            'createdBy'     => $bean->createdBy ? (int) $bean->createdBy : null,
            'exposeAsMcp'   => (bool) $bean->exposeAsMcp,
            'mcpToolName'   => $bean->mcpToolName,
            'createdAt'     => $bean->createdAt,
            'updatedAt'     => $bean->updatedAt,
        ];
    }

    /**
     * Generate a URL-safe slug from the agent name.
     * Appends a numeric suffix if the slug already exists.
     *
     * @param string $name Agent name
     * @return string Unique slug
     */
    private function generateSlug(string $name): string {
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');

        if (empty($slug)) {
            $slug = 'agent';
        }

        // Ensure uniqueness
        $baseSlug = $slug;
        $counter = 1;
        while (true) {
            $existing = Bean::findOne('agent', 'slug = ? AND id != ?', [$slug, $this->bean->id ?? 0]);
            if (!$existing) {
                break;
            }
            $slug = $baseSlug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }

    /**
     * Create a linked member account for this agent (bot account).
     * The member gets level 100 (MEMBER) and status 'bot'.
     */
    private function createLinkedMember(): void {
        $bean = $this->bean;

        $member = Bean::dispense('member');
        $member->username = 'agent-' . $bean->slug;
        $member->email = $bean->slug . '@agent.local';
        $member->level = 100;
        $member->status = 'bot';
        $member->firstName = $bean->name;
        $member->agentId = $bean->id;
        $member->createdAt = date('Y-m-d H:i:s');
        Bean::store($member);

        // Link the member back to the agent
        $bean->memberId = $member->id;
        Bean::store($bean);
    }

    /**
     * Validate that a JSON field contains valid JSON, set default if empty
     *
     * @param string $field Bean property name (camelCase)
     * @param string $default Default JSON string
     */
    private function validateJsonField(string $field, string $default): void {
        $value = $this->bean->$field;

        if (empty($value)) {
            $this->bean->$field = $default;
            return;
        }

        // If it's already an array/object, encode it
        if (is_array($value) || is_object($value)) {
            $this->bean->$field = json_encode($value);
            return;
        }

        // Validate JSON string
        json_decode($value);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \InvalidArgumentException(
                "Invalid JSON in {$field}: " . json_last_error_msg()
            );
        }
    }

    /**
     * Get valid provider list (for forms/validation)
     *
     * @return array
     */
    public static function getValidProviders(): array {
        return self::VALID_PROVIDERS;
    }
}
