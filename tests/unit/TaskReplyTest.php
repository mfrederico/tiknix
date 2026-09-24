<?php
/**
 * add_task_log { as_reply: true } — what a Task Board agent's stop hook sends at the end
 * of every turn, through the project's MCP server (scripts/hooks/workbench-response-capture.php).
 *
 *   a reply     saved in the task's conversation as Claude (a taskcomment, not a log line)
 *   hand-back   a task still `running` becomes `awaiting` — the user's turn
 *   no undo     a task someone already moved on (merged, completed, …) is left as it is
 *   no text     nothing to keep is still a finished turn: handed back, no comment
 *
 * Runs the tool's reply step on a scratch database the way it runs on a project
 * (project-scoped: the task board is that project's own), so no real task is touched.
 */

namespace tests\unit;

require_once __DIR__ . '/ConceptsTestCase.php';

use app\Bean;
use app\mcptools\workbench\AddTaskLogTool;

class TaskReplyTest extends ConceptsTestCase {

    private const DB = 'task-reply-test';

    protected function setUp(): void {
        parent::setUp();
        self::memoryDb();
        if (!Bean::hasDatabase(self::DB)) Bean::addDatabase(self::DB, 'sqlite::memory:');
        Bean::selectDatabase(self::DB);
        \RedBeanPHP\R::nuke();
    }

    protected function tearDown(): void { Bean::selectDatabase('default'); parent::tearDown(); }

    private function task(string $status): \RedBeanPHP\OODBBean {
        $t = Bean::dispense('workbenchtask');
        $t->title = 'Fix: something'; $t->status = $status; $t->memberId = 1; $t->progressMessage = 'Working...';
        Bean::store($t);
        return $t;
    }

    private function reply(\RedBeanPHP\OODBBean $task, string $text): array {
        $m = new \ReflectionMethod(AddTaskLogTool::class, 'reply');
        return json_decode($m->invoke(new AddTaskLogTool(null, (object) ['id' => 1, 'level' => 1]), $task, $text, true), true);
    }

    public function testAReplyIsSavedAsClaudeAndARunningTaskIsHandedBack(): void {
        $t = $this->task('running');
        $r = $this->reply($t, 'Fixed the 200s: /img now sends 400, 404 and 500.');
        $this->assertTrue($r['released']);
        $this->assertSame(['awaiting', 'Waiting for user input'], [(string) Bean::load('workbenchtask', $t->id)->status, (string) Bean::load('workbenchtask', $t->id)->progressMessage]);
        $c = Bean::load('taskcomment', $r['comment_id']);
        $this->assertSame([(int) $t->id, 1, 0], [(int) $c->taskId, (int) $c->isFromClaude, (int) $c->isInternal]);
        $this->assertStringContainsString('/img now sends 400', (string) $c->content);
    }

    public function testATaskAlreadyMovedOnIsNotUndone(): void {
        $t = $this->task('merged');
        $r = $this->reply($t, 'A late reply after the merge.');
        $this->assertFalse($r['released']);
        $this->assertSame('merged', (string) Bean::load('workbenchtask', $t->id)->status);
        $this->assertGreaterThan(0, $r['comment_id'], 'the reply is still kept');
    }

    public function testNoTextStillHandsBackButSavesNoComment(): void {
        $t = $this->task('running');
        $r = $this->reply($t, '   ');
        $this->assertSame([true, 0], [$r['released'], $r['comment_id']]);
        $this->assertSame(0, Bean::count('taskcomment'));
    }
}
