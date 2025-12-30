<?php
namespace app\mcptools;

class EchoTool extends BaseTool {

    public static string $name = 'echo';

    public static string $description = 'Echoes back the provided message. Useful for testing.';

    public static array $inputSchema = [
        'type' => 'object',
        'properties' => [
            'message' => [
                'type' => 'string',
                'description' => 'Message to echo back'
            ]
        ],
        'required' => ['message']
    ];

    public function execute(array $args): string {
        $this->validateArgs($args);
        $message = $args['message'] ?? '';
        if (empty($message)) {
            throw new \Exception("Message is required");
        }
        return "Echo: {$message}";
    }
}
