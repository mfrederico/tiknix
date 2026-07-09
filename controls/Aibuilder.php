<?php
/**
 * AI Builder — in-app entry to a member's jailed Claude/qwen coding sessions.
 *
 * A member (admin) provisions one or more isolated "<slug>.tiknix" instances —
 * each an independent git clone with its own SQLite DB. Opening an instance mints
 * a short-lived HMAC token and renders a terminal (xterm) + chat UI that connect,
 * same-origin, to the aibuilder bridges:
 *   - terminal: wss://<host>/aibuilder/ws       -> node bridge (127.0.0.1:3990)
 *   - chat:     wss://<host>/aibuilder/chat-ws    -> php bridge  (127.0.0.1:3991)
 * Both spawn a bubblewrap-jailed agent confined to THAT instance. Checkpoint /
 * Rollback shell out to the capricorn instance scripts so any change is reversible.
 *
 * Security: the bubblewrap jail (capricorn/bin/jail-run.sh) is the real boundary.
 * This controller gates access (ADMIN), mints the token, validates instance
 * ownership, and brokers snapshot/rollback. Slugs are strictly validated before
 * any shell use, and the shared token secret must match the bridges' env.
 */

namespace app;

use \Flight as Flight;
use app\BaseControls\Control;
use RedBeanPHP\R;

class Aibuilder extends Control {

    private const SLUG_RE = '/^[a-z][a-z0-9]{1,49}$/';
    private const APP     = 'tiknix';

    private function cfg(): array {
        return @parse_ini_file(dirname(__DIR__) . '/conf/aibuilder.ini', true) ?: [];
    }

    private function minLevel(): int {
        return (int)($this->cfg()['access']['min_level'] ?? LEVELS['ADMIN']);
    }

    private function instanceDir(string $sub): string {
        return '/var/www/html/default/' . $sub . '.' . self::APP;
    }

    /** base64url(payload) + "." + hex(HMAC-SHA256(payload, secret)) — mirrors the bridges. */
    private function mintToken(string $sub, int $memberId): string {
        $cfg    = $this->cfg();
        $secret = (string)($cfg['token']['secret'] ?? '');
        $ttl    = (int)($cfg['token']['ttl'] ?? 120);
        $payload = json_encode([
            'app' => self::APP, 'sub' => $sub, 'member_id' => $memberId,
            'exp' => time() + $ttl,
        ]);
        $b64 = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
        return $b64 . '.' . hash_hmac('sha256', $b64, $secret);
    }

    /** Load an instance the current member owns and that exists on disk. */
    private function ownedInstance($id) {
        $id = (int)$id;
        if (!$id) return null;
        $inst = R::load('instance', $id);
        if (!$inst->id) return null;
        if ((int)$inst->memberId !== (int)$this->member->id) return null;
        if (!is_file($this->instanceDir($inst->slug) . '/public/index.php')) return null;
        return $inst;
    }

    /** Run git inside an instance's directory (read/write its own repo only). */
    private function gitInstance(string $slug, array $args): array {
        if (!preg_match(self::SLUG_RE, $slug)) return ['ok' => false, 'out' => '', 'code' => 1];
        $cmd = 'git -C ' . escapeshellarg($this->instanceDir($slug));
        foreach ($args as $a) { $cmd .= ' ' . escapeshellarg((string)$a); }
        $lines = []; $code = 0;
        exec($cmd . ' 2>&1', $lines, $code);
        return ['ok' => $code === 0, 'out' => implode("\n", $lines), 'code' => $code];
    }

    /** Run a capricorn instance script (args already validated). Returns ok/out/code. */
    private function runScript(string $script, array $args): array {
        $cfg    = $this->cfg();
        $binDir = rtrim((string)($cfg['ops']['bin_dir'] ?? '/home/ubuntu/capricorn/bin'), '/');
        $prefix = trim((string)($cfg['ops']['sudo_prefix'] ?? ''));
        $cmd = ($prefix ? $prefix . ' ' : '') . escapeshellarg($binDir . '/' . $script);
        foreach ($args as $a) { $cmd .= ' ' . escapeshellarg((string)$a); }
        $lines = []; $code = 0;
        exec($cmd . ' 2>&1', $lines, $code);
        return ['ok' => $code === 0, 'out' => implode("\n", $lines), 'code' => $code];
    }

    // --- routes ---------------------------------------------------------------

    /** GET /aibuilder — list instances (optionally ?id= to open one inline). */
    public function index($params = []): void {
        if (!$this->requireLevel($this->minLevel())) return;
        $this->renderHome((int)$this->getParam('id', 0));
    }

    /** GET /aibuilder/open/<id> — open a specific instance's terminal + chat. */
    public function open($params = []): void {
        if (!$this->requireLevel($this->minLevel())) return;
        $this->renderHome((int)($params['operation']->name ?? $this->getParam('id', 0)));
    }

