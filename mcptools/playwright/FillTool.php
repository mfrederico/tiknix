<?php
namespace app\mcptools\playwright;

use app\mcptools\BaseTool;

class FillTool extends BaseTool {

    public static string $name = 'playwright_fill';

    public static string $description = 'Fill a form field with a value.';

    public static array $inputSchema = [
        'type' => 'object',
        'properties' => [
            'selector' => [
                'type' => 'string',
                'description' => 'CSS selector for the input field'
            ],
            'value' => [
                'type' => 'string',
                'description' => 'Value to fill into the field'
            ]
        ],
        'required' => ['selector', 'value']
    ];

    public function execute(array $args): string {
        if (!$this->member) {
            throw new \Exception("Authentication required for browser automation");
        }

        $selector = $args['selector'] ?? null;
        $value = $args['value'] ?? null;

        if (!$selector || $value === null) {
            throw new \Exception("Selector and value are required");
        }

        $proxy = $this->getPlaywrightProxy();
        $result = $proxy->fill($selector, $value);

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
