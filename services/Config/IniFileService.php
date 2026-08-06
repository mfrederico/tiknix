<?php
/**
 * IniFileService — round-trip INI parser + writer that preserves comments,
 * blank lines, and structure. Designed for the /settings/ini editor where
 * we read a config file, let an admin tweak values + add keys, and write
 * the file back without disturbing layout.
 *
 * Goes beyond PHP's parse_ini_file (which strips comments) by reading the
 * file line-by-line and keeping the original lines as the source of truth.
 * On save, kvp lines that have changed are regenerated; everything else is
 * passed through verbatim.
 *
 * Inline metadata convention (this is the glue with the editor UI):
 *
 *   [section] ; {"obfuscate": ["client_secret", "provision_secret"]}
 *   api_version = "2026-04" ; {"type":"string", "min":7, "max":7, "format":"####-##"}
 *
 * The trailing `; { ... }` is parsed as JSON and exposed as ->meta on the
 * section/key. Format wildcards: "#" → digit, "A" → letter — compiled to
 * a PCRE regex by validateValue() at save time.
 *
 * Permissions to read/write are NOT enforced here — caller must gate
 * (Settings controller restricts /settings/ini to ROOT).
 */

namespace app\services\Config;

class IniFileService {

    /**
     * Conf directory the editor is allowed to touch. Anything outside this
     * tree gets rejected by openPath() to keep the editor from being a
     * generic file browser.
     */
    public static function confDir(): string {
        return realpath(__DIR__ . '/../../conf') ?: (dirname(__DIR__, 2) . '/conf');
    }

    /**
     * Resolve a request-supplied basename to an absolute path inside conf/.
     * Returns null if the file doesn't exist or escapes the conf root.
     */
    public static function resolvePath(string $basename): ?string {
        // Reject anything with a path separator — basename only. Allow a single
        // ".example.ini" suffix (single ".." would be a traversal, but the
        // exact substring check below would also reject "..ini" so handle that
        // too).
        if ($basename === '' || str_contains($basename, '/') || str_contains($basename, '\\')
            || str_contains($basename, '..')) {
            return null;
        }
        if (!str_ends_with($basename, '.ini')) return null;
        $full = self::confDir() . '/' . $basename;
        if (!is_file($full)) return null;
        // Belt + braces: realpath check the resolved path is inside conf/.
        $real = realpath($full);
        $confReal = self::confDir();
        if ($real === false || strncmp($real, $confReal, strlen($confReal)) !== 0) return null;
        return $real;
    }

    /**
     * Is this basename a `.example.ini` template? Templates are read-only
     * scaffolds — editor offers a "create from template" flow that copies
     * them into a real config file with the .example portion stripped.
     */
    public static function isExample(string $basename): bool {
        return str_ends_with($basename, '.example.ini');
    }

    /**
     * Strip the `.example` segment to get the "real" filename a template
     * should produce, e.g. mcp.example.ini → mcp.ini.
     */
    public static function actualFilenameForExample(string $basename): string {
        return preg_replace('/\.example(?=\.ini$)/', '', $basename);
    }

    /**
     * Copy a template into a fresh config file. Refuses to overwrite an
     * existing target — caller should redirect them to edit it instead.
     * Returns ['ok' => bool, 'path' => string|null, 'basename' => string,
     *          'error' => string|null].
     */
    public static function createFromTemplate(string $exampleBasename): array {
        if (!self::isExample($exampleBasename)) {
            return ['ok' => false, 'error' => 'Source is not an *.example.ini template.'];
        }
        $src = self::resolvePath($exampleBasename);
        if (!$src) return ['ok' => false, 'error' => 'Template file not found.'];

        $newName = self::actualFilenameForExample($exampleBasename);
        // Don't put the new path through resolvePath() — it requires the file
        // to exist. Re-validate the basename shape ourselves.
        if (!preg_match('/^[A-Za-z0-9_.-]+\.ini$/', $newName) || str_contains($newName, '..')) {
            return ['ok' => false, 'error' => 'Computed target filename is invalid.'];
        }
        $dest = self::confDir() . '/' . $newName;
        if (file_exists($dest)) {
            return ['ok' => false, 'error' => "{$newName} already exists.", 'basename' => $newName];
        }
        if (!@copy($src, $dest)) {
            return ['ok' => false, 'error' => 'Could not copy template (check directory permissions).'];
        }
        return ['ok' => true, 'path' => $dest, 'basename' => $newName, 'error' => null];
    }

