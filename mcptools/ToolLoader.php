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
     * Gate for tools that are NOT for every caller: name => ['level' => int, 'concept' => string].
     * Core tools have no entry and are visible to whoever reached the loader. A concept tool
     * (concept.json provides.tools) is visible only to a member at or above its level, and to
     * nobody without a member — a broker key reaches connectors, not the instance's features.
     * @var array<string, array{level:int,concept:string}>
     */
    private array $meta = [];

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
                        $this->definitions[$name] = $this->normalizeDefinition($className::getDefinition());
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
     * Does this tool exist FOR THE CURRENT CALLER? A gated tool the caller may not use is
     * absent, not refused: tools/list omits it and tools/call says "unknown", so an agent
     * never spends a step on something it cannot have. Set the caller with setAuth() first.
     */
    private function visible(string $name): bool {
        if (!isset($this->tools[$name])) return false;
        $gate = $this->meta[$name] ?? null;
        if ($gate === null) return true;
        $level = $this->member->level ?? null;
        return $level !== null && (int) $level <= $gate['level'];
    }

    /**
     * Check if a tool exists (for the current caller — see visible()).
     *
     * @param string $name Tool name
     * @return bool
     */
    public function has(string $name): bool {
        return $this->visible($name);
    }

    /**
     * Get all tool definitions for tools/list response (the current caller's view)
     *
     * @return array Array of tool definitions
     */
    public function getDefinitions(): array {
        return array_values(array_filter($this->definitions, fn(string $n) => $this->visible($n), ARRAY_FILTER_USE_KEY));
    }

    /**
     * Get all tool names (the current caller's view)
     *
     * @return array
     */
    public function getNames(): array {
        return array_values(array_filter(array_keys($this->tools), fn(string $n) => $this->visible($n)));
    }

    /** The concept a tool came from, or null for a core tool. */
    public function conceptOf(string $name): ?string {
        return $this->meta[$name]['concept'] ?? null;
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
        return $this->visible($name) ? $this->definitions[$name] : null;
    }

    /**
     * JSON Schema requires inputSchema.properties to be an object. An empty PHP
     * array json-encodes to [] (array), which strict MCP clients reject with
     * "expected record, received array". Normalize the no-argument case to {}
     * here — the single point every hand-rolled tools/list consumer
     * (mcp-stdio.php, the HTTP Mcp controller, admin UIs) flows through.
     *
     * @param array $def Tool definition from BaseTool::getDefinition()
     * @return array Definition with an object (not array) empty properties
     */
    private function normalizeDefinition(array $def): array {
        if (isset($def['inputSchema']['properties']) &&
            is_array($def['inputSchema']['properties']) &&
            empty($def['inputSchema']['properties'])) {
            $def['inputSchema']['properties'] = (object)[];
        }
        return $def;
    }

    /**
     * Register a tool class that discovery did not find — a concept's tool. Loud on every
     * way it can be wrong: the caller is Concepts::tools(), which only names classes its
     * manifests declared and verify() accepted, so a failure here is a real fault, not a
     * file to skip.
     *
     * @param string $className Fully qualified class name
     * @param array  $meta      ['level' => int, 'concept' => string] — required for a concept tool
     * @return self
     */
    public function register(string $className, array $meta = []): self {
        if (!class_exists($className) || !is_subclass_of($className, BaseTool::class)) {
            throw new \RuntimeException("ToolLoader: {$className} is not a loadable subclass of " . BaseTool::class . '.');
        }
        $name = (string) $className::$name;
        if ($name === '') {
            throw new \RuntimeException("ToolLoader: {$className} has no \$name.");
        }
        if (isset($this->tools[$name]) && $this->tools[$name] !== $className) {
            throw new \RuntimeException("ToolLoader: tool name '{$name}' is already taken by {$this->tools[$name]}; {$className} cannot use it.");
        }
        if ($meta !== []) {
            if (!isset($meta['level'], $meta['concept']) || !is_int($meta['level']) || !is_string($meta['concept'])) {
                throw new \RuntimeException("ToolLoader: register({$className}) meta must carry int level and string concept.");
            }
            $this->meta[$name] = ['level' => $meta['level'], 'concept' => $meta['concept']];
        }
        $this->tools[$name] = $className;
        $this->definitions[$name] = $this->normalizeDefinition($className::getDefinition());
        return $this;
    }
}
