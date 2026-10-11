<?php
namespace app;

/**
 * What is in a spreadsheet, written out as text an agent can read.
 *
 * Someone who says "build an app that does this" and attaches a workbook has handed over a
 * specification: the columns are the data, the formulas are the rules. An agent cannot open an
 * .xlsx (it is a zip of XML), so the request carries this beside it: every sheet, the rows it
 * starts with, and its formulas — each distinct formula once, with how far down it is repeated,
 * because a column of 400 copies of one calculation is one rule.
 *
 * Reads .xlsx only (the format since 2007). No library: a workbook is a zip of a few small XML
 * files, and what is needed from them is cell values and formulas. Styles are not read, so a
 * date shows as the number the workbook stores (days since 1900) — the text says so.
 * A file that is not a workbook, or is too large to read safely, throws with the reason.
 */
class SheetReader {
    public const ROWS = 60;      // rows of each sheet written out
    public const COLS = 30;
    public const FORMULAS = 200; // distinct formulas written out per sheet
    private const ENTRY_BYTES = 25000000;   // one XML part, unpacked
    private const SHEETS = 20;

    /** Is this file a workbook this can read? (A zip that holds xl/workbook.xml.) */
    public static function is(string $file): bool {
        $z = new \ZipArchive();
        if ($z->open($file, \ZipArchive::RDONLY) !== true) return false;
        $ok = $z->locateName('xl/workbook.xml') !== false;
        $z->close();
        return $ok;
    }

    /**
     * @return array{sheets: array<int, array{name:string, rows:int, cols:int, cells: array<int, array<string,string>>, formulas: array<int, array{cell:string, formula:string, count:int, last:string}>, formula_count:int}>}
     */
    public static function read(string $file): array {
        $z = new \ZipArchive();
        if ($z->open($file, \ZipArchive::RDONLY) !== true) throw new \RuntimeException('it is not an .xlsx workbook (it does not open as one)');
        try {
            $part = function (string $name) use ($z): ?\SimpleXMLElement {
                $st = $z->statName($name);
                if ($st === false) return null;
                if ((int) $st['size'] > self::ENTRY_BYTES) throw new \RuntimeException("it is too large to read ({$name} is " . round($st['size'] / 1048576) . ' MB unpacked) — attach only the sheets that matter, or export them as CSV');
                $xml = @simplexml_load_string((string) $z->getFromName($name), 'SimpleXMLElement', LIBXML_NONET | LIBXML_COMPACT);
                if ($xml === false) throw new \RuntimeException("{$name} in it cannot be read as XML");
                return $xml;
            };
            $book = $part('xl/workbook.xml');
            if (!$book) throw new \RuntimeException('it is not an .xlsx workbook (no xl/workbook.xml in it)');
            // which file holds which sheet
            $targets = [];
            if ($rels = $part('xl/_rels/workbook.xml.rels')) foreach ($rels->Relationship as $r) $targets[(string) $r['Id']] = (string) $r['Target'];
            $strings = [];
            if ($ss = $part('xl/sharedStrings.xml')) {
                foreach ($ss->si as $si) {
                    $t = isset($si->t) ? (string) $si->t : '';
                    foreach ($si->r as $run) $t .= (string) $run->t;   // text typed with mixed formatting
                    $strings[] = $t;
                }
            }
            $out = ['sheets' => []];
            $ns = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
            foreach ($book->sheets->sheet as $s) {
                if (count($out['sheets']) >= self::SHEETS) break;
                $rid = (string) $s->attributes($ns)['id'];
                $target = $targets[$rid] ?? '';
                if ($target === '') continue;
                $path = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/' . $target;
                $xml = $part($path);
                if (!$xml) continue;
                $cells = []; $formulas = []; $shapes = []; $rows = 0; $cols = 0; $fcount = 0;
                foreach ($xml->sheetData->row as $row) {
                    foreach ($row->c as $c) {
                        $ref = (string) $c['r'];
                        if (!preg_match('/^([A-Z]{1,3})(\d+)$/', $ref, $m)) continue;
                        $r = (int) $m[2]; $col = self::colNumber($m[1]);
                        $t = (string) $c['t'];
                        $v = $t === 's' ? ($strings[(int) $c->v] ?? '') : ($t === 'inlineStr' ? (string) $c->is->t : ($t === 'b' ? ((string) $c->v === '1' ? 'TRUE' : 'FALSE') : (string) $c->v));
                        if ($v === '' && !isset($c->f)) continue;
                        $rows = max($rows, $r); $cols = max($cols, $col);
                        if ($r <= self::ROWS && $col <= self::COLS) $cells[$r][$m[1]] = $v;
                        if (isset($c->f)) {
                            $f = trim((string) $c->f);
                            if ($f === '') continue;   // a shared formula's later cells carry no text: they are the same rule
                            $fcount++;
                            // the same calculation on another row is the same rule: row numbers taken out
                            $shape = $m[1] . '|' . preg_replace('/(?<![A-Za-z_.])(\$?[A-Z]{1,3})\$?\d+/', '$1#', $f);
                            if (!isset($shapes[$shape])) { $shapes[$shape] = count($formulas); $formulas[] = ['cell' => $ref, 'formula' => '=' . $f, 'count' => 1, 'last' => $ref]; }
                            else { $formulas[$shapes[$shape]]['count']++; $formulas[$shapes[$shape]]['last'] = $ref; }
                        }
                    }
                }
                ksort($cells);
                $out['sheets'][] = ['name' => (string) $s['name'], 'rows' => $rows, 'cols' => $cols, 'cells' => $cells,
                                    'formulas' => array_slice($formulas, 0, self::FORMULAS), 'formula_count' => $fcount];
            }
            if (!$out['sheets']) throw new \RuntimeException('it has no sheets that can be read');
            return $out;
        } finally { $z->close(); }
    }

