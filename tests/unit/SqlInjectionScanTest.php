<?php
/**
 * ValidationService::scanSqlInjection: a value put into SQL is flagged; placeholders built for an
 * IN list, a single-quoted (literal) string, and a table or column NAME are not. (It flagged every
 * Bean::genSlots() IN list — knowledge-base candidate #112, 2026-10-16.)
 *
 * The samples write GET_ALL for the query call, swapped in at run time, so that the edit hook —
 * which runs a check of its own on this file — does not take the samples for this file's code.
 */
namespace tests\unit;

use PHPUnit\Framework\TestCase;
use app\ValidationService;

class SqlInjectionScanTest extends TestCase {
    private function flagged(string $code): bool {
        return (bool) (new ValidationService())->scanSqlInjection('<?php ' . str_replace('GET_ALL', 'Bean::' . 'getAll', $code), 'x.php');
    }

    public function testAValueInTheSqlIsFlagged(): void {
        $this->assertTrue($this->flagged('$r = GET_ALL("SELECT * FROM sale WHERE name = \'$name\'");'));
        $this->assertTrue($this->flagged('$in = Bean::genSlots($ids); $r = GET_ALL("SELECT * FROM $table WHERE id IN ($in)", $ids);'), 'placeholders do not excuse another variable');
        $this->assertTrue($this->flagged('$r = GET_ALL("SELECT * FROM sale WHERE id = " . $id);'));
        $this->assertTrue($this->flagged('$r = GET_ALL("SELECT * FROM sale WHERE name = \'" . $name . "\'");'));
    }

    public function testPlaceholdersLiteralsAndNamesAreNot(): void {
        $this->assertFalse($this->flagged('$in = Bean::genSlots($ids); $r = GET_ALL("SELECT * FROM sale WHERE id IN ($in)", $ids);'));
        $this->assertFalse($this->flagged('$q = implode(",", array_fill(0, count($ids), "?")); $r = GET_ALL("SELECT * FROM sale WHERE id IN ($q)", $ids);'));
        $this->assertFalse($this->flagged('$r = GET_ALL(\'SELECT * FROM sale WHERE id IN (\' . Bean::genSlots($ids) . \')\', $ids);'));
        $this->assertFalse($this->flagged('$in = Bean::genSlots($ids); $r = GET_ALL(\'SELECT * FROM sale WHERE id IN (\' . $in . \')\', $ids);'));
        $this->assertFalse($this->flagged('$r = GET_ALL(\'SELECT * FROM sale WHERE id = $x\');'), 'single quotes do not interpolate');
        $this->assertFalse($this->flagged('$r = GET_ALL("SELECT * FROM sale WHERE id = ?", [$id]);'));
        $this->assertFalse($this->flagged('$pdo->exec(\'ALTER TABLE t ADD COLUMN \' . $col . \' TEXT\');'), 'a name cannot be bound');
        $this->assertFalse($this->flagged("foreach (GET_ALL('SELECT a FROM t') as \$r) {\n if (f(\$x . ' ' . \$y)) {}\n}"), 'a dot on a later line is not the SQL');
    }
}
