<?php
/**
 * capture-showcase.php — refresh the screenshot for each enabled showcase entry.
 *
 * For every enabled `showcase` bean, headless-Chrome the live URL and write a
 * viewport PNG to public/uploads/showcase/<slug>.png (served statically). The "/"
 * landing page renders only these cached images — it never hits the instances.
 *
 * Uses the system google-chrome (no node/playwright dependency). Run from cron,
 * e.g. hourly:
 *   0 * * * *   php /var/www/html/default/tiknix/scripts/capture-showcase.php >> /var/log/tiknix-showcase.log 2>&1
 */

require_once __DIR__ . '/../bootstrap.php';
new app\Bootstrap('conf/config.ini');

use app\Bean;

$ROOT     = dirname(__DIR__);
$OUT_DIR  = $ROOT . '/public/uploads/showcase';
$CHROME   = trim((string)@shell_exec('command -v google-chrome google-chrome-stable chromium chromium-browser 2>/dev/null | head -n1'));
$CONVERT  = trim((string)@shell_exec('command -v convert 2>/dev/null'));   // ImageMagick, optional
$WIDTH    = 1280;
$HEIGHT   = 800;
$TIMEOUT  = 60;   // hard seconds per capture

if ($CHROME === '') {
    fwrite(STDERR, "[showcase] no chrome/chromium binary found — cannot capture\n");
    exit(1);
}
if (!is_dir($OUT_DIR)) @mkdir($OUT_DIR, 0755, true);

$rows = Bean::find('showcase', 'enabled = 1 ORDER BY sort_order ASC, id ASC');
echo '[' . date('c') . '] capturing ' . count($rows) . " showcase page(s) with $CHROME\n";

foreach ($rows as $s) {
    $slug = preg_replace('/[^a-z0-9_-]/i', '', (string)$s->slug);
    $url  = (string)$s->url;
    if ($slug === '' || !preg_match('#^https?://#', $url)) {
        echo "  [skip] invalid slug/url for showcase #{$s->id}\n";
        continue;
    }
    $final = $OUT_DIR . '/' . $slug . '.jpg';   // served as .jpg (see view)
    $tmp   = $OUT_DIR . '/.' . $slug . '.tmp.png';

    // A fresh, isolated profile each run avoids stale singletons in cron.
    $profile = sys_get_temp_dir() . '/tiknix-showcase-' . $slug;
    $cmd = 'timeout ' . (int)$TIMEOUT . ' ' . escapeshellarg($CHROME)
         . ' --headless --disable-gpu --no-sandbox --hide-scrollbars'
         . ' --user-data-dir=' . escapeshellarg($profile)
         . ' --window-size=' . $WIDTH . ',' . $HEIGHT
         . ' --virtual-time-budget=8000'   // let JS/render settle before the shot
         . ' --screenshot=' . escapeshellarg($tmp)
         . ' ' . escapeshellarg($url) . ' 2>/dev/null';

    @exec($cmd, $_o, $code);
    $ok = false;
    if (is_file($tmp) && filesize($tmp) > 0) {
        // Compress the PNG shot to a light JPEG (≈1/10th the bytes) when ImageMagick is
        // present; otherwise serve the PNG bytes under the .jpg name (browsers sniff it).
        // Both write $final atomically, so on failure the previous image is left intact.
        if ($CONVERT !== '') {
            @exec(escapeshellarg($CONVERT) . ' ' . escapeshellarg($tmp)
                . ' -resize 1024x -quality 82 -strip ' . escapeshellarg($final) . ' 2>/dev/null');
            @unlink($tmp);
        } else {
            @rename($tmp, $final);
        }
        $ok = is_file($final) && filesize($final) > 0;
    }
    @unlink($tmp);
    if ($ok) {
        $s->capturedAt = date('Y-m-d H:i:s');
        $s->lastError  = '';
        Bean::store($s);
        echo "  [$slug] ok (" . filesize($final) . " bytes)\n";
    } else {
        $s->lastError = 'capture failed (exit ' . (int)$code . ')';
        Bean::store($s);
        echo "  [$slug] FAILED (exit " . (int)$code . ") — keeping any prior image\n";
    }
    @exec('rm -rf ' . escapeshellarg($profile));
}
echo '[' . date('c') . "] done\n";
