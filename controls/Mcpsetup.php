<?php
/**
 * MCP services (was MCP services, at /mcpsetup — that URL redirects here)
 *
 * Central hub for a project's Claude Code agent configuration:
 * - MCP Servers  (the project's .mcp.json — added, changed and removed here)
 * - MCP Tools    (the project's own mcptools/*Tool.php — listed here, edited in the app)
 * - Claude Hooks (scripts/hooks/*.php and .claude/settings.json — edited at /hooks)
 *
 * The project lives in its container; every file here is read and written there through
 * app\TenantFiles, which commits each change as the member making it. Nothing on this host
 * holds a project's tree.
 */

namespace app;

use \Flight as Flight;
use \app\Feature;
use app\BaseControls\Control;

class Mcpsetup extends Control {

    /**
     * May this member open MCP services and manage MCP servers?
     *
     * The same `mcp` grant that governs API keys and the tool list, deliberately not a
     * flag of its own: this hub IS the MCP server/tool registry, so a separate switch
     * would create a half-granted state — able to mint a key, unable to see the servers
     * that key talks to.
     *
     * It replaces the ADMIN tier ONLY. The hooks and tools tabs stay ROOT's: scripts/hooks
     * holds security-sandbox.php, the PreToolUse control that confines agents. A grant is
     * permission to configure MCP, never a promotion to editing the thing that contains
     * the agents.
     */
    private function mayConfigure(): bool {
        return Feature::allows('mcp', (int) $this->member->id, (int) $this->member->level);
    }

    private function denyConfigure(): never {
        $this->forbid('Ungranted member attempted to reach MCP services', ['feature' => 'mcp']);
    }

    /** @var array{id:int,slug:string,name:string,dir:string,url:string,here:bool}|null the project this page configures */
    private ?array $project = null;
    private ?TenantFiles $files = null;

    /**
     * Point this page at the project it configures: the one selected in the header
     * (app\ProjectTarget) — never core's own tree. No selection → Projects. A selected
     * project that is not in a container cannot be configured from here, and the page says so.
     */
    private function bind(): bool {
        $this->project = \app\ProjectTarget::forMember((int) $this->member->id);
        if ($this->project === null) {
            $this->flash('info', 'Choose a project first — MCP services configures the selected project.');
            Flight::redirect('/projects');
            return false;
        }
        $inst = Bean::load('instance', (int) $this->project['id']);
        if (!$inst->id || !\Model_Instance::tenantRow($inst)) {
            $this->flash('error', "{$this->project['name']} is not running in a container, so its agent cannot be configured from here.");
            Flight::redirect('/projects');
            return false;
        }
        $this->files = new TenantFiles($inst);
        return true;
    }

    /**
     * Main tabbed interface
     */
    public function index($params = []) {
        if (!$this->mayConfigure()) { $this->denyConfigure(); return; }
        if (!$this->bind()) return;

        $activeTab = $this->getParam('tab', 'servers');
        $isRoot = ($this->viewData['member']['level'] ?? 100) <= 1;

        try {
            $servers = Mcp::serversFrom($this->project['url'], $this->mcpConfig());
            $systemServers = array_filter($servers, fn($s) => $s['source'] === 'system');
            $userServers   = array_filter($servers, fn($s) => $s['source'] !== 'system');

            // The project's own MCP tools (ROOT only): the files, named as the app's MCP serves
            // them (CamelCaseTool.php → camel_case). They are edited in the app itself.
            $tools = [];
            if ($isRoot) {
                foreach ($this->files->list('mcptools', '*Tool.php') as $f) {
                    $tools[] = [
                        'name'    => strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', substr($f['file'], 0, -8))),
                        'file'    => $f['file'],
                        'modTime' => $f['mtime'],
                    ];
                }
            }

            $hookFiles = [];
            $hookConfig = [];
            if ($isRoot) {
                foreach ($this->files->list('scripts/hooks', '*.php') as $f) {
                    $hookFiles[] = ['name' => substr($f['file'], 0, -4), 'file' => $f['file'], 'modTime' => $f['mtime'], 'size' => $f['size']];
                }
                $hookConfig = $this->loadSettings()['hooks'] ?? [];
            }
        } catch (\RuntimeException $e) {
            $this->flash('error', $e->getMessage());
            Flight::redirect('/projects');
            return;
        }

        $this->viewData['title'] = 'MCP services';
        $this->viewData['project'] = $this->project;
        $this->viewData['activeTab'] = $activeTab;
        $this->viewData['isRoot'] = $isRoot;
        $this->viewData['systemServers'] = $systemServers;
        // Is the app's own MCP server given to its agents? (runtime AgentMcp: a marker file in the app.)
        $this->viewData['tiknixOff'] = $this->tiknixOff();
        // The project's skills (its .claude/skills/); plugins load on demand — the listing is large.
        try { $this->viewData['skills'] = (new ProjectSkills($this->inst()))->skills(); $this->viewData['skillsError'] = ''; }
        catch (\RuntimeException $e) { $this->viewData['skills'] = []; $this->viewData['skillsError'] = $e->getMessage(); }
        $this->viewData['tiknixBreaks'] = self::TIKNIX_BREAKS;
        $this->viewData['userServers'] = $userServers;
        $this->viewData['tools'] = $tools;
        $this->viewData['hookFiles'] = $hookFiles;
        $this->viewData['hookConfig'] = $hookConfig;
        $this->viewData['csrf'] = SimpleCsrf::getTokenArray();

        $this->render('mcpsetup/index', $this->viewData);
    }

