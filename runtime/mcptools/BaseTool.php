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
    /** The install this tool runs in — the project for a project's MCP server, core for core's. */
    protected function installRoot(): string {
        return \app\Paths::root();
    }

    /**
     * A file path an agent passed, as one this server can read — or a refusal that says
     * what to do instead.
     *
     * Relative = relative to THIS install. (The tools used to fall back to
     * the parent of the install root, one level too high: '/var/www/html/default/controls/…'.)
     * Absolute paths must be inside the install: a Task Board agent works in a task
     * workspace outside its project, and a project's MCP server runs in a pool walled to the
     * project (open_basedir), so such a path cannot be opened here — and reading arbitrary
     * files off the server was never these tools' job. The agent passes the source as
     * `code` instead.
     */
    protected function readablePath(string $path): string {
        $root = $this->installRoot();
        $full = str_starts_with($path, '/') ? $path : $root . '/' . preg_replace('#^\./#', '', $path);
        $real = @realpath($full);   // @: outside open_basedir it warns, and the refusal below says it better
        $inside = fn(string $p) => $p === $root || str_starts_with($p, $root . '/');
        if ($real === false ? !$inside($full) : !$inside($real)) {
            throw new \Exception("{$path} is outside this install ({$root}), so this server cannot read it — a task "
                . "workspace is not the project. Pass the file's contents as `code` instead.");
        }
        if ($real === false) throw new \Exception("File not found: {$path} (resolved to {$full})");
        return $real;
    }

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
