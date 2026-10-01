<?php
/**
 * InstanceRepo — where a project's ORIGIN lives on the control plane: the bare repository its
 * app in its container was seeded from and fetches its code as (GitHttp serves it), made by
 * TenantApp::create and archived and removed by ProvisionService::deleteTenant.
 *
 *   <ROOT>/_origins/<slug>.git     the bare origin
 */

namespace app;

class InstanceRepo {

    public const ORIGINS = '_origins';

    private static ?string $root = null;

    /** The directory that holds instance trees and `_origins/` — overridable for tests. */
    public static function useRoot(?string $root): void { self::$root = $root !== null ? rtrim($root, '/') : null; }

    public static function root(): string { return self::$root ?? \Model_Instance::ROOT; }

    public static function originsDir(): string { return self::root() . '/' . self::ORIGINS; }

    public static function originPath(string $slug): string { return self::originsDir() . '/' . self::assertSlug($slug) . '.git'; }

    public static function hasOrigin(string $slug): bool { return is_file(self::originPath($slug) . '/HEAD'); }

    // ---- internals ------------------------------------------------------------------------

    private static function assertSlug(string $slug): string {
        if (!preg_match('/^[a-z][a-z0-9]*(-[a-z0-9]+)*$/', $slug) || strlen($slug) > 64) throw new \InvalidArgumentException("InstanceRepo: '{$slug}' is not an instance slug.");
        return $slug;
    }
}
