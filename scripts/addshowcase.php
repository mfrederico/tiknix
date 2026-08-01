#!/usr/bin/env php
<?php
/**
 * addshowcase.php — put a project on the "Built with Tiknix" rail.
 *
 *   php scripts/addshowcase.php collectiq-302eb3
 *   php scripts/addshowcase.php collectiq-302eb3 --title="CollectIQ" --blurb="Collection cataloguing with AI appraisal."
 *   php scripts/addshowcase.php --list
 *   php scripts/addshowcase.php collectiq-302eb3 --disable
 *
 * Everything is derived from the instance registry, so the usual case is just the slug:
 * the title comes from the project's display name, and the URL from its published domain
 * when it has one (a project on a custom domain should be shown at that domain, not at
 * the tiknix subdomain it happens to also answer on).
 *
 * IT CAPTURES THE SCREENSHOT IMMEDIATELY, and that is not an optimisation. The landing
 * rail renders an <img> for every enabled row, so an entry added without one puts a
 * BROKEN IMAGE on tiknix.com's front page until the hourly cron catches up. Pass
 * --no-capture only if you are deliberately staging an entry and will run
 * capture-showcase.php yourself.
 *
 * The live site is checked before anything is written. Showcasing a project that 404s or
 * is mid-deploy advertises a broken thing to every visitor, which is worse than an empty
 * slot — so a bad response refuses by default (--force to override, e.g. for a site that
 * is briefly down but genuinely worth listing).
 */

if (php_sapi_name() !== 'cli') { die("cli only\n"); }
require_once __DIR__ . '/../bootstrap.php';
new app\Bootstrap('conf/config.ini');

use app\Bean;

/**
 * Parsed by hand, NOT by getopt(), because getopt() stops at the first non-option
 * argument — and the slug is positional and comes first. Every flag after it was
 * silently discarded, so `addshowcase.php <slug> --title="X"` quietly ignored the title
 * and `--no-capture` captured anyway. Silently is the problem: nothing complained.
 */
$slug = '';
$opt  = [];
foreach (array_slice($argv, 1) as $a) {
    if (strncmp($a, '--', 2) === 0) {
        $kv = explode('=', substr($a, 2), 2);
        $opt[$kv[0]] = $kv[1] ?? true;
    } elseif ($slug === '') {
        $slug = trim($a);
    }
}

function out(string $s): void { echo $s . "\n"; }

// ---- --list ---------------------------------------------------------------
if (isset($opt['list'])) {
    $rows = Bean::find('showcase', 'ORDER BY sort_order ASC, id ASC');
    if (!$rows) { out('showcase is empty'); exit(0); }
    foreach ($rows as $r) {
        $img = dirname(__DIR__) . '/public' . (string) $r->screenshotPath;
        printf("  %-3s %-22s sort=%-4s %-9s %s%s\n",
            ($r->enabled ? 'on' : 'OFF'), $r->slug, $r->sortOrder,
            is_file($img) ? 'shot:ok' : 'shot:MISSING', $r->url,
            $r->lastError ? '  [' . mb_substr((string) $r->lastError, 0, 48) . ']' : '');
    }
    exit(0);
}

if ($slug === '') {
    fwrite(STDERR, "usage: addshowcase.php <instance-slug> [--title=..] [--blurb=..] [--url=..] [--sort=N]\n"
                 . "       addshowcase.php --list\n"
                 . "       addshowcase.php <instance-slug> --disable\n");
    exit(2);
}
$slug = preg_replace('/[^a-z0-9_-]/i', '', $slug);

// ---- --disable ------------------------------------------------------------
if (isset($opt['disable'])) {
    $bean = Bean::findOne('showcase', 'slug = ?', [$slug]);
    if (!$bean || !$bean->id) { fwrite(STDERR, "'$slug' is not on the showcase\n"); exit(1); }
    $bean->enabled   = 0;
    $bean->updatedAt = date('Y-m-d H:i:s');
    Bean::store($bean);
    out("· $slug disabled — it will drop off the landing page immediately");
    exit(0);
}

