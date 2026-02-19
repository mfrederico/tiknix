<?php
/**
 * MCP Tools Controller
 *
 * Admin interface for managing MCP tools in the mcptools/ directory.
 * Allows creating, editing, and deleting tool classes with online PHP editor.
 */

namespace app;

use \Flight as Flight;
use \Exception as Exception;
use app\BaseControls\Control;
use app\mcptools\ToolLoader;

class Mcptools extends Control {

    private string $toolsDir;

    public function __construct() {
        parent::__construct();
        $this->toolsDir = dirname(__DIR__) . '/mcptools';
    }

    /**
     * List all MCP tools
     */
    public function index($params = []) {
        if (!$this->requireLevel(LEVELS['ROOT'])) return;

        $toolLoader = new ToolLoader($this->toolsDir);
        $definitions = $toolLoader->getDefinitions();

        // Get file info for each tool
        $tools = [];
        foreach ($definitions as $def) {
            $name = $def['name'] ?? '';
            $className = $this->toolNameToClassName($name);
            $filePath = $this->findToolFile($name);

            $tools[] = [
                'name' => $name,
                'description' => $def['description'] ?? '',
                'inputSchema' => $def['inputSchema'] ?? [],
                'className' => $className,
                'file' => $filePath ? basename($filePath) : null,
                'filePath' => $filePath,
                'modTime' => $filePath && file_exists($filePath) ? filemtime($filePath) : null
            ];
        }

        // Sort by name
        usort($tools, fn($a, $b) => strcmp($a['name'], $b['name']));

        $this->viewData['title'] = 'MCP Tools';
        $this->viewData['tools'] = $tools;
        $this->viewData['csrf'] = SimpleCsrf::getTokenArray();

        $this->render('mcptools/index', $this->viewData);
    }

    /**
     * Show create form with tool template
     */
    public function create($params = []) {
        if (!$this->requireLevel(LEVELS['ROOT'])) return;

        $template = $this->getToolTemplate();

        $this->viewData['title'] = 'Create MCP Tool';
        $this->viewData['code'] = $template;
        $this->viewData['fileName'] = '';
        $this->viewData['isNew'] = true;
        $this->viewData['csrf'] = SimpleCsrf::getTokenArray();

        $this->render('mcptools/editor', $this->viewData);
    }

    /**
     * Show edit form for existing tool
     */
    public function edit($params = []) {
        if (!$this->requireLevel(LEVELS['ROOT'])) return;

        $name = $this->getParam('name', '');
        if (empty($name)) {
            Flight::redirect('/mcptools');
            return;
        }

        $filePath = $this->findToolFile($name);
        if (!$filePath || !file_exists($filePath)) {
            $_SESSION['flash'][] = ['type' => 'error', 'message' => 'Tool not found: ' . $name];
            Flight::redirect('/mcptools');
            return;
        }

        // Protect system tools
        if (in_array(basename($filePath), ['BaseTool.php', 'ToolLoader.php'])) {
            $_SESSION['flash'][] = ['type' => 'error', 'message' => 'Cannot edit system file'];
            Flight::redirect('/mcptools');
            return;
        }

        $code = file_get_contents($filePath);

        $this->viewData['title'] = 'Edit Tool: ' . $name;
        $this->viewData['code'] = $code;
        $this->viewData['fileName'] = basename($filePath);
        $this->viewData['toolName'] = $name;
        $this->viewData['isNew'] = false;
        $this->viewData['csrf'] = SimpleCsrf::getTokenArray();

        $this->render('mcptools/editor', $this->viewData);
    }

