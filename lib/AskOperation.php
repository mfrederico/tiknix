<?php
/**
 * AskOperation — "do this to my data", from Ask: recording a delivery, adding a product, changing a
 * price, marking an order paid. Not a build (nothing in the code changes) and not an answer.
 *
 * 1. The app's agent, read-only, reads the code and writes the few lines that do it with what the
 *    app already has — its models and their methods — and says in a sentence what they will do
 *    (brief(), parse()). Nothing has run.
 * 2. The person reads it and presses Do it (a project admin only). A restore point is taken
 *    (TenantHost::checkpoint: the code's tag and a copy of each database), then the lines run once
 *    in the app's container, as the app (run()). What they printed is shown. Nothing is committed.
 *
 * What is refused before anything runs (refusal()): a shell or a file write, and SQL that changes
 * data or tables — the app's own methods keep their rules; a hand-written UPDATE skips them.
 */

namespace app;

class AskOperation {
    public const TIMEOUT = 60;

    /** The read-only run that writes the operation. */
    public static function brief(string $said, string $before = ''): string {
        return <<<MD
# Ask — do something to this app's data

Someone who runs this app typed the message at the end of this file. They want something DONE to the
app's records — not the app changed. You are in the app's own directory; you can only READ (Read, Grep,
Glob, describe, database_schema, database_query). Nothing you write runs until they press a button.

Find how the app itself does this — the model and method its own page uses (a controller action, a
`Model_*::method()`), and write the few lines of PHP that do exactly what they asked with that, once.

Rules for the code:
- Use the app's own models and methods, or `\app\Bean` (load/dispense/store/trash) when the app has no
  method for it. Never SQL that writes, never `R::`, never a shell command or a file write.
- Look up what you need by name first (`Bean::findOne('material', 'name = ?', ['Flour'])`) and stop with
  a clear `throw new \RuntimeException('…')` if it is not there — never create something they did not ask for.
- End by `echo`-ing one line saying what was done, with the numbers ("Recorded 10 000 g of Flour from Mill Co; stock is now 9 500 g.").
- The app is already started for you (autoloader, database, config): no `require`, no bootstrap.

Answer in exactly this shape:

1. One or two plain sentences: what will happen, with the numbers. No code words.
2. One ```php block with the code (no `<?php`).

If the app has no way to do it, or you cannot tell exactly what they mean (which supplier? how much?),
write instead one line: `CANNOT: ` and what is missing or what would have to be built.
{$before}
## The message

{$said}
MD;
    }

    /** ['summary' => …, 'code' => …] or ['cannot' => why]. Throws when it is neither. */
    public static function parse(string $text): array {
        $text = trim($text);
        if (preg_match('/^\s*`?CANNOT:\s*(.+)$/mi', $text, $m)) return ['cannot' => trim($m[1], " `\t")];
        if (!preg_match('/```php\s*\n(.*?)```/s', $text, $c)) throw new \UnexpectedValueException('the agent wrote no code for it, and did not say why: ' . mb_substr($text, 0, 200));
        $code = trim((string) preg_replace('/^\s*<\?php\s*/', '', $c[1]));
        $summary = trim(substr($text, 0, (int) strpos($text, $c[0])));
        $summary = trim((string) preg_replace('/^\s*1\.\s*/', '', $summary));
        if ($code === '') throw new \UnexpectedValueException('the agent wrote an empty code block');
        if ($summary === '') throw new \UnexpectedValueException('the agent did not say what the code will do');
        return ['summary' => mb_substr($summary, 0, 1200), 'code' => $code];
    }

    /** Why this code may not run, or ''. Checked when it is written AND again before it runs. */
    public static function refusal(string $code): string {
        if (preg_match('/\b(exec|shell_exec|system|passthru|proc_open|popen|pcntl_exec|file_put_contents|fopen|unlink|rmdir|rename|copy|mkdir|chmod|eval|include|require|require_once|include_once)\s*\(/i', $code, $m)) return "it calls {$m[1]}() — a shell, a file or more code; an action on records needs none";
        if (str_contains($code, '`')) return 'it runs a shell command (backticks)';
        if (preg_match('/\b(require|include)(_once)?\b/i', $code, $m)) return 'it loads another file (' . strtolower($m[0]) . ')';
        if (preg_match('/\bR::/', $code)) return 'it uses R:: directly; the app\'s models and \\app\\Bean keep the app\'s rules';
        if (preg_match('/[\'"][^\'"]*\b(INSERT\s+INTO|UPDATE\s+\w+\s+SET|DELETE\s+FROM|DROP\s+|ALTER\s+TABLE|CREATE\s+TABLE|TRUNCATE)\b/i', $code, $m)) return 'it writes SQL (' . strtoupper(trim($m[1])) . '); the app\'s own methods do this with their rules';
        if (preg_match('/->exec\s*\(|Bean::exec\s*\(/i', $code)) return 'it runs a raw statement';
        return '';
    }

    /** The file that runs in the app: the app started, then the code. */
    public static function script(string $code): string {
        return "<?php\n// Ask: an action on this app's records, run once by its owner from the Builder (app\\AskOperation). Not part of the app.\n"
             . "require '/srv/app/vendor/autoload.php';\nrequire_once \\app\\Paths::runtime() . '/bin/_boot.php';\nnew \\app\\Bootstrap();\n"
             . "use app\\Bean;\n" . $code . "\n";
    }

    /**
     * Run it once, in the app: a restore point first, then the code. ['ok', 'output', 'checkpoint'].
     * A restore point that cannot be taken stops it — never run without one.
     */
    public static function run(object $tenant, int $promptId, string $code, array $author): array {
        if (($why = self::refusal($code)) !== '') return ['ok' => false, 'output' => 'Not run: ' . $why . '.', 'checkpoint' => ''];
        $tag = 'checkpoint-ask-' . $promptId . '-' . date('Ymd-His');
        try { $c = TenantHost::checkpoint($tenant, $tag, 'before an action asked for in Ask (#' . $promptId . ')', $author); }
        catch (\RuntimeException $e) { $c = ['ok' => false, 'error' => $e->getMessage()]; }
        if (empty($c['ok'])) return ['ok' => false, 'output' => 'Not run: no restore point could be taken (' . (string) ($c['error'] ?? '?') . ').', 'checkpoint' => ''];
        $file = '.aibuilder/ops/ask-' . $promptId . '.php';
        [$code1, $out1] = TenantHost::ssh($tenant, 'app', 'cd /srv/app && mkdir -p .aibuilder/ops && cat > ' . escapeshellarg($file), self::script($code), 30);
        if ($code1 !== 0) return ['ok' => false, 'output' => 'Not run: the code could not be put in the app: ' . mb_substr(trim((string) $out1), 0, 300), 'checkpoint' => $tag];
        [$rc, $out] = TenantHost::ssh($tenant, 'app', 'cd /srv/app && timeout ' . self::TIMEOUT . ' php ' . escapeshellarg($file) . ' 2>&1', null, self::TIMEOUT + 15);
        return ['ok' => $rc === 0, 'output' => mb_substr(trim((string) $out), -2000) ?: ($rc === 0 ? 'Done (it printed nothing).' : 'It stopped with exit ' . $rc . ' and printed nothing.'), 'checkpoint' => $tag];
    }
}
