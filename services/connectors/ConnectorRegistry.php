<?php
/**
 * ConnectorRegistry — the one place that knows what connectors exist.
 *
 * Two kinds, discovered the same way:
 *
 *   CODE      *Connector.php in this directory, indexed by key(). For providers
 *             whose behaviour is genuinely bespoke — Shopify's GraphQL, Stripe's
 *             form encoding, QuickBooks' realmId and hourly refresh.
 *
 *   MANIFEST  connectors/*.json at the app root. A base URL, an auth style and
 *             some labels, interpreted by ManifestConnector. Adding one is data:
 *             no PHP, and no edit to any shared file, so it cannot conflict with
 *             anything in any instance.
 *
 * A CODE connector wins a key collision, so a manifest can be prototyped and later
 * graduate to a class without being renamed or re-connected.
 *
 * Manifests live at the APP ROOT rather than beside this file so an instance can
 * carry its own without touching the connectors shipped by core — the merge that
 * brings a core update leaves connectors/ alone unless core changed the same file.
 */

namespace app\services\connectors;

class ConnectorRegistry {

    /** @var array<string,ConnectorInterface>|null */
    private static ?array $map = null;

    /** Problems found while loading manifests, for the hub to surface. */
    private static array $errors = [];

    /** Where manifests live: <app root>/connectors. */
    public static function manifestDir(): string {
        return dirname(__DIR__, 2) . '/connectors';
    }

    private static function load(): array {
        if (self::$map !== null) return self::$map;

        $map = [];
        self::$errors = [];

        foreach (glob(__DIR__ . '/*Connector.php') ?: [] as $file) {
            $cls = __NAMESPACE__ . '\\' . basename($file, '.php');
            if (!class_exists($cls)) continue;
            $ref = new \ReflectionClass($cls);
            if ($ref->isAbstract() || !$ref->implementsInterface(ConnectorInterface::class)) continue;

            // ManifestConnector is a HOST for manifests, not a connector: it needs a
            // manifest to say what it is. Anything else needing constructor
            // arguments is skipped for the same reason rather than fataling the
            // whole registry — one bad file must not take every connector down.
            $ctor = $ref->getConstructor();
            if ($ctor && $ctor->getNumberOfRequiredParameters() > 0) continue;

            $inst = new $cls();
            $map[$inst->key()] = $inst;
        }

        foreach (self::manifests() as $key => $manifest) {
            // Code wins. A manifest that shadows a class is almost certainly a
            // leftover from graduating one, and silently overriding real behaviour
            // with a declarative approximation is the worse outcome.
            if (isset($map[$key])) {
                self::$errors[] = "connectors/{$key}.json is ignored — a {$key} connector class already exists.";
                continue;
            }
            $map[$key] = new ManifestConnector($manifest);
        }

        ksort($map);
        return self::$map = $map;
    }

    /**
     * Read and validate every manifest. A broken one is REPORTED and skipped, never
     * silently dropped: a connector that simply fails to appear is indistinguishable
     * from one nobody added, and that is a long afternoon.
     *
     * @return array<string,array> key => manifest
     */
    private static function manifests(): array {
        $out = [];
        foreach (glob(self::manifestDir() . '/*.json') ?: [] as $file) {
            $name = basename($file, '.json');
            $raw  = @file_get_contents($file);
            if ($raw === false) { self::$errors[] = "connectors/{$name}.json could not be read."; continue; }

            $m = json_decode($raw, true);
            if (!is_array($m)) {
                self::$errors[] = "connectors/{$name}.json is not valid JSON: " . json_last_error_msg();
                continue;
            }

            // The filename is the key unless the manifest says otherwise, and the two
            // must agree — a mismatch means the card you edit is not the card you see.
            $key = strtolower(trim((string) ($m['key'] ?? $name)));
            if ($key !== $name) {
                self::$errors[] = "connectors/{$name}.json declares key '{$key}' — rename the file to {$key}.json.";
                continue;
            }
            if (!preg_match('/^[a-z][a-z0-9_]*$/', $key)) {
                self::$errors[] = "connectors/{$name}.json has an unusable key '{$key}' (lowercase letters, digits and underscore).";
                continue;
            }

            if ($err = self::validate($m)) { self::$errors[] = "connectors/{$name}.json: {$err}"; continue; }

            $m['key'] = $key;
            $out[$key] = $m;
        }
        return $out;
    }

    /** The manifest's key as the catalog and the lock spell it, or '' when the file has none. */
    public const KEY_RE = '/^[a-z][a-z0-9_]*$/D';

