<?php
/**
 * Deploy — where the selected project goes live (the left nav's Deploy).
 *
 * For a project in its own container, what you build IS the live site (merge is publish), so
 * deploying is about where it answers and where copies of it go:
 *
 *   Domains  its main address and every other domain pointed at it, each a site of its own
 *            (lib/TenantDomains.php; the app's side is app\Host): DNS checked, certificate,
 *            its own config and database in the app, routing. Certificates renew themselves.
 *   Export   copies of its code to servers of your own: rsync over SSH, an SSH command
 *            (Publish\RsyncDriver, SshDriver). The SSH key is made and kept here, sealed —
 *            the app never holds it; the code shipped is the container's HEAD.
 *
 * A project still living as a host clone deploys through the Publisher, as before.
 * Anyone on a project sees its page; only its owner changes or runs anything.
 */

namespace app;

use \Flight as Flight;

class Deploy extends BaseControls\Control {

    /** Export drivers offered here: a copy on a server of your own. */
    private const EXPORT_DRIVERS = ['rsync' => Publish\RsyncDriver::class, 'ssh' => Publish\SshDriver::class];

    // ---------------------------------------------------------------- the page

    public function index($params = []) {
        if (!$this->requireLogin()) return;
        $inst = $this->project(false);
        if (!$inst) { $this->flash('info', 'Pick a project to deploy.'); Flight::redirect('/projects'); return; }
        $drivers = [];
        foreach (self::EXPORT_DRIVERS as $k => $cls) $drivers[$k] = ['label' => $cls::label(), 'blurb' => $cls::blurb(), 'fields' => $cls::fields()];
        $this->render('deploy/index', [
            'title'       => 'Deploy',
            'instance'    => $inst,
            'inContainer' => \Model_Instance::tenantRow($inst),
            'canManage'   => $inst->ownedBy((int) $this->member->id),
            'drivers'     => $drivers,
            'qa'          => $this->qaOffer(),
        ]);
    }

    /**
     * QA Testing on this page: the door when the member has it, the upgrade when it is on
     * offer and they do not, "coming soon" until it is ([qa] available in conf/config.ini —
     * a setting that must be there: the page does not guess which of the two to promise).
     *
     * @return array{state:string,url:string} state: enabled | upsell | soon
     */
    private function qaOffer(): array {
        $on = Flight::get('qa.available');
        if ($on === null) throw new \RuntimeException('conf/config.ini has no [qa] available setting (true = offered as an upgrade, false = coming soon).');
        if (Feature::isEnabled('qa', (int) $this->member->id, (int) $this->member->level)) return ['state' => 'enabled', 'url' => '/sidecar/app/qa'];
        return filter_var($on, FILTER_VALIDATE_BOOLEAN) ? ['state' => 'upsell', 'url' => '/contact?category=feature'] : ['state' => 'soon', 'url' => ''];
    }

    /** The selected (or ?id=) project, when the member may see it; $owner = and owns it. */
    private function project(bool $owner) {
        $id = (int) $this->getParam('id', 0);
        if ($id <= 0) { $p = ProjectContext::current((int) $this->member->id); $id = $p ? (int) $p->id : 0; }
        $inst = $id > 0 ? Bean::load('instance', $id) : null;
        if (!$inst || !$inst->id || !$inst->accessibleBy((int) $this->member->id)) return null;
        if ($owner && !$inst->ownedBy((int) $this->member->id)) return null;
        return $inst;
    }

    /** The project for a container-only action, or a JSON refusal naming why. */
    private function containerProject(bool $owner) {
        $inst = $this->project($owner);
        if (!$inst) { Flight::jsonError($owner ? "Only the project's owner can do that." : 'No project selected.', 403); return null; }
        if (!\Model_Instance::tenantRow($inst)) { Flight::jsonError("{$inst->slug} does not live in its own container yet; it deploys through the Publisher.", 409); return null; }
        return $inst;
    }

    // ---------------------------------------------------------------- domains

