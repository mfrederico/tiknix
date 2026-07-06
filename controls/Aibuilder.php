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
        $parent->engine         = $inst->engine;
        $parent->memberId       = (int)$this->member->id;
        $parent->planCheckpoint = $tag;               // fluid: adds plan_checkpoint
        $parent->createdAt      = $now;
        R::store($parent);

        $subs = [];
        foreach ($plan['subtasks'] as $st) {
            if (empty($st['title'])) continue;
            $t = R::dispense('workbenchtask');
            $t->title        = mb_substr((string)$st['title'], 0, 200);
            $t->description  = (string)($st['description'] ?? '');
            $t->taskType     = 'feature';
            $t->priority     = (int)($st['priority'] ?? 3);
            $t->status       = 'pending';
            $t->parentTaskId = (int)$parent->id;
            $t->instanceId   = (int)$inst->id;
            $t->engine       = in_array($st['engine'] ?? '', ['claude', 'qwen'], true) ? $st['engine'] : $inst->engine;
            $t->relatedFiles = is_array($st['files'] ?? null) ? implode("\n", $st['files']) : '';
            $t->memberId     = (int)$this->member->id;
            $t->createdAt    = $now;
            R::store($t);
            $subs[] = ['id' => (int)$t->id, 'title' => $t->title, 'priority' => (int)$t->priority, 'engine' => $t->engine];
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
                'subtasks' => array_map(fn($s) => [
                    'id' => (int)$s->id, 'title' => $s->title, 'description' => $s->description,
                    'priority' => (int)$s->priority, 'engine' => $s->engine, 'status' => $s->status,
                    'files' => $s->relatedFiles,
                ], array_values($subs)),
            ];
        }
        Flight::jsonSuccess(['plans' => $plans]);
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

    // --- Uploads: secure (private/gitignored) + public (published) ------------

    private const UPLOAD_MAX = 52428800; // 50 MB per file

    /** Ensure uploads/{secure,public} exist; secure is gitignored, public is tracked. */
    private function ensureUploadDirs(string $slug): void {
        $base = $this->instanceDir($slug) . '/uploads';
        @mkdir($base . '/secure', 0775, true);
        @mkdir($base . '/public', 0775, true);
        $keep = $base . '/public/.gitkeep';
        if (!is_file($keep)) @file_put_contents($keep, '');
        // Never publish secure uploads: ignore them in the instance repo.
        $gi   = $this->instanceDir($slug) . '/.gitignore';
        $line = '/uploads/secure/';
        $cur  = is_file($gi) ? (string)file_get_contents($gi) : '';
        if (strpos($cur, $line) === false) {
            @file_put_contents($gi, rtrim($cur, "\n") . "\n" . $line . "\n", LOCK_EX);
        }
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
        $destDir = $this->instanceDir($inst->slug) . '/uploads/' . $bucket;

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
                $rel = 'uploads/' . $bucket . '/' . basename($dest);
                // Track public uploads so they publish with the next checkpoint.
                if ($bucket === 'public') $this->gitInstance($inst->slug, ['add', $rel]);
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
            $dir = $this->instanceDir($inst->slug) . '/uploads/' . $b;
            if (!is_dir($dir)) continue;
            foreach (scandir($dir) as $f) {
                if ($f === '.' || $f === '..' || $f === '.gitkeep') continue;
                $full = $dir . '/' . $f;
                if (!is_file($full)) continue;
                $rel = 'uploads/' . $b . '/' . $f;
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

        $bucketDir = realpath($this->instanceDir($inst->slug) . '/uploads/' . $bucket);
        $real      = realpath($this->instanceDir($inst->slug) . '/uploads/' . $bucket . '/' . $name);
        if (!$real || !$bucketDir || strpos($real, $bucketDir) !== 0 || !is_file($real)) {
            Flight::jsonError('Not found', 404); return;
        }
        if ($bucket === 'public') $this->gitInstance($inst->slug, ['rm', '-f', '--cached', 'uploads/public/' . $name]);
        @unlink($real);
        Flight::jsonSuccess([], 'Deleted');
    }
}
