<?php
/**
 * OpenApiSpec — read an OpenAPI 3.x or Swagger 2.0 document and reduce it to the
 * few things a pipeline author actually needs: what the API is called, where it
 * lives, and the list of operations they can call.
 *
 * This is NOT a validator and not a client generator. A REST connection can already
 * call any path on its host; the spec only removes the guesswork about WHICH paths
 * exist and what they take. So an unparseable corner of a document is skipped
 * rather than fatal — a spec with one malformed operation should still tell you
 * about the other two hundred.
 *
 * Both dialects are normalized to the same operation shape:
 *   [ id, method, path, summary, tags[], params[ {name,in,required,type} ], has_body ]
 *
 * Storage lives with the caller. A real spec runs to megabytes — far too big for a
 * connection's metadata column — so the document belongs in secure/ and only this
 * digest travels with the connection.
 */

namespace app\services\Config;

use Symfony\Component\Yaml\Yaml;

class OpenApiSpec {

    /**
     * A spec larger than this is refused rather than parsed into memory.
     *
     * 24 MB, not the 8 MB this started at: GitHub's own description is 13 MB and
     * was being turned away, which is a poor showing for a feature whose whole
     * point is "import the API you actually use". Measured on that document —
     * decode is instantaneous and peaks around 64 MB, so the ceiling is set by
     * politeness to php's memory_limit rather than by anything structural.
     */
    public const MAX_BYTES = 24 * 1024 * 1024;

    /**
     * Operations kept in a digest.
     *
     * The digest lives on the connection and is read on every broker call, so an
     * unbounded one would be a per-request cost. GitHub's 1220 operations are the
     * realistic upper end; the cap sits above that and reports truncation rather
     * than silently shortening the list.
     */
    public const MAX_OPERATIONS = 2000;

