<?php
/**
 * TenantRun — builder work that runs IN a project's container, in a tmux session THERE.
 *
 * The control plane starts it and checks on it; it never holds it up. (It used to: a tmux
 * session on core ran `tenant.php --task`, one SSH call that lasted the whole task, so a core
 * restart or a dropped connection killed the agent mid-edit.) Each run has a directory in the
 * app:
 *
 *   /srv/app/.aibuilder/runs/<id>/input        the brief / request (stdin of the command)
 *   /srv/app/.aibuilder/runs/<id>/result.json  the command's stdout (clitool's JSON answer)
 *   /srv/app/.aibuilder/runs/<id>/output.log   its stderr
 *   /srv/app/.aibuilder/runs/<id>/exit         its exit code — written last: the run is over
 *
 * and a tmux session (named by the caller) running `php scripts/clitool.php <args>` there.
 * start / alive / result / capture / kill / list are each one short SSH call (TenantHost::ssh,
 * as the app user). A container that cannot be reached is an exception naming it, never
 * "not running" — reaping a live task because SSH blinked would discard its work.
 */
namespace app;

final class TenantRun {
    public const DIR = '/srv/app/.aibuilder/runs';
    private const ID_RE = '/^[a-z0-9][a-z0-9-]{0,80}$/';
    private const SESSION_RE = '/^[A-Za-z0-9][A-Za-z0-9_.-]{0,120}$/';

    /**
     * Start `clitool $clitoolArgs` in session $session, stdin from $input; throws when it cannot.
     * $author (TenantHost::author) is who every commit the run makes is by — [] for a run that
     * commits nothing (a plan, an audit).
     */
    public static function start(object $inst, string $session, string $id, string $clitoolArgs, ?string $input, array $author): void {
        self::check($session, $id);
        $dir = self::DIR . '/' . $id;
        if (self::alive($inst, $session)) throw new \RuntimeException("{$inst->slug} already has a session {$session} running");
        [$c, $o] = TenantHost::ssh($inst, 'app', 'mkdir -p ' . escapeshellarg($dir) . ' && cd ' . escapeshellarg($dir)
            . ' && rm -f result.json output.log exit && cat > input', (string) $input, 60);
        if ($c !== 0) throw new \RuntimeException("could not write the run's input in {$inst->slug}'s container: " . trim((string) $o));
        $run = TenantHost::gitEnv($author) . 'php scripts/clitool.php ' . $clitoolArgs
             . ' < ' . escapeshellarg("{$dir}/input") . ' > ' . escapeshellarg("{$dir}/result.json")
             . ' 2> ' . escapeshellarg("{$dir}/output.log") . '; echo $? > ' . escapeshellarg("{$dir}/exit");
        [$c, $o] = TenantHost::ssh($inst, 'app', 'tmux new-session -d -s ' . escapeshellarg($session) . ' -c /srv/app ' . escapeshellarg($run), null, 30);
        if ($c !== 0) throw new \RuntimeException("could not start {$session} in {$inst->slug}'s container: " . trim((string) $o));
    }

    /** Is the session there? Throws when the container cannot be asked. */
    public static function alive(object $inst, string $session): bool {
        if (!preg_match(self::SESSION_RE, $session)) return false;
        [$c, $o] = TenantHost::ssh($inst, 'app', 'tmux has-session -t ' . escapeshellarg('=' . $session) . ' 2>/dev/null && echo yes || echo no', null, 20);
        $a = trim((string) $o);
        if ($c !== 0 || !in_array($a, ['yes', 'no'], true)) throw new \RuntimeException("cannot ask {$inst->slug}'s container about {$session}: " . trim((string) $o));
        return $a === 'yes';
    }

