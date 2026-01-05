<?php
/**
 * Member FUSE Model
 *
 * Enables RedBeanPHP associations for the member bean:
 * - ownApikeyList: API keys belonging to this member
 * - ownContactList: Contact submissions by this member
 * - ownSettingsList: Settings for this member
 * - ownTeammemberList: Team memberships
 */

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
}
