<?php
/**
 * Provision — HMAC-authenticated server-to-server provisioning endpoint. The workbench
 * sidecar (AI Builder) is read-only to core, so instead of writing the instance registry
 * itself it signs {member_id, op, params, exp} with the shared sidecar secret and POSTs
 * here. Core verifies the signature, then dispatches to ProvisionService, which owns the
 * registry mutation plus capricorn provisioning. Writes and custody stay in core.
 *
 * Route provision::call = 101 (PUBLIC-reachable) — it SELF-authenticates via the HMAC, the
 * same pattern as /mcp/message. No core web session is involved (the sidecar has none).
 */
namespace app;

use \Flight as Flight;
use app\BaseControls\Control;

class Provision extends Control {

    private const OPS = ['create', 'fork', 'delete', 'share'];

    /** POST /provision/call — {payload, sig}. payload = json{member_id, op, params, exp}. */
    public function call($params = []): void {
        $req     = Flight::request();
        $payload = (string) ($req->data->payload ?? $_POST['payload'] ?? '');
        $sig     = (string) ($req->data->sig ?? $_POST['sig'] ?? '');
        if ($payload === '' || $sig === '') { Flight::jsonError('Missing signature', 400); return; }

        $secret = $this->secret();
        if ($secret === '' || !hash_equals(hash_hmac('sha256', $payload, $secret), $sig)) {
            Flight::jsonError('Invalid signature', 403); return;
        }

        $claims = json_decode($payload, true);
        if (!is_array($claims) || (int) ($claims['exp'] ?? 0) < time()) { Flight::jsonError('Request expired', 403); return; }

        $memberId = (int) ($claims['member_id'] ?? 0);
        $op       = (string) ($claims['op'] ?? '');
        $p        = (array) ($claims['params'] ?? []);
        if ($memberId <= 0) { Flight::jsonError('No member', 403); return; }
        if (!in_array($op, self::OPS, true)) { Flight::jsonError('Unknown op', 400); return; }

        $svc = new ProvisionService();
        if (!method_exists($svc, $op)) { Flight::jsonError('Op not implemented yet', 501); return; }

        try {
            $res = $svc->$op($memberId, $p);
        } catch (\Throwable $e) {
            $this->logger->error('provision op failed', ['op' => $op, 'err' => $e->getMessage()]);
            Flight::jsonError('Provisioning error: ' . $e->getMessage(), 500); return;
        }

        if (!empty($res['ok'])) {
            $this->logger->info('provision ok', ['op' => $op, 'member' => $memberId, 'slug' => $res['slug'] ?? '']);
            Flight::jsonSuccess($res);
        } else {
            Flight::jsonError((string) ($res['error'] ?? 'Provisioning failed'), (int) ($res['code'] ?? 500));
        }
    }

    /** The shared secret is the workbench sidecar's sso_secret (core [sidecar.workbench]). */
    private function secret(): string {
        $reg = \app\Sidecar\Registry::get('workbench');
        return (string) ($reg['sso_secret'] ?? '');
    }
}
