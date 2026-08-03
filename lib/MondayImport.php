<?php
/**
 * monday.com → workbench tasks, and finished work back again.
 *
 * The shape of this is decided by what a real board actually contains. On the
 * account this was built against, items are PHASE HEADINGS — "1_Discovery &
 * Requirements" in a group called "Parts Website" — with no description column
 * and no updates. Six populated fields, all of them Status, Priority, dates and
 * people.
 *
 * So the brief is the item's NAME plus its group and board, and the columns are
 * context rather than content. That is worth being explicit about: a
 * decomposition of "1_Discovery & Requirements" can only ever expand a phase
 * name using the project around it. It cannot know anything the item does not
 * say, and pretending otherwise by burying the name in a paragraph of invented
 * detail would make the planner look confident about work nobody described.
 *
 * The connection lives on the INSTANCE, not core (the choice made for Telegram):
 * whoever pasted the token owns it, so a self-hosted instance works the same way
 * as a hosted one and nothing has to route through a control plane.
 *
 * Tasks are written to the instance's own data/workbench.db, which is where the
 * workbench sidecar reads them.
 */

namespace app;

use \RedBeanPHP\R;

class MondayImport {

    /** Named RedBean connection for an instance's workbench db. */
    private const WB_KEY = 'wbimport';

    /** Columns worth carrying onto the task. Anything else is board bookkeeping. */
    private const CONTEXT_COLUMNS = ['Status', 'Priority', 'Due Date', 'Timeline',
                                     'Project Phase', 'Estimated Hours', 'Owner'];

    // ---- the connection ---------------------------------------------------------------

    /**
     * The monday connection to use, or null.
     *
     * Own connection first, then the platform's. Both are real: an instance that
     * was connected from within itself — or a self-hosted one — holds its own row
     * and never needs core. But the ordinary way to connect anything here is core's
     * Connections hub, which stores the row in CORE scoped to an instance_id, and
     * that is where the partsdna connection actually lives.
     *
     * Looking locally first is what keeps the ejection story true: a self-hosted
     * instance finds its own token, and CoreDb::path() resolves to its own database
     * so the fallback finds nothing rather than reaching across a network. Nothing
     * has to know which deployment it is in.
     *
     * Enabled and unrevoked only, so a revoked token presents as "not connected"
     * rather than as a call that fails later with a stranger error.
     */
    public static function connection(?int $instanceId = null): ?\RedBeanPHP\OODBBean {
        $where = "connector_type = 'monday' AND enabled = 1 AND (revoked_at IS NULL OR revoked_at = 0)";

        // When an instance is named, BOTH lookups must be scoped to it — including
        // the local one. Core's own connections table holds every instance's rows,
        // so an unscoped "local first" running on core returns whichever monday row
        // sorts first and imports one customer's board using another customer's
        // token. Getting this wrong is silent: both calls succeed, against the
        // wrong account.
        if ($instanceId !== null && $instanceId > 0) {
            $local = Bean::findOne('connections', $where . ' AND instance_id = ?', [$instanceId]);
            if ($local && $local->id) return $local;

            // The bean carries its values with it, so reading accessToken after the
            // connection has been switched back is fine.
            return CoreDb::with(function () use ($where, $instanceId) {
                $c = Bean::findOne('connections', $where . ' AND instance_id = ?', [$instanceId]);
                return ($c && $c->id) ? $c : null;
            }, null);
        }

        // No instance context: a standalone or self-hosted install asking about its
        // own connection. A missing connections table returns null rather than
        // raising — fluid mode treats it as no rows.
        $own = Bean::findOne('connections', $where . ' AND (instance_id IS NULL OR instance_id = 0)');
        return ($own && $own->id) ? $own : null;
    }

    /**
     * The usable bot token for a connection.
     *
     * access_token is stored ENCRYPTED (lib/ConnectionStore.php encrypts on write),
     * so handing the column straight to monday gets "Not authenticated" — which
     * reads like a revoked token rather than like a decrypt that never happened.
     * Same treatment as GitHubPublisher and Storebroker.
     */
    private static function token(\RedBeanPHP\OODBBean $conn): string {
        $raw = (string) ($conn->accessToken ?? '');
        if ($raw === '') return '';

        try {
            $plain = EncryptionService::decrypt($raw);
        } catch (\Throwable $e) {
            \Flight::get('log')?->error('MondayImport: could not decrypt the monday token', [
                'connection' => (int) $conn->id, 'err' => $e->getMessage(),
            ]);
            return '';
        }

        // A connection stored before encryption existed would come back unchanged.
        return $plain !== '' ? $plain : $raw;
    }

    private static function connector(): \app\services\connectors\MondayConnector {
        $c = \app\services\connectors\ConnectorRegistry::get('monday');
        if (!$c) throw new \RuntimeException('The monday.com connector is not available.');
        return $c;
    }

