<?php
/**
 * ProvisionService — the CORE-side owner of instance-registry MUTATIONS + capricorn
 * provisioning. The workbench sidecar (AI Builder) is read-only to core, so it never
 * writes the `instance` registry directly; it signs a request and calls the HMAC-authed
 * /provision endpoint, which dispatches here. All registry writes + custody stay in core.
 *
 * Lifted from controls/Aibuilder's create/fork/delete/share — the ~5-op write-seam. Each
 * op takes ($memberId, $params) and returns ['ok'=>true, …] or ['ok'=>false,'error','code'].
 */
namespace app;

use \Flight as Flight;
use RedBeanPHP\R;

class ProvisionService {

    private const APP = 'tiknix';
    private const SLUG_RE = '/^[a-z][a-z0-9]{1,49}$/';

    private function cfg(): array {
        return @parse_ini_file(dirname(__DIR__) . '/conf/aibuilder.ini', true) ?: [];
    }

    private function appNamespace(): string {
        $host = strtolower((string) (parse_url((string) Flight::get('app.baseurl'), PHP_URL_HOST) ?: ''));
        $ns   = preg_replace('/\.com$/', '', $host);
        return ($ns !== '' && preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/', $ns))
            ? $ns : self::APP;
    }

    private function instanceDir(string $slug): string {
        return '/var/www/html/default/' . $slug . '.' . $this->appNamespace();
    }

    /** Run a capricorn instance script (args already validated). Returns ok/out/code. */
    private function runScript(string $script, array $args): array {
        $cfg    = $this->cfg();
        $binDir = rtrim((string) ($cfg['ops']['bin_dir'] ?? '/home/ubuntu/capricorn/bin'), '/');
        $prefix = trim((string) ($cfg['ops']['sudo_prefix'] ?? ''));
        $cmd = ($prefix ? $prefix . ' ' : '') . escapeshellarg($binDir . '/' . $script);
        foreach ($args as $a) { $cmd .= ' ' . escapeshellarg((string) $a); }
        $lines = []; $code = 0;
        exec($cmd . ' 2>&1', $lines, $code);
        return ['ok' => $code === 0, 'out' => implode("\n", $lines), 'code' => $code];
    }

    /** Register an instance bean owned by $memberId (shared by create/fork). */
    private function registerInstanceBean(int $memberId, string $slug, string $name, string $engine, bool $isDefault): object {
        $member = R::load('member', $memberId);
        $inst = R::dispense('instance');
        $inst->slug        = $slug;
        $inst->app         = $this->appNamespace();
        $inst->displayName = $name;
        $inst->engine      = $engine;
        $inst->status      = 'active';
        $inst->isDefault   = $isDefault ? 1 : 0;
        $inst->createdAt   = date('Y-m-d H:i:s');
        $member->ownInstanceList[] = $inst;   // sets member_id via the association
        R::store($member);
        try { BrokerService::ensureInstanceConfig((int) $inst->id, $memberId, $this->instanceDir($slug)); }
        catch (\Throwable $e) { /* the instance can mint its broker key later */ }
        return $inst;
    }

    /** Provision a NEW isolated instance owned by $memberId. */
    public function create(int $memberId, array $p): array {
        $slug   = strtolower(trim((string) ($p['slug'] ?? '')));
        $name   = trim((string) ($p['name'] ?? '')) ?: ucfirst($slug);
        $engine = (string) ($p['engine'] ?? 'claude');
        // Only root may flag the "(default)" core sandbox; the caller passes is_root.
        $isDefault = !empty($p['is_default']) && !empty($p['is_root']);

        if (!preg_match(self::SLUG_RE, $slug)) return ['ok' => false, 'error' => 'Invalid name (a-z, then a-z0-9, 2-50 chars).', 'code' => 400];
        if (R::count('instance', 'slug = ?', [$slug]) > 0 || is_dir($this->instanceDir($slug)))
            return ['ok' => false, 'error' => 'That name is already taken.', 'code' => 409];

        $member = R::load('member', $memberId);
        if (!$member->id) return ['ok' => false, 'error' => 'Unknown member.', 'code' => 403];

        // capricorn clones the app, seeds an isolated sqlite db + guardrails + reset secrets.
        $out = $this->runScript('provision-instance.sh',
            [$this->appNamespace(), $slug, '--admin', (string) $member->email, '--name', $name]);
        if (!is_file($this->instanceDir($slug) . '/public/index.php'))
            return ['ok' => false, 'error' => 'Provisioning failed. ' . substr(trim($out['out']), -300), 'code' => 500];

        @file_put_contents($this->instanceDir($slug) . '/.aibuilder/engine', $engine . "\n");
        $inst = $this->registerInstanceBean($memberId, $slug, $name, $engine, $isDefault);
        return ['ok' => true, 'id' => (int) $inst->id, 'slug' => $slug];
    }
}
