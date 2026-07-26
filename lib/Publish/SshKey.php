<?php
/**
 * SshKey — per-target SSH keypairs for publish drivers that reach a customer's own
 * server (ssh, rsync).
 *
 * ed25519, not ECDSA or RSA. It is the modern OpenSSH default: small keys, fast
 * verification, no curve-parameter or nonce footguns, and supported by every OpenSSH
 * since 6.5 (2014). RSA remains acceptable only where an ancient server forces it.
 *
 * CUSTODY. The private key is generated HERE and never leaves the control plane: it is
 * stored encrypted on the connection row and written to a mode-0600 temp file only for
 * the duration of a single ssh/rsync invocation. The customer receives the PUBLIC half
 * to add to authorized_keys — which is the whole point of using a keypair rather than
 * asking them to paste a password we would then have to hold.
 *
 * One keypair PER TARGET, never one shared key: revoking a customer's access must not
 * affect anyone else's, and a leaked key must have a blast radius of exactly one server.
 */
namespace app\Publish;

use app\EncryptionService;

class SshKey {

    /** Key type. See the class comment before changing this. */
    const TYPE = 'ed25519';

    /**
     * Generate a fresh keypair.
     *
     * Shells out to ssh-keygen rather than building the OpenSSH wire format by hand:
     * the private-key container is a non-trivial binary format, and getting it subtly
     * wrong yields a key that looks fine and fails at connect time.
     *
     * @return array{ok:bool, private?:string, public?:string, fingerprint?:string, error?:string}
     */
    public static function generate(string $comment = 'tiknix-publish'): array {
        $dir = sys_get_temp_dir() . '/tiknix-key-' . bin2hex(random_bytes(8));
        if (!@mkdir($dir, 0700)) return ['ok' => false, 'error' => 'could not create a temp directory'];
        $path = $dir . '/id';

        $cmd = 'ssh-keygen -t ' . escapeshellarg(self::TYPE)
             . ' -N ""'                                   // no passphrase: nothing could supply one at run time
             . ' -C ' . escapeshellarg($comment)
             . ' -f ' . escapeshellarg($path) . ' 2>&1';
        exec($cmd, $out, $code);

        $result = ['ok' => false, 'error' => 'ssh-keygen failed: ' . trim(implode(' ', $out))];
        if ($code === 0 && is_file($path) && is_file($path . '.pub')) {
            $fp = [];
            exec('ssh-keygen -lf ' . escapeshellarg($path . '.pub') . ' 2>/dev/null', $fp);
            $result = [
                'ok'          => true,
                'private'     => (string) file_get_contents($path),
                'public'      => trim((string) file_get_contents($path . '.pub')),
                'fingerprint' => trim((string) ($fp[0] ?? '')),
            ];
        }

        // Shred before unlinking: the private key was on disk, however briefly.
        foreach ([$path, $path . '.pub'] as $f) {
            if (is_file($f)) { @file_put_contents($f, str_repeat("\0", (int) @filesize($f) ?: 1)); @unlink($f); }
        }
        @rmdir($dir);
        return $result;
    }

    /** Encrypt a private key for storage on the connection row. */
    public static function seal(string $privateKey): string {
        return EncryptionService::encrypt($privateKey);
    }

    /**
     * Materialise the private key for ONE command, then remove it.
     *
     * The callback receives the path to a mode-0600 key file. The file is shredded
     * afterwards even if the callback throws — an aborted deploy must not leave a
     * usable private key in the temp directory.
     *
     * @param callable(string):mixed $fn
     * @return mixed whatever $fn returns
     */
    public static function withKeyFile(string $sealed, callable $fn) {
        $plain = EncryptionService::decrypt($sealed);
        $dir   = sys_get_temp_dir() . '/tiknix-key-' . bin2hex(random_bytes(8));
        if (!@mkdir($dir, 0700)) throw new \RuntimeException('could not create a temp directory for the key');
        $path = $dir . '/id';

        try {
            if (@file_put_contents($path, $plain) === false) throw new \RuntimeException('could not write the key file');
            @chmod($path, 0600);   // ssh refuses a key readable by anyone else
            return $fn($path);
        } finally {
            if (is_file($path)) { @file_put_contents($path, str_repeat("\0", strlen($plain) ?: 1)); @unlink($path); }
            @rmdir($dir);
        }
    }
}
