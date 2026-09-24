<?php
/**
 * lib/PlanNotifier — one conversation per plan OF ONE PROJECT, and its owner is in it.
 *
 * Plan ids restart in every project (each has its own workbench.db). Keyed on the id alone,
 * two projects' plan #32 shared one conversation owned by whoever finished first.
 */

namespace tests\unit;

use app\Bean;
use app\PlanNotifier;
use PHPUnit\Framework\TestCase;

class PlanNotifierThreadTest extends TestCase {

    private string $db;
    private $savedLog = null;

    protected function setUp(): void {
        // PlanNotifier installs a FILE logger when Flight has none (it runs in a bare CLI);
        // put back what was there, or later tests write into the real log/app-*.log.
        $this->savedLog = \Flight::get('log');
        $quiet = new \Monolog\Logger('test');
        $quiet->pushHandler(new \Monolog\Handler\NullHandler());
        \Flight::set('log', $quiet);
        $this->db = sys_get_temp_dir() . '/plannotify-test-' . getmypid() . '-' . bin2hex(random_bytes(3)) . '.db';
        $pdo = new \PDO('sqlite:' . $this->db);
        // No email on the members: the notifier emails only an address it has, so nothing leaves.
        $pdo->exec("CREATE TABLE member (id INTEGER PRIMARY KEY, email TEXT DEFAULT '', username TEXT DEFAULT '', level INTEGER DEFAULT 100, status TEXT DEFAULT 'active')");
        $pdo->exec("INSERT INTO member (id, username) VALUES (1, 'ann'), (2, 'bo')");
        $pdo->exec("CREATE TABLE thread (id INTEGER PRIMARY KEY, subject TEXT, related_type TEXT, related_id INTEGER, project_slug TEXT DEFAULT '', owner_member_id INTEGER, message_count INTEGER, status TEXT, last_direction TEXT, last_preview TEXT, last_message_at TEXT, created_at TEXT, updated_at TEXT, kind TEXT DEFAULT '')");
        $pdo->exec("CREATE TABLE threadmember (id INTEGER PRIMARY KEY, thread_id INTEGER, member_id INTEGER, role TEXT, last_read_id INTEGER DEFAULT 0, muted INTEGER DEFAULT 0, joined_at TEXT, read_at TEXT)");
        $pdo = null;
    }

    protected function tearDown(): void {
        \Flight::set('log', $this->savedLog);
        if (Bean::hasDatabase('default')) Bean::selectDatabase('default');
        @unlink($this->db);
    }

    private function finish(string $slug, int $member, string $title): void {
        $out = PlanNotifier::planFinished($this->db, ['plan_id' => 32, 'title' => $title, 'slug' => $slug, 'member_id' => $member, 'status' => 'done', 'counts' => ['merged' => 1]]);
        $this->assertStringStartsWith('notify: thread #', $out, $out);
    }

    public function testTheSamePlanNumberInTwoProjectsIsTwoConversations(): void {
        $this->finish('serenity', 1, 'Serenity build');
        $this->finish('collectiq', 2, 'Collectiq build');
        $this->finish('collectiq', 2, 'Collectiq build');   // a rebuild continues its own

        $pdo = new \PDO('sqlite:' . $this->db);
        $rows = $pdo->query("SELECT project_slug, owner_member_id, message_count FROM thread ORDER BY id")->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertSame([
            ['project_slug' => 'serenity',  'owner_member_id' => 1, 'message_count' => 1],
            ['project_slug' => 'collectiq', 'owner_member_id' => 2, 'message_count' => 2],
        ], array_map(fn($r) => array_map(fn($v) => is_numeric($v) ? (int) $v : $v, $r), $rows));
        $seated = $pdo->query("SELECT thread_id, member_id FROM threadmember ORDER BY thread_id")->fetchAll(\PDO::FETCH_NUM);
        $this->assertSame([[1, 1], [2, 2]], array_map(fn($r) => array_map('intval', $r), $seated), 'each owner is seated in their own conversation, not the other');
    }
}
