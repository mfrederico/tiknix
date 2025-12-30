<?php
namespace app\mcptools\playwright;

use app\mcptools\BaseTool;

class StatusTool extends BaseTool {

    public static string $name = 'playwright_status';

    public static string $description = 'Check the status of the Playwright browser automation server.';

    public static array $inputSchema = [
        'type' => 'object',
        'properties' => [],
        'required' => []
    ];

    public function execute(array $args): string {
        $proxy = $this->getPlaywrightProxy();
        $status = $proxy->getStatus();

        return json_encode([
            'server_url' => $status['server_url'],
            'available' => $status['available'],
            'message' => $status['available']
                ? 'Playwright server is available'
                : 'Playwright server is not available. Check PLAYWRIGHT_MCP_URL configuration.'
        ], JSON_PRETTY_PRINT);
    }

    protected function getPlaywrightProxy(): \app\PlaywrightProxy {
        static $proxy = null;
        if ($proxy === null) {
            $proxy = new \app\PlaywrightProxy();
        }
        return $proxy;
    }
}
