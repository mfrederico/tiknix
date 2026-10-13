<?php
namespace app;

/**
 * Which pages are used, from the count the permission check already keeps.
 *
 * Every permitted request adds one to its rule's `validcount` (PermissionCache). That makes a
 * rule's row a lifetime tally for one page — or, for a `controller::*` rule, for every page of a
 * controller at once. This reads those tallies into the question an owner can act on: what is
 * here that nobody opens?
 *
 * What the tally is NOT, and the report says so rather than hiding it:
 *   - not per person, and not recent: a page opened 4,000 times last winter looks busy;
 *   - not only people: a page that polls, a timer, a webhook and a bot each count like a visit,
 *     and a rule anyone may use (PUBLIC) counts every crawler;
 *   - not per page under a wildcard rule: one number stands for all of that controller's pages.
 *
 * So the verdicts lean on what the tally CAN say with confidence — "never", "almost never",
 * "the page this rule was for is gone" — and name what cannot be told.
 */
class PageUse {
    /** A rule younger than this has not had time to be opened: it is "new", not "never opened". */
    public const NEW_DAYS = 14;
    /** "Rarely": under one opening a week on average, for a rule at least this old. */
    public const RARE_AFTER_DAYS = 30;
    public const RARE_PER_WEEK = 1.0;

    public const VERDICTS = [
        'gone'    => 'The page is gone',
        'elsewhere' => 'Served from somewhere else',
        'never'   => 'Never opened',
        'rare'    => 'Rarely opened',
        'unclear' => 'Cannot be told apart',
        'new'     => 'Too new to say',
        'used'    => 'In use',
    ];

    /**
     * Judge each rule.
     *
     * @param array<int,array{control:string,method:string,level:int,validcount:?int,created_at:?string,description:?string}> $rules
     * @param callable(string $control): ?array $pagesOf  the public pages of a controller (lower-case method names), or null when no such controller exists
     * @param array<string,true> $linked  "control/method" keys (and "control/*") a menu links to
     * @return array<int,array{control:string,method:string,level:int,hits:int,days:int,per_week:float,pages:int,linked:bool,verdict:string,why:string,description:string}>
     */
    public static function judge(array $rules, callable $pagesOf, array $linked, int $now): array {
        // a controller's own specific rules: its wildcard does not count those pages
        $specific = [];
        foreach ($rules as $r) if ((string) $r['method'] !== '*') $specific[strtolower((string) $r['control'])][strtolower((string) $r['method'])] = true;

        $out = [];
        foreach ($rules as $r) {
            $control = strtolower(trim((string) $r['control'])); $method = strtolower(trim((string) $r['method']));
            $hits = (int) ($r['validcount'] ?? 0);
            $made = strtotime((string) ($r['created_at'] ?? '')) ?: 0;
            $days = $made > 0 ? max(1, (int) floor(($now - $made) / 86400)) : 0;
            $perWeek = $days > 0 ? round($hits / $days * 7, 2) : 0.0;
            $pages = $pagesOf($control);
            $covered = 0;
            if ($pages !== null) $covered = $method === '*' ? count(array_diff($pages, array_keys($specific[$control] ?? []))) : (in_array($method, $pages, true) ? 1 : 0);
            $isLinked = isset($linked["{$control}/{$method}"]) || ($method === '*' && self::anyLinked($linked, $control)) || ($method === 'index' && isset($linked["{$control}/index"]));

            // No controller of that name in this install's own code. Counted anyway: something else
            // answers for it (a sidecar that shares these rules, a plugin) — it is in use, not gone.
            if ($pages === null && $hits > 0) { $verdict = 'elsewhere'; $why = "no controller named '{$control}' in this install's own code, yet it is counted: a sidecar or plugin serves it"; }
            elseif ($pages === null) { $verdict = 'gone'; $why = "no controller named '{$control}' exists, and it was never used"; }
            elseif ($method !== '*' && $covered === 0) { $verdict = 'gone'; $why = "'{$control}' has no page '{$method}' any more"; }
            elseif ($hits === 0 && $days > 0 && $days < self::NEW_DAYS) { $verdict = 'new'; $why = "the rule is {$days} day" . ($days === 1 ? '' : 's') . ' old'; }
            elseif ($hits === 0) { $verdict = 'never'; $why = $days > 0 ? "not once in {$days} days" : 'not once (the rule has no date)'; }
            elseif ($method === '*' && $covered > 1) { $verdict = 'unclear'; $why = "one count for {$covered} pages: which of them are opened cannot be told"; }
            elseif ($days >= self::RARE_AFTER_DAYS && $perWeek < self::RARE_PER_WEEK) { $verdict = 'rare'; $why = "{$hits} time" . ($hits === 1 ? '' : 's') . " in {$days} days"; }
            else { $verdict = 'used'; $why = ''; }

            $out[] = ['control' => $control, 'method' => $method, 'level' => (int) $r['level'], 'hits' => $hits, 'days' => $days, 'per_week' => $perWeek,
                      'pages' => $covered, 'linked' => $isLinked, 'verdict' => $verdict, 'why' => $why, 'description' => trim((string) ($r['description'] ?? ''))];
        }
        return $out;
    }

