<?php
/**
 * RsyncDriver — copy the project to a directory on a server the customer owns.
 *
 * The file list is Snapshot's, not rsync's own --exclude rules, so an rsync publish ships
 * EXACTLY what a GitHub publish would: no database, no real conf/*.ini, no vendor/, no
 * .aibuilder, nothing .gitignore excludes. Two mechanisms, one definition of what is
 * publishable — the alternative is an exclude list that drifts and one day copies a
 * decrypted config onto someone else's server.
 *
 * --delete is deliberately NOT used. rsync's delete semantics against a live docroot are
 * how people lose uploads: anything the running application wrote — user uploads, caches,
 * a local .env — is by definition not in our file list and would be removed. Publishing
 * adds and updates; removing files is a decision that needs a human.
 */
namespace app\Publish;

class RsyncDriver extends SshTargetDriver {

    public static function key(): string   { return 'rsync'; }
    public static function label(): string { return 'rsync over SSH'; }

    public static function blurb(): string {
        return 'Copies this project into a directory on your own server over SSH. Ships exactly what a GitHub publish would — no database, no secrets, no vendor/. Existing files not in the project are left alone.';
    }

    public static function fields(): array {
        return array_merge(self::sshFields(), [
            ['name' => 'path', 'label' => 'Remote directory', 'type' => 'text', 'required' => true,
             'placeholder' => '/var/www/example.com', 'help' => 'Absolute path on the server. Its contents are updated, never deleted.'],
        ]);
    }

    public function deploy(object $inst, array $config, array $opts = []): array {
        $c = self::connection($config);
        if (empty($c['ok'])) return ['ok' => false, 'error' => (string) $c['error']];
        $p = self::remotePath($config);
        if (empty($p['ok'])) return ['ok' => false, 'error' => (string) $p['error']];

        $dir = self::instanceDir($inst);
        if (!is_dir($dir)) return ['ok' => false, 'error' => 'This project has no working directory on the control plane.'];

        $files = Snapshot::files($dir, !empty($inst->isDefault));
        if (!$files) return ['ok' => false, 'error' => 'Nothing to publish — the project has no tracked files.'];

        $conn = self::keyConnection($inst, self::key());
        if (!$conn) return ['ok' => false, 'error' => 'Could not generate an SSH key for this target.'];

        // --files-from needs the list on disk; NUL-separated so a filename with a newline
        // cannot inject an extra entry.
        $listFile = tempnam(sys_get_temp_dir(), 'tiknix-rsync-');
        file_put_contents($listFile, implode("\0", $files));

        try {
            $res = SshKey::withKeyFile((string) $conn->accessToken, function (string $keyFile) use ($c, $p, $dir, $listFile, $inst) {
                $rsh = 'ssh ' . self::sshOpts($keyFile, $inst, (int) $c['port']);
                $cmd = 'rsync -rlptz --safe-links'
                     . ' --files-from=' . escapeshellarg($listFile) . ' --from0'
                     . ' -e ' . escapeshellarg($rsh)
                     . ' ' . escapeshellarg($dir . '/')
                     . ' ' . escapeshellarg($c['user'] . '@' . $c['host'] . ':' . $p['path'] . '/');
                return self::run($cmd);
            });
        } catch (\Throwable $e) {
            self::record($conn, false, $e->getMessage());
            return ['ok' => false, 'error' => $e->getMessage()];
        } finally {
            @unlink($listFile);
        }

        if (empty($res['ok'])) {
            $why = self::explain((string) $res['out'], $this->status($inst, $config));
            self::record($conn, false, substr((string) strtok($why, "\n"), 0, 250));
            return ['ok' => false, 'error' => $why];
        }

        self::record($conn, true, null);
        return [
            'ok'      => true,
            'message' => 'Copied ' . count($files) . ' files to ' . $c['user'] . '@' . $c['host'] . ':' . $p['path'],
            'steps'   => [
                'Built a clean snapshot of the working tree (' . count($files) . ' files)',
                'Synced to ' . $c['user'] . '@' . $c['host'] . ':' . $p['path'],
            ],
        ];
    }
}
