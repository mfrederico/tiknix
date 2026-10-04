<?php
/**
 * 42_McpSetupMoved.php — a project's MCP servers, skills, plugins and hooks are set on the
 * project's own AI agents page (the runtime's /agents, in its container). The control plane's
 * pages for them — /agentsetup, then /mcpsetup — are gone, and so are their permission rows:
 * a row for a controller nothing answers reads on /permissions as a page that exists.
 */
$n = \RedBeanPHP\R::exec("DELETE FROM authcontrol WHERE control IN ('agentsetup', 'mcpsetup')");
echo "  authcontrol: {$n} agentsetup/mcpsetup row(s) removed\n";
\app\PermissionCache::clear();
