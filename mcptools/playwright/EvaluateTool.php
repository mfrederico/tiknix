<?php
namespace app\mcptools\playwright;

use app\mcptools\BaseTool;

class EvaluateTool extends BaseTool {

    public static string $name = 'playwright_evaluate';

    public static string $description = 'Execute JavaScript code in the browser context.';

    public static array $inputSchema = [
        'type' => 'object',
        'properties' => [
            'script' => [
                'type' => 'string',
                'description' => 'JavaScript code to execute'
            ]
        ],
        'required' => ['script']
    ];

    public function execute(array $args): string {
        if (!$this->member) {
            throw new \Exception("Authentication required for browser automation");
        }

        $script = $args['script'] ?? null;
        if (!$script) {
            throw new \Exception("Script is required");
        }

        $proxy = $this->getPlaywrightProxy();
        $result = $proxy->evaluate($script);

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
