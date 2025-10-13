<?php
/**
 * Documentation Controller
 * Displays the README and other documentation
 */

namespace app;

use \Flight as Flight;

class Docs extends BaseControls\Control {
    
    /**
     * Display main documentation (README)
     */
    public function index() {
        $readmePath = BASE_PATH . '/README.md';
        $content = '';
        
        if (file_exists($readmePath)) {
            $markdown = file_get_contents($readmePath);
            
            // Simple markdown to HTML conversion
            $content = $this->parseMarkdown($markdown);
        } else {
            $content = '<div class="alert alert-warning">Documentation file not found.</div>';
        }
        
        $this->render('docs/index', [
            'title' => 'Documentation',
            'content' => $content
        ]);
    }
    
    /**
     * Simple markdown parser for basic formatting
     */
    private function parseMarkdown($text) {
        // IMPORTANT: Process code blocks FIRST to protect code from other transformations

        // Store code blocks temporarily to prevent them from being processed
        $codeBlocks = [];
        $blockCounter = 0;

        // Convert code blocks first (triple backticks)
        $text = preg_replace_callback('/```(\w*)\n(.*?)```/s', function($matches) use (&$codeBlocks, &$blockCounter) {
            $language = $matches[1] ?: 'plaintext';
            $code = htmlspecialchars($matches[2], ENT_QUOTES, 'UTF-8');
            $placeholder = "###CODEBLOCK{$blockCounter}###";
            $codeBlocks[$placeholder] = '<pre><code class="language-' . $language . '">' . $code . '</code></pre>';
            $blockCounter++;
            return $placeholder;
        }, $text);

        // Store inline code temporarily
        $inlineCode = [];
        $inlineCounter = 0;

        // Convert inline code (single backticks) - must be before other inline formatting
        $text = preg_replace_callback('/`([^`\n]+)`/', function($matches) use (&$inlineCode, &$inlineCounter) {
            $code = htmlspecialchars($matches[1], ENT_QUOTES, 'UTF-8');
            $placeholder = "###INLINECODE{$inlineCounter}###";
            $inlineCode[$placeholder] = '<code>' . $code . '</code>';
            $inlineCounter++;
            return $placeholder;
        }, $text);

        // Convert headers (must handle # in headers correctly)
        $text = preg_replace('/^#### (.*?)$/m', '<h4>$1</h4>', $text);
        $text = preg_replace('/^### (.*?)$/m', '<h3>$1</h3>', $text);
        $text = preg_replace('/^## (.*?)$/m', '<h2>$1</h2>', $text);
        $text = preg_replace('/^# (.*?)$/m', '<h1>$1</h1>', $text);

        // Convert bold and italic (order matters!)
        $text = preg_replace('/\*\*\*(.*?)\*\*\*/s', '<strong><em>$1</em></strong>', $text);
        $text = preg_replace('/\*\*(.*?)\*\*/s', '<strong>$1</strong>', $text);
        $text = preg_replace('/(?<!\*)\*(?!\*)([^*\n]+)\*(?!\*)/s', '<em>$1</em>', $text);

        // Convert links
        $text = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '<a href="$2">$1</a>', $text);

        // Convert blockquotes
        $text = preg_replace('/^> (.*)$/m', '<blockquote>$1</blockquote>', $text);

        // Convert horizontal rules
        $text = preg_replace('/^---+$/m', '<hr>', $text);

        // Convert lists (handle nested lists better)
        $lines = explode("\n", $text);
        $inList = false;
        $listHtml = '';
        $newLines = [];

        foreach ($lines as $line) {
            if (preg_match('/^(\s*)[-*+] (.*)$/', $line, $matches)) {
                if (!$inList) {
                    $inList = true;
                    $listHtml = '<ul>';
                }
                $indent = strlen($matches[1]);
                $content = $matches[2];
                $listHtml .= '<li>' . $content . '</li>';
            } else {
                if ($inList) {
                    $newLines[] = $listHtml . '</ul>';
                    $inList = false;
                    $listHtml = '';
                }
                $newLines[] = $line;
            }
        }
        if ($inList) {
            $newLines[] = $listHtml . '</ul>';
        }
        $text = implode("\n", $newLines);

        // Convert tables (simple table support)
        $text = preg_replace_callback('/^\|(.+)\|$/m', function($matches) {
            $cells = explode('|', trim($matches[1], '|'));
            $cellHtml = '';
            foreach ($cells as $cell) {
                $cellHtml .= '<td>' . trim($cell) . '</td>';
            }
            return '<tr>' . $cellHtml . '</tr>';
        }, $text);

        // Wrap table rows in table tags
        $text = preg_replace('/(<tr>.*?<\/tr>\s*)+/s', '<table class="table table-bordered">$0</table>', $text);

        // Restore inline code
        foreach ($inlineCode as $placeholder => $code) {
            $text = str_replace($placeholder, $code, $text);
        }

