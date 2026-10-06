<?php
/**
 * app\Ui — pages described as data. What it must hold: everything is escaped, a spec that would
 * make a page hard to use is refused with the fix named, and a typo in a key is an error rather
 * than a page quietly missing a part.
 */
namespace tests\unit;

use PHPUnit\Framework\TestCase;
use app\Ui;

class UiTest extends TestCase {
    private const EMPTY = ['title' => 'No customers yet', 'text' => 'They appear here after their first order.', 'action' => ['label' => 'Add a customer', 'url' => '/cafe/customer']];

    protected function setUp(): void {
        if (session_status() !== PHP_SESSION_ACTIVE) @session_start();   // csrf_field() keeps its token in the session
    }

    private function refused(callable $fn, string $pattern): void {
        try { $fn(); } catch (\InvalidArgumentException $e) { $this->assertMatchesRegularExpression($pattern, $e->getMessage()); return; }
        $this->fail('expected a refusal matching ' . $pattern);
    }

    public function testAPageHasOnePrimaryActionAndFoldsManyOthersIntoAMenu(): void {
        $h = Ui::page(['title' => 'Customers <b>', 'lead' => 'Everyone who ordered.', 'back' => ['label' => 'Cafe', 'url' => '/cafe'],
            'primary' => ['label' => 'Add a customer', 'url' => '/cafe/customer', 'icon' => 'plus-lg'],
            'more' => [['label' => 'Export', 'url' => '/cafe/export']]]);
        $this->assertStringContainsString('<h1>Customers &lt;b&gt;</h1>', $h);
        $this->assertSame(1, substr_count($h, 'btn btn-primary'));
        $this->assertStringNotContainsString('dropdown', $h, 'one further action sits beside the primary');
        $many = Ui::page(['title' => 'X', 'more' => [['label' => 'A', 'url' => '/a'], ['label' => 'B', 'url' => '/b'], ['label' => 'C', 'url' => '/c']]]);
        $this->assertSame(3, substr_count($many, 'dropdown-item'), 'three would compete with the primary');
        $this->refused(fn() => Ui::page(['title' => 'X', 'primary' => [['label' => 'A', 'url' => '/a'], ['label' => 'B', 'url' => '/b']]]), '/ONE action/');
        $this->refused(fn() => Ui::page(['titel' => 'X']), "/unknown key 'titel'/");
        $this->refused(fn() => Ui::page(['lead' => 'x']), "/needs 'title'/");
    }

    public function testAListShowsItsRowsAndSaysWhatItIsForWhenEmpty(): void {
        $cols = [['label' => 'Name', 'value' => 'name', 'url' => fn($r) => '/c/' . $r['id'], 'sub' => fn($r) => $r['email']],
                 ['label' => 'State', 'value' => 'state', 'as' => 'badge', 'tones' => ['live' => 'success']],
                 ['label' => 'Spent', 'value' => 'spent', 'as' => 'money'],
                 ['label' => 'Since', 'value' => 'since', 'as' => 'date', 'quiet' => true]];
        $h = Ui::table(['rows' => [['id' => 7, 'name' => '<Ann>', 'email' => 'a@x.test', 'state' => 'live', 'spent' => 1234.5, 'since' => '2026-03-04 00:00:00'],
                                   ['id' => 8, 'name' => 'Bo', 'email' => '', 'state' => '', 'spent' => null, 'since' => '']],
                        'columns' => $cols, 'empty' => self::EMPTY, 'title' => 'All', 'count' => 'customer',
                        'actions' => fn($r) => [['label' => 'Edit', 'url' => '/c/edit/' . $r['id'], 'icon' => 'pencil'],
                                                ['label' => 'Delete', 'post' => '/c/delete', 'fields' => ['id' => $r['id']], 'confirm' => 'Delete it?', 'danger' => true]]]);
        $this->assertStringContainsString('<a class="ui-row-link" href="/c/7">&lt;Ann&gt;</a>', $h);
        $this->assertStringContainsString('ui-badge-success">Live<', $h);
        $this->assertStringContainsString('1,234.50', $h);
        $this->assertStringContainsString('Mar 4, 2026', $h);
        $this->assertStringContainsString('2 customers', $h);
        $this->assertStringContainsString('data-ui-confirm="Delete it?"', $h);
        $this->assertStringContainsString('name="id" value="8"', $h);
        $this->assertGreaterThanOrEqual(3, substr_count($h, 'ui-none'), 'an empty value reads as a dash, never as a blank');
        $this->assertSame(substr_count($h, '<th '), substr_count($h, '<td ') / 2, 'every row has a cell under every heading');

        $empty = Ui::table(['rows' => [], 'columns' => $cols, 'empty' => self::EMPTY]);
        $this->assertStringContainsString('No customers yet', $empty);
        $this->assertStringContainsString('href="/cafe/customer"', $empty);
        $this->assertStringNotContainsString('<table', $empty);
    }

