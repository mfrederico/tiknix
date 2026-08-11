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
        return self::dirFrom((string) $this->bean->slug, (string) $this->bean->app);
    }

    /**
     * The path rule itself: slug + app namespace -> directory. No database, no bean.
     *
     * Exists for callers holding a plain ARRAY rather than a bean — which is every
     * sidecar. Each of workbench, pipelines, publisher, explorer and shop had grown its
     * own private copy of this two-line rule, five copies that have to agree forever and
     * that nothing tests. They agree today; the reaper's copy of a neighbouring rule did
     * not, and read "mileage.tiknix" where every other caller said "mileage", so it
     * reported every live build as dead for months.
     *
     * Sidecars derived the parent from dirname(sidecar.core_root), which resolves to the
     * same place as ROOT. Same answer, one definition.
     */
    public static function dirFrom(string $slug, string $app = ''): string {
        $slug = trim($slug);
        if ($slug === '') {
            // NO EMPTY SLUG. It would build ROOT . '/.tiknix' — a string shaped exactly
            // like a project directory that is not one, handed to callers who go on to
            // read config, open databases and run git in it. That is the failure this
            // whole consolidation is about: not a crash, a confident wrong answer.
            throw new \InvalidArgumentException(
                'Model_Instance::dirFrom requires a slug; an empty one names ' . self::ROOT
                . '/.<app>, which is not a project. The caller does not know which instance '
                . 'it means and must say so rather than be given a path.');
        }
        // The app namespace DOES have a legitimate default — a row carrying none is a
        // tiknix app, which is the convention DEFAULT_APP exists to state. The slug has no
        // such convention: absent means unknown, and unknown has no path.
        $app = trim($app) !== '' ? trim($app) : self::DEFAULT_APP;
        return self::ROOT . '/' . $slug . '.' . $app;
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
        return self::dirFrom($slug, $app);
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
     * True when the instance is actually provisioned on disk.
     *
     * NOT called exists(). OODBBean has its own public exists($property), so a model method
     * of that name is SHADOWED — $instance->exists() reaches RedBean's, which requires an
     * argument and fatals without one. It had no callers, so nobody found out. Unlike a
     * method that merely shares a name with a COLUMN, this one is unreachable through the
     * bean at all.
     *
     * Answers for THIS machine only: with a hosted database the row is global while the
     * directory is local, so a false here can mean "provisioned elsewhere".
     */
    public function isProvisioned(): bool {
        return is_file($this->dir() . '/public/index.php');
    }

    /** Public URL of the instance subdomain. */
    public function url(): string {
        return 'https://' . $this->bean->slug . '.' . ($this->bean->app ?: self::DEFAULT_APP) . '.com';
    }

    // ---- access ----------------------------------------------------------------------
    // Who may touch this instance. There were TWO implementations of these — one in
    // TaskAccessControl, one private in ProvisionService — verified agreeing across 130
    // (member × instance) pairs, which is the same "correct today" position every other
    // duplicate in this codebase held right up until it wasn't.

    /**
     * Ownership. The gate for destructive and administrative actions: delete, fork,
     * share management, restart. Distinct from access — a teammate can use an instance
     * without being able to end it.
     */
    public function ownedBy(int $memberId): bool {
        return $memberId > 0 && (int) $this->bean->memberId === $memberId;
    }

    /**
     * May this member use this instance at all — own it, or share a team it is shared
     * with. This is the gate that decides whose code an agent may edit.
     */
    public function accessibleBy(int $memberId): bool {
        if ($this->ownedBy($memberId)) return true;
        if ($memberId <= 0) return false;
        if (!in_array('instance_team', \app\Bean::inspect(), true)) return false;

        return (int) \app\Bean::getCell(
            'SELECT COUNT(*) FROM instance_team it JOIN teammember tm ON tm.team_id = it.team_id '
          . 'WHERE it.instance_id = ? AND tm.member_id = ?',
            [(int) $this->bean->id, $memberId]
        ) > 0;
    }
}