    /**
     * Save new tool
     */
    public function store($params = []) {
        if (!$this->requireLevel(LEVELS['ROOT'])) return;

        $request = Flight::request();
        if ($request->method !== 'POST') {
            Flight::redirect('/mcptools');
            return;
        }

        if (!SimpleCsrf::validate()) {
            $_SESSION['flash'][] = ['type' => 'error', 'message' => 'CSRF validation failed'];
            Flight::redirect('/mcptools');
            return;
        }

        $code = $this->getParam('code', '');
        $fileName = $this->sanitize($this->getParam('file_name', ''));

        if (empty($code)) {
            $_SESSION['flash'][] = ['type' => 'error', 'message' => 'Code is required'];
            Flight::redirect('/mcptools/create');
            return;
        }

        // Validate file name
        if (empty($fileName) || !preg_match('/^[A-Z][a-zA-Z0-9]*Tool\.php$/', $fileName)) {
            $_SESSION['flash'][] = ['type' => 'error', 'message' => 'File name must be PascalCase ending with Tool.php (e.g., MyCustomTool.php)'];
            Flight::redirect('/mcptools/create');
            return;
        }

        // Check if file already exists
        $filePath = $this->toolsDir . '/' . $fileName;
        if (file_exists($filePath)) {
            $_SESSION['flash'][] = ['type' => 'error', 'message' => 'Tool file already exists: ' . $fileName];
            Flight::redirect('/mcptools/create');
            return;
        }

        // Validate PHP code
        $validation = PhpValidator::validateAll($code, 'tool');
        if (!empty($validation['errors'])) {
            $_SESSION['flash'][] = ['type' => 'error', 'message' => 'Validation errors: ' . implode(', ', array_column($validation['errors'], 'message'))];
            Flight::redirect('/mcptools/create');
            return;
        }

        // Save file
        try {
            if (file_put_contents($filePath, $code) === false) {
                throw new Exception('Failed to write file');
            }

            $_SESSION['flash'][] = ['type' => 'success', 'message' => 'Tool created: ' . $fileName];

            // Show security warnings if any
            if (!empty($validation['warnings'])) {
                $_SESSION['flash'][] = ['type' => 'warning', 'message' => 'Security warnings: ' . implode(', ', array_column($validation['warnings'], 'message'))];
            }

        } catch (Exception $e) {
            $_SESSION['flash'][] = ['type' => 'error', 'message' => 'Error: ' . $e->getMessage()];
            Flight::redirect('/mcptools/create');
            return;
        }

        Flight::redirect('/mcptools');
    }

    /**
     * Update existing tool
     */
    public function update($params = []) {
        if (!$this->requireLevel(LEVELS['ROOT'])) return;

        $request = Flight::request();
        if ($request->method !== 'POST') {
            Flight::redirect('/mcptools');
            return;
        }

        if (!SimpleCsrf::validate()) {
            $_SESSION['flash'][] = ['type' => 'error', 'message' => 'CSRF validation failed'];
            Flight::redirect('/mcptools');
            return;
        }

        $code = $this->getParam('code', '');
        $name = $this->sanitize($this->getParam('name', ''));

        if (empty($code) || empty($name)) {
            $_SESSION['flash'][] = ['type' => 'error', 'message' => 'Code and tool name are required'];
            Flight::redirect('/mcptools');
            return;
        }

        $filePath = $this->findToolFile($name);
        if (!$filePath || !file_exists($filePath)) {
            $_SESSION['flash'][] = ['type' => 'error', 'message' => 'Tool not found: ' . $name];
            Flight::redirect('/mcptools');
            return;
        }

        // Protect system files
        if (in_array(basename($filePath), ['BaseTool.php', 'ToolLoader.php'])) {
            $_SESSION['flash'][] = ['type' => 'error', 'message' => 'Cannot modify system file'];
            Flight::redirect('/mcptools');
            return;
        }

        // Validate PHP code
        $validation = PhpValidator::validateAll($code, 'tool');
        if (!empty($validation['errors'])) {
            $_SESSION['flash'][] = ['type' => 'error', 'message' => 'Validation errors: ' . implode(', ', array_column($validation['errors'], 'message'))];
            Flight::redirect('/mcptools/edit?name=' . urlencode($name));
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

            $_SESSION['flash'][] = ['type' => 'success', 'message' => 'Tool updated: ' . basename($filePath)];

            // Show security warnings if any
            if (!empty($validation['warnings'])) {
                $_SESSION['flash'][] = ['type' => 'warning', 'message' => 'Security warnings: ' . implode(', ', array_column($validation['warnings'], 'message'))];
            }

            // Clean up old backups (keep last 5)
            $this->cleanupBackups($filePath);

        } catch (Exception $e) {
            // Restore from backup
            if (file_exists($backupPath)) {
                copy($backupPath, $filePath);
            }
            $_SESSION['flash'][] = ['type' => 'error', 'message' => 'Error: ' . $e->getMessage()];
            Flight::redirect('/mcptools/edit?name=' . urlencode($name));
            return;
        }

        Flight::redirect('/mcptools');
    }