    /**
     * What a manifest must look like to be PUBLISHED (CONNECTOR-CATALOG-PLAN.md §3.2, §5):
     * everything validate() checks, plus a version, a key that matches the file name, no
     * credential literal, no server path. Errors, in words; empty when it may go out.
     *
     * @return string[]
     */
    public static function lint(array $m, string $filename): array {
        $errors = [];
        $name = basename($filename, '.json');
        $key = strtolower(trim((string) ($m['key'] ?? '')));
        if ($key === '') $errors[] = '"key" is required — it is the connector type every connection and binding names.';
        elseif (!preg_match(self::KEY_RE, $key)) $errors[] = "key '{$key}' is unusable (lowercase letters, digits and underscore).";
        elseif ($key !== $name) $errors[] = "key '{$key}' does not match the file name {$name}.json.";
        $version = (string) ($m['version'] ?? '');
        if (!preg_match('/^\d+\.\d+\.\d+$/D', $version)) $errors[] = '"version" must be MAJOR.MINOR.PATCH — the catalog refuses to change a published version.';
        if (($why = self::validate($m)) !== '') $errors[] = $why;
        foreach (['docs_url', 'account_url'] as $u) {
            if (($m[$u] ?? '') !== '' && !preg_match('#^https://#i', (string) $m[$u])) $errors[] = "{$u} must be an https URL.";
        }
        foreach ((array) ($m['roles'] ?? []) as $r) {
            if (!is_string($r) || !preg_match('/^[a-z][a-z0-9_]*$/D', $r)) $errors[] = 'roles must be lowercase identifiers (mail, payments, search).';
        }
        foreach ((array) ($m['fields'] ?? []) as $i => $f) {
            if (!is_array($f) || !preg_match('/^[a-z][a-z0-9_]*$/D', (string) ($f['name'] ?? ''))) { $errors[] = "fields[{$i}] needs a lowercase \"name\"."; continue; }
            if (in_array($f['name'], ['key', 'type', 'env', 'id', 'base_url', 'auth', 'auth_name', 'username'], true)) $errors[] = "fields[{$i}].name '{$f['name']}' collides with a connect-form field.";
        }
        foreach ((array) ($m['endpoints'] ?? []) as $i => $e) {
            if (!is_array($e) || !isset($e['method'], $e['path']) || !str_starts_with((string) $e['path'], '/')) $errors[] = "endpoints[{$i}] needs a method and a path starting with /.";
        }
        // A manifest is a DEFINITION. A credential in it would be published to every install.
        $walk = function ($v, string $path) use (&$walk, &$errors) {
            if (!is_array($v)) return;
            foreach ($v as $k => $child) {
                $p = $path === '' ? (string) $k : "{$path}.{$k}";
                if (is_string($child)) {
                    if (preg_match('/^(client_secret|client_id|api_key|access_token|refresh_token|password|secret|token|signing_key)$/D', (string) $k) && trim($child) !== '') {
                        $errors[] = "{$p} holds a value — credentials live in Connections, never in a manifest.";
                    }
                    if (preg_match('#(^|[\s"\'(])/(var|home|etc|srv|opt|tmp)/#', $child)) $errors[] = "{$p} names a server path ({$child}).";
                    if (preg_match('/^(sk|rk|pk)_(live|test)_[A-Za-z0-9]{8,}|^key-[0-9a-f]{20,}|^xox[bp]-|^ghp_[A-Za-z0-9]{20,}/', trim($child))) $errors[] = "{$p} looks like a live credential.";
                }
                $walk($child, $p);
            }
        };
        $walk($m, '');
        return $errors;
    }

    /** Why this manifest cannot be used, or '' when it is fine. */
    public static function validate(array $m): string {
        $oauth = $m['oauth'] ?? null;

        if (is_array($oauth)) {
            // Half an OAuth block is worse than none: the card would offer a Connect
            // button that goes nowhere, or accept a callback it cannot complete.
            foreach (['authorize_url', 'token_url'] as $req) {
                if (trim((string) ($oauth[$req] ?? '')) === '') {
                    return "oauth.{$req} is required when an oauth block is present.";
                }
                if (!preg_match('#^https://#i', (string) $oauth[$req])) {
                    return "oauth.{$req} must be an https URL — an authorization code must not cross the network in the clear.";
                }
            }
            $ca = strtolower((string) ($oauth['client_auth'] ?? 'body'));
            if (!in_array($ca, ['body', 'basic'], true)) {
                return "oauth.client_auth '{$ca}' is not 'body' or 'basic'.";
            }
        } else {
            $style = strtolower((string) ($m['auth']['style'] ?? 'bearer'));
            $valid = ['bearer', 'header', 'basic', 'query', 'none'];
            if (!in_array($style, $valid, true)) {
                return "auth.style '{$style}' is not one of " . implode(', ', $valid) . '.';
            }
            if (($style === 'header' || $style === 'query') && trim((string) ($m['auth']['name'] ?? '')) === '') {
                return "auth.style '{$style}' needs auth.name (the header or parameter name).";
            }
        }

        $base = trim((string) ($m['base_url'] ?? ''));
        if ($base === '' && empty($m['ask_base_url'])) {
            return 'base_url is required (or set ask_base_url to collect it per connection).';
        }
        if ($base !== '' && !preg_match('#^https?://#i', $base)) {
            return 'base_url must start with http:// or https://.';
        }
        if (trim((string) ($m['label'] ?? '')) === '') {
            return 'label is required — it is what the user sees on the card.';
        }
        return '';
    }

    /** Manifest problems found at load time. Empty when everything read cleanly. */
    public static function errors(): array {
        self::load();
        return self::$errors;
    }

    /**
     * How this install has a connector: 'code' (a class here), 'manifest' (connectors/<key>.json
     * at the app root) or '' (not at all). The catalog installs manifests only, and never
     * over a class.
     */
    public static function kind(string $key): string {
        $c = self::get($key);
        if ($c === null) return '';
        return $c instanceof ManifestConnector ? 'manifest' : 'code';
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

    /** Forget the cached map — for tests and for a hub that just wrote a manifest. */
    public static function flush(): void {
        self::$map = null;
        self::$errors = [];
    }
}
