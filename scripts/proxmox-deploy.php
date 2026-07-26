<?php
/**
 * proxmox-deploy.php — stand a tenant instance up as its own container on the Proxmox
 * node and point capricorn at it.
 *
 *   php scripts/proxmox-deploy.php --slug=test1-ec34d9
 *   php scripts/proxmox-deploy.php --slug=test1-ec34d9 --domain=tiknix.run.sh
 *   php scripts/proxmox-deploy.php --slug=test1-ec34d9 --recreate   # replace its CT
 *
 * Options:
 *   --slug=S      instance slug (required)
 *   --domain=D    public hostname (default: <slug>.<core host>)
 *   --vmid=N      pin a container id (default: remembered, else /cluster/nextid)
 *   --ip=CIDR     static address (default: 10.10.10.<vmid>/24, derived so tenants
 *                 cannot collide). Always static — DHCP cannot work for an OCI
 *                 container, which ships no dhclient for PVE to run inside it.
 *   --gw=IP       gateway (default: 10.10.10.1, the node's vmbr1 address)
 *   --bridge=B    bridge (default: vmbr1, internal + NAT'd on the node)
 *   --recreate    destroy this instance's existing container first
 *
 * REQUIRES on the node: an internal bridge with NAT, so tenants get outbound (composer,
 * Stripe, Shopify, Mailgun) without being internet-facing:
 *
 *   auto vmbr1
 *   iface vmbr1 inet static
 *       address 10.10.10.1/24
 *       bridge-ports none
 *       bridge-stp off
 *       bridge-fd 0
 *       post-up   iptables -t nat -A POSTROUTING -s 10.10.10.0/24 -o vmbr0 -j MASQUERADE
 *       post-down iptables -t nat -D POSTROUTING -s 10.10.10.0/24 -o vmbr0 -j MASQUERADE
 *
 * plus net.ipv4.ip_forward=1. capricorn needs no extra route: the node is also core's
 * gateway, so 10.10.10.0/24 is reachable over core's existing default route.
 *
 * Publishing still needs DNS: point the domain at capricorn with a CNAME. The container
 * serves plain HTTP and is only ever reached through capricorn, so TLS stays in one place.
 */

if (php_sapi_name() !== 'cli') { http_response_code(403); exit("cli only\n"); }

require_once __DIR__ . '/../bootstrap.php';

new app\Bootstrap('conf/config.ini');

$opt  = getopt('', ['slug:', 'domain::', 'vmid::', 'ip::', 'gw::', 'bridge::', 'ignore-platform-reqs', 'cert', 'recreate', 'force']);
$slug = (string) ($opt['slug'] ?? '');
if ($slug === '') exit("usage: php scripts/proxmox-deploy.php --slug=<instance> [--domain=D] [--vmid=N] [--ip=CIDR] [--recreate]\n");

$params = [];
if (isset($opt['vmid'])) $params['vmid']     = (int) $opt['vmid'];
if (isset($opt['ip']))   $params['ip']       = (string) $opt['ip'];
if (isset($opt['gw']))   $params['gw']       = (string) $opt['gw'];
if (isset($opt['bridge'])) $params['bridge'] = (string) $opt['bridge'];
if (isset($opt['ignore-platform-reqs'])) $params['ignorePlatformReqs'] = true;
if (isset($opt['cert'])) $params['cert'] = true;
if (isset($opt['recreate'])) $params['recreate'] = true;
if (isset($opt['force']))    $params['force']    = true;

echo "deploying {$slug}…\n";
$r = app\ProxmoxDeploy::deploy($slug, (string) ($opt['domain'] ?? ''), $params);

foreach ($r['steps'] ?? [] as $s) echo "  · {$s}\n";

if (!$r['ok']) {
    echo "\nFAILED: {$r['error']}\n";
    if (!empty($r['vmid'])) echo "container {$r['vmid']} was created — inspect it before re-running, or pass --recreate\n";
    exit(1);
}

echo "\n{$slug} is up:\n";
echo "  container  {$r['vmid']}\n";
echo "  address    {$r['ip']}:" . app\ProxmoxDeploy::PROXY_PORT . "\n";
echo "  domain     https://{$r['domain']}\n";
echo "\nfirst boot clones the repo and runs composer install, so give it a minute before\n";
echo "the domain answers. Watch progress with: pct enter {$r['vmid']} (on the node)\n";
