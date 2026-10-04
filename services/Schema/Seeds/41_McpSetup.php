<?php
/**
 * 41_McpSetup.php — /agentsetup became /mcpsetup ("MCP services"). The levels the old routes
 * had: the page and its connectivity test are MEMBER at the gate and decided by the `mcp`
 * feature grant in the controller; adding, changing and removing a server are ADMIN. (A
 * granted MEMBER can therefore open the page and test, but not change servers — as before
 * the rename. Whether the grant should cover the writes too is the owner's decision.)
 * Seeded before the first request, which would invent rows. The old page's write rows go;
 * /agentsetup itself stays as a redirect at MEMBER.
 */
echo '  authcontrol: mcpsetup::index => ' . \app\PermissionCache::seedRule('mcpsetup', 'index', 100, 'MCP services: gated by the mcp feature grant, not by level') . "\n";
foreach (['storeServer', 'updateServer', 'deleteServer', 'test'] as $m) {
    echo "  authcontrol: mcpsetup::{$m} => " . \app\PermissionCache::seedRule('mcpsetup', $m, $m === 'test' ? 100 : 50, 'MCP services: ' . $m) . "\n";
}
$n = \RedBeanPHP\R::exec("DELETE FROM authcontrol WHERE control = 'agentsetup' AND lower(method) != 'index'");
echo "  authcontrol: {$n} old agentsetup write row(s) removed\n";
