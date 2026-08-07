<?php
/**
 * Base class for MCP Tools
 *
 * All MCP tools must extend this class and define:
 * - $name: Tool name (e.g., 'hello', 'get_time')
 * - $description: Human-readable description
 * - $inputSchema: JSON Schema for arguments
 * - execute(): The tool implementation
 */

namespace app\mcptools;

use app\Bean;

abstract class BaseTool {

    /**
     * Tool name (without namespace prefix)
     * e.g., 'hello', 'get_time', 'validate_php'
     */
    public static string $name = '';

    /**
     * Human-readable description of what the tool does
     */
    public static string $description = '';

    /**
     * JSON Schema defining the tool's input parameters
     */
    public static array $inputSchema = [
        'type' => 'object',
        'properties' => [],
        'required' => []
    ];

    /**
     * Optional annotations for the tool
     */
    public static array $annotations = [];

    /**
     * Reference to the MCP controller for accessing shared resources
     * @var \app\Mcp|null
     */
    protected $mcp = null;

    /**
     * Current authenticated member (if any)
     * @var object|null
     */
    protected $member = null;

    /**
     * Current API key bean (if any)
     * @var object|null
     */
    protected $apiKey = null;

    /**
     * Constructor
     *
     * @param \app\Mcp|null $mcp Reference to MCP controller
     * @param object|null $member Authenticated member
     * @param object|null $apiKey Current API key
     */
    public function __construct($mcp = null, $member = null, $apiKey = null) {
        $this->mcp = $mcp;
        $this->member = $member;
        $this->apiKey = $apiKey;
    }

    /**
     * Point RedBean at this instance's per-instance workbench.db before touching workbench
     * task beans. Task data is owned by the AI Projects sidecar and lives in the instance's
     * data/workbench.db, so when a build agent reports progress to THIS instance's own MCP
     * the write must land there — not the instance's app db.
     *
     * INERT on the control plane (core keeps its tasks in the ambient core db) and when no
     * workbench.db exists yet. Call at the TOP of execute() in any workbench task tool.
     */
    protected function selectWorkbenchDb(): void {
        if (\is_control_plane()) return;                       // core: ambient core db (unchanged)
        $db = dirname(__DIR__) . '/data/workbench.db';         // {instanceRoot}/data/workbench.db
        if (!is_file($db)) return;                             // no sidecar-owned tasks here
        if (!Bean::hasDatabase('ws')) Bean::addDatabase('ws', 'sqlite:' . $db);
        Bean::selectDatabase('ws');
        Bean::freeze(false);
    }

    /**
     * Execute the tool with the given arguments
     *
     * @param array $args Tool arguments
     * @return string Tool result (text content)
     * @throws \Exception on error
     */
    abstract public function execute(array $args): string;

    /**
     * Get the full tool definition for tools/list response
     *
     * @return array Tool definition with name, description, inputSchema
     */
    public static function getDefinition(): array {
        // Returns the raw definition. The empty-properties → {} normalization that
        // strict MCP clients require ("expected record, received array") lives in
        // ToolLoader::normalizeDefinition(), the single choke point every
        // hand-rolled tools/list consumer flows through. (The fastmcphp-backed
        // server builds its own schema and never calls this.)
        $def = [
            'name' => static::$name,
            'description' => static::$description,
            'inputSchema' => static::$inputSchema
        ];

        if (!empty(static::$annotations)) {
            $def['annotations'] = static::$annotations;
        }

        return $def;
    }

    /**
     * Validate arguments against the input schema
     *
     * @param array $args Arguments to validate
     * @throws \Exception if validation fails
     */
    protected function validateArgs(array $args): void {
        $required = static::$inputSchema['required'] ?? [];

        foreach ($required as $field) {
            if (!isset($args[$field])) {
                throw new \Exception("Missing required argument: {$field}");
            }
        }
    }

    /**
     * Check if the current user has admin privileges
     *
     * @return bool
     */
    protected function isAdmin(): bool {
        if (!$this->member) {
            return false;
        }
        return ($this->member->level ?? 999) <= 50; // ADMIN level or higher
    }

    /**
     * Require admin privileges or throw
     *
     * @throws \Exception if not admin
     */
    protected function requireAdmin(): void {
        if (!$this->isAdmin()) {
            throw new \Exception('This tool requires administrator privileges');
        }
    }
}
