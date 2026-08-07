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
    /**
     * A connection supplied by the caller, used in preference to looking one up.
     *
     * The workbench sidecar reaches core through Sidecar\Kernel::coreDb(), not
     * through app\CoreDb — it has its own database and its own idea of where core
     * is. Rather than teach this class both routes, a caller that already knows how
     * to reach core hands the row over and this stops guessing.
     */
    private static ?\RedBeanPHP\OODBBean $injected = null;

    public static function setConnection(?\RedBeanPHP\OODBBean $conn): void {
        self::$injected = $conn;
    }

    public static function connection(?int $instanceId = null): ?\RedBeanPHP\OODBBean {
        if (self::$injected && self::$injected->id) return self::$injected;

        // The instance owns its connectors, so there is one place to look and no
        // fallback to a platform copy — there is no platform copy any more.
        if ($instanceId !== null && $instanceId > 0) {
            return ConnectionStore::forInstall($instanceId, 'monday');
        }

        // No instance named means no connection. There is deliberately no fallback:
        // a connector belongs to an instance and travels with it, so "look somewhere
        // else" would be a way for one NOT to travel — and the somewhere else was
        // core￢ﾀﾙs old shared table, which is exactly what this move retired.
        return null;
    }

    /**
     * A token the caller already holds in the clear.
     *
     * The workbench sidecar gets one from core over the broker channel, because the
     * decryption key stays in core. Passing it here rather than writing it onto the
     * connection bean keeps ConnectionStore::token() honest: that function's job is
     * to decrypt, and handing it something already decrypted would make it fail.
     */
    private static string $plainToken = '';

    public static function setToken(string $token): void {
        self::$plainToken = $token;
    }

    /** Decryption lives in ConnectionStore — every consumer needs the same answer. */
    private static function token(\RedBeanPHP\OODBBean $conn): string {
        return self::$plainToken !== '' ? self::$plainToken : ConnectionStore::token($conn);
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
            [$it['status'], $it['status_column']] = self::pickStatus($it['statuses'] ?? []);

            // Two different questions, and both have to say "build this".
            //
            // `state` is monday's own lifecycle field — active / archived / deleted
            // — not free text, so it is the more trustworthy of the two. It reads
            // `active` for everything today because items_page excludes the others
            // by default, which makes this guard dead code and worth having anyway:
            // the day that default changes, or a query_params is added to page
            // through an archive, archived work would otherwise arrive looking
            // exactly like open work.
            //
            // The status COLUMN is the one that needed the work: it is whatever a
            // board owner typed, which is why it takes a type lookup to find and
            // word matching to read.
            $state             = strtolower(trim((string) ($it['state'] ?? 'active')));
            $it['archived']    = $state !== '' && $state !== 'active';
            $it['done']        = $it['archived'] || self::isClosed($it['status']);

            // Each subitem judged the same way, so the picker can count what would
            // actually come in rather than how many exist.
            foreach (($it['subitems'] ?? []) as &$sub) {
                [$sub['status']] = self::pickStatus($sub['statuses'] ?? []);
                $ss = strtolower(trim((string) ($sub['state'] ?? 'active')));
                $sub['closed'] = ($ss !== '' && $ss !== 'active') || self::isClosed($sub['status']);
            }
            unset($sub);
        }
        unset($it);

        return $page;
    }

    /**
     * Statuses that mean "there is no work to build here".
     *
     * Both spellings of cancelled, because monday takes whatever the board owner
     * typed and a British board would otherwise import its abandoned work — which
     * is what happened before this existed: only "Done" was recognised, so
     * Cancelled items looked like open work and the select-all ticked them.
     *
     * Cancelled is a STRONGER signal than done: finished work might reasonably be
     * imported for reference, whereas work somebody called off is work nobody
     * decided to do. Both are excluded from the bulk select and both stay
     * individually tickable, because "ignore by default" is a different promise
     * from "refuse", and only the first one is ours to make.
     */
    private const CLOSED_STATUSES = ['done', 'complete', 'completed', 'finished',
                                     'cancelled', 'canceled', 'closed'];

    /**
     * Which of an item's status-type columns is THE status, and its value.
     *
     * There is no "status" field in monday's API — only columns of type `status`,
     * and a board has several. The Manufacturing Transfer board has two, `Status`
     * and `Priority`, so taking the first status-typed column would read a priority
     * of "High" as the state of the work. Taking the column titled "Status" is no
     * better on its own: a board is free to call it State, Progress or Phase.
     *
     * So: a title that looks like a state wins; failing that, a lone status column
     * is unambiguous and is used; and when there are several with no recognisable
     * title we return NOTHING rather than pick one. An item whose status we cannot
     * identify is treated as open, which errs toward offering work rather than
     * hiding it — the reverse would silently drop items off the picker with no way
     * to tell why.
     *
     * @return array{0:string,1:string} [value, column title]
     */
    private static function pickStatus(array $statuses): array {
        if (!$statuses) return ['', ''];

        foreach (['status', 'state', 'progress', 'stage', 'phase'] as $want) {
            foreach ($statuses as $title => $value) {
                if (strcasecmp(trim((string) $title), $want) === 0) return [(string) $value, (string) $title];
            }
        }

        if (count($statuses) === 1) {
            $title = (string) array_key_first($statuses);
            return [(string) $statuses[$title], $title];
        }

        return ['', ''];   // several, none named like a state — do not guess
    }

    /**
     * True when a monday status means the item is not work to build.
     *
     * Matched as a WHOLE WORD anywhere in the value, not as the whole value,
     * because boards write compounds: this account's Creative Pipeline uses
     * "Completed/Live" for seventeen items, and an exact-match list read every one
     * of them as open work. The word boundary keeps "Not Started" from matching on
     * a stray substring.
     *
     * A leading negation disqualifies it outright — "Not Complete" contains
     * "complete" and means the opposite. Cheap to check, and the failure it
     * prevents is offering to build finished work or hiding live work.
     */
    public static function isClosed(string $status): bool {
        $s = strtolower(trim($status));
        if ($s === '') return false;
        if (preg_match('/\b(not|un|isn\'t|no)\b/', $s)) return false;

        return (bool) preg_match('/\b(' . implode('|', self::CLOSED_STATUSES) . ')\b/', $s);
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
        $created = 0; $skipped = 0; $subs = 0; $files = 0; $ids = [];

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

            if ($id > 0) {
                $created++; $ids[] = $id;
                $files += self::storeAssets($it, $id, $instanceId);
                $subs  += self::importSubitems($it, $id, $conn, $instanceId, $instanceTag, $memberId, $existing);
            }
        }

        return ['created' => $created, 'skipped' => $skipped, 'subitems' => $subs,
                'files' => $files, 'task_ids' => $ids];
    }

    /**
     * Re-check imported tasks against monday, and FLAG what has changed.
     *
     * The picker stops closed work being imported; nothing until now noticed work
     * that closed AFTER it was imported, which is the common case — a board moves
     * on while a task sits in the queue.
     *
     * It flags and never deletes, deliberately. Declining to import something is a
     * small promise; removing a task somebody may already have worked on because a
     * board changed is a much larger one, and it is not ours to make. The flag is
     * what a person acts on.
     *
     * Three outcomes are recorded, and they are kept distinct because they want
     * different reactions:
     *   closed  — monday says done/cancelled/archived; probably drop it
     *   open    — was flagged, has since reopened; the flag is cleared
     *   missing — the item is no longer in monday at all (deleted, or the token
     *             lost access to that board). NOT treated as closed: "I cannot see
     *             it" and "it is finished" are different, and only one of them is
     *             a reason to stop work.
     *
     * Content is pulled in the SAME pass. It used to be a second button making the
     * identical call over the identical rows and writing the other half of the
     * answer, which asked a person to guess which of two things they wanted when
     * the honest answer is always both: whether the work is still wanted, and
     * whether it still says what it said.
     *
     * @return array{checked:int,closed:int,reopened:int,missing:int,updated:int,flagged:array,changes:array}
     */
    public static function refresh(?int $instanceId = null): array {
        $conn = self::connection($instanceId);
        if (!$conn) throw new \RuntimeException('No monday.com connection for this instance.');

        $tasks = self::withWorkbench(
            fn() => Bean::find('workbenchtask', "monday_eid IS NOT NULL AND monday_eid != '' ORDER BY id"), []);
        if (!$tasks) return ['checked' => 0, 'closed' => 0, 'reopened' => 0, 'missing' => 0,
                             'updated' => 0, 'files' => 0, 'flagged' => [], 'changes' => []];

        $byEid = [];
        foreach ($tasks as $t) $byEid[(string) $t->mondayEid] = $t;

        $live = self::connector()->itemsById(self::token($conn), array_keys($byEid));

        $out = ['checked' => 0, 'closed' => 0, 'reopened' => 0, 'missing' => 0,
                'updated' => 0, 'files' => 0, 'flagged' => [], 'changes' => []];
        $now = date('Y-m-d H:i:s');

        foreach ($byEid as $eid => $task) {
            $out['checked']++;
            $seen = $live[$eid] ?? null;

            if ($seen === null) {
                // Not returned at all: the token lost sight of it. Different from
                // deleted, which monday DOES return, tagged state=deleted.
                $status = ''; $state = ''; $closed = false; $missing = true;
                $out['missing']++;
            } else {
                [$status] = self::pickStatus($seen['statuses'] ?? []);
                $state    = strtolower(trim((string) ($seen['state'] ?? 'active')));
                $closed   = ($state !== '' && $state !== 'active') || self::isClosed($status);
                $missing  = false;
            }

            $was = (int) ($task->mondayClosed ?? 0) === 1;
            if ($closed && !$was)      $out['closed']++;
            elseif (!$closed && $was)  $out['reopened']++;

            if ($closed || $missing) {
                // The REASON, not the leftover status text. A deleted item keeps
                // whatever status it had when it went — reporting "Ready to Start"
                // for something in monday's recycle bin is a true field and a
                // false answer.
                if ($missing) {
                    $reason = 'no longer visible to this connection';
                } elseif ($state !== '' && $state !== 'active') {
                    $reason = $state . ' in monday';
                } else {
                    $reason = $status ?: 'closed';
                }

                $out['flagged'][] = [
                    'task_id' => (int) $task->id,
                    'title'   => (string) $task->title,
                    'status'  => $reason,
                    'missing' => $missing,
                ];
            }

            // The content half. Only the fields a previous import GENERATED — title,
            // brief, priority — never a status, comment or branch, which are the work
            // rather than a copy of monday.
            $diff = [];
            if ($seen !== null) {
                $title = (string) ($seen['name'] ?? '');
                $brief = self::brief($seen);
                $prio  = self::priority((string) ($seen['fields']['Priority'] ?? ''));

                if ($title !== '' && $title !== (string) $task->title) $diff[] = 'title';
                if ($brief !== (string) $task->description)            $diff[] = 'brief';
                if ($prio !== (int) $task->priority)                   $diff[] = 'priority';
                if ($diff) {
                    $out['updated']++;
                    $out['changes'][] = ['task_id' => (int) $task->id, 'title' => $title, 'fields' => $diff];
                }

                // Attachments too, so tasks imported before this existed get their
                // design files, and a file added to a board later arrives without
                // anybody re-importing. Idempotent by size, so this is a stat() per
                // asset on a run where nothing changed rather than a re-download.
                $out['files'] += self::storeAssets($seen, (int) $task->id, $instanceId);
            }

            self::withWorkbench(function () use ($task, $status, $closed, $missing, $now, $seen, $diff) {
                $row = Bean::load('workbenchtask', (int) $task->id);
                if (!$row->id) return false;

                $row->mondayStatus    = $status;
                $row->mondayClosed    = $closed ? 1 : 0;
                $row->mondayMissing   = $missing ? 1 : 0;
                $row->mondayCheckedAt = $now;

                // updated_at only moves when something actually changed, so a sync
                // over a whole board does not read as a board-wide edit.
                if ($diff && $seen !== null) {
                    $t = (string) ($seen['name'] ?? '');
                    if ($t !== '') $row->title = $t;
                    $row->description = self::brief($seen);
                    $row->priority    = self::priority((string) ($seen['fields']['Priority'] ?? ''));
                    if (($seen['url'] ?? '') !== '') $row->mondayUrl = (string) $seen['url'];
                    $row->updatedAt   = $now;
                }

                Bean::store($row);
                return true;
            }, false);
        }

        return $out;
    }

    /**
     * What is worth pulling down. HTML is the specification on these boards — one
     * item attaches a 1.7MB mockup and says "recreate this" — and images are the
     * screenshots beside it. Everything else stays a reference in the brief.
     *
     * An allowlist rather than a blocklist: a board is a place people put whatever
     * they have, and the failure of guessing wrong is a build agent handed a 40MB
     * video or a customer spreadsheet nobody meant to copy.
     */
    private const PULLABLE = ['html', 'htm', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'svg'];

    /** Per file. Large enough for a real page mockup, small enough to notice. */
    private const MAX_ASSET_BYTES = 25 * 1024 * 1024;

    /**
     * Download an item's attachments into the instance, under the task.
     *
     * Into secure/, which is 0700 and gitignored: these are a customer's design
     * files, and public/ would put them on the open web under a guessable path.
     *
     * Files are named <assetId>-<name> because names collide — this very item
     * carries two different screenshots both called image-from-clipboard.png, and
     * writing by name alone would silently leave one of them.
     *
     * Idempotent by size: an asset already on disk at its full length is skipped,
     * so a sync over a board does not re-download every mockup each run. Paths are
     * plain and relative, which is what keeps this working when secure/ becomes a
     * FUSE mount onto S3 rather than local disk.
     *
     * @return array{stored:string[],skipped:int,failed:array}
     */
    public static function pullAssets(array $it, int $taskId, string $installDir, ?int $instanceId = null): array {
        $out = ['stored' => [], 'skipped' => 0, 'failed' => []];

        $wanted = [];
        foreach (($it['assets'] ?? []) as $id => $name) {
            $ext = strtolower(pathinfo((string) $name, PATHINFO_EXTENSION));
            if ($ext !== '' && in_array($ext, self::PULLABLE, true)) $wanted[(string) $id] = (string) $name;
        }
        if (!$wanted) return $out;

        $conn = self::connection($instanceId);
        if (!$conn) throw new \RuntimeException('No monday.com connection for this instance.');

        $dir = rtrim($installDir, '/') . '/secure/monday/task-' . $taskId;
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException('Could not create ' . $dir);
        }
        @chmod(dirname($dir), 0700);

        // public_url is signed for about an hour, so it is fetched NOW rather than
        // read from anything stored. Only for assets actually being downloaded.
        $meta = self::connector()->assets(self::token($conn), array_keys($wanted));

        foreach ($wanted as $id => $name) {
            $info = $meta[$id] ?? null;
            if (!$info || ($info['public_url'] ?? '') === '') {
                $out['failed'][] = $name . ' (monday returned no download url)';
                continue;
            }

            if ((int) $info['size'] > self::MAX_ASSET_BYTES) {
                $out['failed'][] = $name . ' (' . round($info['size'] / 1048576, 1) . 'MB, over the limit)';
                continue;
            }

            $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $name !== '' ? $name : ('asset-' . $id));
            $path = $dir . '/' . $id . '-' . $safe;
            $rel  = 'secure/monday/task-' . $taskId . '/' . $id . '-' . $safe;

            if (is_file($path) && (int) filesize($path) === (int) $info['size']) {
                $out['skipped']++;
                $out['stored'][] = $rel;   // still ours, still listed
                continue;
            }

            $bytes = self::fetchUrl($info['public_url']);
            if ($bytes === null) { $out['failed'][] = $name . ' (download failed)'; continue; }

            // Written to a temp name and moved, so a half-written file is never
            // mistaken for a complete one by the size check above.
            $tmp = $path . '.part';
            if (@file_put_contents($tmp, $bytes) === false || !@rename($tmp, $path)) {
                @unlink($tmp);
                $out['failed'][] = $name . ' (could not be written)';
                continue;
            }
            @chmod($path, 0600);
            $out['stored'][] = $rel;
        }

        return $out;
    }

    /**
     * Pull an item's attachments and record them on the task. Returns how many.
     *
     * Never throws at the caller: a design file that will not download is a worse
     * brief, not a failed import, and losing the whole task because one 1.7MB
     * mockup timed out would be the wrong trade. It logs instead, so the reason is
     * recoverable rather than guessed at.
     */
    private static function storeAssets(array $it, int $taskId, ?int $instanceId): int {
        if (self::$installDir === '' || empty($it['assets'])) return 0;

        try {
            $r = self::pullAssets($it, $taskId, self::$installDir, $instanceId);
        } catch (\Throwable $e) {
            \Flight::get('log')?->error('MondayImport: attachments could not be pulled',
                ['task' => $taskId, 'err' => $e->getMessage()]);
            return 0;
        }

        foreach ($r['failed'] as $f) {
            \Flight::get('log')?->warning('MondayImport: attachment skipped',
                ['task' => $taskId, 'file' => $f]);
        }
        if (!$r['stored']) return 0;

        self::withWorkbench(function () use ($taskId, $r) {
            $row = Bean::load('workbenchtask', $taskId);
            if (!$row->id) return false;
            // Merged with whatever is already listed, and de-duplicated: a person
            // may have added paths of their own, and a sync must not wipe them.
            $have = json_decode((string) ($row->relatedFiles ?: '[]'), true) ?: [];
            $row->relatedFiles = json_encode(array_values(array_unique(array_merge($have, $r['stored']))));
            Bean::store($row);
            return true;
        }, false);

        return count($r['stored']) - $r['skipped'];
    }

    /** A plain GET that returns null rather than a half-answer. */
    private static function fetchUrl(string $url): ?string {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_CONNECTTIMEOUT => 15,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        // No curl_close: it is deprecated in PHP 8.5 and throws in a web handler.
        return ($code === 200 && is_string($body) && $body !== '') ? $body : null;
    }

    /**
     * A monday item's subitems, imported as CHILD tasks of the one just created.
     *
     * This is the whole reason to look at them. The workbench model is one parent
     * task that a planner then decomposes — but where monday already HAS the
     * breakdown, that breakdown is the real one. "2_UX / UI Design Elements" on a
     * live board carries eight subitems: Global Header & Footer, Homepage
     * Development, Brand Landing Page Templates and so on. Asking a planner to
     * invent a decomposition alongside those would discard work somebody did and
     * substitute a guess for it.
     *
     * Closed subitems are skipped for the same reason closed items are not
     * offered: a finished piece of a live phase is not work to build. Each keeps
     * its own monday_eid, so re-importing the parent will not duplicate them and
     * the refresh pass tracks each one separately.
     */
    private static function importSubitems(array $it, int $parentTaskId, \RedBeanPHP\OODBBean $conn,
                                           int $instanceId, string $instanceTag, int $memberId,
                                           array $alreadyImported): int {
        $subs = $it['subitems'] ?? [];
        if (!$subs) return 0;

        $made = 0;
        foreach ($subs as $sub) {
            $eid = (string) ($sub['id'] ?? '');
            if ($eid === '' || isset($alreadyImported[$eid])) continue;

            $state = strtolower(trim((string) ($sub['state'] ?? 'active')));
            if ($state !== '' && $state !== 'active') continue;          // archived / deleted
            [$subStatus] = self::pickStatus($sub['statuses'] ?? []);
            if (self::isClosed($subStatus)) continue;                     // Done / Cancelled

            $id = self::withWorkbench(function () use ($sub, $eid, $it, $parentTaskId, $conn,
                                                       $instanceId, $instanceTag, $memberId) {
                $now  = date('Y-m-d H:i:s');
                $task = Bean::dispense('workbenchtask');

                $task->title       = (string) ($sub['name'] ?? 'Untitled subitem');
                // The parent names the phase this belongs to. Without it a subitem
                // called "Timeline & Milestones" is unattributable on the board.
                $subDesc = trim((string) ($sub['description'] ?? ''));
                $task->description = trim((string) ($sub['name'] ?? ''))
                                   . ($subDesc !== '' ? "\n\n" . $subDesc : '')
                                   . "\n\n" . 'Part of: ' . trim((string) ($it['name'] ?? ''))
                                   . (($it['group'] ?? '') !== '' ? ' (' . $it['group'] . ')' : '');
                $task->taskType    = 'feature';
                $task->priority    = self::priority((string) ($it['fields']['Priority'] ?? ''));
                $task->status      = 'pending';
                $task->instanceId  = $instanceId;
                $task->instanceTag = $instanceTag;
                $task->memberId    = $memberId;
                $task->parentTaskId = $parentTaskId;

                $task->mondayEid      = $eid;
                $task->mondayBoardEid = (string) ($it['board_id'] ?? '');
                $task->connectionRef  = (int) $conn->id;
                $task->postedBackAt   = null;

                $task->createdAt = $now;
                $task->updatedAt = $now;
                return (int) Bean::store($task);
            }, 0);

            if ($id > 0) $made++;
        }

        return $made;
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

        // The item's DESCRIPTION, when it has one, and directly under the name
        // because it is the brief rather than context. This class was written
        // believing these boards had none — they do, on 28 of 45 items and 53 of 80
        // subitems, and it needs API 2025-07 to see them. A planner handed
        // "1_Discovery & Requirements" alone can only expand a heading; handed
        // "Define the Phase 1 functionality, identify out-of-scope features..." it
        // has something real to decompose.
        $desc = trim((string) ($it['description'] ?? ''));
        if ($desc !== '') {
            $lines[] = '';
            $lines[] = $desc;
        }

        // A SUBITEM says what it is part of. Its own board is "Subitems of X" and
        // its group is "Subitems" — a hidden board nobody chose and a group name
        // that says nothing, where the parent phase is the context that matters.
        $parent = trim((string) ($it['parent_name'] ?? ''));
        if ($parent !== '') {
            $lines[] = '';
            $lines[] = 'Part of: ' . $parent;
        } else {
            $where = array_filter([
                (string) ($it['group'] ?? ''),
                (string) ($it['board'] ?? ''),
            ]);
            if ($where) { $lines[] = ''; $lines[] = 'Project: ' . implode(' — ', $where); }
        }

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
    /**
     * Where the workbench tasks live, when this class has to open them itself.
     *
     * The workbench SIDECAR already points RedBean at the right instance's
     * workbench.db before it calls anything here (WorkbenchDb::selectInstance), and
     * this class lives in core's lib — so resolving a path from __DIR__ would give
     * CORE's workbench.db no matter which instance the sidecar is showing. Callers
     * that have already selected the database call useCurrentDatabase() and this
     * stops switching entirely.
     */
    private static ?string $workbenchDb = null;
    private static bool $useCurrent = false;

    /** The caller has already selected the right database; do not switch. */
    public static function useCurrentDatabase(bool $yes = true): void {
        self::$useCurrent = $yes;
    }

    /** Open this workbench.db explicitly (a CLI, or core acting on one instance). */
    public static function setWorkbenchDb(string $path): void {
        self::$workbenchDb = $path;
        self::$useCurrent  = false;
    }

    /**
     * Where this instance lives on disk, so attachments can be written under it.
     *
     * Unset means attachments are NOT downloaded — import and sync still work, and
     * the brief still names every file with its asset id. That is the right
     * default for a caller that has not said where files may be written: guessing
     * a directory to put a customer's design files in is not a guess worth making.
     */
    private static string $installDir = '';

    public static function setInstallDir(string $dir): void {
        self::$installDir = rtrim($dir, '/');
    }

    private static function withWorkbench(callable $fn, $onError = null) {
        // Already pointed at the right place by whoever called us.
        if (self::$useCurrent) {
            try {
                return $fn();
            } catch (\Throwable $e) {
                \Flight::get('log')?->error('MondayImport: workbench operation failed',
                    ['err' => $e->getMessage()]);
                return $onError;
            }
        }

        $db = self::$workbenchDb ?: (dirname(__DIR__) . '/data/workbench.db');
        if (!is_file($db)) {
            \Flight::get('log')?->warning('MondayImport: no workbench db at ' . $db);
            return $onError;
        }

        $restore = Bean::hasDatabase('default') ? 'default' : null;
        try {
            if (!Bean::hasDatabase(self::WB_KEY)) Bean::addDatabase(self::WB_KEY, 'sqlite:' . $db);
            Bean::selectDatabase(self::WB_KEY);
            return $fn();
        } catch (\Throwable $e) {
            \Flight::get('log')?->error('MondayImport: workbench db operation failed', ['err' => $e->getMessage()]);
            return $onError;
        } finally {
            if ($restore) Bean::selectDatabase($restore);
        }
    }
}
