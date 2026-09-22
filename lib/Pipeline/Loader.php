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

    /**
     * Pipelines that enabled CONCEPTS ship (COMPONENTS_PLAN.md, "Pipeline definitions as a
     * concept part"): slug => ['concept' => name, 'file' => absolute path]. The instance's own
     * pipelines/ is read first and wins; these are appended. Empty by default, so every
     * one-argument construction — which is every caller that predates concepts — reads
     * exactly what it always did.
     *
     * @var array<string,array{concept:string,file:string}>
     */
    private array $conceptSources = [];

    /**
     * @param string $root            the app/instance root; pipelines live at <root>/pipelines/
     * @param array  $conceptSources  from Concepts::pipelineSources(), for THIS install only —
     *                                a reader of another install's directory passes what that
     *                                install's flags say (Concepts::pipelineSourcesFor)
     */
    public function __construct(string $root, array $conceptSources = []) {
        $this->dir = rtrim($root, '/') . '/pipelines';
        foreach ($conceptSources as $slug => $src) {
            $slug = self::safeSlug((string) $slug);
            if ($slug === '' || !is_string($src['concept'] ?? null) || !is_string($src['file'] ?? null)) {
                throw new \InvalidArgumentException("Loader: concept source '{$slug}' must be [concept, file].");
            }
            $this->conceptSources[$slug] = ['concept' => $src['concept'], 'file' => $src['file']];
        }
    }

    public function dir(): string { return $this->dir; }

    /**
     * The loader for the install THIS process runs in: its own pipelines/ plus its enabled
     * concepts' (Concepts::pipelineSources reads this install's flags). For any other root
     * there are no flags to read in-process, so the sources are empty — a reader of another
     * install (InstanceAutomations, Introspector, pipeline-cron) derives them from that
     * install's settings with Concepts::pipelineSourcesFor() and constructs the Loader itself.
     */
    public static function forInstall(string $root): self {
        $here = realpath(dirname(__DIR__, 2));
        $there = realpath($root);
        $sources = ($here !== false && $there !== false && $here === $there && class_exists('\\app\\Concepts'))
            ? \app\Concepts::instance()->pipelineSources() : [];
        return new self($root, $sources);
    }

    /** All valid definitions keyed by slug: the instance's own first, then enabled concepts'. */
    public function all(): array {
        $out = [];
        foreach (glob($this->dir . '/*.json') ?: [] as $file) {
            $def = $this->read($file);
            if ($def && ($def['slug'] ?? '') !== '') $out[$def['slug']] = $def;
        }
        foreach ($this->conceptSources as $slug => $src) {
            if (isset($out[$slug])) continue;   // the instance's own file wins (verify() refuses this at enable)
            $def = is_file($src['file']) ? $this->read($src['file']) : null;
            if ($def && ($def['slug'] ?? '') === $slug) $out[$slug] = $def;
        }
        return $out;
    }

    public function get(string $slug): ?array {
        $slug = self::safeSlug($slug);
        if ($slug === '') return null;
        $file = $this->dir . '/' . $slug . '.json';
        if (is_file($file)) return $this->read($file);
        $src = $this->conceptSources[$slug] ?? null;
        if ($src !== null && is_file($src['file'])) {
            $def = $this->read($src['file']);
            return ($def && ($def['slug'] ?? '') === $slug) ? $def : null;
        }
        return null;
    }

    /** The concept a slug comes from, or null for the instance's own pipeline (or an unknown slug). */
    public function originOf(string $slug): ?string {
        $slug = self::safeSlug($slug);
        if ($slug === '' || is_file($this->dir . '/' . $slug . '.json')) return null;
        return $this->conceptSources[$slug]['concept'] ?? null;
    }

    /**
     * Write a definition to its file (create the dir if needed). Returns the path. A concept
     * pipeline is the project's own code once adopted, so it is saved back to the concept's
     * file, not shadowed by a new instance file that would silently take precedence.
     */
    public function save(array $def): string {
        $slug = self::safeSlug((string) ($def['slug'] ?? ''));
        if ($slug === '') throw new \InvalidArgumentException('pipeline needs a valid slug');
        $own = $this->dir . '/' . $slug . '.json';
        $src = $this->conceptSources[$slug] ?? null;
        $file = (!is_file($own) && $src !== null) ? $src['file'] : $own;
        if (!is_dir(dirname($file))) @mkdir(dirname($file), 0775, true);
        file_put_contents($file, json_encode($def, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        return $file;
    }

    /**
     * Delete the instance's own definition. A concept's pipeline is declared by its manifest,
     * so deleting the file would leave the concept unverifiable: refused, with the two ways
     * to do it properly.
     */
    public function delete(string $slug): bool {
        $slug = self::safeSlug($slug);
        $file = $this->dir . '/' . $slug . '.json';
        if (!is_file($file) && isset($this->conceptSources[$slug])) {
            $c = $this->conceptSources[$slug]['concept'];
            throw new \RuntimeException(
                "pipeline '{$slug}' belongs to concept '{$c}'. Remove it from that concept's provides.pipelines "
              . "(and its file), or disable the concept — it is not deleted from here.");
        }
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
            // A `pipeline` step names its child by slug. Shape here; existence at run time
            // (the Loader that runs it knows the install and its enabled concepts, a
            // definition being validated in a catalog lint does not). A literal self-call
            // is never right and is refused now.
            if ($type === 'pipeline') {
                $child = (string) (($s['config'] ?? [])['slug'] ?? '');
                if ($child === '') {
                    $errors[] = "step '$n': pipeline step needs a slug";
                } elseif (strpos($child, '{') === false && self::safeSlug($child) === '') {
                    $errors[] = "step '$n': '$child' is not a valid pipeline slug";
                } elseif ($child === (string) ($def['slug'] ?? '')) {
                    $errors[] = "step '$n': a pipeline may not call itself";
                }
            }
        }
        // A step that reaches OUT must say where to.
        //
        // A pipeline whose data source is implicit is a pipeline nobody can audit:
        // you cannot tell what it touches without reading every step, and a
        // connection step with no connector used to fail deep inside the broker with
        // a message about the broker rather than about the pipeline. Declaring the
        // source is cheap; discovering it at 3am is not.
        //
        // Either form counts — a named source from the `sources` block, or the
        // connector inline on the step. What is refused is neither.
        $sources = is_array($def['sources'] ?? null) ? $def['sources'] : [];
        foreach ($sources as $sname => $src) {
            if (!preg_match('/^[a-z0-9_]+$/i', (string) $sname)) {
                $errors[] = "source '$sname': invalid name";
            } elseif (!is_array($src) || (trim((string) ($src['connector'] ?? '')) === '')) {
                $errors[] = "source '$sname': needs a connector";
            }
        }
        foreach ($steps as $s) {
            $type = (string) ($s['type'] ?? '');
            if ($type !== 'connection' && $type !== 'query') continue;
            $n   = (string) ($s['name'] ?? '?');
            $cfg = (array) ($s['config'] ?? []);
            $ref = trim((string) ($cfg['source'] ?? ''));

            if ($ref !== '') {
                if (!isset($sources[$ref])) {
                    $errors[] = "step '$n': source '$ref' is not declared"
                              . ($sources ? ' — declared: ' . implode(', ', array_keys($sources)) : ' (this pipeline declares no sources)');
                }
                continue;
            }
            // `query` defaults to the connector it is named for; `connection` cannot.
            if ($type === 'connection' && trim((string) ($cfg['connector'] ?? '')) === '') {
                $errors[] = "step '$n': no data source — name one from `sources`, or set `connector` on the step";
            }
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
        // GitHub push trigger (fired by the /webhook/github keystone): { events[], branches[] }.
        $gh = $def['trigger']['github'] ?? null;
        if ($gh !== null) {
            if (!is_array($gh)) $errors[] = 'trigger.github must be an object';
            else {
                if (isset($gh['events']) && !is_array($gh['events'])) $errors[] = 'trigger.github.events must be an array';
                if (isset($gh['branches']) && !is_array($gh['branches'])) $errors[] = 'trigger.github.branches must be an array';
            }
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
