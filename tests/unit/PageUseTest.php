<?php
/**
 * app\PageUse::judge — what the permission check's own count can say about a page: never opened,
 * almost never, gone, served elsewhere, too new, or not told apart under a wildcard rule.
 */
namespace tests\unit;

use PHPUnit\Framework\TestCase;
use app\PageUse;

class PageUseTest extends TestCase {
    private int $now;
    protected function setUp(): void { $this->now = strtotime('2026-10-12 12:00:00'); }

    private function rule(string $c, string $m, int $hits, int $daysOld, int $level = 100): array {
        return ['control' => $c, 'method' => $m, 'level' => $level, 'validcount' => $hits, 'created_at' => date('Y-m-d H:i:s', $this->now - $daysOld * 86400), 'description' => ''];
    }
    private function verdicts(array $rules, array $linked = []): array {
        $pages = ['cafe' => ['index', 'customers', 'export'], 'shop' => ['index']];
        $out = [];
        foreach (PageUse::judge($rules, fn(string $c) => $pages[$c] ?? null, $linked, $this->now) as $r) $out[$r['control'] . '/' . $r['method']] = $r;
        return $out;
    }

    public function testNeverRareUsedAndNew(): void {
        $v = $this->verdicts([$this->rule('cafe', 'index', 0, 60), $this->rule('cafe', 'customers', 3, 90), $this->rule('cafe', 'export', 400, 90), $this->rule('shop', 'index', 0, 3)]);
        $this->assertSame('never', $v['cafe/index']['verdict']);
        $this->assertSame('rare', $v['cafe/customers']['verdict'], '3 times in 90 days is under once a week');
        $this->assertSame('used', $v['cafe/export']['verdict']);
        $this->assertSame('new', $v['shop/index']['verdict'], 'three days is not long enough to say never');
    }

    public function testARuleWhosePageIsGoneAndOneServedElsewhere(): void {
        $v = $this->verdicts([$this->rule('cafe', 'refund', 12, 80), $this->rule('grocery', '*', 0, 80), $this->rule('workbench', '*', 18000, 80)]);
        $this->assertSame('gone', $v['cafe/refund']['verdict'], 'the controller is here, the page is not');
        $this->assertSame('gone', $v['grocery/*']['verdict'], 'no controller and never used');
        $this->assertSame('elsewhere', $v['workbench/*']['verdict'], 'no controller here, but counted: something else serves it — it is not gone');
    }

    public function testAWildcardOverSeveralPagesCannotBeToldApart(): void {
        $v = $this->verdicts([$this->rule('cafe', '*', 5000, 80), $this->rule('shop', '*', 5000, 80)]);
        $this->assertSame('unclear', $v['cafe/*']['verdict']);
        $this->assertSame(3, $v['cafe/*']['pages']);
        $this->assertSame('used', $v['shop/*']['verdict'], 'a wildcard over ONE page is that page\'s count');
        // a page with a rule of its own is not counted by the wildcard
        $v = $this->verdicts([$this->rule('cafe', '*', 5000, 80), $this->rule('cafe', 'export', 1, 80), $this->rule('cafe', 'customers', 1, 80)]);
        $this->assertSame('used', $v['cafe/*']['verdict']);
        $this->assertSame(1, $v['cafe/*']['pages']);
    }

    public function testAMenuLinkIsNoted(): void {
        $v = $this->verdicts([$this->rule('cafe', 'customers', 0, 60), $this->rule('cafe', 'export', 0, 60)], ['cafe/customers' => true]);
        $this->assertTrue($v['cafe/customers']['linked'], 'in a menu and never opened: the first thing to look at');
        $this->assertFalse($v['cafe/export']['linked']);
    }
}
