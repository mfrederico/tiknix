<?php
/**
 * QuickbooksWebhook — Intuit's change notices for every company connected through the platform's
 * Intuit app arrive at ONE address, POST /webhook/quickbooks on core. Each change names its company
 * (Intuit's realmId), never a project; core routes it by the company to the project(s) that connected
 * it (app\ConnectorRoutes) and forwards it through that project's own door, /connectorapi/event, with
 * its broker key — wherever the project runs. A notice names what changed ("Invoice 42, Update"),
 * never its contents: the project fetches the record itself, with its own access.
 *
 *   reading    the signature and the changes: app\QuickbooksNotice (the runtime's — an app with its own
 *              Intuit app reads its own notices with it); the token is conf/quickbooks.ini [webhook] verifier_token
 *   handle()   answers Intuit at once (it wants a reply within seconds) and forwards in the background
 *   forward()  scripts/connector-forward.php: per company, per project; a project that answers
 *              "not mine" is forgotten (the company was disconnected there)
 */

namespace app;

class QuickbooksWebhook {
    public const CONNECTOR = 'quickbooks';

    /** app\Webhook::$handlers['quickbooks'] — registered in lib/controlplane.php. */
    public static function handle(array $params = []): void {
        $body = (string) file_get_contents('php://input');
        $verifier = QuickbooksNotice::verifier();
        if ($verifier === '') {
            error_log('ERROR QuickbooksWebhook: a notice arrived and conf/quickbooks.ini has no [webhook] verifier_token — set it from the Intuit developer portal (Webhooks). Not accepted; Intuit will retry.');
            \Flight::json(['error' => 'not configured'], 503); return;
        }
        if (!QuickbooksNotice::verify($body, (string) ($_SERVER['HTTP_INTUIT_SIGNATURE'] ?? ''), $verifier)) {
            \Flight::get('log')?->warning('QuickbooksWebhook: refused, the signature does not match', ['from' => (string) ($_SERVER['REMOTE_ADDR'] ?? '')]);
            \Flight::json(['error' => 'bad signature'], 401); return;
        }
        try { $changes = QuickbooksNotice::parse($body); }
        catch (\Throwable $e) { \Flight::get('log')?->warning('QuickbooksWebhook: unreadable notice', ['error' => $e->getMessage()]); \Flight::json(['accepted' => false], 200); return; }
        if (!$changes) {
            // Signed by Intuit and nothing in it read as a change: a format this does not know yet. Said, with
            // what it did hold — never accepted as "nothing happened".
            $j = json_decode($body, true);
            $types = is_array($j) ? (array_is_list($j) ? array_column($j, 'type') : array_keys($j)) : [];
            error_log('ERROR QuickbooksWebhook: a signed notice held no change it could read — its ' . (is_array($j) && array_is_list($j) ? 'event types' : 'keys') . ': '
                . implode(', ', array_slice(array_map('strval', $types), 0, 10)) . ' — ' . mb_substr($body, 0, 400));
            \Flight::json(['accepted' => true, 'changes' => 0], 200); return;
        }
        // Answered now, forwarded after: a project that is slow or down must not make Intuit retry everyone's.
        $dir = Paths::root() . '/data/connector-events';
        if (!is_dir($dir) && !@mkdir($dir, 0770, true)) { error_log("ERROR QuickbooksWebhook: could not create {$dir}"); \Flight::json(['error' => 'storage'], 500); return; }
        $file = $dir . '/' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.json';
        if (file_put_contents($file, json_encode(['connector' => self::CONNECTOR, 'changes' => $changes])) === false) { error_log("ERROR QuickbooksWebhook: could not write {$file}"); \Flight::json(['error' => 'storage'], 500); return; }
        exec('nohup env -u TIKNIX_WORKBENCH_DB php ' . escapeshellarg(Paths::root() . '/scripts/connector-forward.php') . ' ' . escapeshellarg($file)
            . ' >> ' . escapeshellarg(Paths::root() . '/log/connector-forward.log') . ' 2>&1 &');
        \Flight::get('log')?->info('QuickbooksWebhook: accepted', ['changes' => count($changes), 'companies' => count(array_unique(array_column($changes, 'company')))]);
        \Flight::json(['accepted' => true, 'changes' => count($changes)], 200);
    }

    /**
     * Send each project its own companies' changes. Returns a line per company and project.
     * $ask: ConnectorPush::ask (passed in, so the routing can be tested without a network).
     */
    public static function forward(array $changes, callable $ask): array {
        $lines = [];
        $byCompany = [];
        foreach ($changes as $c) $byCompany[(string) $c['company']][] = array_diff_key($c, ['company' => 1]);
        foreach ($byCompany as $company => $list) {
            $projects = ConnectorRoutes::forCompany(self::CONNECTOR, (string) $company);
            if (!$projects) { $lines[] = "company {$company}: no project connected it — " . count($list) . ' change(s) dropped'; continue; }
            foreach ($projects as $iid) {
                try {
                    $r = $ask($iid, '/connectorapi/event', ['connector' => self::CONNECTOR, 'company' => (string) $company, 'changes' => $list]);
                    $d = (array) ($r['data'] ?? $r);
                    if (array_key_exists('known', $d) && !$d['known']) {
                        ConnectorRoutes::forget($iid, self::CONNECTOR, (string) $company);
                        $lines[] = "company {$company} → project {$iid}: it no longer has that company — forgotten";
                    } else {
                        $what = implode(', ', array_map(fn($c) => ($c['entity'] ?? '?') . ' ' . ($c['id'] ?? '') . ' ' . strtolower((string) ($c['operation'] ?? '')), array_slice($list, 0, 6)));
                        $lines[] = "company {$company} → project {$iid}: " . count($list) . " change(s) ({$what}), " . (int) ($d['fired'] ?? 0) . ' pipeline run(s) started';
                    }
                } catch (\Throwable $e) {
                    $lines[] = "company {$company} → project {$iid}: ERROR " . $e->getMessage();
                }
            }
        }
        return $lines;
    }
}
