<?php
/**
 * McpToolAdapter — bridges a tiknix ToolLoader tool (app\mcptools\*Tool) to
 * fastmcphp's Tool interface, so the SAME *Tool classes can be served through a
 * fastmcphp Server on any transport (stdio via mcp-fastmcp.php, HTTP via the Mcp
 * controller). fastmcphp owns the protocol; the *Tool class owns the logic and
 * its explicit inputSchema.
 *
 * Everything is sourced from ToolLoader so there is one source of truth:
 *   - schema: getDefinition() is already normalized (empty properties -> {} object)
 *   - execution: execute() runs the tool with whatever auth context was set on
 *     the loader via setAuth() (the HTTP controller sets member+apiKey; the
 *     stdio introspection server needs none).
 *
 * NOTE: the filename intentionally does NOT end in "Tool.php" so ToolLoader's
 * `*Tool.php` discovery glob never picks it up as a tool.
 */

namespace app\mcptools;

use Fastmcphp\Tools\Tool;
use Fastmcphp\Tools\ToolResult;
use Fastmcphp\Server\Context;

final class McpToolAdapter implements Tool {

    public function __construct(
        private ToolLoader $loader,
        private string $toolName,
    ) {}

    public function getName(): string {
        return $this->loader->getDefinition($this->toolName)['name'] ?? $this->toolName;
    }

    public function getDescription(): string {
        return $this->loader->getDefinition($this->toolName)['description'] ?? '';
    }

    public function getInputSchema(): array {
        return $this->loader->getDefinition($this->toolName)['inputSchema']
            ?? ['type' => 'object', 'properties' => (object)[]];
    }

    public function execute(array $arguments, ?Context $context = null): ToolResult {
        try {
            return ToolResult::text($this->loader->execute($this->toolName, $arguments));
        } catch (\Throwable $e) {
            return ToolResult::error($e->getMessage());
        }
    }

    public function toMcpTool(): array {
        return [
            'name' => $this->getName(),
            'description' => $this->getDescription(),
            'inputSchema' => $this->getInputSchema(),
        ];
    }
}
