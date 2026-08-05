<?php
/**
 * StdioAllowList — which tools the JAILED AGENT gets over stdio.
 *
 * One list, read by both stdio servers (mcp-fastmcp.php and the dependency-free
 * mcp-stdio.php fallback). They previously wrote the same four names out twice,
 * which is a promise the code cannot keep: a tool added to one would silently not
 * appear in the other, and "the two servers are interchangeable" would quietly
 * stop being true.
 *
 * WHY AN ALLOW-LIST AT ALL, when ToolLoader discovers 27.
 *
 * The HTTP gateway authenticates every caller — an api key or a broker key
 * scoped to one instance — and can therefore offer tools that write. A stdio
 * server has no session, no member and no key: it is a subprocess of whatever
 * started it. So the boundary is not "which tools are useful" but "which tools
 * are safe to hand to a caller we cannot identify".
 *
 * WHAT CHANGED, AND WHY IT MATTERED.
 *
 * The list was codebase_map, describe, whatprovides, submit_plan — four tools,
 * written when those were most of what existed. mcptools/ has grown to 27 and the
 * list did not grow with it, so the policy ("read-only introspection") and the
 * list came apart. The consequence was not cosmetic: .mcp.json points the jailed
 * AI Builder agent at this server, so the agent that builds instances could not
 * call reuse_digest — the tool CLAUDE.md declares MANDATORY before adding any
 * controller, model or service — nor any of the standards checks the same
 * document insists on. An agent told to call a tool it cannot see will invent the
 * answer or skip the step.
 *
 * WHAT IS DELIBERATELY STILL OUT.
 *
 *   workbench/*  (get_task, update_task, complete_task, add_task_log,
 *                ask_question, upload_screenshot)
 *       They read $this->member and $this->apiKey — five references in
 *       CompleteTaskTool alone — and neither exists over stdio. They would fail,
 *       or worse, act as nobody. Giving the agent a way to close its own task is
 *       worth doing, but it needs an identity first, not a wider list.
 *
 *   pipeline_set / pipeline_delete / pipeline_run / pipeline_continue
 *       These change an instance's automations. Mutating an instance is not
 *       introspecting a codebase.
 *
 *   pipeline_get / pipeline_list / pipeline_components / pipeline_run_get
 *       Read-only, and defensible to add later — but pipelines are a separate
 *       feature from "understand and validate this code", and widening the list
 *       one justification at a time is how it stopped matching its policy before.
 *
 *   list_users
 *       Reads member records. Not codebase introspection, and the agent building
 *       an app has no use for who the customers are.
 *
 *   mcp_session_info, list_mcp_servers
 *       The first describes an HTTP session this server does not have. The second
 *       lists backend MCP servers, of which there are zero registered anywhere.
 */

namespace app\mcptools;

class StdioAllowList {

    /**
     * Read-only introspection, the standards checks, and submit_plan.
     *
     * Every one of these takes no member and no api key — verified rather than
     * assumed: each has zero references to $this->member or $this->apiKey.
     */
    private const NAMES = [
        // Orient, then drill down.
        'codebase_map',
        'describe',
        'whatprovides',

        // The reuse inventory. CLAUDE.md: "call reuse_digest FIRST when adding a
        // feature". It was absent from this list for as long as the list existed.
        'reuse_digest',

        // The standards checks this project actually enforces. An agent that can
        // run them before finishing is one that stops shipping the violations.
        'check_redbean',
        'check_flightphp',
        'validate_php',
        'full_validation',

        // How a planner returns its plan.
        'submit_plan',
    ];

    /** @return string[] */
    public static function names(): array {
        return self::NAMES;
    }
}
