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
        // status and verify only READ — they spend nothing, so they sit at MEMBER with the
        // rest of the reads. deploy and refresh spend hypervisor capacity.
        return in_array($op, ['status', 'verify'], true) ? LEVELS['MEMBER'] : LEVELS['ADMIN'];
    }

    /** Handshake: is there a hypervisor to talk to, and does this tenant exist on it? */
    public function verify(object $inst, array $config): array {
        $cfg = \app\ProxmoxService::config();
        if ($cfg['host'] === '' || $cfg['tokenid'] === '' || $cfg['secret'] === '') {
            return ['ok' => false, 'message' => 'No hypervisor credentials on this control plane.'];
        }
        $pve = \app\ProxmoxService::fromConfig();
        if (!$pve || $pve->node() === '') return ['ok' => false, 'message' => 'The hypervisor is not answering.'];

        $s = ProxmoxDeploy::status($inst);
        if (empty($s['deployed'])) {
            // Not an error: nothing is wrong, the container simply has not been stood up.
            return ['ok' => true, 'message' => 'Ready to deploy.',
                    'detail' => ['Hypervisor reachable', 'No container yet — publishing will create one']];
        }
        return ['ok' => true, 'message' => 'Connection works.', 'detail' => array_filter([
            'Container ' . $s['vmid'] . ' is ' . $s['status'] . ' at ' . $s['ip'],
            $s['domain'] ? 'Serving ' . $s['domain'] : '',
            $s['certExpires'] ? 'Certificate valid until ' . $s['certExpires'] : '',
        ])];
    }

    /** Max hostnames one container may answer on: the primary plus MAX_ALIASES. */
    public const MAX_DOMAINS = 5;
    public const MAX_ALIASES = self::MAX_DOMAINS - 1;

    /**
     * The host a customer's CNAME must point AT.
     *
     * Read from core's url, never from app.baseurl. This class lives in core's lib but is
     * loaded by the publisher SIDECAR, where app.baseurl is publisher.tiknix.com — telling
     * someone to CNAME at the publisher would send their traffic to a host that proxies
     * nothing. Same trap that pointed workspace .mcp.json at the wrong origin.
     */
    public static function cnameTarget(): string {
        $u = (string) (\Flight::get('sidecar.core_url') ?: \Flight::get('app.baseurl') ?: 'https://tiknix.com');
        return (string) (parse_url($u, PHP_URL_HOST) ?: 'tiknix.com');
    }

    public static function fields(): array {
        $target = self::cnameTarget();
        return [
            ['name' => 'domain', 'label' => 'Domain', 'type' => 'host', 'placeholder' => 'app.example.com',
             'help' => 'Point a CNAME at ' . $target . ' first — the certificate is issued for this domain. '
                     . 'This one is canonical: it becomes the site\'s own base URL.'],
            // Extra hostnames the SAME container answers on. They get their own proxy entry
            // and their own certificate, but they do not change the site's base URL —
            // exactly one name has to be canonical or generated links contradict each other.
            ['name' => 'aliases', 'label' => 'Also answer on', 'type' => 'hostlist',
             'max' => self::MAX_ALIASES, 'placeholder' => "www.example.com\nexample.com",
             'help' => 'Optional. One per line, up to ' . self::MAX_ALIASES . ' more (' . self::MAX_DOMAINS
                     . ' total). Each needs its own CNAME to ' . $target
                     . ' and gets its own certificate. Links the site generates still use the domain above.'],
        ];
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
        // Extra hostnames travel with every deploy AND every refresh, so adding one is
        // just publishing again — there is no separate "add a domain" action to forget.
        $out['aliases'] = self::normalizeAliases($config['aliases'] ?? null, (string) ($config['domain'] ?? ''));
        return $out;
    }

    /**
     * Clean a submitted alias list: lowercase, de-duplicated, never the primary, capped.
     *
     * Capped HERE as well as in the form because the form is not the only way in — a
     * pipeline step carries this config too, and a cap that only exists in the UI is a
     * suggestion. Each extra name costs a certificate, and Let's Encrypt rate-limits
     * duplicates, so an unbounded list would fail slowly and confusingly rather than
     * being refused.
     */
    public static function normalizeAliases($raw, string $primary = ''): array {
        if (is_string($raw)) $raw = preg_split('/[\s,]+/', $raw) ?: [];
        if (!is_array($raw)) return [];
        $primary = strtolower(trim($primary));
        $out = [];
        foreach ($raw as $h) {
            $h = strtolower(trim((string) $h));
            if ($h === '' || $h === $primary) continue;
            if (!preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $h)) continue;
            $out[$h] = true;                       // key = dedupe
            if (count($out) >= self::MAX_ALIASES) break;
        }
        return array_keys($out);
    }
}
