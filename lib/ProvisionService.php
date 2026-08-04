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

    /** Run a capricorn instance script (args already validated). Returns ok/out/code. */
    private function runScript(string $script, array $args): array {
        $cfg    = $this->cfg();
        $binDir = rtrim((string) ($cfg['ops']['bin_dir'] ?? '/home/ubuntu/capricorn/bin'), '/');
        $prefix = trim((string) ($cfg['ops']['sudo_prefix'] ?? ''));
        $cmd = ($prefix ? $prefix . ' ' : '') . escapeshellarg($binDir . '/' . $script);
        foreach ($args as $a) { $cmd .= ' ' . escapeshellarg((string) $a); }
        $lines = []; $code = 0;
        exec($cmd . ' 2>&1', $lines, $code);
        return ['ok' => $code === 0, 'out' => implode("\n", $lines), 'code' => $code];
    }

    /** Register an instance bean owned by $memberId (shared by create/fork). */
    private function registerInstanceBean(int $memberId, string $slug, string $name, string $engine, bool $isDefault): object {
        $member = Bean::load('member', $memberId);
        $inst = Bean::dispense('instance');
        $inst->slug        = $slug;
        $inst->app         = $this->appNamespace();
        $inst->displayName = $name;
        $inst->engine      = $engine;
        $inst->status      = 'active';
        $inst->isDefault   = $isDefault ? 1 : 0;
        $inst->createdAt   = date('Y-m-d H:i:s');
        $member->ownInstanceList[] = $inst;   // sets member_id via the association
        Bean::store($member);
        // The broker key is what lets this project talk to the control plane at all —
        // publish, connected stores, the lot. Failing to write it used to be swallowed on
        // the theory that it could be minted later, which produced a project that looked
        // finished and could not ship, with nothing in the log to say why. It is LOUD now:
        // the error is recorded, and the caller is told so it can reach the person who
        // just made the project. Provisioning still succeeds — the working copy exists and
        // the Connections page can mint the key — but nobody is left guessing.
        try {
            BrokerService::ensureInstanceConfig((int) $inst->id, $memberId, $this->instanceDir($slug));
        } catch (\Throwable $e) {
            Flight::get('log')->error('broker key not written at provision', [
                'instance' => (int) $inst->id, 'slug' => $slug, 'err' => $e->getMessage(),
            ]);
            // Deliberately NOT stored on the bean: a property set here would have RedBean
            // grow an instance column the schema never asked for.
            $this->lastWarning = 'This project was created, but its broker key could not be '
                . 'written (' . $e->getMessage() . '). Open Connections to mint it before publishing.';
        }
        return $inst;
    }

    /** Provision a NEW isolated instance owned by $memberId. */
    public function create(int $memberId, array $p): array {
        $base   = strtolower(trim((string) ($p['slug'] ?? '')));
        $name   = trim((string) ($p['name'] ?? '')) ?: ucfirst($base);
        $engine = (string) ($p['engine'] ?? 'claude');
        // Only root may flag the "(default)" core sandbox; the caller passes is_root.
        $isDefault = !empty($p['is_default']) && !empty($p['is_root']);

        if (!preg_match(self::BASE_RE, $base)) return ['ok' => false, 'error' => 'Invalid name (a-z, then a-z0-9, 2-40 chars).', 'code' => 400];
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

        // capricorn clones the app, seeds an isolated sqlite db + guardrails + reset secrets.
        $out = $this->runScript('provision-instance.sh',
            [$this->appNamespace(), $slug, '--admin', (string) $member->email, '--name', $name]);
        if (!is_file($this->instanceDir($slug) . '/public/index.php'))
            return ['ok' => false, 'error' => 'Provisioning failed. ' . substr(trim($out['out']), -300), 'code' => 500];

        @file_put_contents($this->instanceDir($slug) . '/.aibuilder/engine', $engine . "\n");
        $inst = $this->registerInstanceBean($memberId, $slug, $name, $engine, $isDefault);
        $out = ['ok' => true, 'id' => (int) $inst->id, 'slug' => $slug];
        if ($this->lastWarning !== '') $out['warning'] = $this->lastWarning;
        return $out;
    }

    // ---- authorization (core is the authority; the caller passes ids, we re-check) ----

    // These were a SECOND implementation of the access rules, agreeing with
    // TaskAccessControl only by coincidence of nobody having changed either. Both now ask
    // the instance, which is the thing the question is about.
    private function ownsInstance(int $memberId, int $instanceId): bool {
        return $instanceId > 0 && Bean::load('instance', $instanceId)->ownedBy($memberId);
    }
    private function canAccessInstance(int $memberId, int $instanceId): bool {
        return $instanceId > 0 && Bean::load('instance', $instanceId)->accessibleBy($memberId);
    }

    /** Run git inside an instance's own repo. */
    private function gitInstance(string $slug, array $args): array {
        if (!preg_match(self::SLUG_RE, $slug)) return ['ok' => false, 'out' => '', 'code' => 1];
        $cmd = 'git -C ' . escapeshellarg($this->instanceDir($slug));
        foreach ($args as $a) { $cmd .= ' ' . escapeshellarg((string) $a); }
        $lines = []; $code = 0;
        exec($cmd . ' 2>&1', $lines, $code);
        return ['ok' => $code === 0, 'out' => implode("\n", $lines), 'code' => $code];
    }

    /** The configured sqlite db path (relative) for an instance, e.g. "database/foo.db". */
    private function instanceDbRel(string $slug): string {
        $ini = @parse_ini_file($this->instanceDir($slug) . '/conf/config.ini', true) ?: [];
        $p   = (string) ($ini['database']['path'] ?? '');
        return preg_match('#^database/[A-Za-z0-9._-]+\.db$#', $p) ? $p : 'database/' . $slug . '.db';
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

        $inst  = Bean::load('instance', $instanceId);
        $teams = $inst->sharedTeamList;
        if ($shared) $teams[$team->id] = $team; else unset($teams[$team->id]);
        $inst->sharedTeamList = $teams;
        Bean::store($inst);

        return ['ok' => true, 'team_id' => $teamId, 'team_name' => (string) $team->name, 'shared' => $shared,
                'shared_team_ids' => array_values(array_map('intval', array_keys($inst->sharedTeamList)))];
    }

    // ---- fork: new instance from a source instance's checkpoint (code + tracked db) ----

    public function fork(int $memberId, array $p): array {
        $srcId  = (int) ($p['id'] ?? 0);
        $ckpt   = (string) ($p['checkpoint'] ?? 'checkpoint-baseline');
        $base   = strtolower(trim((string) ($p['slug'] ?? '')));
        $name   = trim((string) ($p['name'] ?? '')) ?: ucfirst($base);
        if (!$this->canAccessInstance($memberId, $srcId)) return ['ok' => false, 'error' => 'No such source instance', 'code' => 404];
        $srcSlug = (string) Bean::getCell('SELECT slug FROM instance WHERE id = ?', [$srcId]);
        $engine  = (string) (Bean::getCell('SELECT engine FROM instance WHERE id = ?', [$srcId]) ?: 'claude');
        if (!preg_match('/^checkpoint-[A-Za-z0-9._-]+$/', $ckpt)) return ['ok' => false, 'error' => 'Invalid checkpoint name', 'code' => 400];
        if (trim($this->gitInstance($srcSlug, ['tag', '-l', $ckpt])['out']) !== $ckpt)
            return ['ok' => false, 'error' => 'Checkpoint not found in source instance', 'code' => 404];
        if (!preg_match(self::BASE_RE, $base)) return ['ok' => false, 'error' => 'Invalid name.', 'code' => 400];
        $slug = $this->mintSlug($base);   // fresh {base}-{hash}; the base may repeat across tenants
        if ($slug === '') return ['ok' => false, 'error' => 'Could not allocate a unique instance id.', 'code' => 500];

        $member = Bean::load('member', $memberId);
        $srcDir = $this->instanceDir($srcSlug);
        $newDir = $this->instanceDir($slug);

        $out = $this->runScript('provision-instance.sh',
            [$this->appNamespace(), $slug, '--admin', (string) $member->email, '--name', $name]);
        if (!is_file($newDir . '/public/index.php'))
            return ['ok' => false, 'error' => 'Provisioning failed. ' . substr(trim($out['out']), -300), 'code' => 500];

        // Overlay the checkpoint code (minus database/, which is instance-specific).
        $tar = $newDir . '/.aibuilder/fork-src.tar';
        @mkdir(dirname($tar), 0775, true);
        $a = []; $ac = 0;
        exec('git -C ' . escapeshellarg($srcDir) . ' archive ' . escapeshellarg($ckpt) . ' -o ' . escapeshellarg($tar) . ' 2>&1', $a, $ac);
        if ($ac !== 0) return ['ok' => false, 'error' => 'Could not read the checkpoint tree.', 'code' => 500];
        $e = []; $ec = 0;
        exec('tar -xf ' . escapeshellarg($tar) . ' -C ' . escapeshellarg($newDir)
             . ' --exclude=' . escapeshellarg('database') . ' --exclude=' . escapeshellarg('database/*') . ' 2>&1', $e, $ec);
        @unlink($tar);
        if ($ec !== 0) return ['ok' => false, 'error' => 'Could not apply the checkpoint code (permissions?).', 'code' => 500];

        // Carry DATA: stream the checkpoint's tracked sqlite db into the new db path.
        $srcDbRel = $this->instanceDbRel($srcSlug);
        $newDb    = $newDir . '/' . $this->instanceDbRel($slug);
        $carried  = false;
        if ($this->gitInstance($srcSlug, ['cat-file', '-e', $ckpt . ':' . $srcDbRel])['ok']) {
            $d = []; $dc = 0;
            exec('git -C ' . escapeshellarg($srcDir) . ' show ' . escapeshellarg($ckpt . ':' . $srcDbRel)
                 . ' > ' . escapeshellarg($newDb) . ' 2>&1', $d, $dc);
            $carried = ($dc === 0 && is_file($newDb) && filesize($newDb) > 0);
        }

        $this->gitInstance($slug, ['add', '-A']);
        $this->gitInstance($slug, ['commit', '--no-verify', '-m',
            'Fork from ' . $srcSlug . '@' . $ckpt . ($carried ? ' (code+data)' : ' (code only)')]);

        $inst = $this->registerInstanceBean($memberId, $slug, $name, $engine, false);
        $out = ['ok' => true, 'id' => (int) $inst->id, 'slug' => $slug, 'data_carried' => $carried];
        if ($this->lastWarning !== '') $out['warning'] = $this->lastWarning;
        return $out;
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
        $isRoot     = !empty($p['is_root']);
        $inst = Bean::load('instance', $instanceId);
        if (!$inst->id) return ['ok' => false, 'error' => 'No such instance', 'code' => 404];
        if ((int) $inst->memberId !== $memberId && !$isRoot) return ['ok' => false, 'error' => 'Not your instance', 'code' => 403];
        if (!empty($inst->isDefault)) return ['ok' => false, 'error' => 'The (default) core instance cannot be deleted here.', 'code' => 403];

        $slug = (string) $inst->slug;
        if (!preg_match(self::SLUG_RE, $slug)) return ['ok' => false, 'error' => 'Invalid instance slug', 'code' => 400];
        $domain = $this->confirmPhrase($slug);
        if (!hash_equals($domain, trim((string) ($p['confirm'] ?? ''))))
            return ['ok' => false, 'error' => 'Confirmation does not match — type "' . $domain . '" exactly.', 'code' => 400];

        $dir = $this->instanceDir($slug);
        // Deliberately recomputed INLINE rather than through instanceDir(): this is the
        // guard standing in front of an rm -rf, and a guard that calls the thing it is
        // guarding cannot catch that thing being wrong.
        if ($dir !== '/var/www/html/default/' . $slug . '.' . $this->appNamespace() || strpos(basename($dir), '.') === false)
            return ['ok' => false, 'error' => 'Refusing to delete: path failed validation', 'code' => 400];

        $steps = [];
        $sock = $dir . '/.aibuilder/tmux.sock';
        if (@file_exists($sock)) { @exec('tmux -S ' . escapeshellarg($sock) . ' kill-server 2>&1'); $steps[] = 'killed jailed session'; }

        // No connector cleanup here any more: the connections live in the instance's
        // own data/connections.db, sealed with its own secure/connections.key, and
        // both are inside $dir. Archiving the directory takes them with it — and
        // keeps them recoverable from the tombstone, which deleting rows here did not.

        if (is_dir($dir)) {
            $res = $this->archiveInstance($dir, $slug);   // wipes the dir (incl. its workbench.db) → tombstone zip
            if (!$res['ok']) return ['ok' => false, 'error' => 'Archive failed: ' . $res['error'], 'code' => 500];
            $steps[] = $res['message'];
        } else { $steps[] = 'folder already absent'; }

        // Clean core's task records for this instance (stale copies + sessions + /projects clones).
        $tasks = Bean::find('workbenchtask', 'instance_id = ?', [$instanceId]);
        if ($tasks) {
            $killed = 0; $wiped = 0;
            foreach ($tasks as $t) {
                $sessions = [(string) $t->agentSession, (string) $t->tmuxSession];
                if (empty($t->parentTaskId)) $sessions[] = 'tiknix-plan' . (int) $t->id . '-orchestrator';
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

    /** Archive an instance folder to public/slug.zip (secrets neutralized), then wipe. */
    private function archiveInstance(string $dir, string $slug): array {
        foreach (glob($dir . '/conf/*.ini') ?: [] as $ini) {
            if (substr($ini, -12) === '.example.ini') continue;
            $example = substr($ini, 0, -4) . '.example.ini';
            if (is_file($example)) @copy($example, $ini); else @unlink($ini);
        }
        $tmpZip = sys_get_temp_dir() . '/' . $slug . '-' . date('Ymd-His') . '.zip';
        @unlink($tmpZip);
        $cmd = 'cd ' . escapeshellarg($dir) . ' && zip -r -q ' . escapeshellarg($tmpZip) . " . -x 'vendor/*' 'node_modules/*' '.git/*'";
        $out = []; $code = 0; @exec($cmd . ' 2>&1', $out, $code);
        if (!is_file($tmpZip)) return ['ok' => false, 'error' => 'zip produced no archive: ' . implode(' ', array_slice($out, -2))];
        @exec('rm -rf ' . escapeshellarg($dir) . ' 2>&1');
        if (!@mkdir($dir . '/public', 0775, true) && !is_dir($dir . '/public'))
            return ['ok' => false, 'error' => 'could not recreate public/ (archive kept at ' . $tmpZip . ')'];
        $dest = $dir . '/public/' . $slug . '.zip';
        if (!@rename($tmpZip, $dest)) { @copy($tmpZip, $dest); @unlink($tmpZip); }
        @chmod($dest, 0644);
        $kb = (int) round((@filesize($dest) ?: 0) / 1024);
        return ['ok' => true, 'message' => 'archived to public/' . $slug . '.zip (' . $kb . ' KB)'];
    }
}
