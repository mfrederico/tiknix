<?php
/**
 * ProjectSkills — a project's skills and Claude Code plugins, managed where its agents run.
 *
 * Both are features of the CLI every agent of a project runs through, whatever model is
 * behind it, and both live in the project's container:
 *
 *   skills   <app>/.claude/skills/<name>/SKILL.md — instructions an agent loads when a task
 *            matches the skill's description. Code-like, so they are COMMITTED to the app as
 *            the member who added them (TenantFiles), and a task's worktree has them. A skill
 *            an enabled Tiknix plugin installed (the runtime's ledger,
 *            .claude/skills/.tiknix-managed.json) is listed and never touched from here.
 *   plugins  bundles from a Claude Code marketplace (skills, commands, agents, MCP servers),
 *            installed with the app's own `bin/claude plugin …` at user scope in the agents'
 *            HOME (<app>/.aibuilder/home), which every agent of the project shares. State,
 *            not code: nothing is committed.
 *
 * Every call is an SSH into the container (TenantHost). Names are validated here before they
 * reach a shell; the CLI's own answer — success or its refusal — is what comes back. A plugin
 * whose install runs a marketplace-declared command is NOT auto-accepted: the CLI refuses
 * without -y, and that refusal is shown.
 */
namespace app;

class ProjectSkills {

    public const NAME_RE    = '/^[a-z][a-z0-9-]{1,62}$/D';
    public const PLUGIN_RE  = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,80}@[A-Za-z0-9][A-Za-z0-9._-]{0,80}$/D';
    /** owner/repo, or an https URL to a git repository or a marketplace.json */
    public const SOURCE_RE  = '#^(?:[A-Za-z0-9][A-Za-z0-9._-]{0,60}/[A-Za-z0-9][A-Za-z0-9._-]{0,80}|https://[A-Za-z0-9.-]+(?:/[A-Za-z0-9._~%/-]*)?)$#D';
    public const MAX_SKILL_BYTES = 60000;

    private const CLI = 'cd /srv/app && export HOME=/srv/app/.aibuilder/home && ./bin/claude';

    public function __construct(private object $inst) {
        if (!\Model_Instance::tenantRow($inst)) throw new \InvalidArgumentException("{$inst->slug} is not in a container");
    }

    // ---- skills ---------------------------------------------------------------------

    /** @return array<int,array{name:string,description:string,managed:bool,bytes:int}> */
    public function skills(): array {
        [$c, $o] = TenantHost::ssh($this->inst, 'app', 'cd /srv/app && php /dev/stdin', self::SKILL_READER, 30);
        $d = json_decode((string) $o, true);
        if ($c !== 0 || !is_array($d)) throw new \RuntimeException("could not list {$this->inst->slug}'s skills: " . mb_substr(trim((string) $o), 0, 200));
        return $d;
    }

    private const SKILL_READER = <<<'PHP'
