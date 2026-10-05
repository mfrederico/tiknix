<?php
/**
 * The project notebook (app\Notebook): what an agent proposes is read out of its final message,
 * added once to the right document and committed as the task's member; a person's edit replaces a
 * document whole; and what a prompt is handed is bounded.
 */
namespace tests\unit;

use PHPUnit\Framework\TestCase;
use app\Notebook;

class NotebookTest extends TestCase {
    private string $root;
    private array $envBefore = [];
    protected function setUp(): void {
        $this->root = sys_get_temp_dir() . '/tiknix-notebook-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0700, true);
        $this->git('init -q');
        foreach (['GIT_AUTHOR_NAME' => 'Dana', 'GIT_AUTHOR_EMAIL' => 'dana@example.test', 'GIT_COMMITTER_NAME' => 'Dana', 'GIT_COMMITTER_EMAIL' => 'dana@example.test'] as $k => $v) {
            $this->envBefore[$k] = getenv($k); putenv("{$k}={$v}");
        }
    }
    protected function tearDown(): void {
        foreach ($this->envBefore as $k => $v) putenv($v === false ? $k : "{$k}={$v}");
        exec('rm -rf ' . escapeshellarg($this->root));
    }
    private function git(string $args): string {
        exec('env -u GIT_DIR -u GIT_WORK_TREE -u GIT_INDEX_FILE git -C ' . escapeshellarg($this->root) . ' ' . $args . ' 2>&1', $o);
        return trim(implode("\n", $o));
    }

    public function testEntriesAreReadFromTheNotebookSectionOnly(): void {
        $out = "## Done\nBuilt the vault.\n- lesson: this line is under another heading and is not an entry\n\n## Notebook\n"
             . "- decision: Vault files are keyed by a hash of member id + password, so a password change must rekey.\n"
             . "- **map**: The sidebar is `views/app/nav.php`, registered in lib/app.php.\n"
             . "* Lesson:   z.ai has no embeddings endpoint —   Searchables needs its own Embeddings agent.\n"
             . "- lesson: none\n- todo: not a kind\nsome prose\n\n## Handoff\n- lesson: this is the handoff, not the notebook\n";
        $e = Notebook::parse($out);
        $this->assertSame(['decisions', 'map', 'lessons'], array_column($e, 'doc'));
        $this->assertSame('z.ai has no embeddings endpoint — Searchables needs its own Embeddings agent.', $e[2]['text'], 'whitespace is collapsed');
        $this->assertSame([], Notebook::parse("## Done\nAll good.\n## Handoff\n- nothing"), 'no section, no entries');
        $many = "## Notebook\n" . str_repeat("- lesson: a distinct lesson number that is long enough\n", 20);
        $this->assertCount(Notebook::MAX_PER_RUN, Notebook::parse($many));
        $this->assertSame(Notebook::MAX_ENTRY, mb_strlen(Notebook::parse("## Notebook\n- map: " . str_repeat('x', 900))[0]['text']));
    }

    public function testEntriesAreAddedOnceWithTheirSourceAndCommittedAsTheMember(): void {
        $entries = Notebook::parse("## Notebook\n- map: The sidebar is views/app/nav.php, registered in lib/app.php.\n- lesson: Seeds go in services/Schema/Seeds, numbered from 50.\n");
        $this->assertSame(['added' => 2, 'skipped' => 0], Notebook::add($this->root, $entries, 'task #5'));
        $map = file_get_contents("{$this->root}/agent/notebook/map.md");
        $this->assertStringStartsWith("# Map\n", $map);
        $this->assertMatchesRegularExpression('/^- The sidebar is views\/app\/nav\.php, registered in lib\/app\.php\. _\(task #5, \d{4}-\d\d-\d\d\)_$/m', $map);
        $this->assertSame('Dana <dana@example.test> Notebook: 2 entries from task #5', $this->git('log -1 --format="%an <%ae> %s"'));
        $this->assertSame('', $this->git('status --porcelain'));
        // the same words again (another task learned the same thing) are not added twice
        $again = [['doc' => 'map', 'text' => 'the sidebar is views/app/nav.php,  registered in lib/app.php'], ['doc' => 'decisions', 'text' => 'Prices are stored in cents.']];
        $this->assertSame(['added' => 1, 'skipped' => 1], Notebook::add($this->root, $again, 'task #6'));
        $this->assertSame(1, substr_count(file_get_contents("{$this->root}/agent/notebook/map.md"), 'The sidebar is'));
    }

    public function testAPersonsEditReplacesTheDocumentAndEmptyRemovesIt(): void {
        Notebook::add($this->root, [['doc' => 'lessons', 'text' => 'A lesson that turned out to be wrong.']], 'task #2');
        Notebook::set($this->root, 'lessons', "# Lessons\r\n\r\n- The corrected lesson.\r\n");
        $this->assertSame("# Lessons\n\n- The corrected lesson.\n", file_get_contents("{$this->root}/agent/notebook/lessons.md"));
        $this->assertSame('Notebook: Lessons edited', $this->git('log -1 --format=%s'));
        Notebook::set($this->root, 'lessons', "  \n");
        $this->assertFileDoesNotExist("{$this->root}/agent/notebook/lessons.md");
        $this->assertSame('', $this->git('status --porcelain'), 'the removal is committed');
        $this->expectException(\InvalidArgumentException::class);
        Notebook::set($this->root, '../etc', 'x');
    }

