<?php
namespace app\mcptools\playwright;

use app\mcptools\BaseTool;

class CloseTool extends BaseTool {

    public static string $name = 'playwright_close';

    public static string $description = 'Close the browser session.';

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
        $result = $proxy->close();

        return json_encode([
            'success' => true,
            'message' => 'Browser session closed',
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
