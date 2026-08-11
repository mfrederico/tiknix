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
     *
     * @return bool TRUE when this process is answering for ONE project's own workbench.db.
     */
    protected function selectWorkbenchDb(): bool {
        if (\is_control_plane()) return false;                 // core: ambient core db (unchanged)
        $db = dirname(__DIR__) . '/data/workbench.db';         // {instanceRoot}/data/workbench.db
        if (!is_file($db)) return false;                       // no sidecar-owned tasks here
        if (!Bean::hasDatabase('ws')) Bean::addDatabase('ws', 'sqlite:' . $db);
        Bean::selectDatabase('ws');
        Bean::freeze(false);
        return true;
    }

    /**
     * May this caller act on $task? Answered differently on a project than on core.
     *
     * ON CORE the question is "which of the many members' tasks is this one" — one database
     * holds every project's tasks, so ownership is the only thing separating them, and
     * TaskAccessControl decides.
     *
     * ON A PROJECT the question is already answered before it is asked. The database this
     * task came from holds THAT project's tasks and nothing else, and the API key that
     * opened the door exists only in THAT project's apikey table. Both are per-project, so
     * arriving here with the task in hand IS the authorisation.
     *
     * Asking TaskAccessControl anyway compares a member id from one database against a
     * member table in another. Task rows are stamped with the CONTROL PLANE's member id
     * (mtmoses is 25 on tiknix.com), while an agent authenticating to the project resolves
     * against the project's own members (ids 1 and 2). Those numbers mean different people,
     * so the comparison is not strict — it is meaningless, and it fails whichever way you
     * point it: core's id against the project's members denies every task, and the project's
     * agent key against a core id denies them too.
     */
    protected function mayUseTask(bool $projectScoped, $task, string $mode = 'view'): bool {
        if ($projectScoped) return true;
        $ac = new \app\TaskAccessControl();
        return $mode === 'edit'
            ? $ac->canEdit((int) $this->member->id, $task)
            : $ac->canView((int) $this->member->id, $task);
    }

    /**
     * The tasks this caller may list. On a project that is every task in its own
     * workbench.db — see mayUseTask() for why ownership cannot be asked there.
     */
    protected function visibleTasks(bool $projectScoped, array $filters): array {
        $ac = new \app\TaskAccessControl();
        if (!$projectScoped) return $ac->getVisibleTasks((int) $this->member->id, $filters);

        $where = []; $params = [];
        foreach (['status' => 'status', 'team_id' => 'team_id'] as $k => $col) {
            if (!isset($filters[$k]) || $filters[$k] === null) continue;
            $where[] = "{$col} = ?"; $params[] = $filters[$k];
        }
        $sql = ($where ? implode(' AND ', $where) . ' ' : '') . 'ORDER BY id DESC';
        return array_values(Bean::find('workbenchtask', $sql, $params));
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
