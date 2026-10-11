<?php
/**
 * app\SheetReader — a workbook written out as text for an agent: each sheet's first rows and its
 * formulas, a calculation repeated down a column said once. Reads a real .xlsx built here.
 */
namespace tests\unit;

use PHPUnit\Framework\TestCase;
use app\SheetReader;

class SheetReaderTest extends TestCase {
    private string $file;

    protected function setUp(): void {
        $this->file = sys_get_temp_dir() . '/tiknix-sheet-' . bin2hex(random_bytes(4)) . '.xlsx';
        $z = new \ZipArchive();
        $z->open($this->file, \ZipArchive::CREATE);
        $z->addFromString('[Content_Types].xml', '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
        $z->addFromString('xl/workbook.xml', '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="Shifts" sheetId="1" r:id="rId1"/><sheet name="Rates" sheetId="2" r:id="rId2"/></sheets></workbook>');
        $z->addFromString('xl/_rels/workbook.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="x" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="x" Target="worksheets/sheet2.xml"/></Relationships>');
        $z->addFromString('xl/sharedStrings.xml', '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<si><t>Name</t></si><si><t>Hours</t></si><si><t>Pay</t></si><si><r><t>Ana </t></r><r><t>Ruiz</t></r></si><si><t>Ben | Okoro</t></si><si><t>Rate</t></si></sst>');
        $rows = '<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c><c r="C1" t="s"><v>2</v></c></row>'
              . '<row r="2"><c r="A2" t="s"><v>3</v></c><c r="B2"><v>38.5</v></c><c r="C2"><f>B2*Rates!$B$1</f><v>770</v></c></row>'
              . '<row r="3"><c r="A3" t="s"><v>4</v></c><c r="B3"><v>40</v></c><c r="C3"><f>B3*Rates!$B$1</f><v>800</v></c></row>'
              . '<row r="4"><c r="B4"><f>SUM(B2:B3)</f><v>78.5</v></c><c r="C4"><f>SUM(C2:C3)</f><v>1570</v></c></row>';
        $z->addFromString('xl/worksheets/sheet1.xml', '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>' . $rows . '</sheetData></worksheet>');
        $z->addFromString('xl/worksheets/sheet2.xml', '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
            . '<row r="1"><c r="A1" t="s"><v>5</v></c><c r="B1"><v>20</v></c></row></sheetData></worksheet>');
        $z->close();
    }

    protected function tearDown(): void { @unlink($this->file); }

    public function testAWorkbookIsToldFromAnotherZipAndFromAPicture(): void {
        $this->assertTrue(SheetReader::is($this->file));
        $zip = sys_get_temp_dir() . '/tiknix-notsheet-' . bin2hex(random_bytes(4)) . '.zip';
        $z = new \ZipArchive(); $z->open($zip, \ZipArchive::CREATE); $z->addFromString('readme.txt', 'hello'); $z->close();
        $this->assertFalse(SheetReader::is($zip));
        unlink($zip);
        $this->assertFalse(SheetReader::is(__FILE__));
    }

    public function testEachSheetItsValuesAndItsFormulasAreRead(): void {
        $b = SheetReader::read($this->file);
        $this->assertSame(['Shifts', 'Rates'], array_column($b['sheets'], 'name'));
        $s = $b['sheets'][0];
        $this->assertSame([4, 3], [$s['rows'], $s['cols']]);
        $this->assertSame('Ana Ruiz', $s['cells'][2]['A'], 'text typed with mixed formatting is joined');
        $this->assertSame('38.5', $s['cells'][2]['B']);
        $this->assertSame(4, $s['formula_count']);
        // C2 and C3 are one calculation; the two totals are different columns, so two more
        $this->assertSame(['C2', 'B4', 'C4'], array_column($s['formulas'], 'cell'));
        $this->assertSame(['=B2*Rates!$B$1', 2, 'C3'], [$s['formulas'][0]['formula'], $s['formulas'][0]['count'], $s['formulas'][0]['last']]);
        $this->assertSame([], $b['sheets'][1]['formulas']);
    }

    public function testItIsWrittenOutForAnAgent(): void {
        $md = SheetReader::markdown($this->file, 'payroll.xlsx');
        $this->assertStringContainsString('“payroll.xlsx”', $md);
        $this->assertStringContainsString('## Sheet “Shifts” — 4 rows, 3 columns used', $md);
        $this->assertStringContainsString('2 | Ana Ruiz | 38.5 | 770', $md);
        $this->assertStringContainsString('3 | Ben / Okoro | 40 | 800', $md, 'a | in a cell does not break the columns');
        $this->assertStringContainsString('- `C2`: `=B2*Rates!$B$1` — the same calculation in 2 cells, down to `C3`', $md);
        $this->assertStringContainsString('- `B4`: `=SUM(B2:B3)`', $md);
        $this->assertStringContainsString('No formulas: this sheet is data.', $md);
    }

    public function testAFileThatIsNotAWorkbookSaysSo(): void {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not an .xlsx workbook');
        SheetReader::read(__FILE__);
    }
}
