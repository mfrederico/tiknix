<?php
/**
 * last_error — the newest ERROR/CRITICAL in the application log, with what preceded it.
 *
 * CLAUDE.md rule #1 ("check logs first") for an agent with no shell. Also reports the
 * newest PHP fatal from the SAPI's error_log when there is one this process can read —
 * and says so when there is not, because "no fatal found" and "could not look" are
 * different answers.
 */

namespace app\mcptools;

use app\LogReader;
use app\Redact;

class LastErrorTool extends BaseTool {

    public static string $name = 'last_error';
    public static string $description = 'The newest ERROR or CRITICAL entry in this install\'s application log (log/app-<date>.log), with the entries logged just before it — what the process was doing when it failed. Also the newest PHP fatal error from the PHP error log when one is readable. Call this FIRST when something misbehaves (CLAUDE.md rule #1). Secrets are redacted.';
    public static array $inputSchema = [
        'type' => 'object',
        'properties' => [
            'context' => ['type' => 'integer', 'description' => 'How many entries before the error to include (default 15, max 100).'],
        ],
        'required' => [],
    ];

    public function execute(array $args): string {
        $this->validateArgs($args);
        $context = max(0, min(100, (int) ($args['context'] ?? 15)));
        $reader = LogReader::forInstall();
        $files = $reader->files();
        $out = "# last_error\n\n";
        if (!$files) {
            $out .= "No application log files in {$reader->dir()} (expected log/app-YYYY-MM-DD.log). Either nothing has run, or [logging] file points elsewhere.\n";
        } else {
            $found = $reader->lastError($context);
            if ($found === null) {
                $out .= 'No ERROR or CRITICAL entry in the newest ' . min(3, count($files)) . " log file(s): " . implode(', ', array_slice($files, 0, 3)) . ".\n";
            } else {
                $out .= "**{$found['file']}** — newest error:\n\n```\n" . LogReader::format($found['entry']) . "\n```\n\n";
                if ($found['before']) {
                    $out .= 'The ' . count($found['before']) . " entries before it:\n\n```\n";
                    foreach ($found['before'] as $e) $out .= LogReader::format($e) . "\n";
                    $out .= "```\n";
                }
            }
        }
        $out .= "\n## PHP fatal errors\n\n" . self::phpFatal() . "\n";
        return $out;
    }

    /** The newest "PHP Fatal error" line in the SAPI's error_log, or why it cannot be read. */
    private static function phpFatal(): string {
        $path = (string) ini_get('error_log');
        if ($path === '') {
            return 'This process has no error_log ini setting; PHP fatals go to the SAPI\'s own log (php-fpm pool / CLI stderr), which is not readable from here.';
        }
        if (!is_readable($path)) {
            return "PHP error_log is {$path}, which this process cannot read.";
        }
        $size = filesize($path) ?: 0;
        $fh = fopen($path, 'rb');
        if ($fh === false) return "PHP error_log is {$path}, which could not be opened.";
        if ($size > 1024 * 1024) fseek($fh, $size - 1024 * 1024);
        $tail = stream_get_contents($fh) ?: '';
        fclose($fh);
        $lines = preg_split("/\r\n|\n/", $tail);
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            if (preg_match('/PHP (Fatal error|Parse error)/', $lines[$i])) {
                return "Newest fatal in {$path}:\n\n```\n" . Redact::secrets($lines[$i]) . "\n```";
            }
        }
        return "No PHP fatal or parse error in the newest part of {$path}.";
    }
}
