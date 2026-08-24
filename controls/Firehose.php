<?php
/**
 * Firehose — control-plane error ingest for AI Builder instances.
 *
 * Instances POST uncaught errors here (see lib/ErrorReporter.php). We dedup by
 * signature and, for a NEW error on a published + idle instance, auto-triage it
 * into a fix workspace (Phase 3). The feed UI is Phase 4.
 *
 * Security — two layers, mirroring controls/Mcp.php:
 *   1. Route: firehose::report = 101 (PUBLIC) so instances can reach it.
 *   2. Controller: a shared secret ([firehose] ingest_key) validated per request.
 * Only the CONTROL PLANE sets ingest_key, so an instance clone of this code
 * (which has api_key but no ingest_key) rejects every report — it can't be
 * tricked into ingesting into its own DB.
 *
 * Collision safety (see lib/ErrorReporter.php for the full picture):
 *   Layer 1 origin gate — instances only report when role=live (workspaces muted).
 *   Layer 2 active-build guard + Layer 3 signature dedup — enforced here.
 */

namespace app;

use \Flight as Flight;
use \app\Bean;
use app\BaseControls\Control;

class Firehose extends Control {

    public function __construct() {
        parent::__construct();
    }

    /** GET /firehose — admin feed of detected errors (newest first, 'new' on top). */
    public function index() {
        if (!$this->requireLogin()) return;
        $this->requireBuilderTools('Firehose');
        if ($this->member->level > LEVELS['ADMIN']) {
            $this->flash('error', 'The error firehose is admin-only.');
            Flight::redirect('/dashboard');
            return;
        }

        $rows = Bean::find('detectederror',
            "ORDER BY CASE WHEN status = 'new' THEN 0 ELSE 1 END, last_seen_at DESC LIMIT 300");
        /* The task is on the PROJECT's board, so it is read from there — the same file the
           workbench sidecar opens. Reading core's table here showed a number for tasks that
           no longer live on core and nothing at all for the ones that do. */
        $errors = [];
        $boards = [];   // one handle per instance, not one per row
        foreach ($rows as $e) {
            $task = null;
            $slug = preg_replace('/\.[^.]+$/', '', (string)$e->instanceTag);
            if ((int)$e->taskId > 0 && $slug !== '') {
                if (!array_key_exists($slug, $boards)) {
                    $inst = Bean::findOne('instance', 'slug = ?', [$slug]);
                    $boards[$slug] = ($inst && $inst->id) ? $this->instanceBoard($inst) : null;
                }
                if ($boards[$slug]) {
                    try {
                        $st = $boards[$slug]->prepare('SELECT id, status FROM workbenchtask WHERE id = ?');
                        $st->execute([(int)$e->taskId]);
                        $row = $st->fetch(\PDO::FETCH_ASSOC) ?: null;
                        if ($row) $task = (object)['id' => (int)$row['id'], 'status' => (string)$row['status']];
                    } catch (\Throwable $ex) { $task = null; }
                }
            }
            $errors[] = ['e' => $e, 'task' => $task, 'slug' => $slug];
        }

        $this->viewData['title']  = 'Error Firehose';
        $this->viewData['errors'] = $errors;
        $this->viewData['counts'] = [
            'new'      => (int)Bean::count('detectederror', "status = 'new'"),
            'open'     => (int)Bean::count('detectederror', "status IN ('new','triaged','building','reopened','deferred')"),
            'resolved' => (int)Bean::count('detectederror', "status = 'resolved'"),
        ];
        $this->render('firehose/index', $this->viewData);
    }

