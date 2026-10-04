<?php
/**
 * ProvisionService — the CORE-side owner of instance-registry MUTATIONS + capricorn
 * provisioning. The workbench sidecar (AI Builder) is read-only to core, so it never
 * writes the `instance` registry directly; it signs a request and calls the HMAC-authed
 * /provision endpoint, which dispatches here. All registry writes + custody stay in core.
 *
 * Lifted from controls/Aibuilder's create/fork/delete/share — the ~5-op write-seam. Each
 * op takes ($memberId, $params) and returns ['ok'=>true, …] or ['ok'=>false,'error','code'].
 */
namespace app;

use \Flight as Flight;

class ProvisionService {

    private const APP = 'tiknix';
    // Validates a STORED slug, which is the immutable {base}-{hash} identity
    // (e.g. "towels-a1b2c3"). Path-safe: lowercase, starts with a letter, internal
    // single hyphens only — no dots/slashes/uppercase (these slugs become dir names).
    private const SLUG_RE = '/^[a-z][a-z0-9]*(-[a-z0-9]+)*$/';
    // Validates the user-chosen BASE name before the hash is appended. The base is
    // NOT unique — two tenants may both pick "towels" — and may itself contain hyphens
    // ("mighty-mouse"); the minted hash is always appended as the FINAL segment, so the
    // stored slug reads "mighty-mouse-a1b2c3" and the hash sits right before ".tiknix.com".
    private const BASE_RE = '/^(?=.{2,40}$)[a-z][a-z0-9]*(-[a-z0-9]+)*$/';

    /**
     * Set when a project was created but something about it is not right — today, a
     * broker key that could not be written. Carried back to the caller so the person who
     * just clicked Create is told, rather than finding out at their first publish.
     */
    private string $lastWarning = '';

    private function cfg(): array {
        return @parse_ini_file(dirname(__DIR__) . '/conf/aibuilder.ini', true) ?: [];
    }

