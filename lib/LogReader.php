<?php
/**
 * LogReader — the application log, as entries, for the observation tools.
 *
 * CLAUDE.md rule #1 is "check logs first". A jailed build agent has no shell that reaches
 * log/, so last_error and read_log_entries read it through this class. It answers from
 * log/ only (the directory bootstrap resolved for Monolog's RotatingFileHandler:
 * app-<date>.log), never from a path the caller names — a file argument is a basename
 * that must match the rotating pattern.
 *
 * Entries, not lines: a Monolog line is `[date] channel.LEVEL: message {context} []` and
 * a stack trace continues on unprefixed lines, which belong to the entry above them.
 * Every string returned has been through Redact::secrets(): the log is where a stray
 * credential is most likely to have been printed by accident.
 */

namespace app;

class LogReader {

    public const LEVELS = ['DEBUG' => 100, 'INFO' => 200, 'NOTICE' => 250, 'WARNING' => 300, 'ERROR' => 400, 'CRITICAL' => 500, 'ALERT' => 550, 'EMERGENCY' => 600];
    public const FILE_RE = '/^app-\d{4}-\d{2}-\d{2}\.log$/D';
    private const LINE_RE = '/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] ([A-Za-z0-9_-]+)\.([A-Z]+): (.*)$/s';
    /** How much of a big file is read: the newest 4 MB is thousands of entries. */
    private const TAIL_BYTES = 4 * 1024 * 1024;

    private string $dir;

    public function __construct(string $dir) {
        $this->dir = rtrim($dir, '/');
    }

    /** The log directory this install writes to, resolved exactly as bootstrap resolves it. */
    public static function forInstall(): self {
        $root = dirname(__DIR__);
        $file = (string) (\Flight::get('logging.file') ?: 'log/app.log');
        $abs  = ($file[0] ?? '') === '/' ? $file : $root . '/' . ltrim($file, './');
        return new self(dirname($abs));
    }

    public function dir(): string {
        return $this->dir;
    }

    /** @return string[] log basenames, newest first */
    public function files(): array {
        $out = [];
        foreach (glob($this->dir . '/app-*.log') ?: [] as $p) {
            if (preg_match(self::FILE_RE, basename($p))) $out[] = basename($p);
        }
        rsort($out);
        return $out;
    }

    /** A caller-supplied basename, or the newest file. Throws on a name outside the pattern. */
    public function resolve(?string $basename): ?string {
        if ($basename === null || $basename === '') {
            $files = $this->files();
            return $files[0] ?? null;
        }
        if (!preg_match(self::FILE_RE, $basename)) {
            throw new \InvalidArgumentException("'{$basename}' is not a log file name; the log is log/app-YYYY-MM-DD.log.");
        }
        return is_file($this->dir . '/' . $basename) ? $basename : null;
    }

    /**
     * The newest entries of one file, oldest first.
     *
     * @param string      $basename  from resolve()
     * @param int         $count     how many, from the end
     * @param string|null $minLevel  'ERROR' keeps ERROR and above; null keeps all
     * @param string|null $contains  case-insensitive substring on message + context
     * @return array<int,array{time:string,channel:string,level:string,message:string,extra:string}>
     */
    public function entries(string $basename, int $count = 50, ?string $minLevel = null, ?string $contains = null): array {
        $all = $this->parse($basename);
        $min = $minLevel !== null ? (self::LEVELS[strtoupper($minLevel)] ?? null) : null;
        if ($minLevel !== null && $min === null) {
            throw new \InvalidArgumentException("'{$minLevel}' is not a log level; one of " . implode(', ', array_keys(self::LEVELS)) . '.');
        }
        $needle = $contains !== null ? mb_strtolower(trim($contains)) : '';
        $kept = [];
        foreach ($all as $e) {
            if ($min !== null && (self::LEVELS[$e['level']] ?? 0) < $min) continue;
            if ($needle !== '' && !str_contains(mb_strtolower($e['message'] . ' ' . $e['extra']), $needle)) continue;
            $kept[] = $e;
        }
        return array_slice($kept, -max(1, $count));
    }

    /**
     * The newest ERROR-or-worse entry across the newest files, with the entries that came
     * before it in the same file (what the process was doing when it failed).
     *
     * @return array{file:string,entry:array,before:array}|null  null = no error in the files searched
     */
    public function lastError(int $contextEntries = 15, int $filesToSearch = 3): ?array {
        foreach (array_slice($this->files(), 0, max(1, $filesToSearch)) as $file) {
            $all = $this->parse($file);
            for ($i = count($all) - 1; $i >= 0; $i--) {
                if ((self::LEVELS[$all[$i]['level']] ?? 0) >= self::LEVELS['ERROR']) {
                    return ['file' => $file, 'entry' => $all[$i], 'before' => array_slice($all, max(0, $i - $contextEntries), min($i, $contextEntries))];
                }
            }
        }
        return null;
    }

    /** @return array<int,array{time:string,channel:string,level:string,message:string,extra:string}> */
    public function parse(string $basename): array {
        $path = $this->dir . '/' . $basename;
        if (!preg_match(self::FILE_RE, $basename) || !is_file($path)) {
            throw new \InvalidArgumentException("No log file '{$basename}' in {$this->dir}.");
        }
        $size = filesize($path) ?: 0;
        $fh = fopen($path, 'rb');
        if ($fh === false) throw new \RuntimeException("{$path} exists but could not be opened for reading.");
        $skipped = false;
        if ($size > self::TAIL_BYTES) { fseek($fh, $size - self::TAIL_BYTES); $skipped = true; }
        $text = stream_get_contents($fh) ?: '';
        fclose($fh);
        $lines = preg_split("/\r\n|\n|\r/", $text);
        if ($skipped) array_shift($lines);   // a partial first line

        $entries = [];
        $cur = null;
        foreach ($lines as $line) {
            if (preg_match(self::LINE_RE, $line, $m)) {
                if ($cur !== null) $entries[] = $cur;
                [$message, $extra] = self::splitContext($m[4]);
                $cur = ['time' => $m[1], 'channel' => $m[2], 'level' => $m[3], 'message' => Redact::secrets($message), 'extra' => Redact::secrets($extra)];
            } elseif ($cur !== null && $line !== '') {
                $cur['extra'] .= ($cur['extra'] !== '' ? "\n" : '') . Redact::secrets($line);   // stack trace / continuation
            }
        }
        if ($cur !== null) $entries[] = $cur;
        return $entries;
    }

    /** Monolog appends ` {json context} [extra]`; keep the context readable, drop an empty extra. */
    private static function splitContext(string $rest): array {
        $rest = rtrim($rest);
        if (str_ends_with($rest, ' []')) $rest = substr($rest, 0, -3);
        $pos = strpos($rest, ' {');
        if ($pos !== false && str_ends_with($rest, '}')) {
            return [trim(substr($rest, 0, $pos)), trim(substr($rest, $pos + 1))];
        }
        return [trim($rest), ''];
    }

    /** One entry as the tools print it. */
    public static function format(array $e): string {
        $s = "[{$e['time']}] {$e['level']}: {$e['message']}";
        if ($e['extra'] !== '') $s .= "\n    " . str_replace("\n", "\n    ", $e['extra']);
        return $s;
    }
}
