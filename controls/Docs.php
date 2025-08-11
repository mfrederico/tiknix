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
        // Convert headers
        $text = preg_replace('/^### (.*?)$/m', '<h3>$1</h3>', $text);
        $text = preg_replace('/^## (.*?)$/m', '<h2>$1</h2>', $text);
        $text = preg_replace('/^# (.*?)$/m', '<h1>$1</h1>', $text);
        
        // Convert bold and italic
        $text = preg_replace('/\*\*\*(.*?)\*\*\*/s', '<strong><em>$1</em></strong>', $text);
        $text = preg_replace('/\*\*(.*?)\*\*/s', '<strong>$1</strong>', $text);
        $text = preg_replace('/\*(.*?)\*/s', '<em>$1</em>', $text);
        
        // Convert inline code
        $text = preg_replace('/`([^`]+)`/', '<code>$1</code>', $text);
        
        // Convert code blocks
        $text = preg_replace_callback('/```(\w*)\n(.*?)```/s', function($matches) {
            $language = $matches[1] ?: 'plaintext';
            $code = htmlspecialchars($matches[2], ENT_QUOTES, 'UTF-8');
            return '<pre><code class="language-' . $language . '">' . $code . '</code></pre>';
        }, $text);
        
        // Convert links
        $text = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '<a href="$2">$1</a>', $text);
        
        // Convert lists
        $text = preg_replace_callback('/^(\s*)[-*] (.*)$/m', function($matches) {
            $indent = strlen($matches[1]) / 2;
            $class = $indent > 0 ? ' style="margin-left: ' . ($indent * 20) . 'px;"' : '';
            return '<li' . $class . '>' . $matches[2] . '</li>';
        }, $text);
        
        // Wrap consecutive list items in ul tags
        $text = preg_replace('/(<li.*?<\/li>\s*)+/s', '<ul>$0</ul>', $text);
        
        // Convert line breaks to paragraphs
        $paragraphs = explode("\n\n", $text);
        $html = '';
        foreach ($paragraphs as $para) {
            $para = trim($para);
            if ($para) {
                // Don't wrap if it's already an HTML element
                if (!preg_match('/^<(h[1-6]|ul|ol|pre|div|table|blockquote)/i', $para)) {
                    $html .= '<p>' . nl2br($para) . '</p>';
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