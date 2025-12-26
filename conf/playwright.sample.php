<?php
/**
 * Playwright MCP Configuration
 *
 * Copy this file to playwright.php and configure for your environment.
 *
 * Tiknix proxies to an external Playwright MCP server for browser automation.
 * You can use the official Playwright MCP server or any compatible implementation.
 *
 * To start a Playwright MCP server:
 * 1. Install: npm install @anthropic-ai/mcp-server-playwright
 * 2. Run: npx @anthropic-ai/mcp-server-playwright --port 3000
 *
 * Or run via Docker:
 * docker run -p 3000:3000 anthropic/mcp-server-playwright
 */

return [
    // URL of the Playwright MCP server
    'mcp_url' => 'http://localhost:3000',

    // Optional authentication token for the Playwright server
    'mcp_token' => null,

    // Request timeout in seconds
    'timeout' => 30,

    // Default browser settings
    'browser' => [
        'headless' => true,
        'viewport' => [
            'width' => 1280,
            'height' => 720
        ]
    ],

    // Screenshot storage location (relative to project root)
    'screenshot_path' => 'storage/screenshots',

    // Enable/disable Playwright tools in MCP
    'enabled' => true
];
