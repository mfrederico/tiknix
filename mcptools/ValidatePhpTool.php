<?php
namespace app\mcptools;

class ValidatePhpTool extends BaseTool {

    public static string $name = 'validate_php';

    public static string $description = 'Validate PHP syntax for one or more files. Returns syntax errors if any.';

    public static array $inputSchema = [
        'type' => 'object',
        'properties' => [
            'path' => [
                'type' => 'string',
                'description' => 'Path to PHP file or directory to validate'
            ]
        ],
        'required' => ['path']
    ];

    public function execute(array $args): string {
        $this->validateArgs($args);

        $path = $args['path'] ?? null;
        if (!$path) {
            throw new \Exception("Path is required");
        }

        // Resolve path relative to project root
        $projectRoot = \Flight::get('project_root') ?? dirname(dirname(__DIR__));
        $fullPath = $this->resolvePath($path, $projectRoot);

        $validator = new \app\ValidationService($projectRoot);
        $result = $validator->validatePhpSyntax($fullPath);

        return json_encode([
            'path' => $path,
            'valid' => $result['valid'],
            'errors' => $result['errors']
        ], JSON_PRETTY_PRINT);
    }

    private function resolvePath(string $path, string $projectRoot): string {
        if (strpos($path, '/') === 0) {
            return $path;
        }
        return $projectRoot . '/' . ltrim($path, './');
    }
}
