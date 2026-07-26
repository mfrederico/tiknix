<?php
/**
 * Publish — the ONE door through which a publish target actually runs.
 *
 * Publishing is authored in the publisher.tiknix sidecar and executed by a pipeline in
 * the project, but the work itself has to happen on the CONTROL PLANE: the GitHub PAT and
 * the hypervisor credentials live here, encrypted, and neither an instance nor a sidecar
 * may hold them. So instead of copying credentials outward, the instance asks core to
 * publish it, and core runs the driver.
 *
 * AUTHENTICATION is the instance's own broker key (`brk_`, conf/broker.ini) — the same
 * credential and the same boundary lib/Pipeline/Steps/ConnectionStep already uses for
 * store calls. The instance is resolved FROM THE KEY, never from the request body, so a
 * pipeline can only ever publish the instance it belongs to. That is the whole security
 * model here: there is no instance_id parameter to tamper with.
 *
 * Route publish::run = 101 (PUBLIC-reachable) — it SELF-authenticates via that bearer
 * key, the same contract as mcp::message and pipeline::trigger. PUBLIC means reachable,
 * not unprotected. See lib/PermissionCache::defaultLevelFor().
 */

namespace app;

use \Flight as Flight;
use app\BaseControls\Control;
use app\Publish\PublishRegistry;
use RedBeanPHP\R;

class Publish extends Control {

    /** POST /publish/run — body {target, op?: deploy|refresh|status, config?, opts?}. */
    public function run($params = []): void {
        $key = $this->brokerKey();
        if (!$key) { Flight::jsonError('A valid broker key is required.', 401); return; }

        $instanceId = (int) ($key->instanceId ?? 0);
        if ($instanceId <= 0) { Flight::jsonError('Broker key is not bound to an instance.', 403); return; }
        $inst = R::load('instance', $instanceId);
        if (!$inst->id) { Flight::jsonError('Instance not found.', 404); return; }

        $body   = $this->jsonBody();
        $target = (string) ($body['target'] ?? '');
        $op     = strtolower((string) ($body['op'] ?? 'deploy'));
        if (!in_array($op, ['deploy', 'refresh', 'status'], true)) { Flight::jsonError('Unknown op.', 400); return; }

        $driver = PublishRegistry::driver($target);
        if (!$driver) { Flight::jsonError('Unknown publish target: ' . $target, 400); return; }

        // Targets are not equally cheap, and this door has no logged-in person to ask —
        // the pipeline runs unattended. So authorize the INSTANCE OWNER at the level the
        // driver demands. Without this, one door would let any member provision a
        // container just by naming a different target, bypassing the ADMIN gate the
        // Connections hub has always had on exactly that action.
        $owner = R::load('member', (int) $inst->memberId);
        $level = (int) ($owner->level ?? LEVELS['PUBLIC']);
        $need  = $driver::minLevel($op);
        if (!$owner->id || $level > $need) {
            $this->logger->warning('publish denied', ['target' => $target, 'op' => $op,
                'instance' => $instanceId, 'owner_level' => $level, 'needs' => $need]);
            Flight::jsonError('This project\'s owner is not permitted to ' . $op . ' the ' . $target . ' target.', 403);
            return;
        }

        // Target config lives with the target. The pipeline may pass per-run overrides
        // (a domain, sizing), but never credentials — those are resolved here by driver.
        $config = (array) ($body['config'] ?? []);
        $opts   = (array) ($body['opts'] ?? []);

        try {
            if ($op === 'status') { Flight::jsonSuccess($driver->status($inst, $config)); return; }
            $res = $op === 'refresh' ? $driver->refresh($inst, $config, $opts)
                                     : $driver->deploy($inst, $config, $opts);
        } catch (\Throwable $e) {
            $this->logger->error('publish failed', ['target' => $target, 'instance' => $instanceId, 'err' => $e->getMessage()]);
            Flight::jsonError('Publish error: ' . $e->getMessage(), 500); return;
        }

        if (empty($res['ok'])) {
            $this->logger->warning('publish rejected', ['target' => $target, 'instance' => $instanceId, 'err' => $res['error'] ?? '']);
            Flight::jsonError((string) ($res['error'] ?? 'Publish failed'), 502); return;
        }
        $this->logger->info('published', ['target' => $target, 'instance' => $instanceId]);
        Flight::jsonSuccess($res, (string) ($res['message'] ?? 'Published'));
    }

    // ---- auth ---------------------------------------------------------------

    /**
     * The `apikey` bean for a presented broker token, or null.
     *
     * Broker keys are stored as a sha-256 hash and never in plaintext, so a DB read
     * cannot yield a usable token — the lookup is by hash, exactly as Mcp does it.
     */
    private function brokerKey() {
        $token = $this->bearer();
        if ($token === '') return null;
        $key = Bean::findOne('apikey', 'token_hash = ? AND key_class = ? AND is_active = 1',
            [EncryptionService::hashHex($token), 'broker']);
        if (!$key || !$key->id) return null;
        if ($key->expiresAt && strtotime((string) $key->expiresAt) < time()) return null;

        $key->lastUsedAt = date('Y-m-d H:i:s');
        $key->lastUsedIp = $_SERVER['REMOTE_ADDR'] ?? null;
        Bean::store($key);
        return $key;
    }

    private function jsonBody(): array {
        $raw = (string) (Flight::request()->getBody() ?: file_get_contents('php://input'));
        $d = json_decode($raw, true);
        return is_array($d) ? $d : [];
    }

    private function bearer(): string {
        $headers = function_exists('getallheaders') ? (getallheaders() ?: []) : [];
        $h = '';
        foreach ($headers as $k => $v) if (strcasecmp($k, 'Authorization') === 0) { $h = (string) $v; break; }
        if ($h === '') $h = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        return stripos($h, 'bearer ') === 0 ? trim(substr($h, 7)) : '';
    }
}
