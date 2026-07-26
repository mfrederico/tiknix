<?php
/**
 * TiknixHostedDriver — "Tiknix Hosted": the instance runs in its own container on our
 * hypervisor, fronted by capricorn, with TLS issued for its domain.
 *
 * This is the only driver that owns the whole stack, so it is the only one that can
 * offer a domain, a certificate and a settings refresh from one button. It is a thin
 * wrapper: lib/ProxmoxDeploy.php already implements create / status / refresh, and this
 * class exists to put that behind the same interface as git, ssh and rsync targets.
 */
namespace app\Publish;

use app\ProxmoxDeploy;

class TiknixHostedDriver implements PublishDriver {

    public static function key(): string   { return 'tiknix-hosted'; }
    public static function label(): string { return 'Tiknix Hosted'; }

    public static function blurb(): string {
        return 'Runs in its own container with its own database and resources, on a domain you choose. We handle the proxy and the certificate.';
    }

    public static function capabilities(): array {
        return [
            'domain'   => true,   // binds a hostname and writes capricorn's proxy file
            'tls'      => true,   // issues and renews the certificate
            'refresh'  => true,   // rewrites the boot command and restarts, data intact
            'recreate' => true,   // rebuilds the container (destructive — server guards it)
            'sshKey'   => false,
        ];
    }

    /**
     * Mirrors the Connections hub exactly: connections::lxcstatus is MEMBER, lxcdeploy
     * and lxcrefresh are ADMIN. Standing up or reshaping a container spends hypervisor
     * capacity, so the door must not be a cheaper route to it than the button.
     */
    public static function minLevel(string $op): int {
        return $op === 'status' ? LEVELS['MEMBER'] : LEVELS['ADMIN'];
    }

    /**
     * Stand the container up, or bring an existing one in line — one meaning of "publish".
     *
     * ProxmoxDeploy::deploy refuses outright when the container already exists, because
     * replacing it purges the data volumes. That refusal is a guard, not an answer: a
     * pipeline that says "publish" a second time wants the live target to match the
     * settings, which is precisely refresh. So do that, and leave `recreate` as the only
     * way to ask for the destructive path.
     */
    public function deploy(object $inst, array $config, array $opts = []): array {
        if (empty($opts['recreate'])) {
            $state = ProxmoxDeploy::status($inst);
            if (!empty($state['deployed'])) return $this->refresh($inst, $config, $opts);
        }

        $r = ProxmoxDeploy::deploy((string) $inst->slug, (string) ($config['domain'] ?? ''), [
            'recreate' => !empty($opts['recreate']),
            'force'    => !empty($opts['force']),
            // Certificates are the point of a hosted target, so issue by default. The
            // call is a no-op when a valid cert already exists, which matters because
            // Let's Encrypt caps duplicates at 5/week.
            'cert'     => $opts['cert'] ?? true,
        ] + $this->passthrough($config));

        return empty($r['ok'])
            ? ['ok' => false, 'error' => (string) ($r['error'] ?? 'Deploy failed'), 'steps' => $r['steps'] ?? []]
            : ['ok' => true, 'steps' => $r['steps'] ?? []];
    }

    public function status(object $inst, array $config): array {
        return ProxmoxDeploy::status($inst);
    }

    public function refresh(object $inst, array $config, array $opts = []): array {
        $r = ProxmoxDeploy::refreshBoot((string) $inst->slug, (string) ($config['domain'] ?? ''), $this->passthrough($config));
        return empty($r['ok'])
            ? ['ok' => false, 'error' => (string) ($r['error'] ?? 'Refresh failed')]
            : ['ok' => true, 'steps' => $r['steps'] ?? []];
    }

    /**
     * Per-target overrides an operator may set on the connection. Only keys that are
     * safe to vary per tenant — sizing and placement, never credentials.
     */
    private function passthrough(array $config): array {
        $out = [];
        foreach (['cores', 'memory', 'rootfsGb', 'ip', 'gw', 'bridge', 'nameserver', 'image'] as $k) {
            if (isset($config[$k]) && $config[$k] !== '') $out[$k] = $config[$k];
        }
        return $out;
    }
}
