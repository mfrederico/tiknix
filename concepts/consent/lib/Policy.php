<?php
/**
 * Policy — the header that makes a browser keep to the visitor's answer. Whatever a page's code writes, the browser
 * loads from this site and the sources named here and refuses (and reports) the rest. It is not an XSS defence, so
 * inline code stays allowed: the pages use it. The same header is the scanner: a load outside it is reported to
 * /consent/report, and that is how a service joins the list.
 *
 *   watch    Content-Security-Policy-Report-Only: nothing is blocked; everything already listed is allowed so only
 *            what is new reports (a page of forty photos must not send forty reports)
 *   enforce  Content-Security-Policy: what is needed + what this visitor said yes to.
 *            An app that sends its own policy later replaces this one on that response (partsdna's Shopify framing).
 */

namespace app\concepts\consent;

class Policy {

    /** The front server sends this only when the app sends no policy of its own, so an enforcing policy must carry it. */
    public const FRAME_ANCESTORS = "frame-ancestors 'self' https://tiknix.com https://*.tiknix.com";

    /** @param string[] $groups what the visitor said yes to @return array{0:string,1:string} header name, value */
    public static function header(string $mode, array $groups, array $services, string $host): array {
        if ($mode === 'watch') {
            $listed = array_values(array_filter(array_unique(array_column($services, 'group'))));
            return ['Content-Security-Policy-Report-Only', self::value(Inventory::sources(array_merge($listed, Groups::asked()), $services), $host)];
        }
        return ['Content-Security-Policy', self::value(Inventory::sources($groups, $services), $host) . '; ' . self::FRAME_ANCESTORS];
    }

    /** The strictest policy: only this site. For when the list cannot be read: nothing unasked may slip through. */
    public static function strict(string $host): array {
        return ['Content-Security-Policy', self::value([], $host) . '; ' . self::FRAME_ANCESTORS];
    }

    private static function value(array $sources, string $host): string {
        $parts = array_merge(["'self'", "'unsafe-inline'", "'unsafe-eval'", 'data:', 'blob:', 'wss://' . $host], $sources);
        return 'default-src ' . implode(' ', $parts) . '; report-uri /consent/report';
    }

    /**
     * What a report says: the host a page tried to load from, the page (no query: it can hold a token), and which
     * kind of load. Null for what is not an outside host (inline code, data:, this site itself).
     * @return array{host:string,page:string,directive:string}|null
     */
    public static function fromReport(array $body, string $ownHost): ?array {
        $r = is_array($body['csp-report'] ?? null) ? $body['csp-report'] : null;
        if ($r === null) return null;
        $blocked = (string) ($r['blocked-uri'] ?? '');
        $host = strtolower((string) parse_url($blocked, PHP_URL_HOST));
        if ($host === '' || $host === strtolower($ownHost) || !Known::isHost($host)) return null;
        return ['host' => $host, 'page' => (string) parse_url((string) ($r['document-uri'] ?? ''), PHP_URL_PATH),
                'directive' => (string) ($r['effective-directive'] ?? $r['violated-directive'] ?? '')];
    }
}