    /** Render the instance picker plus, if one is selected, its Terminal/Chat. */
    private function renderHome(int $selId): void {
        $instances = $this->member->with(' ORDER BY created_at DESC ')->ownInstanceList;
        $selected  = $selId ? $this->ownedInstance($selId) : null;

        $cfg = $this->cfg();
        $this->render('aibuilder/index', [
            'title'          => 'AI Builder',
            'instances'      => array_values($instances),
            'selected'       => $selected,
            'ab_sub'         => $selected ? $selected->slug : '',
            'ab_token'       => $selected ? $this->mintToken($selected->slug, (int)$this->member->id) : '',
            'ab_wspath'      => (string)($cfg['bridge']['ws_path'] ?? '/aibuilder/ws'),
            'ab_chat_wspath' => (string)($cfg['bridge']['chat_ws_path'] ?? '/aibuilder/chat-ws'),
            'ab_hasInstance' => (bool)$selected,
            'ab_isDefault'   => $selected ? (bool)$selected->isDefault : false,
            'ab_isRoot'      => Flight::hasLevel(LEVELS['ROOT']),
            'ab_mainRepo'    => GitHubPublisher::mainGithubRepo(),
            'ab_url'         => $selected ? 'https://' . $selected->slug . '.' . self::APP . '.com' : '',
        ]);
    }

    /** POST /aibuilder/create — provision a new instance. JSON. */
    public function create($params = []): void {
        if (!$this->requireLevel($this->minLevel())) return;
        if (!$this->validateCSRF()) return;

        $slug   = strtolower(trim((string)$this->getParam('slug', '')));
        $name   = trim((string)$this->getParam('name', '')) ?: ucfirst($slug);
        $engine = in_array($this->getParam('engine'), ['claude', 'qwen'], true)
                    ? $this->getParam('engine') : 'claude';

        if (!preg_match(self::SLUG_RE, $slug)) {
            Flight::jsonError('Invalid name: use 2-50 lowercase letters/numbers, starting with a letter.', 400);
            return;
        }
        if (R::count('instance', 'slug = ?', [$slug]) > 0 || is_dir($this->instanceDir($slug))) {
            Flight::jsonError('That name is already taken.', 409);
            return;
        }

        // Provision: capricorn clones the app, seeds an isolated sqlite db + guardrails.
        $out = $this->runScript('provision-instance.sh',
            [self::APP, $slug, '--admin', (string)$this->member->email, '--name', $name]);

        if (!is_file($this->instanceDir($slug) . '/public/index.php')) {
            $this->logger->error('aibuilder provision failed', ['slug' => $slug, 'out' => $out['out']]);
            Flight::jsonError('Provisioning failed. ' . substr(trim($out['out']), -300), 500);
            return;
        }

        // Honor the chosen engine (provision wrote the conf default; override if needed).
        @file_put_contents($this->instanceDir($slug) . '/.aibuilder/engine', $engine . "\n");

        // Record ownership via the association (sets member_id automatically).
        $member = R::load('member', (int)$this->member->id);
        $inst = R::dispense('instance');
        $inst->slug        = $slug;
        $inst->app         = self::APP;
        $inst->displayName = $name;
        $inst->engine      = $engine;
        $inst->status      = 'active';
        // Root may flag one instance as the "(default)" tiknix-core sandbox that
        // publishes back to main. Only root; other members' instances are never default.
        $inst->isDefault   = (Flight::hasLevel(LEVELS['ROOT'])
                              && filter_var($this->getParam('is_default', false), FILTER_VALIDATE_BOOLEAN)) ? 1 : 0;
        $inst->createdAt   = date('Y-m-d H:i:s');
        $member->ownInstanceList[] = $inst;
        R::store($member);

        $this->logger->info('aibuilder instance created', ['slug' => $slug, 'by' => $this->member->id]);
        Flight::jsonSuccess(['id' => $inst->id, 'slug' => $slug], 'Instance created');
    }

    /** GET /aibuilder/refresh?id= — re-mint a token (AJAX reconnect). JSON. */
    public function refresh($params = []): void {
        if (!$this->requireLevel($this->minLevel())) return;
        $inst = $this->ownedInstance($this->getParam('id', 0));
        if (!$inst) { Flight::jsonError('No such instance', 404); return; }
        Flight::jsonSuccess(['token' => $this->mintToken($inst->slug, (int)$this->member->id)]);
    }

    /** GET /aibuilder/changes?id= — files changed since the last checkpoint. JSON. */
    public function changes($params = []): void {
        if (!$this->requireLevel($this->minLevel())) return;
        $inst = $this->ownedInstance($this->getParam('id', 0));
        if (!$inst) { Flight::jsonError('No such instance', 404); return; }

        // Uncommitted working-tree changes == the delta since the last checkpoint
        // (snapshot-instance.sh commits everything, so this self-resets per checkpoint).
        $out = $this->gitInstance($inst->slug, ['status', '--porcelain']);
        $files = [];
        foreach (explode("\n", $out['out']) as $line) {
            if (trim($line) === '') continue;
            $status = trim(substr($line, 0, 2));
            $path   = substr($line, 3);
            if (($p = strpos($path, ' -> ')) !== false) $path = substr($path, $p + 4); // rename
            $files[] = ['status' => $status, 'path' => trim($path)];
        }
        Flight::jsonSuccess(['files' => $files, 'count' => count($files)]);
    }