    /**
     * Parse a raw spec document (JSON or YAML) into the normalized digest.
     *
     * @throws \Exception when the document is not a spec we can read
     */
    public static function parse(string $raw): array {
        $raw = trim($raw);
        if ($raw === '')                     throw new \Exception('The specification is empty.');
        if (strlen($raw) > self::MAX_BYTES)  throw new \Exception('That specification is larger than 8 MB.');

        $doc = self::decode($raw);
        if (!is_array($doc)) {
            throw new \Exception('That does not look like an OpenAPI or Swagger document.');
        }

        $version = (string) ($doc['openapi'] ?? $doc['swagger'] ?? '');
        if ($version === '') {
            throw new \Exception('No "openapi" or "swagger" version field — is this a specification?');
        }
        $isV2 = str_starts_with($version, '2.');

        $paths = $doc['paths'] ?? null;
        if (!is_array($paths) || !$paths) {
            throw new \Exception('The specification declares no paths.');
        }

        $ops = [];
        foreach ($paths as $path => $item) {
            if (!is_array($item)) continue;
            // Parameters declared on the PATH apply to every operation under it.
            $shared = self::params($item['parameters'] ?? [], $doc);

            foreach ($item as $method => $op) {
                $method = strtoupper((string) $method);
                if (!in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'], true)) continue;
                if (!is_array($op)) continue;

                $params = array_merge($shared, self::params($op['parameters'] ?? [], $doc));

                // Swagger 2 models a body as a parameter with in:"body"; OpenAPI 3
                // gives it its own requestBody. Normalize to one flag, and drop the
                // pseudo-parameter so it is not offered as a query field.
                $hasBody = isset($op['requestBody']);
                foreach ($params as $i => $p) {
                    if (($p['in'] ?? '') === 'body') { $hasBody = true; unset($params[$i]); }
                }

                $ops[] = [
                    'id'       => (string) ($op['operationId'] ?? self::synthesizeId($method, (string) $path)),
                    'method'   => $method,
                    'path'     => (string) $path,
                    // Trimmed: the digest is stored on the connection and read on
                    // every broker call, and some specs put several paragraphs of
                    // markdown in `description`. A line is enough to choose by.
                    'summary'  => mb_substr(trim((string) ($op['summary'] ?? $op['description'] ?? '')), 0, 200),
                    'tags'     => array_values(array_filter(array_map('strval', (array) ($op['tags'] ?? [])))),
                    'params'   => array_values($params),
                    'has_body' => $hasBody,
                ];
            }
        }

        if (!$ops) throw new \Exception('The specification declares no callable operations.');

        // Operation ids are the handle a pipeline calls by, so they have to be
        // unique. Specs in the wild repeat them; suffix rather than drop, so no
        // endpoint silently disappears from the list.
        $seen = [];
        foreach ($ops as $i => $op) {
            $id = $op['id'];
            if (isset($seen[$id])) {
                $id = $id . '_' . (++$seen[$op['id']]);
                $ops[$i]['id'] = $id;
            }
            $seen[$op['id']] = $seen[$op['id']] ?? 1;
        }

        usort($ops, static fn($a, $b) => [$a['path'], $a['method']] <=> [$b['path'], $b['method']]);

        $total = count($ops);
        $truncated = $total > self::MAX_OPERATIONS;
        if ($truncated) $ops = array_slice($ops, 0, self::MAX_OPERATIONS);

        return [
            'title'        => trim((string) ($doc['info']['title'] ?? '')),
            'api_version'  => trim((string) ($doc['info']['version'] ?? '')),
            'spec_version' => $version,
            'servers'      => self::servers($doc, $isV2),
            'operations'   => $ops,
            'total'        => $total,
            // Reported, never silent. A list that quietly stops short reads as "that
            // is the whole API" and sends someone hunting an endpoint that is there.
            'truncated'    => $truncated,
        ];
    }

    /** JSON first, YAML second — a JSON spec is also valid YAML but json_decode is stricter and faster. */
    private static function decode(string $raw): ?array {
        if ($raw[0] === '{' || $raw[0] === '[') {
            $j = json_decode($raw, true);
            if (is_array($j)) return $j;
        }
        try {
            $y = Yaml::parse($raw);
            return is_array($y) ? $y : null;
        } catch (\Throwable $e) {
            throw new \Exception('Could not read that specification: ' . $e->getMessage());
        }
    }

    /**
     * Base URLs the spec advertises.
     *
     * OpenAPI 3 lists them outright. Swagger 2 spreads the same information across
     * host + basePath + schemes, so it is reassembled here rather than asking the
     * caller to know the difference.
     */
    private static function servers(array $doc, bool $isV2): array {
        if (!$isV2) {
            $out = [];
            foreach ((array) ($doc['servers'] ?? []) as $s) {
                $url = trim((string) ($s['url'] ?? ''));
                if ($url !== '') $out[] = $url;
            }
            return $out;
        }

        $host = trim((string) ($doc['host'] ?? ''));
        if ($host === '') return [];
        $base    = trim((string) ($doc['basePath'] ?? ''));
        $schemes = (array) ($doc['schemes'] ?? ['https']);
        $out = [];
        foreach ($schemes as $scheme) {
            $scheme = strtolower((string) $scheme);
            if ($scheme !== 'http' && $scheme !== 'https') continue;
            $out[] = $scheme . '://' . $host . rtrim($base, '/');
        }
        return $out;
    }

    /**
     * Turn the spec's advertised servers into absolute base URLs.
     *
     * A server url is very often RELATIVE — the real Swagger Petstore 3 spec
     * advertises just "/api/v3", meaning "relative to wherever you fetched me
     * from". Handed straight to a connection that is useless, so it is resolved
     * against the spec's own URL. Returns [] when there is nothing to resolve
     * against, rather than inventing a host.
     */
    public static function absoluteServers(array $servers, string $specUrl): array {
        $out = [];
        foreach ($servers as $s) {
            $s = trim((string) $s);
            if ($s === '') continue;
            if (preg_match('#^https?://#i', $s)) { $out[] = rtrim($s, '/'); continue; }

            $p = parse_url($specUrl);
            if (empty($p['scheme']) || empty($p['host'])) continue;
            $origin = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
            $out[] = rtrim($origin . '/' . ltrim($s, '/'), '/');
        }
        return array_values(array_unique($out));
    }

    /** Normalize a parameter list, following local $ref pointers. */
    private static function params($params, array $doc): array {
        $out = [];
        foreach ((array) $params as $p) {
            if (!is_array($p)) continue;
            if (isset($p['$ref'])) {
                $p = self::deref((string) $p['$ref'], $doc);
                if (!is_array($p)) continue;
            }
            $name = trim((string) ($p['name'] ?? ''));
            if ($name === '') continue;
            $out[] = [
                'name'     => $name,
                'in'       => (string) ($p['in'] ?? 'query'),
                'required' => (bool) ($p['required'] ?? false),
                // OpenAPI 3 nests the type in a schema; Swagger 2 puts it inline.
                'type'     => (string) ($p['schema']['type'] ?? $p['type'] ?? 'string'),
            ];
        }
        return $out;
    }

    /**
     * Resolve a LOCAL $ref ("#/components/parameters/PageSize") only.
     *
     * A remote ref would mean fetching a URL chosen by the document — the same
     * server-side request forgery the REST connector guards against, but with no
     * user in the loop at all. Unresolvable refs simply drop the parameter, which
     * costs a hint in the UI and nothing more.
     */
    private static function deref(string $ref, array $doc) {
        if (!str_starts_with($ref, '#/')) return null;
        $cur = $doc;
        foreach (explode('/', substr($ref, 2)) as $seg) {
            $seg = str_replace(['~1', '~0'], ['/', '~'], $seg);
            if (!is_array($cur) || !array_key_exists($seg, $cur)) return null;
            $cur = $cur[$seg];
        }
        return $cur;
    }

    /** A readable handle for an operation that declared no operationId. */
    private static function synthesizeId(string $method, string $path): string {
        $slug = trim(preg_replace('/[^A-Za-z0-9]+/', '_', $path), '_');
        return strtolower($method) . '_' . strtolower($slug === '' ? 'root' : $slug);
    }

    /**
     * Fill an operation's path template from supplied values: /pets/{petId} + [petId=7]
     * becomes /pets/7. Query parameters are returned separately for the caller to
     * append, so a value never lands in the wrong part of the URL.
     *
     * @return array [path, query[]]
     * @throws \Exception when a required path placeholder has no value — a missing
     *                    one would otherwise be sent literally as "{petId}".
     */
    public static function fill(array $op, array $values): array {
        $path = (string) $op['path'];

        foreach ($op['params'] as $p) {
            if (($p['in'] ?? '') !== 'path') continue;
            $name = $p['name'];
            if (!array_key_exists($name, $values) || $values[$name] === '') {
                throw new \Exception("Operation '{$op['id']}' needs a value for the path parameter '{$name}'.");
            }
            $path = str_replace('{' . $name . '}', rawurlencode((string) $values[$name]), $path);
        }

        if (preg_match('/\{([^}]+)\}/', $path, $m)) {
            throw new \Exception("Operation '{$op['id']}' has an unfilled path placeholder '{$m[1]}'.");
        }

        $query = [];
        foreach ($op['params'] as $p) {
            if (($p['in'] ?? '') !== 'query') continue;
            $name = $p['name'];
            if (array_key_exists($name, $values) && $values[$name] !== '') {
                $query[$name] = $values[$name];
            } elseif (!empty($p['required'])) {
                throw new \Exception("Operation '{$op['id']}' needs a value for the required parameter '{$name}'.");
            }
        }

        return [$path, $query];
    }
}
