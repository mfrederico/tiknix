<?php
namespace app\mcptools;

class GetTimeTool extends BaseTool {

    public static string $name = 'get_time';

    public static string $description = 'Returns the current server date and time.';

    public static array $inputSchema = [
        'type' => 'object',
        'properties' => [
            'timezone' => [
                'type' => 'string',
                'description' => 'Timezone (e.g., "America/New_York", "UTC"). Defaults to server timezone.'
            ],
            'format' => [
                'type' => 'string',
                'description' => 'Date format (PHP date format string). Defaults to "Y-m-d H:i:s".'
            ]
        ],
        'required' => []
    ];

    public function execute(array $args): string {
        $timezone = $args['timezone'] ?? date_default_timezone_get();
        $format = $args['format'] ?? 'Y-m-d H:i:s';

        try {
            $tz = new \DateTimeZone($timezone);
            $dt = new \DateTime('now', $tz);
            return json_encode([
                'datetime' => $dt->format($format),
                'timezone' => $timezone,
                'unix_timestamp' => $dt->getTimestamp()
            ], JSON_PRETTY_PRINT);
        } catch (\Exception $e) {
            throw new \Exception("Invalid timezone: {$timezone}");
        }
    }
}
