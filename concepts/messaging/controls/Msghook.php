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
