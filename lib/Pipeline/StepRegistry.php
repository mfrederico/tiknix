<?php
/**
 * Pipeline\StepRegistry — the ONE registry (myctobot had three, drifted). Auto-
 * discovers lib/Pipeline/Steps/*Step.php and indexes them by their type() token, so
 * adding a step type is dropping one file. Provides the components list agents +
 * the editor read to know what they can wire.
 */

namespace app\Pipeline;

use app\Pipeline\Steps\StepInterface;

class StepRegistry {

    private static ?array $byType = null;

    /** type => StepInterface instance. */
    public static function all(): array {
        if (self::$byType !== null) return self::$byType;
        self::$byType = [];
        foreach (glob(__DIR__ . '/Steps/*Step.php') ?: [] as $file) {
            $cls = 'app\\Pipeline\\Steps\\' . basename($file, '.php');
            if (!class_exists($cls)) continue;
            $rc = new \ReflectionClass($cls);
            if (!$rc->implementsInterface(StepInterface::class) || $rc->isInterface() || $rc->isAbstract()) continue;
            $inst = new $cls();
            self::$byType[$cls::type()] = $inst;
        }
        ksort(self::$byType);
        return self::$byType;
    }

    public static function get(string $type): ?StepInterface {
        return self::all()[$type] ?? null;
    }

    public static function types(): array {
        return array_keys(self::all());
    }

    /** [type => schema] — the "what can I build with" surface for agents/editor. */
    public static function components(): array {
        $out = [];
        foreach (self::all() as $type => $step) $out[$type] = $step::schema();
        return $out;
    }
}
