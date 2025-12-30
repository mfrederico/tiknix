<?php
namespace app\mcptools\playwright;

use app\mcptools\BaseTool;

class ClickTool extends BaseTool {

    public static string $name = 'playwright_click';

    public static string $description = 'Click an element on the page.';

    public static array $inputSchema = [
        'type' => 'object',
        'properties' => [
            'selector' => [
                'type' => 'string',
                'description' => 'CSS or XPath selector for the element'
            ]
        ],
        'required' => ['selector']
    ];

    public function execute(array $args): string {
        if (!$this->member) {
            throw new \Exception("Authentication required for browser automation");
        }

        $selector = $args['selector'] ?? null;
        if (!$selector) {
            throw new \Exception("Selector is required");
        }

        $proxy = $this->getPlaywrightProxy();
        $result = $proxy->click($selector);

        return json_encode([
            'success' => true,
            'selector' => $selector,
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