    // ==================== MCP SERVER ACTIONS ====================

    /** Flash a message and redirect back to an agent-setup tab (optionally its edit view). */
    private function flashTo(string $tab, string $type, string $message, ?string $edit = null): void {
        $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
        Flight::redirect('/mcpsetup?tab=' . $tab . ($edit !== null ? '&edit=' . urlencode($edit) : ''));
    }

    /** The project's .mcp.json as an array — {"mcpServers": {}} when it has none yet. */
    private function mcpConfig(): array {
        $raw = $this->files->read('.mcp.json');
        if ($raw === null) return ['mcpServers' => []];
        $cfg = json_decode($raw, true);
        if (!is_array($cfg)) throw new \RuntimeException("{$this->project['name']}'s .mcp.json is not valid JSON — fix it in the app before adding servers here.");
        if (!isset($cfg['mcpServers']) || !is_array($cfg['mcpServers'])) $cfg['mcpServers'] = [];
        return $cfg;
    }

    private function writeMcpConfig(array $cfg, string $why): void {
        if ($cfg['mcpServers'] === []) $cfg['mcpServers'] = (object) [];   // {} — the CLI reads [] as no config at all
        $this->files->write('.mcp.json', json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", (int) $this->member->id, $why);
    }

    /**
     * Add or update one server in the project's .mcp.json. The tiknix entry is the platform's
     * (regenerated with the project's own key) and is never written here. Provisioning's
     * regeneration merges, so servers added here survive it.
     */
    private function saveServer(string $mode, string $slug, array $config): void {
        try {
            $cfg = $this->mcpConfig();
            $exists = isset($cfg['mcpServers'][$slug]);
            if ($mode === 'add' && $exists) { $this->flashTo('servers', 'error', "A server named '{$slug}' already exists in {$this->project['name']}"); return; }
            if ($mode === 'update' && !$exists) { $this->flashTo('servers', 'error', "No server named '{$slug}' in {$this->project['name']}"); return; }
            $cfg['mcpServers'][$slug] = $config;
            $this->writeMcpConfig($cfg, "MCP services: MCP server {$slug} " . ($mode === 'add' ? 'added' : 'updated'));
        } catch (\RuntimeException $e) {
            $this->flashTo('servers', 'error', $e->getMessage()); return;
        }
        $this->logger->info('MCP services: MCP server ' . $mode, ['project' => $this->project['slug'], 'server' => $slug, 'member_id' => $this->member->id]);
        $this->flashTo('servers', 'success', 'Server ' . ($mode === 'add' ? 'added' : 'updated') . " in {$this->project['name']}: {$slug}");
    }

    /** Store new MCP server */
    public function storeServer($params = []) {
        if (!$this->mayConfigure()) { $this->denyConfigure(); return; }
        if (!$this->bind()) return;
        if (!$this->validatePost()) return;

        $slug = $this->sanitize($this->getParam('slug', ''));
        if (empty($slug) || !preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug)) { $this->flashTo('servers', 'error', 'Invalid server name'); return; }
        if (in_array($slug, ['tiknix', 'playwright'])) { $this->flashTo('servers', 'error', 'Cannot use reserved name'); return; }

        $this->saveServer('add', $slug, $this->buildServerConfig());
    }

    /** Update MCP server */
    public function updateServer($params = []) {
        if (!$this->mayConfigure()) { $this->denyConfigure(); return; }
        if (!$this->bind()) return;
        if (!$this->validatePost()) return;

        $slug = $this->sanitize($this->getParam('slug', ''));
        if (in_array($slug, ['tiknix'])) { $this->flashTo('servers', 'error', 'Cannot modify system server'); return; }

        $this->saveServer('update', $slug, $this->buildServerConfig());
    }

