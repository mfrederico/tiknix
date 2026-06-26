<?php
/**
 * Instance FUSE Model
 *
 * An AI Builder instance: an isolated <slug>.tiknix git clone with its own
 * SQLite database, AI-editable inside a bubblewrap jail. One row per provisioned
 * instance; owned by the member who created it.
 *
 * Enables associations:
 * - $member->ownInstanceList : instances owned by a member (via member_id)
 *
 * Columns (camelCase in PHP -> snake_case in DB):
 * - memberId    : owner (FK member.id)
 * - slug        : subdomain label, the "<sub>" in "<sub>.tiknix"
 * - app         : source app codename (always "tiknix" here)
 * - displayName : human label
 * - engine      : coding agent for the jail (claude | qwen)
 * - status      : active | provisioning | failed
 * - createdAt   : timestamp
 */

class Model_Instance extends \RedBeanPHP\SimpleModel {

    /** Absolute on-disk path to this instance directory. */
    public function dir(): string {
        return '/var/www/html/default/' . $this->bean->slug . '.' . ($this->bean->app ?: 'tiknix');
    }

    /** True when the instance is actually provisioned on disk. */
    public function exists(): bool {
        return is_file($this->dir() . '/public/index.php');
    }

    /** Public URL of the instance subdomain. */
    public function url(): string {
        return 'https://' . $this->bean->slug . '.' . ($this->bean->app ?: 'tiknix') . '.com';
    }
}