    /**
     * List editable INI files in conf/ with size + mtime. Templates split
     * out so the UI can render them in a separate "Create from template"
     * card. Only templates whose actual sibling doesn't already exist are
     * worth showing — if both mcp.ini and mcp.example.ini exist, the
     * template is no longer actionable.
     */
    public static function listFiles(): array {
        $dir = self::confDir();
        $editable = [];
        $templates = [];
        $existing = [];
        foreach (glob($dir . '/*.ini') ?: [] as $path) {
            $existing[basename($path)] = $path;
        }
        foreach ($existing as $name => $path) {
            $entry = [
                'name'     => $name,
                'path'     => $path,
                'size'     => @filesize($path) ?: 0,
                'mtime'    => @filemtime($path) ?: 0,
                'writable' => is_writable($path),
            ];
            if (self::isExample($name)) {
                $actualName = self::actualFilenameForExample($name);
                $entry['actual_name']    = $actualName;
                // actual_exists tells the view which CTA to render: "Create
                // config" when no real sibling yet, "Edit existing" otherwise.
                // Always-visible templates so admins can see what's available
                // even after they've created the real one.
                $entry['actual_exists']  = isset($existing[$actualName]);
                $templates[] = $entry;
            } else {
                $editable[] = $entry;
            }
        }
        usort($editable,  fn($a, $b) => strcmp($a['name'], $b['name']));
        usort($templates, fn($a, $b) => strcmp($a['name'], $b['name']));
        return ['editable' => $editable, 'templates' => $templates];
    }

    /**
     * Parse an INI file into a structured form that preserves the original
     * line layout. Returns:
     *   [
     *     'lines'    => [ ['type' => ..., ...], ... ],   // ordered, every line
     *     'sections' => [ 'name' => [
     *         'meta' => [...],
     *         'keys' => [ 'key' => ['value'=>..., 'meta'=>..., 'lineIdx'=>N] ],
     *     ] ],
     *   ]
     */
    public static function parse(string $path): array {
        $raw = @file_get_contents($path);
        if ($raw === false) return ['lines' => [], 'sections' => []];

        // Normalize line endings so parse + write are stable across editors.
        $lines = preg_split("/\r\n|\r|\n/", $raw);
        $structured = [];
        $sections = [];
        $current = ''; // empty section name = "global" (top-of-file kvps)
        $sections[$current] = ['meta' => [], 'keys' => []];

        foreach ($lines as $idx => $line) {
            $trim = trim($line);

            // Blank line
            if ($trim === '') {
                $structured[] = ['type' => 'blank', 'raw' => $line];
                continue;
            }

            // Comment-only line (starts with ; or # — both are INI-standard).
            if ($trim[0] === ';' || $trim[0] === '#') {
                $structured[] = ['type' => 'comment', 'raw' => $line];
                continue;
            }

            // Section header: [name]   optionally followed by ; { ... } meta
            if ($trim[0] === '[') {
                $endBracket = strpos($trim, ']');
                if ($endBracket !== false) {
                    $name = trim(substr($trim, 1, $endBracket - 1));
                    $rest = trim(substr($trim, $endBracket + 1));
                    $meta = self::extractInlineMeta($rest);
                    $current = $name;
                    if (!isset($sections[$current])) {
                        $sections[$current] = ['meta' => $meta, 'keys' => []];
                    } else {
                        $sections[$current]['meta'] = $meta;
                    }
                    $structured[] = [
                        'type' => 'section',
                        'name' => $name,
                        'meta' => $meta,
                        'raw'  => $line,
                    ];
                    continue;
                }
            }

            // key = value [; { ... }]
            if (str_contains($trim, '=')) {
                [$rawKey, $rawRest] = explode('=', $trim, 2);
                $key = trim($rawKey);
                $valuePart = trim($rawRest);
                // Split off trailing inline comment / meta. We have to be careful:
                // ; can appear inside a quoted string. Find the first ; that's
                // outside any quotes.
                [$valueWithQuotes, $tail] = self::splitValueAndComment($valuePart);
                $value = self::stripQuotes(trim($valueWithQuotes));
                $meta = self::extractInlineMeta($tail);

                // Keep the human trailing comment. The tail is EITHER meta JSON or
                // prose, never both, so a tail that did not parse as meta is a
                // comment worth carrying across a rewrite. Without this, changing a
                // value silently strips the explanation next to it — and in these
                // files those explanations are the documentation ("false = OPTIONAL
                // (prompted but can Skip for now)"). Losing them on every toggle is
                // how a config file becomes unreadable one edit at a time.
                $comment = ($meta === [] && trim($tail) !== '') ? $tail : '';

                $idxInLines = count($structured);
                $structured[] = [
                    'type'    => 'kvp',
                    'section' => $current,
                    'key'     => $key,
                    'value'   => $value,
                    'meta'    => $meta,
                    'comment' => $comment,
                    'raw'     => $line,
                ];
                $sections[$current]['keys'][$key] = [
                    'value'   => $value,
                    'meta'    => $meta,
                    'comment' => $comment,
                    'lineIdx' => $idxInLines,
                ];
                continue;
            }

            // Anything else — keep as a passthrough so we don't lose it.
            $structured[] = ['type' => 'unknown', 'raw' => $line];
        }

        return ['lines' => $structured, 'sections' => $sections];
    }

