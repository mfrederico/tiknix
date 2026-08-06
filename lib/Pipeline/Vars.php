<?php
/**
 * Pipeline\Vars — {variable} substitution, the pipeline language's core.
 *
 * Resolves `{path}` tokens against a bag built by the Executor:
 *   {context.x}            input params passed to the run
 *   {<step>.a.b}           a prior step's output, flattened (same shape as {prev.*})
 *   {<step>.output.a.b}    the same output via its explicit namespace (back-compat)
 *   {<step>.stdout}        a prior step's stdout / stderr / exit
 *   {<step>.input.x}       the resolved config/args that step ran with
 *   {prev.x}               the previous step's output (dot-path)
 *   {time.now|date|ts}     wall-clock built-ins
 *   {run_id} {run_uid} {run_directory} {pipeline_slug}   run built-ins
 *
 * Reserved step keys (output/stdout/stderr/exit/input) win over same-named output
 * keys; reach a shadowed output key via its explicit {<step>.output.<key>} path.
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
        if (preg_match('/^\{([a-zA-Z0-9_.\-]+)(?:\|([^}]*))?\}$/', $value, $m)) {
            $v = self::lookup($m[1], $bag);
            if ($v !== null) return $v;
            return isset($m[2]) ? self::literal($m[2]) : $value;
        }
        return preg_replace_callback('/\{([a-zA-Z0-9_.\-]+)(?:\|([^}]*))?\}/', function ($m) use ($bag) {
            $v = self::lookup($m[1], $bag);
            if ($v === null) {
                // An unknown token stays literal so a typo is VISIBLE rather than
                // silently becoming empty — unless the author wrote a fallback.
                if (!isset($m[2])) return $m[0];
                $v = self::literal($m[2]);
            }
            if ($v === null) return '';
            return is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_SLASHES);
        }, $value);
    }

    /**
     * Coerce a written fallback to a typed value.
     *
     * The fallback in {token|fallback} is authored text, so the words null / true /
     * false and bare numbers mean what they say. This is what makes cursor
     * pagination expressible: page one has no cursor yet, and a GraphQL API wants a
     * real null there — the empty string is a different thing and most APIs reject
     * it. Writing {cursor.output|null} says "null until a page has been fetched",
     * which is true, rather than leaving the literal token to be sent as a cursor.
     *
     * A fallback only applies when the author wrote one. An unresolved token with
     * no fallback still comes through literally, so a mistyped step name stays
     * loud instead of quietly becoming empty.
     */
    private static function literal(string $raw) {
        $t = trim($raw);
        if ($t === '') return '';
        $low = strtolower($t);
        if ($low === 'null')  return null;
        if ($low === 'true')  return true;
        if ($low === 'false') return false;
        if (is_numeric($t))   return $t + 0;
        return $raw;
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