    /** POST /firehose/resolve — admin sets a detected error's status (resolved/ignored/new). */
    public function resolve() {
        if (!$this->requireLogin()) return;
        if (Flight::request()->method !== 'POST') { Flight::redirect('/firehose'); return; }
        if (!\app\SimpleCsrf::validate()) { Flight::jsonError('CSRF validation failed', 403); return; }
        if ($this->member->level > LEVELS['ADMIN']) { Flight::jsonError('Admins only', 403); return; }

        $id     = (int)$this->getParam('id');
        $status = (string)$this->getParam('status', 'resolved');
        if (!in_array($status, ['resolved', 'ignored', 'new'], true)) { Flight::jsonError('bad status', 422); return; }

        $e = Bean::load('detectederror', $id);
        if (!$e->id) { Flight::jsonError('not found', 404); return; }
        $e->status    = $status;
        $e->updatedAt = date('Y-m-d H:i:s');
        Bean::store($e);
        Flight::jsonSuccess(['id' => $id, 'status' => $status]);
    }

    /** POST /firehose/report — JSON error ingest. Self-authed by shared key. */
    public function report() {
        $data = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($data)) { Flight::jsonError('invalid payload', 400); return; }

        // Layer-2 auth: shared secret, header preferred, body fallback.
        $provided = $_SERVER['HTTP_X_FIREHOSE_KEY'] ?? ($data['api_key'] ?? '');
        $expected = (string)(Flight::get('firehose.ingest_key') ?? '');
        if ($expected === '' || !hash_equals($expected, (string)$provided)) {
            Flight::jsonError('unauthorized', 401);
            return;
        }

        $sig      = trim((string)($data['signature'] ?? ''));
        $instance = trim((string)($data['instance'] ?? ''));
        if ($sig === '' || $instance === '') {
            Flight::jsonError('missing signature or instance', 422);
            return;
        }

        $now = date('Y-m-d H:i:s');
        $err = Bean::findOne('detectederror', 'signature = ?', [$sig]);

        if ($err && $err->id) {
            // Known signature — bump counters, never duplicate (Layer 3).
            $err->hitCount   = (int)$err->hitCount + 1;
            $err->lastSeenAt = $now;
            // Regression: a previously resolved/ignored error is firing again.
            if (in_array($err->status, ['resolved', 'ignored'], true)) {
                $err->status = 'reopened';
            }
            Bean::store($err);
            Flight::jsonSuccess([
                'id' => (int)$err->id, 'status' => $err->status,
                'hits' => (int)$err->hitCount, 'new' => false,
            ], 'recorded');
            return;
        }

        // New signature — record it.
        $err = Bean::dispense('detectederror');
        $err->signature   = $sig;
        $err->instanceTag = $instance;
        $err->type        = (string)($data['type'] ?? 'exception');
        $err->message     = mb_substr((string)($data['message'] ?? ''), 0, 500);
        $err->fullMessage = mb_substr((string)($data['full_message'] ?? ''), 0, 2000);
        $err->klass       = mb_substr((string)($data['class'] ?? ''), 0, 200);
        $err->file        = mb_substr((string)($data['file'] ?? ''), 0, 500);
        $err->line        = (int)($data['line'] ?? 0);
        $err->trace       = mb_substr((string)($data['trace'] ?? ''), 0, 8000);
        $err->url         = mb_substr((string)($data['url'] ?? ''), 0, 500);
        $err->httpMethod  = mb_substr((string)($data['http_method'] ?? ''), 0, 12);
        $err->context     = json_encode($data['context'] ?? []);
        $err->hitCount    = 1;
        $err->status      = 'new';
        $err->taskId      = 0;
        $err->firstSeenAt = $now;
        $err->lastSeenAt  = $now;
        $err->createdAt   = $now;
        Bean::store($err);

        // Phase 3 hooks auto-triage here.
        $triage = $this->autoTriage($err);