    /**
     * Delete tool
     */
    public function delete($params = []) {
        if (!$this->requireLevel(LEVELS['ROOT'])) return;

        $request = Flight::request();
        if ($request->method !== 'POST') {
            Flight::redirect('/mcptools');
            return;
        }

        if (!SimpleCsrf::validate()) {
            $_SESSION['flash'][] = ['type' => 'error', 'message' => 'CSRF validation failed'];
            Flight::redirect('/mcptools');
            return;
        }

        $name = $this->sanitize($this->getParam('name', ''));

        if (empty($name)) {
            $_SESSION['flash'][] = ['type' => 'error', 'message' => 'Tool name is required'];
            Flight::redirect('/mcptools');
            return;
        }

        $filePath = $this->findToolFile($name);
        if (!$filePath || !file_exists($filePath)) {
            $_SESSION['flash'][] = ['type' => 'error', 'message' => 'Tool not found: ' . $name];
            Flight::redirect('/mcptools');
            return;
        }

        // Protect system files
        $protectedFiles = ['BaseTool.php', 'ToolLoader.php'];
        if (in_array(basename($filePath), $protectedFiles)) {
            $_SESSION['flash'][] = ['type' => 'error', 'message' => 'Cannot delete system file'];
            Flight::redirect('/mcptools');
            return;
        }

        try {
            // Create backup before delete
            $backupPath = $filePath . '.deleted.' . date('Ymd_His');
            rename($filePath, $backupPath);

            $_SESSION['flash'][] = ['type' => 'success', 'message' => 'Tool deleted: ' . $name];

        } catch (Exception $e) {
            $_SESSION['flash'][] = ['type' => 'error', 'message' => 'Error: ' . $e->getMessage()];
        }

        Flight::redirect('/mcptools');
    }

    /**
     * Find tool file by name
     */
    private function findToolFile(string $name): ?string {
        // Convert tool_name to ToolNameTool.php
        $className = $this->toolNameToClassName($name);
        $fileName = $className . '.php';

        // Check main directory
        $path = $this->toolsDir . '/' . $fileName;
        if (file_exists($path)) {
            return $path;
        }

        // Check subdirectories
        $subdirs = ['workbench'];
        foreach ($subdirs as $subdir) {
            $path = $this->toolsDir . '/' . $subdir . '/' . $fileName;
            if (file_exists($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Convert tool name (snake_case) to class name (PascalCase + Tool)
     */
    private function toolNameToClassName(string $name): string {
        // tiknix_validate_php -> TiknixValidatePhpTool
        $parts = explode('_', $name);
        $className = '';
        foreach ($parts as $part) {
            $className .= ucfirst($part);
        }
        return $className . 'Tool';
    }

    /**
     * Get tool template for new tools
     */
    private function getToolTemplate(): string {
        return <<<'PHP'
<?php
namespace app\mcptools;

class MyCustomTool extends BaseTool {

    public static string $name = 'my_custom';

    public static string $description = 'Description of what this tool does';

    public static array $inputSchema = [
        'type' => 'object',
        'properties' => [
            'param1' => [
                'type' => 'string',
                'description' => 'Description of parameter 1'
            ],
            'param2' => [
                'type' => 'integer',
                'description' => 'Description of parameter 2 (optional)'
            ]
        ],
        'required' => ['param1']
    ];

    public function execute(array $args): string {
        $this->validateArgs($args);

        $param1 = $args['param1'] ?? '';
        $param2 = $args['param2'] ?? 0;

        // Your implementation here
        $result = [
            'success' => true,
            'param1' => $param1,
            'param2' => $param2,
            'message' => 'Tool executed successfully'
        ];

        return json_encode($result, JSON_PRETTY_PRINT);
    }
}
PHP;
    }

    /**
     * Clean up old backup files (keep last 5)
     */
    private function cleanupBackups(string $filePath): void {
        $pattern = $filePath . '.bak.*';
        $backups = glob($pattern);

        if (count($backups) > 5) {
            // Sort by modification time (oldest first)
            usort($backups, fn($a, $b) => filemtime($a) - filemtime($b));

            // Delete oldest backups
            $toDelete = array_slice($backups, 0, count($backups) - 5);
            foreach ($toDelete as $backup) {
                @unlink($backup);
            }
        }
    }
}