    public function testNoAuthorIsAFaultAndNothingIsLeftStaged(): void {
        putenv('GIT_AUTHOR_NAME'); putenv('GIT_AUTHOR_EMAIL');
        try { Notebook::add($this->root, [['doc' => 'map', 'text' => 'Something worth knowing here.']], 'task #1'); $this->fail('committed with no author'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('no author was given', $e->getMessage()); }
        $this->assertSame('', $this->git('diff --cached --name-only'));
    }

    public function testThePromptSectionHoldsTheDocumentsAndHowToAddAndIsBounded(): void {
        $empty = Notebook::brief($this->root);
        $this->assertStringContainsString('Nothing recorded yet', $empty);
        $this->assertStringContainsString('section headed exactly `## Notebook`', $empty);
        $this->assertStringContainsString('BEFORE `## Handoff`', $empty);
        Notebook::add($this->root, [['doc' => 'decisions', 'text' => 'Prices are stored in cents.']], 'task #3');
        $old = "# Lessons\n\n" . str_repeat("- an old lesson that fills the page up to its budget and beyond it\n", 400) . "- THE NEWEST LESSON\n";
        Notebook::set($this->root, 'lessons', $old);
        $b = Notebook::brief($this->root);
        $this->assertStringContainsString('### Decisions', $b);
        $this->assertStringContainsString('Prices are stored in cents.', $b);
        $this->assertSame(1, substr_count($b, 'What was decided and why'), 'what a document is for is said once, in its heading');
        $this->assertStringContainsString('THE NEWEST LESSON', $b, 'when a document outgrows the budget the newest entries are the ones kept');
        $this->assertStringContainsString('older entries are in agent/notebook/lessons.md', $b);
        $this->assertLessThan(Notebook::BRIEF_BUDGET + 2500, mb_strlen($b));
        $this->assertStringNotContainsString('### Map', $b, 'a document with nothing in it is not listed');
    }

    /* ---- the notebook tool: a terminal session reads and adds; a build task is told how instead ---- */

    private function tool(string $root): \app\mcptools\NotebookTool {
        return new class($root) extends \app\mcptools\NotebookTool {
            public function __construct(private string $r) {}
            protected function installRoot(): string { return $this->r; }
        };
    }

    public function testTheToolReadsAndATerminalAdds(): void {
        $t = $this->tool($this->root);
        $this->assertStringContainsString('Nothing recorded yet', $t->execute([]));
        $said = $t->execute(['add' => [['kind' => 'lesson', 'text' => 'The reports page needs the mcp grant, not admin.'], ['kind' => 'map', 'text' => 'Reports live in controls/Reports.php and views/reports.']]]);
        $this->assertStringContainsString('2 added', $said);
        $read = $t->execute([]);
        $this->assertStringContainsString('The reports page needs the mcp grant', $read);
        $this->assertStringContainsString('_(a terminal session, ', $read);
        $this->assertSame('Dana', $this->git('log -1 --format=%an'));
        foreach ([[['kind' => 'todo', 'text' => 'a perfectly long enough sentence']], [['kind' => 'map', 'text' => 'short']]] as $bad) {
            try { $t->execute(['add' => $bad]); $this->fail('accepted ' . json_encode($bad)); } catch (\Exception $e) { $this->addToAssertionCount(1); }
        }
    }

    public function testABuildTaskIsRefusedAndToldHow(): void {
        $wt = $this->root . '/.aibuilder/wt/plan-1-task-2'; mkdir($wt, 0700, true);
        $said = $this->tool($wt)->execute(['add' => [['kind' => 'lesson', 'text' => 'Something a task learned along the way.']]]);
        $this->assertStringContainsString('Not added', $said);
        $this->assertStringContainsString('`## Notebook`', $said);
        $this->assertDirectoryDoesNotExist($wt . '/agent/notebook');
    }

    public function testTheRepositorysOwnIdentityAuthorsATerminalsEntry(): void {
        putenv('GIT_AUTHOR_NAME'); putenv('GIT_AUTHOR_EMAIL'); putenv('GIT_COMMITTER_NAME'); putenv('GIT_COMMITTER_EMAIL');
        $this->git('config user.name "Terminal Tess"'); $this->git('config user.email tess@example.test');
        $this->assertSame(1, Notebook::add($this->root, [['doc' => 'map', 'text' => 'Added with the repository identity set.']], 'a terminal session')['added']);
        $this->assertSame('Terminal Tess', $this->git('log -1 --format=%an'));
    }
}