    public function testAListThatCannotBeScannedOrADeleteThatDoesNotAskIsRefused(): void {
        $wide = array_map(fn($i) => ['label' => "C{$i}", 'value' => 'x'], range(1, Ui::MAX_COLUMNS + 1));
        $this->refused(fn() => Ui::table(['rows' => [['x' => 1]], 'columns' => $wide, 'empty' => self::EMPTY]), '/more than a person can scan/');
        $this->refused(fn() => Ui::table(['rows' => [], 'columns' => [['label' => 'A', 'value' => 'x']]]), "/needs 'empty'/");
        $one = [['label' => 'A', 'value' => 'x']];
        $this->refused(fn() => Ui::table(['rows' => [['x' => 1]], 'columns' => $one, 'empty' => self::EMPTY,
            'actions' => fn($r) => [['label' => 'Delete', 'post' => '/d', 'danger' => true]]]), '/must ask first/');
        $this->refused(fn() => Ui::table(['rows' => [['x' => 1]], 'columns' => $one, 'empty' => self::EMPTY,
            'actions' => fn($r) => [['label' => 'Delete', 'url' => '/d', 'confirm' => 'Sure?', 'danger' => true]]]), '/never a link/');
        $this->refused(fn() => Ui::table(['rows' => [['x' => 'soon']], 'columns' => [['label' => 'When', 'value' => 'x', 'as' => 'date']], 'empty' => self::EMPTY]), '/not a date/');
    }

