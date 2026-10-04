<?php
use RedBeanPHP\R;
/**
 * 43_McpGrantCoversWrites.php — the owner's decision (2026-10-04): the `mcp` feature grant
 * covers changing a project's MCP servers, not only seeing them. Seed 41 first wrote the write
 * routes at ADMIN; seedRule never widens a row, by design, so the move is made here, guarded:
 * only a row still exactly as seed 41 wrote it (level 50, its own description) is moved. A row
 * somebody changed since is theirs and is left, and said.
 */
foreach (['storeServer', 'updateServer', 'deleteServer', 'removeTiknix', 'restoreTiknix'] as $m) {
    $row = R::findOne('authcontrol', 'control = ? AND lower(method) = ?', ['mcpsetup', strtolower($m)]);
    if (!$row) { echo "  authcontrol: mcpsetup::{$m} — no row (seed 41 adds it at MEMBER)\n"; continue; }
    if ((int) $row->level === 100) { echo "  authcontrol: mcpsetup::{$m} => unchanged\n"; continue; }
    if ((int) $row->level === 50 && (string) $row->description === 'MCP services: ' . $m) {
        $row->level = 100;
        $row->description = 'MCP services: ' . $m . ' (the mcp grant decides, in the controller)';
        R::store($row);
        echo "  authcontrol: mcpsetup::{$m} => moved to MEMBER (the mcp grant decides)\n";
    } else {
        echo "  authcontrol: mcpsetup::{$m} => kept at {$row->level} — changed by someone since it was seeded\n";
    }
}
\app\PermissionCache::clear();