        // Restore code blocks
        foreach ($codeBlocks as $placeholder => $code) {
            $text = str_replace($placeholder, $code, $text);
        }

        // Convert line breaks to paragraphs (but be smarter about it)
        $paragraphs = explode("\n\n", $text);
        $html = '';
        foreach ($paragraphs as $para) {
            $para = trim($para);
            if ($para) {
                // Don't wrap if it's already an HTML element
                if (!preg_match('/^<(h[1-6]|ul|ol|pre|div|table|blockquote|hr)/i', $para)) {
                    // Don't use nl2br on content that already has block elements
                    if (preg_match('/<(li|tr|td|th)/', $para)) {
                        $html .= $para;
                    } else {
                        $html .= '<p>' . nl2br($para) . '</p>';
                    }
                } else {
                    $html .= $para;
                }
            }
        }

        return $html;
    }
    
    /**
     * Display API documentation
     */
    public function api() {
        $this->render('docs/api', [
            'title' => 'API Documentation'
        ]);
    }
    
    /**
     * Display CLI documentation
     */
    public function cli() {
        $content = $this->getCliHelp();

        $this->render('docs/cli', [
            'title' => 'CLI Documentation',
            'content' => $content
        ]);
    }

    /**
     * Display Caching documentation
     */
    public function caching() {
        $cachingPath = BASE_PATH . '/docs/CACHING.md';
        $content = '';

        if (file_exists($cachingPath)) {
            $markdown = file_get_contents($cachingPath);
            $content = $this->parseMarkdown($markdown);
        } else {
            // Fallback content if file doesn't exist
            $content = '<div class="alert alert-info">
                <h4>TikNix Caching System</h4>
                <p>The TikNix framework includes a sophisticated multi-tier caching system that provides:</p>
                <ul>
                    <li><strong>9.4x faster database queries</strong> with transparent query caching</li>
                    <li><strong>175,000 permission checks/second</strong> with 3-tier permission caching</li>
                    <li><strong>Zero configuration</strong> - works out of the box</li>
                    <li><strong>Multi-tenant safe</strong> - isolated cache namespaces</li>
                </ul>
                <p>For detailed documentation, please ensure <code>docs/CACHING.md</code> exists.</p>
                </div>';
        }

        $this->render('docs/caching', [
            'title' => 'Caching System Documentation',
            'content' => $content
        ]);
    }
    
    /**
     * Get CLI help content
     */
    private function getCliHelp() {
        ob_start();
        ?>
        <h2>CLI Command Reference</h2>
        
        <h3>Basic Usage</h3>
        <pre><code>php public/index.php [options]</code></pre>
        
        <h3>Options</h3>
        <table class="table">
            <thead>
                <tr>
                    <th>Option</th>
                    <th>Description</th>
                    <th>Example</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><code>--help</code></td>
                    <td>Show help message</td>
                    <td><code>php index.php --help</code></td>
                </tr>
                <tr>
                    <td><code>--control=NAME</code></td>
                    <td>Controller name (required)</td>
                    <td><code>--control=test</code></td>
                </tr>
                <tr>
                    <td><code>--method=NAME</code></td>
                    <td>Method name (default: index)</td>
                    <td><code>--method=cleanup</code></td>
                </tr>
                <tr>
                    <td><code>--member=ID</code></td>
                    <td>Member ID to run as</td>
                    <td><code>--member=1</code></td>
                </tr>
                <tr>
                    <td><code>--params=STRING</code></td>
                    <td>URL-encoded parameters</td>
                    <td><code>--params='id=5&type=pdf'</code></td>
                </tr>
                <tr>
                    <td><code>--json=JSON</code></td>
                    <td>JSON parameters</td>
                    <td><code>--json='{"key":"value"}'</code></td>
                </tr>
                <tr>
                    <td><code>--cron</code></td>
                    <td>Cron mode (suppress output)</td>
                    <td><code>--cron</code></td>
                </tr>
                <tr>
                    <td><code>--verbose</code></td>
                    <td>Verbose output</td>
                    <td><code>--verbose</code></td>
                </tr>
            </tbody>
        </table>
        
        <h3>Examples</h3>
        
        <h4>Run a simple command</h4>
        <pre><code>php public/index.php --control=test --method=hello</code></pre>
        
        <h4>Run with parameters</h4>
        <pre><code>php public/index.php --control=report --method=generate --params='type=daily&format=pdf'</code></pre>
        
        <h4>Run as specific member</h4>
        <pre><code>php public/index.php --member=1 --control=admin --method=cleanup</code></pre>
        
        <h4>Cron job example</h4>
        <pre><code>0 2 * * * /usr/bin/php /path/to/index.php --control=cleanup --method=daily --member=1 --cron</code></pre>
        <?php
        return ob_get_clean();
    }
}