    // ---- reading ----------------------------------------------------------------------

    /** Boards to choose from. Subitem boards are already filtered by the connector. */
    public static function boards(?int $instanceId = null): array {
        $conn = self::connection($instanceId);
        if (!$conn) return [];
        return self::connector()->boards(self::token($conn));
    }

    /**
     * Items on a board, each marked with whether it has already been imported.
     *
     * The flag matters more than it looks: these boards carry finished work
     * (Status "Done" on much of the one this was written against), and a list that
     * does not say what is already here invites importing the same phase twice.
     */
    public static function items(string $boardId, int $limit = 50, string $cursor = '', ?int $instanceId = null): array {
        $conn = self::connection($instanceId);
        if (!$conn) return ['items' => [], 'cursor' => ''];

        $page     = self::connector()->items(self::token($conn), $boardId, $limit, $cursor);
        $imported = self::importedEids(array_column($page['items'], 'id'));

        foreach ($page['items'] as &$it) {
            $it['imported']    = isset($imported[$it['id']]);
            $it['task_id']     = $imported[$it['id']] ?? null;
            // Surfaced separately so a picker can grey out finished work without
            // having to know which column title means "done" on this board.
            $it['done']        = strcasecmp(trim((string) ($it['fields']['Status'] ?? '')), 'done') === 0;
        }
        unset($it);

        return $page;
    }

    /** monday item id => workbench task id, for the ones already here. */
    private static function importedEids(array $eids): array {
        if (!$eids) return [];
        return self::withWorkbench(function () use ($eids) {
            $rows = Bean::find('workbenchtask',
                'monday_eid IN (' . Bean::genSlots($eids) . ')', array_values($eids));
            $out = [];
            foreach ($rows as $r) $out[(string) $r->mondayEid] = (int) $r->id;
            return $out;
        }, []);
    }

    // ---- importing --------------------------------------------------------------------

    /**
     * Turn ticked monday items into workbench tasks.
     *
     * ONE parent task per item. The planner decomposes it into children the way it
     * does for any other task — no separate decomposition step here, so imported
     * work goes through exactly the same pipeline as work typed in by hand.
     *
     * Already-imported items are skipped rather than duplicated, and reported, so
     * ticking a whole board twice is harmless.
     *
     * @return array{created: int, skipped: int, task_ids: int[]}
     */
    public static function import(array $items, int $instanceId, string $instanceTag, int $memberId): array {
        $conn = self::connection($instanceId);
        if (!$conn) throw new \RuntimeException('No monday.com connection for this instance.');

        $existing = self::importedEids(array_column($items, 'id'));
        $created = 0; $skipped = 0; $ids = [];

        foreach ($items as $it) {
            $eid = (string) ($it['id'] ?? '');
            if ($eid === '') continue;
            if (isset($existing[$eid])) { $skipped++; continue; }

            $id = self::withWorkbench(function () use ($it, $eid, $conn, $instanceId, $instanceTag, $memberId) {
                $now  = date('Y-m-d H:i:s');
                $task = Bean::dispense('workbenchtask');

                $task->title       = (string) ($it['name'] ?? 'Untitled monday item');
                $task->description = self::brief($it);
                $task->taskType    = 'feature';
                $task->priority    = self::priority((string) ($it['fields']['Priority'] ?? ''));
                $task->status      = 'pending';
                $task->instanceId  = $instanceId;
                $task->instanceTag = $instanceTag;
                $task->memberId    = $memberId;

                // The link back. _eid because these are the far end's string ids —
                // monday's, not RedBean foreign keys. See CLAUDE.md.
                $task->mondayEid      = $eid;
                $task->mondayBoardEid = (string) ($it['board_id'] ?? '');
                $task->connectionRef  = (int) $conn->id;
                $task->mondayUrl      = (string) ($it['url'] ?? '');
                $task->postedBackAt   = null;

                $task->createdAt = $now;
                $task->updatedAt = $now;
                return (int) Bean::store($task);
            }, 0);

            if ($id > 0) { $created++; $ids[] = $id; }
        }

        return ['created' => $created, 'skipped' => $skipped, 'task_ids' => $ids];
    }

