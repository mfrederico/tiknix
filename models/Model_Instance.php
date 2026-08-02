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

    /** Where instance directories live. One definition, so a path cannot drift. */
    public const ROOT = '/var/www/html/default';

    /** Default app namespace when a row does not carry one. */
    public const DEFAULT_APP = 'tiknix';

    /** Absolute on-disk path to this instance directory. */
    public function dir(): string {
        return self::ROOT . '/' . $this->bean->slug . '.' . ($this->bean->app ?: self::DEFAULT_APP);
    }

    /**
     * The directory for a slug, for callers holding one rather than a bean.
     *
     * Derives the app namespace from the ROW, which is the point: three separate copies of
     * this path existed and controls/Integrations.php hard-coded ".tiknix". They agreed
     * only because every instance so far has app='tiknix'; the first one that does not
     * would have sent Integrations at a directory that is not there — and these paths feed
     * git operations and archiving.
     *
     * Falls back to the default namespace for a slug with no row, which is what a caller
     * mid-provision has.
     */
    public static function dirForSlug(string $slug, string $fallbackApp = self::DEFAULT_APP): string {
        $bean = \app\Bean::findOne('instance', 'slug = ?', [$slug]);
        $app  = ($bean && $bean->id && $bean->app) ? (string) $bean->app : $fallbackApp;
        return self::ROOT . '/' . $slug . '.' . ($app ?: self::DEFAULT_APP);
    }

    /**
     * The instance's own workbench database — where its plans and tasks live.
     *
     * Built inline in several places, always as dir() . '/data/workbench.db'. It is the
     * file the Builder writes to, so getting it wrong means writing someone's task data
     * into the wrong instance.
     */
    public function workbenchDb(): string {
        return $this->dir() . '/data/workbench.db';
    }

    /**
     * The instance's application database, as an absolute path.
     *
     * Reads the instance's own config where present, because an instance may have been
     * configured with a different filename; falls back to the conventional
     * database/<slug>.db. Mirrors ProvisionService::instanceDbRel(), which is where this
     * rule was written.
     */
    public function dbPath(): string {
        $ini = @parse_ini_file($this->dir() . '/conf/config.ini', true) ?: [];
        $rel = (string) ($ini['database']['path'] ?? '');
        if (!preg_match('#^database/[A-Za-z0-9._-]+\.db$#', $rel)) {
            $rel = 'database/' . $this->bean->slug . '.db';
        }
        return $this->dir() . '/' . $rel;
    }

    /**
     * True when the instance is actually provisioned on disk.
     *
     * NOT called exists(). OODBBean has its own public exists($property), so a model method
     * of that name is SHADOWED — $instance->exists() reaches RedBean's, which requires an
     * argument and fatals without one. It had no callers, so nobody found out. Unlike a
     * method that merely shares a name with a COLUMN, this one is unreachable through the
     * bean at all.
     */
    public function isProvisioned(): bool {
        return is_file($this->dir() . '/public/index.php');
    }

    /** Public URL of the instance subdomain. */
    public function url(): string {
        return 'https://' . $this->bean->slug . '.' . ($this->bean->app ?: 'tiknix') . '.com';
    }
}
