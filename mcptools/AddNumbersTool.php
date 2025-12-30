<?php
namespace app\mcptools;

class AddNumbersTool extends BaseTool {

    public static string $name = 'add_numbers';

    public static string $description = 'Adds two numbers together and returns the result.';

    public static array $inputSchema = [
        'type' => 'object',
        'properties' => [
            'a' => [
                'type' => 'number',
                'description' => 'First number'
            ],
            'b' => [
                'type' => 'number',
                'description' => 'Second number'
            ]
        ],
        'required' => ['a', 'b']
    ];

    public function execute(array $args): string {
        $this->validateArgs($args);

        if (!isset($args['a']) || !isset($args['b'])) {
            throw new \Exception("Both 'a' and 'b' parameters are required");
        }

        $a = (float)$args['a'];
        $b = (float)$args['b'];
        $result = $a + $b;

        return json_encode([
            'a' => $a,
            'b' => $b,
            'operation' => 'addition',
            'result' => $result
        ], JSON_PRETTY_PRINT);
    }
}
