<?php
namespace app\mcptools;

class ValidatePhpTool extends BaseTool {

    public static string $name = 'validate_php';

    public static string $description = 'Validate PHP syntax for a file, a directory, or source passed as `code`. Returns syntax errors if any. A path must be inside this install; from a Task Board agent\'s workspace pass `code` instead (the server cannot read the workspace).';

    public static array $inputSchema = [
        'type' => 'object',
        'properties' => [
            'path' => [
                'type' => 'string',
                'description' => 'Path to a PHP file or directory in this install'
            ],
            'code' => [
                'type' => 'string',
                'description' => 'PHP source to check directly (alternative to path)'
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
        if ($path) {
            $full = $this->readablePath($path);
            $result = is_dir($full)
                ? $validator->validatePhpSyntaxBulk($validator->phpFilesIn($full))
                : $validator->validatePhpSyntax($full);
        } else {
            $result = $validator->validatePhpCode((string) $code, (string) ($args['label'] ?? 'inline'));
        }

        return json_encode([
            'path' => $path ?? ($args['label'] ?? 'inline'),
            'valid' => $result['valid'],
            'errors' => $result['errors']
        ], JSON_PRETTY_PRINT);
    }

}
