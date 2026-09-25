<?php
/**
 * ConceptLock — `concepts.lock` at the install root: which concepts (plugins) this install
 * has, at what version, from where, whether each is switched on, and a hash of its files.
 *
 *   {
 *     "version": 1,
 *     "concepts": {
 *       "pdf": { "version": "1.0.0", "enabled": true, "source": "control-plane catalog",
 *                "installed_at": "2026-09-25T…", "enabled_at": "…", "hash": "sha256:…" }
 *     }
 *   }
 *
 * It is THE record of plugin state, and it is a file in the project's repository on
 * purpose:
 *
 *   - it travels with the code — a clone, a task worktree, a test run and a deploy all see
 *     the same plugins without a database (the enabled flag used to be a settings row, so
 *     the test suites, which run on a scratch database, saw no plugins at all and the
 *     CLAUDE.md drift test failed on every install that had one);
 *   - it says when a plugin was edited in place: `hash` is what was installed, and a
 *     mismatch means an update will be a merge task, not a replace;
 *   - it is what an update compares against the catalog.
 *
 * Writes are atomic (temp file + rename). A lock that does not parse is a fault, never an
 * empty set. An install whose concepts/ has directories but no lock has not been migrated:
 * that throws with the command to run, rather than quietly treating everything as off.
 */
namespace app;

class ConceptLock {

    public const FILE = 'concepts.lock';
    public const VERSION = 1;

    /** @return array{version:int,concepts:array<string,array<string,mixed>>} */
    public static function read(string $root): array {
        $file = self::path($root);
        if (!is_file($file)) return ['version' => self::VERSION, 'concepts' => []];
        $data = json_decode((string) file_get_contents($file), true);
        if (!is_array($data) || !isset($data['concepts']) || !is_array($data['concepts'])) {
            throw new ConceptException("{$file} is not a valid lock file (expected {\"version\", \"concepts\"}). Fix it or rebuild it with: php scripts/clitool.php --concept-lock");
        }
        ksort($data['concepts']);
        return ['version' => (int) ($data['version'] ?? self::VERSION), 'concepts' => $data['concepts']];
    }

    public static function exists(string $root): bool {
        return is_file(self::path($root));
    }

    /**
     * Names switched on. No concepts/ directory → nothing. A concepts/ directory with
     * entries but no lock → not migrated yet: an error naming the fix.
     *
     * @return string[]
     */
    public static function enabledNames(string $root): array {
        $root = rtrim($root, '/');
        if (!self::exists($root)) {
            if (self::installedDirs($root)) {
                throw new ConceptException("{$root}/" . self::FILE . " is missing but " . Concepts::DIR . "/ has concepts. Write it once with: php scripts/clitool.php --concept-lock");
            }
            return [];
        }
        $out = [];
        foreach (self::read($root)['concepts'] as $name => $row) {
            if (!empty($row['enabled'])) $out[] = (string) $name;
        }
        return $out;
    }

    /** The lock's row for one concept, or null when it is not recorded. */
    public static function entry(string $root, string $name): ?array {
        return self::read($root)['concepts'][$name] ?? null;
    }

    /** Record a fresh install (switched off) — what ConceptCatalog::install() calls. */
    public static function record(string $root, string $name, string $version, string $source): void {
        $root = rtrim($root, '/');
        $data = self::read($root);
        $data['concepts'][$name] = [
            'version'      => $version,
            'enabled'      => false,
            'source'       => $source,
            'installed_at' => date('c'),
            'hash'         => self::hash("{$root}/" . Concepts::DIR . "/{$name}"),
        ];
        self::write($root, $data);
    }

    /** Switch a recorded concept on or off. An unrecorded one is recorded from its directory first. */
    public static function setEnabled(string $root, string $name, bool $on): void {
        $root = rtrim($root, '/');
        $data = self::read($root);
        if (!isset($data['concepts'][$name])) {
            $data['concepts'][$name] = self::rowFromDir($root, $name);
        }
        $data['concepts'][$name]['enabled'] = $on;
        $data['concepts'][$name][$on ? 'enabled_at' : 'disabled_at'] = date('c');
        self::write($root, $data);
    }

    /** Whether the concept's files differ from what the lock recorded — edited in place. */
    public static function modified(string $root, string $name): bool {
        $root = rtrim($root, '/');
        $row = self::entry($root, $name);
        if ($row === null || empty($row['hash'])) return false;
        return $row['hash'] !== self::hash("{$root}/" . Concepts::DIR . "/{$name}");
    }

