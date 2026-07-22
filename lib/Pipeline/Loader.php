<?php
/**
 * Pipeline\Loader — pipeline definitions are versioned JSON files in the app repo
 * (`pipelines/<slug>.json`), the source of truth. Discovered by glob, exactly like
 * the connector registry. The file IS the export format. Run history lives in the
 * DB (see Executor); definitions never do.
 */

namespace app\Pipeline;

class Loader {

    private string $dir;

    /** $root = the app/instance root; pipelines live at <root>/pipelines/. */
    public function __construct(string $root) {
        $this->dir = rtrim($root, '/') . '/pipelines';
    }

    public function dir(): string { return $this->dir; }

    /** All valid definitions keyed by slug. */
    public function all(): array {
        $out = [];
        foreach (glob($this->dir . '/*.json') ?: [] as $file) {
            $def = $this->read($file);
            if ($def && ($def['slug'] ?? '') !== '') $out[$def['slug']] = $def;
        }
        return $out;
    }

    public function get(string $slug): ?array {
        $slug = self::safeSlug($slug);
        if ($slug === '') return null;
        $file = $this->dir . '/' . $slug . '.json';
        return is_file($file) ? $this->read($file) : null;
    }

    /** Write a definition to its file (create the dir if needed). Returns the path. */
    public function save(array $def): string {
        $slug = self::safeSlug((string) ($def['slug'] ?? ''));
        if ($slug === '') throw new \InvalidArgumentException('pipeline needs a valid slug');
        if (!is_dir($this->dir)) @mkdir($this->dir, 0775, true);
        $file = $this->dir . '/' . $slug . '.json';
        file_put_contents($file, json_encode($def, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        return $file;
    }

    public function delete(string $slug): bool {
        $slug = self::safeSlug($slug);
        $file = $this->dir . '/' . $slug . '.json';
        return is_file($file) ? @unlink($file) : false;
    }

    /**
     * Validate a definition against the step registry. Returns a list of error
     * strings ([] = valid). Used by set_pipeline's dry_run and the editor.
     */
    public static function validate(array $def): array {
        $errors = [];
        if (self::safeSlug((string) ($def['slug'] ?? '')) === '') $errors[] = 'missing or invalid slug';
        $steps = $def['steps'] ?? null;
        if (!is_array($steps) || !$steps) { $errors[] = 'no steps'; return $errors; }

        $names = [];
        foreach ($steps as $i => $s) {
            $n = (string) ($s['name'] ?? '');
            if ($n === '' || !preg_match('/^[a-z0-9_]+$/i', $n)) { $errors[] = "step #$i: invalid name"; continue; }
            if (isset($names[$n])) $errors[] = "duplicate step name '$n'";
            $names[$n] = true;
            $type = (string) ($s['type'] ?? '');
            if (!StepRegistry::get($type)) $errors[] = "step '$n': unknown type '$type'";
        }
        // Validate goto targets resolve to a real step name.
        foreach ($steps as $s) {
            foreach (['on_success', 'on_fail'] as $k) {
                $flow = (string) ($s[$k] ?? '');
                if (strncmp($flow, 'goto:', 5) === 0) {
                    $target = substr($flow, 5);
                    if (!isset($names[$target])) $errors[] = "step '{$s['name']}': $k goto:$target has no such step";
                }
            }
        }
        return $errors;
    }

    private function read(string $file): ?array {
        $raw = @file_get_contents($file);
        if ($raw === false) return null;
        $def = json_decode($raw, true);
        return is_array($def) ? $def : null;
    }

    public static function safeSlug(string $slug): string {
        $slug = strtolower(trim($slug));
        return preg_match('/^[a-z0-9]([a-z0-9-]{0,62}[a-z0-9])?$/', $slug) ? $slug : '';
    }
}
