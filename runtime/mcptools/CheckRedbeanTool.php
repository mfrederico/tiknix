<?php
namespace app\mcptools;

class CheckRedbeanTool extends BaseTool {

    public static string $name = 'check_redbean';

    public static string $description = 'Check PHP code for RedBeanPHP convention violations (raw R:: instead of the Bean wrapper, bean naming, associations, exec usage). A path must be inside this install; from a Task Board agent\'s workspace pass `code` instead (the server cannot read the workspace).';

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

        $validator = new \app\ValidationService($this->installRoot());

        if ($path) {
            $code = file_get_contents($this->readablePath($path));
        }

        $result = $validator->checkRedBeanConventions($code, $path ?? 'inline');

        return json_encode([
            'path' => $path ?? 'inline',
            'errors' => $result['errors'] ?? [],
            'warnings' => $result['warnings'] ?? []
        ], JSON_PRETTY_PRINT);
    }
}
