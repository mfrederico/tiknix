<?php
namespace app\mcptools;

use \app\Bean;

class McpSessionInfoTool extends BaseTool {

    public static string $name = 'mcp_session_info';

    public static string $description = 'Returns info about stored MCP sessions for debugging.';

    public static array $inputSchema = [
        'type' => 'object',
        'properties' => [],
        'required' => []
    ];

    public function execute(array $args): string {
        $apiKeyId = $this->apiKey->id ?? 0;

        // Get database sessions
        $dbSessions = [];
        if ($apiKeyId) {
            $sessions = Bean::find('mcpsession', 'apikey_id = ?', [$apiKeyId]);
            foreach ($sessions as $s) {
                $dbSessions[] = [
                    'server_slug' => $s->serverSlug,
                    'session_id' => substr($s->sessionId, 0, 12) . '...',
                    'full_session_id' => $s->sessionId,
                    'expires_at' => $s->expiresAt,
                    'created_at' => $s->createdAt,
                    'current_time' => date('Y-m-d H:i:s'),
                    'is_expired' => $s->expiresAt < date('Y-m-d H:i:s'),
                ];
            }
        }

        return json_encode([
            'apikey_id' => $apiKeyId,
            'db_sessions' => $dbSessions,
            'note' => 'Use this to debug MCP session persistence issues'
        ], JSON_PRETTY_PRINT);
    }
}
