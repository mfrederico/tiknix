<?php
namespace app\concepts\consent\mcptools;

use app\concepts\consent\{Inventory, Scan};

class ConsentScan extends \app\mcptools\BaseTool {
    public static string $name = 'consent_scan';
    public static string $description = '[Cookie consent] The page scan: a browser on the platform walks this site before answering the cookie question and lists the outside services, cookies and browser storage it finds. action "run" starts it (a few minutes; at most once per ten minutes), action "results" says what the last one found. Use "run" ONLY when a person has just asked for a consent scan in this conversation: never on your own initiative, after a build, or from a pipeline. Each scan signs in with a member account of its own on this site.';
    public static array $inputSchema = [
        'type' => 'object',
        'properties' => ['action' => ['type' => 'string', 'enum' => ['run', 'results'], 'description' => 'run: start a scan. results: what the last one found.']],
        'required' => ['action'],
    ];

    public function execute(array $args): string {
        $action = (string) ($args['action'] ?? '');
        try {
            if ($action === 'run') {
                $host = strtolower((string) (\app\Host::current() ?: parse_url((string) \Flight::get('app.baseurl'), PHP_URL_HOST)));
                if ($host === '') throw new \RuntimeException('this site has no address: set [app] baseurl in conf/config.ini');
                Scan::request($host);
                return json_encode(['ok' => true, 'host' => $host, 'said' => 'The scan has started. It takes a few minutes. Ask for action "results" after that, or see /consent.'], JSON_PRETTY_PRINT);
            }
            if ($action === 'results') {
                $latest = Scan::latest(Inventory::all(), (string) (\Flight::get('app.session_name') ?: 'APP_SESSION'));
                return json_encode(['ok' => true, 'scan' => $latest ?? 'No scan has run on this site.', 'page' => '/consent'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            }
            return json_encode(['ok' => false, 'error' => 'action is run or results']);
        } catch (\RuntimeException | \UnexpectedValueException $e) {
            return json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
    }
}
