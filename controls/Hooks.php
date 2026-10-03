<?php
/**
 * Hooks Controller
 *
 * ROOT's editor for a project's Claude Code hooks:
 * - the hook scripts in its scripts/hooks/
 * - the hook configuration in its .claude/settings.json
 *
 * The project lives in its container: every read and write goes through app\TenantFiles,
 * which commits each change there as the member making it. The app's git history is the
 * backup (there are no .bak or .deleted copies any more).
 */

namespace app;

use \Flight as Flight;
use app\BaseControls\Control;

class Hooks extends Control {

    /** @var array|null the project whose hooks these are (app\ProjectTarget) */
    private ?array $project = null;
    private ?TenantFiles $files = null;

    /**
     * The selected project's hooks (the header's project) — the same rule as Agent Setup,
     * whose Hooks tab links here. Never core's own tree. No selection → Projects; a project
     * not in a container cannot be edited from here, and the page says so.
     */
    private function bind(): bool {
        $this->project = \app\ProjectTarget::forMember((int) $this->member->id);
        if ($this->project === null) {
            $this->flash('info', 'Choose a project first — hooks belong to the selected project.');
            Flight::redirect('/projects');
            return false;
        }
        $inst = Bean::load('instance', (int) $this->project['id']);
        if (!$inst->id || !\Model_Instance::tenantRow($inst)) {
            $this->flash('error', "{$this->project['name']} is not running in a container, so its hooks cannot be edited from here.");
            Flight::redirect('/projects');
            return false;
        }
        $this->files = new TenantFiles($inst);
        $this->viewData['project'] = $this->project;
        return true;
    }

    /** A POST with a valid CSRF token, or a redirect to $back. */
    private function validatePost(string $back): bool {
        if (Flight::request()->method !== 'POST') { Flight::redirect($back); return false; }
        if (!SimpleCsrf::validate()) { $this->flash('error', 'CSRF validation failed'); Flight::redirect($back); return false; }
        return true;
    }

    /** The container could not be reached or refused: say so and go back. */
    private function failed(\RuntimeException $e, string $back): void {
        $this->flash('error', $e->getMessage());
        Flight::redirect($back);
    }

    /**
     * List all hooks and their configuration
     */
    public function index($params = []) {
        if (!$this->requireLevel(LEVELS['ROOT'])) return;
        if (!$this->bind()) return;

        try {
            $files = [];
            foreach ($this->files->list('scripts/hooks', '*.php') as $f) {
                $files[] = ['name' => substr($f['file'], 0, -4), 'file' => $f['file'], 'modTime' => $f['mtime'], 'size' => $f['size']];
            }
            $hooks = $this->loadSettings()['hooks'] ?? [];
        } catch (\RuntimeException $e) { $this->failed($e, '/projects'); return; }

        $this->viewData['title'] = 'Claude Hooks';
        $this->viewData['files'] = $files;
        $this->viewData['hooks'] = $hooks;
        $this->viewData['csrf'] = SimpleCsrf::getTokenArray();

        $this->render('hooks/index', $this->viewData);
    }

    /**
     * Show create form for new hook
     */
    public function create($params = []) {
        if (!$this->requireLevel(LEVELS['ROOT'])) return;
        if (!$this->bind()) return;

        $this->viewData['title'] = 'Create Hook';
        $this->viewData['code'] = $this->getHookTemplate();
        $this->viewData['fileName'] = '';
        $this->viewData['isNew'] = true;
        $this->viewData['csrf'] = SimpleCsrf::getTokenArray();

        $this->render('hooks/editor', $this->viewData);
    }