    public function testAFormLabelsEveryFieldMarksTheOptionalOnesAndShowsProblemsWhereTheyAre(): void {
        $h = Ui::form(['action' => '/cafe/customer', 'submit' => 'Save customer', 'cancel' => '/cafe/customers', 'hidden' => ['id' => 5],
            'values' => ['name' => 'A "quoted" name', 'tier' => 'gold', 'news' => 1, 'starts' => '2026-10-05 09:30:00'],
            'problems' => ['email' => 'That is not an email address.'],
            'fields' => [['name' => 'name', 'label' => 'Name', 'required' => true, 'max' => 80],
                         ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true, 'help' => 'Receipts go here.'],
                         ['name' => 'tier', 'label' => 'Tier', 'type' => 'select', 'options' => ['std' => 'Standard', 'gold' => 'Gold'], 'width' => 6],
                         ['name' => 'starts', 'label' => 'Starts', 'type' => 'datetime', 'width' => 6],
                         ['name' => 'news', 'label' => 'Send the newsletter', 'type' => 'switch'],
                         ['name' => 'notes', 'label' => 'Notes', 'type' => 'textarea', 'more' => true]]]);
        $this->assertStringContainsString(csrf_field(), $h, 'a POST form carries the CSRF field');
        $this->assertStringContainsString('value="A &quot;quoted&quot; name"', $h);
        $this->assertStringContainsString('maxlength="80"', $h);
        $this->assertStringContainsString('<option value="gold" selected>', $h);
        $this->assertStringContainsString('value="2026-10-05T09:30"', $h);
        $this->assertStringContainsString('id="f-email-problem">That is not an email address.', $h);
        $this->assertStringContainsString('aria-describedby="f-email-help f-email-problem"', $h);
        $this->assertSame(3, substr_count($h, 'ui-optional'), 'tier, starts and notes are the optional ones');
        $this->assertStringContainsString('<details class="ui-more">', $h, 'an empty rarely-needed field stays folded');
        $this->assertStringContainsString('>Save customer</button>', $h);
        $open = Ui::form(['action' => '/x', 'submit' => 'Save', 'values' => ['notes' => 'hello'], 'fields' => [['name' => 'notes', 'label' => 'Notes', 'more' => true]]]);
        $this->assertStringContainsString('<details class="ui-more" open>', $open, 'one that has a value is shown');
    }

    public function testAFormThatIsAWallOrHasAnUnlabelledFieldIsRefused(): void {
        $wall = array_map(fn($i) => ['name' => "f{$i}", 'label' => "F{$i}"], range(1, Ui::MAX_FLAT_FIELDS + 1));
        $this->refused(fn() => Ui::form(['action' => '/x', 'submit' => 'Save', 'fields' => $wall]), '/is a wall/');
        $wall[5]['section'] = 'Billing';
        $this->assertStringContainsString('ui-form-section', Ui::form(['action' => '/x', 'submit' => 'Save', 'fields' => $wall]));
        $this->refused(fn() => Ui::form(['action' => '/x', 'submit' => 'Save', 'fields' => [['name' => 'a', 'placeholder' => 'Name']]]), "/needs a 'label'/");
        $this->refused(fn() => Ui::form(['action' => '/x', 'submit' => 'Save', 'fields' => [['name' => 'a', 'label' => 'A', 'type' => 'dropdown']]]), '/is not one of/');
        $this->refused(fn() => Ui::form(['action' => '/x', 'fields' => [['name' => 'a', 'label' => 'A']]]), "/needs 'submit'/");
    }

    public function testDetailAndNoticeEscapeAndRawIsDeliberate(): void {
        $h = Ui::detail(['title' => 'Contact', 'items' => ['Email' => '<a@x>', 'Phone' => '', 'Bio' => Ui::raw('<em>hi</em>')]]);
        $this->assertStringContainsString('&lt;a@x&gt;', $h);
        $this->assertStringContainsString('<em>hi</em>', $h);
        $this->assertStringContainsString('ui-none', $h);
        $n = Ui::notice(['tone' => 'warning', 'title' => 'No page yet.', 'text' => 'Create <one>.', 'action' => ['label' => 'Create it', 'url' => '/p']]);
        $this->assertStringContainsString('role="alert"', $n);
        $this->assertStringContainsString('Create &lt;one&gt;.', $n);
        $this->refused(fn() => Ui::notice(['tone' => 'purple', 'text' => 'x']), '/not one of/');
    }

    public function testAnAgendaGroupsByDaySoonestFirstAndSaysTodayInWords(): void {
        $today = date('Y-m-d'); $tomorrow = date('Y-m-d', strtotime('+1 day')); $later = date('Y-m-d', strtotime('+9 days'));
        $items = [['id' => 3, 'at' => "{$later} 14:00:00", 'end' => "{$later} 14:45:00", 'who' => 'Later <one>', 'st' => 'confirmed', 'note' => ''],
                  ['id' => 2, 'at' => "{$today} 16:30:00", 'end' => "{$today} 17:00:00", 'who' => 'Second today', 'st' => 'pending', 'note' => 'bring notes'],
                  ['id' => 1, 'at' => "{$today} 09:00:00", 'end' => "{$today} 09:45:00", 'who' => 'First today', 'st' => 'pending', 'note' => ''],
                  ['id' => 4, 'at' => "{$tomorrow} 10:00:00", 'end' => "{$tomorrow} 10:30:00", 'who' => 'Tomorrow one', 'st' => '', 'note' => '']];
        $spec = ['items' => $items, 'at' => 'at', 'until' => 'end', 'title' => 'who', 'sub' => 'note', 'empty' => self::EMPTY,
                 'badge' => ['value' => 'st', 'tones' => ['confirmed' => 'success', 'pending' => 'warning'], 'labels' => ['pending' => 'Waiting for confirmation']],
                 'actions' => fn($r) => [['label' => 'Confirm', 'post' => '/a/confirm', 'fields' => ['id' => $r['id']]],
                                         ['label' => 'Cancel', 'post' => '/a/cancel', 'fields' => ['id' => $r['id']], 'confirm' => 'Cancel it?', 'danger' => true]]];
        $h = Ui::agenda($spec);
        $this->assertSame(3, substr_count($h, 'class="ui-day"'), 'three days');
        $this->assertLessThan(strpos($h, 'Second today'), strpos($h, 'First today'), 'within a day, by time');
        $this->assertLessThan(strpos($h, 'Tomorrow one'), strpos($h, 'Second today'));
        $this->assertLessThan(strpos($h, 'Later &lt;one&gt;'), strpos($h, 'Tomorrow one'));
        $this->assertStringContainsString('<strong>Today</strong>', $h);
        $this->assertStringContainsString('<strong>Tomorrow</strong>', $h);
        $this->assertStringContainsString('9:00 am</time><span class="ui-until"> – 9:45 am</span>', $h);
        $this->assertStringContainsString('ui-badge-warning">Waiting for confirmation<', $h);
        $this->assertStringContainsString('bring notes', $h);
        $this->assertSame(4, substr_count($h, '>Confirm</span>'), 'one action shown per item');
        $this->assertSame(4, substr_count($h, 'data-ui-confirm="Cancel it?"'), 'the dangerous one is in each item\'s menu, and asks');

        $desc = Ui::agenda(['order' => 'desc', 'heading' => 'Earlier'] + $spec);
        $this->assertLessThan(strpos($desc, 'First today'), strpos($desc, 'Later &lt;one&gt;'), 'latest first');
        $this->assertStringContainsString('<h2 class="ui-agenda-heading">Earlier</h2>', $desc);

        $empty = Ui::agenda(['items' => []] + $spec);
        $this->assertStringContainsString('No customers yet', $empty);
        $this->refused(fn() => Ui::agenda(['items' => [['at' => 'whenever', 'who' => 'x']], 'at' => 'at', 'title' => 'who', 'empty' => self::EMPTY]), '/not a date and time/');
        $this->refused(fn() => Ui::agenda(['items' => [], 'at' => 'at', 'title' => 'who']), "/needs 'empty'/");
    }
}