        Flight::jsonSuccess([
            'id' => (int)$err->id, 'status' => $err->status,
            'new' => true, 'triage' => $triage,
        ], 'recorded');
    }

    /**
     * Auto-triage a newly-detected error: create a highlighted workbench task
     * against the reporting instance, guarded so it never collides with an agent
     * already on the repo. Returns a small status array for the ingest response.
     */
    private function autoTriage($err): array {
        // Control-plane only: an instance clone of this code must never create tasks.
        if (function_exists('is_control_plane') && !is_control_plane()) {
            return ['action' => 'skipped', 'reason' => 'not control plane'];
        }

        // Resolve the reporting instance. Reported tag is "<slug>.tiknix".
        $tag  = (string)$err->instanceTag;
        $slug = preg_replace('/\.[^.]+$/', '', $tag);   // bidsurge.tiknix -> bidsurge
        $inst = Bean::findOne('instance', 'slug = ?', [$slug]);
        if (!$inst || !$inst->id) {
            $err->status = 'unmatched';
            Bean::store($err);
            return ['action' => 'skipped', 'reason' => 'no matching instance'];
        }

        /* Layer 2 — active-build guard. It gates LAUNCHING a fix, not recording one.
           An agent already working this repo must not have a second agent started
           underneath it; a pending task is a note on a board and collides with nothing.
           This used to return here without creating anything, so an error arriving while
           a build happened to be running reached no board at all and waited for an idle
           sweep that only runs when auto_triage is on — off everywhere by design. The
           error was recorded, invisible, and forgotten.
           It stayed theoretical only because the guard was counting core's task table and
           always answered "idle". Fixing that made this reachable, so it is fixed too. */
        $busy = $this->instanceHasActiveBuild($tag);

        // Auto-launch is per-instance opt-in (instance.auto_triage). When on, run
        // the fix through the existing headless plan orchestrator (worktree +
        // merge-back + auto-retry). When off, create a highlighted task the human
        // can Run. Layer 3 dedup already guarantees this fires once per signature.
        if (!$busy && filter_var($inst->autoTriage ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $planId = $this->launchViaOrchestrator($err, $inst);
            if ($planId) {
                $err->taskId = $planId;
                $err->status = 'building';
                Bean::store($err);
                return ['action' => 'launched', 'plan_id' => $planId];
            }
            // Launch failed — fall through to a plain triage task so nothing is lost.
        }

        $task = $this->createTriageTask($err, $inst, $tag);
        if (!$task) {
            // Recorded, but nobody was told to fix it. Saying so beats a status of
            // 'triaged' pointing at a task that was never created.
            $err->status = 'untriaged';
            Bean::store($err);
            return ['action' => 'skipped', 'reason' => 'project has no task board yet'];
        }
        $err->taskId = $task['id'];
        $err->status = 'triaged';
        Bean::store($err);
        // 'busy' is reported so the ingest response still says why nothing was LAUNCHED,
        // which is the part the guard actually decided. The task exists either way.
        return ['action' => 'task_created', 'task_id' => $task['id'], 'busy' => $busy];
    }

    /**
     * The task board a project actually reads: its own data/workbench.db.
     *
     * Read-write PDO, or null when the project has no board yet (never built in) — which
     * is a fact, not a fault, and the caller decides what to do about it.
     */
    private function instanceBoard($inst): ?\PDO {
        $db = $inst->workbenchDb();
        if (!is_file($db)) return null;
        try {
            $pdo = new \PDO('sqlite:' . $db);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $has = $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='workbenchtask'")->fetchColumn();
            return $has ? $pdo : null;
        } catch (\Throwable $e) {
            Flight::get('log')?->warning('Firehose: could not open a project task board', [
                'db' => $db, 'err' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * A standalone highlighted triage task (used when auto-launch is off/failed).
     *
     * Written into the PROJECT's board, not core's. It used to dispense workbenchtask here,
     * on the control plane — but the workbench sidecar reads each project's own
     * data/workbench.db, so every fix task landed in a table that board never opens. The
     * task existed, the firehose linked to it by number, and the number meant nothing on
     * the page it sent you to.
     *
     * Column-aware because instance schemas have genuinely drifted — clones merge core at
     * different times — so naming a column unconditionally fails on the older ones, and a
     * failed insert here loses the only record that an error was triaged.
     *
     * @return array{id:int}|null  null when the project has no board to write to
     */
    private function createTriageTask($err, $inst, string $tag): ?array {
        $pdo = $this->instanceBoard($inst);
        if (!$pdo) {
            Flight::get('log')?->warning('Firehose: project has no task board; error recorded but not triaged', [
                'instance' => $tag, 'error' => (int)$err->id,
            ]);
            return null;
        }

        $now  = date('Y-m-d H:i:s');
        $want = [
            'title'            => 'Fix: ' . mb_substr((string)$err->message, 0, 120),
            'description'      => $this->triageBrief($err),
            'task_type'        => 'bug',
            'priority'         => 2,
            'status'           => 'pending',
            'member_id'        => (int)$inst->memberId,
            'instance_id'      => (int)$inst->id,
            'instance_tag'     => $tag,
            'base_branch'      => '',                // resolves to instance/<slug> at run time
            'authcontrol_level'=> 1,
            'source'           => 'detected_error',  // powers the workbench highlight
            'detectederror_id' => (int)$err->id,
            'audit_cycle'      => (int)(json_decode((string)$err->context, true)['audit_cycle'] ?? 0),
            'created_at'       => $now,
            'updated_at'       => $now,
        ];
        $have = array_column($pdo->query("SELECT name FROM pragma_table_info('workbenchtask')")->fetchAll(\PDO::FETCH_ASSOC), 'name');
        $use  = array_intersect_key($want, array_flip($have));

        $sql = 'INSERT INTO workbenchtask (' . implode(',', array_keys($use)) . ') VALUES ('
             . implode(',', array_fill(0, count($use), '?')) . ')';
        $pdo->prepare($sql)->execute(array_values($use));

        return ['id' => (int)$pdo->lastInsertId()];
    }

    /** Layer 2: is an agent currently building/running against this instance? */
    private function instanceHasActiveBuild(string $tag): bool {
        /* The PROJECT's board. Counting core's table asked whether the control plane had
           a running task for this tag — which it never does — so the guard that exists to
           stop a fix colliding with an agent already on the repo always said "idle". */
        $slug = preg_replace('/\.[^.]+$/', '', $tag);
        $inst = Bean::findOne('instance', 'slug = ?', [$slug]);
        $pdo  = $inst && $inst->id ? $this->instanceBoard($inst) : null;
        if (!$pdo) return false;
        $st = $pdo->prepare("SELECT COUNT(*) FROM workbenchtask WHERE instance_tag = ? AND status IN ('running','building')");
        $st->execute([$tag]);
        return (int)$st->fetchColumn() > 0;
    }


    /** Markdown brief handed to the fix agent (and shown in the task view). */
    private function triageBrief($err): string {
        $ctx = json_decode((string)$err->context, true) ?: [];
        $l   = [];
        $l[] = '**Auto-detected error** on `' . $err->instanceTag . '` — captured by the firehose.';
        $l[] = '';
        $l[] = '- **Type:** ' . $err->type;
        $l[] = '- **Message:** ' . $err->message;
        if (!empty($err->klass)) $l[] = '- **Class:** `' . $err->klass . '`';
        $l[] = '- **Location:** `' . $err->file . ':' . $err->line . '`';
        if (!empty($err->url)) $l[] = '- **Request:** `' . $err->httpMethod . ' ' . $err->url . '`';
        if (!empty($ctx['controller'])) {
            $l[] = '- **Controller:** `' . $ctx['controller'] . '->' . ($ctx['method'] ?? '') . '`';
        }
        $l[] = '- **Seen:** ' . (int)$err->hitCount . '× (first ' . $err->firstSeenAt . ')';
        $l[] = '';
        $l[] = '### Full message';
        $l[] = '```';
        $l[] = (string)$err->fullMessage;
        $l[] = '```';
        $l[] = '';
        $l[] = '### Stack trace';
        $l[] = '```';
        $l[] = (string)$err->trace;
        $l[] = '```';
        $l[] = '';
        $l[] = '**Goal:** reproduce, find the root cause at the location above, fix it, and verify the page/endpoint works.';

        // Visual evidence from the audit that surfaced this failure. Rendered as
        // inline images (MarkdownParser supports ![](url)); the agent reads them as
        // markdown pointers to the exact broken screen. Kept OUTSIDE the code fences
        // above so they display rather than being escaped.
        $shots = $ctx['screens'] ?? [];
        if (is_array($shots) && $shots) {
            $l[] = '';
            $l[] = '### Screenshots from the failing audit';
            $l[] = '_Visual proof of the defect — reproduce against these._';
            foreach (array_slice($shots, 0, 6) as $u) {
                if (is_string($u) && preg_match('#^https?://#i', $u)) $l[] = '![screenshot](' . $u . ')';
            }
        }
        return implode("\n", $l);
    }

    /**
     * Auto-launch the fix by wrapping the detected error as a 1-task plan and
     * running it through the existing headless orchestrator (worktree off
     * instance/<slug> + merge-back + auto-retry). Returns the plan id, or 0 on
     * failure (caller falls back to a plain triage task).
     */
    private function launchViaOrchestrator($err, $inst): int {
        try {
            $app  = $inst->app ?: 'tiknix';
            $plan = [
                'title'    => 'Fix: ' . mb_substr((string)$err->message, 0, 120),
                'summary'  => 'Auto-triaged from a detected runtime error on ' . $err->instanceTag . '.',
                'subtasks' => [[
                    'id'          => 't1',
                    'title'       => 'Fix: ' . mb_substr((string)$err->message, 0, 120),
                    'description' => $this->triageBrief($err),
                    'files'       => $err->file ? [(string)$err->file] : [],
                    'priority'    => 2,
                    'depends_on'  => [],
                ]],
            ];
            $res    = \app\PlanIngestor::ingest($inst, $plan, (int)$inst->memberId, '', $app);
            $planId = (int)($res['parent']['id'] ?? 0);
            if (!$planId) return 0;

            // Mark the plan building (mirrors Workbench::planbuild) + tag it as
            // detected-error so the workbench can highlight it.
            $parent = Bean::load('workbenchtask', $planId);
            $parent->planStatus      = 'building';
            $parent->status          = 'running';
            $parent->source          = 'detected_error';
            $parent->detectederrorId = (int)$err->id;
            // Inherit the audit-chain depth so this fix's own post-build audit knows
            // how deep it is and the audit->fix loop terminates (see AuditReporter).
            $parent->auditCycle      = (int)(json_decode((string)$err->context, true)['audit_cycle'] ?? 0);
            $parent->updatedAt       = date('Y-m-d H:i:s');
            Bean::store($parent);

            $level = (int)((Bean::load('member', (int)$inst->memberId)->level) ?: 1);
            return $this->startOrchestrator($planId, $inst, $level) ? $planId : 0;
        } catch (\Throwable $e) {
            $this->logger->error('Firehose auto-launch failed: ' . $e->getMessage());
            return 0;
        }
    }

    /** Launch the detached worktree orchestrator for a plan (headless mirror of Workbench). */
    private function startOrchestrator(int $planId, $inst, int $level): bool {
        $app = $inst->app ?: 'tiknix';
        $dir = $inst->dir();
        // Escalate the FINAL cap-cycle fix to a stronger model. The audit->fix loop
        // gets MAX_AUDIT_CYCLES attempts; the last one is the last auto-shot before a
        // human takes over, so give it opus (the earlier, cheaper cycles stay sonnet).
        $auditCycle = (int)(Bean::load('workbenchtask', $planId)->auditCycle ?? 0);
        $model = ($auditCycle >= \app\AuditReporter::MAX_AUDIT_CYCLES) ? 'opus' : 'sonnet';
        // This copy of the launcher used to omit the TIKNIX_WORKBENCH_DB export the
        // sidecar's copy had, so a sweep-launched plan for an instance wrote its task
        // state to core's db instead of the instance's. app\PlanOrchestrator carries it.
        return \app\PlanOrchestrator::launch($planId, (string)$inst->slug, $dir, $level, $model);
    }
}
