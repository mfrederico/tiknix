<?php
/**
 * Guard — what the plugin does on every request (concept.json "boot"): adds its menu and footer links, sends the
 * policy header, and when a page needs it, puts the question and the small script into the HTML.
 * When the list cannot be read: logged as an ERROR; while enforcing, the strictest policy; while watching, nothing is sent.
 */

namespace app\concepts\consent;

class Guard {

    public static function boot(): void {
        if (PHP_SAPI === 'cli') return;
        \Flight::before('start', function () {
            // A sidecar (workbench, messaging, QA) runs on core's code and so boots core's plugins, but it is not this
            // site: it has none of the plugin's tables or permissions.
            if (\Flight::has('sidecar.core_root')) return;
            $views = dirname(__DIR__) . '/views/consent';
            \app\Chrome::add('prepare', "{$views}/_nav.php");
            \app\Chrome::add('footer', "{$views}/_footer.php");
            self::start();
        });
    }

    public static function start(): void {
        $host = strtolower((string) \app\Host::visitorHost());
        $mode = 'enforce';
        $services = null;
        try {
            $mode = Settings::mode();
            $services = Inventory::cached();
            [$name, $value] = Policy::header($mode, Choice::current()['groups'] ?? [], $services, $host);
        } catch (\Throwable $e) {
            // watching promises to block nothing, so a fault there costs only the report; otherwise nothing unasked slips through
            \Flight::get('log')->error('consent: the policy could not be built' . ($mode === 'watch' ? ', so this page is not watched: ' : ', so this page gets the strictest one: ') . $e->getMessage());
            if ($mode === 'watch') return;
            [$name, $value] = Policy::strict($host);
            $services = null;
        }
        header("{$name}: {$value}");
        if ($mode !== 'enforce') return;
        header('Vary: Cookie', false);   // the page differs by the visitor's answer: the front cache keeps a copy per answer
        if ($services === null) return;
        $snippet = '<script src="/consent/js?v=' . (int) @filemtime(dirname(__DIR__) . '/assets/tnx-consent.js') . '" defer></script>';
        if (Choice::current() === null && Inventory::needsAsking($services)) $snippet = Banner::html($services, (string) \Flight::siteName(), Choice::last()) . $snippet;
        \Flight::response()->addResponseBodyCallback(fn($body) => self::inject((string) $body, $snippet));
    }

    /** The snippet before the last </body> of an HTML page; anything else untouched. */
    public static function inject(string $body, string $snippet, ?array $headers = null): string {
        foreach ($headers ?? array_merge(headers_list(), array_map(fn($k, $v) => "{$k}: " . (is_array($v) ? implode(', ', $v) : $v), array_keys(\Flight::response()->headers()), \Flight::response()->headers())) as $h) {
            if (stripos($h, 'content-type:') === 0 && stripos($h, 'text/html') === false) return $body;
        }
        $at = strripos($body, '</body>');
        return $at === false ? $body : substr_replace($body, $snippet, $at, 0);
    }
}
