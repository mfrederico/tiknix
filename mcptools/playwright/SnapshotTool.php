<?php
namespace app\mcptools\playwright;

use app\mcptools\BaseTool;

class SnapshotTool extends BaseTool {

    public static string $name = 'playwright_snapshot';

    public static string $description = 'Get an accessibility snapshot of the current page for understanding page structure.';

    public static array $inputSchema = [
        'type' => 'object',
        'properties' => [],
        'required' => []
    ];

    public function execute(array $args): string {
        if (!$this->member) {
            throw new \Exception("Authentication required for browser automation");
        }

        $proxy = $this->getPlaywrightProxy();
        $result = $proxy->getSnapshot();

        return json_encode([
            'success' => true,
            'result' => $result
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
