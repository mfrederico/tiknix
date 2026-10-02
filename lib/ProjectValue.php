<?php
/**
 * ProjectValue — what a project would have cost as a custom build, from the lines of code
 * that were written for it.
 *
 * Deterministic and explainable: the project's OWN code in its container (controllers, models,
 * libraries, services, views, its routes, scripts and front-end files — never the runtime,
 * vendored code or binary assets), non-blank lines, MINUS the lines every project starts with
 * (the empty-app template), so a fresh project is worth nothing yet. Tests count at half
 * weight (real work, not what a client pays for); database seeds are shown but not counted
 * (catalog data is not engineering — partsdna had 17,000 lines of it). Then two settings in
 * conf/config.ini [value]:
 *
 *   lines_per_hour   how many finished lines a web developer delivers in an hour. Calibrated
 *                    on the owner's own quotes (2026-10-02): dealeryes ≈ 77,000 lines quoted
 *                    at $85k, partsdna ≈ 28,000 at $50k — about $1.30 a line, which at a
 *                    mid-level developer's $73 an hour is 56 lines an hour.
 *   hourly_rate      what that developer costs, in dollars
 *
 * Beside the estimate, how long tiknix took: from the first Builder task started to the last
 * one finished (the project's board). Computed by scripts/project-value.php (cron, nightly)
 * and cached as JSON in the project's workspace; the Projects page only reads the cache.
 */

namespace app;

class ProjectValue {

    /** The areas counted, in the order the breakdown shows them. */
    public const AREAS = ['controls' => 'Controllers', 'models' => 'Models', 'lib' => 'Libraries', 'services' => 'Services', 'views' => 'Views',
                          'routes' => 'Routes', 'mcptools' => 'MCP tools', 'scripts' => 'Scripts', 'public' => 'Front-end files', 'tests' => 'Tests', 'database' => 'Database seeds'];
    /** How much of an area's lines count as engineering: tests half, data seeds none. */
    public const WEIGHT = ['tests' => 0.5, 'database' => 0.0];
    private const SKIP = '\.(png|jpe?g|gif|webp|ico|svg|woff2?|ttf|eot|pdf|zip|gz|lock|min\.js|min\.css|map)$';
    public const TEMPLATE = '/var/www/html/default/tiknix-app';

    /** @return array{lines_per_hour:float,hourly_rate:float} the two assumptions, which must be set */
    public static function assumptions(): array {
        $lph = (float) \Flight::get('value.lines_per_hour'); $rate = (float) \Flight::get('value.hourly_rate');
        if ($lph <= 0 || $rate <= 0) throw new \RuntimeException('conf/config.ini needs [value] lines_per_hour and hourly_rate for the project value estimate');
        return ['lines_per_hour' => $lph, 'hourly_rate' => $rate];
    }

    /** The shell that counts non-blank lines per area in a git working copy (runs here and in a container). */
    private static function countScript(): string {
        $areas = implode(' ', array_keys(self::AREAS));
        return 'for d in ' . $areas . '; do if [ -d "$d" ]; then n=$(git ls-files -z -- "$d" | grep -z -v -E ' . escapeshellarg(self::SKIP) . ' | xargs -0 cat 2>/dev/null | grep -c -v "^[[:space:]]*$"); else n=0; fi; printf "%s %s\n" "$d" "$n"; done';
    }

    /** @return array<string,int> area => lines, from the script's output */
    private static function parse(string $out): array {
        $lines = [];
        foreach (explode("\n", trim($out)) as $l) if (preg_match('/^(\w+) (\d+)$/', trim($l), $m) && isset(self::AREAS[$m[1]])) $lines[$m[1]] = (int) $m[2];
        if (count($lines) !== count(self::AREAS)) throw new \RuntimeException('the line count came back incomplete: ' . mb_substr($out, 0, 200));
        return $lines;
    }