// ---- resolve the project --------------------------------------------------
// From the registry, so a typo cannot silently create a showcase entry pointing at
// nothing. This is also where the title and URL come from.
$inst = Bean::findOne('instance', 'slug = ?', [$slug]);
if (!$inst || !$inst->id) {
    fwrite(STDERR, "no instance '$slug' in the registry — check the slug (addshowcase.php --list shows current entries)\n");
    exit(1);
}
if ((string) $inst->status !== 'active') {
    fwrite(STDERR, "instance '$slug' is '{$inst->status}', not active — refusing to showcase it\n");
    exit(1);
}

$app    = (string) ($inst->app ?: 'tiknix');
// A published project belongs on its own domain; the tiknix subdomain is where it is
// BUILT, which is not what a visitor should be sent to.
$domain = trim((string) ($inst->ctDomain ?? ''));
$url    = trim((string) ($opt['url'] ?? ''))
        ?: ($domain !== '' ? 'https://' . $domain . '/' : 'https://' . $slug . '.' . $app . '.com/');
$title  = trim((string) ($opt['title'] ?? '')) ?: (string) ($inst->displayName ?: $slug);
$blurb  = trim((string) ($opt['blurb'] ?? ''));

// ---- is it actually up? ---------------------------------------------------
$code = 0;
$ch = curl_init($url);
curl_setopt_array($ch, [CURLOPT_NOBODY => true, CURLOPT_FOLLOWLOCATION => true,
                        CURLOPT_TIMEOUT => 20, CURLOPT_RETURNTRANSFER => true]);
curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($code < 200 || $code >= 400) {
    $msg = "$url answered HTTP $code";
    if (!isset($opt['force'])) {
        fwrite(STDERR, "refusing: $msg. A broken site on the landing page is worse than an empty slot.\n"
                     . "Fix it, or pass --force if you know it is only briefly down.\n");
        exit(1);
    }
    out("! $msg — continuing because --force was given");
}

// ---- upsert ---------------------------------------------------------------
$bean  = Bean::findOne('showcase', 'slug = ?', [$slug]) ?: Bean::dispense('showcase');
$isNew = !$bean->id;
$now   = date('Y-m-d H:i:s');

if (isset($opt['sort'])) {
    $sort = (int) $opt['sort'];
} elseif ($isNew) {
    // After everything currently listed, in steps of 10 so entries can be slotted between.
    $sort = ((int) Bean::getCell('SELECT COALESCE(MAX(sort_order), 0) FROM showcase')) + 10;
} else {
    $sort = (int) $bean->sortOrder;
}

$bean->slug           = $slug;
$bean->url            = $url;
$bean->title          = $title;
if ($blurb !== '' || $isNew) $bean->blurb = $blurb;   // never blank an existing blurb by omission
$bean->sortOrder      = $sort;
$bean->screenshotPath = '/uploads/showcase/' . $slug . '.jpg';
if ($isNew) {
    $bean->enabled   = 1;    // only on create — re-running must not undo a manual disable
    $bean->createdAt = $now;
}
$bean->updatedAt = $now;
Bean::store($bean);

out(($isNew ? '+ added ' : '· updated ') . "$slug — \"$title\" -> $url (sort $sort)");
if (!$isNew && !$bean->enabled) {
    out('  NOTE: this entry is disabled, so it will not appear. Re-enable it with --sort or by hand.');
}

// ---- screenshot now, not in an hour ---------------------------------------
if (isset($opt['no-capture'])) {
    out('  --no-capture: the rail will show a broken image until capture-showcase.php runs.');
    exit(0);
}
out('  capturing the screenshot now (the rail renders an <img> for every enabled row)…');
$cmd = 'php ' . escapeshellarg(__DIR__ . '/capture-showcase.php') . ' 2>&1';
exec($cmd, $lines, $rc);
foreach ($lines as $l) out('    ' . $l);

$img = dirname(__DIR__) . '/public/uploads/showcase/' . $slug . '.jpg';
out(is_file($img)
    ? '  screenshot ready: ' . $bean->screenshotPath
    : '  ! no screenshot yet — the rail will show a gap for this entry until capture succeeds');