    /** POST /aibuilder/checkpoint?id= — checkpoint with an optional description. JSON. */
    public function checkpoint($params = []): void {
        if (!$this->requireLevel($this->minLevel())) return;
        if (!$this->validateCSRF()) return;
        $inst = $this->ownedInstance($this->getParam('id', 0));
        if (!$inst) { Flight::jsonError('No such instance', 404); return; }

        $desc = mb_substr(trim(preg_replace('/[\r\n]+/', ' ', (string)$this->getParam('label', ''))), 0, 200);

        // snapshot-instance.sh commits + creates an auto-unique lightweight tag, echoing it.
        $out = $this->runScript('snapshot-instance.sh', [self::APP, $inst->slug]);
        if (!$out['ok']) { Flight::jsonError('Checkpoint failed: ' . substr(trim($out['out']), -300), 500); return; }

        $tag = '';
        foreach (array_reverse(array_filter(array_map('trim', explode("\n", $out['out'])))) as $l) {
            if (preg_match('/^checkpoint-[A-Za-z0-9._-]+$/', $l)) { $tag = $l; break; }
        }
        // Re-tag as an ANNOTATED tag carrying the description (git-native; HEAD is the snapshot commit).
        if ($tag !== '' && $desc !== '') {
            $this->gitInstance($inst->slug, ['tag', '-f', '-a', $tag, '-m', $desc]);
        }

        // Auto-publish: if this instance has a GitHub connection with auto-publish on,
        // push HEAD + open/refresh a PR right after the checkpoint lands.
        $publish = null;
        $conn = Bean::findOne('connections',
            'member_id = ? AND instance_id = ? AND connector_type = ? AND enabled = 1',
            [(int)$this->member->id, (int)$inst->id, 'github']);
        if ($conn && $conn->id) {
            $meta = json_decode($conn->metadataJson ?: '{}', true) ?: [];
            if (!empty($meta['autoPublish'])) {
                $res = GitHubPublisher::publish($inst, $conn);
                $conn->lastUsedAt = date('Y-m-d H:i:s');
                $conn->lastError  = $res['ok'] ? ($res['note'] ?? null) : ($res['error'] ?? 'publish failed');
                Bean::store($conn);
                $publish = $res['ok']
                    ? ['ok' => true, 'pr' => $res['pr'], 'message' => $res['message'], 'note' => $res['note'] ?? null]
                    : ['ok' => false, 'error' => $res['error'] ?? 'publish failed'];
            }
        }

        Flight::jsonSuccess(['checkpoint' => $tag, 'description' => $desc, 'publish' => $publish], 'Checkpoint saved');
    }

    /** GET /aibuilder/checkpoints?id= — list checkpoints with descriptions. JSON. */
    public function checkpoints($params = []): void {
        if (!$this->requireLevel($this->minLevel())) return;
        $inst = $this->ownedInstance($this->getParam('id', 0));
        if (!$inst) { Flight::jsonError('No such instance', 404); return; }

        $out = $this->gitInstance($inst->slug, ['for-each-ref', '--sort=-creatordate',
            '--format=%(refname:short)|%(creatordate:short)|%(objectname:short)|%(contents:subject)',
            'refs/tags/checkpoint-*']);
        $items = [];
        foreach (explode("\n", $out['out']) as $line) {
            if ($line === '') continue;
            $p = explode('|', $line, 4);
            $items[] = [
                'name'        => $p[0] ?? '',
                'date'        => $p[1] ?? '',
                'commit'      => $p[2] ?? '',
                'description' => $p[3] ?? '',  // empty for lightweight (undescribed) tags
            ];
        }
        Flight::jsonSuccess(['checkpoints' => $items]);
    }

    /** POST /aibuilder/rollback/<checkpoint>?id= — restore a checkpoint. JSON. */
    public function rollback($params = []): void {
        if (!$this->requireLevel($this->minLevel())) return;
        if (!$this->validateCSRF()) return;
        $inst = $this->ownedInstance($this->getParam('id', 0));
        if (!$inst) { Flight::jsonError('No such instance', 404); return; }

        $ckpt = (string)($params['operation']->name ?? $this->getParam('checkpoint', 'checkpoint-baseline'));
        if (!preg_match('/^[a-z0-9-]{3,60}$/i', $ckpt)) {
            Flight::jsonError('Invalid checkpoint', 400); return;
        }
        $out = $this->runScript('rollback-instance.sh', [self::APP, $inst->slug, $ckpt]);
        if ($out['ok']) Flight::jsonSuccess(['log' => $out['out']], 'Rolled back to ' . $ckpt);
        else            Flight::jsonError('Rollback failed: ' . substr(trim($out['out']), -300), 500);
    }

    /** Validate a decomposed-plan array: {title, subtasks:[{title,...}]}. */
    private function validPlan($plan): bool {
        return is_array($plan) && !empty($plan['title']) && !empty($plan['subtasks']) && is_array($plan['subtasks']);
    }

