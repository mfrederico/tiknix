<?php
/**
 * Paths — where THIS app is, and where the runtime is (RUNTIME-SPLIT-MAP.md).
 *
 * Runtime code used to find the app as `dirname(__DIR__)` — true only while the file sat one
 * level under the app root. Runtime files now live under runtime/ and will live under
 * vendor/tiknix/runtime/ once the package is cut, so "the directory above mine" is no longer
 * the app. The app is Composer's ROOT package: the project whose vendor/autoload.php was
 * loaded. That answer is the same for the control plane, for an app, for a sidecar that loads
 * core's autoloader (its root package is core, as before), and for the package layout.
 *
 *   Paths::root()     the app: conf/, data/, secure/, concepts/, connectors/, log/, public/
 *   Paths::runtime()  the runtime's own tree: its lib/, controls/, views/, services/, mcptools/
 *
 * An app overrides a runtime file by having the same relative path in its own tree
 * (controls/, lib/, views/, mcptools/, services/, models/): the autoloader and the view
 * resolver look in the app first. See app\Overrides for what that costs on an update.
 */

namespace app;

class Paths {

    private static ?string $root = null;

    public static function root(): string {
        if (self::$root !== null) return self::$root;
        $p = '';
        if (class_exists(\Composer\InstalledVersions::class)) {
            $p = (string) (\Composer\InstalledVersions::getRootPackage()['install_path'] ?? '');
        }
        $real = $p !== '' ? realpath($p) : false;
        if ($real === false || !is_file($real . '/composer.json')) {
            throw new \RuntimeException("Paths: Composer's root package path ('{$p}') is not an app root with a composer.json — cannot tell where this app is.");
        }
        return self::$root = $real;
    }

    /** The runtime's tree: the directory holding its lib/ (runtime/ now, vendor/tiknix/runtime later). */
    public static function runtime(): string {
        return dirname(__DIR__);
    }

    /** Test seam: act as if the app lived at $root (null = Composer's answer again). */
    public static function useRoot(?string $root): void {
        self::$root = $root !== null ? rtrim($root, '/') : null;
    }

    /**
     * Candidate locations for a relative path, app first, runtime second — the override
     * order every resolver here uses (views, seeds, MCP tools).
     *
     * @return string[]
     */
    public static function layered(string $rel): array {
        $rel = ltrim($rel, '/');
        $out = [self::root() . '/' . $rel];
        $rt = self::runtime() . '/' . $rel;
        if ($rt !== $out[0]) $out[] = $rt;
        return $out;
    }

    /**
     * The route file routes/<name>.php — the app's when it has one, else the runtime's; null
     * when neither exists (a first URL segment with no route file of its own is ordinary:
     * the default route handles it).
     */
    public static function route(string $name): ?string {
        if (!preg_match('/^[a-z0-9_-]+$/i', $name)) return null;   // a URL segment, never a path
        foreach (self::layered("routes/{$name}.php") as $f) if (is_file($f)) return $f;
        return null;
    }
}
