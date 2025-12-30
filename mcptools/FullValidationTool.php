<?php
namespace app\mcptools;

class FullValidationTool extends BaseTool {

    public static string $name = 'full_validation';

    public static string $description = 'Run all validators (PHP syntax, security, RedBeanPHP, FlightPHP) on code.';

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

        $projectRoot = \Flight::get('project_root') ?? dirname(dirname(__DIR__));
        $fullPath = $this->resolvePath($path, $projectRoot);

        $validator = new \app\ValidationService($projectRoot);
        $result = $validator->fullValidation($fullPath);

        return json_encode([
            'path' => $path,
            'valid' => $result['valid'],
            'errors' => $result['errors'],
            'warnings' => $result['warnings'],
            'info' => $result['info']
        ], JSON_PRETTY_PRINT);
    }

    private function resolvePath(string $path, string $projectRoot): string {
        if (strpos($path, '/') === 0) {
            return $path;
        }
        return $projectRoot . '/' . ltrim($path, './');
    }
}