    /** The workbook as Markdown: what an agent is given to read in its place. */
    public static function markdown(string $file, string $calledName): string {
        $book = self::read($file);
        $md = "# The spreadsheet “{$calledName}”, written out\n\n"
            . count($book['sheets']) . ' sheet' . (count($book['sheets']) === 1 ? '' : 's') . ': ' . implode(', ', array_map(fn($s) => '“' . $s['name'] . '”', $book['sheets'])) . ".\n"
            . "Values are as the workbook stores them: a date may show as a number (days since 1900-01-01), a percentage as a fraction.\n";
        foreach ($book['sheets'] as $s) {
            $md .= "\n## Sheet “{$s['name']}” — {$s['rows']} rows, {$s['cols']} columns used\n";
            if (!$s['cells']) { $md .= "\nEmpty.\n"; continue; }
            $letters = [];
            for ($i = 1; $i <= min($s['cols'], self::COLS); $i++) $letters[] = self::colLetters($i);
            $md .= "\nIts first " . min($s['rows'], self::ROWS) . " rows (row number, then columns " . $letters[0] . '–' . end($letters) . ", separated by ` | `):\n\n```\n";
            foreach ($s['cells'] as $r => $row) {
                $md .= $r . ' | ' . rtrim(implode(' | ', array_map(fn($l) => str_replace(["\n", '|'], [' ', '/'], mb_substr((string) ($row[$l] ?? ''), 0, 80)), $letters)), ' |') . "\n";
            }
            $md .= "```\n";
            if ($s['rows'] > self::ROWS) $md .= "\n(" . ($s['rows'] - self::ROWS) . " more rows are not written out.)\n";
            if ($s['formulas']) {
                $md .= "\nIts formulas — {$s['formula_count']} cells, " . count($s['formulas']) . " different calculations. Each is one RULE of what this sheet does:\n\n";
                foreach ($s['formulas'] as $f) {
                    $md .= "- `{$f['cell']}`: `" . str_replace('`', "'", mb_substr($f['formula'], 0, 300)) . '`' . ($f['count'] > 1 ? " — the same calculation in {$f['count']} cells, down to `{$f['last']}`" : '') . "\n";
                }
            } else {
                $md .= "\nNo formulas: this sheet is data.\n";
            }
        }
        return $md;
    }

    private static function colNumber(string $letters): int {
        $n = 0;
        foreach (str_split($letters) as $ch) $n = $n * 26 + (ord($ch) - 64);
        return $n;
    }

    private static function colLetters(int $n): string {
        $s = '';
        while ($n > 0) { $n--; $s = chr(65 + $n % 26) . $s; $n = intdiv($n, 26); }
        return $s;
    }
}
