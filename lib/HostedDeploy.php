<?php
/**
 * HostedDeploy — deploy a repo BRANCH into /var/www/html/hosted/<domain>/ so a custom domain
 * serves it (via capricorn determine_env's /hosted/<full-domain>/ router). Model B: a finalized
 * snapshot, separate from the editable staging instance.
 *
 * Data safety: `git reset --hard origin/<branch>` updates only TRACKED files (the code). The
 * deployed app's conf/config.ini, database/, and vendor/ are all gitignored, so they PERSIST
 * across deploys — the production DB is never wiped. Schema/data changes ride in via idempotent
 * migration seeders (database/seeds/*.php), run every deploy.
 *
 * The OAuth/PAT token is used only for the clone/fetch and is immediately scrubbed from the
 * remote so it never rests in .git/config. TLS (lego) + the nginx vhost are provisioned by the
 * operator for now (hand-picked customers) — this just lands the files under /hosted.
 */
namespace app;

class HostedDeploy {

    const HOSTED_ROOT = '/var/www/html/hosted';

    /** @return array{ok:bool, dir?:string, steps?:string[], error?:string} */
    public static function deploy(string $domain, string $repoFull, string $branch, string $token, string $instanceDir): array {
        $domain = strtolower(trim($domain));
        if (!self::validHost($domain)) return ['ok' => false, 'error' => 'Invalid domain'];
        if (!preg_match('#^[A-Za-z0-9._/-]+$#', $repoFull) || !preg_match('#^[A-Za-z0-9._/-]+$#', $branch))
            return ['ok' => false, 'error' => 'Invalid repo or branch'];

        $dir   = self::HOSTED_ROOT . '/' . $domain;
        $auth  = 'https://x-access-token:' . $token . '@github.com/' . $repoFull . '.git';
        $clean = 'https://github.com/' . $repoFull . '.git';
        $steps = [];

        if (!is_dir($dir . '/.git')) {
            if (!is_dir(self::HOSTED_ROOT) && !@mkdir(self::HOSTED_ROOT, 0775, true))
                return ['ok' => false, 'error' => self::HOSTED_ROOT . ' is not writable by the web user'];
            [$out, $code] = self::sh('git clone --depth 1 --branch ' . escapeshellarg($branch) . ' ' . escapeshellarg($auth) . ' ' . escapeshellarg($dir) . ' 2>&1');
            if ($code !== 0 || !is_dir($dir . '/.git')) return ['ok' => false, 'error' => 'Clone failed: ' . self::scrub($out, $token)];
            $steps[] = 'cloned ' . $repoFull . '@' . $branch;
        } else {
            self::sh('git -C ' . escapeshellarg($dir) . ' remote set-url origin ' . escapeshellarg($auth));
            self::sh('git -C ' . escapeshellarg($dir) . ' fetch --depth 1 origin ' . escapeshellarg($branch) . ' 2>&1');
            [$out, $code] = self::sh('git -C ' . escapeshellarg($dir) . ' reset --hard ' . escapeshellarg('origin/' . $branch) . ' 2>&1');
            if ($code !== 0) return ['ok' => false, 'error' => 'Update failed: ' . self::scrub($out, $token)];
            [$head] = self::sh('git -C ' . escapeshellarg($dir) . ' rev-parse --short HEAD 2>&1');
            $steps[] = 'updated to ' . $branch . ' @ ' . trim($head);
        }
        // Never leave the token behind in .git/config.
        self::sh('git -C ' . escapeshellarg($dir) . ' remote set-url origin ' . escapeshellarg($clean));

        // vendor: symlink the instance's (same framework) — avoids composer install, stays current.
        if (!file_exists($dir . '/vendor') && is_dir($instanceDir . '/vendor')) {
            if (@symlink($instanceDir . '/vendor', $dir . '/vendor')) $steps[] = 'linked vendor';
        }

        // conf/config.ini (gitignored → survives resets): create on first deploy, stamp host + db.
        $steps[] = self::ensureConfig($dir, $domain);

        // Idempotent migration seeders (schema/data), preserving whatever DB is already there.
        $seed = self::runSeeders($dir);
        if ($seed !== '') $steps[] = $seed;

        return ['ok' => true, 'dir' => $dir, 'steps' => $steps];
    }

    /** Create config.ini on first deploy (from *.example), stamp baseurl + a per-domain db path. */
    private static function ensureConfig(string $dir, string $domain): string {
        $cfg = $dir . '/conf/config.ini';
        $fresh = !is_file($cfg);
        if ($fresh) {
            $tpl = is_file($dir . '/conf/config.example.ini') ? $dir . '/conf/config.example.ini'
                 : (is_file($dir . '/conf/config.sqlite.example.ini') ? $dir . '/conf/config.sqlite.example.ini' : '');
            if ($tpl === '' || !@copy($tpl, $cfg)) return 'config.ini absent (no template) — the deployed app may need manual config';
        }
        $ini = (string) @file_get_contents($cfg);
        $db  = 'database/' . preg_replace('/[^a-z0-9]+/', '_', $domain) . '.db';
        $ini = preg_replace('/^\s*baseurl\s*=.*$/m', 'baseurl = "https://' . $domain . '"', $ini, 1);
        $ini = preg_replace('/^\s*path\s*=.*$/m', 'path = ' . $db, $ini, 1);
        @file_put_contents($cfg, $ini);
        @mkdir($dir . '/database', 0775, true);
        return $fresh ? 'created config.ini (baseurl=' . $domain . ')' : 're-stamped config.ini';
    }

    /** Run every database/seeds/*.php (they are idempotent via \app\Bean). */
    private static function runSeeders(string $dir): string {
        $seeds = glob($dir . '/database/seeds/*.php') ?: [];
        if (!$seeds) return '';
        $ok = 0;
        foreach ($seeds as $s) { [, $c] = self::sh('cd ' . escapeshellarg($dir) . ' && php ' . escapeshellarg($s) . ' 2>&1'); if ($c === 0) $ok++; }
        return 'ran ' . $ok . '/' . count($seeds) . ' seeder(s)';
    }

    private static function sh(string $cmd): array { $o = []; $c = 0; @exec($cmd, $o, $c); return [implode("\n", $o), $c]; }
    private static function scrub(string $s, string $token): string { return $token !== '' ? str_replace($token, '***', $s) : $s; }

    private static function validHost(string $h): bool {
        if ($h === '' || strlen($h) > 253 || strpos($h, '..') !== false) return false;
        if (!preg_match('/^[a-z0-9.-]+$/', $h)) return false;
        return $h[0] !== '.' && $h[0] !== '-' && substr($h, -1) !== '.' && substr($h, -1) !== '-' && strpos($h, '.') !== false;
    }
}
