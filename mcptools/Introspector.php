<?php
/**
 * Introspector — deterministic, lean codebase introspection.
 *
 * Scans a tiknix codebase (controllers, models, lib, conf) plus its SQLite
 * schema/permissions and answers structural questions WITHOUT loading file
 * bodies. Everything it returns is a pointer (path:line + one-liner) so callers
 * drill down with Read only where needed. No LLM, no network — safe in the jail.
 *
 * Used by the codebase_map / describe / whatprovides MCP tools.
 */

namespace app\mcptools;

class Introspector {

    private string $root;
    private ?\PDO $db = null;

    public function __construct(?string $root = null) {
        // mcptools/ always lives at <root>/mcptools, so dirname(__DIR__) is the app root.
        $this->root = rtrim($root ?: dirname(__DIR__), '/');
        $this->openDb();
    }

    /** Open the instance's sqlite db (read-only) if config points at one. */
    private function openDb(): void {
        $ini = @parse_ini_file("{$this->root}/conf/config.ini", true) ?: [];
        $type = strtolower($ini['database']['type'] ?? '');
        $path = $ini['database']['path'] ?? '';
        if ($type !== 'sqlite' || $path === '') return;
        $abs = $path[0] === '/' ? $path : "{$this->root}/{$path}";
        if (!is_file($abs)) return;
        try { $this->db = new \PDO('sqlite:' . $abs); $this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_SILENT); }
        catch (\Throwable $e) { $this->db = null; }
    }

    // === public API ==========================================================

    /** Lean table-of-contents: names + counts, no detail. */
    public function map(): array {
        $controllers = array_map(fn($c) => ['name' => $c['name'], 'routes' => count($c['methods'])], $this->controllers());
        $models = array_map(fn($m) => ['name' => $m['name'], 'table' => $m['table']], $this->models());
        $routeCount = array_sum(array_column($controllers, 'routes'));
        return [
            'controllers' => $controllers,
            'models'      => $models,
            'libs'        => array_column($this->libs(), 'name'),
            'config'      => $this->confSections(),
            'routeCount'  => $routeCount,
        ];
    }

    /** Detail for matching primitives (a name may be BOTH a controller and a model). */
    public function describe(string $name): array {
        $q = strtolower(preg_replace('/\.php$/', '', trim($name)));
        $out = [];

        foreach ($this->controllers() as $c) {
            if (strtolower($c['name']) === $q) {
                $out[] = ['kind' => 'controller', 'name' => $c['name'], 'path' => $c['path'], 'line' => $c['line'],
                    'routes' => array_map(fn($m) => [
                        'route' => '/' . strtolower($c['name']) . '/' . $m['name'],
                        'method' => $m['name'], 'line' => $m['line'],
                        'level' => $this->routeLevel(strtolower($c['name']), $m['name']),
                    ], $c['methods'])];
            }
        }
        foreach ($this->models() as $m) {
            if ($m['name'] === $q || strtolower($m['class']) === 'model_' . $q) {
                $out[] = ['kind' => 'model', 'name' => $m['name'], 'table' => $m['table'], 'path' => $m['path'],
                    'columns' => $this->columns($m['table']), 'relations' => $this->relations($m['table'])];
            }
        }
        foreach ($this->libs() as $l) {
            if (strtolower($l['name']) === $q) {
                $out[] = ['kind' => 'lib', 'name' => $l['name'], 'path' => $l['path'], 'methods' => $l['methods']];
            }
        }
        return $out;
    }

    /** Ranked pointers across all primitives for a concept. Capped. */
    public function whatprovides(string $concept, int $limit = 12): array {
        $concept = strtolower(trim($concept));
        if ($concept === '') return [];
        $words = array_values(array_filter(preg_split('/\s+/', $concept)));
        $hits = [];

        $score = function (string $name, string $text) use ($concept, $words) {
            $n = strtolower($name); $t = strtolower($text); $s = 0;
            if (strpos($n, $concept) !== false) $s += 5;
            $s += substr_count($t, $concept) * 2;
            foreach ($words as $w) { if (strpos($n, $w) !== false) $s += 2; if (strpos($t, $w) !== false) $s += 1; }
            return $s;
        };
        $add = function ($kind, $name, $path, $line, $why, $s) use (&$hits) {
            if ($s > 0) $hits[] = ['kind' => $kind, 'name' => $name, 'path' => $path, 'line' => $line, 'why' => $why, '_s' => $s];
        };

        foreach ($this->controllers() as $c) {
            $methods = implode(' ', array_column($c['methods'], 'name'));
            $add('controller', $c['name'], $c['path'], $c['line'], 'controller', $score($c['name'], $c['name'] . ' ' . $methods . ' ' . $c['doc']));
            foreach ($c['methods'] as $m) {
                $r = '/' . strtolower($c['name']) . '/' . $m['name'];
                $add('route', $r, $c['path'], $m['line'], 'route', $score($m['name'] . ' ' . $r, $m['name']));
            }
        }
        foreach ($this->models() as $m) {
            $cols = implode(' ', array_column($this->columns($m['table']), 'name'));
            $add('model', $m['name'], $m['path'], 1, 'model/table ' . $m['table'], $score($m['name'] . ' ' . $m['table'], $m['name'] . ' ' . $m['table'] . ' ' . $cols));
        }
        foreach ($this->libs() as $l) {
            $add('lib', $l['name'], $l['path'], 1, 'lib class', $score($l['name'], $l['name'] . ' ' . implode(' ', $l['methods'])));
        }
        foreach ($this->confSections() as $sec) {
            $add('config', '[' . $sec . ']', 'conf/config.ini', 1, 'config section', $score($sec, $sec));
        }

        usort($hits, fn($a, $b) => $b['_s'] <=> $a['_s']);
        return array_map(fn($h) => array_diff_key($h, ['_s' => 1]), array_slice($hits, 0, $limit));
    }

    /**
     * Reuse digest — a compact, prose inventory of everything that already
     * exists (controllers, models+columns, lib services, permissions, config,
     * seeders), meant to be INJECTED into the planner prompt so decomposition
     * reuses existing primitives instead of reinventing them. Token-bounded:
     * long lists are capped with a "+N more" marker (the planner can drill with
     * describe()). Degrades gracefully when no DB is present (structural parts
     * still render; DB-derived parts note they're not live yet).
     */
    public function digest(int $maxColsPerModel = 14, int $maxMethodsPerLib = 10, int $maxRoutesPerCtrl = 12): string {
        $names = [1 => 'ROOT', 50 => 'ADMIN', 100 => 'MEMBER', 101 => 'PUBLIC'];
        $lvl = fn($n) => $n === null ? '?' : ($names[$n] ?? (string)$n);
        $out = [];

        // --- Controllers -----------------------------------------------------
        $ctrls = $this->controllers();
        $out[] = '### Controllers (' . count($ctrls) . ') — reuse an existing route/controller before adding one';
        foreach ($ctrls as $c) {
            $slug = strtolower($c['name']);
            $methods = array_column($c['methods'], 'name');
            $levels = [];
            foreach ($c['methods'] as $m) { $levels[$lvl($this->routeLevel($slug, $m['name']))] = true; }
            $lv = $levels ? ' [' . implode(',', array_keys($levels)) . ']' : '';
            $shown = array_slice($methods, 0, $maxRoutesPerCtrl);
            $more = count($methods) > $maxRoutesPerCtrl ? ' +' . (count($methods) - $maxRoutesPerCtrl) : '';
            $out[] = "- **{$c['name']}**{$lv} — " . implode(', ', $shown) . $more;
        }

        // --- Models / tables -------------------------------------------------
        $models = $this->models();
        $out[] = '';
        $out[] = '### Models / tables (' . count($models) . ') — reuse a bean before creating a near-duplicate';
        foreach ($models as $m) {
            $cols = array_column($this->columns($m['table']), 'name');
            $colShown = array_slice($cols, 0, $maxColsPerModel);
            $colMore = count($cols) > $maxColsPerModel ? ' +' . (count($cols) - $maxColsPerModel) : '';
            $rels = array_map(fn($r) => '→' . $r['belongsTo'], $this->relations($m['table']));
            $relStr = $rels ? '  {rel: ' . implode(' ', $rels) . '}' : '';
            $colStr = $cols ? implode(', ', $colShown) . $colMore : '(no live table yet)';
            $out[] = "- **{$m['name']}** — {$colStr}{$relStr}";
        }

        // --- Lib services ----------------------------------------------------
        $libs = $this->libs();
        $out[] = '';
        $out[] = '### Lib services (' . count($libs) . ') — reuse a service before writing new logic';
        foreach ($libs as $l) {
            if (!$l['methods']) continue;
            $shown = array_slice($l['methods'], 0, $maxMethodsPerLib);
            $more = count($l['methods']) > $maxMethodsPerLib ? ' +' . (count($l['methods']) - $maxMethodsPerLib) : '';
            $out[] = "- **{$l['name']}**: " . implode(', ', $shown) . $more;
        }

        // --- Permissions (authcontrol) --------------------------------------
        $rows = $this->authcontrolRows();
        if ($rows) {
            $wild = array_values(array_filter($rows, fn($r) => $r['method'] === '*'));
            usort($wild, fn($a, $b) => $a['level'] <=> $b['level']);
            $out[] = '';
            $out[] = '### Permissions (authcontrol, ' . count($rows) . ' rows) — every NEW route needs an entry here (ship it as a seed)';
            foreach ($wild as $r) {
                $ln = $lvl($r['level']);
                $out[] = "- {$r['control']}::* = {$r['level']} ({$ln})";
            }
            $rest = count($rows) - count($wild);
            if ($rest > 0) $out[] = "- …plus {$rest} per-method entries — use describe(\"<controller>\") for specifics";
        }

        // --- Config ----------------------------------------------------------
        $conf = $this->confSections();
        if ($conf) {
            $out[] = '';
            $out[] = '### Config sections (conf/config.ini): ' . implode(' ', array_map(fn($s) => "[$s]", $conf));
        }

        // --- Seeding ---------------------------------------------------------
        $base = $this->seedScripts('services/Schema/Seeds');
        $plan = $this->seedScripts('database/seeds');
        $out[] = '';
        $out[] = '### Seeding — how DB / permission changes ship (never write the live DB directly)';
        $out[] = '- Base schema seeds (run at instance creation): ' . ($base ? implode(', ', $base) : '(none)');
        $out[] = '- Plan seeds — idempotent PHP in `database/seeds/*.php`, applied ONCE post-merge via the `\\app\\Bean` wrapper (findOne/dispense/store): ' . ($plan ? implode(', ', $plan) : '(none yet)');
        $out[] = '- A new route\'s permission == a seed here that upserts an `authcontrol` row (control, method, level). RedBean auto-creates a model\'s table on first store — no CREATE TABLE.';

        return implode("\n", $out);
    }

    // === public accessors for the Architecture Explorer ======================

    /** All authcontrol rows (control, method, level) — the Explorer's top ribbon. */
    public function authcontrol(): array {
        return $this->authcontrolRows();
    }

    /** App table names (excludes SQLite internals), for the `select *` drill picker. */
    public function tables(): array {
        return array_values(array_filter($this->tableNames(), fn($t) => strncmp($t, 'sqlite_', 7) !== 0));
    }

    /** Column metadata for a table (name+type), read-only. */
    public function tableColumns(string $table): array {
        return $this->columns($table);
    }

    /**
     * Read up to $limit rows from $table (read-only, LIMIT-capped, identifier-guarded).
     * Returns ['columns'=>[...], 'rows'=>[[...]], 'total'=>int]. The table MUST be a
     * real table name (validated) — never interpolate untrusted input here.
     */
    public function rows(string $table, int $limit = 50, int $offset = 0): array {
        $empty = ['columns' => [], 'rows' => [], 'total' => 0];
        if (!$this->db) return $empty;
        if (!preg_match('/^[a-z0-9_]+$/i', $table)) return $empty;
        if (!in_array($table, $this->tableNames(), true)) return $empty;   // allowlist to real tables
        $limit  = max(1, min(500, $limit));
        $offset = max(0, $offset);
        $cols = array_column($this->columns($table), 'name');
        $out  = $empty;
        $out['columns'] = $cols;
        try {
            $total = $this->db->query("SELECT COUNT(*) FROM " . $table);
            $out['total'] = $total ? (int) $total->fetchColumn() : 0;
            $stmt = $this->db->query("SELECT * FROM " . $table . " LIMIT " . $limit . " OFFSET " . $offset);
            foreach ($stmt ?: [] as $r) {
                $row = [];
                foreach ($cols as $c) $row[$c] = $r[$c] ?? null;
                $out['rows'][] = $row;
            }
        } catch (\Throwable $e) {}
        return $out;
    }

    /**
     * Controller inventory for CallGraph: name(lower) => {name, path, methods:[m=>line],
     * hasFallback}. Reuses the scanner controllers() (public methods + line numbers).
     */
    public function controllerList(): array {
        $out = [];
        foreach ($this->controllers() as $c) {
            $methods = [];
            foreach ($c['methods'] as $m) $methods[$m['name']] = $m['line'];
            $out[strtolower($c['name'])] = [
                'name'        => $c['name'],
                'path'        => $c['path'],
                'methods'     => $methods,
                'hasFallback' => isset($methods['_fallback']),
            ];
        }
        return $out;
    }

    /** Lib inventory for CallGraph: ClassName => {path, methods:[names]}. */
    public function libList(): array {
        $out = [];
        foreach ($this->libs() as $l) {
            $out[$l['name']] = ['path' => $l['path'], 'methods' => $l['methods']];
        }
        return $out;
    }

    /** Root path this introspector is scanning (CallGraph reads bodies under it). */
    public function rootPath(): string {
        return $this->root;
    }

    /** Bean→bean FK edges (belongsTo via *_id), for the data-model graph. */
    public function relationEdges(): array {
        $out = [];
        foreach ($this->tables() as $t) {
            foreach ($this->relations($t) as $r) {
                $out[] = ['from' => $t, 'to' => $r['belongsTo'], 'via' => $r['via']];
            }
        }
        return $out;
    }

    /**
     * Literal custom routes from routes/*.php — but ONLY from files bootstrap.php
     * actually require()s (per CALLGRAPH-DESIGN §3.5: unloaded routes files, e.g.
     * routes/api.php here, are DEAD and their literals are not live routes). Each:
     * ['pattern','verbs','file','line','live'=>bool].
     */
    public function routeLiterals(): array {
        $loaded = $this->loadedRoutesFiles();
        $out = [];
        foreach (glob("{$this->root}/routes/*.php") ?: [] as $file) {
            $base = basename($file);
            $src  = @file_get_contents($file);
            if ($src === false) continue;
            $live = in_array($base, $loaded, true);
            foreach (explode("\n", $src) as $i => $ln) {
                if (preg_match("/Flight::route\\(\\s*'(?:([A-Z|]+)\\s+)?(\\/[^']*)'/", $ln, $m)) {
                    $out[] = [
                        'pattern' => $m[2],
                        'verbs'   => $m[1] ?: 'ANY',
                        'file'    => "routes/{$base}",
                        'line'    => $i + 1,
                        'live'    => $live,
                    ];
                }
            }
        }
        return $out;
    }

    /** routes/*.php basenames that bootstrap.php require()s (defaultRoute is implicit). */
    private function loadedRoutesFiles(): array {
        $boot = @file_get_contents("{$this->root}/bootstrap.php");
        if ($boot === false) return [];
        $loaded = [];
        if (preg_match_all("#require(?:_once)?\\s+__DIR__\\s*\\.\\s*'/routes/([a-z0-9_]+\\.php)'#i", $boot, $m)) {
            $loaded = $m[1];
        }
        return $loaded;
    }

    // === scanners ============================================================

    private array $_ctrl;
    private function controllers(): array {
        if (isset($this->_ctrl)) return $this->_ctrl;
        $out = [];
        foreach (glob("{$this->root}/controls/*.php") ?: [] as $file) {
            $base = basename($file, '.php');
            if ($base === 'BaseControls') continue;
            $src = @file_get_contents($file); if ($src === false) continue;
            $lines = explode("\n", $src);
            $classLine = 1; $doc = '';
            foreach ($lines as $i => $ln) { if (preg_match('/^\s*(?:abstract\s+|final\s+)?class\s+/', $ln)) { $classLine = $i + 1; break; } }
            if (preg_match('#/\*\*(.*?)\*/#s', $src, $dm)) $doc = preg_replace('/\s+/', ' ', $dm[1]);
            $methods = [];
            foreach ($lines as $i => $ln) {
                if (preg_match('/^\s*public\s+function\s+(\w+)\s*\(/', $ln, $mm)) {
                    if (in_array($mm[1], ['__construct', '__destruct', '__call', '__get', '__set'], true)) continue;
                    $methods[] = ['name' => $mm[1], 'line' => $i + 1];
                }
            }
            $out[] = ['name' => $base, 'path' => "controls/{$base}.php", 'line' => $classLine, 'methods' => $methods, 'doc' => $doc];
        }
        return $this->_ctrl = $out;
    }

    private array $_models;
    private function models(): array {
        if (isset($this->_models)) return $this->_models;
        $out = [];
        foreach (glob("{$this->root}/models/Model_*.php") ?: [] as $file) {
            $base = basename($file, '.php');                 // Model_Member
            $bean = strtolower(substr($base, strlen('Model_')));
            $out[] = ['name' => $bean, 'class' => $base, 'table' => $bean, 'path' => "models/{$base}.php"];
        }
        return $this->_models = $out;
    }

    private array $_libs;
    private function libs(): array {
        if (isset($this->_libs)) return $this->_libs;
        $out = [];
        foreach (glob("{$this->root}/lib/*.php") ?: [] as $file) {
            $base = basename($file, '.php');
            $src = @file_get_contents($file); if ($src === false) continue;
            $methods = [];
            if (preg_match_all('/^\s*public\s+(?:static\s+)?function\s+(\w+)/m', $src, $mm)) {
                $methods = array_values(array_filter($mm[1], fn($x) => strpos($x, '__') !== 0));
            }
            $out[] = ['name' => $base, 'path' => "lib/{$base}.php", 'methods' => $methods];
        }
        return $this->_libs = $out;
    }

    private function confSections(): array {
        $ini = @parse_ini_file("{$this->root}/conf/config.ini", true) ?: [];
        return array_keys($ini);
    }

    // === db-derived ==========================================================

    private function columns(string $table): array {
        if (!$this->db || !preg_match('/^[a-z0-9_]+$/i', $table)) return [];
        $out = [];
        try {
            foreach ($this->db->query("PRAGMA table_info(" . $table . ")") ?: [] as $r) {
                $out[] = ['name' => $r['name'], 'type' => $r['type']];
            }
        } catch (\Throwable $e) {}
        return $out;
    }

    /** Infer relations from *_id columns, but only when the target table exists. */
    private function relations(string $table): array {
        $rel = []; $tables = $this->tableNames();
        foreach ($this->columns($table) as $c) {
            if (preg_match('/^(.*)_id$/', $c['name'], $m) && $m[1] !== '' && in_array($m[1], $tables, true)) {
                $rel[] = ['belongsTo' => $m[1], 'via' => $c['name']];
            }
        }
        return $rel;
    }

    private array $_tables;
    private function tableNames(): array {
        if (isset($this->_tables)) return $this->_tables;
        $this->_tables = [];
        if ($this->db) {
            try { foreach ($this->db->query("SELECT name FROM sqlite_master WHERE type='table'") ?: [] as $r) $this->_tables[] = $r['name']; }
            catch (\Throwable $e) {}
        }
        return $this->_tables;
    }

    private array $_levels;
    private function routeLevel(string $control, string $method): ?int {
        if (!$this->db) return null;
        if (!isset($this->_levels)) {
            $this->_levels = [];
            try {
                foreach ($this->db->query("SELECT control, method, level FROM authcontrol") ?: [] as $r) {
                    $this->_levels[strtolower($r['control']) . '::' . strtolower($r['method'])] = (int)$r['level'];
                }
            } catch (\Throwable $e) { $this->_levels = []; }
        }
        return $this->_levels["{$control}::{$method}"] ?? $this->_levels["{$control}::*"] ?? null;
    }

    /** All authcontrol rows (control, method, level), for the reuse digest. */
    private function authcontrolRows(): array {
        if (!$this->db) return [];
        $out = [];
        try {
            foreach ($this->db->query("SELECT control, method, level FROM authcontrol ORDER BY level, control") ?: [] as $r) {
                $out[] = ['control' => $r['control'], 'method' => $r['method'], 'level' => (int)$r['level']];
            }
        } catch (\Throwable $e) {}
        return $out;
    }

    /** Basenames of the seed scripts under a relative dir (e.g. database/seeds). */
    private function seedScripts(string $rel): array {
        $files = glob("{$this->root}/{$rel}/*.php") ?: [];
        sort($files);
        return array_map(fn($f) => basename($f), $files);
    }
}
