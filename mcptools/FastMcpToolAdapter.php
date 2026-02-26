<?php
/**
 * FastMcpToolAdapter - Wraps existing BaseTool classes as fastmcphp Tool instances
 *
 * Bridges the tiknix BaseTool interface into fastmcphp's Tool interface,
 * allowing existing mcptools to be registered with the fastmcphp Server.
 */

namespace app\mcptools;

use Fastmcphp\Tools\Tool;
use Fastmcphp\Tools\ToolResult;
use Fastmcphp\Server\Context;

class FastMcpToolAdapter implements Tool
{
    private string $className;
    private string $namePrefix;

    /**
     * @param string $className Fully qualified BaseTool class name
     * @param string $namePrefix Namespace prefix (e.g., 'tiknix')
     * @param object|null $mcp MCP controller reference
     * @param object|null $member Authenticated member
     * @param object|null $apiKey API key bean
     */
    public function __construct(
        string $className,
        string $namePrefix = 'tiknix',
        private ?object $mcp = null,
        private ?object $member = null,
        private ?object $apiKey = null,
    ) {
        $this->className = $className;
        $this->namePrefix = $namePrefix;
    }

    public function getName(): string
    {
        return $this->namePrefix . ':' . $this->className::$name;
    }

    public function getDescription(): string
    {
        $prefix = '[' . ucfirst($this->namePrefix) . '] ';
        return $prefix . $this->className::$description;
    }

    public function getInputSchema(): array
    {
        $schema = $this->className::$inputSchema;

        // Ensure empty properties is an object for JSON Schema compliance
        if (isset($schema['properties']) && is_array($schema['properties']) && empty($schema['properties'])) {
            $schema['properties'] = new \stdClass();
        }

        return $schema;
    }

    public function execute(array $arguments, ?Context $context = null): ToolResult
    {
        try {
            /** @var BaseTool $tool */
            $tool = new ($this->className)($this->mcp, $this->member, $this->apiKey);
            $result = $tool->execute($arguments);
            return ToolResult::text($result);
        } catch (\Exception $e) {
            return ToolResult::error($e->getMessage());
        }
    }

    public function toMcpTool(): array
    {
        $tool = [
            'name' => $this->getName(),
            'description' => $this->getDescription(),
            'inputSchema' => $this->getInputSchema(),
        ];

        $annotations = $this->className::$annotations ?? [];
        if (!empty($annotations)) {
            $tool['annotations'] = $annotations;
        }

        return $tool;
    }

    /**
     * Update the auth context (called when auth changes per-request)
     */
    public function setAuth(?object $member, ?object $apiKey): void
    {
        $this->member = $member;
        $this->apiKey = $apiKey;
    }

    /**
     * Set the MCP controller reference
     */
    public function setMcp(?object $mcp): void
    {
        $this->mcp = $mcp;
    }
}
