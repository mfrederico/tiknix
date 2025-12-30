<?php
/**
 * MCP Tool Loader
 *
 * Auto-discovers and loads tool classes from the mcptools directory.
 * Provides tool registration, lookup, and execution.
 */

namespace app\mcptools;

class ToolLoader {

    /**
     * Loaded tool classes indexed by name
     * @var array<string, string> name => className
     */
    private array $tools = [];

    /**
     * Tool definitions cache
     * @var array<string, array>
     */
    private array $definitions = [];

    /**
     * Base directory for tool files
     * @var string
     */
    private string $baseDir;

    /**
     * Reference to MCP controller
     * @var \app\Mcp|null
     */
    private $mcp = null;

    /**
     * Current authenticated member
     * @var object|null
     */
    private $member = null;

    /**
     * Current API key
     * @var object|null
     */
    private $apiKey = null;

    /**
     * Constructor
     *
     * @param string|null $baseDir Base directory for tools (defaults to mcptools/)
     */
    public function __construct(?string $baseDir = null) {
        $this->baseDir = $baseDir ?? dirname(__FILE__);
        $this->discover();
    }

    /**
     * Set the MCP controller reference
     *
     * @param \app\Mcp $mcp
     * @return self
     */
    public function setMcp($mcp): self {
        $this->mcp = $mcp;
        return $this;
    }

    /**
     * Set authentication context
     *
     * @param object|null $member
     * @param object|null $apiKey
     * @return self
     */
    public function setAuth($member, $apiKey): self {
        $this->member = $member;
        $this->apiKey = $apiKey;
        return $this;
    }

    /**
     * Discover tool classes in the mcptools directory
     */
    private function discover(): void {
        $this->discoverInDir($this->baseDir);

        // Discover in subdirectories
        $subdirs = ['workbench'];
        foreach ($subdirs as $subdir) {
            $path = $this->baseDir . '/' . $subdir;
            if (is_dir($path)) {
                $this->discoverInDir($path);
            }
        }
    }

    /**
     * Discover tools in a specific directory
     *
     * @param string $dir Directory path
     */
    private function discoverInDir(string $dir): void {
        $files = glob($dir . '/*Tool.php');

        foreach ($files as $file) {
            $className = $this->getClassNameFromFile($file);
            if ($className && class_exists($className)) {
                // Verify it extends BaseTool
                if (is_subclass_of($className, BaseTool::class)) {
                    $name = $className::$name;
                    if ($name) {
                        $this->tools[$name] = $className;
                        $this->definitions[$name] = $className::getDefinition();
                    }
                }
            }
        }
    }

    /**
     * Get fully qualified class name from file path
     *
     * @param string $file File path
     * @return string|null Class name or null
     */
    private function getClassNameFromFile(string $file): ?string {
        $basename = basename($file, '.php');

        // Skip BaseTool and ToolLoader
        if (in_array($basename, ['BaseTool', 'ToolLoader'])) {
            return null;
        }

        // Determine namespace based on directory
        $dir = dirname($file);
        $relDir = str_replace($this->baseDir, '', $dir);
        $relDir = trim($relDir, '/');

        if ($relDir) {
            return "\\app\\mcptools\\{$relDir}\\{$basename}";
        }

        return "\\app\\mcptools\\{$basename}";
    }

    /**
     * Check if a tool exists
     *
     * @param string $name Tool name
     * @return bool
     */
    public function has(string $name): bool {
        return isset($this->tools[$name]);
    }

    /**
     * Get all tool definitions for tools/list response
     *
     * @return array Array of tool definitions
     */
    public function getDefinitions(): array {
        return array_values($this->definitions);
    }

    /**
     * Get all tool names
     *
     * @return array
     */
    public function getNames(): array {
        return array_keys($this->tools);
    }

    /**
     * Execute a tool by name
     *
     * @param string $name Tool name
     * @param array $args Tool arguments
     * @return string Tool result
     * @throws \Exception if tool not found or execution fails
     */
    public function execute(string $name, array $args): string {
        if (!$this->has($name)) {
            throw new \Exception("Unknown tool: {$name}");
        }

        $className = $this->tools[$name];
        $tool = new $className($this->mcp, $this->member, $this->apiKey);

        return $tool->execute($args);
    }

    /**
     * Get a tool instance
     *
     * @param string $name Tool name
     * @return BaseTool|null
     */
    public function get(string $name): ?BaseTool {
        if (!$this->has($name)) {
            return null;
        }

        $className = $this->tools[$name];
        return new $className($this->mcp, $this->member, $this->apiKey);
    }

    /**
     * Get tool definition by name
     *
     * @param string $name Tool name
     * @return array|null
     */
    public function getDefinition(string $name): ?array {
        return $this->definitions[$name] ?? null;
    }

    /**
     * Register a tool class manually
     *
     * @param string $className Fully qualified class name
     * @return self
     */
    public function register(string $className): self {
        if (is_subclass_of($className, BaseTool::class)) {
            $name = $className::$name;
            if ($name) {
                $this->tools[$name] = $className;
                $this->definitions[$name] = $className::getDefinition();
            }
        }
        return $this;
    }
}
