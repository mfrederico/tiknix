<?php
/**
 * The runtime's pages load nothing from another company's servers: libraries and fonts are the app's own copies
 * (/rt/vendor). A visitor's address must not reach a CDN or Google Fonts before they agree to anything (GDPR) —
 * and a copy that is named but missing would be a broken page, so every /rt/vendor file a page names exists.
 */

namespace tests\unit;

use PHPUnit\Framework\TestCase;

class NoThirdPartyAssetsTest extends TestCase {

    private const HOSTS = '#https?://(cdn\.jsdelivr\.net|fonts\.googleapis\.com|fonts\.gstatic\.com|code\.jquery\.com|cdnjs\.cloudflare\.com|unpkg\.com|esm\.sh|cdn\.tailwindcss\.com|ajax\.googleapis\.com|stackpath\.bootstrapcdn\.com|maxcdn\.bootstrapcdn\.com)#i';

    private function files(): \Generator {
        $rt = \app\Paths::runtime();
        foreach (['views', 'lib', 'controls', 'public/js', 'public/css'] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("{$rt}/{$dir}", \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) if (preg_match('/\.(php|js|css|html)$/', $f->getFilename())) yield substr($f->getPathname(), strlen($rt) + 1) => (string) file_get_contents($f->getPathname());
        }
    }

    public function testNoPageLoadsFromACdnOrGoogleFonts(): void {
        $found = [];
        foreach ($this->files() as $path => $src) {
            if (preg_match_all(self::HOSTS, $src, $m)) $found[] = $path . ': ' . implode(', ', array_unique($m[0]));
        }
        $this->assertSame([], $found, 'Serve it from public/vendor (see its README) instead');
    }

    public function testEveryVendorFileAPageNamesIsThere(): void {
        $rt = \app\Paths::runtime();
        $missing = [];
        foreach ($this->files() as $path => $src) {
            preg_match_all('#/rt/vendor/([A-Za-z0-9._/-]+\.[a-z0-9]+)\b#', $src, $m);
            foreach (array_unique($m[1]) as $f) if (!is_file("{$rt}/public/vendor/{$f}")) $missing[] = "{$path}: /rt/vendor/{$f}";
        }
        foreach (glob("{$rt}/public/vendor/fonts/*.css") as $css) {
            preg_match_all('#url\((files/[^)]+)\)#', (string) file_get_contents($css), $m);
            foreach (array_unique($m[1]) as $f) if (!is_file(dirname($css) . '/' . $f)) $missing[] = basename($css) . ": {$f}";
        }
        $this->assertSame([], $missing);
    }
}