    private function appNamespace(): string {
        $host = strtolower((string) (parse_url((string) Flight::get('app.baseurl'), PHP_URL_HOST) ?: ''));
        $ns   = preg_replace('/\.com$/', '', $host);
        return ($ns !== '' && preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/', $ns))
            ? $ns : self::APP;
    }

    /**
     * Is this member actually ROOT, per core's member row? The authority for privilege in
     * the provision path — never a caller-supplied is_root flag, which the HMAC does not
     * vouch for. A member id of 0, a missing row, or any level above ROOT is not root.
     */
    private static function memberIsRoot(int $memberId): bool {
        if ($memberId <= 0) return false;
        $level = Bean::getCell('SELECT level FROM member WHERE id = ?', [$memberId]);
        return $level !== null && (int) $level <= \LEVELS['ROOT'];
    }

    private function instanceDir(string $slug): string {
        // appNamespace() is derived from the HOST, which is what a not-yet-provisioned slug
        // has to use — there is no row to read yet. Once there is one, the row wins.
        return \Model_Instance::dirForSlug($slug, $this->appNamespace());
    }

    /**
     * Mint the immutable {base}-{hash} slug — the frozen identity that anchors the
     * dir, the staging host, and any bespoke-domain CNAME. The base repeats across
     * tenants; the 6-char hash makes the full slug unique in the registry and on disk.
     * Returns '' if a free slug can't be allocated (astronomically unlikely).
     */
    private function mintSlug(string $base): string {
        for ($i = 0; $i < 8; $i++) {
            $hash = substr(bin2hex(random_bytes(4)), 0, 6);   // 6 hex chars: [0-9a-f], DNS/path safe
            $slug = $base . '-' . $hash;
            if (Bean::count('instance', 'slug = ?', [$slug]) === 0 && !is_dir($this->instanceDir($slug))) return $slug;
        }
        return '';
    }

    /** A NEW project owned by $memberId, in its own container. */
    public function create(int $memberId, array $p): array {
        $base   = strtolower(trim((string) ($p['slug'] ?? '')));
        $name   = trim((string) ($p['name'] ?? '')) ?: ucfirst($base);
        $engine = (string) ($p['engine'] ?? 'claude');
        $plan   = ProjectQuota::kindOf((string) ($p['plan'] ?? 'project'));
        // Only root may flag the "(default)" core sandbox; root-ness is read from the
        // member's real level, not a caller-supplied is_root flag (see delete()).
        $isDefault = !empty($p['is_default']) && self::memberIsRoot($memberId);

        if (!preg_match(self::BASE_RE, $base)) return ['ok' => false, 'error' => 'Invalid name (a-z, then a-z0-9, 2-40 chars).', 'code' => 400];

        // Plan gate. Checked BEFORE anything is minted or written, so a refusal leaves no
        // half-made project behind. Returns null when enforcement is off.
        if ($refusal = ProjectQuota::refusalFor($memberId, 1)) return $refusal;
        // The base repeats across tenants; mint a unique {base}-{hash} slug. The lone
        // exception is the root-flagged "(default)" core sandbox, which keeps its bare slug.
        if ($isDefault) {
            $slug = $base;
            if (Bean::count('instance', 'slug = ?', [$slug]) > 0 || is_dir($this->instanceDir($slug)))
                return ['ok' => false, 'error' => 'That name is already taken.', 'code' => 409];
        } else {
            $slug = $this->mintSlug($base);
            if ($slug === '') return ['ok' => false, 'error' => 'Could not allocate a unique instance id.', 'code' => 500];
        }

        $member = Bean::load('member', $memberId);
        if (!$member->id) return ['ok' => false, 'error' => 'Unknown member.', 'code' => 403];

        // A project lives in its own container. Now: its registry row and its repository (TenantApp —
        // the app template, the creator's project). Then, in the background (about two minutes):
        // the container created, provisioned — its owner account is the creator (claim-root), its
        // broker key, its builder terminal — and published at <slug>.<app>.com (tenant.php --up).
        $t = TenantApp::create($slug, $name, $memberId);
        if (!$t['ok']) return ['ok' => false, 'error' => 'Could not create the project: ' . $t['error'], 'code' => 500];
        $inst = $t['inst'];
        $inst->app       = $this->appNamespace();
        $inst->engine    = $engine;
        $inst->plan      = ProjectQuota::kindOf($plan);
        $inst->isDefault = $isDefault ? 1 : 0;
        Bean::store($inst);
        try {
            $this->setUpContainer($inst, (array) ($p['then'] ?? []));
        } catch (\RuntimeException $e) {
            Flight::get('log')->error('project container setup could not start', ['slug' => $slug, 'err' => $e->getMessage()]);
            return ['ok' => true, 'id' => (int) $inst->id, 'slug' => $slug, 'warning' => 'The project exists, but setting up its container could not start: ' . $e->getMessage()];
        }
        return ['ok' => true, 'id' => (int) $inst->id, 'slug' => $slug];
    }

    /**
     * `tenant.php --up` for a new project, detached: its container created, provisioned and
     * published at <slug>.<app>.com. Its progress — and any ERROR — is in its workspace's
     * .aibuilder/provision.log, which the project card reads (Model_Instance::setupReport).
     *
     * The run is its own session (setsid), and its pid — which is then also its process-group
     * id — is .aibuilder/provision.pid: a setup that stops making progress without saying so
     * (the box rebooted, an ssh hung) is the one thing the log cannot record, so
     * scripts/provision-sweep.php reads the pid to tell dead from hung, and ends the whole
     * group before it removes what the setup left behind.
     *
     * @param string[] $then tenant.php arguments to run once the container is up — only if it
     *                       came up (&&): the Get-started hand-off's --handoff-finish=<token>
     */
    private function setUpContainer(object $inst, array $then = []): void {
        $slug = (string) $inst->slug;
        $ab = \Model_Instance::workspaceFrom($slug) . '/.aibuilder';
        if (!is_dir($ab) && !@mkdir($ab, 0775, true)) throw new \RuntimeException("could not create {$ab}");
        $log = $ab . '/provision.log';
        $pid = $ab . '/provision.pid';
        $domain = $slug . '.' . $this->appNamespace() . '.com';
        $cmd = 'cd ' . escapeshellarg(dirname(__DIR__)) . ' && echo "[setup] $(date) ' . $slug . ' -> ' . $domain . '"'
             . ' && env -u TIKNIX_WORKBENCH_DB php scripts/tenant.php --up=' . escapeshellarg($slug) . ' --domain=' . escapeshellarg($domain);
        if ($then) {
            foreach ($then as $a) {
                if (!preg_match('/^--[a-z][a-z-]*(=[A-Za-z0-9._-]+)?$/D', (string) $a)) throw new \RuntimeException("not a tenant.php argument: {$a}");
            }
            $cmd .= ' && echo "[then] $(date) ' . implode(' ', $then) . '" && env -u TIKNIX_WORKBENCH_DB php scripts/tenant.php ' . implode(' ', array_map('escapeshellarg', $then));
        }
        $script = 'echo $$ > ' . escapeshellarg($pid) . '; (' . $cmd . ') >> ' . escapeshellarg($log) . ' 2>&1';
        exec('setsid nohup bash -lc ' . escapeshellarg($script) . ' > /dev/null 2>&1 &', $o, $c);
        if ($c !== 0) throw new \RuntimeException("could not start tenant.php --up for {$slug}");
    }

    // ---- authorization (core is the authority; the caller passes ids, we re-check) ----

    // These were a SECOND implementation of the access rules, agreeing with
    // TaskAccessControl only by coincidence of nobody having changed either. Both now ask
    // the instance, which is the thing the question is about.
    private function ownsInstance(int $memberId, int $instanceId): bool {
        return $instanceId > 0 && Bean::load('instance', $instanceId)->ownedBy($memberId);
    }
    /**
     * Change a project's priced KIND (Project ↔ Client project). Owner only; the billing
     * consequence is whatever ProjectQuota::breakdown says next time it is asked, so there
     * is nothing to sync here — the usage callback reads live counts.
     */
    public function setPlan(int $memberId, array $p): array {
        $instanceId = (int) ($p['id'] ?? 0);
        $plan = strtolower(trim((string) ($p['plan'] ?? '')));
        if (!in_array($plan, ProjectQuota::KINDS, true)) return ['ok' => false, 'error' => 'Unknown project kind.', 'code' => 400];
        $inst = Bean::load('instance', $instanceId);
        if (!$inst->id) return ['ok' => false, 'error' => 'No such instance', 'code' => 404];
        if (!$this->ownsInstance($memberId, $instanceId)) return ['ok' => false, 'error' => 'Not your instance', 'code' => 403];
        if (!empty($inst->isDefault)) return ['ok' => false, 'error' => 'The (default) core instance has no plan.', 'code' => 403];
        if (ProjectQuota::kindOf($inst->plan) === $plan) return ['ok' => true, 'id' => $instanceId, 'plan' => $plan, 'changed' => false];
        $inst->plan = $plan;
        Bean::store($inst);
        Flight::get('log')->info('project kind changed', ['instance' => $instanceId, 'member' => $memberId, 'plan' => $plan]);
        return ['ok' => true, 'id' => $instanceId, 'plan' => $plan, 'changed' => true];
    }

    // ---- share: toggle a team on an owned instance (instance_team m2m) ----

    public function share(int $memberId, array $p): array {
        $instanceId = (int) ($p['id'] ?? 0);
        $teamId     = (int) ($p['team_id'] ?? 0);
        $shared     = !empty($p['shared']);
        if (!$this->ownsInstance($memberId, $instanceId)) return ['ok' => false, 'error' => 'No such instance (owner only)', 'code' => 404];
        if ($teamId <= 0) return ['ok' => false, 'error' => 'Pick a team', 'code' => 400];
        if ((int) Bean::getCell('SELECT COUNT(*) FROM teammember WHERE team_id = ? AND member_id = ?', [$teamId, $memberId]) === 0)
            return ['ok' => false, 'error' => 'You are not a member of that team', 'code' => 403];
        $team = Bean::load('team', $teamId);
        if (!$team->id) return ['ok' => false, 'error' => 'No such team', 'code' => 404];

        // Plan gate, on the RECEIVING side — sharing is the one action whose cost lands on
        // someone other than the person doing it. Only when turning sharing ON; removing a
        // share can only ever reduce a total, so it is never refused.
        if ($shared && ($refusal = ProjectQuota::refusalForShare($instanceId, $teamId))) return $refusal;

        $inst  = Bean::load('instance', $instanceId);
        $teams = $inst->sharedTeamList;
        if ($shared) $teams[$team->id] = $team; else unset($teams[$team->id]);
        $inst->sharedTeamList = $teams;
        Bean::store($inst);
        // Unshared: the team's members who can no longer use it lose the account Tiknix made in its app.
        $revokeErrors = [];
        if (!$shared) foreach (AppAccess::teamMembers($teamId) as $mid) $revokeErrors = array_merge($revokeErrors, AppAccess::revokeLost($mid, [$instanceId])['errors']);
        if ($revokeErrors) return ['ok' => false, 'error' => 'Unshared, but: ' . implode('; ', $revokeErrors), 'code' => 502];

        return ['ok' => true, 'team_id' => $teamId, 'team_name' => (string) $team->name, 'shared' => $shared,
                'shared_team_ids' => array_values(array_map('intval', array_keys($inst->sharedTeamList)))];
    }

    // ---- fork: new instance from a source instance's checkpoint (code + tracked db) ----

    public function fork(int $memberId, array $p): array {
        // Copying a project was a host-clone operation (capricorn's provision-instance.sh + a checkout
        // of the source's checkpoint). Projects live in their own containers now; a container copy
        // is not built yet — refused by name rather than producing a host clone nothing can build.
        return ['ok' => false, 'error' => 'Copying a project is not available yet for projects in their own container.', 'code' => 409];
    }

    // ---- delete: confirm-gated teardown (kill jail, unlink connectors, archive, trash) ----

    /**
     * The exact phrase delete() demands as confirmation.
     *
     * Public because a UI has to SHOW it and check what was typed against it, and a
     * second copy of this rule in a view would drift from the one that actually guards
     * the deletion — leaving a form that cannot be satisfied, or worse, one that looks
     * satisfied and is not.
     */
    public function confirmPhrase(string $slug): string {
        return $slug . '.' . $this->appNamespace() . '.com';
    }

    public function delete(int $memberId, array $p): array {
        $instanceId = (int) ($p['id'] ?? 0);
        // Root-ness is read from the MEMBER, never from $p. The signed provision payload
        // proves which sidecar sent it, not the caller's privilege, so a caller-supplied
        // is_root=true used to authorise deleting any tenant's project. member_id in the
        // payload is signed and trustworthy; their level is the authority.
        $isRoot = self::memberIsRoot($memberId);
        $inst = Bean::load('instance', $instanceId);
        if (!$inst->id) return ['ok' => false, 'error' => 'No such instance', 'code' => 404];
        if ((int) $inst->memberId !== $memberId && !$isRoot) return ['ok' => false, 'error' => 'Not your instance', 'code' => 403];
        if (!empty($inst->isDefault)) return ['ok' => false, 'error' => 'The (default) core instance cannot be deleted here.', 'code' => 403];

        $slug = (string) $inst->slug;
        if (!preg_match(self::SLUG_RE, $slug)) return ['ok' => false, 'error' => 'Invalid instance slug', 'code' => 400];
        $domain = $this->confirmPhrase($slug);
        if (!hash_equals($domain, trim((string) ($p['confirm'] ?? ''))))
            return ['ok' => false, 'error' => 'Confirmation does not match — type "' . $domain . '" exactly.', 'code' => 400];

        $tenant = \Model_Instance::tenantRow($inst);
        if ($tenant) {
            // In its own container: archive the app (code + data) and its origin + board, then the
            // container, every name it was served under, the origin and the workspace (deleteTenant).
            $t = $this->deleteTenant($inst, $slug);
            if (!$t['ok']) return ['ok' => false, 'error' => $t['error'], 'code' => 500];
            $steps = $t['steps'];
        } else {
            return ['ok' => false, 'error' => "{$slug} is not running in its own container — nothing here knows how to delete it.", 'code' => 409];
        }

        // Clean core's task records for this instance (stale copies + sessions + /projects clones).
        $tasks = Bean::find('workbenchtask', 'instance_id = ?', [$instanceId]);
        if ($tasks) {
            $killed = 0; $wiped = 0;
            foreach ($tasks as $t) {
                $sessions = [(string) $t->agentSession, (string) $t->tmuxSession];
                // Both names: this instance is being torn down, so an orchestrator
                // still running under the pre-rename name must die with it.
                if (empty($t->parentTaskId)) {
                    $sessions[] = PlanOrchestrator::sessionName((int) $t->id, $slug);
                    $sessions[] = TmuxManager::legacyPlanSessionName((int) $t->id);
                }
                foreach (array_unique(array_filter($sessions)) as $s) {
                    if (TmuxManager::exists($s)) { TmuxManager::kill($s); $killed++; }
                }
                $ws = (string) $t->projectPath;
                if ($ws !== '' && strpos($ws, '/projects/') !== false && is_dir($ws)) { @exec('rm -rf ' . escapeshellarg($ws) . ' 2>&1'); $wiped++; }
                foreach (['tasklog', 'taskcomment', 'tasksnapshot'] as $child) {
                    $rows = Bean::find($child, 'task_id = ?', [(int) $t->id]);
                    if ($rows) Bean::trashAll($rows);
                }
            }
            Bean::trashAll($tasks);
            $steps[] = 'deleted ' . count($tasks) . ' workbench task(s)'
                     . ($killed ? ", stopped {$killed} session(s)" : '')
                     . ($wiped ? ", removed {$wiped} workspace(s)" : '');
        }

        Bean::trash($inst);
        $steps[] = 'removed instance record';

        return ['ok' => true, 'slug' => $slug, 'domain' => $domain, 'steps' => $steps];
    }

    /**
     * Delete EVERY project a member owns — for account closure.
     *
     * The per-instance typed confirmation delete() demands is for a person deleting one
     * project on purpose; account closure has already made the member type their whole
     * account's confirmation once, so the phrase is supplied here per instance rather than
     * asked for again. Default/core instances are skipped by delete()'s own guard. Returns
     * a summary; a single instance failing does NOT abort the rest (a half-closed account
     * with one orphaned project is worse than reporting which ones did not go).
     */
    public function deleteAllForMember(int $memberId): array {
        $deleted = []; $failed = [];
        $rows = Bean::find('instance',
            "member_id = ? AND (status IS NULL OR status != 'deleted')", [$memberId]);
        foreach ($rows as $inst) {
            if (!$inst->id || !empty($inst->isDefault)) continue;
            $res = $this->delete($memberId, [
                'id'      => (int) $inst->id,
                'confirm' => $this->confirmPhrase((string) $inst->slug),
            ]);
            if (!empty($res['ok'])) $deleted[] = (string) $inst->slug;
            else $failed[] = ['slug' => (string) $inst->slug, 'error' => (string) ($res['error'] ?? 'unknown')];
        }
        return ['ok' => empty($failed), 'deleted' => $deleted, 'failed' => $failed];
    }

    /**
     * Tear down a project that lives in its own container — what deleting it means now, for the
     * Projects page and `tenant.php --destroy` alike. Nothing is removed until it is archived:
     *
     *   secure/archives/<slug>-<stamp>-app.tgz    the app from its container (/srv/app: code AND
     *                                             data — its databases, secure/, uploads; not vendor/)
     *   secure/archives/<slug>-<stamp>-origin.tgz its origin repository + builder board (_origins,
     *                                             _workspaces) — the history, the plans
     *
     * then the container (TenantHost::destroy: every name it was served under), the origin and
     * the workspace. The caller removes the task records and the registry row.
     */
    private function deleteTenant(object $inst, string $slug): array {
        $root = \Model_Instance::ROOT;
        $workspace = \Model_Instance::dirOf($inst);   // before destroy(), which clears ct_kind
        $origins = array_values(array_filter([
            "{$root}/_origins/{$slug}.git", "{$root}/_origins/{$slug}.merge", "{$root}/_origins/{$slug}.lock",
        ], 'file_exists'));
        if ($workspace !== "{$root}/_workspaces/{$slug}") return ['ok' => false, 'error' => "refusing: {$slug}'s workspace resolved to {$workspace}"];

        $archiveDir = dirname(__DIR__) . '/secure/archives';
        if (!is_dir($archiveDir) && !@mkdir($archiveDir, 0700, true)) return ['ok' => false, 'error' => "could not create {$archiveDir}"];
        $stamp = date('Ymd-His');
        $steps = [];

        // A container whose setup stopped before app.sh put the app in it (the provision sweep's
        // case) has nothing to archive — distinct from an archive that FAILS, which refuses below.
        // The template already holds an empty /srv/app; the app is there once app.sh has cloned it.
        $hasApp = false;
        if ((int) $inst->ctVmid > 0) {
            [$tc, $to] = TenantHost::ssh($inst, 'root', 'test -d /srv/app/.git && echo yes || echo no', null, 60);
            if ($tc !== 0) return ['ok' => false, 'error' => "could not look inside {$slug}'s container (nothing removed): " . trim($to)];
            $hasApp = trim($to) === 'yes';
            if (!$hasApp) $steps[] = 'the container had no app in it yet (setup never reached app.sh) — nothing to archive';
        }
        if ($hasApp) {
            $appTgz = "{$archiveDir}/{$slug}-{$stamp}-app.tgz";
            [$c, $err] = TenantHost::ssh($inst, 'root', 'tar czf - -C /srv --exclude=app/vendor --exclude=app/node_modules --exclude=app/bin/claude app', null, 1800, null, $appTgz);
            exec('gzip -t ' . escapeshellarg($appTgz) . ' 2>&1', $gz, $gzc);
            if ($c !== 0 || $gzc !== 0 || filesize($appTgz) < 1024) {
                @unlink($appTgz);
                return ['ok' => false, 'error' => "could not archive {$slug}'s app from its container (nothing removed): " . trim($err)];
            }
            $steps[] = 'archived the app to secure/archives/' . basename($appTgz) . ' (' . round(filesize($appTgz) / 1048576, 1) . ' MB)';
        }
        $rel = array_map(fn($p) => substr($p, strlen($root) + 1), array_merge($origins, is_dir($workspace) ? [$workspace] : []));
        if ($rel) {
            $originTgz = "{$archiveDir}/{$slug}-{$stamp}-origin.tgz";
            exec('tar czf ' . escapeshellarg($originTgz) . ' -C ' . escapeshellarg($root) . ' ' . implode(' ', array_map('escapeshellarg', $rel)) . ' 2>&1', $o, $tc);
            if ($tc !== 0) { @unlink($originTgz); return ['ok' => false, 'error' => "could not archive {$slug}'s origin (nothing removed): " . implode(' ', array_slice($o, -2))]; }
            $steps[] = 'archived origin + board to secure/archives/' . basename($originTgz);
        }

        if ((int) $inst->ctVmid > 0) {
            $d = TenantHost::destroy($inst);
            if (!$d['ok']) return ['ok' => false, 'error' => 'the archives are made, the container is not destroyed: ' . $d['error'], 'steps' => $steps];
            $steps[] = $d['step'];
        }
        foreach (array_merge($origins, is_dir($workspace) ? [$workspace] : []) as $p) {
            // Each path rebuilt from the slug above; never a caller's string.
            exec('rm -rf ' . escapeshellarg($p) . ' 2>&1');
        }
        if ($rel) $steps[] = 'removed ' . implode(', ', $rel);
        return ['ok' => true, 'steps' => $steps];
    }

}
