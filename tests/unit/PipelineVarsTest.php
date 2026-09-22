<?php
/** {token} resolution: unresolved stays literal AND is reported; a written fallback is not. */
namespace tests\unit;

use app\Pipeline\Vars;
use PHPUnit\Framework\TestCase;

class PipelineVarsTest extends TestCase {
    public function testUnresolvedTokensAreLeftLiteralAndReported(): void {
        Vars::takeUnresolved();
        $bag = ['context' => ['who' => 'Ada'], 'count' => ['output' => ['rows' => [['n' => '2']]]]];
        $this->assertSame('Ada', Vars::resolve('{context.who}', $bag));
        $this->assertSame('2', Vars::resolve('{count.output.rows.0.n}', $bag));
        $this->assertSame('{context.mailto}', Vars::resolve('{context.mailto}', $bag), 'literal, so a typo shows');
        $this->assertSame('x {count.output.0.n} y', Vars::resolve('x {count.output.0.n} y', $bag));
        $this->assertSame(['{context.mailto}', '{count.output.0.n}'], Vars::takeUnresolved());
        $this->assertSame([], Vars::takeUnresolved(), 'drained');
    }

    public function testAWrittenFallbackIsNotAWarning(): void {
        Vars::takeUnresolved();
        $this->assertSame('', Vars::resolve('{context.mailto|}', []));
        $this->assertNull(Vars::resolve('{cursor.output|null}', []));
        $this->assertSame('n=0', Vars::resolve('n={context.n|0}', []));
        $this->assertSame([], Vars::takeUnresolved());
    }
}
