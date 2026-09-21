<?php
/**
 * ClaudeBinary — an instance's own <root>/bin/claude.
 *
 * A pipeline `agent` step runs the instance's OWN claude, as the instance's user, with the
 * instance's credentials (see Pipeline\Steps\AgentStep). That binary is <root>/bin/claude.
 *
 * It used to be a SYMLINK to the operator's install, /home/ubuntu/.local/bin/claude — itself
 * a symlink into ~/.local/share/claude/versions/<n>. Two things are wrong with pointing out
 * of the instance at an absolute host path:
 *
 *   1. It dangles anywhere /home/ubuntu is not mounted — and the AI Builder sandbox
 *      (capricorn's jail-run.sh) is exactly such a place. Its own comment says so: "the
 *      launcher symlink targets an absolute operator path and would dangle". Every pipeline
 *      run the builder agent started from inside its sandbox died with
 *      "bin/claude: No such file or directory", while the same pipeline started from the web
 *      UI, a minute either side, completed. It read as flaky; it was 100% determined by who
 *      dispatched the run.
 *   2. Claude updates itself by re-pointing ~/.local/bin/claude and deleting old versions, so
 *      a second-hand link can be left pointing at a file that is no longer there.
 *
 * A HARD LINK has neither problem. It is a real file inside the instance tree — visible in
 * the sandbox, which bind-mounts that tree, and to the isolated pool user — it costs no disk,
 * and the inode it names survives the original being unlinked by an update. The price is that
 * it pins a version until it is re-linked: that is what relink() is for, and an old claude
 * still runs.
 *
 * Hard links cannot cross filesystems. Where the install is on another one (a tenant whose
 * claude is a system package, say) there is nothing to dangle in the first place, and a
 * symlink to that system path is correct; link() does that and says which it did. It never
 * copies — 230 MB per instance, silently stale, is not a default anyone chose.
 */

namespace app;

class ClaudeBinary {

    public const REL = 'bin/claude';

    /**
     * The operator's claude on this host, fully resolved to the real file — or null.
     * Resolved the way jail-run.sh does it, because a provisioning shell often has a minimal
     * PATH with no ~/.local/bin on it.
     */
    public static function hostBinary(): ?string {
        $candidates = [trim((string) @shell_exec('command -v claude 2>/dev/null'))];
        $home = (string) getenv('HOME');
        if ($home !== '') $candidates[] = $home . '/.local/bin/claude';
        $candidates[] = '/usr/local/bin/claude';
        $candidates[] = '/usr/bin/claude';
        foreach ($candidates as $c) {
            if ($c === '' || !is_executable($c)) continue;
            $real = realpath($c);
            if ($real !== false && is_file($real)) return $real;
        }
        return null;
    }

    /**
     * Make sure git will never pick bin/claude up.
     *
     * As a symlink it was a few bytes, and at least one instance committed it. As a hard link
     * it is the whole binary — ~230 MB — sitting at a path that nothing ignored: the next
     * `git add -A` would put it in the project's repository, which bloats every clone and
     * fails any push to a host with a 100 MB file limit. It is a machine-local artifact, like
     * vendor/.
     *
     * Ignored through .git/info/exclude: local to this clone, shared by its worktrees, and no
     * tracked file changes, so nothing needs committing in the project to be safe. A path git
     * already TRACKS is not affected by any ignore rule, so that is refused, with the command
     * that fixes it — quietly linking there would stage a 230 MB type-change.
     */
    private static function keepOutOfGit(string $root): void {
        if (!is_dir($root . '/.git')) return;   // not a clone (or a worktree, which shares its parent's exclude)
        $git = 'git -C ' . escapeshellarg($root) . ' ';

        exec($git . 'ls-files --error-unmatch -- ' . escapeshellarg(self::REL) . ' 2>/dev/null', $o, $tracked);
        if ($tracked === 0) {
            throw new \RuntimeException(
                'ClaudeBinary: ' . self::REL . " is TRACKED by git in {$root}, so no ignore rule applies to it and a hard link "
              . "would stage a ~230 MB binary. Untrack it first, on that project's branch:\n"
              . "    git -C {$root} rm --cached " . self::REL . " && git -C {$root} commit -m 'Stop tracking bin/claude (machine-local)'");
        }
        exec($git . 'check-ignore -q -- ' . escapeshellarg(self::REL) . ' 2>/dev/null', $o2, $ignored);
        if ($ignored === 0) return;

        $exclude = $root . '/.git/info/exclude';
        if (!is_dir(dirname($exclude)) && !@mkdir(dirname($exclude), 0775, true)) {
            throw new \RuntimeException("ClaudeBinary: could not create " . dirname($exclude) . '.');
        }
        $rule = "\n# machine-local: a hard link to the host's claude binary (~230 MB). See lib/ClaudeBinary.php.\n/" . self::REL . "\n";
        if (@file_put_contents($exclude, $rule, FILE_APPEND) === false) {
            throw new \RuntimeException("ClaudeBinary: could not write {$exclude}, so " . self::REL . ' would not be ignored. Not linking.');
        }
    }