    /**
     * Show edit form for existing hook
     */
    public function edit($params = []) {
        if (!$this->requireLevel(LEVELS['ROOT'])) return;
        if (!$this->bind()) return;

        $name = $this->sanitize($this->getParam('name', ''));
        if (!$this->hookName($name)) { Flight::redirect('/hooks'); return; }

        try { $code = $this->files->read("scripts/hooks/{$name}.php"); }
        catch (\RuntimeException $e) { $this->failed($e, '/hooks'); return; }
        if ($code === null) { $this->flash('error', 'Hook not found: ' . $name); Flight::redirect('/hooks'); return; }

        $this->viewData['title'] = 'Edit Hook: ' . $name;
        $this->viewData['code'] = $code;
        $this->viewData['fileName'] = $name . '.php';
        $this->viewData['hookName'] = $name;
        $this->viewData['isNew'] = false;
        $this->viewData['csrf'] = SimpleCsrf::getTokenArray();

        $this->render('hooks/editor', $this->viewData);
    }

    /**
     * Save new hook
     */
    public function store($params = []) {
        if (!$this->requireLevel(LEVELS['ROOT'])) return;
        if (!$this->bind()) return;
        if (!$this->validatePost('/hooks')) return;

        $code = $this->getParam('code', '');
        $fileName = $this->sanitize($this->getParam('file_name', ''));

        if (empty($code)) { $this->flash('error', 'Code is required'); Flight::redirect('/hooks/create'); return; }
        if (!preg_match('/^[a-z][a-z0-9-]*\.php$/', $fileName)) {
            $this->flash('error', 'File name must be lowercase with dashes ending in .php');
            Flight::redirect('/hooks/create');
            return;
        }

        $validation = PhpValidator::validateAll($code, 'hook');
        if (!empty($validation['errors'])) {
            $this->flash('error', 'Validation errors: ' . implode(', ', array_column($validation['errors'], 'message')));
            Flight::redirect('/hooks/create');
            return;
        }

        try {
            if ($this->files->exists("scripts/hooks/{$fileName}")) { $this->flash('error', 'Hook file already exists: ' . $fileName); Flight::redirect('/hooks/create'); return; }
            $this->files->write("scripts/hooks/{$fileName}", $code, (int) $this->member->id, "Hooks: {$fileName} created", true);
        } catch (\RuntimeException $e) { $this->failed($e, '/hooks/create'); return; }

        $this->flash('success', 'Hook created: ' . $fileName);
        $this->warnings($validation);
        Flight::redirect('/hooks');
    }

    /**
     * Update existing hook
     */
    public function update($params = []) {
        if (!$this->requireLevel(LEVELS['ROOT'])) return;
        if (!$this->bind()) return;
        if (!$this->validatePost('/hooks')) return;

        $code = $this->getParam('code', '');
        $name = $this->sanitize($this->getParam('name', ''));

        if (empty($code) || !$this->hookName($name)) { $this->flash('error', 'Code and hook name are required'); Flight::redirect('/hooks'); return; }

        $validation = PhpValidator::validateAll($code, 'hook');
        if (!empty($validation['errors'])) {
            $this->flash('error', 'Validation errors: ' . implode(', ', array_column($validation['errors'], 'message')));
            Flight::redirect('/hooks/edit?name=' . urlencode($name));
            return;
        }

        try {
            if (!$this->files->exists("scripts/hooks/{$name}.php")) { $this->flash('error', 'Hook not found: ' . $name); Flight::redirect('/hooks'); return; }
            $this->files->write("scripts/hooks/{$name}.php", $code, (int) $this->member->id, "Hooks: {$name}.php updated", true);
        } catch (\RuntimeException $e) { $this->failed($e, '/hooks/edit?name=' . urlencode($name)); return; }

        $this->flash('success', 'Hook updated: ' . $name);
        $this->warnings($validation);
        Flight::redirect('/hooks');
    }

