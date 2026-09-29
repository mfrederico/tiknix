<?php
namespace app\mcptools;

class FullValidationTool extends BaseTool {

    public static string $name = 'full_validation';

    public static string $description = 'Run all validators (PHP syntax, security, RedBeanPHP, FlightPHP) on code. Also takes source as `code`. A path must be inside this install; from a Task Board agent\'s workspace pass `code` instead (the server cannot read the workspace).';

    public static array $inputSchema = [
        'type' => 'object',
        'properties' => [
            'path' => [
                'type' => 'string',
                'description' => 'Path to a PHP file or directory in this install'
            ],
            'code' => [
                'type' => 'string',
                'description' => 'PHP source to validate directly (alternative to path)'
            ],
            'label' => [
                'type' => 'string',
                'description' => 'With code: the file it came from, used in messages (e.g. controls/Img.php)'
            ]
        ],
        'required' => []
    ];

    public function execute(array $args): string {
        $this->validateArgs($args);

        $path = $args['path'] ?? null;
        $code = $args['code'] ?? null;
        if (!$path && ($code === null || $code === '')) {
            throw new \Exception("Either 'path' or 'code' is required");
        }

        $validator = new \app\ValidationService($this->installRoot());
        $result = $path
            ? $validator->fullValidation($this->readablePath($path))
            : $validator->validateCode((string) $code, (string) ($args['label'] ?? 'inline'));
        $path = $path ?? ($args['label'] ?? 'inline');

        return json_encode([
            'path' => $path,
            'valid' => $result['valid'],
            'errors' => $result['errors'],
            'warnings' => $result['warnings'],
            'info' => $result['info']
        ], JSON_PRETTY_PRINT);
    }
}