    /** The empty-app template's lines per area: what every project starts with. */
    public static function baseline(): array {
        if (!is_dir(self::TEMPLATE . '/.git')) throw new \RuntimeException('the app template is not at ' . self::TEMPLATE);
        exec('cd ' . escapeshellarg(self::TEMPLATE) . ' && ' . self::countScript() . ' 2>&1', $out, $code);
        if ($code !== 0) throw new \RuntimeException('counting the template failed: ' . implode(' ', $out));
        return self::parse(implode("\n", $out));
    }

    public static function cacheFile(object $inst): string { return \Model_Instance::dirOf($inst) . '/data/value.json'; }

    /** Count, estimate, cache. @return array the summary written */
    public static function compute(object $inst, ?array $baseline = null): array {
        if (!\Model_Instance::tenantRow($inst)) throw new \RuntimeException("{$inst->slug} does not run in a container");
        $a = self::assumptions();
        $baseline = $baseline ?? self::baseline();
        [$code, $out] = TenantHost::ssh($inst, 'app', 'cd /srv/app && ' . self::countScript(), null, 120);
        if ($code !== 0) throw new \RuntimeException("{$inst->slug}'s code could not be counted: " . mb_substr(trim($out), 0, 200));
        $lines = self::parse($out);
        $areas = []; $written = 0.0;
        foreach (self::AREAS as $k => $label) {
            $n = max(0, $lines[$k] - ($baseline[$k] ?? 0));          // what this project added over the template
            $w = self::WEIGHT[$k] ?? 1.0;
            if ($n > 0) $areas[] = ['area' => $label, 'lines' => $n, 'weight' => $w];
            $written += $n * $w;
        }
        $written = (int) round($written);
        $hours = round($written / $a['lines_per_hour'], 1);
        $summary = [
            'computed_at' => date('c'), 'lines' => $written, 'areas' => $areas,
            'lines_per_hour' => $a['lines_per_hour'], 'hourly_rate' => $a['hourly_rate'],
            'hours' => $hours, 'dollars' => (int) round($hours * $a['hourly_rate']),
            'tiknix' => self::elapsed($inst),
        ];
        $file = self::cacheFile($inst);
        if (!is_dir(dirname($file)) && !@mkdir(dirname($file), 0775, true)) throw new \RuntimeException('could not create ' . dirname($file));
        if (file_put_contents($file, json_encode($summary, JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX) === false) throw new \RuntimeException("could not write {$file}");
        return $summary;
    }

    /** The cached summary, or null when none has been computed yet. */
    public static function read(object $inst): ?array {
        $file = self::cacheFile($inst);
        if (!is_file($file)) return null;
        $j = json_decode((string) file_get_contents($file), true);
        return is_array($j) && isset($j['dollars']) ? $j : null;
    }

    /**
     * How long tiknix took: the Builder's merged tasks on the project's board, first started to
     * last finished, and how many. Null when the board has none.
     * @return array{tasks:int,first:string,last:string,minutes:int}|null
     */
    private static function elapsed(object $inst): ?array {
        $db = \Model_Instance::dirOf($inst) . '/data/workbench.db';
        if (!is_file($db)) return null;
        try {
            $pdo = new \PDO('sqlite:' . $db, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            $cols = array_column($pdo->query('PRAGMA table_info(workbenchtask)')->fetchAll(\PDO::FETCH_ASSOC), 'name');
            foreach (['status', 'started_at', 'completed_at'] as $c) if (!in_array($c, $cols, true)) return null;
            $r = $pdo->query("SELECT COUNT(*) AS n, MIN(started_at) AS first, MAX(completed_at) AS last FROM workbenchtask WHERE status = 'merged' AND started_at != '' AND completed_at != ''")->fetch(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            error_log("ERROR ProjectValue: {$inst->slug}'s board could not be read: " . $e->getMessage());
            return null;
        }
        if (!$r || (int) $r['n'] === 0) return null;
        $mins = (int) round((strtotime((string) $r['last']) - strtotime((string) $r['first'])) / 60);
        return ['tasks' => (int) $r['n'], 'first' => (string) $r['first'], 'last' => (string) $r['last'], 'minutes' => max(0, $mins)];
    }
}
