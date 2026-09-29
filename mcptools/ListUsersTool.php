<?php
namespace app\mcptools;

use \app\Bean;

class ListUsersTool extends BaseTool {

    public static string $name = 'list_users';

    public static string $description = 'Lists users in the system (requires authentication).';

    public static array $inputSchema = [
        'type' => 'object',
        'properties' => [
            'limit' => [
                'type' => 'integer',
                'description' => 'Maximum number of users to return (default: 10)'
            ]
        ],
        'required' => []
    ];

    public function execute(array $args): string {
        $this->requireAdmin();

        $limit = min((int)($args['limit'] ?? 10), 100);

        $users = Bean::find('member', 'ORDER BY id LIMIT ?', [$limit]);

        $result = [];
        foreach ($users as $user) {
            $result[] = [
                'id' => $user->id,
                'username' => $user->username,
                'email' => $user->email,
                'level' => $user->level
            ];
        }

        return json_encode([
            'count' => count($result),
            'users' => $result
        ], JSON_PRETTY_PRINT);
    }
}
