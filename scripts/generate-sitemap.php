#!/usr/bin/env php
<?php
/**
 * Sitemap Generator for ShipCannon
 *
 * Generates sitemap.xml from public routes in authcontrol table (level 101)
 * and URL aliases defined in routes/default.php.
 *
 * Usage: php scripts/generate-sitemap.php
 * Cron:  0 3 * * * cd /var/www/html/default/shipcannon && php scripts/generate-sitemap.php >> /dev/null 2>&1
 */

if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line\n");
}

require_once __DIR__ . '/../bootstrap.php';

use \RedBeanPHP\R as R;
use \app\Bean;
use \Flight as Flight;

// Colors for output
define('GREEN', "\033[0;32m");
define('YELLOW', "\033[1;33m");
define('BLUE', "\033[0;34m");
define('NC', "\033[0m");

echo BLUE . "\n=== ShipCannon Sitemap Generator ===\n" . NC;

try {
    $app = new app\Bootstrap('conf/config.ini');

    $baseUrl = rtrim(Flight::get('app.baseurl') ?: 'https://shipcannon.com', '/');

    // Manually load URL aliases (routes/default.php isn't loaded in CLI mode)
    require_once __DIR__ . '/../routes/default.php';
    $aliases = Flight::get('url_aliases') ?: [];

    // Build reverse map: controller::method => alias slug
    $aliasMap = [];
    foreach ($aliases as $slug => $target) {
        $key = strtolower($target[0] . '::' . $target[1]);
        $aliasMap[$key] = $slug;
    }

    echo "Base URL: {$baseUrl}\n";
    echo "URL aliases loaded: " . count($aliases) . "\n";

    // Get all public routes (level 101 = PUBLIC)
    $publicPerms = Bean::find('authcontrol', ' level = ? ', [101]);

    echo "Public authcontrol entries: " . count($publicPerms) . "\n";

    // Only include pages from these controllers in the sitemap
    $sitemapControllers = ['index'];

    // Methods to skip even if public (POST handlers, redirects, admin pages)
    $skipMethods = [
        'blogeditor',
        'handlecontactform',
        'getstarted',   // redirect to external site
        'thankyou',      // thank-you page (no SEO value)
    ];

    // Priority map for specific pages
    $priorityMap = [
        '/'                            => '1.0',
        '/pricing'                     => '0.9',
        '/contact-us'                  => '0.8',
        '/all-about-us'                => '0.8',
        '/case-study-linentablecloth'  => '0.8',
        '/blog'                        => '0.8',
        '/privacy'                     => '0.3',
        '/terms'                       => '0.3',
    ];

    // Change frequency map
    $changefreqMap = [
        '/'     => 'weekly',
        '/blog' => 'weekly',
    ];

    $defaultPriority = '0.5';
    $defaultChangefreq = 'monthly';

    $urls = [];

    foreach ($publicPerms as $perm) {
        $control = strtolower($perm->control);
        $method = strtolower($perm->method);

        // Only include whitelisted controllers
        if (!in_array($control, $sitemapControllers)) {
            continue;
        }

        // Skip specific methods
        if (in_array($method, $skipMethods)) {
            continue;
        }

        // Handle wildcards: controller|*|101 means all public methods
        if ($method === '*') {
            $className = "app\\" . ucfirst($control);
            if (!class_exists($className)) {
                continue;
            }

            $reflection = new \ReflectionClass($className);
            $methods = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC);

            foreach ($methods as $refMethod) {
                // Skip inherited methods from parent controller
                if ($refMethod->getDeclaringClass()->getName() !== $className) {
                    continue;
                }

                $mName = strtolower($refMethod->getName());

                // Skip internal/private-like methods
                if (str_starts_with($mName, '_') || str_starts_with($mName, 'handle')) {
                    continue;
                }

                if (in_array($mName, $skipMethods)) {
                    continue;
                }

                // Check if there's a non-public specific override in authcontrol
                $restrictedPerm = Bean::findOne('authcontrol', ' LOWER(control) = ? AND LOWER(method) = ? AND level < 101 ', [$control, $mName]);
                if ($restrictedPerm) {
                    echo YELLOW . "  Skipping {$control}/{$mName} (restricted to level {$restrictedPerm->level})" . NC . "\n";
                    continue;
                }

                $url = buildUrl($control, $mName, $aliasMap);
                if ($url !== null) {
                    $urls[$url] = true;
                }
            }
        } else {
            $url = buildUrl($control, $method, $aliasMap);
            if ($url !== null) {
                $urls[$url] = true;
            }
        }
    }

    // Add blog posts as individual URLs
    $postsDir = __DIR__ . '/../views/index/posts';
    if (is_dir($postsDir)) {
        foreach (glob($postsDir . '/*.md') as $file) {
            $raw = file_get_contents($file);
            // Check for draft: true in front matter — skip drafts
            if (preg_match('/^---\s*\n(.*?)\n---/s', $raw, $m)) {
                if (preg_match('/^draft\s*:\s*true/mi', $m[1])) {
                    continue;
                }
            }
            $slug = basename($file, '.md');
            $urls['/blog/' . $slug] = true;
        }
    }

    // Sort URLs for clean output
    ksort($urls);

    // Generate XML
    $now = date('Y-m-d');

    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

    foreach (array_keys($urls) as $path) {
        $fullUrl = $baseUrl . $path;
        $priority = $priorityMap[$path] ?? $defaultPriority;
        $changefreq = $changefreqMap[$path] ?? $defaultChangefreq;

        $xml .= "  <url>\n";
        $xml .= "    <loc>" . htmlspecialchars($fullUrl) . "</loc>\n";
        $xml .= "    <lastmod>{$now}</lastmod>\n";
        $xml .= "    <changefreq>{$changefreq}</changefreq>\n";
        $xml .= "    <priority>{$priority}</priority>\n";
        $xml .= "  </url>\n";
    }

    $xml .= "</urlset>\n";

    // Write sitemap to the public web root
    $sitemapPath = __DIR__ . '/../sitemap.xml';
    file_put_contents($sitemapPath, $xml);

    echo GREEN . "\nSitemap generated: {$sitemapPath}" . NC . "\n";
    echo "Total URLs: " . count($urls) . "\n";

    echo "\nURLs included:\n";
    foreach (array_keys($urls) as $path) {
        $priority = $priorityMap[$path] ?? $defaultPriority;
        echo "  {$baseUrl}{$path}  (priority: {$priority})\n";
    }

    echo "\n" . BLUE . "=== Sitemap Generation Complete ===" . NC . "\n\n";

} catch (\Exception $e) {
    echo "\033[0;31m" . "Error: " . $e->getMessage() . NC . "\n\n";
    exit(1);
}

/**
 * Build URL path from controller/method, preferring URL aliases
 */
function buildUrl(string $control, string $method, array $aliasMap): ?string {
    $key = strtolower("{$control}::{$method}");

    // Check if there's a URL alias
    if (isset($aliasMap[$key])) {
        return '/' . $aliasMap[$key];
    }

    // index::index maps to /
    if ($control === 'index' && $method === 'index') {
        return '/';
    }

    // For index controller methods without aliases, they're still reachable
    // but we prefer only aliased URLs in the sitemap for clean SEO
    // Return null to skip un-aliased index methods
    if ($control === 'index') {
        return null;
    }

    return '/' . $control . '/' . $method;
}
