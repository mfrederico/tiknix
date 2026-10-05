<?php
/**
 * app\UiPattern decides which pages a kind of record needs from its shape, and app\PageCheck
 * names what a view does by hand that app\Ui decides. Both are rules: these pin the rules.
 */
namespace tests\unit;

use PHPUnit\Framework\TestCase;
use app\UiPattern;
use app\PageCheck;

class UiPatternTest extends TestCase {
    private function cols(array $names): array { return array_fill_keys($names, ''); }

    public function testTheShapeOfTheRecordPicksThePattern(): void {
        $p = fn(string $bean, array $cols, array $ctx = []) => UiPattern::suggest($bean, $this->cols($cols), $ctx)['pattern'];
        $this->assertSame('list-form', $p('healthitem', ['id', 'name', 'dose', 'notes', 'member_id', 'created_at'], ['parents' => ['member']]));
        $this->assertSame('calendar', $p('booking', ['id', 'title', 'starts_at', 'ends_at', 'member_id'], ['parents' => ['member']]));
        $this->assertSame('thread', $p('ticketreply', ['id', 'ticket_id', 'member_id', 'body', 'created_at'], ['parents' => ['ticket', 'member']]));
        $this->assertSame('settings', $p('preference', ['id', 'member_id', 'theme', 'digest'], ['parents' => ['member'], 'one_each' => true]));
        $this->assertSame('child-list', $p('orderline', ['id', 'order_id', 'product', 'quantity', 'price'], ['parents' => ['order']]));
        $this->assertSame('list-record', $p('customer', ['id', 'name', 'email'], ['children' => ['order']]));
        $this->assertSame('list-record', $p('property', ['id', 'name', 'street', 'city', 'region', 'postcode', 'country', 'bedrooms', 'bathrooms', 'area', 'notes']));
        $this->assertSame('gallery', $p('artwork', ['id', 'title', 'photo', 'artist']));
        $this->assertSame('list-form', $p('article', ['id', 'title', 'body', 'created_at']), 'a timestamp the app writes is not "when it happens"');
    }

    public function testTheListShowsWhatTellsRowsApartAndTheFormAsksOnlyWhatAPersonDecides(): void {
        $s = UiPattern::suggest('contact', ['id' => 'INTEGER', 'name' => 'TEXT', 'email' => 'TEXT', 'message' => 'TEXT', 'status' => 'TEXT', 'ip_address' => 'TEXT',
            'user_agent' => 'TEXT', 'read_at' => 'TEXT', 'member_id' => 'INTEGER', 'api_token' => 'TEXT', 'stripe_eid' => 'TEXT', 'created_at' => 'TEXT'], ['parents' => ['member']]);
        $this->assertLessThanOrEqual(5, count($s['list']));
        $this->assertSame('name', $s['list'][0]['column']);
        $this->assertTrue($s['list'][0]['main']);
        $this->assertSame('badge', $s['list'][1]['as']);
        $asked = array_column($s['form'], 'name');
        $this->assertSame(['name', 'email', 'message', 'status'], $asked, 'what the app records, a secret, an outside id and the owner are never asked');
        $left = implode("\n", $s['leave_out']);
        foreach (['member_id', 'api_token', 'stripe_eid', 'ip_address', 'read_at'] as $c) $this->assertStringContainsString("`{$c}`", $left);
        $this->assertStringContainsString('Ui::table', $s['spec']);
        $this->assertStringContainsString("'name' => 'message', 'label' => 'Message', 'type' => 'textarea'", $s['spec']);
        $this->assertStringContainsString('only their own', implode(' ', $s['notes']));
    }