    /**
     * What the planner is actually given.
     *
     * Deliberately plain. The item name IS the brief on these boards, so this
     * states it and lists the context columns underneath rather than inventing
     * prose around it — a planner handed a confident-sounding paragraph would
     * decompose the invention, not the work.
     */
    public static function brief(array $it): string {
        $lines = [];
        $lines[] = trim((string) ($it['name'] ?? ''));

        $where = array_filter([
            (string) ($it['group'] ?? ''),
            (string) ($it['board'] ?? ''),
        ]);
        if ($where) $lines[] = 'Project: ' . implode(' — ', $where);

        $ctx = [];
        foreach (self::CONTEXT_COLUMNS as $col) {
            $v = trim((string) (($it['fields'][$col] ?? '')));
            if ($v !== '') $ctx[] = $col . ': ' . $v;
        }
        if ($ctx) {
            $lines[] = '';
            $lines[] = implode("\n", $ctx);
        }

        // Only when the board actually has prose. On boards that do, this is the
        // real brief and everything above is context — the ordering flips, which is
        // why it is appended rather than merged in.
        foreach (($it['fields'] ?? []) as $col => $val) {
            if (in_array($col, self::CONTEXT_COLUMNS, true)) continue;
            if (mb_strlen((string) $val) < 60) continue;   // a value, not a description
            $lines[] = '';
            $lines[] = $col . ':';
            $lines[] = (string) $val;
        }

        $lines[] = '';
        $lines[] = 'Imported from monday.com item ' . (string) ($it['id'] ?? '') . '.';

        return trim(implode("\n", $lines));
    }

    /** monday's Priority text onto the numeric priority the workbench uses. */
    private static function priority(string $text): int {
        return match (strtolower(trim($text))) {
            'critical', 'urgent' => 1,
            'high'               => 2,
            'medium'             => 3,
            'low'                => 4,
            default              => 3,
        };
    }

    // ---- posting back -----------------------------------------------------------------

    /**
     * Send one imported task's finished decomposition back to its monday item.
     *
     * Manual, per item — nothing reaches a client's board without somebody asking.
     * Subitems plus a summary update, which is what postCompletion does.
     *
     * Refuses when nothing is finished: an empty post-back is noise on somebody
     * else's board, and "there is nothing to send yet" is a better answer than a
     * comment saying so.
     *
     * @return array{posted: bool, subitems: int, failed: int, reason: string}
     */
    public static function postBack(int $taskId, ?int $instanceId = null): array {
        $conn = self::connection($instanceId);
        if (!$conn) return ['posted' => false, 'subitems' => 0, 'failed' => 0,
                            'reason' => 'No monday.com connection on this instance.'];

        $data = self::withWorkbench(function () use ($taskId) {
            $task = Bean::load('workbenchtask', $taskId);
            if (!$task->id || !$task->mondayEid) return null;

            $children = Bean::find('workbenchtask',
                'parent_task_id = ? AND status = ? ORDER BY id', [$taskId, 'completed']);

            return [
                'eid'      => (string) $task->mondayEid,
                'title'    => (string) $task->title,
                'posted'   => (string) ($task->postedBackAt ?? ''),
                'children' => array_map(fn($c) => [
                    'title'        => (string) $c->title,
                    'completed_at' => (string) ($c->completedAt ?? ''),
                ], array_values($children)),
            ];
        }, null);

        if (!$data)               return ['posted' => false, 'subitems' => 0, 'failed' => 0, 'reason' => 'Task not found, or it did not come from monday.'];
        if (!$data['children'])   return ['posted' => false, 'subitems' => 0, 'failed' => 0, 'reason' => 'No completed tasks to post yet.'];

        $res = self::connector()->postCompletion(
            self::token($conn),
            $data['eid'],
            $data['children'],
            'Completed in tiknix: ' . $data['title']
        );

        self::withWorkbench(function () use ($taskId) {
            $t = Bean::load('workbenchtask', $taskId);
            if ($t->id) { $t->postedBackAt = date('Y-m-d H:i:s'); Bean::store($t); }
            return true;
        }, false);

        return ['posted' => true, 'subitems' => $res['subitems'], 'failed' => $res['failed'],
                'reason' => $res['failed'] ? $res['failed'] . ' subitem(s) failed; see the log.' : ''];
    }

    // ---- the workbench database -------------------------------------------------------

    /**
     * Run against this instance's data/workbench.db, then put the connection back.
     *
     * Mirrors app\CoreDb::with — same reasoning, different database. Never throws:
     * a workbench db that is missing means the workbench has not been used on this
     * instance yet, which is a normal state and not a reason to fail the caller.
     */
    private static function withWorkbench(callable $fn, $onError = null) {
        $db = dirname(__DIR__) . '/data/workbench.db';
        if (!is_file($db)) {
            \Flight::get('log')?->warning('MondayImport: no workbench db at ' . $db);
            return $onError;
        }

        $restore = R::hasDatabase('default') ? 'default' : null;
        try {
            if (!R::hasDatabase(self::WB_KEY)) R::addDatabase(self::WB_KEY, 'sqlite:' . $db);
            R::selectDatabase(self::WB_KEY);
            return $fn();
        } catch (\Throwable $e) {
            \Flight::get('log')?->error('MondayImport: workbench db operation failed', ['err' => $e->getMessage()]);
            return $onError;
        } finally {
            if ($restore) R::selectDatabase($restore);
        }
    }
}
