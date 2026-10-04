<?php
/**
 * 41_McpSetup.php — /agentsetup became /mcpsetup ("MCP services"). Every route is MEMBER at
 * the gate and decided in the controller by the `mcp` feature grant (Mcpsetup::mayConfigure),
 * which stands in for the ADMIN tier — the owner's decision, 2026-10-04: a member granted MCP
 * manages their project's servers, including removing and restoring its own. Seeded before
 * the first request, which would invent ADMIN rows. (Seed 43 moves rows an earlier version of
 * this seed wrote at ADMIN.)
 */
echo '  authcontrol: mcpsetup::index => ' . \app\PermissionCache::seedRule('mcpsetup', 'index', 100, 'MCP services: gated by the mcp feature grant, not by level') . "\n";
foreach (['storeServer', 'updateServer', 'deleteServer', 'removeTiknix', 'restoreTiknix', 'test',
          'skill', 'skillSave', 'skillDelete', 'plugins', 'pluginInstall', 'pluginRemove', 'marketplaceAdd', 'marketplaceRemove'] as $m) {
    echo "  authcontrol: mcpsetup::{$m} => " . \app\PermissionCache::seedRule('mcpsetup', $m, 100, 'MCP services: ' . $m . ' (the mcp grant decides, in the controller)') . "\n";
}
$n = \RedBeanPHP\R::exec("DELETE FROM authcontrol WHERE control = 'agentsetup' AND lower(method) != 'index'");
echo "  authcontrol: {$n} old agentsetup write row(s) removed\n";
