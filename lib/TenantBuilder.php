<?php
/**
 * TenantBuilder — the builder's side of a project that lives in its own container
 * (RUNTIME-SPLIT-MAP.md step 5, "the builder in containers").
 *
 * The app is in the container; the builder's RECORDS stay on core, in the project's
 * workspace (Model_Instance::dirOf → _workspaces/<slug>): the task board (data/workbench.db),
 * plans, logs, the runner scripts. Work runs in the container through tenant.php over SSH —
 * a task (clitool --agent-task), a plan (--agent-plan) — inside a detached tmux session, so
 * the callers keep their contract: a live session is a running task; when it has gone, the
 * result is the JSON file the session wrote.
 *
 * The agent in the container runs on the APP's own credential (AgentTask; the owner's call,
 * 2026-09-30): no credential leaves core for a container, and an app with none refuses the
 * task, naming the fix.
 */

namespace app;

class TenantBuilder {

    /** What the workspace keeps from a host clone's .aibuilder/ (history, not machinery). */
    private const HISTORY = ['*.log', '*.json', '*.md', '*.txt', 'engine', 'plans'];

    /** The instance row when $slug lives in its own container, else null (core's registry, from any process). */
    public static function bySlug(string $slug): ?object {
        $inst = CoreDb::with(fn() => Bean::findOne('instance', 'slug = ?', [$slug]));
        return ($inst && $inst->id && \Model_Instance::tenantRow($inst)) ? $inst : null;
    }

