<?php
namespace app\mcptools;

class HelloTool extends BaseTool {

    public static string $name = 'hello';

    public static string $description = 'Returns a friendly greeting. Use this to test the MCP connection.';

    public static array $inputSchema = [
        'type' => 'object',
        'properties' => [
            'name' => [
                'type' => 'string',
                'description' => 'Name to greet (optional)'
            ]
        ],
        'required' => []
    ];

    public function execute(array $args): string {
        $name = $args['name'] ?? 'World';
        return "Hello, {$name}! Welcome to the Tiknix MCP server.";
    }
}