    /** Delete MCP server */
    public function deleteServer($params = []) {
        if (!$this->mayConfigure()) { $this->denyConfigure(); return; }
        if (!$this->bind()) return;
        if (!$this->validatePost()) return;

        $slug = $this->sanitize($this->getParam('slug', ''));
        if (in_array($slug, ['tiknix', 'playwright'])) { $this->flashTo('servers', 'error', 'Cannot delete system server'); return; }

        try {
            $cfg = $this->mcpConfig();
            if (!isset($cfg['mcpServers'][$slug])) { $this->flashTo('servers', 'error', "No server named '{$slug}' in {$this->project['name']}"); return; }
            unset($cfg['mcpServers'][$slug]);
            $this->writeMcpConfig($cfg, "MCP services: MCP server {$slug} removed");
        } catch (\RuntimeException $e) {
            $this->flashTo('servers', 'error', $e->getMessage()); return;
        }
        $this->logger->info('MCP services: MCP server removed', ['project' => $this->project['slug'], 'server' => $slug, 'member_id' => $this->member->id]);
        $this->flashTo('servers', 'success', "Server removed from {$this->project['name']}: {$slug}");
    }

    /**
     * POST /mcpsetup/test — can the project's agents reach their MCP servers? Asked IN the
     * project's container, where they run (app\McpProbe): the app's own server every build
     * task is given, and each server in its .mcp.json. JSON: {servers: {name: {ok, type,
     * tools, ms, error}}}. No key or header comes back — only the outcome.
     */
    public function test($params = []) {
        if (!$this->mayConfigure()) { Flight::jsonError('MCP is not enabled for your account.', 403); return; }
        $project = \app\ProjectTarget::forMember((int) $this->member->id);
        if ($project === null) { Flight::jsonError('Choose a project first.', 409); return; }
        if (!$this->validateCSRF()) return;
        $inst = Bean::load('instance', (int) $project['id']);
        if (!$inst->id || trim((string) $inst->ctIp) === '') { Flight::jsonError($project['name'] . ' is not running in its own container.', 409); return; }
        try {
            $servers = McpProbe::run($inst);
        } catch (\RuntimeException $e) {
            $this->logger->error('ERROR Mcpsetup::test: ' . $e->getMessage(), ['project' => $project['slug']]);
            Flight::jsonError($e->getMessage(), 502); return;
        }
        $bad = array_keys(array_filter($servers, fn($s) => empty($s['ok'])));
        $this->logger->info('MCP connectivity tested', ['project' => $project['slug'], 'servers' => count($servers), 'failing' => $bad, 'member_id' => (int) $this->member->id]);
        Flight::jsonSuccess(['project' => $project['name'], 'servers' => $servers]);
    }

    // ==================== THE APP'S OWN SERVER (danger zone) ====================
    //
    // Every agent of a project is given the app's own MCP server (runtime AgentMcp) unless the
    // project switches it off: a marker file, .aibuilder/mcp-tiknix.off, in the app. Switching
    // it off is the owner's right — and breaks planning — so it takes a typed confirmation and
    // is undone with one click.

    /** What stops working without it — the runtime's AgentMcp::whatBreaks(), said here before the app is asked. */
    public const TIKNIX_BREAKS = "Plans cannot be made (the planner hands its plan back through this server), and build agents lose the project's own tools: the codebase map, the logs, the schema, and every tool in mcptools/.";
    private const TIKNIX_OFF_FILE = '.aibuilder/mcp-tiknix.off';
    public const TIKNIX_CONFIRM = 'remove tiknix';

    private function inst(): object {
        $inst = Bean::load('instance', (int) $this->project['id']);
        if (!$inst->id || trim((string) $inst->ctIp) === '') throw new \RuntimeException($this->project['name'] . ' is not running in its own container.');
        return $inst;
    }

    private function tiknixOff(): bool {
        [$c, $o] = TenantHost::ssh($this->inst(), 'app', 'test -f /srv/app/' . self::TIKNIX_OFF_FILE . ' && echo off || echo on', null, 30);
        if ($c !== 0) throw new \RuntimeException("could not ask {$this->project['name']}'s container about its MCP server: " . trim((string) $o));
        return trim((string) $o) === 'off';
    }

