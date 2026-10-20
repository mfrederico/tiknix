<?php
/**
 * app\ProblemMatch: the same problem in a checker's other words is one problem; different problems
 * stay apart. The cases are the reports inresonance's check filed on 2026-10-20 — twelve reports of
 * six things, three checkers each wording them their own way.
 */
namespace tests\unit;

use PHPUnit\Framework\TestCase;
use app\ProblemMatch;

class ProblemMatchTest extends TestCase {
    public function testTheSameProblemInOtherWordsIsOne(): void {
        $this->assertTrue(ProblemMatch::same('/friends shows no 6-character meet code', 'No 6-character meet code or code entry on /friends'));
        $this->assertTrue(ProblemMatch::same('Guest /social does not list 6 example profiles marked Example', '/social does not list 6 example profiles marked Example'));
        $this->assertTrue(ProblemMatch::same('Sidebar has no Community > People or My profile entry', 'Sidebar lacks Community → People and My profile entries'));
    }

    public function testDifferentProblemsStayApart(): void {
        $this->assertFalse(ProblemMatch::same('Same-site URL in a post is not linked straight to the post', '/friends shows no 6-character meet code'));
        $this->assertFalse(ProblemMatch::same('Home page has no link to /auth/register for guests', 'Person page shows no bio and no mutual-friends section'));
        $this->assertFalse(ProblemMatch::same('', 'anything at all'));
    }

    public function testFailuresFromSeveralCheckersFoldIntoOneEach(): void {
        $f = [
            ['label' => '/friends shows no 6-character meet code', 'message' => 'short', 'level' => 'root', 'screens' => ['a.png']],
            ['label' => 'Home page has no link to /auth/register for guests', 'message' => '', 'level' => 'member', 'screens' => []],
            ['label' => 'No 6-character meet code or code entry on /friends', 'message' => 'a longer account of it', 'level' => 'member', 'screens' => ['b.png']],
        ];
        $out = ProblemMatch::fold($f);
        $this->assertCount(2, $out);
        $this->assertSame('root, member', $out[0]['level']);
        $this->assertSame(['a.png', 'b.png'], $out[0]['screens']);
        $this->assertSame('a longer account of it', $out[0]['message'], 'the fuller account is kept');
        $this->assertArrayNotHasKey('_text', $out[0]);
    }
}