    /** GET: the project's addresses — its main one and each domain with its certificate. */
    public function domains($params = []) {
        if (!$this->requireLogin()) return;
        if (!($inst = $this->containerProject(false))) return;
        $out = [];
        foreach (TenantDomains::of($inst) as $d) {
            $exp = TenantDomains::certExpires($d);
            $out[] = ['domain' => $d, 'cert_expires' => $exp, 'cert' => $exp !== '' ? 'own' : 'wildcard'];
        }
        Flight::jsonSuccess([
            'main'       => (string) $inst->ctDomain,
            'domains'    => $out,
            'server_ip'  => TenantDomains::ourAddress(),
            'can_manage' => $inst->ownedBy((int) $this->member->id),
        ]);
    }

    /** POST domain: point it at this project as a site of its own. */
    public function domainadd($params = []) {
        if (!$this->requireLogin() || !$this->requirePost()) return;
        if (!($inst = $this->containerProject(true))) return;
        // Custom domains come with a paid project — the rule the old hosting card had.
        if (!ProjectQuota::canUseCustomDomain((int) $inst->memberId)) {
            Flight::jsonError('Custom domains come with a paid project ($' . number_format(ProjectQuota::PRICE_PER_PROJECT, 0)
                . '/mo). Add a card on the Billing page and this unlocks.', 402);
            return;
        }
        $domain = (string) $this->getParam('domain', '');
        set_time_limit(300);   // DNS, a Let's Encrypt order, the app's database: under a minute, usually
        $r = TenantDomains::add($inst, $domain);
        $this->logger->info('Domain add', ['instance' => $inst->slug, 'domain' => $domain, 'ok' => $r['ok'], 'error' => $r['error'] ?? '']);
        if (!$r['ok']) { Flight::jsonError($r['error'], 400); return; }
        Flight::jsonSuccess(['steps' => $r['steps'], 'url' => $r['url']], $r['url'] . ' is live — its first visit sets up its own admin.');
    }

    /** POST domain: stop serving it (the site's data stays in the app). */
    public function domainremove($params = []) {
        if (!$this->requireLogin() || !$this->requirePost()) return;
        if (!($inst = $this->containerProject(true))) return;
        $domain = (string) $this->getParam('domain', '');
        $r = TenantDomains::remove($inst, $domain);
        $this->logger->info('Domain remove', ['instance' => $inst->slug, 'domain' => $domain, 'ok' => $r['ok'], 'error' => $r['error'] ?? '']);
        if (!$r['ok']) { Flight::jsonError($r['error'], 400); return; }
        Flight::jsonSuccess(['steps' => $r['steps']], "{$domain} is no longer served. Its data stays in the app.");
    }

    // ---------------------------------------------------------------- export targets

    /** GET: the project's export targets, each with its driver's status (the key to authorise). */
    public function targets($params = []) {
        if (!$this->requireLogin()) return;
        if (!($inst = $this->containerProject(false))) return;
        $out = [];
        foreach (Bean::find('deploytarget', 'instance_ref = ? ORDER BY id ASC', [(int) $inst->id]) as $t) {
            $cfg = json_decode((string) $t->configJson, true) ?: [];
            $cls = self::EXPORT_DRIVERS[(string) $t->driver] ?? null;
            $out[] = [
                'id' => (int) $t->id, 'driver' => (string) $t->driver, 'label' => (string) $t->label,
                'driver_label' => $cls ? $cls::label() : (string) $t->driver, 'config' => $cfg,
                'status' => $cls ? (new $cls())->status($inst, $cfg) : null,
                'last_run_at' => (string) ($t->lastRunAt ?? ''), 'last_ok' => (bool) $t->lastOk, 'last_message' => (string) ($t->lastMessage ?? ''),
            ];
        }
        Flight::jsonSuccess(['targets' => $out, 'can_manage' => $inst->ownedBy((int) $this->member->id)]);
    }

