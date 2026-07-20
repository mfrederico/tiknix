<?php
/**
 * sync-social-feeds.php — refresh each PUBLISHED social showcase page.
 *
 * For every published `socialpage`: refresh an expiring token, pull the feed via the
 * connector, MIRROR each image locally under public/socialmedia/<slug>/ (Meta's CDN
 * URLs are signed and expire), and cache the normalized feed on the page. The public
 * /social/<slug> controller then serves only this cached copy — never hitting Meta.
 *
 * Run from cron, e.g. every 30 minutes:
 *   (crontab)  0,30 * * * *   php /var/www/html/default/tiknix/scripts/sync-social-feeds.php >> /var/log/tiknix-social.log 2>&1
 */

require_once __DIR__ . '/../bootstrap.php';
new app\Bootstrap('conf/config.ini');

use app\Bean;
use app\EncryptionService;
use app\services\connectors\ConnectorRegistry;
use RedBeanPHP\R;

$ROOT       = dirname(__DIR__);
$MEDIA_ROOT = $ROOT . '/public/socialmedia';
$REFRESH_WINDOW = 10 * 86400;   // refresh a token when within 10 days of expiry

$pages = Bean::find('socialpage', 'published = 1');
echo '[' . date('c') . "] syncing " . count($pages) . " published social page(s)\n";

foreach ($pages as $page) {
    $slug = (string)$page->slug;
    try {
        $conn = Bean::load('connections', (int)$page->connectionId);
        if (!$conn->id || (int)$conn->enabled !== 1 || !empty($conn->revokedAt)) {
            throw new \Exception('connection missing/disabled');
        }
        $connector = (new ConnectorRegistry())->get((string)$conn->connectorType);
        if (!$connector) throw new \Exception('no connector for ' . $conn->connectorType);

        $token = EncryptionService::decrypt((string)$conn->accessToken);
        $meta  = json_decode((string)($conn->metadataJson ?: '{}'), true) ?: [];

        // --- refresh an expiring token -------------------------------------
        $expiresAt = (int)($meta['expires_at'] ?? 0);
        if ($expiresAt > 0 && $expiresAt - time() < $REFRESH_WINDOW) {
            $r = $connector->refreshToken($conn, $token);
            if ($r && !empty($r['access_token'])) {
                $token = (string)$r['access_token'];
                $meta['expires_at'] = (int)($r['expires_at'] ?? (time() + 5184000));
                $conn->accessToken  = EncryptionService::encrypt($token);
                $conn->metadataJson = json_encode($meta);
                $conn->updatedAt    = date('Y-m-d H:i:s');
                Bean::store($conn);
                echo "  [$slug] token refreshed (expires " . date('Y-m-d', $meta['expires_at']) . ")\n";
            }
        }

        // --- pull the feed --------------------------------------------------
        $feed  = $connector->fetchFeed($conn, $token, ['limit' => (int)($page->maxItems ?: 30)]);
        $items = $feed['items'] ?? [];

        // --- mirror media locally (CDN links expire) ------------------------
        $dir = $MEDIA_ROOT . '/' . $slug;
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        foreach ($items as &$it) {
            $id  = preg_replace('/[^a-zA-Z0-9_]/', '', (string)($it['id'] ?? ''));
            if ($id === '') continue;
            // photos → media_url; video/reel → thumbnail_url (link out to the reel for playback)
            $remote = ((string)$it['kind'] === 'photo' || (string)$it['kind'] === 'carousel')
                ? (string)($it['media_url'] ?: $it['thumbnail_url'])
                : (string)($it['thumbnail_url'] ?: $it['media_url']);
            if ($remote === '') continue;
            $local = $dir . '/' . $id . '.jpg';
            if (!is_file($local)) {
                $bytes = @file_get_contents($remote);
                if ($bytes !== false && strlen($bytes) > 0) @file_put_contents($local, $bytes);
            }
            if (is_file($local)) {
                $rel = '/socialmedia/' . $slug . '/' . $id . '.jpg';
                $it['thumbnail_url'] = $rel;
                if ((string)$it['kind'] === 'photo' || (string)$it['kind'] === 'carousel') $it['media_url'] = $rel;
            }
        }
        unset($it);

        // --- keep the page's display fields fresh from the connection -------
        if ($page->handle === '' || $page->handle === null)   $page->handle = (string)($meta['username'] ?? ltrim((string)$conn->externalName, '@'));
        if ($page->externalUrl === '' || $page->externalUrl === null) $page->externalUrl = (string)$conn->externalUrl;

        $page->feedJson = json_encode(array_values($items), JSON_UNESCAPED_SLASHES);
        $page->syncedAt = date('Y-m-d H:i:s');
        $page->updatedAt = date('Y-m-d H:i:s');
        Bean::store($page);
        echo "  [$slug] " . count($items) . " item(s) cached\n";
    } catch (\Throwable $e) {
        echo "  [$slug] FAILED: " . $e->getMessage() . "\n";
        try { $page->lastError = $e->getMessage(); $page->updatedAt = date('Y-m-d H:i:s'); Bean::store($page); } catch (\Throwable $e2) {}
    }
}
echo '[' . date('c') . "] done\n";
