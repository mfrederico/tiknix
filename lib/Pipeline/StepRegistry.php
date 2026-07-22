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

    /**
     * [type => schema] — the "what can I build with" surface for agents/editor.
     * A step declares rich `fields` (type/options/required/help) as its single
     * source of truth; we DERIVE the legacy `config` string-map (the agent-facing
     * surface consumed by pipeline_components) from those fields, so there is no
     * drift and no duplication. The editor reads `fields`; agents read `config`.
     */
    public static function components(): array {
        $out = [];
        foreach (self::all() as $type => $step) {
            $schema = $step::schema();
            if (!isset($schema['config']) && !empty($schema['fields'])) {
                $cfg = [];
                foreach ($schema['fields'] as $f) {
                    $cfg[$f['name']] = $f['help'] ?? ($f['label'] ?? $f['name']);
                }
                $schema['config'] = $cfg;
            }
            $out[$type] = $schema;
        }
        return $out;
    }
}