<?php
$base = '.claude/skills'; $out = [];
$ledger = is_file("$base/.tiknix-managed.json") ? (json_decode((string) file_get_contents("$base/.tiknix-managed.json"), true) ?: []) : [];
foreach (glob("$base/*/SKILL.md") ?: [] as $f) {
    $name = basename(dirname($f)); $txt = (string) file_get_contents($f); $desc = '';
    if (preg_match('/^---\s*\n(.*?)\n---/s', $txt, $m) && preg_match('/^description:\s*(.+)$/m', $m[1], $d)) $desc = trim($d[1], " \t\"'");
    $out[] = ['name' => $name, 'description' => mb_substr($desc, 0, 300), 'managed' => in_array($name, $ledger, true), 'bytes' => strlen($txt)];
}
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
PHP;

    /** The SKILL.md text for one skill, or null. */
    public function skillText(string $name): ?string {
        self::skillName($name);
        return (new TenantFiles($this->inst))->read(".claude/skills/{$name}/SKILL.md");
    }

    /**
     * Add or replace a skill: its SKILL.md is composed from the name, the one-line description
     * (what an agent matches a task against — required) and the body, and committed as $memberId.
     */
    public function saveSkill(string $name, string $description, string $body, int $memberId): void {
        self::skillName($name);
        $description = trim(preg_replace('/\s+/', ' ', $description));
        if ($description === '') throw new \InvalidArgumentException('a skill needs a description: it is what an agent matches a task against to decide whether to load the skill');
        if (mb_strlen($description) > 300) throw new \InvalidArgumentException('keep the description to 300 characters — it is loaded into every session');
        $body = trim(str_replace("\r\n", "\n", $body));
        if ($body === '') throw new \InvalidArgumentException('a skill needs instructions');
        if ($this->isManaged($name)) throw new \RuntimeException("'{$name}' is a skill an enabled Tiknix plugin installed; it is managed with that plugin and cannot be changed here");
        $text = "---\nname: {$name}\ndescription: " . json_encode($description, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n---\n\n{$body}\n";
        if (strlen($text) > self::MAX_SKILL_BYTES) throw new \InvalidArgumentException('the skill is over ' . (int) (self::MAX_SKILL_BYTES / 1000) . ' KB');
        (new TenantFiles($this->inst))->write(".claude/skills/{$name}/SKILL.md", $text, $memberId, "Skill {$name} saved from MCP services");
    }

    public function removeSkill(string $name, int $memberId): void {
        self::skillName($name);
        if ($this->isManaged($name)) throw new \RuntimeException("'{$name}' is a skill an enabled Tiknix plugin installed; switch that plugin off to remove it");
        $files = new TenantFiles($this->inst);
        if (!$files->exists(".claude/skills/{$name}/SKILL.md")) throw new \RuntimeException("this project has no skill named '{$name}'");
        $files->remove(".claude/skills/{$name}/SKILL.md", $memberId, "Skill {$name} removed from MCP services");
    }

    private function isManaged(string $name): bool {
        foreach ($this->skills() as $s) if ($s['name'] === $name) return $s['managed'];
        return false;
    }

    private static function skillName(string $name): void {
        if (!preg_match(self::NAME_RE, $name)) throw new \InvalidArgumentException('a skill name is lowercase letters, digits and dashes (2–63 characters), e.g. release-notes');
    }

    // ---- plugins --------------------------------------------------------------------

    /**
     * What is installed, what the configured marketplaces offer, and the marketplaces.
     * @return array{installed:array,available:array,marketplaces:array}
     */
    public function plugins(bool $withAvailable = true): array {
        $r = $this->cliJson('plugin list --json' . ($withAvailable ? ' --available' : ''), 90);
        $m = $this->cliJson('plugin marketplace list --json', 60);
        $installed = array_values((array) ($withAvailable ? ($r['installed'] ?? []) : $r));
        $available = [];
        foreach ((array) ($r['available'] ?? []) as $p) {
            $available[] = ['id' => (string) ($p['pluginId'] ?? ''), 'name' => (string) ($p['name'] ?? ''), 'marketplace' => (string) ($p['marketplaceName'] ?? ''),
                            'description' => mb_substr((string) ($p['description'] ?? ''), 0, 400), 'installs' => (int) ($p['installCount'] ?? 0)];
        }
        return ['installed' => $installed, 'available' => $available, 'marketplaces' => array_values($m)];
    }

    /** Install for every agent of the project. Returns the CLI's own message. */
    public function installPlugin(string $id): string {
        if (!preg_match(self::PLUGIN_RE, $id)) throw new \InvalidArgumentException('a plugin is named plugin@marketplace');
        return $this->cliDo('plugin install ' . escapeshellarg($id) . ' -s user --json', 300);
    }

    public function removePlugin(string $id): string {
        if (!preg_match(self::PLUGIN_RE, $id)) throw new \InvalidArgumentException('a plugin is named plugin@marketplace');
        return $this->cliDo('plugin uninstall ' . escapeshellarg($id) . ' --json', 120);
    }

    /** Add a marketplace: owner/repo on GitHub, or an https URL. */
    public function addMarketplace(string $source): string {
        $source = trim($source);
        if (!preg_match(self::SOURCE_RE, $source)) throw new \InvalidArgumentException('a marketplace is a GitHub repository (owner/repo) or an https:// URL');
        return $this->cliDo('plugin marketplace add ' . escapeshellarg($source), 180);
    }

    public function removeMarketplace(string $name): string {
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,80}$/', $name)) throw new \InvalidArgumentException('not a marketplace name');
        return $this->cliDo('plugin marketplace remove ' . escapeshellarg($name), 60);
    }

    private function cliJson(string $args, int $timeout): array {
        [$c, $o] = TenantHost::ssh($this->inst, 'app', self::CLI . ' ' . $args . ' 2>/tmp/claude-plugin.err; rc=$?; [ $rc -ne 0 ] && head -c 400 /tmp/claude-plugin.err >&2; exit $rc', null, $timeout);
        $d = json_decode((string) $o, true);
        if ($c !== 0 || !is_array($d)) throw new \RuntimeException("the project's agent program did not answer `claude {$args}` (exit {$c}): " . mb_substr(trim((string) $o), 0, 300));
        return $d;
    }

    /** Run a changing command; its message on success, a RuntimeException carrying its own words otherwise. */
    private function cliDo(string $args, int $timeout): string {
        [$c, $o] = TenantHost::ssh($this->inst, 'app', self::CLI . ' ' . $args . ' 2>&1', null, $timeout);
        $out = trim((string) $o);
        $j = json_decode($out, true);
        // Not JSON: the CLI prints its progress, then its verdict — the verdict is the last line.
        $lines = array_values(array_filter(array_map('trim', explode("\n", $out)), fn($l) => $l !== ''));
        $msg = is_array($j) ? (string) ($j['message'] ?? $j['error'] ?? $out) : ($c === 0 ? (string) (end($lines) ?: '') : $out);
        if ($c !== 0 || (is_array($j) && (($j['ok'] ?? $j['success'] ?? true) === false))) throw new \RuntimeException(mb_substr($msg !== '' ? $msg : "exit {$c}", 0, 600));
        return mb_substr($msg, 0, 600);
    }
}