    /**
     * Delete hook file
     */
    public function delete($params = []) {
        if (!$this->requireLevel(LEVELS['ROOT'])) return;
        if (!$this->bind()) return;
        if (!$this->validatePost('/hooks')) return;

        $name = $this->sanitize($this->getParam('name', ''));
        if (!$this->hookName($name)) { $this->flash('error', 'Hook name is required'); Flight::redirect('/hooks'); return; }

        try {
            if (!$this->files->exists("scripts/hooks/{$name}.php")) { $this->flash('error', 'Hook not found'); Flight::redirect('/hooks'); return; }
            $this->files->remove("scripts/hooks/{$name}.php", (int) $this->member->id, "Hooks: {$name}.php removed");
        } catch (\RuntimeException $e) { $this->failed($e, '/hooks'); return; }

        $this->flash('success', 'Hook deleted: ' . $name);
        Flight::redirect('/hooks');
    }

    /**
     * Show settings.json editor
     */
    public function config($params = []) {
        if (!$this->requireLevel(LEVELS['ROOT'])) return;
        if (!$this->bind()) return;

        try { $settings = $this->loadSettings(); }
        catch (\RuntimeException $e) { $this->failed($e, '/hooks'); return; }
        $hooksJson = json_encode($settings['hooks'] ?? new \stdClass(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $this->viewData['title'] = 'Hook Configuration';
        $this->viewData['hooksJson'] = $hooksJson;
        $this->viewData['csrf'] = SimpleCsrf::getTokenArray();

        $this->render('hooks/config', $this->viewData);
    }

    /**
     * Save settings.json configuration
     */
    public function saveConfig($params = []) {
        if (!$this->requireLevel(LEVELS['ROOT'])) return;
        if (!$this->bind()) return;
        if (!$this->validatePost('/hooks/config')) return;

        $hooks = json_decode(($this->getParam('hooks_json', '{}')) ?? '', true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->flash('error', 'Invalid JSON: ' . json_last_error_msg());
            Flight::redirect('/hooks/config');
            return;
        }

        try {
            $settings = $this->loadSettings();
            $settings['hooks'] = $hooks;
            $this->files->write('.claude/settings.json', json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", (int) $this->member->id, 'Hooks: configuration saved');
        } catch (\RuntimeException $e) { $this->failed($e, '/hooks/config'); return; }

        $this->flash('success', 'Hook configuration saved');
        Flight::redirect('/hooks/config');
    }

    /** A hook's name is its file name without .php — the only shape TenantFiles accepts. */
    private function hookName(string $name): bool {
        return (bool) preg_match('/^[a-z][a-z0-9-]*$/', $name);
    }

    private function warnings(array $validation): void {
        if (!empty($validation['warnings'])) {
            $this->flash('warning', 'Security warnings: ' . implode(', ', array_column($validation['warnings'], 'message')));
        }
    }

    /**
     * Load .claude/settings.json
     */
    private function loadSettings(): array {
        $raw = $this->files->read('.claude/settings.json');
        if ($raw === null) return ['hooks' => []];
        $settings = json_decode($raw, true);
        return is_array($settings) ? $settings : ['hooks' => []];
    }

    /**
     * Get hook template
     */
    private function getHookTemplate(): string {
        return <<<'PHP'
#!/usr/bin/env php
<?php
/**
 * Hook: my-custom-hook
 *
 * Events: PreToolUse, PostToolUse, Stop
 *
 * Input (stdin JSON):
 *   tool_name: Name of the tool being called
 *   tool_input: Arguments passed to the tool
 *
 * Output:
 *   Exit 0 to allow/continue
 *   Exit 2 to block (PreToolUse only)
 *   Optionally output JSON with decision/reason
 */

// Read input from Claude Code
$input = json_decode(file_get_contents('php://stdin'), true);

$toolName = $input['tool_name'] ?? '';
$toolInput = $input['tool_input'] ?? [];

// Your hook logic here
$shouldAllow = true;
$reason = '';

// Example: Check something
if (false /* your condition */) {
    $shouldAllow = false;
    $reason = 'Blocked because...';
}

// Output decision (optional for PreToolUse)
if (!$shouldAllow) {
    echo json_encode([
        'decision' => 'block',
        'reason' => $reason
    ]);
    exit(2); // Block the operation
}

// Allow the operation
exit(0);
PHP;
    }
}