    /**
     * Apply edits + writes back to disk. $changes is a nested map:
     *   [
     *     'sections' => [
     *       'shopify' => [
     *         'keys'     => ['api_version' => '2026-04', 'new_thing' => 'val'],
     *         'newKeys'  => ['extra_key' => 'extra_value'],
     *         'deletes'  => ['old_key'],               // remove these kvps
     *         'keyMeta'  => ['api_version' => ['type' => 'string', 'min' => 7]],
     *         'meta'     => [...],   // optional — overwrite section meta
     *       ],
     *     ],
     *     'newSections' => [
     *       'integrations' => ['enabled' => '1', ...],
     *     ],
     *   ]
     *
     * Validation: each value runs through validateValue() against the meta
     * declared in the file (or new meta if `keyMeta` updates it in the same
     * pass). Returns ['ok' => bool, 'errors' => [...]]. On ok, writes the
     * file atomically (temp + rename).
     */
    public static function save(string $path, array $changes): array {
        // Trace what's being written + at what scope. Useful when an admin
        // says "I clicked Save and nothing happened" — the input shape
        // tells us whether the form actually posted what we expected.
        \Flight::get('log')?->debug('IniFileService::save', [
            'path'         => $path,
            'sections'     => array_keys($changes['sections'] ?? []),
            'newSections'  => array_keys($changes['newSections'] ?? []),
        ]);
        $parsed = self::parse($path);
        $lines    = $parsed['lines'];
        $sections = $parsed['sections'];
        $errors   = [];

        // ── validate everything before mutating the structure ──
        $sectionInputs = $changes['sections'] ?? [];
        foreach ($sectionInputs as $secName => $secChanges) {
            $secMeta = $sections[$secName]['meta'] ?? [];
            $deletes = array_flip(array_map('strval', $secChanges['deletes'] ?? []));
            $keyMetaUpdates = is_array($secChanges['keyMeta'] ?? null) ? $secChanges['keyMeta'] : [];
            foreach (($secChanges['keys'] ?? []) as $k => $v) {
                if (isset($deletes[$k])) continue; // value of a deleted key — ignore
                // If meta is being updated this pass, validate against the new
                // rules (so the operator can tighten + supply a value in one go).
                $effectiveMeta = $keyMetaUpdates[$k] ?? ($sections[$secName]['keys'][$k]['meta'] ?? []);
                $err = self::validateValue($v, $effectiveMeta, $secMeta, $k);
                if ($err) $errors[] = "[{$secName}] {$k}: {$err}";
            }
            foreach (($secChanges['newKeys'] ?? []) as $k => $v) {
                if (!self::isValidKeyName($k)) { $errors[] = "[{$secName}] '{$k}' is not a valid key name."; continue; }
                if (isset($sections[$secName]['keys'][$k])) { $errors[] = "[{$secName}] {$k} already exists."; continue; }
                $err = self::validateValue($v, [], $secMeta, $k);
                if ($err) $errors[] = "[{$secName}] {$k}: {$err}";
            }
            // Reject metadata updates targeting keys that don't exist.
            foreach ($keyMetaUpdates as $k => $_) {
                if (isset($deletes[$k])) continue;
                if (!isset($sections[$secName]['keys'][$k])) {
                    $errors[] = "[{$secName}] {$k}: cannot update metadata — key not found.";
                }
            }
        }
        $newSectionInputs = $changes['newSections'] ?? [];
        foreach ($newSectionInputs as $secName => $newKeys) {
            if (!self::isValidSectionName($secName)) { $errors[] = "'{$secName}' is not a valid section name."; continue; }
            if (isset($sections[$secName]))         { $errors[] = "Section [{$secName}] already exists."; continue; }
            foreach (($newKeys ?: []) as $k => $v) {
                if (!self::isValidKeyName($k))      { $errors[] = "[{$secName}] '{$k}' is not a valid key name."; continue; }
            }
        }
        if ($errors) return ['ok' => false, 'errors' => $errors];

        // ── apply deletes first so subsequent value/meta updates don't touch
        //     lines we're about to drop. Track line indices to remove and
        //     splice them out in one pass to keep $sections[]['lineIdx']
        //     references that we still need stable.
        $linesToDrop = [];
        foreach ($sectionInputs as $secName => $secChanges) {
            foreach (($secChanges['deletes'] ?? []) as $delKey) {
                $delKey = (string)$delKey;
                if (!isset($sections[$secName]['keys'][$delKey])) continue;
                $linesToDrop[] = $sections[$secName]['keys'][$delKey]['lineIdx'];
                unset($sections[$secName]['keys'][$delKey]);
            }
        }

        // ── apply key-value + per-key metadata updates to existing kvp lines ──
        foreach ($sectionInputs as $secName => $secChanges) {
            $keyMetaUpdates = is_array($secChanges['keyMeta'] ?? null) ? $secChanges['keyMeta'] : [];
            foreach (($secChanges['keys'] ?? []) as $k => $v) {
                if (!isset($sections[$secName]['keys'][$k])) continue;
                $idx = $sections[$secName]['keys'][$k]['lineIdx'];
                $existingMeta = $lines[$idx]['meta'] ?? [];
                // Meta update wins over the existing inline JSON. Merge isn't
                // worth it — UI submits the full intended ruleset every save.
                $newMeta = array_key_exists($k, $keyMetaUpdates) ? $keyMetaUpdates[$k] : $existingMeta;
                $lines[$idx]['value'] = $v;
                $lines[$idx]['meta']  = $newMeta;
                $lines[$idx]['raw']   = self::renderKvpLine($k, $v, $newMeta, (string)($lines[$idx]['comment'] ?? ''));
            }
            // Meta-only updates (no value change submitted but metadata is).
            foreach ($keyMetaUpdates as $k => $newMeta) {
                if (!isset($sections[$secName]['keys'][$k])) continue;
                if (isset($secChanges['keys'][$k])) continue; // already handled above
                $idx = $sections[$secName]['keys'][$k]['lineIdx'];
                $lines[$idx]['meta'] = $newMeta;
                $lines[$idx]['raw']  = self::renderKvpLine($k, (string)$lines[$idx]['value'], $newMeta, (string)($lines[$idx]['comment'] ?? ''));
            }
        }

        // ── physically drop the deleted lines now (after everything that
        //     referenced lineIdx has run). Sort high → low so each splice
        //     doesn't disturb earlier indices.
        if (!empty($linesToDrop)) {
            rsort($linesToDrop);
            foreach ($linesToDrop as $i) array_splice($lines, $i, 1);
        }

        // ── append new keys to the section ──
        // We slot them right before the next section header (or EOF). Keeps
        // visual locality so admins see them under the section they belong to.
        foreach ($sectionInputs as $secName => $secChanges) {
            $newKeys = $secChanges['newKeys'] ?? [];
            if (empty($newKeys)) continue;
            $insertAt = self::findSectionEndIndex($lines, $secName);
            $additions = [];
            foreach ($newKeys as $k => $v) {
                $additions[] = ['type' => 'kvp', 'section' => $secName, 'key' => $k, 'value' => $v, 'meta' => [], 'raw' => self::renderKvpLine($k, $v, [])];
            }
            array_splice($lines, $insertAt, 0, $additions);
        }

        // ── append entirely new sections at the end of file ──
        foreach ($newSectionInputs as $secName => $newKeys) {
            // Trim trailing blanks then re-add one for separation.
            while (!empty($lines) && end($lines)['type'] === 'blank') array_pop($lines);
            $lines[] = ['type' => 'blank', 'raw' => ''];
            $lines[] = ['type' => 'section', 'name' => $secName, 'meta' => [], 'raw' => "[{$secName}]"];
            foreach (($newKeys ?: []) as $k => $v) {
                $lines[] = ['type' => 'kvp', 'section' => $secName, 'key' => $k, 'value' => $v, 'meta' => [], 'raw' => self::renderKvpLine($k, $v, [])];
            }
        }

        // ── render lines back to a single string + atomic write ──
        $out = '';
        foreach ($lines as $line) {
            $out .= ($line['raw'] ?? '') . "\n";
        }
        // Drop one trailing newline added by the loop so we land on a single \n.
        $out = rtrim($out, "\n") . "\n";

        $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $out, LOCK_EX) === false) {
            return ['ok' => false, 'errors' => ['Failed to write temp file.']];
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            return ['ok' => false, 'errors' => ['Failed to swap config file into place.']];
        }
        return ['ok' => true, 'errors' => []];
    }

    // ─── Rendering helpers ──────────────────────────────────────────────

    /**
     * Quote-aware split for "value [; metaOrComment]". Handles values that
     * contain a literal `;` inside double quotes (e.g. scopes lists).
     */
    private static function splitValueAndComment(string $valuePart): array {
        $inQuote = false;
        $len = strlen($valuePart);
        for ($i = 0; $i < $len; $i++) {
            $c = $valuePart[$i];
            if ($c === '"') { $inQuote = !$inQuote; continue; }
            if (!$inQuote && ($c === ';' || $c === '#')) {
                return [substr($valuePart, 0, $i), substr($valuePart, $i + 1)];
            }
        }
        return [$valuePart, ''];
    }

    private static function stripQuotes(string $v): string {
        if (strlen($v) >= 2 && $v[0] === '"' && $v[strlen($v)-1] === '"') {
            return substr($v, 1, -1);
        }
        return $v;
    }

    /**
     * Try to JSON-decode the trailing comment text after a value or section
     * header. Returns an associative array on success, [] otherwise. Handles
     * leading whitespace / curly braces tolerantly.
     */
    private static function extractInlineMeta(string $tail): array {
        $tail = trim($tail);
        if ($tail === '') return [];
        // The convention is JSON wrapped in {...}. Find the first { and let
        // json_decode do the work.
        $start = strpos($tail, '{');
        if ($start === false) return [];
        $candidate = substr($tail, $start);
        $decoded = json_decode($candidate, true);
        if (is_array($decoded)) return $decoded;
        return [];
    }

    private static function renderKvpLine(string $key, string $value, array $meta = [], string $comment = ''): string {
        // Wrap value in quotes if it contains anything that looks structural
        // (whitespace, commas, semis, equals) — safe default for the editor.
        $needsQuote = $value === '' || preg_match('/[\s,;=#"\\\\]/u', $value);
        $rendered   = $needsQuote ? '"' . str_replace('"', '\\"', $value) . '"' : $value;
        $line = $key . ' = ' . $rendered;
        if (!empty($meta)) {
            // The meta convention owns the tail when it is present.
            $line .= ' ; ' . json_encode($meta, JSON_UNESCAPED_SLASHES);
        } elseif ($comment !== '') {
            // Carry the human comment through unchanged, leading space and all,
            // so a rewritten line still reads the way its author wrote it.
            $line .= ' ;' . rtrim($comment);
        }
        return $line;
    }

    private static function findSectionEndIndex(array $lines, string $secName): int {
        $inSection = false;
        $lastIdxInSection = null;
        foreach ($lines as $i => $l) {
            if ($l['type'] === 'section') {
                if ($inSection) return $i; // hit the next section
                if ($l['name'] === $secName) { $inSection = true; $lastIdxInSection = $i; }
                continue;
            }
            if ($inSection) $lastIdxInSection = $i;
        }
        return $lastIdxInSection !== null ? ($lastIdxInSection + 1) : count($lines);
    }

    private static function isValidSectionName(string $name): bool {
        return (bool)preg_match('/^[A-Za-z][A-Za-z0-9_.-]*$/', $name);
    }

    private static function isValidKeyName(string $name): bool {
        return (bool)preg_match('/^[A-Za-z_][A-Za-z0-9_.-]*$/', $name);
    }

    // ─── Validation ─────────────────────────────────────────────────────

    /**
     * Apply the per-key validation rules carried in $meta. Returns null on
     * success, an error message string on failure.
     *
     * Recognized rules:
     *   type     "string" (default) | "int" | "bool" | "email" | "url"
     *   min      string length (string/email/url) or numeric value (int)
     *   max      string length (string/email/url) or numeric value (int)
     *   format   wildcard pattern: # = digit, A = letter, x = any
     *   obfuscate (section meta) — display-only, no validation impact here
     */
    public static function validateValue(string $value, array $keyMeta, array $sectionMeta, string $keyName): ?string {
        $type = strtolower((string)($keyMeta['type'] ?? 'string'));

        switch ($type) {
            case 'int':
                if ($value === '') return null;
                if (!preg_match('/^-?\d+$/', $value)) return 'must be an integer';
                $n = (int)$value;
                if (isset($keyMeta['min']) && $n < (int)$keyMeta['min']) return 'must be ≥ ' . $keyMeta['min'];
                if (isset($keyMeta['max']) && $n > (int)$keyMeta['max']) return 'must be ≤ ' . $keyMeta['max'];
                break;
            case 'bool':
                if (!in_array(strtolower($value), ['', '0', '1', 'true', 'false', 'yes', 'no', 'on', 'off'], true)) {
                    return 'must be a boolean (0/1, true/false)';
                }
                break;
            case 'email':
                if ($value === '') break;
                if (!filter_var($value, FILTER_VALIDATE_EMAIL)) return 'must be a valid email';
                break;
            case 'url':
                if ($value === '') break;
                if (!filter_var($value, FILTER_VALIDATE_URL)) return 'must be a valid URL';
                break;
            case 'string':
            default:
                $len = mb_strlen($value);
                if (isset($keyMeta['min']) && $len < (int)$keyMeta['min']) return 'min length ' . (int)$keyMeta['min'];
                if (isset($keyMeta['max']) && $len > (int)$keyMeta['max']) return 'max length ' . (int)$keyMeta['max'];
                break;
        }

        if (!empty($keyMeta['format'])) {
            $regex = self::compileFormat((string)$keyMeta['format']);
            if (!preg_match($regex, $value)) {
                return "must match format '{$keyMeta['format']}'";
            }
        }
        return null;
    }

    /**
     * Translate a wildcard format string into a PCRE pattern. # = digit,
     * A = letter, x = any character; everything else is literal-escaped.
     */
    private static function compileFormat(string $fmt): string {
        $pattern = '';
        $len = strlen($fmt);
        for ($i = 0; $i < $len; $i++) {
            $c = $fmt[$i];
            switch ($c) {
                case '#': $pattern .= '\d'; break;
                case 'A': $pattern .= '[A-Za-z]'; break;
                case 'x': $pattern .= '.'; break;
                default:  $pattern .= preg_quote($c, '/');
            }
        }
        return '/^' . $pattern . '$/u';
    }

    /**
     * Helper for the view: given a key's section-level meta, decide whether
     * the value should be obfuscated (masked) in the UI.
     *
     * Resolution order:
     *   1. Per-key  {"obfuscate": true|false}  (most specific — wins)
     *   2. Section-level {"obfuscate": [k1, k2]} list
     *   3. Heuristic match on the key name — any key whose name contains
     *      "secret" / "password" / "token" / "private_key" / "_key" / "apikey"
     *      is treated as sensitive even if nobody marked it. Defense in
     *      depth — protects files like mailgun.ini that ship without the
     *      metadata convention. Override with {"obfuscate": false} per-key.
     */
    public static function shouldObfuscate(string $key, array $sectionMeta, array $keyMeta = []): bool {
        // type=secret implies obfuscate=true unless explicitly overridden.
        if (strtolower((string)($keyMeta['type'] ?? '')) === 'secret') {
            // Per-key obfuscate=false still wins so an operator can declare
            // a "secret" type but force-show the value (audit dump, etc.).
            if (array_key_exists('obfuscate', $keyMeta)) {
                return (bool)$keyMeta['obfuscate'];
            }
            return true;
        }
        // Explicit per-key flag wins both ways — true forces, false vetoes.
        if (array_key_exists('obfuscate', $keyMeta)) {
            return (bool)$keyMeta['obfuscate'];
        }
        $list = $sectionMeta['obfuscate'] ?? null;
        if (is_array($list) && in_array($key, $list, true)) return true;

        // Heuristic fallback — last line of defense for files lacking
        // explicit metadata.
        $needles = ['secret', 'password', 'passwd', 'token', 'private_key', 'apikey', 'api_key'];
        $lower = strtolower($key);
        foreach ($needles as $n) {
            if (str_contains($lower, $n)) return true;
        }
        // Bare "key" name (top-level mailgun key) or a *_key suffix that
        // isn't obviously public. Public keys are rare in our config files;
        // operator can set {"obfuscate": false} to opt out.
        if ($lower === 'key') return true;
        if (preg_match('/(^|_)(signing|webhook|client|provision|encryption|admin|access|refresh|bearer)_(key|secret|token)$/i', $lower)) return true;
        if (preg_match('/_key$/', $lower) && !str_contains($lower, 'public')) return true;
        return false;
    }
}