    /**
     * The run's outcome: null while it has not finished (no exit file yet); else
     * ['exit' => int, 'result' => array|null (the JSON it printed), 'log' => tail of stderr].
     */
    public static function result(object $inst, string $id): ?array {
        self::check('x', $id);
        $dir = self::DIR . '/' . $id;
        [$c, $o] = TenantHost::ssh($inst, 'app', 'cd ' . escapeshellarg($dir) . ' 2>/dev/null || { echo NODIR; exit 0; }; '
            . '[ -f exit ] || { echo RUNNING; exit 0; }; echo "EXIT $(cat exit)"; echo ---LOG---; tail -c 3000 output.log 2>/dev/null; echo; echo ---RESULT---; cat result.json 2>/dev/null', null, 30);
        if ($c !== 0) throw new \RuntimeException("cannot read run {$id} in {$inst->slug}'s container: " . trim((string) $o));
        $o = (string) $o;
        if (str_starts_with($o, 'NODIR')) return ['exit' => -1, 'result' => null, 'log' => "no run {$id} in the container"];
        if (str_starts_with($o, 'RUNNING')) return null;
        preg_match('/^EXIT (-?\d+)/', $o, $m);
        $log = trim((string) strstr(substr($o, (int) strpos($o, '---LOG---') + 9), '---RESULT---', true));
        $raw = (string) substr($o, (int) strpos($o, '---RESULT---') + 12);
        $json = ($p = strpos($raw, '{')) !== false ? json_decode(substr($raw, $p), true) : null;
        return ['exit' => (int) ($m[1] ?? -1), 'result' => is_array($json) ? $json : null, 'log' => $log];
    }

    /** The session's screen (last $lines lines). Throws when the container cannot be asked. */
    public static function capture(object $inst, string $session, int $lines = 100): string {
        if (!preg_match(self::SESSION_RE, $session)) return '';
        [$c, $o] = TenantHost::ssh($inst, 'app', 'tmux capture-pane -p -J -S -' . max(1, $lines) . ' -t ' . escapeshellarg('=' . $session . ':') . ' 2>/dev/null || true', null, 20);
        if ($c !== 0) throw new \RuntimeException("cannot read {$session} in {$inst->slug}'s container: " . trim((string) $o));
        return (string) $o;
    }

    /**
     * What the agents are DOING right now, readable: the tail of each run's live transcript.
     *
     * A task's agent runs headless (its output arrives when it ends), but it writes its session
     * as it goes — <home>/.claude/projects/<worktree path, non-alphanumerics as dashes>/<id>.jsonl.
     * This reads the newest transcript per run id IN the container (one ssh for all of them; the
     * reader is a few lines of PHP sent on stdin, so no app needs a newer runtime for it) and
     * returns, per id, the last $lines events as text: what the agent said, each tool it called
     * with the telling part of its input, and any tool error. File contents and tool output are
     * not included — this is a progress view, not a dump.
     *
     * @param string[] $ids run ids (plan-1-task-2, board-7)
     * @return array<string,array{lines:string[],at:string,found:bool}>
     */
    public static function activity(object $inst, array $ids, int $lines = 40): array {
        $ids = array_values(array_filter($ids, fn($i) => preg_match('/^[a-z0-9][a-z0-9-]{0,80}$/', (string) $i)));
        if (!$ids) return [];
        [$c, $o] = TenantHost::ssh($inst, 'app', 'php /dev/stdin ' . max(1, min(200, $lines)) . ' ' . implode(' ', array_map('escapeshellarg', $ids)), self::ACTIVITY_READER, 30);
        $d = json_decode((string) $o, true);
        if ($c !== 0 || !is_array($d)) throw new \RuntimeException("cannot read the agents' activity in {$inst->slug}'s container: " . mb_substr(trim((string) $o), 0, 200));
        return $d;
    }

