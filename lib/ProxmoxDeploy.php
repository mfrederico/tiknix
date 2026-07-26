<?php
/**
 * ProxmoxDeploy — stand a tenant instance up as its own OCI-backed LXC on the Proxmox
 * node, fronted by capricorn. The counterpart to HostedDeploy (which lands code in
 * /hosted on THIS host); this one gives a tenant a whole container.
 *
 * SHAPE, AND WHY IT IS THIS SHAPE (all measured — see scripts/proxmox-probe.php):
 *
 *  - The container is created from the generic tiknix-base OCI image. Per-tenant CODE is
 *    NOT baked into an image: PVE refuses to overwrite an existing template, so an
 *    image-per-tenant would make every code push a destroy-and-recreate.
 *  - Code arrives by git, pulled BY the container FROM core's smart-HTTP endpoint
 *    (lib/GitHttp.php). It cannot be pushed in: bind mounts are root@pam-only and
 *    unreachable by any API token, and PVE has no exec API — nothing outside can write
 *    into a container or run a command in it.
 *  - Therefore the container bootstraps itself. The OCI import turns the image's
 *    ENTRYPOINT into a settable config key, so the boot command is injected there — and
 *    it carries its OWN environment as export lines, because the `env` and `nameserver`
 *    config keys are accepted by the API, read back correctly, and are then never
 *    applied to init. Nothing about a container's environment can be trusted to arrive
 *    from outside; the boot command writes /etc/resolv.conf and /etc/hosts itself.
 *  - Only DATA gets storage-backed mount points. conf/ deliberately does not, because a
 *    volume would shadow the committed conf/*.example.ini the entrypoint seeds from;
 *    config.ini is regenerated each boot and APP_KEY is passed in so encrypted data
 *    still survives a recreate.
 *  - Apache is exec'd IMMEDIATELY and the code sync runs behind it. The first sync takes
 *    minutes (clone + composer install); doing it first left the container listening on
 *    nothing, which is indistinguishable from a dead one and makes the boot log —
 *    served at /sync.log — unreachable exactly when it is needed.
 *
 * Publishing is capricorn's job: the container serves plain HTTP on port 80 and never
 * faces the internet, so TLS stays centralised on one host instead of becoming a
 * per-tenant renewal problem.
 */
namespace app;

use RedBeanPHP\R;

class ProxmoxDeploy {

    /**
     * Base runtime image. Versioned tags are mandatory, not stylistic: PVE refuses to
     * overwrite an existing template, so a tag can never be updated in place — publish
     * a new tag and point here. Override with [proxmox] image= in conf/proxmox.ini.
     */
    const IMAGE = 'ghcr.io/mfrederico/tiknix-base:8.3';

    /** The configured image, falling back to IMAGE. */
    public static function image(): string {
        $cfg = ProxmoxService::config();
        return trim((string) ($cfg['image'] ?? '')) ?: self::IMAGE;
    }

    /**
     * Storage is thin-provisioned, so this is a CEILING, not a reservation — a serving
     * tenant writes ~600 MB of it, and shrinking the number reclaims nothing. Its real
     * job is stopping one tenant from filling the shared pool. Headroom covers 30 days
     * of rotated logs, composer's cache, and a larger base image; going much below this
     * risks a tenant wedging mid-deploy, which is far more expensive than unused quota.
     */
    const ROOTFS_GB    = 4;
    const DATA_GB      = 2;
    const CORES        = 2;
    /**
     * A serving container idles around 24 MB, so this is sized for the ONE thing that
     * spikes: `composer install` on first boot, which resolves dependencies in-container
     * and can want several hundred MB. The swap is a cushion for exactly that — with
     * swap=0 an overshoot is a hard OOM kill, and the tenant then comes up with no
     * vendor/ and no obvious reason why. Swap costs disk, not reserved memory.
     */
    const MEMORY_MB    = 512;
    const SWAP_MB      = 512;
    /** capricorn proxies here. Port 80 — the container serves apache directly; the
     *  8080 rewrite only happens inside docker/entrypoint.sh, which no longer runs
     *  as the container's init. */
    const PROXY_PORT   = 80;
    /** Guest resolver — see the nameserver note in deploy(). */
    const DNS          = '8.8.8.8';