    /** Persist a decomposed plan as a workbench task tree + take a baseline checkpoint. */
    private function savePlanTree($inst, array $plan): array {
        // Baseline checkpoint so the WHOLE plan is reversible to the pre-plan state.
        $snap = $this->runScript('snapshot-instance.sh', [self::APP, $inst->slug]);
        $tag = '';
        foreach (array_reverse(array_filter(array_map('trim', explode("\n", $snap['out'])))) as $l) {
            if (preg_match('/^checkpoint-[A-Za-z0-9._-]+$/', $l)) { $tag = $l; break; }
        }
        if ($tag !== '') {
            $this->gitInstance($inst->slug, ['tag', '-f', '-a', $tag, '-m', 'plan: ' . mb_substr((string)$plan['title'], 0, 80)]);
        }

        $now = date('Y-m-d H:i:s');
        $parent = R::dispense('workbenchtask');
        $parent->title          = mb_substr((string)$plan['title'], 0, 200);
        $parent->description    = (string)($plan['summary'] ?? '');
        $parent->taskType       = 'feature';
        $parent->priority       = 2;
        $parent->status         = 'pending';
        $parent->instanceId     = (int)$inst->id;     // fluid: adds instance_id
        $parent->instanceTag    = $inst->slug . '.' . self::APP;  // fluid: tenant tag e.g. "jadams.tiknix"
        $parent->engine         = $inst->engine;
        $parent->memberId       = (int)$this->member->id;
        $parent->planCheckpoint = $tag;               // fluid: adds plan_checkpoint
        $parent->planStatus     = 'draft';            // fluid: draft -> approved -> building -> done
        $parent->createdAt      = $now;
        R::store($parent);

        // Pass 1: create every subtask, remembering the planner's stable ref
        // (its "id", or a positional fallback) so we can resolve depends_on next.
        $rows = [];       // [$task, $subtaskArray, $ref]
        $refMap = [];     // planner ref => db task id
        $i = 0;
        foreach ($plan['subtasks'] as $st) {
            if (empty($st['title'])) continue;
            $i++;
            $ref = trim((string)($st['id'] ?? '')) ?: ('t' . $i);
            $t = R::dispense('workbenchtask');
            $t->title        = mb_substr((string)$st['title'], 0, 200);
            $t->description  = (string)($st['description'] ?? '');
            $t->taskType     = 'feature';
            $t->priority     = (int)($st['priority'] ?? 3);
            $t->status       = 'pending';
            $t->parentTaskId = (int)$parent->id;
            $t->instanceId   = (int)$inst->id;
            $t->instanceTag  = $inst->slug . '.' . self::APP;   // fluid: tenant tag, denormalized for the board
            $t->engine       = in_array($st['engine'] ?? '', ['claude', 'qwen'], true) ? $st['engine'] : $inst->engine;
            $t->relatedFiles = json_encode(is_array($st['files'] ?? null) ? array_values($st['files']) : []);
            $t->planRef      = $ref;                  // fluid: planner's stable id
            $t->memberId     = (int)$this->member->id;
            $t->createdAt    = $now;
            R::store($t);
            $refMap[$ref] = (int)$t->id;
            $rows[] = [$t, $st, $ref];
        }

        // Pass 2: resolve depends_on (planner refs) to concrete db task ids. Store
        // as a JSON array on the subtask — this is the DAG the executor walks.
        $subs = [];
        foreach ($rows as [$t, $st, $ref]) {
            $deps = [];
            foreach ((array)($st['depends_on'] ?? []) as $d) {
                $d = trim((string)$d);
                if ($d !== '' && isset($refMap[$d]) && $refMap[$d] !== (int)$t->id) {
                    $deps[] = $refMap[$d];
                }
            }
            $deps = array_values(array_unique($deps));
            $t->dependsOn = json_encode($deps);       // fluid: JSON array of task ids
            R::store($t);
            $subs[] = [
                'id' => (int)$t->id, 'ref' => $ref, 'title' => $t->title,
                'priority' => (int)$t->priority, 'engine' => $t->engine, 'depends_on' => $deps,
            ];
        }
        $this->logger->info('aibuilder plan saved', ['instance' => $inst->slug, 'parent' => $parent->id, 'subtasks' => count($subs)]);
        return ['parent' => ['id' => (int)$parent->id, 'title' => $parent->title], 'checkpoint' => $tag, 'subtasks' => $subs];
    }

    /** POST /aibuilder/planingest?id= — ingest the plan the agent wrote to .aibuilder/plan.json. JSON.
     *  Reliable handoff: the jailed planner WRITES a file (a tool it does well) rather than us
     *  scraping JSON out of chat text. */
    public function planingest($params = []): void {
        if (!$this->requireLevel($this->minLevel())) return;
        if (!$this->validateCSRF()) return;
        $inst = $this->ownedInstance($this->getParam('id', 0));
        if (!$inst) { Flight::jsonError('No such instance', 404); return; }

        $file = $this->instanceDir($inst->slug) . '/.aibuilder/plan.json';
        if (!is_file($file)) { Flight::jsonError('No plan.json was written by the planner yet.', 404); return; }
        $plan = json_decode((string)@file_get_contents($file), true);
        if (!$this->validPlan($plan)) { Flight::jsonError('plan.json is not a valid plan {title, subtasks:[...]}.', 422); return; }

        $res = $this->savePlanTree($inst, $plan);
        @unlink($file);  // consume it so the next plan starts clean
        Flight::jsonSuccess($res, 'Plan saved');
    }

    /** POST /aibuilder/plansave?id= — save a decomposed plan posted as JSON (fallback path). JSON. */
    public function plansave($params = []): void {
        if (!$this->requireLevel($this->minLevel())) return;
        if (!$this->validateCSRF()) return;
        $inst = $this->ownedInstance($this->getParam('id', 0));
        if (!$inst) { Flight::jsonError('No such instance', 404); return; }

        $plan = json_decode((string)$this->getParam('plan', ''), true);
        if (!$this->validPlan($plan)) { Flight::jsonError('Invalid plan: need {title, subtasks:[...]}', 400); return; }
        Flight::jsonSuccess($this->savePlanTree($inst, $plan), 'Plan saved');
    }

