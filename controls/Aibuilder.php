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

    /** POST /aibuilder/checkpoint?id= — take a rollback checkpoint. JSON. */
    public function checkpoint($params = []): void {
        if (!$this->requireLevel($this->minLevel())) return;
        if (!$this->validateCSRF()) return;
        $inst = $this->ownedInstance($this->getParam('id', 0));
        if (!$inst) { Flight::jsonError('No such instance', 404); return; }

        $label = preg_replace('/[^a-z0-9]/i', '', (string)$this->getParam('label', ''));
        $out = $this->runScript('snapshot-instance.sh', array_filter([self::APP, $inst->slug, $label]));
        if ($out['ok']) Flight::jsonSuccess(['log' => $out['out']], 'Checkpoint saved');
        else            Flight::jsonError('Checkpoint failed: ' . substr(trim($out['out']), -300), 500);
    }

    /** GET /aibuilder/checkpoints?id= — list checkpoints. JSON. */
    public function checkpoints($params = []): void {
        if (!$this->requireLevel($this->minLevel())) return;
        $inst = $this->ownedInstance($this->getParam('id', 0));
        if (!$inst) { Flight::jsonError('No such instance', 404); return; }

        $out = $this->runScript('rollback-instance.sh', ['--list', self::APP, $inst->slug]);
        $items = [];
        foreach (explode("\n", $out['out']) as $line) {
            if (preg_match('/^\s*(checkpoint-\S+)\s+(\S+)\s+(\S+)/', $line, $m)) {
                $items[] = ['name' => $m[1], 'date' => $m[2], 'commit' => $m[3]];
            }
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
}
