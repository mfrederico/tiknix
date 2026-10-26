<?php
namespace app\concepts\messaging;

use \Flight as Flight;
use app\BaseControls\Control;

/**
 * Where the messaging service tells this app what happened to a message: POST /msghook/event, signed with the
 * site's hook key (lib/Events.php). The event becomes this app's own (AppEvents::fire) and every pipeline whose
 * trigger names it starts; the answer says which. PUBLIC on purpose: the signature is the check.
 */
class Msghook extends Control {

    // nav: none — the messaging service posts here

    /**
     * POST /msghook/hourly — what the hourly pipeline calls (an http step to {app.url}/msghook/hourly, signed as the app
     * itself by the runtime's SelfCall): mirror this app into the service, then feed the digest and the app's own nudges
     * (Runner::hourly). The answer lists what was done. Anyone else is refused 403.
     */
    public function hourly($params = []) {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { Flight::json(['ok' => false, 'reason' => 'POST only'], 405); return; }
        if (!class_exists('\\app\\SelfCall')) { Flight::json(['ok' => false, 'reason' => 'this runtime has no SelfCall (alpha.235 or newer): the hourly run cannot be called over HTTP yet'], 500); return; }
        if (!\app\SelfCall::verify()) { Flight::json(['ok' => false, 'reason' => \app\SelfCall::reason()], 403); return; }
        set_time_limit(0); ignore_user_abort(true);
        try {
            $lines = Runner::hourly();
        } catch (\Throwable $e) {
            $this->logger?->error('Msghook: the hourly run failed: ' . $e->getMessage());
            Flight::json(['ok' => false, 'reason' => $e->getMessage()], 500);
            return;
        }
        $this->logger?->info('Msghook: hourly run', ['site' => Client::site(), 'lines' => count($lines)]);
        Flight::json(['ok' => true, 'site' => Client::site(), 'lines' => $lines], 200);
    }

    public function event($params = []) {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { Flight::json(['accepted' => false, 'reason' => 'POST only'], 405); return; }
        $raw = (string) file_get_contents('php://input');
        $sig = (string) ($_SERVER['HTTP_X_MESSAGING_SIGNATURE'] ?? '');
        $why = Events::verify($raw, $sig, Events::hookKey());
        if ($why === 'signature does not match') $why = Events::verify($raw, $sig, Events::hookKey(true));   // the key may have been rotated
        if ($why !== null) {
            $this->logger?->warning('Msghook: refused — ' . $why, ['host' => Client::site()]);
            Flight::json(['accepted' => false, 'reason' => $why], 403);
            return;
        }
        $p = json_decode($raw, true);
        if (!is_array($p)) { Flight::json(['accepted' => false, 'reason' => 'the body is not JSON'], 400); return; }
        try {
            $fired = Events::fire($p);
        } catch (\InvalidArgumentException $e) {
            Flight::json(['accepted' => false, 'reason' => $e->getMessage()], 400);
            return;
        }
        $this->logger?->info('Msghook: ' . (string) $p['event'], ['member' => (int) ($p['member_id'] ?? 0), 'campaign' => (int) ($p['campaign']['id'] ?? 0), 'started' => count($fired)]);
        Flight::json(['accepted' => true, 'event' => (string) $p['event'], 'started' => array_map(fn($f) => ['slug' => $f['slug'], 'run_id' => $f['run_id'], 'error' => $f['error']], $fired)], 200);
    }
}