    /**
     * Tenant containers live on an INTERNAL bridge (bridge-ports none) behind NAT on the
     * node, not on the public bridge. Three reasons, in order of importance:
     *  - a tenant is never internet-facing; only capricorn talks to it,
     *  - outbound still works (masquerade), which the app REQUIRES — Stripe, Shopify and
     *    Mailgun are all outbound calls, as is composer on first boot,
     *  - no public address is burned per tenant, and an unrouted public IP fails in the
     *    worst way: on-link traffic works, so it looks healthy until anything off-link
     *    is attempted.
     * capricorn reaches tenants through its default route, because the node is also
     * core's gateway — no second NIC and no static route on the core container.
     */
    const BRIDGE       = 'vmbr1';
    const SUBNET       = '10.10.10.';
    const SUBNET_CIDR  = '24';
    const SUBNET_GW    = '10.10.10.1';
    const BOOT_TIMEOUT = 180;

    /** Where capricorn looks for proxy targets: /var/www/html/.proxy.<sname>. */
    const PROXY_DIR = '/var/www/html';

    /**
     * TLS. capricorn resolves certificates per-SNI in dynamic_ssl.lua, so a newly issued
     * cert is served on the next handshake — no nginx reload, nothing to restart.
     * Issuance is lego DNS-01 via the Spaceship API.
     */
    const CERT_SCRIPT = '/home/ubuntu/capricorn/scripts/runcertbot.sh';
    const CERT_DIR    = '/etc/letsencrypt/lego/certificates';
    /** Reissue only inside this window, so a redeploy never burns rate limit. */
    const CERT_RENEW_DAYS = 30;

