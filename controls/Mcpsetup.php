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
