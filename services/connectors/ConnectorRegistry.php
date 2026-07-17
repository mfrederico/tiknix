<?php
/**
 * ConnectorRegistry — auto-discovers *Connector.php in this directory and indexes
 * each by its key(). Drop a new connector class here and it lights up with no
 * wiring. (AbstractConnector and the interface are skipped — not instantiable /
 * not concrete.)
 */

namespace app\services\connectors;

class ConnectorRegistry {

    /** @var array<string,ConnectorInterface>|null */
    private static ?array $map = null;

    private static function load(): array {
        if (self::$map !== null) return self::$map;
        $map = [];
        foreach (glob(__DIR__ . '/*Connector.php') ?: [] as $file) {
            $cls = __NAMESPACE__ . '\\' . basename($file, '.php');
            if (!class_exists($cls)) continue;
            $ref = new \ReflectionClass($cls);
            if ($ref->isAbstract() || !$ref->implementsInterface(ConnectorInterface::class)) continue;
            $inst = new $cls();
            $map[$inst->key()] = $inst;
        }
        return self::$map = $map;
    }

    public static function has(string $key): bool {
        return isset(self::load()[$key]);
    }

    public static function get(string $key): ?ConnectorInterface {
        return self::load()[$key] ?? null;
    }

    /** @return ConnectorInterface[] */
    public static function all(): array {
        return array_values(self::load());
    }
}