    private static function anyLinked(array $linked, string $control): bool {
        foreach ($linked as $k => $_) if (str_starts_with($k, $control . '/')) return true;
        return false;
    }

    /** Who a rule lets in, in words — and whether its count can include machines. */
    public static function audience(int $level): string {
        return $level >= 101 ? 'Anyone' : ($level >= 100 ? 'Members' : ($level >= 50 ? 'Admins' : 'Root'));
    }

    /**
     * This install's rules, judged: its controllers are the app's own and the runtime's, its menu
     * links are the `'url' => '/…'` and `href="/…"` its menu views write.
     */
    public static function report(): array {
        $dirs = [Paths::root() . '/controls', Paths::runtime() . '/controls'];
        $files = [];   // lower-case controller name => its class name
        foreach ($dirs as $d) foreach (glob($d . '/*.php') ?: [] as $f) { $n = basename($f, '.php'); $files[strtolower($n)] ??= $n; }
        $cache = [];
        $pagesOf = function (string $control) use ($files, &$cache): ?array {
            if (array_key_exists($control, $cache)) return $cache[$control];
            if (!isset($files[$control])) return $cache[$control] = null;
            $class = '\\app\\' . $files[$control];
            if (!class_exists($class)) return $cache[$control] = null;
            $pages = [];
            foreach ((new \ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $m) {
                if ($m->isStatic() || $m->isConstructor() || str_starts_with($m->getName(), '_') || $m->getDeclaringClass()->getName() !== ltrim($class, '\\')) continue;
                $pages[] = strtolower($m->getName());
            }
            return $cache[$control] = $pages;
        };
        $linked = [];
        $menus = array_merge(glob(Paths::root() . '/views/platform/chrome_*.php') ?: [], glob(Paths::root() . '/views/app/nav.php') ?: [],
                             glob(Paths::runtime() . '/views/layouts/*.php') ?: [], glob(Paths::runtime() . '/views/components/*.php') ?: []);
        foreach ($menus as $f) {
            if (preg_match_all('#(?:\'url\'\s*=>\s*\'|href=")/([a-z0-9_-]+)(?:/([a-z0-9_-]+))?#i', (string) file_get_contents($f), $m, PREG_SET_ORDER)) {
                foreach ($m as $hit) $linked[strtolower($hit[1]) . '/' . strtolower($hit[2] ?? 'index')] = true;
            }
        }
        $rules = Bean::getAll('SELECT control, method, level, validcount, created_at, description FROM authcontrol ORDER BY control, method');
        $rows = self::judge($rules, $pagesOf, $linked, time());
        $by = array_fill_keys(array_keys(self::VERDICTS), []);
        foreach ($rows as $r) $by[$r['verdict']][] = $r;
        usort($by['rare'], fn($a, $b) => $a['per_week'] <=> $b['per_week']);
        usort($by['used'], fn($a, $b) => $b['hits'] <=> $a['hits']);
        usort($by['unclear'], fn($a, $b) => $b['pages'] <=> $a['pages']);
        return ['by' => $by, 'rules' => count($rows), 'hits' => array_sum(array_column($rows, 'hits'))];
    }
}
