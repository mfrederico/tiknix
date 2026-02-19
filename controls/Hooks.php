<?php
/**
 * Hooks Controller
 *
 * Admin interface for managing Claude Code hooks.
 * - Edit hook PHP scripts in scripts/hooks/
 * - Configure hook settings in .claude/settings.json
 */

namespace app;

use \Flight as Flight;
use \Exception as Exception;
use app\BaseControls\Control;

class Hooks extends Control {

    private string $hooksDir;
    private string $settingsFile;

    public function __construct() {
        parent::__construct();
        $this->hooksDir = dirname(__DIR__) . '/scripts/hooks';
        $this->settingsFile = dirname(__DIR__) . '/.claude/settings.json';
    }

    /**
     * List all hooks and their configuration
     */
    public function index($params = []) {
        if (!$this->requireLevel(LEVELS['ROOT'])) return;

        // Get hook files
        $hookFiles = glob($this->hooksDir . '/*.php');
        $files = [];
        foreach ($hookFiles as $file) {
            $files[] = [
                'name' => basename($file, '.php'),
                'file' => basename($file),
                'path' => $file,
                'modTime' => filemtime($file),
                'size' => filesize($file)
            ];
        }
        usort($files, fn($a, $b) => strcmp($a['name'], $b['name']));

        // Get settings
        $settings = $this->loadSettings();
        $hooks = $settings['hooks'] ?? [];

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

        $template = $this->getHookTemplate();

        $this->viewData['title'] = 'Create Hook';
        $this->viewData['code'] = $template;
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

        $name = $this->getParam('name', '');
        if (empty($name)) {
            Flight::redirect('/hooks');
            return;
        }

        $filePath = $this->hooksDir . '/' . $name . '.php';
        if (!file_exists($filePath)) {
            $_SESSION['flash'][] = ['type' => 'error', 'message' => 'Hook not found: ' . $name];
            Flight::redirect('/hooks');
            return;
        }

        $code = file_get_contents($filePath);

        $this->viewData['title'] = 'Edit Hook: ' . $name;
        $this->viewData['code'] = $code;
        $this->viewData['fileName'] = basename($filePath);
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

        $request = Flight::request();
        if ($request->method !== 'POST') {
            Flight::redirect('/hooks');
            return;
        }

        if (!SimpleCsrf::validate()) {
            $_SESSION['flash'][] = ['type' => 'error', 'message' => 'CSRF validation failed'];
            Flight::redirect('/hooks');
            return;
        }

        $code = $this->getParam('code', '');
        $fileName = $this->sanitize($this->getParam('file_name', ''));

        if (empty($code)) {
            $_SESSION['flash'][] = ['type' => 'error', 'message' => 'Code is required'];
            Flight::redirect('/hooks/create');
            return;
        }

        // Validate file name
        if (empty($fileName) || !preg_match('/^[a-z][a-z0-9-]*\.php$/', $fileName)) {
            $_SESSION['flash'][] = ['type' => 'error', 'message' => 'File name must be lowercase with dashes ending in .php'];
            Flight::redirect('/hooks/create');
            return;
        }

        // Check if file already exists
        $filePath = $this->hooksDir . '/' . $fileName;
        if (file_exists($filePath)) {
            $_SESSION['flash'][] = ['type' => 'error', 'message' => 'Hook file already exists: ' . $fileName];
            Flight::redirect('/hooks/create');
            return;
        }

        // Validate PHP code
        $validation = PhpValidator::validateAll($code, 'hook');
        if (!empty($validation['errors'])) {
            $_SESSION['flash'][] = ['type' => 'error', 'message' => 'Validation errors: ' . implode(', ', array_column($validation['errors'], 'message'))];
            Flight::redirect('/hooks/create');
            return;
        }

        // Save file
        try {
            if (file_put_contents($filePath, $code) === false) {
                throw new Exception('Failed to write file');
            }

            // Make executable
            chmod($filePath, 0755);

            $_SESSION['flash'][] = ['type' => 'success', 'message' => 'Hook created: ' . $fileName];

            if (!empty($validation['warnings'])) {
                $_SESSION['flash'][] = ['type' => 'warning', 'message' => 'Security warnings: ' . implode(', ', array_column($validation['warnings'], 'message'))];
            }

        } catch (Exception $e) {
            $_SESSION['flash'][] = ['type' => 'error', 'message' => 'Error: ' . $e->getMessage()];
            Flight::redirect('/hooks/create');
            return;
        }

        Flight::redirect('/hooks');
    }