    /** on|off, then the app reports at once so the Builder's banner follows. */
    private function setTiknix(bool $on): void {
        $who = 'member ' . (int) $this->member->id . ' at ' . date('c');
        $cmd = $on ? 'rm -f /srv/app/' . self::TIKNIX_OFF_FILE
                   : 'mkdir -p /srv/app/.aibuilder && printf %s ' . escapeshellarg($who) . ' > /srv/app/' . self::TIKNIX_OFF_FILE;
        [$c, $o] = TenantHost::ssh($this->inst(), 'app', $cmd . ' && cd /srv/app && php scripts/clitool.php --status-report --send --why=' . escapeshellarg('own MCP server switched ' . ($on ? 'on' : 'off')) . ' 2>&1 | tail -1', null, 90);
        if ($c !== 0) throw new \RuntimeException('the container refused: ' . trim((string) $o));
    }

    /** POST /mcpsetup/removeTiknix — switch the app's own MCP server off (typed confirmation). */
    public function removeTiknix($params = []) {
        if (!$this->mayConfigure()) { $this->denyConfigure(); return; }
        if (!$this->bind()) return;
        if (!$this->validatePost()) return;
        if (trim((string) $this->getParam('confirm', '')) !== self::TIKNIX_CONFIRM) { $this->flashTo('servers', 'error', 'Type "' . self::TIKNIX_CONFIRM . '" exactly to remove the tiknix server.'); return; }
        try { $this->setTiknix(false); } catch (\RuntimeException $e) { $this->flashTo('servers', 'error', $e->getMessage()); return; }
        $this->logger->warning('MCP services: the app\'s own MCP server switched OFF', ['project' => $this->project['slug'], 'member_id' => (int) $this->member->id]);
        $this->flashTo('servers', 'warning', "The tiknix server is removed from {$this->project['name']}'s agents. Plans cannot be made until it is restored.");
    }

    /** POST /mcpsetup/restoreTiknix — one click back. */
    public function restoreTiknix($params = []) {
        if (!$this->mayConfigure()) { $this->denyConfigure(); return; }
        if (!$this->bind()) return;
        if (!$this->validatePost()) return;
        try { $this->setTiknix(true); } catch (\RuntimeException $e) { $this->flashTo('servers', 'error', $e->getMessage()); return; }
        $this->logger->info('MCP services: the app\'s own MCP server restored', ['project' => $this->project['slug'], 'member_id' => (int) $this->member->id]);
        $this->flashTo('servers', 'success', "The tiknix server is restored for {$this->project['name']}'s agents.");
    }

    // ==================== SKILLS & PLUGINS (app\ProjectSkills) ====================

    /** The gate + project for a JSON route of this section; null = already answered. */
    private function skillsJson(bool $post = true): ?ProjectSkills {
        if (!$this->mayConfigure()) { Flight::jsonError('MCP is not enabled for your account.', 403); return null; }
        $this->project = \app\ProjectTarget::forMember((int) $this->member->id);
        if ($this->project === null) { Flight::jsonError('Choose a project first.', 409); return null; }
        if ($post && !$this->validateCSRF()) return null;
        try { return new ProjectSkills($this->inst()); }
        catch (\Throwable $e) { Flight::jsonError($e->getMessage(), 409); return null; }
    }

    /** Run one change, answer JSON: the CLI's or the library's own words either way. */
    private function skillsDo(callable $fn, string $what, array $ctx = []): void {
        try {
            $msg = (string) $fn();
            $this->logger->info("MCP services: {$what}", $ctx + ['project' => $this->project['slug'], 'member_id' => (int) $this->member->id]);
            Flight::jsonSuccess(['message' => $msg], $msg !== '' ? $msg : 'Done.');
        } catch (\InvalidArgumentException $e) {
            Flight::jsonError($e->getMessage(), 400);
        } catch (\RuntimeException $e) {
            $this->logger->error("ERROR MCP services: {$what} failed: " . $e->getMessage(), $ctx + ['project' => $this->project['slug']]);
            Flight::jsonError($e->getMessage(), 502);
        }
    }

    /** GET /mcpsetup/skill?name= — one skill's text, to edit. */
    public function skill($params = []) {
        if (!($ps = $this->skillsJson(false))) return;
        try { $t = $ps->skillText((string) $this->getParam('name', '')); }
        catch (\InvalidArgumentException $e) { Flight::jsonError($e->getMessage(), 400); return; }
        if ($t === null) { Flight::jsonError('No such skill.', 404); return; }
        $desc = preg_match('/^---\s*\n(.*?)\n---\s*\n?/s', $t, $m) && preg_match('/^description:\s*(.+)$/m', $m[1], $d) ? (json_decode(trim($d[1])) ?? trim($d[1], " \t\"'")) : '';
        Flight::jsonSuccess(['description' => (string) $desc, 'body' => ltrim((string) preg_replace('/^---\s*\n.*?\n---\s*\n?/s', '', $t))]);
    }