    /** GET /aibuilder/plan?id= — list saved plans (task trees) for an instance. JSON. */
    public function plan($params = []): void {
        if (!$this->requireLevel($this->minLevel())) return;
        $inst = $this->ownedInstance($this->getParam('id', 0));
        if (!$inst) { Flight::jsonError('No such instance', 404); return; }

        $parents = R::find('workbenchtask', 'instance_id = ? AND parent_task_id IS NULL ORDER BY created_at DESC', [(int)$inst->id]);
        $plans = [];
        foreach ($parents as $p) {
            $subs = R::find('workbenchtask', 'parent_task_id = ? ORDER BY priority ASC, id ASC', [(int)$p->id]);
            $plans[] = [
                'id' => (int)$p->id, 'title' => $p->title, 'summary' => $p->description,
                'checkpoint' => $p->planCheckpoint, 'status' => $p->status,
                'plan_status' => $p->planStatus ?: 'draft',
                'instance_tag' => $p->instanceTag ?: ($inst->slug . '.' . self::APP),
                'subtasks' => array_map(fn($s) => [
                    'id' => (int)$s->id, 'ref' => $s->planRef, 'title' => $s->title, 'description' => $s->description,
                    'priority' => (int)$s->priority, 'engine' => $s->engine, 'status' => $s->status,
                    'files' => $s->relatedFiles,
                    'depends_on' => json_decode($s->dependsOn ?: '[]', true) ?: [],
                ], array_values($subs)),
            ];
        }
        Flight::jsonSuccess(['plans' => $plans]);
    }

    /**
     * POST /aibuilder/plangenerate?id= — launch the headless (claude -p) planner
     * for a goal. It grounds itself via the tiknix MCP and calls submit_plan,
     * which writes .aibuilder/plan.json for planingest to pick up. JSON.
     */
    public function plangenerate($params = []): void {
        if (!$this->requireLevel($this->minLevel())) return;
        if (!$this->validateCSRF()) return;
        $inst = $this->ownedInstance($this->getParam('id', 0));
        if (!$inst) { Flight::jsonError('No such instance', 404); return; }

        $goal = trim((string)$this->getParam('goal', ''));
        if (mb_strlen($goal) < 10) { Flight::jsonError('Describe the goal in a sentence or two (min 10 chars).', 400); return; }

        $runner = new PlanRunner($inst->slug, $this->instanceDir($inst->slug),
                                 (int)$this->member->id, (int)$this->member->level, (string)$inst->engine);
        try {
            $session = $runner->start($goal);
        } catch (\Throwable $e) {
            Flight::jsonError('Could not start planner: ' . $e->getMessage(), 500);
            return;
        }
        $this->logger->info('aibuilder planner started', ['instance' => $inst->slug, 'session' => $session]);
        Flight::jsonSuccess(['session' => $session, 'running' => true], 'Planner started — decomposing the goal…');
    }

    /** GET /aibuilder/planstatus?id= — poll the headless planner (running / plan_ready / log). JSON. */
    public function planstatus($params = []): void {
        if (!$this->requireLevel($this->minLevel())) return;
        $inst = $this->ownedInstance($this->getParam('id', 0));
        if (!$inst) { Flight::jsonError('No such instance', 404); return; }

        $runner = new PlanRunner($inst->slug, $this->instanceDir($inst->slug),
                                 (int)$this->member->id, (int)$this->member->level, (string)$inst->engine);
        Flight::jsonSuccess([
            'running'    => $runner->running(),
            'plan_ready' => $runner->planReady(),
            'log'        => $runner->logTail(40),
        ]);
    }

    /** Resolve a plan (workbenchtask parent) by id and authorize via its instance. */
    private function ownedPlan($planId) {
        $planId = (int)$planId;
        if ($planId <= 0) return null;
        $plan = R::load('workbenchtask', $planId);
        if (!$plan->id || $plan->parentTaskId) return null;         // must be a plan parent
        $inst = $this->ownedInstance((int)$plan->instanceId);
        if (!$inst) return null;
        return [$plan, $inst];
    }

    /** POST /aibuilder/planapprove?plan= — mark a plan approved (ready to build). JSON. */
    public function planapprove($params = []): void {
        if (!$this->requireLevel($this->minLevel())) return;
        if (!$this->validateCSRF()) return;
        $pi = $this->ownedPlan($this->getParam('plan', 0));
        if (!$pi) { Flight::jsonError('No such plan', 404); return; }
        [$plan] = $pi;
        $plan->planStatus = 'approved';
        $plan->updatedAt  = date('Y-m-d H:i:s');
        R::store($plan);
        Flight::jsonSuccess(['plan_status' => 'approved'], 'Plan approved — ready to build.');
    }

