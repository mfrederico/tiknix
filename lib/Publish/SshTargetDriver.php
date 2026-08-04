<?php
/**
 * SshTargetDriver — shared plumbing for targets that reach a customer's own server.
 *
 * WHY THIS IS A DRIVER AND NOT JUST A PIPELINE STEP. The recipe genuinely is one shell
 * command; a `shell` step running `rsync -az ... user@host:/path` would work today, and
 * an operator who wants to use their own key and their own agent should just do that.
 * What forces a driver is the KEY. A pipeline runs inside the instance, where the jailed
 * agent and the customer's own application code can read the filesystem — so a private
 * key placed there is a key we have handed to everything running in that instance. Here
 * the key is generated on the control plane, stored encrypted, and materialised into a
 * mode-0600 file for the length of exactly one command. The instance presents only its
 * broker key and never sees the credential. That is the same boundary GithubPrDriver
 * holds for a PAT, for the same reason.
 *
 * The keypair is created on first use and its PUBLIC half is reported by status(), so the
 * first publish to a new host fails with something actionable — "add this to
 * authorized_keys" — rather than a bare permission denied. One keypair per instance per
 * driver: revoking one customer's access must never affect another's.
 *
 * Host, user and path are NOT secrets, so they live in the publish pipeline in the
 * project's repo where they are reviewable. Only the key lives here.
 */
namespace app\Publish;

use app\Bean;

abstract class SshTargetDriver implements PublishDriver {

    /** Connection timeout for a single ssh/rsync invocation. */
    protected const CONNECT_TIMEOUT = 10;

    /** Wall-clock cap so a hung transfer cannot pin a pipeline worker forever. */
    protected const RUN_TIMEOUT = 600;

    /**
     * Any member may publish to a server they control — it is their host, their key, and
     * it costs this control plane nothing. Compare tiknix-hosted, which spends ours.
     */
    public static function minLevel(string $op): int {
        return LEVELS['MEMBER'];
    }


    public static function capabilities(): array {
        return [
            'code'     => true,    // shipping the change IS the deploy for these
            'domain'   => false,   // a directory on someone else's server has no hostname we own
            'tls'      => false,
            'refresh'  => true,    // publishing again re-runs it
            'recreate' => false,
            'sshKey'   => true,
        ];
    }

    /** Shared connection fields; subclasses append their own. */
    protected static function sshFields(): array {
        return [
            ['name' => 'host', 'label' => 'Host', 'type' => 'host', 'required' => true,
             'placeholder' => 'server.example.com', 'help' => 'The server to reach over SSH.'],
            ['name' => 'user', 'label' => 'User', 'type' => 'text', 'required' => true,
             'placeholder' => 'deploy', 'help' => 'The SSH user. Give it only the access this deploy needs.'],
            ['name' => 'port', 'label' => 'Port', 'type' => 'number',
             'placeholder' => '22', 'help' => 'Optional — defaults to 22.'],
        ];
    }

    public function status(object $inst, array $config): array {
        $conn = self::keyConnection($inst, static::key(), false);
        $host = trim((string) ($config['host'] ?? ''));
        $user = trim((string) ($config['user'] ?? ''));
        $meta = $conn ? (json_decode((string) ($conn->metadataJson ?: '{}'), true) ?: []) : [];

        return [
            'configured'  => $host !== '' && $user !== '',
            'target'      => $host !== '' ? ($user !== '' ? $user . '@' . $host : $host) : '',
            // The customer needs this to authorise us. It is a PUBLIC key; showing it is
            // the entire point of generating a keypair instead of asking for a password.
            'publicKey'   => (string) ($meta['public_key'] ?? ''),
            'fingerprint' => (string) ($meta['fingerprint'] ?? ''),
            'keyReady'    => $conn && (string) ($meta['public_key'] ?? '') !== '',
            'lastUsed'    => $conn ? ($conn->lastUsedAt ?: null) : null,
            'lastError'   => $conn ? ($conn->lastError ?: null) : null,
        ];
    }

    public function refresh(object $inst, array $config, array $opts = []): array {
        return $this->deploy($inst, $config, $opts);
    }