    /** Runs in the container (PHP on stdin): args = <lines> <run id>… → JSON {id: {lines, at, found}}. */
    private const ACTIVITY_READER = <<<'PHP'
<?php
$n = (int) $argv[1]; $out = [];
foreach (array_slice($argv, 2) as $id) {
    $dir = '/srv/app/.aibuilder/home/.claude/projects/' . preg_replace('/[^A-Za-z0-9]/', '-', "/srv/app/.aibuilder/wt/{$id}");
    $files = glob($dir . '/*.jsonl') ?: [];
    usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
    if (!$files) { $out[$id] = ['lines' => [], 'at' => '', 'found' => false]; continue; }
    $f = $files[0]; $size = filesize($f); $h = fopen($f, 'r');
    if ($size > 3000000) { fseek($h, $size - 3000000); fgets($h); }   // the tail; drop the partial first line
    $lines = [];
    $short = fn($s, $m = 180) => mb_strlen($s = trim(preg_replace('/\s+/', ' ', (string) $s))) > $m ? mb_substr($s, 0, $m) . '…' : $s;
    while (($l = fgets($h)) !== false) {
        $j = json_decode($l, true); if (!is_array($j)) continue;
        $t = isset($j['timestamp']) ? date('H:i:s', strtotime($j['timestamp'])) : '';
        $content = $j['message']['content'] ?? null; if (!is_array($content)) continue;
        foreach ($content as $c) {
            if (!is_array($c)) continue;
            if (($j['type'] ?? '') === 'assistant' && ($c['type'] ?? '') === 'text' && trim((string) $c['text']) !== '') $lines[] = "[{$t}] " . $short($c['text'], 400);
            elseif (($c['type'] ?? '') === 'tool_use') {
                $in = (array) ($c['input'] ?? []); $what = '';
                foreach (['description', 'command', 'file_path', 'path', 'pattern', 'query', 'url', 'prompt', 'skill'] as $k) if (!empty($in[$k]) && is_scalar($in[$k])) { $what = $short($in[$k]); break; }
                $lines[] = "[{$t}]   → " . ($c['name'] ?? 'tool') . ($what !== '' ? ': ' . $what : '');
            } elseif (($c['type'] ?? '') === 'tool_result' && !empty($c['is_error'])) {
                $r = $c['content'] ?? ''; if (is_array($r)) $r = implode(' ', array_map(fn($x) => is_array($x) ? (string) ($x['text'] ?? '') : (string) $x, $r));
                $lines[] = "[{$t}]   ✗ " . $short($r, 240);
            }
        }
    }
    fclose($h);
    $out[$id] = ['lines' => array_slice($lines, -$n), 'at' => date('c', filemtime($f)), 'found' => true];
}
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
PHP;

    /**
     * End the session AND every process under it. Absent is fine; unreachable throws.
     *
     * Killing the tmux session alone hangs up only the pane's process group — and the agent is not
     * in it: clitool runs it under `timeout`, which puts its child in a group of its own. The agent
     * then kept working with no session and no branch (the proof run's stopped task #7). So the
     * pane's whole tree is collected first, sent TERM, then KILL for whatever is still up.
     */
    public static function kill(object $inst, string $session): void {
        if (!preg_match(self::SESSION_RE, $session)) return;
        $t = escapeshellarg('=' . $session);
        // list-panes takes a SESSION target (display-message wants a pane, and `=name` is not one —
        // it answered nothing and the tree was never found).
        $script = 'pid=$(tmux list-panes -t ' . $t . " -F '#{pane_pid}' 2>/dev/null | head -1); "
                . 'if [ -n "$pid" ]; then '
                . 'tree() { for c in $(pgrep -P "$1"); do tree "$c"; echo "$c"; done; }; '
                . 'all="$(tree "$pid") $pid"; kill -TERM $all 2>/dev/null; sleep 2; kill -KILL $all 2>/dev/null; '
                . 'fi; tmux kill-session -t ' . $t . ' 2>/dev/null; true';
        [$c, $o] = TenantHost::ssh($inst, 'app', $script, null, 30);
        if ($c !== 0) throw new \RuntimeException("cannot stop {$session} in {$inst->slug}'s container: " . trim((string) $o));
    }

    /** @return string[] the container's tmux sessions whose name starts with $prefix */
    public static function list(object $inst, string $prefix = ''): array {
        [$c, $o] = TenantHost::ssh($inst, 'app', "tmux list-sessions -F '#{session_name}' 2>/dev/null; true", null, 20);
        if ($c !== 0) throw new \RuntimeException("cannot list {$inst->slug}'s container sessions: " . trim((string) $o));
        return array_values(array_filter(array_map('trim', explode("\n", (string) $o)), fn($s) => $s !== '' && str_starts_with($s, $prefix)));
    }

    private static function check(string $session, string $id): void {
        if (!preg_match(self::SESSION_RE, $session)) throw new \InvalidArgumentException("not a session name: {$session}");
        if (!preg_match(self::ID_RE, $id)) throw new \InvalidArgumentException("not a run id: {$id}");
    }
}
