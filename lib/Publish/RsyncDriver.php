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

        // The project ships what its container runs (its HEAD — merge is publish), exported to
        // a temp dir on core for the length of this call.
        $tenant = \app\TenantBuilder::bySlug((string) $inst->slug);
        if (!$tenant) return ['ok' => false, 'error' => "{$inst->slug} is not in a container, so there is no code to export."];
        try { $dir = \app\TenantBuilder::exportTree($tenant); }
        catch (\Throwable $e) { return ['ok' => false, 'error' => 'Could not take the code from the project\'s container: ' . $e->getMessage()]; }
        try {
            return $this->ship($inst, $config, $c, $p, $dir);
        } finally {
            \app\TenantBuilder::removeExport($dir);
        }
    }

    /** rsync the snapshot of $dir to the target. */
    private function ship(object $inst, array $config, array $c, array $p, string $dir): array {
        $files = Snapshot::files($dir, !empty($inst->isDefault));
        if (!$files) return ['ok' => false, 'error' => 'Nothing to publish — the project has no tracked files.'];

        $conn = self::keyConnection($inst, self::key());
        if (!$conn) return ['ok' => false, 'error' => 'Could not generate an SSH key for this target.'];

        // --files-from needs the list on disk; NUL-separated so a filename with a newline
        // cannot inject an extra entry.
        $listFile = tempnam(sys_get_temp_dir(), 'tiknix-rsync-');
        file_put_contents($listFile, implode("\0", $files));

        try {
            $res = SshKey::withKeyFile((string) $conn->privateKey, function (string $keyFile) use ($c, $p, $dir, $listFile, $inst) {
                $rsh = 'ssh ' . self::sshOpts($keyFile, $inst, (int) $c['port']);
                $cmd = 'rsync -rlptz --safe-links'
                     . ' --files-from=' . escapeshellarg($listFile) . ' --from0'
                     . ' -e ' . escapeshellarg($rsh)
                     . ' ' . escapeshellarg($dir . '/')
                     . ' ' . escapeshellarg($c['user'] . '@' . $c['host'] . ':' . $p['path'] . '/');
                return self::run($cmd);
            });
        } catch (\Throwable $e) {
            self::record($conn, $inst, false, $e->getMessage());
            return ['ok' => false, 'error' => $e->getMessage()];
        } finally {
            @unlink($listFile);
        }

        if (empty($res['ok'])) {
            $why = self::explain((string) $res['out'], $this->status($inst, $config));
            self::record($conn, $inst, false, substr((string) strtok($why, "\n"), 0, 250));
            return ['ok' => false, 'error' => $why];
        }

        self::record($conn, $inst, true, null);
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