    /**
     * Update existing hook
     */
    public function update($params = []) {
        if (!$this->requireLevel(LEVELS['ROOT'])) return;

        $request = Flight::request();
        if ($request->method !== 'POST') {
            Flight::redirect('/hooks');
            return;
        }

        if (!SimpleCsrf::validate()) {
            $_SESSION['flash'][] = ['type' => 'error', 'message' => 'CSRF validation failed'];
            Flight::redirect('/hooks');
            return;
        }

        $code = $this->getParam('code', '');
        $name = $this->sanitize($this->getParam('name', ''));

        if (empty($code) || empty($name)) {
            $_SESSION['flash'][] = ['type' => 'error', 'message' => 'Code and hook name are required'];
            Flight::redirect('/hooks');
            return;
        }

        $filePath = $this->hooksDir . '/' . $name . '.php';
        if (!file_exists($filePath)) {
            $_SESSION['flash'][] = ['type' => 'error', 'message' => 'Hook not found: ' . $name];
            Flight::redirect('/hooks');
            return;
        }

        // Validate PHP code
        $validation = PhpValidator::validateAll($code, 'hook');
        if (!empty($validation['errors'])) {
            $_SESSION['flash'][] = ['type' => 'error', 'message' => 'Validation errors: ' . implode(', ', array_column($validation['errors'], 'message'))];
            Flight::redirect('/hooks/edit?name=' . urlencode($name));
            return;
        }

        // Create backup
        $backupPath = $filePath . '.bak.' . date('Ymd_His');
        copy($filePath, $backupPath);

        // Save file
        try {
            if (file_put_contents($filePath, $code) === false) {
                throw new Exception('Failed to write file');
            }

            $_SESSION['flash'][] = ['type' => 'success', 'message' => 'Hook updated: ' . $name];

            if (!empty($validation['warnings'])) {
                $_SESSION['flash'][] = ['type' => 'warning', 'message' => 'Security warnings: ' . implode(', ', array_column($validation['warnings'], 'message'))];
            }

            // Clean up old backups
            $this->cleanupBackups($filePath);

        } catch (Exception $e) {
            if (file_exists($backupPath)) {
                copy($backupPath, $filePath);
            }
            $_SESSION['flash'][] = ['type' => 'error', 'message' => 'Error: ' . $e->getMessage()];
            Flight::redirect('/hooks/edit?name=' . urlencode($name));
            return;
        }

        Flight::redirect('/hooks');
    }

    /**
     * Delete hook file
     */
    public function delete($params = []) {
        if (!$this->requireLevel(LEVELS['ROOT'])) return;

        $request = Flight::request();
        if ($request->method !== 'POST') {
            Flight::redirect('/hooks');
            return;
        }

        if (!SimpleCsrf::validate()) {
            $_SESSION['flash'][] = ['type' => 'error', 'message' => 'CSRF validation failed'];
            Flight::redirect('/hooks');
            return;
        }

        $name = $this->sanitize($this->getParam('name', ''));

        if (empty($name)) {
            $_SESSION['flash'][] = ['type' => 'error', 'message' => 'Hook name is required'];
            Flight::redirect('/hooks');
            return;
        }

        $filePath = $this->hooksDir . '/' . $name . '.php';
        if (!file_exists($filePath)) {
            $_SESSION['flash'][] = ['type' => 'error', 'message' => 'Hook not found'];
            Flight::redirect('/hooks');
            return;
        }

        try {
            $backupPath = $filePath . '.deleted.' . date('Ymd_His');
            rename($filePath, $backupPath);
            $_SESSION['flash'][] = ['type' => 'success', 'message' => 'Hook deleted: ' . $name];
        } catch (Exception $e) {
            $_SESSION['flash'][] = ['type' => 'error', 'message' => 'Error: ' . $e->getMessage()];
        }

        Flight::redirect('/hooks');
    }

    /**
     * Show settings.json editor
     */
    public function config($params = []) {
        if (!$this->requireLevel(LEVELS['ROOT'])) return;

        $settings = $this->loadSettings();
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

        $request = Flight::request();
        if ($request->method !== 'POST') {
            Flight::redirect('/hooks/config');
            return;
        }

        if (!SimpleCsrf::validate()) {
            $_SESSION['flash'][] = ['type' => 'error', 'message' => 'CSRF validation failed'];
            Flight::redirect('/hooks/config');
            return;
        }

        $hooksJson = $this->getParam('hooks_json', '{}');

        // Validate JSON
        $hooks = json_decode($hooksJson, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $_SESSION['flash'][] = ['type' => 'error', 'message' => 'Invalid JSON: ' . json_last_error_msg()];
            Flight::redirect('/hooks/config');
            return;
        }

        // Load current settings
        $settings = $this->loadSettings();

        // Backup
        $backupPath = $this->settingsFile . '.bak.' . date('Ymd_His');
        if (file_exists($this->settingsFile)) {
            copy($this->settingsFile, $backupPath);
        }

        // Update hooks section
        $settings['hooks'] = $hooks;

        try {
            $json = json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if (file_put_contents($this->settingsFile, $json . "\n") === false) {
                throw new Exception('Failed to write settings file');
            }

            $_SESSION['flash'][] = ['type' => 'success', 'message' => 'Hook configuration saved'];

            // Clean up old backups
            $this->cleanupBackups($this->settingsFile);

        } catch (Exception $e) {
            if (file_exists($backupPath)) {
                copy($backupPath, $this->settingsFile);
            }
            $_SESSION['flash'][] = ['type' => 'error', 'message' => 'Error: ' . $e->getMessage()];
        }

        Flight::redirect('/hooks/config');
    }

    /**
     * Load .claude/settings.json
     */
    private function loadSettings(): array {
        if (!file_exists($this->settingsFile)) {
            return ['hooks' => []];
        }

        $content = file_get_contents($this->settingsFile);
        $settings = json_decode($content, true);

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

    /**
     * Clean up old backup files
     */
    private function cleanupBackups(string $filePath): void {
        $pattern = $filePath . '.bak.*';
        $backups = glob($pattern);

        if (count($backups) > 5) {
            usort($backups, fn($a, $b) => filemtime($a) - filemtime($b));
            $toDelete = array_slice($backups, 0, count($backups) - 5);
            foreach ($toDelete as $backup) {
                @unlink($backup);
            }
        }
    }
}
