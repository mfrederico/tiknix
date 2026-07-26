<?php
/**
 * PublishRegistry — the available publish targets, and how a connection row resolves
 * to the driver that runs it.
 *
 * A publish connection stores its driver key in metadata_json.driver. Everything else
 * about the target (domain, host, path, sizing) is config in the same blob, so adding a
 * driver never needs a schema change.
 *
 * Publish targets are NOT accounts: there is no external identity and usually no OAuth
 * token, so a connection row here carries an empty access_token and lives entirely in
 * its metadata. That is why they are their own category rather than another OAuth card.
 */
namespace app\Publish;

class PublishRegistry {

    /** driver key => class. Order is the order shown in the UI. */
    private const DRIVERS = [
        'tiknix-hosted' => TiknixHostedDriver::class,
        'github-pr'     => GithubPrDriver::class,
    ];

    /**
     * Drivers that are usable on THIS control plane. A driver whose backing service is
     * not configured is listed but marked unavailable, so the UI can explain why rather
     * than silently omitting an option the operator expects to see.
     *
     * @return array<int,array{key:string,label:string,blurb:string,capabilities:array,available:bool,reason:string}>
     */
    public static function all(): array {
        $out = [];
        foreach (self::DRIVERS as $key => $class) {
            [$available, $reason] = self::availability($key);
            $out[] = [
                'key'          => $class::key(),
                'label'        => $class::label(),
                'blurb'        => $class::blurb(),
                'capabilities' => $class::capabilities(),
                'available'    => $available,
                'reason'       => $reason,
            ];
        }
        return $out;
    }

    /**
     * Hosting targets only — the ones that answer "where does this instance run", and so
     * the only ones the Connections hub's Hosting section should offer. A repository
     * target (github-pr) belongs to the Publisher, not to the hosting card; listing it
     * here would read as "your project runs on a pull request".
     *
     * @return array<int,array<string,mixed>>
     */
    public static function hosting(): array {
        return array_values(array_filter(self::all(), fn($d) => empty($d['capabilities']['code'])));
    }

    /**
     * Repository targets — the ones that answer "how does a change reach my code".
     *
     * The two kinds are ORTHOGONAL, not alternatives: a project can perfectly well open a
     * pull request on its repo AND run in a container, because those answer different
     * questions. Anything presenting targets as one-of-N is conflating them.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function repository(): array {
        return array_values(array_filter(self::all(), fn($d) => !empty($d['capabilities']['code'])));
    }

    /** @return PublishDriver|null */
    public static function driver(string $key): ?PublishDriver {
        $class = self::DRIVERS[$key] ?? null;
        return $class ? new $class() : null;
    }

    /** Resolve the driver for a connection row, or null if it names an unknown one. */
    public static function forConnection(object $conn): ?PublishDriver {
        $meta = json_decode((string) ($conn->metadataJson ?: '{}'), true) ?: [];
        return self::driver((string) ($meta['driver'] ?? ''));
    }

    /** @return array{0:bool,1:string} */
    private static function availability(string $key): array {
        if ($key === 'tiknix-hosted') {
            $cfg = \app\ProxmoxService::config();
            return ($cfg['host'] !== '' && $cfg['tokenid'] !== '' && $cfg['secret'] !== '')
                ? [true, '']
                : [false, 'No hypervisor credentials on this control plane (conf/proxmox.ini).'];
        }
        return [true, ''];
    }
}