    /**
     * Provision (or re-provision) a tenant container for an instance slug.
     * @return array{ok:bool, vmid?:int, ip?:string, domain?:string, steps?:string[], error?:string}
     */
    public static function deploy(string $slug, string $domain = '', array $opts = []): array {
        $steps = [];

        $pve = ProxmoxService::fromConfig();
        if (!$pve) return ['ok' => false, 'error' => 'conf/proxmox.ini is missing or incomplete'];
        $node = $pve->node();
        if ($node === '') return ['ok' => false, 'error' => 'no Proxmox node visible to this token'];

        // The instance must exist, be active, and have a repo core can serve.
        $r = GitHttp::resolve($slug);
        if (!$r['ok']) return ['ok' => false, 'error' => (string) $r['error']];
        $inst   = $r['bean'];
        $branch = 'instance/' . $slug;
        $domain = $domain !== '' ? strtolower(trim($domain)) : self::defaultDomain($slug);
        if (!self::validHost($domain)) return ['ok' => false, 'error' => 'Invalid domain: ' . $domain];

        // The container authenticates to core with a read-only, single-repo capability.
        $token  = GitHttp::deployToken($inst);
        $remote = self::remoteUrl($slug);
        $host   = (string) (parse_url($remote, PHP_URL_HOST) ?: '');

        $img = self::ensureImage($pve, $node, (string) ($opts['image'] ?? self::image()));
        if (!$img['ok']) return ['ok' => false, 'error' => $img['error']];
        $steps[] = $img['step'];

        // One container per instance, remembered on the registry row.
        $vmid = (int) ($opts['vmid'] ?? $inst->ctVmid ?? 0);
        if ($vmid > 0 && $pve->ctExists($node, $vmid)) {
            if (empty($opts['recreate'])) return ['ok' => false, 'error' => 'CT ' . $vmid . ' already exists for ' . $slug . ' (pass recreate to replace it)'];

            // Recreate DESTROYS the data volumes (purge), so refuse once a tenant has
            // real data. Code changes never need this — the in-container puller applies
            // them within a minute — so recreate is only for changing the image or the
            // container's shape, and by then the tenant may own something worth keeping.
            if (empty($opts['force'])) {
                $state = self::dataState($pve, $node, $vmid);
                if ($state['hasData']) {
                    return ['ok' => false, 'error' => 'refusing to recreate CT ' . $vmid . ': ' . $state['why']
                        . '. Recreate purges the database and uploads volumes. Push to '
                        . $branch . ' for code changes; pass force to destroy it anyway.'];
                }
                $steps[] = 'recreate allowed (' . $state['why'] . ')';
            }
            // PVE refuses to destroy a running container, so stop it first and give the
            // stop time to land before the destroy is attempted.
            $pve->stopCt($node, $vmid);
            for ($i = 0; $i < 10 && (string) ($pve->ctStatus($node, $vmid)['status'] ?? '') !== 'stopped'; $i++) sleep(2);
            $d = $pve->destroyCt($node, $vmid, true);
            if (!$d['ok']) return ['ok' => false, 'error' => 'could not destroy CT ' . $vmid . ': ' . $d['exit']];
            $steps[] = 'destroyed existing CT ' . $vmid;
        }
        if ($vmid <= 0) $vmid = $pve->nextId();
        if ($vmid <= 0) return ['ok' => false, 'error' => 'could not allocate a vmid'];

        // Address derived from the container id, so two tenants can never be handed the
        // same one. (Two containers sharing an address is silent: both answer ARP, the
        // bridge learns whichever spoke last, and the symptom is intermittent
        // unreachability rather than an error.) Static is mandatory regardless — DHCP
        // cannot work for an OCI container, which ships no dhclient for PVE to run.
        $bridge = (string) ($opts['bridge'] ?? self::BRIDGE);
        $ip     = trim((string) ($opts['ip'] ?? ''));
        $gw     = trim((string) ($opts['gw'] ?? ''));
        if ($ip === '') $ip = self::SUBNET . ($vmid % 254) . '/' . self::SUBNET_CIDR;
        if ($gw === '') $gw = self::SUBNET_GW;
        if (!str_contains($ip, '/')) return ['ok' => false, 'error' => 'ip must include a prefix, e.g. 10.10.10.100/24'];

        $create = $pve->createCt($node, $vmid, $img['volid'], [
            'hostname'     => self::hostname($slug),
            'cores'        => (int) ($opts['cores']  ?? self::CORES),
            'memory'       => (int) ($opts['memory'] ?? self::MEMORY_MB),
            'swap'         => (int) ($opts['swap'] ?? self::SWAP_MB),
            'rootfs'       => $img['rootfs'] . ':' . (int) ($opts['rootfsGb'] ?? self::ROOTFS_GB),
            // DATA only. conf/ is intentionally absent — see the class comment.
            'mp0'          => $img['rootfs'] . ':' . self::DATA_GB . ',mp=/var/www/html/database',
            'mp1'          => $img['rootfs'] . ':' . self::DATA_GB . ',mp=/var/www/html/public/uploads',
            // STATIC ONLY. ip=dhcp cannot work for an OCI container: PVE runs dhclient
            // INSIDE the guest via lxc-attach, and OCI images do not ship one — the
            // interface silently comes up with no address and every fetch fails.
            'net0'         => 'name=eth0,bridge=' . $bridge . ',ip=' . $ip . ',gw=' . $gw,
            // REQUIRED. PVE does not template /etc/resolv.conf for an OCI container, so
            // without this the guest has no DNS at all: git and composer both fail to
            // resolve, the sync silently falls back to bare apache, and the container
            // serves 403 from an empty docroot while looking perfectly healthy.
            'nameserver'   => (string) ($opts['nameserver'] ?? self::DNS),
            'unprivileged' => 1,
            'onboot'       => 1,
            'start'        => 0,
        ]);
        if (!$create['ok']) return ['ok' => false, 'error' => 'create failed: ' . $create['exit'] . ($create['log'] ? "\n" . $create['log'] : '')];
        $steps[] = 'created CT ' . $vmid . ' from ' . $img['volid'];

        // Everything the container needs at boot. These are exported by the boot command
        // itself rather than set via the `env` config key: that key is accepted by the
        // API and reads back correctly, but is never applied to init on PVE 9.2.5. HOME
        // is included deliberately — without it git cannot find /root/.gitconfig and
        // rejects the working copy with "dubious ownership".
        $home = '/' . 'root';
        $vars = [
            'HOME'                 => $home,
            'PATH'                 => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
            // Referenced by the vhost as ${APACHE_DOCUMENT_ROOT}; if unset, apache
            // resolves it literally and 403s every path.
            'APACHE_DOCUMENT_ROOT' => '/var/www/html/public',
            'DNS_SERVER'           => (string) ($opts['nameserver'] ?? self::DNS),
            // Core's address on the bridge, so the container can always reach the git
            // endpoint directly. Defaults to whatever this host resolves its own
            // baseurl to, which is correct whenever core serves the endpoint itself.
            'CORE_IP'              => (string) ($opts['coreIp'] ?? (gethostbyname($host) ?: '')),
            'GIT_REMOTE'           => $remote,
            'GIT_HOST'             => $host,
            // Consumed once at boot to write a credential file, keeping the token out of
            // the remote URL and therefore out of git's output and the served boot log.
            'GIT_TOKEN'            => $token,
            'GIT_BRANCH'           => $branch,
            'BASE_URL'             => 'https://' . $domain,
            // docker/entrypoint.sh rewrites apache's listen port to this. Apache is
            // already running by the time it does so, but the rewritten ports.conf would
            // take effect on the next restart and silently move the container off the
            // port capricorn proxies to. Pin it to the port we actually serve.
            'APPLICATION_PORT'     => (string) self::PROXY_PORT,
            // Escape hatch for bring-up ONLY, and off by default. A missing PHP extension
            // is an image defect: ignoring the platform requirement installs the package
            // anyway and defers the failure to runtime, where it is far harder to read.
            // Fix the base image instead; this exists so a known-good image rebuild is
            // not a prerequisite for testing everything downstream of it.
            'COMPOSER_FLAGS'       => empty($opts['ignorePlatformReqs']) ? '' : '--ignore-platform-reqs',
            // Pinned so 2FA secrets and stored tokens survive a container recreate —
            // conf/ is not a volume, so config.ini is regenerated every boot.
            'APP_KEY'              => self::appKey($inst),
        ];

        $ep = $pve->setCtConfig($node, $vmid, ['entrypoint' => self::bootCommand($vars)]);
        if ($ep['error'] !== '') return ['ok' => false, 'error' => 'could not set entrypoint: ' . $ep['error']];
        $steps[] = 'configured boot command';

        // Trust the container's STATUS, not the start task's exit code: PVE's start task
        // routinely reports "unable to get PID for CT <id>" from a get_init_pid race
        // while the container is in fact running.
        $start  = $pve->startCt($node, $vmid);
        $status = '';
        for ($i = 0; $i < 10; $i++) {
            $status = (string) ($pve->ctStatus($node, $vmid)['status'] ?? '');
            if ($status === 'running') break;
            sleep(2);
        }
        if ($status !== 'running') {
            return ['ok' => false, 'vmid' => $vmid, 'steps' => $steps,
                    'error' => 'container is ' . ($status ?: 'unknown') . ' after start: ' . $start['exit']
                             . ($start['log'] ? "\n" . $start['log'] : '')];
        }
        $steps[] = 'started' . ($start['ok'] ? '' : ' (start task warned: ' . $start['exit'] . ')');

        // The address is static and known up front, so there is nothing to discover.
        $addr  = explode('/', $ip)[0];
        $proxy = self::writeProxy($domain, $addr);
        if (!$proxy['ok']) return ['ok' => false, 'vmid' => $vmid, 'ip' => $addr, 'error' => $proxy['error'], 'steps' => $steps];
        $steps[] = $proxy['step'];

        // TLS last: the container is already serving, so a certificate problem should not
        // fail the deploy — it just means the tenant is reachable but not yet trusted.
        if (!empty($opts['cert'])) {
            $cert = self::ensureCert($domain);
            $steps[] = $cert['step'];
        }

        $inst->ctVmid   = $vmid;
        $inst->ctIp     = $addr;
        $inst->ctDomain = $domain;
        R::store($inst);

        return ['ok' => true, 'vmid' => $vmid, 'ip' => $addr, 'domain' => $domain, 'steps' => $steps];
    }

