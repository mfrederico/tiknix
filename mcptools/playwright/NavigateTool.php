<?php
namespace app\mcptools\playwright;

use app\mcptools\BaseTool;

class NavigateTool extends BaseTool {

    public static string $name = 'playwright_navigate';

    public static string $description = 'Navigate to a URL in the browser.';

    public static array $inputSchema = [
        'type' => 'object',
        'properties' => [
            'url' => [
                'type' => 'string',
                'description' => 'The URL to navigate to'
            ],
            'wait_until' => [
                'type' => 'string',
                'description' => 'When to consider navigation complete',
                'enum' => ['load', 'domcontentloaded', 'networkidle']
            ]
        ],
        'required' => ['url']
    ];

    public function execute(array $args): string {
        if (!$this->member) {
            throw new \Exception("Authentication required for browser automation");
        }

        $url = $args['url'] ?? null;
        if (!$url) {
            throw new \Exception("URL is required");
        }

        $proxy = $this->getPlaywrightProxy();
        $result = $proxy->navigate($url, [
            'waitUntil' => $args['wait_until'] ?? 'load'
        ]);

        return json_encode([
            'success' => true,
            'url' => $url,
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
