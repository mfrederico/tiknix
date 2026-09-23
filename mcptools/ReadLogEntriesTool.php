<?php
/**
 * read_log_entries — the newest N application log entries, filtered by level and text.
 *
 * Reads log/ only: the file argument is a basename that must look like app-YYYY-MM-DD.log,
 * so the tool can never be pointed at another file. Entries, not lines — a stack trace
 * stays with the entry it belongs to. Secrets are redacted.
 */

namespace app\mcptools;

use app\LogReader;

class ReadLogEntriesTool extends BaseTool {

    public static string $name = 'read_log_entries';
    public static string $description = 'The newest entries of this install\'s application log (log/app-<date>.log), oldest first, optionally only at or above a level (DEBUG, INFO, NOTICE, WARNING, ERROR, CRITICAL) and only those containing a substring. Multi-line entries (stack traces) are kept whole. Reads log/ only. Secrets are redacted.';
    public static array $inputSchema = [
        'type' => 'object',
        'properties' => [
            'count'    => ['type' => 'integer', 'description' => 'How many entries, from the end (default 50, max 500).'],
            'level'    => ['type' => 'string',  'description' => 'Minimum level to keep: DEBUG, INFO, NOTICE, WARNING, ERROR or CRITICAL. Omit for all.'],
            'contains' => ['type' => 'string',  'description' => 'Keep only entries whose message or context contains this text (case-insensitive), e.g. a controller name or "Calling:".'],
            'file'     => ['type' => 'string',  'description' => 'A log file basename, e.g. app-2026-09-22.log, for an earlier day. Default: the newest file.'],
        ],
        'required' => [],
    ];

    public function execute(array $args): string {
        $this->validateArgs($args);
        $count = max(1, min(500, (int) ($args['count'] ?? 50)));
        $level = isset($args['level']) && trim((string) $args['level']) !== '' ? strtoupper(trim((string) $args['level'])) : null;
        $contains = isset($args['contains']) && trim((string) $args['contains']) !== '' ? (string) $args['contains'] : null;
        $reader = LogReader::forInstall();
        try {
            $file = $reader->resolve(isset($args['file']) ? (string) $args['file'] : null);
            if ($file === null) {
                $files = $reader->files();
                return "# read_log_entries\n\n" . (isset($args['file'])
                    ? "No file '{$args['file']}' in {$reader->dir()}. Files: " . ($files ? implode(', ', $files) : '(none)') . "\n"
                    : "No application log files in {$reader->dir()} (expected log/app-YYYY-MM-DD.log).\n");
            }
            $entries = $reader->entries($file, $count, $level, $contains);
        } catch (\InvalidArgumentException $e) {
            return "# read_log_entries FAILED\n\n" . $e->getMessage() . "\n";
        }
        $filter = ($level !== null ? " level ≥ {$level}" : '') . ($contains !== null ? " containing \"{$contains}\"" : '');
        $out = "# read_log_entries — {$file}" . ($filter !== '' ? " ({$filter} )" : '') . "\n\n";
        if (!$entries) return $out . "No entries match.\n";
        $out .= count($entries) . " entr" . (count($entries) === 1 ? 'y' : 'ies') . ", oldest first:\n\n```\n";
        foreach ($entries as $e) $out .= LogReader::format($e) . "\n";
        return $out . "```\n";
    }
}