    /**
     * The container's boot command, injected as the OCI entrypoint.
     *
     * git init + fetch rather than clone: the mount points already exist under
     * /var/www/html when this runs, so the directory is never empty and clone refuses.
     * Idempotent — a restart re-fetches and moves to the branch tip.
     */
    public static function bootCommand(array $vars = []): string {
        // NEVER `set -e` here, and never let a failure reach the end of the script: this
        // command IS the container's init, so anything that exits non-zero kills the
        // container outright — which is exactly how a transient network problem turned
        // into "unable to get PID for CT". Every step is allowed to fail; the container
        // stays up and the background loop heals it on the next pass.
        // NO INNER DOUBLE QUOTES, ANYWHERE. PVE stores the entrypoint string verbatim —
        // an escaped \" round-trips through the config API looking perfect — but its argv
        // parser does not honour the escape, so init receives a mangled command and exits
        // immediately. The symptom is "unable to get PID for CT <id>" with a config that
        // reads back byte-identical to what was sent. Values are whitespace-free (the
        // caller validates that), so bare $VAR is safe; `x$A != x$B` avoids needing
        // quotes for possibly-empty comparisons.
        $sync = 'sync_code() {'
              . ' git remote set-url origin $GIT_REMOTE'
              . ' && timeout 180 git fetch --depth 1 origin $GIT_BRANCH'
              . ' && git checkout -q -f FETCH_HEAD'
              . ' && { [ -f vendor/autoload.php ] || { composer install --no-dev --no-interaction --optimize-autoloader --no-progress $COMPOSER_FLAGS && composer clear-cache -q; }; };'
              . ' };';

        // The container's environment is set HERE, by the boot command itself, not via
        // the `env` config key. That key is accepted by the API and reads back correctly
        // but never reaches init on PVE 9.2.5 — the OCI import writes lxc.init.cwd for
        // WORKDIR yet no lxc.environment for ENV, and Proxmox intentionally does not
        // expose arbitrary lxc.* options through the API, so there is nothing to fix from
        // outside. An empty environment fails invisibly: apache resolves
        // ${APACHE_DOCUMENT_ROOT} literally and 403s, git loses HOME and reports "dubious
        // ownership", and the clone URL is empty.
        $exports = '';
        foreach ($vars as $k => $v) {
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string) $k) || preg_match('/[\s"]/', (string) $v)) {
                throw new \RuntimeException('boot variable ' . $k . ' is not shell-safe');
            }
            $exports .= ' export ' . $k . '=' . $v . ';';
        }

        $sh = 'cd /var/www/html;'
            . $exports
            . ' ' . $sync
            // Credentials live in a git credential file, never in the remote URL, so the
            // token cannot leak into git's error output (and therefore into the log below).
            // echo, not printf: the boot command cannot contain quotes, and a backslash
            // in an UNQUOTED word is an escape in POSIX sh — printf's \n became a literal
            // "n", so the file held ...@tiknix.comn with no trailing newline and git
            // never matched the host ("could not read Username"). echo appends the
            // newline itself and needs no escapes.
            . ' echo https://x:$GIT_TOKEN@$GIT_HOST > $HOME/.git-credentials;'
            . ' chmod 600 $HOME/.git-credentials;'
            . ' git config --global credential.helper store;'
            . ' git config --global --add safe.directory /var/www/html;'
            // Create the docroot up front: apache serves 403 for a MISSING DocumentRoot,
            // which is indistinguishable from a permissions problem. With it present the
            // sync log below is fetchable, so a failed boot can be diagnosed over HTTP
            // instead of needing node access.
            // Write the resolver ourselves. The `nameserver` config key is accepted by
            // the API but, like `env`, is never templated into an OCI container — the
            // guest boots with an empty /etc/resolv.conf and every name lookup fails
            // ("Could not resolve host"), which reads as a network fault rather than a
            // missing file. Cheap and idempotent, so just do it on every boot.
            . ' echo nameserver $DNS_SERVER > /etc/resolv.conf;'
            // Core is on the same bridge, so pin it in /etc/hosts: the code sync then
            // needs neither DNS nor the upstream gateway, and a tenant can always be
            // repaired from core even when its outbound path is broken.
            . ' echo $CORE_IP $GIT_HOST >> /etc/hosts;'
            // The vhost says DocumentRoot ${APACHE_DOCUMENT_ROOT}, which apache expands
            // from ITS environment at config-parse time. Rather than depend on the
            // container environment being populated, define it in apache's own config —
            // conf-enabled is included before sites-enabled, so the Define is in scope.
            . ' echo Define APACHE_DOCUMENT_ROOT /var/www/html/public > /etc/apache2/conf-enabled/00-docroot.conf;'
            . ' mkdir -p public;'
            // Does the container actually receive the env we set? Records presence only,
            // never a value — GIT_TOKEN must not reach a publicly served file.
            . ' { [ -n $GIT_REMOTE ] && echo env-ok remote=$GIT_REMOTE branch=$GIT_BRANCH; } > public/envcheck.txt 2>&1;'
            . ' [ -s public/envcheck.txt ] || echo env-MISSING > public/envcheck.txt;'
            . ' [ -d .git ] || { git init -q . && git remote add origin $GIT_REMOTE; };'
            // EVERYTHING SLOW RUNS BEHIND APACHE. The first sync clones the repo and runs
            // composer install, which takes minutes — doing that before exec'ing apache
            // left the container listening on nothing at all, so a stuck git was
            // indistinguishable from a dead container and the log could not be fetched.
            // Serving first means the boot log is always readable, which is how every
            // failure in this file was eventually diagnosed.
            //
            // Each git call is wrapped in `timeout`: a hung fetch (auth prompt, black-holed
            // route) must not wedge the sync loop forever.
            . ' ( { echo == resolv.conf; cat /etc/resolv.conf;'
            . '     echo == dns $GIT_HOST; getent hosts $GIT_HOST 2>&1;'
            . '     echo == sync; sync_code && echo TIKNIX_SYNC_OK;'
            // The repo ships its own entrypoint: seeds config.ini from the examples, pins
            // app_key from APP_KEY, initialises the DB. Passing `true` as the command runs
            // that preparation and exits instead of exec-ing a second apache.
            . '     [ -f docker/entrypoint.sh ] && { echo == app-init; bash docker/entrypoint.sh true 2>&1; };'
            // Writable paths. Storage-backed mount points come up owned by root, and the
            // app logs to log/ (which docker/entrypoint.sh does not create — it only
            // handles storage/logs), so without this every request dies in the logger
            // before it reaches a route: "could not be opened in append mode".
            . '     echo == perms; mkdir -p log storage/logs database public/uploads;'
            . '     chown -R www-data:www-data log storage database conf public/uploads 2>&1;'
            . '   } > public/sync.txt 2>&1;'
            // The every-minute puller. Poll with ls-remote (one round trip, no object
            // transfer) and only do real work when the branch tip actually moved.
            . '   while :; do sleep 60;'
            . '     R=$(timeout 30 git ls-remote origin $GIT_BRANCH 2>/dev/null | cut -f1);'
            . '     H=$(git rev-parse HEAD 2>/dev/null);'
            . '     [ x$R != x ] && [ x$R != x$H ] && sync_code >> public/sync.txt 2>&1;'
            . '   done ) &'
            . ' exec apache2-foreground';

        $cmd = '/bin/sh -c "' . $sh . '"';
        // Fail loudly here rather than shipping a container that cannot boot.
        if (substr_count($cmd, '"') !== 2) {
            throw new \RuntimeException('boot command contains inner double quotes, which PVE will mis-parse');
        }
        return $cmd;
    }

    /** Make sure the base image is on the node, pulling it if not. */
    private static function ensureImage(ProxmoxService $pve, string $node, string $image): array {
        $tmpl = $pve->storagesFor($node, 'vztmpl')[0]['storage'] ?? '';
        $root = $pve->storagesFor($node, 'rootdir')[0]['storage'] ?? '';
        if ($tmpl === '' || $root === '') return ['ok' => false, 'error' => 'node has no vztmpl and/or rootdir storage'];

        $ref = preg_replace('#^docker://#', '', $image);
        [$repo, $tag] = array_pad(explode(':', $ref, 2), 2, 'latest');
        $want = basename($repo) . '_' . $tag . '.tar';

        foreach ($pve->content($node, $tmpl, 'vztmpl') as $t) {
            if (basename((string) $t['volid']) === $want) {
                return ['ok' => true, 'volid' => (string) $t['volid'], 'rootfs' => $root, 'step' => 'image present: ' . $t['volid']];
            }
        }
        $pull = $pve->ociRegistryPull($node, $tmpl, ['reference' => $ref]);
        if (!$pull['ok']) return ['ok' => false, 'error' => 'image pull failed: ' . $pull['exit'] . ($pull['log'] ? "\n" . $pull['log'] : '')];
        return ['ok' => true, 'volid' => $tmpl . ':vztmpl/' . $want, 'rootfs' => $root, 'step' => 'pulled ' . $ref];
    }


    /**
     * Point capricorn at the container. determine_env splits the host into sname + tld
     * (last label), and determine_proxy reads /var/www/html/.proxy.<sname> — so
     * test1.tiknix.com is served from .proxy.test1.tiknix.
     */
    private static function writeProxy(string $domain, string $ip): array {
        $parts = explode('.', $domain);
        if (count($parts) < 2) return ['ok' => false, 'error' => 'domain needs at least one dot: ' . $domain];
        array_pop($parts);                       // drop the tld, exactly as capricorn does
        $file = self::PROXY_DIR . '/.proxy.' . implode('.', $parts);

        $body = "proxyhost={$ip}\nproxyport=" . self::PROXY_PORT . "\n";
        if (@file_put_contents($file, $body) === false) {
            return ['ok' => false, 'error' => $file . ' is not writable by the web user'];
        }
        return ['ok' => true, 'step' => 'wrote ' . basename($file) . ' -> ' . $ip . ':' . self::PROXY_PORT];
    }

    /**
     * Does this container hold tenant data worth protecting?
     *
     * The volumes cannot be inspected from outside — there is no exec API and bind
     * mounts are root-only — so ask the APP instead: tiknix redirects to /install until
     * setup is complete, which is exactly the "nothing here yet" signal. Anything else
     * means someone has an account and therefore data.
     *
     * FAILS CLOSED. An unreachable container is reported as having data, because the
     * cost of being wrong is asymmetric: refusing a recreate wastes a minute, while
     * wrongly allowing one destroys a tenant's database.
     *
     * @return array{hasData:bool, why:string}
     */
    private static function dataState(ProxmoxService $pve, string $node, int $vmid): array {
        $ip = '';
        foreach (explode(',', (string) ($pve->ctConfig($node, $vmid)['net0'] ?? '')) as $part) {
            if (str_starts_with(trim($part), 'ip=')) { $ip = explode('/', substr(trim($part), 3))[0]; break; }
        }
        if ($ip === '' || $ip === 'dhcp') return ['hasData' => true, 'why' => 'cannot determine the container address'];

        $ch = curl_init('http://' . $ip . '/');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_FOLLOWLOCATION => false]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $to   = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);

        if ($body === false || $code === 0) return ['hasData' => true, 'why' => 'container did not respond, so its state is unknown'];
        if (str_contains($to, '/install'))  return ['hasData' => false, 'why' => 'still on first-run setup, no account created yet'];
        return ['hasData' => true, 'why' => 'the app is past first-run setup (HTTP ' . $code . ')'];
    }

    /**
     * Issue a TLS certificate for the tenant's hostname, if one is needed.
     *
     * OPT-IN, and a no-op when a usable cert already exists. Let's Encrypt allows only
     * 5 duplicate certificates per week: issuing on every deploy would exhaust that in
     * an afternoon of redeploys and then fail for a real tenant. So this checks the
     * existing cert's expiry first and only calls out when there is genuinely nothing
     * to serve, or the cert is within CERT_RENEW_DAYS of expiring.
     *
     * Requires write access to the lego folder — fine from the CLI (ubuntu), but a
     * web-triggered deploy runs as www-data and will not have it.
     *
     * @return array{ok:bool, step:string}
     */
    private static function ensureCert(string $domain): array {
        $crt = self::CERT_DIR . '/' . $domain . '.crt';

        if (is_file($crt)) {
            // -checkend is exactly this question: still valid N seconds from now?
            $seconds = self::CERT_RENEW_DAYS * 86400;
            exec('openssl x509 -checkend ' . $seconds . ' -noout -in ' . escapeshellarg($crt) . ' 2>&1', $o, $code);
            if ($code === 0) return ['ok' => true, 'step' => 'certificate present and valid'];
            $reason = 'expiring within ' . self::CERT_RENEW_DAYS . ' days';
        } else {
            $reason = 'no certificate on disk';
        }

        if (!is_file(self::CERT_SCRIPT)) {
            return ['ok' => false, 'step' => 'certificate needed (' . $reason . ') but ' . self::CERT_SCRIPT . ' is missing'];
        }

        exec(escapeshellcmd(self::CERT_SCRIPT) . ' ' . escapeshellarg($domain) . ' 2>&1', $out, $code);
        if ($code !== 0 || !is_file($crt)) {
            return ['ok' => false, 'step' => 'certificate issuance failed: ' . trim(implode(' ', array_slice($out, -3)))];
        }
        return ['ok' => true, 'step' => 'issued certificate for ' . $domain . ' (' . $reason . ')'];
    }

    /** Stable per-instance app key so encrypted data survives a container recreate. */
    private static function appKey(object $inst): string {
        if (empty($inst->appKey) || !preg_match('/^[0-9a-f]{64}$/', (string) $inst->appKey)) {
            $inst->appKey = bin2hex(random_bytes(32));
            R::store($inst);
        }
        return (string) $inst->appKey;
    }

    /**
     * The clone URL, WITHOUT credentials. The token goes in a git credential file
     * instead (see bootCommand): git prints the remote URL in most of its error
     * messages, so a token embedded here would end up in any log we captured — which is
     * precisely why the boot log can now be served for diagnostics.
     */
    private static function remoteUrl(string $slug): string {
        return rtrim((string) \Flight::get('app.baseurl'), '/') . '/git/' . $slug . '.git';
    }

    private static function defaultDomain(string $slug): string {
        $host = strtolower((string) (parse_url((string) \Flight::get('app.baseurl'), PHP_URL_HOST) ?: 'tiknix.com'));
        return $slug . '.' . $host;
    }

    /** PVE hostnames are DNS labels: no dots, no underscores. */
    private static function hostname(string $slug): string {
        return substr(preg_replace('/[^a-z0-9-]/', '-', strtolower($slug)), 0, 63);
    }

    private static function validHost(string $h): bool {
        return $h !== '' && strlen($h) <= 253 && !str_contains($h, '..')
            && preg_match('/^[a-z0-9.-]+$/', $h) === 1
            && $h[0] !== '.' && $h[0] !== '-' && substr($h, -1) !== '.' && str_contains($h, '.');
    }
}
