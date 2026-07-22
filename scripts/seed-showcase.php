<?php
/**
 * seed-showcase.php — idempotent seed of the public landing-page showcase.
 *
 * The showcase is a CURATED, site-owned gallery of live tiknix instances shown on
 * the "/" landing page (a rotating screenshot rail). Entries are matched by `slug`
 * so re-running only fills gaps / updates copy — it never duplicates. Screenshots
 * are captured separately by scripts/capture-showcase.php into
 * public/uploads/showcase/<slug>.png.
 *
 *   php scripts/seed-showcase.php
 */

require_once __DIR__ . '/../bootstrap.php';
new app\Bootstrap('conf/config.ini');

use app\Bean;

$entries = [
    [
        'slug'  => 'bookingscheduler',
        'url'   => 'https://bookingscheduler.tiknix.com/',
        'title' => 'El Salón',
        'blurb' => 'Hair-salon booking — services, pricing & appointment scheduling.',
        'sortOrder' => 10,
    ],
    [
        'slug'  => 'mileage',
        'url'   => 'https://mileage.tiknix.com/',
        'title' => 'Travel Trailer Trip Planner',
        'blurb' => 'Route mapping with a day-by-day driving & rest-day plan.',
        'sortOrder' => 20,
    ],
    [
        'slug'  => 'pd',
        'url'   => 'https://pd.tiknix.com/',
        'title' => 'Headwaters Union',
        'blurb' => 'A fly-fishing brand site with lead capture.',
        'sortOrder' => 30,
    ],
];

$now = date('Y-m-d H:i:s');
foreach ($entries as $e) {
    $bean = Bean::findOne('showcase', 'slug = ?', [$e['slug']]) ?: Bean::dispense('showcase');
    $isNew = !$bean->id;
    $bean->slug           = $e['slug'];
    $bean->url            = $e['url'];
    $bean->title          = $e['title'];
    $bean->blurb          = $e['blurb'];
    $bean->sortOrder      = (int)$e['sortOrder'];
    $bean->screenshotPath = '/uploads/showcase/' . $e['slug'] . '.jpg';
    if ($isNew) {
        $bean->enabled   = 1;      // only set on create — don't re-enable a manually disabled entry
        $bean->createdAt = $now;
    }
    $bean->updatedAt = $now;
    Bean::store($bean);
    echo ($isNew ? '  + created ' : '  · updated ') . $e['slug'] . " ({$e['title']})\n";
}
echo "done — " . count($entries) . " showcase entr(y|ies) seeded\n";
