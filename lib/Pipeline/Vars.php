<?php
/**
 * Pipeline\Vars — {variable} substitution, the pipeline language's core.
 *
 * Resolves `{path}` tokens against a bag built by the Executor:
 *   {context.x}            input params passed to the run
 *   {<step>.output.a.b}    a prior step's structured output (dot-path)
 *   {<step>.stdout}        a prior step's stdout / stderr / exit
 *   {prev.x}               the previous step's output (dot-path)
 *   {time.now|date|ts}     wall-clock built-ins
 *   {run_id} {run_uid} {run_directory} {pipeline_slug}   run built-ins
 *
 * Scalars interpolate in place; a token that resolves to an array/object is
 * JSON-encoded (so it can flow into a shell arg or an http body). An unknown token
 * is left literal, so a stray brace in config never silently vanishes.
 */

namespace app\Pipeline;

class Vars {

    /** Recursively substitute tokens in a config value (string | array). */
    public static function resolve($value, array $bag) {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) $out[$k] = self::resolve($v, $bag);
            return $out;
        }
        if (!is_string($value) || strpos($value, '{') === false) return $value;

        // A string that is EXACTLY one token returns the raw typed value (so
        // {fetch.output.rows} stays an array, not a JSON string), which matters for
        // steps that want structured input. Mixed strings interpolate + stringify.
        if (preg_match('/^\{([a-zA-Z0-9_.\-]+)\}$/', $value, $m)) {
            $v = self::lookup($m[1], $bag);
            return $v === null ? $value : $v;
        }
        return preg_replace_callback('/\{([a-zA-Z0-9_.\-]+)\}/', function ($m) use ($bag) {
            $v = self::lookup($m[1], $bag);
            if ($v === null) return $m[0];                       // leave unknown tokens literal
            return is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_SLASHES);
        }, $value);
    }

    /** Dot-path lookup into the bag; returns null when any segment is missing. */
    public static function lookup(string $path, array $bag) {
        $cur = $bag;
        foreach (explode('.', $path) as $seg) {
            if (is_array($cur) && array_key_exists($seg, $cur)) { $cur = $cur[$seg]; continue; }
            if (is_object($cur) && isset($cur->$seg)) { $cur = $cur->$seg; continue; }
            return null;
        }
        return $cur;
    }

    /** Time built-ins for the bag. */
    public static function timeBag(): array {
        $t = time();
        return ['now' => date('Y-m-d H:i:s', $t), 'date' => date('Y-m-d', $t),
                'ts' => $t, 'iso' => date('c', $t)];
    }
}
