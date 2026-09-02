<?php
/**
 * Backfill the playwright MCP server into an existing AI Builder instance's
 * .mcp.json (new instances get it at provision time). Idempotent.
 *
 * Usage:
 *   php scripts/add-playwright-mcp.php <slug|instanceDir>
 *   php scripts/add-playwright-mcp.php --all        # every provisioned instance
 */

require_once __DIR__ . '/../vendor/autoload.php';   // Model_Instance (classmapped)

function addPlaywright(string $file): string {
    if (!is_file($file)) return "skip: no .mcp.json at $file";
    $json = json_decode(((string)file_get_contents($file)) ?? '', true);
    if (!is_array($json)) return "skip: invalid JSON in $file";
    $json['mcpServers'] = $json['mcpServers'] ?? [];

    $want = ['-y', '@playwright/mcp@latest', '--headless', '--isolated'];
    $have = $json['mcpServers']['playwright']['args'] ?? null;

    // PRESENT IS NOT THE SAME AS CORRECT. This used to return early on isset(),
    // which made the script useless for the one job it is reached for now: an
    // entry written before --isolated was required is present, wrong, and leaks a
    // persistent chrome profile per run. Compare the args and repair in place.
    if ($have !== null) {
        if ($have === $want) return "ok: already correct in $file";
        // Only the args are replaced. A workspace may legitimately carry a
        // hand-edited command (a pinned version, a wrapper), and rewriting the
        // whole entry would silently discard it.
        $json['mcpServers']['playwright']['args'] = $want;
        file_put_contents($file, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        return "repaired: $file (was " . json_encode($have) . ")";
    }

    $json['mcpServers']['playwright'] = ['command' => 'npx', 'args' => $want];
    file_put_contents($file, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    return "added: $file";
}

$arg = $argv[1] ?? '';
if ($arg === '') { fwrite(STDERR, "usage: php scripts/add-playwright-mcp.php <slug|instanceDir|--all>\n"); exit(1); }

if ($arg === '--all') {
    foreach (glob('/var/www/html/default/*.tiknix', GLOB_ONLYDIR) ?: [] as $dir) {
        // The glob matches core.tiknix, a SYMLINK to the control plane — --all means
        // every provisioned instance, not core's own .mcp.json.
        if (!\Model_Instance::isProvisionedInstance($dir)) continue;
        echo addPlaywright($dir . '/.mcp.json') . "\n";
    }
    exit(0);
}

$dir = is_dir($arg) ? rtrim($arg, '/') : '/var/www/html/default/' . $arg . '.tiknix';
echo addPlaywright($dir . '/.mcp.json') . "\n";
