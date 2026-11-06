<?php
/**
 * Session — the queue between whoever drives (an agent, a script, the MCP tool) and the browser that plays the steps
 * (a person's open runner page, /browserdrive). Beans:
 *   drivesession  path (where it starts), note, status open → claimed → closed | expired, opened_by (member who opened it),
 *                 runner (member whose page claimed it), opened_at, claimed_at, seen_at (the runner's last call), closed_at, why
 *   drivestep     one command: n, cmd (JSON), status queued → sent → done | failed, result (JSON), timestamps; ownDrivestepList
 * Only paths of this site that the admins allowed (the setting browserdrive_allow) can be opened or gone to: a driven
 * session acts as the person whose browser runs it, so it must only ever touch sandbox pages and data.
 * Two things keep that true. A session is played only in the tab of the member who opened it, and only they can send it steps:
 * another admin's key never acts as you, whatever level you are. And script (evaluate, waitFor with js) is off until an admin
 * switches it on (the setting browserdrive_scripts): a script in a page of this site can reach every other page, whatever the list says.
 */

namespace app\concepts\browserdrive;

use app\Bean;

class Session {

    public const SETTING = 'browserdrive_allow';
    public const SCRIPTS = 'browserdrive_scripts';
    /** Each command and the arguments it takes; anything else is refused before it reaches a browser. */
    public const COMMANDS = [
        'goto' => ['path'], 'reload' => [], 'back' => [], 'viewport' => ['width', 'height'],
        'click' => ['target', 'button', 'count'], 'dblclick' => ['target'], 'hover' => ['target'],
        'fill' => ['target', 'value'], 'type' => ['target', 'text', 'delay'], 'press' => ['key', 'target', 'hold'],
        'check' => ['target'], 'uncheck' => ['target'], 'select' => ['target', 'value'],
        'drag' => ['from', 'to', 'steps'], 'mouse' => ['action', 'x', 'y', 'button'], 'wheel' => ['x', 'y', 'dx', 'dy'],
        'waitFor' => ['target', 'text', 'js', 'state', 'timeout'], 'wait' => ['ms'],
        'evaluate' => ['js', 'arg'], 'text' => ['target'], 'value' => ['target'], 'attr' => ['target', 'name'],
        'count' => ['target'], 'html' => ['target'], 'url' => [], 'title' => [], 'screenshot' => ['target'], 'console' => [],
    ];
    public const CLAIM_WAIT = 300;   // s an open session waits for a runner before it expires
    public const RUNNER_GONE = 90;   // s without a call from its runner before a claimed session expires
    public const IDLE = 900;         // s without a step before a session expires

    // ---- the allowed paths ----
    /** @return string[] path patterns: "/demo" (exactly), "/demo/*" (it and anything under it) */
    public static function allowed(): array {
        $raw = (string) (\Flight::getSetting(self::SETTING, 0) ?? '');
        return array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', $raw)), fn($p) => $p !== ''));
    }
    public static function setAllowed(string $raw): array {
        $list = array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', $raw)), fn($p) => $p !== ''));
        foreach ($list as $p) if (!preg_match('#^/[A-Za-z0-9_\-./]*\*?$#', $p)) throw new \InvalidArgumentException("'{$p}' is not a path pattern: a path of this site starting with /, optionally ending in *");
        \Flight::setSetting(self::SETTING, implode("\n", $list), 0);
        return $list;
    }
    /** The path part only, checked against the allowed patterns. Throws naming why. */
    public static function checkPath(string $path): string { return self::matchPath($path, self::allowed()); }
    /** checkPath against a given list (no database: what the tests call). */
    public static function matchPath(string $path, array $allow): string {
        $path = trim($path);
        if ($path === '' || $path[0] !== '/' || str_starts_with($path, '//') || preg_match('#[\s\\\\]|^/[^?]*://#', $path)) throw new \InvalidArgumentException("'{$path}' is not a path of this site (it starts with a single /)");
        $only = (string) parse_url('http://x' . $path, PHP_URL_PATH);
        if (str_contains($only, '..')) throw new \InvalidArgumentException("'{$path}' climbs out with ..");
        if (!$allow) throw new \RuntimeException('browserdrive: no path is allowed yet. An admin lists the sandbox pages a session may open on /browserdrive (the setting ' . self::SETTING . ')');
        foreach ($allow as $p) { $base = rtrim(substr($p, 0, -1), '/'); if (str_ends_with($p, '*') ? ($only === $base || str_starts_with($only, $base . '/')) : $only === $p) return $path; } // /x/* is /x and what is under it, never /xy
        throw new \InvalidArgumentException("'{$only}' is not an allowed path. Allowed: " . implode(', ', $allow));
    }

