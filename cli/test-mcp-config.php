#!/usr/bin/env php
<?php
/**
 * Test Mcp::ensureMcpConfig for .mcp.json generation
 *
 * Usage: php cli/test-mcp-config.php [path]
 *
 * If no path provided, uses /tmp/test-mcp-workspace
 */

// Bootstrap the application
$projectRoot = dirname(__DIR__);
chdir($projectRoot);
require_once $projectRoot . '/bootstrap.php';
$app = new \app\Bootstrap('conf/config.ini');

use app\Mcp;

$testPath = $argv[1] ?? '/tmp/test-mcp-workspace';
$mcpJson = rtrim($testPath, '/') . '/.mcp.json';

// Ensure test directory exists
if (!is_dir($testPath)) {
    mkdir($testPath, 0755, true);
    echo "Created test directory: {$testPath}\n";
}

echo "=== Test 1: Create new config (no API key) ===\n";
@unlink($mcpJson); // Start fresh
$result = Mcp::ensureMcpConfig($testPath);
echo "Result: " . ($result ? 'CREATED' : 'no changes') . "\n";
if (file_exists($mcpJson)) {
    echo file_get_contents($mcpJson) . "\n";
} else {
    echo "ERROR: File not created!\n";
}

echo "=== Test 2: Run again (should be no changes) ===\n";
$result = Mcp::ensureMcpConfig($testPath);
echo "Result: " . ($result ? 'updated' : 'NO CHANGES (correct)') . "\n\n";

echo "=== Test 3: Add API key ===\n";
$result = Mcp::ensureMcpConfig($testPath, 'test-api-key-12345');
echo "Result: " . ($result ? 'UPDATED' : 'no changes') . "\n";
echo file_get_contents($mcpJson) . "\n";

echo "=== Test 4: Run again with same key (should be no changes) ===\n";
$result = Mcp::ensureMcpConfig($testPath, 'test-api-key-12345');
echo "Result: " . ($result ? 'updated' : 'NO CHANGES (correct)') . "\n\n";

echo "=== Test 5: Preserve custom servers ===\n";
$config = json_decode(file_get_contents($mcpJson), true);
$config['mcpServers']['my-custom-mcp'] = ['command' => 'custom-cmd', 'args' => ['--custom']];
file_put_contents($mcpJson, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
echo "Added 'my-custom-mcp' server manually...\n";

$result = Mcp::ensureMcpConfig($testPath, 'test-api-key-12345');
echo "Result: " . ($result ? 'updated' : 'NO CHANGES (correct)') . "\n";
$final = json_decode(file_get_contents($mcpJson), true);
$preserved = isset($final['mcpServers']['my-custom-mcp']);
echo "Custom server preserved: " . ($preserved ? 'YES ✓' : 'NO ✗') . "\n";
echo file_get_contents($mcpJson) . "\n";

// Cleanup if using default test path
if ($testPath === '/tmp/test-mcp-workspace') {
    @unlink($mcpJson);
    @rmdir($testPath);
    echo "Cleaned up test directory.\n";
}

echo "\n=== All tests complete ===\n";
