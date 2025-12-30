<?php
namespace app\mcptools;

class CheckRedbeanTool extends BaseTool {

    public static string $name = 'check_redbean';

    public static string $description = 'Check PHP code for RedBeanPHP convention violations (bean naming, associations, R::exec usage).';

    public static array $inputSchema = [
        'type' => 'object',
        'properties' => [
            'path' => [
                'type' => 'string',
                'description' => 'Path to PHP file or directory to check'
            ],
            'code' => [
                'type' => 'string',
                'description' => 'PHP code to check directly (alternative to path)'
            ]
        ],
        'required' => []
    ];

    public function execute(array $args): string {
        $path = $args['path'] ?? null;
        $code = $args['code'] ?? null;

        if (!$path && !$code) {
            throw new \Exception("Either 'path' or 'code' is required");
        }

        $projectRoot = \Flight::get('project_root') ?? dirname(dirname(__DIR__));
        $validator = new \app\ValidationService($projectRoot);

        if ($path) {
            $fullPath = $this->resolvePath($path, $projectRoot);
            if (!file_exists($fullPath)) {
                throw new \Exception("File not found: {$path}");
            }
            $code = file_get_contents($fullPath);
        }

        $result = $validator->checkRedBeanConventions($code, $path ?? 'inline');

        return json_encode([
            'path' => $path ?? 'inline',
            'errors' => $result['errors'] ?? [],
            'warnings' => $result['warnings'] ?? []
        ], JSON_PRETTY_PRINT);
    }

    private function resolvePath(string $path, string $projectRoot): string {
        if (strpos($path, '/') === 0) {
            return $path;
        }
        return $projectRoot . '/' . ltrim($path, './');
    }
}
