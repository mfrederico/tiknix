<?php
/**
 * TenantFiles — the few files of a project an operator page edits by hand, in the project's
 * container: the agent's MCP servers (.mcp.json), its hooks (.claude/settings.json and
 * scripts/hooks/*.php) and the app's own MCP tools (mcptools/*Tool.php). Agent Setup and Hooks
 * on the control plane go through here; nothing on this host holds a project's tree any more.
 *
 * Every call is one SSH round trip as the app user in /srv/app. A write is COMMITTED there,
 * authored by the member making it — the app's git history is the record of what it runs, an
 * uncommitted file would hold its next update back (`--update` refuses a dirty tree), and the
 * history is the backup the old .bak/.deleted copies used to be.
 *
 * Only the paths named in ALLOWED are reachable: this is not a file manager for the container.
 * A container that does not answer throws — the page says so; it never shows an empty list as
 * if the project had nothing.
 */
namespace app;

final class TenantFiles {
    private const ALLOWED = [
        '~^\.mcp\.json$~',
        '~^\.claude/settings\.json$~',
        '~^mcptools/[A-Z][A-Za-z0-9]*Tool\.php$~',
        '~^mcptools/workbench/[A-Z][A-Za-z0-9]*Tool\.php$~',
        '~^scripts/hooks/[a-z][a-z0-9-]*\.php$~',
    ];

    public function __construct(private object $inst) {
        if (!\Model_Instance::tenantRow($inst)) throw new \InvalidArgumentException("{$inst->slug} is not in a container");
    }

    /** The file's contents, or null when it is not there. */
    public function read(string $rel): ?string {
        $this->vet($rel);
        [$code, $out] = $this->ssh('if [ -f ' . escapeshellarg($rel) . ' ]; then printf PRESENT; cat ' . escapeshellarg($rel) . '; else printf ABSENT; fi');
        if ($code !== 0) throw $this->refused("read {$rel}", $out);
        if (str_starts_with($out, 'ABSENT')) return null;
        if (!str_starts_with($out, 'PRESENT')) throw $this->refused("read {$rel}", $out);
        return substr($out, 7);
    }

    public function exists(string $rel): bool {
        return $this->read($rel) !== null;
    }

    /**
     * The files directly in $dir matching the shell $pattern (e.g. '*.php'), name-sorted.
     * @return array<int, array{file:string, mtime:int, size:int}>
     */
    public function list(string $dir, string $pattern): array {
        if (!preg_match('~^(mcptools|mcptools/workbench|scripts/hooks)$~', $dir)) throw new \InvalidArgumentException("not a listable project folder: {$dir}");
        if (!preg_match('~^[A-Za-z0-9*.-]+$~', $pattern)) throw new \InvalidArgumentException("not a file pattern: {$pattern}");
        [$code, $out] = $this->ssh('if [ -d ' . escapeshellarg($dir) . ' ]; then find ' . escapeshellarg($dir) . ' -maxdepth 1 -type f -name ' . escapeshellarg($pattern) . ' -printf "%f\t%T@\t%s\n"; fi');
        if ($code !== 0) throw $this->refused("list {$dir}", $out);
        $files = [];
        foreach (array_filter(explode("\n", $out)) as $line) {
            [$name, $mtime, $size] = array_pad(explode("\t", $line), 3, '0');
            $files[] = ['file' => $name, 'mtime' => (int) (float) $mtime, 'size' => (int) $size];
        }
        usort($files, fn($a, $b) => strcmp($a['file'], $b['file']));
        return $files;
    }

    /**
     * What the project already has of the catalog's kind — ConceptCatalog::inventory() for a
     * project whose tree is in its container: its concepts (concepts/<name>/) and its connector
     * manifests (connectors/<key>.json).
     * @return array{concepts:string[],connectors:string[]}
     */
    public function inventory(): array {
        [$code, $out] = $this->ssh('ls -1 concepts 2>/dev/null | grep -v "^\\." ; echo ---; ls -1 connectors 2>/dev/null | grep "\\.json$"; true');
        if ($code !== 0) throw $this->refused('list its plugins', $out);
        [$c, $k] = array_pad(explode("---\n", $out, 2), 2, '');
        return [
            'concepts'   => array_values(array_filter(array_map('trim', explode("\n", $c)), fn($n) => preg_match('/^[a-z][a-z0-9]*$/', $n))),
            'connectors' => array_values(array_filter(array_map(fn($n) => substr(trim($n), 0, -5), array_filter(explode("\n", $k))), fn($n) => $n !== '')),
        ];
    }

    /**
     * Write (create or replace) the file and commit it as the member. A file the app's own
     * .gitignore excludes (.mcp.json carries the project's key) is written and left
     * uncommitted — that is the app's rule, not a failure.
     */
    public function write(string $rel, string $content, int $memberId, string $why, bool $executable = false): void {
        $this->vet($rel);
        $q = escapeshellarg($rel);
        $cmd = 'mkdir -p ' . escapeshellarg(dirname($rel)) . ' && cat > ' . $q
             . ($executable ? ' && chmod 755 ' . $q : '')
             . " && (git check-ignore -q {$q} || git add -A {$q}) && " . $this->commit($memberId, $why);
        [$code, $out] = $this->ssh($cmd, $content);
        if ($code !== 0) throw $this->refused("write {$rel}", $out);
    }

    /** Remove the file and commit the removal as the member. */
    public function remove(string $rel, int $memberId, string $why): void {
        $this->vet($rel);
        $q = escapeshellarg($rel);
        [$code, $out] = $this->ssh("git rm -q -f --ignore-unmatch {$q} && rm -f {$q} && " . $this->commit($memberId, $why));
        if ($code !== 0) throw $this->refused("remove {$rel}", $out);
    }

    /** Commit what is staged as the member — or nothing is staged, which is not a failure. */
    private function commit(int $memberId, string $why): string {
        $env = TenantHost::gitEnv(TenantHost::author($memberId));
        return '(git diff --cached --quiet || ' . $env . 'git commit -q -m ' . escapeshellarg($why) . ')';
    }

    private function vet(string $rel): void {
        foreach (self::ALLOWED as $re) if (preg_match($re, $rel)) return;
        throw new \InvalidArgumentException("not a file Tiknix edits in a project: {$rel}");
    }

    private function ssh(string $command, ?string $stdin = null): array {
        return TenantHost::ssh($this->inst, 'app', 'cd /srv/app && ' . $command, $stdin, 60);
    }

    private function refused(string $what, string $out): \RuntimeException {
        $msg = "{$this->inst->slug}'s container could not {$what}: " . mb_substr(trim($out), 0, 300);
        error_log('ERROR TenantFiles ' . $msg);
        return new \RuntimeException($msg);
    }
}
