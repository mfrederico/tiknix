<?php
/**
 * StdioAllowList — which tools the JAILED AGENT gets over stdio.
 *
 * One list, read by the stdio server (mcp-fastmcp.php). It once served two stdio
 * servers — a fastmcphp one and a hand-rolled fallback that nothing selected; the
 * fallback is gone (2026-09-23), the list stays because it is the policy, not the
 * plumbing.
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
 *   workbench/*  (get_task, update_task, complete_task, add_task_log, ask_question,
 *                upload_screenshot, …) — HTTP only. They read $this->member and
 *                $this->apiKey, and neither exists over stdio: they would fail, or act as
 *                nobody. A Task Board agent reaches them through its project's HTTP MCP
 *                server, with that project's key. (Removed by mistake 2026-09-23 — tasks
 *                could no longer close themselves — and restored the next day.)
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

        // The shared concept catalog (COMPONENTS_PLAN.md): what somebody already built,
        // anywhere. Read-only, and neither takes a member or an api key — an instance
        // authenticates to the control plane with its own conf/broker.ini.
        'concepts_search',
        'concepts_get',

        // The standards checks this project actually enforces. An agent that can
        // run them before finishing is one that stops shipping the violations.
        'check_redbean',
        'check_flightphp',
        'validate_php',
        'full_validation',

        // Observation (COMPONENTS_PLAN.md "Observation tools"): what is HAPPENING, not what
        // exists. CLAUDE.md rule #1 is "check logs first" and until these the jailed agent
        // had no tool that could. Read-only, no identity needed, no member data: log text
        // is scrubbed of credential shapes (Redact), the schema is column names and types.
        // database_query is deliberately NOT here — it returns rows, so it stays HTTP + ADMIN.
        'last_error',
        'read_log_entries',
        'database_schema',
        'application_info',

        // How a planner returns its plan.
        'submit_plan',
    ];

    /** @return string[] */
    public static function names(): array {
        return self::NAMES;
    }
}
