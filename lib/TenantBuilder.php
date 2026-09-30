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

    /** The project's workspace, created when missing. */
    public static function workspace(object $inst): string {
        if (!\Model_Instance::tenantRow($inst)) throw new \RuntimeException("{$inst->slug} does not live in its own container");
        $ws = \Model_Instance::dirOf($inst);
        foreach (['', '/data', '/.aibuilder'] as $d) {
            if (!is_dir($ws . $d) && !@mkdir($ws . $d, 0775, true)) throw new \RuntimeException("could not create {$ws}{$d}");
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
              . "env -u TIKNIX_WORKBENCH_DB " . $command . "\n";
        if (file_put_contents($script, $body) === false) throw new \RuntimeException("could not write {$script}");
        @chmod($script, 0755);
        if (!TmuxManager::create($session, $script, $ws)) throw new \RuntimeException("could not start the tmux session {$session}");
    }

    /** tenant.php's command line for one verb, with the prompt/request from a file. */
    public static function tenantCommand(object $inst, string $verb, string $id, string $inputFile, string $outFile, array $extra = []): string {
        $args = ['--' . $verb . '=' . $inst->slug, '--id=' . $id, '--out=' . $outFile];
        foreach ($extra as $k => $v) $args[] = "--{$k}={$v}";
        return 'php ' . escapeshellarg(Paths::root() . '/scripts/tenant.php') . ' '
             . implode(' ', array_map('escapeshellarg', $args)) . ' < ' . escapeshellarg($inputFile);
    }

    /** The JSON a finished session wrote, or null when there is none (the session died first). */
    public static function result(string $outFile): ?array {
        if (!is_file($outFile)) return null;
        $d = json_decode((string) file_get_contents($outFile), true);
        return is_array($d) ? $d : null;
    }
}