    /** The project's workspace, created when missing. */
    public static function workspace(object $inst): string {
        if (!\Model_Instance::tenantRow($inst)) throw new \RuntimeException("{$inst->slug} does not live in its own container");
        $ws = \Model_Instance::dirOf($inst);
        foreach (['', '/data', '/.aibuilder'] as $d) {
            if (!is_dir($ws . $d) && !@mkdir($ws . $d, 0775, true)) throw new \RuntimeException("could not create {$ws}{$d}");
        }
        // The audit's browser (it verifies the running site over its public URL, from here).
        if (!is_file("{$ws}/.mcp.json")) {
            file_put_contents("{$ws}/.mcp.json", json_encode(['mcpServers' => ['playwright' => [
                'command' => 'npx', 'args' => ['-y', '@playwright/mcp@latest', '--headless', '--isolated', '--no-sandbox'],
            ]]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        }
        return $ws;
    }

    /**
     * The host clone's builder history into the workspace, once: its task board (a consistent
     * SQLite backup) and .aibuilder's logs, plans, audit results and seed lists. Its worktrees,
     * agent state, sockets and runner scripts stay behind. A workspace that already has a task
     * board is left alone — its own history is newer than the clone's.
     */
    public static function adoptHistory(object $inst): array {
        $ws = self::workspace($inst);
        $host = \Model_Instance::dirFrom((string) $inst->slug, (string) ($inst->app ?: \Model_Instance::DEFAULT_APP));
        if (is_file("{$ws}/data/workbench.db")) return ['ok' => true, 'step' => "{$ws} already has its task board: nothing adopted"];
        if (!is_dir($host)) return ['ok' => true, 'step' => "no host clone at {$host}: a fresh workspace"];
        $steps = [];
        if (is_file("{$host}/data/workbench.db")) {
            $src = new \SQLite3("{$host}/data/workbench.db", SQLITE3_OPEN_READONLY);
            $dst = new \SQLite3("{$ws}/data/workbench.db");
            if (!$src->backup($dst)) return ['ok' => false, 'error' => "SQLite backup of {$host}/data/workbench.db failed: " . $src->lastErrorMsg()];
            $src->close(); $dst->close();
            $pdo = new \PDO("sqlite:{$ws}/data/workbench.db");
            $has = (bool) $pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'workbenchtask'")->fetchColumn();
            $steps[] = $has ? 'task board: ' . (int) $pdo->query('SELECT COUNT(*) FROM workbenchtask')->fetchColumn() . ' task(s)' : 'task board: never used';
        }
        $copied = 0;
        foreach (self::HISTORY as $pat) {
            foreach (glob("{$host}/.aibuilder/{$pat}") ?: [] as $f) {
                $to = "{$ws}/.aibuilder/" . basename($f);
                exec('cp -a ' . escapeshellarg($f) . ' ' . escapeshellarg($to) . ' 2>&1', $o, $c);
                if ($c !== 0) return ['ok' => false, 'error' => "could not copy {$f}: " . implode(' ', $o)];
                $copied++;
            }
        }
        $steps[] = "{$copied} history file(s) from .aibuilder/";
        return ['ok' => true, 'step' => "adopted from {$host}: " . implode(', ', $steps)];
    }

    /**
     * Start $command in a detached tmux session named $session, from the workspace. The
     * command writes its own result file; the session ending is the signal that it did.
     */
    public static function launch(object $inst, string $session, string $command, string $label): void {
        $ws = self::workspace($inst);
        $script = "{$ws}/.aibuilder/run-{$label}.sh";
        $body = "#!/bin/bash\n# {$label} for {$inst->slug}, in its container (lib/TenantBuilder.php)\n"
              . "cd " . escapeshellarg(Paths::root()) . "\n"
              . $command . "\n";
        if (file_put_contents($script, $body) === false) throw new \RuntimeException("could not write {$script}");
        @chmod($script, 0755);
        if (!TmuxManager::create($session, $script, $ws)) throw new \RuntimeException("could not start the tmux session {$session}");
    }

    /** tenant.php's command line for one verb, with the prompt/request from a file. */
    public static function tenantCommand(object $inst, string $verb, string $id, string $inputFile, string $outFile, array $extra = []): string {
        $args = ['--' . $verb . '=' . $inst->slug, '--id=' . $id, '--out=' . $outFile];
        foreach ($extra as $k => $v) $args[] = "--{$k}={$v}";
        // Never with the task board as its database: a builder process carries
        // TIKNIX_WORKBENCH_DB, and tenant.php booted on it finds no instance registry.
        return 'env -u TIKNIX_WORKBENCH_DB php ' . escapeshellarg(Paths::root() . '/scripts/tenant.php') . ' '
             . implode(' ', array_map('escapeshellarg', $args)) . ' < ' . escapeshellarg($inputFile);
    }

    /**
     * The app's code as it runs — its container's HEAD (merge is publish) — as a directory
     * on core, for an export driver (rsync) to ship from: `git archive` over SSH, extracted
     * into a temp dir that is its own git repository, so Publish\Snapshot lists it exactly
     * as it lists a host clone (tracked files, minus what is ours or secret). The caller
     * removes it with removeExport(). Throws when the container does not answer.
     */
    public static function exportTree(object $inst): string {
        $dir = sys_get_temp_dir() . '/tiknix-export-' . $inst->slug . '-' . bin2hex(random_bytes(4));
        if (!@mkdir($dir, 0700, true)) throw new \RuntimeException("could not create {$dir}");
        $tar = $dir . '.tar';
        try {
            [$code, $out] = TenantHost::ssh($inst, 'app', 'cd /srv/app && git archive --format=tar HEAD > /tmp/export.tar && base64 -w0 /tmp/export.tar; rm -f /tmp/export.tar', null, 300);
            if ($code !== 0 || trim($out) === '') throw new \RuntimeException("{$inst->slug}'s container gave no code (exit {$code}): " . mb_substr(trim($out), 0, 200));
            $bytes = base64_decode(trim($out), true);
            if ($bytes === false || file_put_contents($tar, $bytes) === false) throw new \RuntimeException("{$inst->slug}'s code came back unreadable");
            exec('tar -xf ' . escapeshellarg($tar) . ' -C ' . escapeshellarg($dir) . ' 2>&1 && env -u GIT_DIR -u GIT_INDEX_FILE -u GIT_WORK_TREE git -C ' . escapeshellarg($dir) . ' init -q 2>&1', $o, $c);
            if ($c !== 0) throw new \RuntimeException('could not unpack the code: ' . implode(' ', $o));
            return $dir;
        } catch (\Throwable $e) {
            self::removeExport($dir);
            throw $e;
        } finally {
            @unlink($tar);
        }
    }

    public static function removeExport(string $dir): void {
        if (str_starts_with($dir, sys_get_temp_dir() . '/tiknix-export-') && is_dir($dir)) exec('rm -rf ' . escapeshellarg($dir));
    }

    /**
     * What the builder's agent picker offers for a project in its own container: the app's
     * Claude account state and its agents, from the app (clitool --agents; no keys leave it).
     * Throws when the container does not answer: an empty list would read as "no agents".
     */
    public static function agents(object $inst): array {
        [$code, $out] = TenantHost::ssh($inst, 'app', 'cd /srv/app && php scripts/clitool.php --agents', null, 60);
        $d = json_decode((string) $out, true);
        if ($code !== 0 || !is_array($d) || !isset($d['agents'])) {
            throw new \RuntimeException("{$inst->slug}'s container did not list its agents (exit {$code}): " . mb_substr(trim((string) $out), 0, 300));
        }
        return $d;
    }

    /**
     * The audit's answer (tenant.php --audit --out) into the workspace: the manifest where
     * plan-audit.php reads it, and the screenshots — which the control plane's browser saved
     * HERE (its --output-dir is the workspace) — copied into the app, where the report links them
     * (public/uploads/audit/<plan>/). Returns the exit code (0 = a manifest was delivered).
     */
    public static function unpackAudit(string $outFile, string $manifestFile, string $slug, string $ws, int $planId): int {
        $r = self::result($outFile);
        if ($r === null) { echo "[audit] ERROR no answer from the container ({$outFile})\n"; return 1; }
        if (empty($r['ok']) || (string) ($r['manifest'] ?? '') === '') {
            echo '[audit] ERROR the audit gave no manifest: ' . ($r['error'] ?? '?') . "\n";
            if (!empty($r['output'])) echo "[audit] agent output (tail):\n" . mb_substr((string) $r['output'], -1500) . "\n";
            return 1;
        }
        // The browser saved each screenshot under its own name in the shots dir; the agent records
        // them as best it can. Point every entry at the file that is really there (by basename);
        // one that is not is dropped and counted, never linked to a missing image.
        $m = json_decode((string) $r['manifest'], true);
        if (!is_array($m)) { echo "[audit] ERROR the manifest is not JSON\n"; return 1; }
        $shotsRel = "public/uploads/audit/{$planId}";
        $dropped = 0;
        $fix = static function (array $screens) use ($ws, $shotsRel, &$dropped): array {
            $out = [];
            foreach ($screens as $p) {
                $b = basename((string) $p);
                if ($b !== '' && is_file("{$ws}/{$shotsRel}/{$b}")) $out[] = "{$shotsRel}/{$b}"; else $dropped++;
            }
            return $out;
        };
        foreach (['levels', 'checks', 'failures'] as $k) {
            foreach (($m[$k] ?? []) as $i => $entry) {
                if (is_array($entry) && isset($entry['screens']) && is_array($entry['screens'])) $m[$k][$i]['screens'] = $fix($entry['screens']);
            }
        }
        if ($dropped) echo "[audit] {$dropped} screenshot reference(s) named no file the browser saved — dropped\n";
        $r['manifest'] = json_encode($m, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (file_put_contents($manifestFile . '.tmp', (string) $r['manifest']) === false || !rename($manifestFile . '.tmp', $manifestFile)) {
            echo "[audit] ERROR could not write {$manifestFile}\n"; return 1;
        }
        echo '[audit] manifest delivered' . (!empty($r['credential']) ? ' (ran on ' . $r['credential'] . ')' : '') . "\n";
        $shots = "{$ws}/public/uploads/audit/{$planId}";
        if (!is_dir($shots)) { echo "[audit] no screenshots were taken\n"; return 0; }
        $inst = self::bySlug($slug);
        $tar = tempnam(sys_get_temp_dir(), 'audit-shots-');
        // Images only: the browser also writes its console logs into this folder, and the app serves
        // public/ — those stay here, with the audit.
        $pngs = array_map(fn($f) => $planId . '/' . basename($f), glob("{$shots}/*.png") ?: []);
        if (!$pngs) { echo "[audit] no screenshots were taken\n"; return 0; }
        exec('tar czf ' . escapeshellarg($tar) . ' -C ' . escapeshellarg("{$ws}/public/uploads/audit") . ' ' . implode(' ', array_map('escapeshellarg', $pngs)) . ' 2>&1', $o, $c);
        [$sc, $so] = $c === 0 && $inst
            ? TenantHost::ssh($inst, 'app', 'mkdir -p /srv/app/public/uploads/audit && tar xzf - -C /srv/app/public/uploads/audit', null, 120, $tar)
            : [1, $c !== 0 ? implode(' ', $o) : "no project {$slug}"];
        @unlink($tar);
        $n = count(glob("{$shots}/*.png") ?: []);
        echo $sc === 0 ? "[audit] {$n} screenshot(s) copied into the app (public/uploads/audit/{$planId})\n"
                       : "[audit] ERROR the screenshots stay on core ({$shots}); copying them into the app failed: " . trim((string) $so) . "\n";
        return 0;
    }

    /** The JSON a finished session wrote, or null when there is none (the session died first). */
    public static function result(string $outFile): ?array {
        if (!is_file($outFile)) return null;
        $d = json_decode((string) file_get_contents($outFile), true);
        return is_array($d) ? $d : null;
    }

    /**
     * The codebase inventory the planner's and the builder's briefs embed — computed IN THE
     * CONTAINER (the Introspector behind the MCP tools, over /srv/app), since the app's code is
     * there and nowhere on core.
     */
    public static function digest(object $inst): string {
        [$code, $out] = TenantHost::ssh($inst, 'app', 'cd /srv/app && php -d error_reporting=0 -r '
            . escapeshellarg('require "vendor/autoload.php"; require "vendor/tiknix/runtime/mcptools/Introspector.php"; echo (new app\mcptools\Introspector("/srv/app"))->digest();'), null, 120);
        if ($code !== 0 || trim($out) === '') {
            error_log("ERROR TenantBuilder::digest: {$inst->slug}'s container gave no codebase inventory (exit {$code}): " . mb_substr(trim($out), 0, 300));
            return '_(codebase inventory unavailable: the container did not answer — see the log)_';
        }
        return $out;
    }

    /** The first runtime release whose clitool can describe its app (--architecture). */
    public const ARCHITECTURE_SINCE = 'v2.0.0-alpha.91';

    /**
     * The app as the Architecture Explorer draws it, asked of the app itself (the runtime's
     * `clitool --architecture…`, mcptools/Architecture): its code and its database are in the
     * container and nowhere on core.
     *
     *   'hash'   {hash}   a fingerprint that changes when the model would — what a cached model is kept under
     *   'model'  the model: permissions by controller, tables, the call/render graph
     *   'rows'   a page of $table's rows from $offset, credential columns withheld
     *
     * Throws when the container does not answer with it — and says so when the reason is an
     * app on a runtime too old to have the command, with the fix. Never an empty model: that
     * would read as "this app has no code".
     */
    public static function architecture(object $inst, string $what = 'model', string $table = '', int $offset = 0): array {
        $arg = match ($what) {
            'hash'  => '--architecture=hash',
            'model' => '--architecture',
            'rows'  => '--architecture-rows=' . escapeshellarg($table) . ' --offset=' . max(0, $offset),
            default => throw new \InvalidArgumentException("architecture(): 'hash', 'model' or 'rows', not '{$what}'"),
        };
        [$code, $out] = TenantHost::ssh($inst, 'app', 'cd /srv/app && php -d error_reporting=0 scripts/clitool.php ' . $arg, null, 120);
        $d = json_decode((string) $out, true);
        if ($code === 0 && is_array($d)) return $d;

        $said = mb_substr(trim((string) $out), 0, 300);
        // An older runtime's clitool does not know the option: it prints its usage, not JSON.
        $why = !str_contains((string) $out, 'error:') && stripos((string) $out, 'architecture') === false
            ? "its runtime is older than " . self::ARCHITECTURE_SINCE . ", the first that can describe its app — update it (in the app: php scripts/clitool.php --update)"
            : "it answered (exit {$code}): {$said}";
        error_log("ERROR TenantBuilder::architecture({$what}): {$inst->slug}: {$why}" . ($said !== '' ? " — output: {$said}" : ''));
        throw new \RuntimeException("{$inst->slug} could not describe itself: {$why}");
    }

    /**
     * The planner's result (tenant.php --plan --out) into the workspace, where the planner's
     * script and plan-ingest look: <member>-<time>-<rand>.plan.json, or plan-complete.md when
     * the agent found the goal already built. Returns the process exit code (0 = something
     * was delivered).
     */
    public static function unpackPlan(string $outFile, string $abDir, string $slug, int $memberId, string $agent = ''): int {
        $r = self::result($outFile);
        if ($r === null) { echo "[planner] the container planner left no result ({$outFile})\n"; return 1; }
        if (!empty($r['credential'])) echo "[planner] ran in {$slug}'s container on {$r['credential']}\n";
        if (($r['status'] ?? '') === 'complete') {
            file_put_contents("{$abDir}/plan-complete.md", (string) ($r['complete'] ?? ''));
            echo "[planner] the agent judged the goal already built (plan-complete.md)\n";
            return 0;
        }
        if (($r['status'] ?? '') !== 'planned') {
            echo '[planner] no plan: ' . ($r['status'] ?? '?') . ' — ' . ($r['error'] ?? '') . "\n";
            if (!empty($r['output'])) echo "[planner] agent output (tail):\n" . mb_substr((string) $r['output'], -2000) . "\n";
            return 1;
        }
        $plan = json_decode((string) $r['plan'], true);
        if (!is_array($plan)) { echo "[planner] the plan is not JSON\n"; return 1; }
        $plan['instance'] = $slug;   // submit_plan stamped the container's throwaway worktree
        $plan['agent']    = $agent;  // the agent it was planned on; its tasks run on it too (PlanIngestor)
        $file = "{$abDir}/{$memberId}-" . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(4)), 0, 6) . '.plan.json';
        if (file_put_contents($file, json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false) { echo "[planner] could not write {$file}\n"; return 1; }
        echo '[planner] plan received from the container: "' . ($plan['title'] ?? '?') . '" (' . count($plan['subtasks'] ?? []) . " task(s))\n";
        return 0;
    }
}
