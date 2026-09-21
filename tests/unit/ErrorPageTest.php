<?php
/**
 * The error pages a visitor can be shown. Two properties are held here because both have
 * already been lost once: every page is big enough that a browser does not replace it with
 * its own, and the page of last resort shows nothing about the server.
 */

namespace tests\unit;

use PHPUnit\Framework\TestCase;

class ErrorPageTest extends TestCase {

    /** Below this, some browsers substitute their own "friendly" error screen for the server's. */
    private const BROWSER_THRESHOLD = 512;

    public static function setUpBeforeClass(): void {
        require_once dirname(__DIR__, 2) . '/lib/fatal-handler.php';
    }

    public function testTheLastResortPageIsBigEnoughToBeShown(): void {
        $page = tiknix_error_page(500, 'Server Error', 'Something went wrong on our end.');
        $this->assertGreaterThanOrEqual(self::BROWSER_THRESHOLD, strlen($page));
        $this->assertStringContainsString('<title>500 - Server Error</title>', $page);
    }

    public function testTheLastResortPageNeedsNothingElse(): void {
        $page = tiknix_error_page(500, 'Server Error', 'x');
        // No stylesheet, script, image or font to fetch: whatever broke may have broken those too.
        $this->assertDoesNotMatchRegularExpression('/<link\b|<script\b|<img\b|url\(/i', $page);
    }

    public function testTheLastResortPageEscapesWhatItIsGiven(): void {
        $page = tiknix_error_page(500, '<script>alert(1)</script>', 'in /var/www/html <b>x</b>');
        $this->assertStringNotContainsString('<script>alert(1)</script>', $page);
        $this->assertStringNotContainsString('<b>x</b>', $page);
    }

    /** @dataProvider errorViews */
    public function testEveryErrorViewIsBigEnoughToBeShown(string $view): void {
        $file = dirname(__DIR__, 2) . "/views/error/{$view}.php";
        $this->assertFileExists($file);
        // The source is a floor for the rendered page: the markup and CSS are static, and the
        // PHP in these views only ever adds to them.
        $this->assertGreaterThanOrEqual(self::BROWSER_THRESHOLD, filesize($file), "views/error/{$view}.php");
    }

    public function errorViews(): array {
        return [['403'], ['404'], ['500']];
    }

    public function testTheFrontControllerLoadsTheHandlerBeforeAnythingElse(): void {
        // CODE only — comments stripped by the tokenizer. The file's own comment quotes the old
        // ini_set line to explain why it went, and a text match would read that as the line.
        $index = '';
        foreach (token_get_all(file_get_contents(dirname(__DIR__, 2) . '/public/index.php')) as $tok) {
            if (is_array($tok) && in_array($tok[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
            $index .= is_array($tok) ? $tok[1] : $tok;
        }
        $handler  = strpos($index, "lib/fatal-handler.php");
        $autoload = strpos($index, 'bootstrap.php');
        $this->assertNotFalse($handler, 'public/index.php must require lib/fatal-handler.php');
        $this->assertLessThan($autoload, $handler, 'the handler must be registered before the app boots — a fatal during boot is the case it exists for');
        $this->assertStringNotContainsString("ini_set('display_errors', 1)", $index, 'raw PHP errors must not be shown unconditionally');
    }
}
