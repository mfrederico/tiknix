<?php
/**
 * Social — the PUBLIC (authcontrol 101) showcase front controller for social feeds.
 *
 * A member publishes their connected Instagram feed at /social/<slug>; this serves a
 * cacheable, self-contained showcase page (server-rendered grid of reels + photos) and
 * a /social/<slug>.json data endpoint. It reads ONLY the cached copy of the feed
 * (socialpage.feed_json, refreshed by scripts/sync-social-feeds.php) — it never calls
 * Meta per request, so it is fast, rate-limit-safe, and works with expired CDN links
 * because images are mirrored locally under public/socialmedia/<slug>/.
 *
 * Mirrors controls/Shop.php: front controller, level-101 route, Cache-Control + ETag +
 * 304 revalidation via \Flight::halt (Flight otherwise emits its own 200 at shutdown).
 */

namespace app;

use \Flight as Flight;
use app\BaseControls\Control;
use app\Bean;

class Social extends Control {

    /** GET /social — no landing directory; a bare hit 404s. */
    public function index($params = []): void { $this->notFound(); }

    /**
     * GET /social/<slug> (showcase page) and /social/<slug>.json (feed data).
     * Caught by the FlightMap _fallback hook for any unknown method segment.
     */
    public function _fallback($seg, $params = []): void {
        $raw    = (string)$seg;
        $isJson = str_ends_with($raw, '.json');
        $slug   = self::normSlug($isJson ? substr($raw, 0, -5) : $raw);
        if ($slug === '') { $this->notFound(); return; }

        $page = Bean::findOne('socialpage', 'slug = ? AND published = 1', [$slug]);
        if (!$page || !$page->id) { $this->notFound(); return; }

        $items = json_decode((string)($page->feedJson ?: '[]'), true) ?: [];
        $tag   = (string)($page->syncedAt ?: $page->updatedAt);

        if ($isJson) {
            $this->emit(json_encode(['handle' => $page->handle, 'title' => $page->title,
                'synced_at' => $page->syncedAt, 'items' => $items], JSON_UNESCAPED_SLASHES),
                'application/json; charset=utf-8', $tag);
            return;
        }
        $this->emit($this->renderPage($page, $items), 'text/html; charset=utf-8', $tag);
    }

    /** Build the self-contained showcase HTML from the cached feed (view partial). */
    private function renderPage($page, array $items): string {
        ob_start();
        include dirname(__DIR__) . '/views/social/page.php';
        return (string)ob_get_clean();
    }

    /** Slug guard — lowercase handle-ish; defends the DB lookup + any path use. */
    private static function normSlug(string $s): string {
        $s = strtolower(trim($s));
        return preg_match('/^[a-z0-9][a-z0-9_.-]{0,49}$/', $s) ? $s : '';
    }

    /** Emit a body with a short public cache + ETag; honour If-None-Match (304). */
    private function emit(string $body, string $type, string $tagBasis): void {
        $etag = '"' . md5($tagBasis . '|' . strlen($body)) . '"';
        if (!headers_sent()) {
            header('Content-Type: ' . $type);
            header('Cache-Control: public, max-age=300');
            header('ETag: ' . $etag);
        }
        if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) { \Flight::halt(304); }
        echo $body;
    }

    private function notFound(): void {
        if (!headers_sent()) { http_response_code(404); header('Content-Type: text/html; charset=utf-8'); }
        echo '<!doctype html><meta charset="utf-8"><title>Not found</title>'
           . '<div style="font:16px system-ui;padding:3rem;text-align:center;color:#555">This showcase page was not found.</div>';
    }
}
