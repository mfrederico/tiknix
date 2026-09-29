<?php
/**
 * Overrides — app files that REPLACE runtime files, and what that costs on an update.
 *
 * The owner's rule (2026-09-28): once an app overrides a runtime file, the runtime can no
 * longer upgrade that file for it. The runtime update still moves everything else; the
 * overridden file stays the app's, and the app's owner reconciles it by hand. This class
 * makes that visible instead of silent:
 *
 *   overrides.lock (app root)  {"version":1,"overrides":{"controls/Help.php":{"base":"sha256:…",
 *                               "release":"v1.6.2","recorded_at":"…"}}}
 *
 *   base     hash of the RUNTIME file the override was taken from
 *   status   current     the runtime file is still what the override was based on
 *            STALE       the runtime changed it since — the owner must carry that change
 *                        into their copy (or delete the override to take the runtime's)
 *            unrecorded  an app file shadows a runtime file with no row: nobody knows what
 *                        it was based on, so every runtime change to it is invisible
 *            orphaned    the runtime no longer has that file: the app's copy is just its own
 *
 * Overrides are detected by path: the same relative path under the app root and under the
 * runtime tree, in the layers the autoloader and view resolver honour (controls, lib, views,
 * mcptools, services, models).
 */

namespace app;

class Overrides {

    public const FILE = 'overrides.lock';
    public const LAYERS = ['controls', 'lib', 'views', 'mcptools', 'services', 'models'];

    /** @return array<string,array{status:string,base:?string,release:?string,now:?string}> path → state */
    public static function report(?string $root = null, ?string $runtime = null): array {
        $root = rtrim($root ?? Paths::root(), '/');
        $runtime = rtrim($runtime ?? Paths::runtime(), '/');
        if ($root === $runtime) return [];
        $lock = self::read($root);
        $out = [];
        foreach (self::shadowing($root, $runtime) as $rel) {
            $row = $lock[$rel] ?? null;
            $now = self::hash("{$runtime}/{$rel}");
            if ($row === null) $status = 'unrecorded';
            else $status = ($row['base'] ?? '') === $now ? 'current' : 'STALE';
            $out[$rel] = ['status' => $status, 'base' => $row['base'] ?? null, 'release' => $row['release'] ?? null, 'now' => $now];
        }
        foreach ($lock as $rel => $row) {
            if (!isset($out[$rel]) && is_file("{$root}/{$rel}") && !is_file("{$runtime}/{$rel}")) {
                $out[$rel] = ['status' => 'orphaned', 'base' => $row['base'] ?? null, 'release' => $row['release'] ?? null, 'now' => null];
            }
        }
        ksort($out);
        return $out;
    }

    /**
     * Start overriding a runtime file: copy it into the app at the same path and record what
     * it was based on. Refuses a path the runtime does not have, and an app file that exists.
     */
    public static function take(string $rel, string $release = '', ?string $root = null, ?string $runtime = null): string {
        $root = rtrim($root ?? Paths::root(), '/');
        $runtime = rtrim($runtime ?? Paths::runtime(), '/');
        $rel = self::assertRel($rel);
        if (!is_file("{$runtime}/{$rel}")) throw new \RuntimeException("Overrides: the runtime has no {$rel} to override.");
        if (is_file("{$root}/{$rel}")) throw new \RuntimeException("Overrides: {$rel} already exists in the app — record it with --override-record={$rel} if it is an override.");
        if (!is_dir(dirname("{$root}/{$rel}")) && !mkdir(dirname("{$root}/{$rel}"), 0775, true)) throw new \RuntimeException('Overrides: could not create ' . dirname("{$root}/{$rel}"));
        if (!copy("{$runtime}/{$rel}", "{$root}/{$rel}")) throw new \RuntimeException("Overrides: could not copy {$rel}.");
        self::record($rel, $release, $root, $runtime);
        return "{$root}/{$rel}";
    }

    /** Record (or re-baseline, after the owner reconciled a STALE one) an override against the runtime file as it is now. */
    public static function record(string $rel, string $release = '', ?string $root = null, ?string $runtime = null): void {
        $root = rtrim($root ?? Paths::root(), '/');
        $runtime = rtrim($runtime ?? Paths::runtime(), '/');
        $rel = self::assertRel($rel);
        if (!is_file("{$runtime}/{$rel}")) throw new \RuntimeException("Overrides: the runtime has no {$rel}.");
        $lock = self::read($root);
        $lock[$rel] = ['base' => self::hash("{$runtime}/{$rel}"), 'release' => $release, 'recorded_at' => date('c')];
        ksort($lock);
        $json = json_encode(['version' => 1, 'overrides' => $lock], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        $tmp = "{$root}/" . self::FILE . '.' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $json) !== strlen($json) || !rename($tmp, "{$root}/" . self::FILE)) {
            @unlink($tmp);
            throw new \RuntimeException("Overrides: could not write {$root}/" . self::FILE);
        }
    }

    /** @return string[] relative paths present in both the app and the runtime, within the override layers */
    public static function shadowing(string $root, string $runtime): array {
        $out = [];
        foreach (self::LAYERS as $layer) {
            $dir = "{$runtime}/{$layer}";
            if (!is_dir($dir)) continue;
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if (!$f->isFile()) continue;
                $rel = substr($f->getPathname(), strlen($runtime) + 1);
                if (is_file("{$root}/{$rel}")) $out[] = $rel;
            }
        }
        sort($out);
        return $out;
    }

    private static function read(string $root): array {
        $f = "{$root}/" . self::FILE;
        if (!is_file($f)) return [];
        $d = json_decode((string) file_get_contents($f), true);
        if (!is_array($d) || !is_array($d['overrides'] ?? null)) throw new \RuntimeException("{$f} is not a valid overrides lock ({\"version\",\"overrides\"}).");
        return $d['overrides'];
    }

    private static function hash(string $file): string {
        return 'sha256:' . hash_file('sha256', $file);
    }

    private static function assertRel(string $rel): string {
        $rel = ltrim(trim($rel), '/');
        $first = explode('/', $rel)[0];
        if ($rel === '' || str_contains($rel, '..') || !in_array($first, self::LAYERS, true)) {
            throw new \InvalidArgumentException("Overrides: '{$rel}' is not a runtime path (" . implode(', ', self::LAYERS) . ').');
        }
        return $rel;
    }
}
