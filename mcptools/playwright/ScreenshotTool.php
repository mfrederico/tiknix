<?php
namespace app\mcptools\playwright;

use app\mcptools\BaseTool;

class ScreenshotTool extends BaseTool {

    public static string $name = 'playwright_screenshot';

    public static string $description = 'Take a screenshot of the current page.';

    public static array $inputSchema = [
        'type' => 'object',
        'properties' => [
            'name' => [
                'type' => 'string',
                'description' => 'Name for the screenshot'
            ],
            'full_page' => [
                'type' => 'boolean',
                'description' => 'Capture full scrollable page'
            ],
            'width' => [
                'type' => 'integer',
                'description' => 'Viewport width'
            ],
            'height' => [
                'type' => 'integer',
                'description' => 'Viewport height'
            ]
        ],
        'required' => ['name']
    ];

    public function execute(array $args): string {
        if (!$this->member) {
            throw new \Exception("Authentication required for browser automation");
        }

        $name = $args['name'] ?? 'screenshot';

        $proxy = $this->getPlaywrightProxy();
        $result = $proxy->screenshot($name, [
            'fullPage' => $args['full_page'] ?? false,
            'width' => $args['width'] ?? 1280,
            'height' => $args['height'] ?? 720
        ]);

        return json_encode([
            'success' => true,
            'name' => $name,
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
