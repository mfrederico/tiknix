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
        // Validate the schedule if present (empty = no schedule, fine).
        $cron = trim((string) ($def['trigger']['cron'] ?? ''));
        if ($cron !== '' && !self::validCron($cron)) {
            $errors[] = "invalid trigger.cron '$cron' (need 5 fields: minute hour day month weekday)";
        }
        return $errors;
    }

    /** True if $expr is a well-formed 5-field cron expression (min hour dom mon dow). */
    public static function validCron(string $expr): bool {
        $f = preg_split('/\s+/', trim($expr));
        if (count($f) !== 5) return false;
        $ranges = [[0, 59], [0, 23], [1, 31], [1, 12], [0, 7]];   // dow: 0 and 7 both = Sunday
        foreach ($f as $i => $spec) {
            if (!self::validCronField($spec, $ranges[$i][0], $ranges[$i][1])) return false;
        }
        return true;
    }

    private static function validCronField(string $spec, int $lo, int $hi): bool {
        if ($spec === '') return false;
        foreach (explode(',', $spec) as $part) {
            if ($part === '') return false;
            if (strpos($part, '/') !== false) {
                [$part, $s] = explode('/', $part, 2);
                if (!ctype_digit($s) || (int) $s < 1) return false;
            }
            if ($part === '*') continue;
            if (strpos($part, '-') !== false) {
                [$a, $b] = explode('-', $part, 2);
                if (!ctype_digit($a) || !ctype_digit($b) || (int) $a < $lo || (int) $b > $hi || (int) $a > (int) $b) return false;
            } elseif (!ctype_digit($part) || (int) $part < $lo || (int) $part > $hi) {
                return false;
            }
        }
        return true;
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
