<?php
/**
 * TenantShare — lending one of Tiknix's own connections to an app in its container.
 *
 * An app normally connects its own services (Connections). Some have none of their own yet and
 * used Tiknix's on the host — partsdna and Serenity sent mail through Tiknix's Mailgun — and the
 * operator may lend it on purpose (the owner's call, 2026-10-01: credential sharing). A share:
 *
 *   share()    reads the connection core itself uses for that service (the one bound to the
 *              role named, e.g. core.mail), decrypts it HERE, and pipes it to the app's own
 *              `clitool --connection-import` over SSH stdin — never argv, never a file, never
 *              printed. The app stores it in its own encrypted store, marked shared_from=tiknix,
 *              and binds it. Only what the service needs to SEND: a webhook signing secret stays
 *              on core (inbound mail for that domain is core's).
 *   unshare()  the app's `--connection-unshare=TYPE`: the shared rows and their bindings go;
 *              the app's own connections are never touched.
 *
 * Every share and unshare is recorded on the instance (lent_connections) and logged, so who
 * holds a Tiknix credential is a question with an answer.
 */

namespace app;

class TenantShare {

    /** What can be lent, and the role core reads it from: connector type => [concept, role]. */
    public const SHAREABLE = ['mailgun' => ['core', 'mail']];

    public static function share(object $inst, string $type, array $bindTo = []): array {
        if (!isset(self::SHAREABLE[$type])) return ['ok' => false, 'error' => "{$type} cannot be shared — shareable: " . implode(', ', array_keys(self::SHAREABLE))];
        [$concept, $role] = self::SHAREABLE[$type];
        try {
            $conn = ConnectionBindings::for($concept, $role);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => "core has no {$type} connection bound to {$concept}.{$role}: " . $e->getMessage()];
        }
        if ((string) $conn->connectorType !== $type) return ['ok' => false, 'error' => "core's {$concept}.{$role} is a {$conn->connectorType} connection, not {$type}"];
        $token = ConnectionStore::ownToken($conn);
        if ($token === '') return ['ok' => false, 'error' => "core's {$type} connection #{$conn->id} has no usable secret — fix it on core first"];
        $doc = [
            'type' => $type, 'env' => (string) ($conn->environment ?: 'production'),
            'alias' => 'Tiknix ' . ConnectionStore::alias($conn) . ' (shared)',
            'shared_from' => 'tiknix',
            'payload' => [
                'external_eid' => (string) $conn->externalEid, 'external_name' => (string) $conn->externalName,
                'external_url' => (string) $conn->externalUrl, 'token_type' => (string) $conn->tokenType,
                'scopes' => (string) $conn->scopes, 'auth_type' => (string) ($conn->authType ?: 'api_key'),
                'access_token' => $token, 'metadata' => (string) $conn->metadataJson,
            ],
            'bind' => array_values($bindTo ?: ["{$concept}.{$role}"]),
        ];
        $json = json_encode($doc);
        sodium_memzero($token);
        [$c, $o] = TenantHost::ssh($inst, 'app', 'cd /srv/app && php scripts/clitool.php --connection-import', $json, 120);
        sodium_memzero($json);
        if ($c !== 0) return ['ok' => false, 'error' => "the app refused the import ({$c}): " . trim($o)];
        self::record($inst, $type, 'shared');
        return ['ok' => true, 'steps' => array_values(array_filter(array_map('trim', explode("\n", $o)), fn($l) => $l !== '' && !str_contains($l, 'setlocale')))];
    }

    public static function unshare(object $inst, string $type): array {
        [$c, $o] = TenantHost::ssh($inst, 'app', 'cd /srv/app && php scripts/clitool.php --connection-unshare=' . escapeshellarg($type), null, 120);
        if ($c !== 0) return ['ok' => false, 'error' => "the app refused ({$c}): " . trim($o)];
        self::record($inst, $type, 'unshared');
        return ['ok' => true, 'steps' => array_values(array_filter(array_map('trim', explode("\n", $o)), fn($l) => $l !== '' && !str_contains($l, 'setlocale')))];
    }

    /** instance.lent_connections: {type: {state, at}}. */
    private static function record(object $inst, string $type, string $state): void {
        $all = json_decode((string) ($inst->lentConnections ?? ''), true) ?: [];
        $all[$type] = ['state' => $state, 'at' => date('c')];
        $inst->lentConnections = json_encode($all);
        Bean::store($inst);
        \Flight::get('log')->warning("TenantShare: Tiknix's {$type} connection {$state} with {$inst->slug}");
    }
}