    // ---- script in a driven page: off until an admin switches it on ----
    public static function scriptsOn(): bool { return (string) (\Flight::getSetting(self::SCRIPTS, 0) ?? '') === '1'; }
    public static function setScripts(bool $on): void { \Flight::setSetting(self::SCRIPTS, $on ? '1' : '0', 0); }
    /** Refuses a command that runs script while it is off (no database: what the tests call). */
    public static function checkScript(array $cmd, bool $on): void {
        if ($on || !(($cmd['do'] ?? '') === 'evaluate' || isset($cmd['js']))) return;
        throw new \InvalidArgumentException(($cmd['do'] ?? '') . ' with script is off on this site: script runs as the person whose tab plays it and can reach every page, not only the sandbox ones. An admin can switch it on at /browserdrive.');
    }

    // ---- this address's tables ----
    private static bool $ready = false;
    /**
     * Every address of an app has its own database, and a plugin's seeds run where it was enabled, not on an address added later.
     * Without the tables a claim fails and a step reads as "no step", so the first use on an address sets them up (the seeds are idempotent).
     */
    public static function ready(): void {
        if (self::$ready) return;
        $has = fn() => array_key_exists('runner', Bean::inspect('drivesession')) && array_key_exists('claimed_at', Bean::inspect('drivesession')) && array_key_exists('done_at', Bean::inspect('drivestep')) && array_key_exists('sent_at', Bean::inspect('drivestep'));
        if (!$has()) {
            ob_start();
            try { foreach (glob(dirname(__DIR__) . '/seeds/*.php') as $seed) (static function (string $seed): void { require $seed; })($seed); }
            finally { ob_end_clean(); }
            if (!$has()) throw new \RuntimeException('browserdrive: this address has no session tables and setting them up failed. Run: TIKNIX_HOST=<this address> php scripts/clitool.php --concept-seeds=browserdrive');
        }
        self::$ready = true;
    }

