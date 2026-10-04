<?php
/**
 * TenantFiles — what a project's tree holds, asked of its container: nothing on this host holds
 * a project's tree. One question is left here: what the project already has of the catalog's
 * kind, for a plugin install's plan.
 *
 * (It used to edit a project's .mcp.json, hooks and skills over SSH for the control plane's
 * pages. Those are pages of the app itself now — the runtime's AI agents page, app\ProjectFiles —
 * so the app writes its own files.)
 *
 * A container that does not answer throws — never an empty inventory as if the project had
 * nothing.
 */
namespace app;

final class TenantFiles {
    public function __construct(private object $inst) {
        if (!\Model_Instance::tenantRow($inst)) throw new \InvalidArgumentException("{$inst->slug} is not in a container");
    }

    /**
     * ConceptCatalog::inventory() for a project whose tree is in its container: its concepts
     * (concepts/<name>/) and its connector manifests (connectors/<key>.json).
     * @return array{concepts:string[],connectors:string[]}
     */
    public function inventory(): array {
        [$code, $out] = TenantHost::ssh($this->inst, 'app', 'cd /srv/app && ls -1 concepts 2>/dev/null | grep -v "^\\." ; echo ---; ls -1 connectors 2>/dev/null | grep "\\.json$"; true', null, 60);
        if ($code !== 0) {
            $msg = "{$this->inst->slug}'s container could not list its plugins: " . mb_substr(trim($out), 0, 300);
            error_log('ERROR TenantFiles ' . $msg);
            throw new \RuntimeException($msg);
        }
        [$c, $k] = array_pad(explode("---\n", $out, 2), 2, '');
        return [
            'concepts'   => array_values(array_filter(array_map('trim', explode("\n", $c)), fn($n) => preg_match('/^[a-z][a-z0-9]*$/', $n))),
            'connectors' => array_values(array_filter(array_map(fn($n) => substr(trim($n), 0, -5), array_filter(explode("\n", $k))), fn($n) => $n !== '')),
        ];
    }
}
