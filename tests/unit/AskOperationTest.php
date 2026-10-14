<?php
/**
 * app\AskOperation: an action on an app's records, written by the app's agent and run only on Do it —
 * read from what the agent wrote, and refused when it would do more than use the app's own methods.
 */
namespace tests\unit;

use app\AskOperation;
use PHPUnit\Framework\TestCase;

class AskOperationTest extends TestCase {
    public function testWhatTheAgentWroteIsReadAsWordsAndCode(): void {
        $op = AskOperation::parse("1. Records a 10 kg Flour delivery from Mill Co; stock goes up by 10 000 g.\n\n```php\n\$m = Bean::findOne('material', 'name = ?', ['Flour']);\necho 'ok';\n```");
        $this->assertSame('Records a 10 kg Flour delivery from Mill Co; stock goes up by 10 000 g.', $op['summary']);
        $this->assertStringStartsWith('$m = Bean::findOne', $op['code']);
        $this->assertSame(['cannot' => 'which supplier?'], AskOperation::parse('CANNOT: which supplier?'));
        $this->expectException(\UnexpectedValueException::class);
        AskOperation::parse('I would record it.');
    }

    public function testOnlyTheAppsOwnMethodsMayRun(): void {
        $this->assertSame('', AskOperation::refusal("\$d = Model_Delivery::record(\$s, \$m, 10000);\necho 'done';"));
        foreach (["shell_exec('ls');", "`rm -rf /`;", "file_put_contents('x', 1);", "R::exec('x');", "Bean::exec('UPDATE material SET stock_qty = 0');",
                  "\$pdo->exec('DELETE FROM sale');", "Bean::getAll(\"UPDATE material SET stock_qty = 5\");", "require 'x.php';", "eval(\$x);"] as $bad) {
            $this->assertNotSame('', AskOperation::refusal($bad), $bad);
        }
    }

    public function testTheScriptStartsTheAppThenRunsTheCode(): void {
        $s = AskOperation::script("echo 'hi';");
        $this->assertStringStartsWith('<?php', $s);
        $this->assertStringContainsString("new \\app\\Bootstrap();", $s);
        $this->assertStringEndsWith("echo 'hi';\n", $s);
    }
}
