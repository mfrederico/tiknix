<?php
/**
 * The validator MCP tools (validate_php, full_validation, check_redbean, check_flightphp)
 * from where a Task Board agent calls them: its PROJECT's MCP server, which runs walled to
 * the project (open_basedir) while the agent's files are in a task workspace outside it.
 *
 *   code      every tool takes the source itself, so the workspace does not have to be
 *             readable; messages name the caller's label, not a temp file
 *   outside   a path outside the install is refused with what to do instead (pass code)
 *   relative  resolves against THIS install — it used to resolve one level too high
 */

namespace tests\unit;

use app\mcptools\CheckRedbeanTool;
use app\mcptools\FullValidationTool;
use app\mcptools\ValidatePhpTool;
use PHPUnit\Framework\TestCase;

class ValidatorToolsTest extends TestCase {

    private function run_(object $tool, array $args): array {
        return json_decode($tool->execute($args), true);
    }

    public function testCodeIsCheckedAndErrorsNameTheCallersFile(): void {
        $ok = $this->run_(new ValidatePhpTool(), ['code' => "<?php\necho 'fine';\n", 'label' => 'controls/Img.php']);
        $this->assertTrue($ok['valid']);

        $bad = $this->run_(new ValidatePhpTool(), ['code' => "<?php\nfunction (\n", 'label' => 'controls/Img.php']);
        $this->assertFalse($bad['valid']);
        $this->assertStringContainsString('controls/Img.php', implode("\n", $bad['errors']));
        $this->assertStringNotContainsString('tkval', implode("\n", $bad['errors']), 'the temp file is not named');
    }

    public function testFullValidationOnCodeRunsTheConventionChecks(): void {
        $r = $this->run_(new FullValidationTool(), ['code' => "<?php\nuse RedBeanPHP\\R;\n\$b = R::dispense('widget');\nR::store(\$b);\n", 'label' => 'controls/Widget.php']);
        $this->assertNotEmpty(array_merge($r['errors'], $r['warnings']), 'raw R:: is reported');
        $this->assertSame('controls/Widget.php', $r['path']);
    }

    public function testAPathOutsideTheInstallIsRefusedWithWhatToDoInstead(): void {
        // Another install's file — as a task workspace is to a PROJECT's server.
        $elsewhere = dirname(__DIR__, 3) . '/some-other-project.tiknix/controls/Img.php';
        try {
            (new CheckRedbeanTool())->execute(['path' => $elsewhere]);
            $this->fail('read a path outside the install');
        } catch (\Exception $e) {
            $this->assertStringContainsString('Pass the file\'s contents as `code`', $e->getMessage());
        }
        $this->expectExceptionMessage('outside this install');
        (new ValidatePhpTool())->execute(['path' => '/etc/passwd']);
    }

    public function testARelativePathIsThisInstalls(): void {
        $r = $this->run_(new ValidatePhpTool(), ['path' => 'lib/Bean.php']);
        $this->assertTrue($r['valid'], implode("\n", $r['errors']));
        $this->assertTrue($this->run_(new ValidatePhpTool(), ['path' => 'mcptools'])['valid'], 'a directory is checked file by file');
    }
}