    /**
     * POST /aibuilder/planrun?plan= — launch the detached worktree orchestrator for
     * an approved plan (parallel build agents, capped at PlanExecutor::MAX_CONCURRENT). JSON.
     */
    public function planrun($params = []): void {
        if (!$this->requireLevel($this->minLevel())) return;
        if (!$this->validateCSRF()) return;
        $pi = $this->ownedPlan($this->getParam('plan', 0));
        if (!$pi) { Flight::jsonError('No such plan', 404); return; }
        [$plan, $inst] = $pi;

        if (!in_array($plan->planStatus, ['approved', 'stalled'], true)) {
            Flight::jsonError('Approve the plan before running it (or it is already building).', 409);
            return;
        }
        $session = 'tiknix-plan' . (int)$plan->id . '-orchestrator';
        if (TmuxManager::exists($session)) { Flight::jsonError('This plan is already running.', 409); return; }

        $dir = $this->instanceDir($inst->slug);
        $cmd = 'php ' . escapeshellarg(dirname(__DIR__) . '/scripts/plan-orchestrate.php')
             . ' --plan=' . (int)$plan->id
             . ' --slug=' . escapeshellarg((string)$inst->slug)
             . ' --dir='  . escapeshellarg($dir)
             . ' --model=sonnet'
             . ' --level=' . (int)$this->member->level;
        $ab = $dir . '/.aibuilder';
        @mkdir($ab, 0775, true);
        $scriptFile = $ab . '/run-orchestrator.sh';
        file_put_contents($scriptFile, "#!/bin/bash\n" . $cmd . ' 2>&1 | tee ' . escapeshellarg($ab . '/orchestrator.log') . "\n");
        @chmod($scriptFile, 0755);

        if (!TmuxManager::create($session, $scriptFile, $dir)) {
            Flight::jsonError('Could not start the orchestrator.', 500);
            return;
        }
        $plan->planStatus = 'building';
        $plan->updatedAt  = date('Y-m-d H:i:s');
        R::store($plan);
        Flight::jsonSuccess(['session' => $session], 'Build started — up to ' . PlanExecutor::MAX_CONCURRENT . ' agents running.');
    }

    /** GET /aibuilder/planprogress?plan= — per-task build status for the live board. JSON. */
    public function planprogress($params = []): void {
        if (!$this->requireLevel($this->minLevel())) return;
        $pi = $this->ownedPlan($this->getParam('plan', 0));
        if (!$pi) { Flight::jsonError('No such plan', 404); return; }
        [$plan] = $pi;
        $subs = R::find('workbenchtask', 'parent_task_id = ? ORDER BY priority ASC, id ASC', [(int)$plan->id]);
        $tasks = [];
        foreach ($subs as $s) {
            $tasks[] = [
                'id' => (int)$s->id, 'title' => $s->title, 'status' => $s->status,
                'engine' => $s->engine, 'error' => (string)$s->errorMessage,
                'depends_on' => json_decode((string)$s->dependsOn ?: '[]', true) ?: [],
            ];
        }
        Flight::jsonSuccess([
            'plan_status' => $plan->planStatus ?: 'draft',
            'running'     => TmuxManager::exists('tiknix-plan' . (int)$plan->id . '-orchestrator'),
            'tasks'       => $tasks,
        ]);
    }

    /**
     * POST /aibuilder/restart — kill the instance's jailed tmux session so a fresh
     * jail (with the current binds/settings) launches when the terminal reconnects.
     * The fpm user owns the socket, so no elevation is needed. JSON.
     */
    public function restart($params = []): void {
        if (!$this->requireLevel($this->minLevel())) return;
        if (!$this->validateCSRF()) return;
        $inst = $this->ownedInstance($this->getParam('id', 0));
        if (!$inst) { Flight::jsonError('No such instance', 404); return; }
        $sock = $this->instanceDir($inst->slug) . '/.aibuilder/tmux.sock';
        if (@file_exists($sock)) {
            @exec('tmux -S ' . escapeshellarg($sock) . ' kill-server 2>&1');
        }
        Flight::jsonSuccess([], 'Session restarted — reconnecting');
    }

    /**
     * POST /aibuilder/delete — danger-zone delete. The caller must type the
     * instance's full domain (slug.tiknix.com) to confirm. Kills the jailed
     * session, unlinks any GitHub connector (the remote repo is left intact),
     * archives the folder to a tombstone zip in a fresh public/, wipes everything
     * else, and removes the instance + connector DB records. JSON.
     */
    public function delete($params = []): void {
        if (!$this->requireLevel($this->minLevel())) return;
        if (!$this->validateCSRF()) return;

        $inst = R::load('instance', (int)$this->getParam('id', 0));
        if (!$inst->id) { Flight::jsonError('No such instance', 404); return; }
        if ((int)$inst->memberId !== (int)$this->member->id && !Flight::hasLevel(LEVELS['ROOT'])) {
            Flight::jsonError('Not your instance', 403); return;
        }
        if (!empty($inst->isDefault)) { Flight::jsonError('The (default) core instance cannot be deleted here.', 403); return; }

        $slug = (string)$inst->slug;
        if (!preg_match(self::SLUG_RE, $slug)) { Flight::jsonError('Invalid instance slug', 400); return; }
        $domain = $slug . '.' . self::APP . '.com';
        if (!hash_equals($domain, trim((string)$this->getParam('confirm', '')))) {
            Flight::jsonError('Confirmation does not match — type "' . $domain . '" exactly.', 400); return;
        }

        $dir = $this->instanceDir($slug);
        // Hard safety before any destructive fs op: canonical path + a dot in the
        // basename (every instance dir is "slug.app"; the source app dir is not).
        if ($dir !== '/var/www/html/default/' . $slug . '.' . self::APP || strpos(basename($dir), '.') === false) {
            Flight::jsonError('Refusing to delete: path failed validation', 400); return;
        }

        $steps = [];

        // 1) Kill the jailed session (same mechanism as restart).
        $sock = $dir . '/.aibuilder/tmux.sock';
        if (@file_exists($sock)) { @exec('tmux -S ' . escapeshellarg($sock) . ' kill-server 2>&1'); $steps[] = 'killed jailed session'; }

        // 2) Unlink GitHub connector(s). The remote repo itself is left untouched.
        $conns = R::find('connections', 'instance_id = ?', [(int)$inst->id]);
        if ($conns) { R::trashAll($conns); $steps[] = 'removed ' . count($conns) . ' connector(s)'; }

        // 3) Archive + wipe, leaving a tombstone zip in a fresh public/.
        if (is_dir($dir)) {
            $res = $this->archiveInstance($dir, $slug);
            if (!$res['ok']) { Flight::jsonError('Archive failed: ' . $res['error'], 500); return; }
            $steps[] = $res['message'];
        } else {
            $steps[] = 'folder already absent';
        }

        // 4) Drop the instance record.
        R::trash($inst);
        $steps[] = 'removed instance record';

        $this->logger->warning('aibuilder instance deleted', ['slug' => $slug, 'by' => (int)$this->member->id, 'steps' => $steps]);
        Flight::jsonSuccess(['slug' => $slug, 'steps' => $steps], 'Deleted ' . $domain);
    }