    // ---- the driving side ----
    public static function open(int $memberId, string $path, string $note = ''): object {
        self::ready();
        self::expire();
        $s = Bean::dispense('drivesession');
        $s->path = self::checkPath($path); $s->note = mb_substr(trim($note), 0, 300); $s->status = 'open'; $s->openedBy = $memberId;
        $s->openedAt = date('Y-m-d H:i:s'); $s->seenAt = null; $s->steps = 0;
        Bean::store($s);
        return $s;
    }
    /** A session the member may drive: only their own. It plays in their tab, so nobody else's key may send it steps, whatever their level. */
    public static function mine(int $sessionId, object $member): object {
        $s = Bean::load('drivesession', $sessionId);
        if (!$s->id) throw new \InvalidArgumentException("no session {$sessionId}");
        if ((int) $s->openedBy !== (int) $member->id) throw new \InvalidArgumentException("session {$sessionId} is not yours");
        return $s;
    }
    public static function clean(array $cmd): array {
        $name = (string) ($cmd['do'] ?? ''); if (!isset(self::COMMANDS[$name])) throw new \InvalidArgumentException("unknown command '{$name}'. Commands: " . implode(', ', array_keys(self::COMMANDS)));
        $out = ['do' => $name];
        foreach ($cmd as $k => $v) { if ($k === 'do') continue; if (!in_array($k, self::COMMANDS[$name], true)) throw new \InvalidArgumentException("{$name} has no argument '{$k}' (it takes: " . (implode(', ', self::COMMANDS[$name]) ?: 'none') . ')'); $out[$k] = $v; }
        if ($name === 'goto') $out['path'] = self::checkPath((string) ($out['path'] ?? ''));
        if (in_array($name, ['evaluate'], true) && (!is_string($out['js'] ?? null) || $out['js'] === '' || strlen($out['js']) > 20000)) throw new \InvalidArgumentException('evaluate needs js: the body of a function, at most 20000 characters');
        return $out;
    }
    public static function step(object $session, array $cmd): object {
        self::expire();
        if (!in_array($session->status, ['open', 'claimed'], true)) throw new \RuntimeException("session {$session->id} is {$session->status}" . ($session->why ? ": {$session->why}" : ''));
        $c = self::clean($cmd);
        self::checkScript($c, self::scriptsOn());
        $st = Bean::dispense('drivestep'); $st->n = (int) $session->steps + 1; $st->cmd = json_encode($c, JSON_UNESCAPED_SLASHES); $st->status = 'queued'; $st->queuedAt = date('Y-m-d H:i:s');
        $session->steps = $st->n; $session->ownDrivestepList[] = $st; Bean::store($session);
        return $st;
    }
    /** Waits up to $secs for the step's result; null when it is not done yet (ask again). */
    public static function wait(int $stepId, int $secs): ?array {
        $until = microtime(true) + max(0, min(60, $secs)); $checked = microtime(true);
        do {
            if (microtime(true) - $checked > 5) { self::expire(); $checked = microtime(true); } // a runner that went away ends the session, so this says so
            // The runner's answer arrives from another request, and a loop that only reads would be served the first answer
            // from the query caches again and again (they clear on this process's own writes). A parameter that changes on
            // every pass makes each read a new query, so it goes to the database.
            $row = Bean::getRow('SELECT st.id, st.n, st.cmd, st.status, st.result, se.status AS session, se.why FROM drivestep st JOIN drivesession se ON se.id = st.drivesession_id WHERE st.id = ? AND ? > 0', [$stepId, (int) (microtime(true) * 1000)]);
            if (!$row) throw new \InvalidArgumentException("no step {$stepId}");
            $view = ['step' => (int) $row['id'], 'n' => (int) $row['n'], 'status' => (string) $row['status'], 'cmd' => json_decode((string) $row['cmd'], true), 'result' => $row['result'] !== null ? json_decode((string) $row['result'], true) : null];
            if (in_array($row['status'], ['done', 'failed'], true)) return $view;
            if (in_array($row['session'], ['closed', 'expired'], true)) return $view + ['session' => (string) $row['session'], 'why' => (string) $row['why']];
            usleep(250000);
        } while (microtime(true) < $until);
        return null;
    }
    public static function view(object $st): array {
        return ['step' => (int) $st->id, 'n' => (int) $st->n, 'status' => (string) $st->status, 'cmd' => json_decode((string) $st->cmd, true), 'result' => $st->result !== null ? json_decode((string) $st->result, true) : null];
    }
    public static function close(object $s, string $why): void {
        if (in_array($s->status, ['closed', 'expired'], true)) return;
        $s->status = 'closed'; $s->why = mb_substr($why, 0, 200); $s->closedAt = date('Y-m-d H:i:s'); Bean::store($s);
        foreach ($s->withCondition(' status IN (?, ?) ', ['queued', 'sent'])->ownDrivestepList as $st) { $st->status = 'failed'; $st->result = json_encode(['error' => 'the session was closed: ' . $s->why]); Bean::store($st); }
    }