    /**
     * Handshake: connect, prove who we land as, and report what we found — no transfer,
     * no command of the customer's, nothing written.
     *
     * Generating the keypair here rather than on first publish is the point: you cannot
     * authorise a key you have not been given, so the handshake's job on a fresh target is
     * to fail usefully and hand over the public half.
     */
    public function verify(object $inst, array $config): array {
        $c = self::connection($config);
        if (empty($c['ok'])) return ['ok' => false, 'message' => (string) $c['error']];

        $conn = self::keyConnection($inst, static::key());
        if (!$conn) return ['ok' => false, 'message' => 'Could not generate an SSH key for this target.'];

        // `id` on every unix, and the remote path check is a read. Both are printed as
        // one line each so a chatty login shell (motd, banners) cannot be mistaken for
        // the answer.
        $path  = trim((string) ($config['path'] ?? ''));
        $probe = 'echo TIKNIX_USER=$(id -un); echo TIKNIX_HOST=$(hostname)';
        if ($path !== '') {
            $p = self::remotePath($config);
            if (empty($p['ok'])) return ['ok' => false, 'message' => (string) $p['error']];
            $q = escapeshellarg($p['path']);
            $probe .= '; if [ -d ' . $q .' ]; then if [ -w ' . $q . ' ]; then echo TIKNIX_PATH=writable;'
                    . ' else echo TIKNIX_PATH=not-writable; fi; else echo TIKNIX_PATH=missing; fi';
        }

        try {
            $res = SshKey::withKeyFile((string) $conn->accessToken, function (string $keyFile) use ($c, $probe, $inst) {
                return self::run('ssh ' . self::sshOpts($keyFile, $inst, (int) $c['port'])
                    . ' ' . escapeshellarg($c['user'] . '@' . $c['host'])
                    . ' ' . escapeshellarg($probe));
            });
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        $out = (string) $res['out'];
        if (empty($res['ok'])) {
            return ['ok' => false, 'message' => self::explain($out, $this->status($inst, $config))];
        }

        $found = [];
        foreach (['USER', 'HOST', 'PATH'] as $k) {
            if (preg_match('/TIKNIX_' . $k . '=(\S+)/', $out, $m)) $found[$k] = $m[1];
        }
        // Landing successfully in a directory we cannot write to is a failure the customer
        // would otherwise only meet halfway through their first real publish.
        if (($found['PATH'] ?? '') === 'missing')      return ['ok' => false, 'message' => 'Signed in fine, but the remote directory does not exist.'];
        if (($found['PATH'] ?? '') === 'not-writable') return ['ok' => false, 'message' => 'Signed in as ' . ($found['USER'] ?? '?') . ', but that user cannot write to the remote directory.'];

        $detail = ['Signed in as ' . ($found['USER'] ?? '?') . ' on ' . ($found['HOST'] ?? $c['host'])];
        if (isset($found['PATH'])) $detail[] = 'Remote directory is writable';
        return ['ok' => true, 'message' => 'Connection works.', 'detail' => $detail];
    }

    // ---- shared helpers ------------------------------------------------------

    /**
     * The connection row holding this instance's sealed key for this driver, creating the
     * keypair on first use.
     *
     * Scoped by instance and driver only — NOT by member — because the caller is the
     * instance itself via its broker key, not a logged-in person.
     */
    protected static function keyConnection(object $inst, string $driverKey, bool $create = true) {
        // Scoping (and enabled/revoked) via ConnectionStore -- see its docblock.
        $conn = \app\ConnectionStore::forInstall((int) $inst->id, $driverKey);
        if ($conn) return $conn;
        if (!$create) return null;

        $kp = SshKey::generate('tiknix-publish:' . $inst->slug . ':' . $driverKey);
        if (empty($kp['ok'])) return null;

        // Into the INSTANCE's store, not core's. This dispensed against whatever
        // database was selected -- core's -- while the read above came from the
        // instance, so the key was written where the reader never looked and a fresh
        // keypair was minted on EVERY publish. The customer's authorized_keys entry
        // stopped matching the moment it was added.
        //
        // SshKey::seal is deliberately kept: these are core-minted deploy keys and
        // core has to be able to use them. What changes is only WHERE the row lives.
        \app\ConnectionStore::withInstall((int) $inst->id, function () use ($inst, $driverKey, $kp) {
            $conn = Bean::dispense('connections');
            $conn->connectorType = $driverKey;
            $conn->environment   = 'production';
            $conn->enabled       = 1;
            $conn->authType      = \app\ConnectionStore::AUTH_SEALED;
            $conn->accessToken   = SshKey::seal((string) $kp['private']);
            $conn->metadataJson  = json_encode([
                'driver'      => $driverKey,
                'public_key'  => (string) $kp['public'],
                'fingerprint' => (string) $kp['fingerprint'],
            ]);
            $conn->createdAt = date('Y-m-d H:i:s');
            return (int) Bean::store($conn);
        }, 0, true);

        // Re-read through the same door every other caller uses, so a failed write
        // surfaces here as "no connection" rather than as a bean that looks stored.
        return \app\ConnectionStore::forInstall((int) $inst->id, $driverKey);
    }

    /**
     * Record the outcome so the card and the next status() agree with what happened.
     *
     * The bean came from forInstall() and is READ-ONLY: RedBean stores to the database
     * selected at store() time, so saving it here wrote core's table. Re-open the
     * instance's store and update it there.
     */
    protected static function record($conn, object $inst, bool $ok, ?string $error): void {
        if (!$conn) return;
        $id = (int) $conn->id;
        if ($id <= 0) return;

        \app\ConnectionStore::withInstall((int) $inst->id, function () use ($id, $ok, $error) {
            $row = Bean::load('connections', $id);
            if (!$row->id) return false;
            $row->lastUsedAt = date('Y-m-d H:i:s');
            $row->lastError  = $ok ? null : $error;
            Bean::store($row);
            return true;
        }, false, true);
    }

    /**
     * ssh options common to every invocation.
     *
     * StrictHostKeyChecking=accept-new trusts a host's key the FIRST time and pins it
     * after: `no` would accept a changed key silently, which is exactly the case worth
     * refusing, and `yes` cannot work at all without a pre-seeded known_hosts. The
     * known_hosts file is per instance so one customer's host key cannot shadow another's.
     */
    protected static function sshOpts(string $keyFile, object $inst, int $port): string {
        $known = self::knownHostsFile($inst);
        return '-i ' . escapeshellarg($keyFile)
             . ' -o IdentitiesOnly=yes'
             . ' -o StrictHostKeyChecking=accept-new'
             . ' -o UserKnownHostsFile=' . escapeshellarg($known)
             . ' -o ConnectTimeout=' . self::CONNECT_TIMEOUT
             . ' -o BatchMode=yes'                       // never block on a prompt
             . ' -p ' . (int) $port;
    }

    protected static function knownHostsFile(object $inst): string {
        $dir = sys_get_temp_dir() . '/tiknix-known-hosts';
        if (!is_dir($dir)) @mkdir($dir, 0700, true);
        $f = $dir . '/' . preg_replace('/[^a-z0-9_-]/i', '', (string) $inst->slug);
        if (!is_file($f)) { @touch($f); @chmod($f, 0600); }
        return $f;
    }

    /** Validate the connection settings shared by every ssh target. */
    protected static function connection(array $config): array {
        $host = strtolower(trim((string) ($config['host'] ?? '')));
        $user = trim((string) ($config['user'] ?? ''));
        $port = (int) ($config['port'] ?? 22) ?: 22;

        if ($host === '' || !preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/', $host) || strpos($host, '..') !== false) {
            return ['ok' => false, 'error' => 'A valid host is required.'];
        }
        // Anchored: the user is interpolated into a shell command, and a permissive
        // pattern here is the difference between a deploy and an injection.
        if (!preg_match('/^[a-z_][a-z0-9_-]{0,31}$/i', $user)) {
            return ['ok' => false, 'error' => 'A valid SSH user is required.'];
        }
        if ($port < 1 || $port > 65535) return ['ok' => false, 'error' => 'Invalid port.'];

        return ['ok' => true, 'host' => $host, 'user' => $user, 'port' => $port];
    }

    /** An absolute, traversal-free remote path. */
    protected static function remotePath(array $config, string $field = 'path'): array {
        $path = trim((string) ($config[$field] ?? ''));
        if ($path === '' || $path[0] !== '/' || strpos($path, '..') !== false) {
            return ['ok' => false, 'error' => 'The remote path must be absolute and contain no "..".'];
        }
        return ['ok' => true, 'path' => rtrim($path, '/')];
    }

    /** The instance's working directory on this control plane. */
    protected static function instanceDir(object $inst): string {
        // Was a hard-coded '.tiknix'; the row carries the namespace.
        return $inst->dir();
    }

    /** Run a command with a wall-clock cap; returns [ok, output]. */
    protected static function run(string $cmd): array {
        $lines = []; $code = 0;
        exec('timeout ' . self::RUN_TIMEOUT . ' ' . $cmd . ' 2>&1', $lines, $code);
        return ['ok' => $code === 0, 'out' => trim(implode("\n", $lines)), 'code' => $code];
    }

    /**
     * Turn a failed ssh/rsync into something the operator can act on. A bare
     * "Permission denied (publickey)" tells them nothing about what to do next.
     */
    protected static function explain(string $out, array $status): string {
        if (stripos($out, 'permission denied') !== false || stripos($out, 'publickey') !== false) {
            $pk = (string) ($status['publicKey'] ?? '');
            // First line stands alone: it is what gets recorded on the connection and
            // shown on the card, where a wrapped public key is noise rather than help.
            return 'The server rejected our key — add this target\'s public key to ~/.ssh/authorized_keys for that user, then publish again.'
                 . ($pk !== '' ? "\n" . $pk : '');
        }
        if (stripos($out, 'host key verification failed') !== false) {
            return 'The server presented a DIFFERENT host key than last time. This is refused on purpose — verify the server before publishing again.';
        }
        return $out !== '' ? $out : 'The command failed with no output.';
    }
}