    /** POST id?, driver, label, fields…: create or update a target. */
    public function targetsave($params = []) {
        if (!$this->requireLogin() || !$this->requirePost()) return;
        if (!($inst = $this->containerProject(true))) return;
        $id = (int) $this->getParam('target', 0);
        $t = $id > 0 ? $this->target($inst, $id) : Bean::dispense('deploytarget');
        if (!$t) return;
        $driver = $id > 0 ? (string) $t->driver : (string) $this->getParam('driver', '');
        $cls = self::EXPORT_DRIVERS[$driver] ?? null;
        if (!$cls) { Flight::jsonError("Unknown export kind '{$driver}'.", 400); return; }
        $cfg = []; $missing = [];
        foreach ($cls::fields() as $f) {
            $v = trim((string) $this->getParam((string) $f['name'], ''));
            if ($v === '' && !empty($f['required'])) $missing[] = (string) ($f['label'] ?? $f['name']);
            if ($v !== '') $cfg[(string) $f['name']] = $v;
        }
        if ($missing) { Flight::jsonError('Required: ' . implode(', ', $missing) . '.', 400); return; }
        $t->instanceRef = (int) $inst->id;
        $t->driver      = $driver;
        $t->label       = trim((string) $this->getParam('label', '')) ?: ($cls::label() . ' → ' . ($cfg['host'] ?? ''));
        $t->configJson  = json_encode($cfg, JSON_UNESCAPED_SLASHES);
        if (!$t->createdAt) $t->createdAt = date('Y-m-d H:i:s');
        $t->updatedAt   = date('Y-m-d H:i:s');
        Bean::store($t);
        $this->logger->info('Export target saved', ['instance' => $inst->slug, 'target' => (int) $t->id, 'driver' => $driver]);
        Flight::jsonSuccess(['id' => (int) $t->id], 'Saved. Test the connection to get the key to authorise on your server.');
    }

    /** POST target: remove it (its driver key stays with the project, for the next target on that host). */
    public function targetdelete($params = []) {
        if (!$this->requireLogin() || !$this->requirePost()) return;
        if (!($inst = $this->containerProject(true))) return;
        if (!($t = $this->target($inst, (int) $this->getParam('target', 0)))) return;
        Bean::trash($t);
        Flight::jsonSuccess([], 'Removed.');
    }

    /** POST target: the handshake — connect, prove who we land as, check the path; nothing written. */
    public function targetverify($params = []) {
        if (!$this->requireLogin() || !$this->requirePost()) return;
        if (!($inst = $this->containerProject(true))) return;
        if (!($t = $this->target($inst, (int) $this->getParam('target', 0)))) return;
        $cls = self::EXPORT_DRIVERS[(string) $t->driver];
        $drv = new $cls();
        $cfg = json_decode((string) $t->configJson, true) ?: [];
        $r = $drv->verify($inst, $cfg);
        Flight::json(['success' => (bool) ($r['ok'] ?? false), 'message' => (string) ($r['message'] ?? ''), 'data' => ['status' => $drv->status($inst, $cfg)] + $r]);
    }

    /** POST target: export now. */
    public function targetrun($params = []) {
        if (!$this->requireLogin() || !$this->requirePost()) return;
        if (!($inst = $this->containerProject(true))) return;
        if (!($t = $this->target($inst, (int) $this->getParam('target', 0)))) return;
        $cls = self::EXPORT_DRIVERS[(string) $t->driver];
        set_time_limit(700);   // the drivers cap one transfer at 600 s
        $r = (new $cls())->deploy($inst, json_decode((string) $t->configJson, true) ?: []);
        $ok = (bool) ($r['ok'] ?? false);
        $msg = (string) ($ok ? ($r['message'] ?? 'Done.') : ($r['error'] ?? 'Failed.'));
        $t->lastRunAt = date('Y-m-d H:i:s'); $t->lastOk = $ok ? 1 : 0; $t->lastMessage = mb_substr($msg, 0, 2000);
        Bean::store($t);
        $this->logger->info('Export run', ['instance' => $inst->slug, 'target' => (int) $t->id, 'ok' => $ok, 'message' => mb_substr($msg, 0, 300)]);
        if (!$ok) { Flight::jsonError($msg, 400); return; }
        Flight::jsonSuccess(['steps' => array_values((array) ($r['steps'] ?? []))], $msg);
    }

    /** One of the project's targets, or a JSON 404. */
    private function target(object $inst, int $id) {
        $t = $id > 0 ? Bean::load('deploytarget', $id) : null;
        if (!$t || !$t->id || (int) $t->instanceRef !== (int) $inst->id || !isset(self::EXPORT_DRIVERS[(string) $t->driver])) {
            Flight::jsonError('No such export target on this project.', 404);
            return null;
        }
        return $t;
    }
}