    /**
     * Rebuild the lock from what is on disk: every concepts/<name> gets a row (version from
     * its manifest, source from .installed.json or "authored here"), rows whose directory is
     * gone are dropped, hashes are recomputed for rows that have none. The enabled state is
     * kept; for a concept the lock does not know yet it is taken from $enabledElsewhere —
     * the settings-table flags this file replaces — which is the one-time migration.
     *
     * Hashes of rows that already have one are NOT recomputed: that would erase the
     * evidence of an in-place edit. Pass $rehash to accept the current files as the baseline.
     *
     * @param string[] $enabledElsewhere
     * @return array{added:string[],removed:string[],kept:string[],file:string}
     */
    public static function sync(string $root, array $enabledElsewhere = [], bool $rehash = false): array {
        $root = rtrim($root, '/');
        $data = self::exists($root) ? self::read($root) : ['version' => self::VERSION, 'concepts' => []];
        $dirs = self::installedDirs($root);
        $added = $removed = $kept = [];
        foreach ($dirs as $name) {
            if (!isset($data['concepts'][$name])) {
                $row = self::rowFromDir($root, $name);
                $row['enabled'] = in_array($name, $enabledElsewhere, true);
                if ($row['enabled']) $row['enabled_at'] = date('c');
                $data['concepts'][$name] = $row;
                $added[] = $name;
            } else {
                $m = ConceptManifest::load("{$root}/" . Concepts::DIR . "/{$name}", $name);
                $data['concepts'][$name]['version'] = $m->version;
                if ($rehash || empty($data['concepts'][$name]['hash'])) {
                    $data['concepts'][$name]['hash'] = self::hash("{$root}/" . Concepts::DIR . "/{$name}");
                }
                $kept[] = $name;
            }
        }
        foreach (array_keys($data['concepts']) as $name) {
            if (!in_array($name, $dirs, true)) { unset($data['concepts'][$name]); $removed[] = $name; }
        }
        self::write($root, $data);
        return ['added' => $added, 'removed' => $removed, 'kept' => $kept, 'file' => self::path($root)];
    }

    /**
     * sha256 over the concept's files (sorted relative paths + contents), excluding the
     * installer's provenance note. Same files → same hash on any machine.
     */
    public static function hash(string $dir): string {
        if (!is_dir($dir)) throw new ConceptException("ConceptLock: {$dir} is not a directory.");
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            /** @var \SplFileInfo $f */
            if (!$f->isFile()) continue;
            $rel = substr($f->getPathname(), strlen($dir) + 1);
            if ($rel === ConceptCatalog::PROVENANCE_FILE) continue;
            $files[$rel] = (string) file_get_contents($f->getPathname());
        }
        ksort($files);
        $h = hash_init('sha256');
        foreach ($files as $rel => $bytes) { hash_update($h, $rel . "\0" . $bytes . "\0"); }
        return 'sha256:' . hash_final($h);
    }

    public static function path(string $root): string {
        return rtrim($root, '/') . '/' . self::FILE;
    }

    /* ---- internals ---- */

    /** @return string[] concept directory names on disk, sorted */
    private static function installedDirs(string $root): array {
        $dir = rtrim($root, '/') . '/' . Concepts::DIR;
        if (!is_dir($dir)) return [];
        $names = [];
        foreach (glob("{$dir}/*", GLOB_ONLYDIR) ?: [] as $d) {
            $n = basename($d);
            if (preg_match('/^[a-z][a-z0-9]*$/D', $n)) $names[] = $n;
        }
        sort($names);
        return $names;
    }

    private static function rowFromDir(string $root, string $name): array {
        $dir = "{$root}/" . Concepts::DIR . "/{$name}";
        $m = ConceptManifest::load($dir, $name);
        $prov = is_file("{$dir}/" . ConceptCatalog::PROVENANCE_FILE)
            ? json_decode((string) file_get_contents("{$dir}/" . ConceptCatalog::PROVENANCE_FILE), true) : null;
        return [
            'version'      => $m->version,
            'enabled'      => false,
            'source'       => (string) ($prov['source'] ?? 'authored here'),
            'installed_at' => (string) ($prov['installed_at'] ?? date('c')),
            'hash'         => self::hash($dir),
        ];
    }

    private static function write(string $root, array $data): void {
        ksort($data['concepts']);
        $file = self::path($root);
        $json = json_encode(['version' => self::VERSION, 'concepts' => $data['concepts']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, $json) !== strlen($json) || !rename($tmp, $file)) {
            @unlink($tmp);
            throw new ConceptException("ConceptLock: could not write {$file}.");
        }
    }
}
