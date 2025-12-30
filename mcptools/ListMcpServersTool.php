<?php
namespace app\mcptools;

use \app\Bean;

class ListMcpServersTool extends BaseTool {

    public static string $name = 'list_mcp_servers';

    public static string $description = 'Lists registered MCP servers from the Tiknix registry. Returns server names, endpoints, versions, and available tools.';

    public static array $inputSchema = [
        'type' => 'object',
        'properties' => [
            'status' => [
                'type' => 'string',
                'description' => 'Filter by status: active, inactive, deprecated, or all. Defaults to active only.',
                'enum' => ['active', 'inactive', 'deprecated', 'all']
            ],
            'auth_type' => [
                'type' => 'string',
                'description' => 'Filter by authentication type',
                'enum' => ['none', 'basic', 'bearer', 'apikey']
            ],
            'tag' => [
                'type' => 'string',
                'description' => 'Filter by tag'
            ],
            'featured_only' => [
                'type' => 'boolean',
                'description' => 'Return only featured servers'
            ],
            'include_tools' => [
                'type' => 'boolean',
                'description' => 'Include full tool definitions in response. Defaults to false for brevity.'
            ],
            'limit' => [
                'type' => 'integer',
                'description' => 'Maximum number of servers to return (default: 50, max: 100)'
            ]
        ],
        'required' => []
    ];

    public function execute(array $args): string {
        $status = $args['status'] ?? 'active';
        $authType = $args['auth_type'] ?? null;
        $tag = $args['tag'] ?? null;
        $featuredOnly = $args['featured_only'] ?? false;
        $includeTools = $args['include_tools'] ?? false;
        $limit = min((int)($args['limit'] ?? 50), 100);

        // Build query
        $conditions = [];
        $params = [];

        if ($status !== 'all') {
            $conditions[] = 'status = ?';
            $params[] = $status;
        }

        if ($authType) {
            $conditions[] = 'auth_type = ?';
            $params[] = $authType;
        }

        if ($featuredOnly) {
            $conditions[] = 'featured = 1';
        }

        $sql = '';
        if (!empty($conditions)) {
            $sql = implode(' AND ', $conditions);
        }
        $sql .= ' ORDER BY featured DESC, sort_order ASC, name ASC';
        $sql .= ' LIMIT ' . $limit;

        if (empty($conditions)) {
            $servers = Bean::findAll('mcpserver', 'ORDER BY featured DESC, sort_order ASC, name ASC LIMIT ' . $limit);
        } else {
            $servers = Bean::find('mcpserver', $sql, $params);
        }

        $result = [];
        foreach ($servers as $server) {
            $serverTags = json_decode($server->tags, true) ?: [];

            // Filter by tag if specified
            if ($tag && !in_array($tag, $serverTags)) {
                continue;
            }

            $serverData = [
                'slug' => $server->slug,
                'name' => $server->name,
                'description' => $server->description,
                'endpoint_url' => $server->endpointUrl,
                'version' => $server->version,
                'status' => $server->status,
                'author' => $server->author,
                'auth_type' => $server->authType,
                'documentation_url' => $server->documentationUrl,
                'featured' => (bool)$server->featured,
                'tags' => $serverTags
            ];

            // Include tool definitions if requested
            $tools = json_decode($server->tools, true) ?: [];
            if ($includeTools) {
                $serverData['tools'] = $tools;
            } else {
                // Just include tool count and names
                $serverData['tool_count'] = count($tools);
                $serverData['tool_names'] = array_map(fn($t) => $t['name'] ?? 'unknown', $tools);
            }

            $result[] = $serverData;
        }

        return json_encode([
            'count' => count($result),
            'servers' => $result
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