    /** POST /mcpsetup/skillSave — name, description, body. Committed to the project as this member. */
    public function skillSave($params = []) {
        if (!($ps = $this->skillsJson())) return;
        $name = strtolower(trim((string) $this->getParam('name', '')));
        $this->skillsDo(function () use ($ps, $name) {
            $ps->saveSkill($name, (string) $this->getParam('description', ''), (string) $this->getParam('body', ''), (int) $this->member->id);
            return "Skill '{$name}' saved to {$this->project['name']}.";
        }, 'skill saved', ['skill' => $name]);
    }

    /** POST /mcpsetup/skillDelete */
    public function skillDelete($params = []) {
        if (!($ps = $this->skillsJson())) return;
        $name = (string) $this->getParam('name', '');
        $this->skillsDo(function () use ($ps, $name) { $ps->removeSkill($name, (int) $this->member->id); return "Skill '{$name}' removed."; }, 'skill removed', ['skill' => $name]);
    }

    /** GET /mcpsetup/plugins — installed, available (every configured marketplace) and the marketplaces. */
    public function plugins($params = []) {
        if (!($ps = $this->skillsJson(false))) return;
        try { Flight::jsonSuccess($ps->plugins()); }
        catch (\RuntimeException $e) { $this->logger->error('ERROR MCP services: plugin listing failed: ' . $e->getMessage(), ['project' => $this->project['slug']]); Flight::jsonError($e->getMessage(), 502); }
    }

    /** POST /mcpsetup/pluginInstall — id = plugin@marketplace */
    public function pluginInstall($params = []) {
        if (!($ps = $this->skillsJson())) return;
        $id = trim((string) $this->getParam('id', ''));
        $this->skillsDo(fn() => $ps->installPlugin($id), 'plugin installed', ['plugin' => $id]);
    }

    /** POST /mcpsetup/pluginRemove */
    public function pluginRemove($params = []) {
        if (!($ps = $this->skillsJson())) return;
        $id = trim((string) $this->getParam('id', ''));
        $this->skillsDo(fn() => $ps->removePlugin($id), 'plugin removed', ['plugin' => $id]);
    }

    /** POST /mcpsetup/marketplaceAdd — source = owner/repo or https URL */
    public function marketplaceAdd($params = []) {
        if (!($ps = $this->skillsJson())) return;
        $src = trim((string) $this->getParam('source', ''));
        $this->skillsDo(fn() => $ps->addMarketplace($src), 'marketplace added', ['source' => $src]);
    }

    /** POST /mcpsetup/marketplaceRemove */
    public function marketplaceRemove($params = []) {
        if (!($ps = $this->skillsJson())) return;
        $name = trim((string) $this->getParam('name', ''));
        $this->skillsDo(fn() => $ps->removeMarketplace($name), 'marketplace removed', ['marketplace' => $name]);
    }

    // ==================== HELPERS ====================

    private function validatePost(): bool {
        if (Flight::request()->method !== 'POST') {
            Flight::redirect('/mcpsetup');
            return false;
        }
        if (!SimpleCsrf::validate()) {
            $_SESSION['flash'][] = ['type' => 'error', 'message' => 'CSRF validation failed'];
            Flight::redirect('/mcpsetup');
            return false;
        }
        return true;
    }

    private function buildServerConfig(): array {
        $type = $this->getParam('type', 'stdio');
        $config = ['type' => $type];

        if ($type === 'stdio') {
            $config['command'] = $this->getParam('command', '');
            $args = json_decode(($this->getParam('args', '[]')) ?? '', true);
            if (!empty($args)) $config['args'] = $args;
            $env = json_decode(($this->getParam('env', '{}')) ?? '', true);
            if (!empty($env)) $config['env'] = $env;
        } else {
            $config['url'] = $this->getParam('url', '');
            $headers = json_decode(($this->getParam('headers', '{}')) ?? '', true);
            if (!empty($headers)) $config['headers'] = $headers;
        }

        return $config;
    }

    private function loadSettings(): array {
        $raw = $this->files->read('.claude/settings.json');
        if ($raw === null) return ['hooks' => []];
        $settings = json_decode($raw, true);
        return is_array($settings) ? $settings : ['hooks' => []];
    }

}
