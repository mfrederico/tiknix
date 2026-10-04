<?php
/**
 * 42_AgentsetupGone.php — /agentsetup is gone (the page is MCP services at /mcpsetup; the
 * redirect that stood in for a day was removed at the owner's word). Its permission rows go
 * with it: a row for a controller that does not exist is noise in the permissions page.
 */
$n = \RedBeanPHP\R::exec("DELETE FROM authcontrol WHERE control = 'agentsetup'");
echo "  authcontrol: {$n} agentsetup row(s) removed\n";