    /**
     * What <root>/bin/claude is right now.
     *
     * @return array{state:string,path:string,target:?string,inode:?int,detail:string}
     *         state: missing | hardlink | file | symlink | dangling
     */
    public static function status(string $root): array {
        $path = rtrim($root, '/') . '/' . self::REL;
        $out = ['state' => 'missing', 'path' => $path, 'target' => null, 'inode' => null, 'detail' => 'not installed'];
        if (is_link($path)) {
            $out['target'] = (string) readlink($path);
            if (!file_exists($path)) {   // file_exists() follows the link; is_link() does not
                return ['state' => 'dangling', 'detail' => "symlink to {$out['target']}, which does not exist from here"] + $out;
            }
            return ['state' => 'symlink', 'inode' => (int) fileinode($path), 'detail' => "symlink to {$out['target']}"] + $out;
        }
        if (is_file($path)) {
            $st = stat($path);
            $hard = $st !== false && $st['nlink'] > 1;
            return ['state' => $hard ? 'hardlink' : 'file', 'inode' => $st === false ? null : (int) $st['ino'],
                    'detail' => $hard ? 'hard link (shares the host install\'s inode)' : 'a regular file of its own'] + $out;
        }
        return $out;
    }

    /**
     * Install or refresh <root>/bin/claude from the host binary. Idempotent: already the same
     * inode → nothing to do.
     *
     * @return array{action:string,detail:string}  action: unchanged | hardlinked | symlinked
     */
    public static function link(string $root, ?string $hostBinary = null): array {
        $root = rtrim($root, '/');
        $host = $hostBinary ?? self::hostBinary();
        if ($host === null || !is_file($host)) {
            throw new \RuntimeException(
                'ClaudeBinary: no claude binary found on this host (looked on PATH, $HOME/.local/bin, /usr/local/bin, '
              . '/usr/bin). Pipeline agent steps need one at ' . self::REL . '.');
        }
        $path = $root . '/' . self::REL;
        $dir = dirname($path);
        self::keepOutOfGit($root);   // BEFORE a 230 MB file exists at a path git might add
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
            throw new \RuntimeException("ClaudeBinary: could not create {$dir}.");
        }
        if (is_file($path) && !is_link($path) && fileinode($path) === fileinode($host)) {
            return ['action' => 'unchanged', 'detail' => 'already a hard link to ' . $host];
        }

        // Build beside it and rename over: a run that starts mid-relink must find a working
        // binary, never a gap.
        $tmp = $path . '.new-' . bin2hex(random_bytes(3));
        if (@link($host, $tmp)) {
            if (!@rename($tmp, $path)) { @unlink($tmp); throw new \RuntimeException("ClaudeBinary: could not move the new link into place at {$path}."); }
            return ['action' => 'hardlinked', 'detail' => "{$path} => {$host} (same inode)"];
        }
        // link() failed. Across filesystems that is expected and a symlink is right — the
        // target is a real system path, not the operator's home. Anything else is a fault.
        $hostDev = @stat($host)['dev'] ?? null;
        $dirDev  = @stat($dir)['dev'] ?? null;
        if ($hostDev === null || $dirDev === null || $hostDev === $dirDev) {
            throw new \RuntimeException(
                "ClaudeBinary: could not hard-link {$host} to {$path}, and they are on the same filesystem, so it "
              . 'should have worked (check ownership and fs.protected_hardlinks).');
        }
        if (!@symlink($host, $tmp) || !@rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException("ClaudeBinary: could not symlink {$host} at {$path}.");
        }
        return ['action' => 'symlinked', 'detail' => "{$path} -> {$host} (different filesystem, so a hard link is not possible)"];
    }
}