    /**
     * Archive an instance folder to public/slug.zip, then wipe. Excludes the big
     * regenerable dirs (vendor, node_modules, .git); swaps real conf/*.ini for the
     * matching .example.ini (or drops a secret-bearing .ini with no example) so the
     * web-served archive never carries live credentials.
     */
    private function archiveInstance(string $dir, string $slug): array {
        // (a) Neutralize secrets: real conf/*.ini -> its .example.ini.
        foreach (glob($dir . '/conf/*.ini') ?: [] as $ini) {
            if (substr($ini, -12) === '.example.ini') continue;
            $example = substr($ini, 0, -4) . '.example.ini';
            if (is_file($example)) @copy($example, $ini);
            else                   @unlink($ini);
        }

        // (b) Zip everything except the heavy regenerable dirs.
        $tmpZip = sys_get_temp_dir() . '/' . $slug . '-' . date('Ymd-His') . '.zip';
        @unlink($tmpZip);
        $cmd = 'cd ' . escapeshellarg($dir) . ' && zip -r -q ' . escapeshellarg($tmpZip)
             . " . -x 'vendor/*' 'node_modules/*' '.git/*'";
        $out = []; $code = 0; @exec($cmd . ' 2>&1', $out, $code);
        if (!is_file($tmpZip)) {
            return ['ok' => false, 'error' => 'zip produced no archive: ' . implode(' ', array_slice($out, -2))];
        }

        // (c) Wipe the folder, then recreate an empty public/.
        @exec('rm -rf ' . escapeshellarg($dir) . ' 2>&1');
        if (!@mkdir($dir . '/public', 0775, true) && !is_dir($dir . '/public')) {
            return ['ok' => false, 'error' => 'could not recreate public/ (archive kept at ' . $tmpZip . ')'];
        }

        // (d) Drop the tombstone zip into public/.
        $dest = $dir . '/public/' . $slug . '.zip';
        if (!@rename($tmpZip, $dest)) { @copy($tmpZip, $dest); @unlink($tmpZip); }
        @chmod($dest, 0644);

        $kb = (int)round((@filesize($dest) ?: 0) / 1024);
        return ['ok' => true, 'message' => 'archived to public/' . $slug . '.zip (' . $kb . ' KB)'];
    }

    // --- Uploads: secure (private/gitignored) + public (published) ------------

    private const UPLOAD_MAX = 52428800; // 50 MB per file

    /** Relative dir for an upload bucket. public/uploads is under the docroot (web-served);
     *  secure/uploads is outside it (not web-accessible). BOTH are tracked and published. */
    private function uploadBucketRel(string $bucket): string {
        return ($bucket === 'public' ? 'public' : 'secure') . '/uploads';
    }

    /** Ensure both upload buckets exist. public/uploads is web-served (under the docroot);
     *  secure/uploads sits outside the docroot so it is NOT web-accessible — a place for a
     *  DB or system files. Both are committed + published; the only difference is reachability. */
    private function ensureUploadDirs(string $slug): void {
        $root = $this->instanceDir($slug);
        foreach (['public/uploads', 'secure/uploads'] as $rel) {
            @mkdir($root . '/' . $rel, 0775, true);
            $keep = $root . '/' . $rel . '/.gitkeep';
            if (!is_file($keep)) @file_put_contents($keep, '');
        }
        // public/uploads is web-served: serve assets, but never EXECUTE uploaded code.
        $puh = $root . '/public/uploads/.htaccess';
        if (!is_file($puh)) {
            @file_put_contents($puh,
                "# Uploaded assets are served but never executed.\n"
                . "<FilesMatch \"\\.(php|phtml|phar|php[0-9]|pht)$\">\n    Require all denied\n</FilesMatch>\n");
        }
        // Defense-in-depth: if a web server is ever mis-pointed at the instance root
        // (docroot must be public/), deny web access to secure/ entirely.
        $sh = $root . '/secure/.htaccess';
        if (!is_file($sh)) @file_put_contents($sh, "Require all denied\n");
    }

    /** Reduce an uploaded name to a safe basename (no traversal, no hidden files). */
    private function safeName(string $name): string {
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', basename($name));
        $name = ltrim($name, '.');
        return substr($name === '' ? 'file' : $name, 0, 120);
    }

