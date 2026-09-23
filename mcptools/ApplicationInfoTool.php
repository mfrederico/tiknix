<?php
/**
 * application_info — what this install is: versions, database, engines, plugins.
 *
 * The first question of any diagnosis ("which PHP, which tiknix, which database, is this
 * core or a project, what is switched on") answered from the running process, not from
 * a document. Where a fact cannot be established, the line says why instead of showing a
 * placeholder: a version that reads "unknown" would be believed.
 */

namespace app\mcptools;

use app\Bean;
use app\Concepts;
use app\EngineRegistry;

class ApplicationInfoTool extends BaseTool {

    public static string $name = 'application_info';
    public static string $description = 'What this install is: PHP version, tiknix version (git), whether it is the core install or a project and whether the pool is isolated, the app name and base URL, database driver, the AI engines registered and the default one, and every plugin (concept) installed — enabled or not, with catalog version. Call it first when orienting or diagnosing.';
    public static array $inputSchema = ['type' => 'object', 'properties' => [], 'required' => []];

    public function execute(array $args): string {
        $root = dirname(__DIR__);
        $out = "# application_info\n\n";
        $out .= '- **PHP** ' . PHP_VERSION . ' (' . PHP_SAPI . ")\n";
        $out .= '- **tiknix** ' . self::version($root) . "\n";
        $out .= '- **install** ' . (is_core_install() ? 'core (the control plane)' : 'a project (' . basename($root) . ')')
              . (is_file($root . '/.fpm-isolated') ? ', isolated php-fpm pool' : '') . "\n";
        $out .= '- **app** ' . self::flight('app.name') . ' — ' . self::flight('app.baseurl') . ' (env ' . self::flight('app.environment') . ', timezone ' . date_default_timezone_get() . ")\n";
        $adapter = Bean::getDatabaseAdapter();
        $out .= '- **database** ' . ($adapter ? $adapter->getDatabase()->getDatabaseType() . ', ' . count(Bean::inspect()) . ' tables' : 'NOT CONNECTED') . "\n";
        try {
            $out .= '- **engines** ' . implode(', ', EngineRegistry::names()) . ' (default ' . EngineRegistry::defaultEngine() . ")\n";
        } catch (\Throwable $e) {
            $out .= '- **engines** could not be read: ' . $e->getMessage() . "\n";
        }
        $out .= "\n## Plugins (concepts)\n\n";
        $registry = Concepts::instance();
        $scan = $registry->scan();
        if (!$scan) {
            $out .= "None installed (" . Concepts::DIR . "/ is empty or absent).\n";
        } else {
            $out .= "| name | state | version | origin |\n|---|---|---|---|\n";
            foreach ($scan as $name => $row) {
                $m = $row['manifest'];
                $state = $m === null ? 'BROKEN: ' . $row['error'] : ($row['enabled'] ? 'enabled' : 'disabled');
                $prov = $registry->provenance($name);
                $origin = $prov ? 'catalog v' . ($prov['version'] ?? '?') . ', installed ' . substr((string) ($prov['installed_at'] ?? ''), 0, 10) : 'authored here';
                $out .= "| {$name} | {$state} | " . ($m ? $m->version : '') . " | {$origin} |\n";
            }
            $out .= "\nA disabled plugin is inert: none of its classes load, none of its routes answer.\n";
        }
        return $out;
    }

    private static function flight(string $key): string {
        $v = \Flight::get($key);
        return $v === null || $v === '' ? "(no {$key} in conf/config.ini)" : (string) $v;
    }

    /** git describe, or the reason there is none — never a made-up number. */
    private static function version(string $root): string {
        $out = []; $code = 0;
        @exec('git -C ' . escapeshellarg($root) . ' describe --tags --always 2>&1', $out, $code);
        $said = trim(implode(' ', $out));
        if ($code === 0 && $said !== '') return $said;
        return 'not determinable here (git describe ' . ($said !== '' ? "said: {$said}" : "exited {$code}") . ')';
    }
}