    public function testALongFormFoldsItsTailAndNamesReadAsWords(): void {
        $s = UiPattern::suggest('event', $this->cols(['title', 'starts_at', 'venue', 'city', 'capacity', 'is_public', 'has_parking', 'dress_code', 'contact_phone']));
        $more = array_column(array_filter($s['form'], fn($f) => !empty($f['more'])), 'name');
        $this->assertContains('is_public', $more);
        $this->assertNotContains('title', $more);
        $this->assertNotContains('starts_at', $more);
        $this->assertSame('Starts', UiPattern::label('starts_at'));
        $this->assertSame('Active', UiPattern::label('is_active'));
        $this->assertSame('Who sees it', UiPattern::label('audience'));
        $this->assertSame('Class', UiPattern::label('class_id'));
        $this->assertSame('money', UiPattern::kind('unit_price', 'REAL'));
        $this->assertSame('secret', UiPattern::kind('password_hash', 'TEXT'));
    }

    /* ---- PageCheck ---- */

    public function testAHandBuiltPageIsToldWhatToUseInstead(): void {
        $src = <<<'HTML'
<div class="container py-4">
  <a class="btn btn-primary" href="/a">New</a> <a class="btn btn-primary" href="/b">Import</a>
  <?php foreach ($_SESSION['flash'] ?? [] as $m): ?><div class="alert alert-info"><?= $m ?></div><?php endforeach; ?>
  <div class="card"><table class="table"><tr><td>x</td></tr></table></div>
  <form onsubmit="return confirm('Sure?')"><button>Delete</button></form>
  <i style="color: #6c757d"></i>
</div>
HTML;
        $rules = array_column(PageCheck::check($src), 'rule');
        foreach (['table', 'card', 'alert', 'confirm', 'flash', 'container', 'colour', 'primary', 'heading'] as $r) $this->assertContains($r, $rules, $r);
        $table = current(array_filter(PageCheck::check($src), fn($i) => $i['rule'] === 'table'));
        $this->assertSame(4, $table['line']);
        $this->assertStringContainsString('Ui::table()', $table['message']);
    }

    public function testAPageDescribedWithUiIsCleanAndAHandMadeOneSaysSo(): void {
        $ui = "<?= \\app\\Ui::page(['title' => 'Customers']) ?>\n<?= \\app\\Ui::table(['rows' => \$rows, 'columns' => [], 'empty' => []]) ?>\n";
        $this->assertSame([], PageCheck::check($ui));
        $calendar = "<?php // ui: hand-made — an agenda grouped by day ?>\n<?= \\app\\Ui::page(['title' => 'Classes']) ?>\n<div class=\"ui-panel\"><table><tr><td>Mon</td></tr></table></div>\n";
        $this->assertSame([], PageCheck::check($calendar), 'a page that says it is hand-made may have its own table');
        $still = array_column(PageCheck::check("<?php // ui: hand-made — a chat ?>\n<h1>Chat</h1><button onclick=\"confirm('x')\">x</button>"), 'rule');
        $this->assertSame(['confirm'], $still, 'but it still does not use the browser confirm');
        $this->assertSame([], array_column(PageCheck::check('<p>part</p>', true), 'rule'), 'a partial has no heading of its own');
    }

    public function testViewsAreCheckedByPathAndTheShellsPartsAreNot(): void {
        $root = sys_get_temp_dir() . '/pagecheck-' . bin2hex(random_bytes(4));
        mkdir("{$root}/views/cafe", 0700, true); mkdir("{$root}/views/layouts", 0700, true); mkdir("{$root}/views/emails", 0700, true);
        file_put_contents("{$root}/views/cafe/index.php", '<h1>Cafe</h1><table></table>');
        file_put_contents("{$root}/views/cafe/_row.php", '<td>x</td>');
        file_put_contents("{$root}/views/layouts/layout.php", '<table></table>');
        file_put_contents("{$root}/views/emails/welcome.php", '<table></table>');
        try {
            $all = PageCheck::views($root);
            $this->assertSame(['views/cafe/index.php'], array_values(array_unique(array_column($all, 'view'))));
            $this->assertSame([], PageCheck::views($root, ['views/cafe/_row.php']));
            $this->assertStringStartsWith('views/cafe/index.php:1 — ', PageCheck::lines($all)[0]);
        } finally {
            foreach (glob("{$root}/views/*/*") as $f) unlink($f);
            foreach (glob("{$root}/views/*") as $d) rmdir($d);
            rmdir("{$root}/views"); rmdir($root);
        }
    }
}
