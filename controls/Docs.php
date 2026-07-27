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
        $content = file_exists($readmePath)
            ? $this->parse(file_get_contents($readmePath))
            : '<div class="alert alert-warning">Documentation file not found.</div>';

        $this->render('docs/index', [
            'title' => 'Documentation',
            'content' => $content
        ]);
    }

    /**
     * Render one of the repo's own markdown files.
     *
     * The README links to its siblings the way a README should — `[…](REDBEAN_README.md)`
     * — which is right in a checkout and a 404 on the web, where nothing serves a bare
     * .md from the project root. Rather than mangle the README to suit the browser, this
     * route serves those files, and parse() rewrites the links to point here.
     *
     * Only markdown, only from the project root or docs/, and the resolved path is
     * checked against those roots after realpath — so no amount of ../ reaches conf/.
     */
    public function file() {
        $name = (string) $this->getParam('name', '');
        $path = $this->docPath($name);

        if ($path === '') {
            Flight::notFound();
            return;
        }

        $this->render('docs/index', [
            'title'   => basename($path),
            'content' => $this->parse(file_get_contents($path)),
        ]);
    }

    /** Absolute path of a permitted doc, or '' if the name is not one. */
    private function docPath(string $name): string {
        if ($name === '' || substr(strtolower($name), -3) !== '.md') return '';

        $real = realpath(BASE_PATH . '/' . $name);
        if ($real === false || !is_file($real)) return '';

        $root = realpath(BASE_PATH);
        foreach ([$root, $root . '/docs'] as $allowed) {
            // Root itself holds only top-level docs; docs/ may nest.
            if ($allowed === $root && dirname($real) === $root) return $real;
            if ($allowed !== $root && strpos($real, $allowed . '/') === 0) return $real;
        }
        return '';
    }

    /** Parse markdown, then point its relative .md links at a route that serves them. */
    private function parse(string $markdown): string {
        $html = MarkdownParser::parse($markdown);
        return preg_replace_callback(
            '/href="(?!https?:|\/|#)([^"#]+\.md)(#[^"]*)?"/i',
            fn($m) => 'href="/docs/file?name=' . rawurlencode($m[1]) . '"',
            $html
        );
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
        // Render the CLI help view to capture its output
        ob_start();
        Flight::render('docs/cli_help');
        $content = ob_get_clean();

        $this->render('docs/cli', [
            'title' => 'CLI Documentation',
            'content' => $content
        ]);
    }

    /**
     * Display Tutorials page
     */
    public function tutorials() {
        $this->render('docs/tutorials', [
            'title' => 'Tutorials'
        ]);
    }

    /**
     * Display Workbench documentation
     */
    public function workbench() {
        $workbenchPath = BASE_PATH . '/docs/WORKBENCH.md';
        $content = '';

        if (file_exists($workbenchPath)) {
            $content = $this->parse(file_get_contents($workbenchPath));
        } else {
            $content = '<div class="alert alert-warning">AI Projects documentation not found.</div>';
        }

        $this->render('docs/workbench', [
            'title' => 'AI Projects Documentation',
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
            $content = $this->parse(file_get_contents($cachingPath));
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
}