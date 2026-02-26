<?php
/**
 * Member FUSE Model
 *
 * Enables RedBeanPHP associations for the member bean:
 * - ownApikeyList: API keys belonging to this member
 * - ownContactList: Contact submissions by this member
 * - ownSettingsList: Settings for this member
 * - ownTeammemberList: Team memberships
 *
 * Agent support:
 * - agentId: FK to agent table (NULL for human members)
 * - isAgent(): Check if this member is a bot account
 * - getAgent(): Load the linked agent bean
 */

use app\Bean;

class Model_Member extends \RedBeanPHP\SimpleModel {

    /**
     * Get display name for this member
     * Returns first+last name if available, otherwise username, otherwise email
     */
    public function displayName(): string {
        $bean = $this->bean;

        // Try first + last name
        if (!empty($bean->firstName) || !empty($bean->lastName)) {
            return trim(($bean->firstName ?? '') . ' ' . ($bean->lastName ?? ''));
        }

        // Fall back to username
        if (!empty($bean->username)) {
            return $bean->username;
        }

        // Fall back to email (part before @)
        if (!empty($bean->email)) {
            return explode('@', $bean->email)[0];
        }

        return 'Unknown';
    }

    /**
     * Get initials for avatar placeholder
     */
    public function initials(): string {
        $name = $this->displayName();
        $parts = explode(' ', $name);

        if (count($parts) >= 2) {
            return strtoupper(substr($parts[0], 0, 1) . substr($parts[1], 0, 1));
        }

        return strtoupper(substr($name, 0, 1));
    }

    /**
     * Check if this member is a bot account linked to an agent
     *
     * @return bool
     */
    public function isAgent(): bool {
        return !empty($this->bean->agentId);
    }

    /**
     * Load the linked agent bean, or null if this is a human member
     *
     * @return \RedBeanPHP\OODBBean|null
     */
    public function getAgent() {
        if (!$this->isAgent()) {
            return null;
        }
        $agent = Bean::load('agent', (int) $this->bean->agentId);
        return ($agent && $agent->id) ? $agent : null;
    }

    /**
     * Get the agent badge HTML for display in views.
     * Returns empty string for human members.
     *
     * @return string HTML badge markup
     */
    public function agentBadge(): string {
        if (!$this->isAgent()) {
            return '';
        }
        $agent = $this->getAgent();
        $provider = $agent ? htmlspecialchars($agent->provider) : 'unknown';
        return '<span class="badge bg-info ms-1" title="AI Agent (' . $provider . ')"><i class="bi bi-robot"></i> Bot</span>';
    }
}
