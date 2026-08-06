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

    /** Why this manifest cannot be used, or '' when it is fine. */
    private static function validate(array $m): string {
        $style = strtolower((string) ($m['auth']['style'] ?? 'bearer'));
        $valid = ['bearer', 'header', 'basic', 'query', 'none'];
        if (!in_array($style, $valid, true)) {
            return "auth.style '{$style}' is not one of " . implode(', ', $valid) . '.';
        }
        if (($style === 'header' || $style === 'query') && trim((string) ($m['auth']['name'] ?? '')) === '') {
            return "auth.style '{$style}' needs auth.name (the header or parameter name).";
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
