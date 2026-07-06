<?php
/**
 * Backfill the playwright MCP server into an existing AI Builder instance's
 * .mcp.json (new instances get it at provision time). Idempotent.
 *
 * Usage:
 *   php scripts/add-playwright-mcp.php <slug|instanceDir>
 *   php scripts/add-playwright-mcp.php --all        # every *.tiknix under /var/www/html/default
 */

function addPlaywright(string $file): string {
    if (!is_file($file)) return "skip: no .mcp.json at $file";
    $json = json_decode((string)file_get_contents($file), true);
    if (!is_array($json)) return "skip: invalid JSON in $file";
    $json['mcpServers'] = $json['mcpServers'] ?? [];
    if (isset($json['mcpServers']['playwright'])) return "ok: already present in $file";
    $json['mcpServers']['playwright'] = [
        'command' => 'npx',
        'args'    => ['-y', '@playwright/mcp@latest', '--headless', '--isolated'],
    ];
    file_put_contents($file, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    return "added: $file";
}

$arg = $argv[1] ?? '';
if ($arg === '') { fwrite(STDERR, "usage: php scripts/add-playwright-mcp.php <slug|instanceDir|--all>\n"); exit(1); }

if ($arg === '--all') {
    foreach (glob('/var/www/html/default/*.tiknix', GLOB_ONLYDIR) ?: [] as $dir) {
        echo addPlaywright($dir . '/.mcp.json') . "\n";
    }
    exit(0);
}

$dir = is_dir($arg) ? rtrim($arg, '/') : '/var/www/html/default/' . $arg . '.tiknix';
echo addPlaywright($dir . '/.mcp.json') . "\n";
