<?php
/**
 * LocalMcpServer — builds a fastmcphp server exposing tiknix's local MCP tools
 * (the ToolLoader *Tool classes) via McpToolAdapter.
 *
 * This is the single place tiknix's local tools are registered with fastmcphp,
 * shared by every transport:
 *   - stdio: mcptools/mcp-fastmcp.php (the in-jail introspection server)
 *   - HTTP:  controls/Mcp.php (the multi-homed gateway's own tools)
 *
 * One tool model, many transports — the basis for extending MCP across the
 * multi-homed instance concept. fastmcphp owns the protocol; *Tool classes own
 * the logic.
 *
 * NOTE: the filename intentionally does NOT end in "Tool.php" so ToolLoader's
 * `*Tool.php` discovery glob never picks it up as a tool.
 */

namespace app\mcptools;

use Fastmcphp\Fastmcphp;

final class LocalMcpServer {

    /**
     * @param ToolLoader  $loader Discovered tiknix tools (auth already set via
     *                            setAuth() if the caller requires it).
     * @param array       $meta   ['name' => ?, 'version' => ?, 'instructions' => ?].
     * @param array|null  $allow  Optional allow-list of tool names; null = all
     *                            discovered tools.
     */
    public static function build(ToolLoader $loader, array $meta = [], ?array $allow = null): Fastmcphp {
        $mcp = new Fastmcphp(
            name: $meta['name'] ?? 'tiknix',
            version: $meta['version'] ?? '0.1.0',
            instructions: $meta['instructions'] ?? '',
        );

        foreach ($loader->getNames() as $name) {
            if ($allow !== null && !in_array($name, $allow, true)) {
                continue;
            }
            $mcp->addTool(new McpToolAdapter($loader, $name));
        }

        return $mcp;
    }
}
