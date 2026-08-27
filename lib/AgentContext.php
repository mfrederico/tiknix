<?php
/**
 * AgentContext — the one place that decides which engine an agent runs on, which model it
 * asks for, and whose credentials it uses.
 *
 * These three answers MUST agree. When they disagreed the failures were quiet and
 * expensive: a task dispatched on one provider while bound to another's credential store
 * looked like an auth error from the wrong vendor; a model tier resolved from the default
 * engine sent `sonnet` to a provider that has never heard of it; an engine read from a file
 * that does not exist in a git clone silently became `claude`. Every one of those shipped,
 * in four separate runners, because each answered the same question its own way:
 *
 *   PlanRunner    engine from the caller,        planner tier
 *   PlanExecutor  engine from the task row,      worker tier
 *   ClaudeRunner  engine from the task, then a file in the WORKSPACE
 *   AuditRunner   engine from the instance file, auditor tier
 *
 * They differ legitimately in WHERE the hint comes from and WHICH tier they want. They do
 * not differ in how a hint becomes an engine, how an engine becomes a model, or how a
 * member becomes a credential store — so that part lives here and the callers pass what
 * they know.
 *
 * Adding a provider should be an ini edit. It was a five-file hunt.
 */

namespace app;

class AgentContext {

    /** The engine this run uses. Always a registered name. */
    public string $engine;

    /** The model to ask for. Never empty. */
    public string $model;

    /** Directory bound as the agent's ~/.claude — where its credentials live. */
    public string $stateDir;

    private function __construct(string $engine, string $model, string $stateDir) {
        $this->engine   = $engine;
        $this->model    = $model;
        $this->stateDir = $stateDir;
    }

    /**
     * @param int         $memberId    Whose credentials the agent runs on. Credentials
     *                                 follow the PERSON, not the project (app\AgentState).
     * @param string      $tier        planner | worker | auditor | resolver — what the run
     *                                 IS, not which model it wants. The registry maps the
     *                                 tier onto a model for whichever engine was chosen,
     *                                 which is what makes 'opus' stop leaking to providers
     *                                 that do not have one.
     * @param string      $instanceDir The PROJECT directory — not a worktree or a task
     *                                 workspace. Used for the engine file and as
     *                                 AgentState's project-store fallback, both of which
     *                                 are properties of the project.
     * @param string|null $engineHint  What the caller already knows: a task's engine, a
     *                                 member's pick. Wins when it names a registered
     *                                 engine, and is ignored when it does not — a stored
     *                                 value for an engine that has since been removed must
     *                                 not stop a run.
     * @param string|null $modelHint   An explicit model on the task. Wins over the tier,
     *                                 because it is a more specific instruction.
     */
    public static function for(
        int $memberId,
        string $tier,
        string $instanceDir,
        ?string $engineHint = null,
        ?string $modelHint = null
    ): self {
        $engine = self::resolveEngine($instanceDir, $engineHint);

        /* An explicit model beats the tier. It is NOT validated against the engine: a
           member who typed a model name knows something the registry may not (a new
           snapshot, a preview id), and refusing it would be the registry overruling a
           person. It is validated as a shell-safe token by MemberEnginePrefs::clean where
           it is stored. */
        $model = trim((string) $modelHint);
        if ($model === '') {
            // Raises when the engine declares no such tier — a config error reads as one.
            $model = MemberEnginePrefs::model($memberId, $engine, $tier);
        }

        return new self($engine, $model, AgentState::resolve($memberId, $engine, $instanceDir));
    }

    /**
     * hint → the project's engine file → the registry default. First registered name wins.
     *
     * The file is read from the INSTANCE, never from a workspace. A per-task workspace is a
     * git clone and `.aibuilder/` is not in it, so reading there always missed and fell
     * through to claude — which is how a zai task came to look for its key in claude's
     * store.
     */
    private static function resolveEngine(string $instanceDir, ?string $hint): string {
        $hint = trim((string) $hint);
        if ($hint !== '' && EngineRegistry::isValid($hint)) return $hint;

        $file = rtrim($instanceDir, '/') . '/.aibuilder/engine';
        if (is_file($file)) {
            $fromFile = trim((string) @file_get_contents($file));
            if ($fromFile !== '' && EngineRegistry::isValid($fromFile)) return $fromFile;
        }

        return EngineRegistry::defaultEngine();
    }
}
