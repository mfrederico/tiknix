<?php
/**
 * SshDriver — run a deploy command on a server the customer owns.
 *
 * The counterpart to rsync rather than a competitor to it: rsync gets the files there,
 * this makes the server act on them — `git pull && composer install --no-dev`, a restart,
 * a migration. Plenty of teams already have that command; this target runs it with a key
 * they authorised, from the same publish that ships the code.
 *
 * The command is the CUSTOMER'S and is run verbatim on their own server, so there is
 * nothing to sanitise inside it — it is passed as a single argument to ssh, which hands
 * it to their login shell. What is validated is everything AROUND it (host, user, port),
 * because those are interpolated into the command WE build.
 */
namespace app\Publish;

class SshDriver extends SshTargetDriver {

    public static function key(): string   { return 'ssh'; }
    public static function label(): string { return 'SSH command'; }

    public static function blurb(): string {
        return 'Runs a command on your own server over SSH — a pull, a build, a restart. Pair it with rsync, or use it alone when the server fetches its own code.';
    }

    public static function fields(): array {
        return array_merge(self::sshFields(), [
            ['name' => 'command', 'label' => 'Command', 'type' => 'textarea', 'required' => true,
             'placeholder' => 'cd /var/www/example.com && git pull && composer install --no-dev',
             'help' => 'Run verbatim on your server, as the user above. Non-zero exit fails the publish.'],
        ]);
    }

    public function deploy(object $inst, array $config, array $opts = []): array {
        $c = self::connection($config);
        if (empty($c['ok'])) return ['ok' => false, 'error' => (string) $c['error']];

        $command = trim((string) ($config['command'] ?? ''));
        if ($command === '') return ['ok' => false, 'error' => 'A command is required.'];

        $conn = self::keyConnection($inst, self::key());
        if (!$conn) return ['ok' => false, 'error' => 'Could not generate an SSH key for this target.'];

        try {
            $res = SshKey::withKeyFile((string) $conn->accessToken, function (string $keyFile) use ($c, $command, $inst) {
                $cmd = 'ssh ' . self::sshOpts($keyFile, $inst, (int) $c['port'])
                     . ' ' . escapeshellarg($c['user'] . '@' . $c['host'])
                     . ' ' . escapeshellarg($command);
                return self::run($cmd);
            });
        } catch (\Throwable $e) {
            self::record($conn, false, $e->getMessage());
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        $out = (string) $res['out'];
        if (empty($res['ok'])) {
            $why = self::explain($out, $this->status($inst, $config));
            self::record($conn, false, substr((string) strtok($why, "\n"), 0, 250));
            return ['ok' => false, 'error' => $why];
        }

        self::record($conn, true, null);
        // The remote output IS the useful part of this target — surface it in the run log
        // rather than a bare "ok", but keep it bounded so a chatty build cannot flood the
        // step record.
        $tail = $out === '' ? [] : array_slice(explode("\n", $out), -20);
        return [
            'ok'      => true,
            'message' => 'Ran on ' . $c['user'] . '@' . $c['host'],
            'steps'   => array_merge(['Ran the command on ' . $c['user'] . '@' . $c['host']], $tail),
        ];
    }
}