    /** POST /aibuilder/upload — store file(s) into the secure|public bucket. JSON. */
    public function upload($params = []): void {
        if (!$this->requireLevel($this->minLevel())) return;
        if (!$this->validateCSRF()) return;
        $inst = $this->ownedInstance($this->getParam('id', 0));
        if (!$inst) { Flight::jsonError('No such instance', 404); return; }

        $bucket    = $this->getParam('bucket', 'secure') === 'public' ? 'public' : 'secure';
        $overwrite = filter_var($this->getParam('overwrite', false), FILTER_VALIDATE_BOOLEAN);
        $this->ensureUploadDirs($inst->slug);
        $destDir = $this->instanceDir($inst->slug) . '/' . $this->uploadBucketRel($bucket);

        if (empty($_FILES['files']['name'])) { Flight::jsonError('No files uploaded', 400); return; }
        $names = (array)$_FILES['files']['name'];
        $tmps  = (array)$_FILES['files']['tmp_name'];
        $errs  = (array)$_FILES['files']['error'];
        $sizes = (array)$_FILES['files']['size'];

        $stored = []; $errors = [];
        foreach ($names as $i => $origName) {
            if (($errs[$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) { $errors[] = $origName . ': upload error'; continue; }
            if (($sizes[$i] ?? 0) > self::UPLOAD_MAX)               { $errors[] = $origName . ': too large (max 50MB)'; continue; }
            if (!is_uploaded_file($tmps[$i]))                        { $errors[] = $origName . ': invalid'; continue; }

            $name = $this->safeName((string)$origName);
            $dest = $destDir . '/' . $name;
            if ($overwrite) {
                // index.php is protected — never overwrite the front controller.
                if (strtolower($name) === 'index.php' && is_file($dest)) {
                    $errors[] = $origName . ': index.php is protected (not overwritten)'; continue;
                }
                // otherwise keep $dest as-is; move_uploaded_file replaces it.
            } else {
                $n = 1;
                while (is_file($dest)) {
                    $ext  = pathinfo($name, PATHINFO_EXTENSION);
                    $dest = $destDir . '/' . pathinfo($name, PATHINFO_FILENAME) . '-' . $n . ($ext ? '.' . $ext : '');
                    $n++;
                }
            }
            if (move_uploaded_file($tmps[$i], $dest)) {
                @chmod($dest, 0664);
                $rel = $this->uploadBucketRel($bucket) . '/' . basename($dest);
                // Track the file so it publishes with the next checkpoint (both buckets publish).
                $this->gitInstance($inst->slug, ['add', $rel]);
                $stored[] = ['name' => basename($dest), 'path' => $rel, 'ref' => '@' . $rel, 'bucket' => $bucket];
            } else {
                $errors[] = $origName . ': write failed';
            }
        }
        Flight::jsonSuccess(['stored' => $stored, 'errors' => $errors], count($stored) . ' file(s) uploaded');
    }

    /** GET /aibuilder/uploads?id= — list uploaded files by bucket. JSON. */
    public function uploads($params = []): void {
        if (!$this->requireLevel($this->minLevel())) return;
        $inst = $this->ownedInstance($this->getParam('id', 0));
        if (!$inst) { Flight::jsonError('No such instance', 404); return; }
        $out = ['secure' => [], 'public' => []];
        foreach (['secure', 'public'] as $b) {
            $dir = $this->instanceDir($inst->slug) . '/' . $this->uploadBucketRel($b);
            if (!is_dir($dir)) continue;
            foreach (scandir($dir) as $f) {
                if ($f === '.' || $f === '..' || $f === '.gitkeep' || $f === '.htaccess') continue;
                $full = $dir . '/' . $f;
                if (!is_file($full)) continue;
                $rel = $this->uploadBucketRel($b) . '/' . $f;
                $out[$b][] = ['name' => $f, 'path' => $rel, 'ref' => '@' . $rel, 'size' => filesize($full)];
            }
        }
        Flight::jsonSuccess(['uploads' => $out]);
    }

    /** POST /aibuilder/deleteupload — remove an uploaded file. JSON. */
    public function deleteupload($params = []): void {
        if (!$this->requireLevel($this->minLevel())) return;
        if (!$this->validateCSRF()) return;
        $inst = $this->ownedInstance($this->getParam('id', 0));
        if (!$inst) { Flight::jsonError('No such instance', 404); return; }
        $bucket = $this->getParam('bucket', 'secure') === 'public' ? 'public' : 'secure';
        $name   = basename((string)$this->getParam('name', ''));
        if ($name === '' || $name === '.gitkeep') { Flight::jsonError('Invalid file', 400); return; }

        $relDir    = $this->uploadBucketRel($bucket);
        $bucketDir = realpath($this->instanceDir($inst->slug) . '/' . $relDir);
        $real      = realpath($this->instanceDir($inst->slug) . '/' . $relDir . '/' . $name);
        if (!$real || !$bucketDir || strpos($real, $bucketDir) !== 0 || !is_file($real)) {
            Flight::jsonError('Not found', 404); return;
        }
        $this->gitInstance($inst->slug, ['rm', '-f', '--cached', $relDir . '/' . $name]);
        @unlink($real);
        Flight::jsonSuccess([], 'Deleted');
    }
}
