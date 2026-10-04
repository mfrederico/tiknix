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

    /** Where the builder's records for projects in their own containers live (dirOf). */
    public const WORKSPACES = self::ROOT . '/_workspaces';

    /** Absolute on-disk path to this instance's directory on core (dirOf). */
    public function dir(): string {
        return self::dirOf($this->bean);
    }

    /** Does this project live in its own container (TenantHost, instance.ct_kind = 'tenant')? */
    public function isTenant(): bool {
        return self::tenantRow($this->bean);
    }

    /** The same question for a bean or a plain row (a sidecar's array). */
    public static function tenantRow($inst): bool {
        $kind = is_array($inst) ? (string) ($inst['ct_kind'] ?? $inst['ctKind'] ?? '') : (string) ($inst->ctKind ?? '');
        return $kind === 'tenant';
    }

    /**
     * WHERE CORE KEEPS A PROJECT'S FILES — one rule for every caller. A host clone: its own
     * directory (dirFrom). A project in its own container: its WORKSPACE, _workspaces/<slug>,
     * which holds the builder's records only (the task board data/workbench.db, plans, logs);
     * the app itself is in the container, reached over SSH (TenantHost). A caller that went on
     * to read the app's code or config from here finds nothing — and fails saying so, rather
     * than acting on a host copy that no longer serves the site.
     *
     * @param \RedBeanPHP\OODBBean|array $inst  a bean, or a row (slug, app, ct_kind)
     */
    public static function dirOf($inst): string {
        $slug = is_array($inst) ? (string) ($inst['slug'] ?? '') : (string) ($inst->slug ?? '');
        $app  = is_array($inst) ? (string) ($inst['app'] ?? '') : (string) ($inst->app ?? '');
        return self::tenantRow($inst) ? self::workspaceFrom($slug) : self::dirFrom($slug, $app);
    }

    /** _workspaces/<slug> for a slug (validated like dirFrom). */
    public static function workspaceFrom(string $slug): string {
        $slug = trim($slug);
        if (!preg_match('/^[a-z][a-z0-9]*(-[a-z0-9]+)*$/', $slug)) {
            throw new \RuntimeException("Model_Instance::workspaceFrom(): '{$slug}' is not a slug");
        }
        return self::WORKSPACES . '/' . $slug;
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
        if ($bean && $bean->id && self::tenantRow($bean)) return self::dirOf($bean);
        $app  = ($bean && $bean->id && $bean->app) ? (string) $bean->app : $fallbackApp;
        return self::dirFrom($slug, $app);
    }

    /**
     * A setup whose log has not moved for this long is stalled: the whole of a normal setup
     * is about two minutes, and the longest single step (a TenantHost::system recipe) is
     * capped at fifteen.
     */
    public const SETUP_STALL_SECONDS = 600;

    /**
     * A project's container setup, for its card: 'active' once its container is published,
     * 'failed' when the setup stopped — see setupReport() — 'pending' while it is still going,
     * '' when there is no record of a setup at all.
     */
    public static function setupStateFor($inst): string {
        return self::setupReport($inst)['state'];
    }

    /**
     * A project's container setup, in full. The background `tenant.php --up` that
     * ProvisionService::create starts writes its workspace's .aibuilder/provision.log and its
     * pid to .aibuilder/provision.pid (its process group, too).
     *
     *   state    'active'  — container + domain on the row
     *            'failed'  — the log records an ERROR, or nothing has been written to it for
     *                        SETUP_STALL_SECONDS: a setup that died or hung cannot say so itself,
     *                        and "no progress for twelve minutes" is a failure to the person
     *                        waiting, whatever the process is doing
     *            'pending' — the log exists, is moving, and has no ERROR
     *            ''        — no log: nothing recorded a setup
     *   error    the ERROR line, or the stall described (last step + how long ago); '' otherwise
     *   last     the log's last line
     *   idle     seconds since the log last moved (0 without a log)
     *   pid      the setup's pid from provision.pid, 0 without one
     *   running  whether that pid is alive right now (false without one)
     *
     * @return array{state:string,error:string,last:string,idle:int,pid:int,running:bool}
     */
    public static function setupReport($inst): array {
        $r = ['state' => '', 'error' => '', 'last' => '', 'idle' => 0, 'pid' => 0, 'running' => false];
        if ((int) $inst->ctVmid > 0 && trim((string) $inst->ctDomain) !== '') { $r['state'] = 'active'; return $r; }
        $ab  = self::workspaceFrom((string) $inst->slug) . '/.aibuilder';
        $log = $ab . '/provision.log';
        if (!is_file($log)) return $r;
        $lines = array_values(array_filter(array_map('trim', file($log))));
        $r['last'] = $lines ? (string) end($lines) : '';
        $r['idle'] = max(0, time() - (int) filemtime($log));
        if (is_file($ab . '/provision.pid')) {
            $r['pid'] = (int) trim((string) file_get_contents($ab . '/provision.pid'));
            $r['running'] = $r['pid'] > 0 && posix_kill($r['pid'], 0);
        }
        foreach ($lines as $l) if (str_starts_with($l, 'ERROR ')) $r['error'] = substr($l, 6);
        if ($r['error'] !== '') { $r['state'] = 'failed'; return $r; }
        if ($r['idle'] >= self::SETUP_STALL_SECONDS) {
            $r['state'] = 'failed';
            $r['error'] = 'no progress for ' . (int) floor($r['idle'] / 60) . ' minutes'
                        . ($r['last'] !== '' ? ' — last step: ' . $r['last'] : '')
                        . ($r['running'] ? ' (the setup process is still there, hung)' : ' (the setup process is gone)');
            return $r;
        }
        $r['state'] = 'pending';
        return $r;
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

    /** Is unattended auto-triage on for this instance? */
    public function autoTriageOn(): bool {
        return filter_var($this->bean->autoTriage ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Turn unattended auto-triage on or off, recording who did it.
     *
     * This lives on the model rather than in the controller so the flag and its audit
     * row cannot come apart. Flipping it decides that the control plane may launch
     * headless agent builds against a client's repo with nobody watching — through
     * Firehose on a new error AND through plan-audit's idle sweep on deferred ones —
     * so the change is a billable act and has to stay answerable later.
     *
     * Writes nothing when the value is unchanged: a trail full of no-ops is a trail
     * nobody reads. Returns true when something actually changed.
     */
    public function setAutoTriage(bool $on, int $byMemberId, string $note = ''): bool {
        $was = $this->autoTriageOn();
        if ($was === $on) return false;

        $this->bean->autoTriage = $on ? 1 : 0;
        \app\Bean::store($this->bean);

        $row = \app\Bean::dispense('instanceaudit');
        $row->instanceId = (int) $this->bean->id;
        $row->memberId   = $byMemberId;
        $row->field      = 'auto_triage';
        $row->oldValue   = $was ? '1' : '0';
        $row->newValue   = $on  ? '1' : '0';
        $row->note       = mb_substr($note, 0, 500);
        $row->createdAt  = date('Y-m-d H:i:s');
        \app\Bean::store($row);

        \Flight::get('log')?->info('auto_triage changed', [
            'instance' => (string) $this->bean->slug,
            'to'       => $on ? 'on' : 'off',
            'by'       => $byMemberId,
        ]);
        return true;
    }

    /**
     * The most recent change to a setting on this instance, or null.
     *
     * The "audit line" the admin page shows. Reading the newest row rather than keeping
     * a separate current-state stamp is what stops the two disagreeing.
     */
    public function lastAudit(string $field = 'auto_triage'): ?object {
        if (!in_array('instanceaudit', \app\Bean::inspect(), true)) return null;
        $row = \app\Bean::findOne('instanceaudit',
            'instance_id = ? AND field = ? ORDER BY id DESC', [(int) $this->bean->id, $field]);
        return ($row && $row->id) ? $row : null;
    }
}