    // ---- the runner side (a person's page) ----
    /** The oldest open session THIS member opened, now this runner's; null when none waits. A session never plays in someone else's tab. Atomic: two runners never take the same one. */
    public static function claim(int $runnerId): ?object {
        self::ready();
        self::expire();
        // this runner's page was reloaded mid-session: it takes its own session back, and the step it was playing is played again
        $tick = (int) (microtime(true) * 1000);
        $mine = Bean::getRow('SELECT id FROM drivesession WHERE status = ? AND runner = ? AND seen_at < ? AND ? > 0 ORDER BY id LIMIT 1', ['claimed', $runnerId, date('Y-m-d H:i:s', time() - 5), $tick]);
        if ($mine) {
            Bean::exec('UPDATE drivestep SET status = ?, sent_at = NULL WHERE drivesession_id = ? AND status = ?', ['queued', (int) $mine['id'], 'sent']);
            Bean::exec('UPDATE drivesession SET seen_at = ? WHERE id = ?', [date('Y-m-d H:i:s'), (int) $mine['id']]);
            return Bean::load('drivesession', (int) $mine['id']);
        }
        foreach (Bean::getAll('SELECT id FROM drivesession WHERE status = ? AND opened_by = ? AND ? > 0 ORDER BY id', ['open', $runnerId, $tick]) as $row) { $s = (object) ['id' => (int) $row['id']]; // a new query each time: the query cache could hide a session opened elsewhere for a minute (see wait)
            $n = Bean::exec('UPDATE drivesession SET status = ?, runner = ?, claimed_at = ?, seen_at = ? WHERE id = ? AND status = ?', ['claimed', $runnerId, date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), (int) $s->id, 'open']);
            if ($n === 1) return Bean::load('drivesession', (int) $s->id);
        }
        return null;
    }
    /** The runner's next step to play in this session, now marked sent; waits up to $secs for one. */
    public static function next(object $s, int $runnerId, int $secs): ?array {
        if ((int) $s->runner !== $runnerId || $s->status !== 'claimed') throw new \RuntimeException("session {$s->id} is not claimed by this page (it is {$s->status})");
        $until = microtime(true) + max(0, min(25, $secs));
        do {
            Bean::exec('UPDATE drivesession SET seen_at = ? WHERE id = ?', [date('Y-m-d H:i:s'), (int) $s->id]);
            $tick = (int) (microtime(true) * 1000); // a new query each pass: the query caches would otherwise answer "no step" for a while (see wait)
            $row = Bean::getRow('SELECT status, why FROM drivesession WHERE id = ? AND ? > 0', [(int) $s->id, $tick]); if (($row['status'] ?? '') !== 'claimed') return ['closed' => true, 'why' => (string) ($row['why'] ?? '')];
            $st = Bean::getRow('SELECT id, n, cmd FROM drivestep WHERE drivesession_id = ? AND status = ? AND ? > 0 ORDER BY n LIMIT 1', [(int) $s->id, 'queued', $tick]);
            if ($st && Bean::exec('UPDATE drivestep SET status = ?, sent_at = ? WHERE id = ? AND status = ?', ['sent', date('Y-m-d H:i:s'), (int) $st['id'], 'queued']) === 1) return ['step' => (int) $st['id'], 'n' => (int) $st['n'], 'cmd' => json_decode((string) $st['cmd'], true)];
            usleep(250000);
        } while (microtime(true) < $until);
        return null;
    }
    public static function done(object $s, int $runnerId, int $stepId, array $result): void {
        if ((int) $s->runner !== $runnerId) throw new \RuntimeException("session {$s->id} is not claimed by this page");
        $st = Bean::load('drivestep', $stepId); if (!$st->id || (int) $st->drivesessionId !== (int) $s->id) throw new \InvalidArgumentException("no step {$stepId} in session {$s->id}");
        if ($st->status !== 'sent') throw new \RuntimeException("step {$stepId} is {$st->status}, not waiting for a result");
        $json = json_encode($result, JSON_UNESCAPED_SLASHES); if ($json === false || strlen($json) > 8_000_000) throw new \InvalidArgumentException('a step result is JSON under 8 MB');
        $st->status = empty($result['error']) ? 'done' : 'failed'; $st->result = $json; $st->doneAt = date('Y-m-d H:i:s'); Bean::store($st);
        // a click can follow a link out of the sandbox: the page is checked after every step, and a session that left it ends
        $url = (string) ($result['url'] ?? ''); if ($url !== '' && $url !== 'about:blank') { try { self::checkPath($url); } catch (\InvalidArgumentException $e) { self::close($s, "step {$st->n} left the allowed pages for {$url}; nothing more plays there"); } }
    }

    /** Sessions nobody claimed, whose runner went away, or that sat idle are ended, with why. */
    public static function expire(): void {
        $now = time();
        foreach (Bean::find('drivesession', ' status IN (?, ?) ', ['open', 'claimed']) as $s) {
            $why = '';
            if ($s->status === 'open' && $now - strtotime((string) $s->openedAt) > self::CLAIM_WAIT) $why = 'no runner page claimed it within ' . self::CLAIM_WAIT . ' s: the member whose key opened it does not have /browserdrive open';
            elseif ($s->status === 'claimed' && $now - strtotime((string) $s->seenAt) > self::RUNNER_GONE) $why = 'its runner page stopped answering (closed, or the computer slept)';
            else { $last = Bean::findOne('drivestep', ' drivesession_id = ? ORDER BY n DESC ', [(int) $s->id]); $t = $last ? strtotime((string) $last->queuedAt) : strtotime((string) $s->openedAt); if ($now - $t > self::IDLE) $why = 'no step for ' . self::IDLE . ' s'; }
            if ($why !== '') { self::close($s, $why); $s->status = 'expired'; Bean::store($s); }
        }
    }
}
