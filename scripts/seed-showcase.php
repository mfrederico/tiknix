<?php
/**
 * seed-showcase.php — idempotent seed of the public landing-page showcase.
 *
 * The showcase is a CURATED, site-owned gallery of live tiknix instances shown on
 * the "/" landing page (a rotating screenshot rail). Entries are matched by `slug`
 * so re-running only fills gaps / updates copy — it never duplicates. Screenshots
 * are captured separately by scripts/capture-showcase.php into
 * public/uploads/showcase/<slug>.png. An entry carrying a `story` is also a founder
 * story (landing section + /stories), stored as JSON in showcase.story_json.
 *
 *   php scripts/seed-showcase.php
 */

require_once __DIR__ . '/../bootstrap.php';
new app\Bootstrap('conf/config.ini');

use app\Bean;

$entries = [
    // ── Founder stories ──────────────────────────────────────────────────────────
    // An entry with a `story` is also a founder story: a card on the landing page and
    // a chapter on /stories (ordered by story.order). Facts only — what the founder
    // started with and what their project actually does. Never write a quote the
    // founder did not say; add one as story.quote only once they have given it.
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
    [
        'slug'  => 'partsdna-74a225',
        'url'   => 'https://partsdna-74a225.tiknix.com/parts/find?q=MYT4213500',
        'title' => 'PartsDNA',
        'blurb' => 'A Shopify parts app — find the right part by vehicle fitment.',
        'sortOrder' => 50,
        'story' => [
            'order'       => 30,
            'founder'     => 'Matthew Frederico',
            // He is tiknix's own founder: said plainly, so this never reads as an
            // independent customer's endorsement.
            'role'        => 'Founder of tiknix — built for a client in the MSP space',
            'image'       => '/uploads/showcase/partsdna-74a225.jpg',
            'headline'    => 'A fully fleshed-out parts and inventory platform',
            'startedWith' => 'A client\'s parts catalogue',
            'summary'     => 'Matthew built PartsDNA for a company in the MSP space: a parts and inventory platform where customers find the exact part for their model, and the catalogue, stock and orders stay in sync with the store.',
            'built'       => [
                'Parts finder by model and fitment',
                'A curated fitment graph with an admin builder',
                'Public catalogue, search suggestions and a parts API',
                'Shopify product, inventory and order sync',
                'Back-in-stock alerts',
            ],
            'body' => [
                'Selling parts comes down to one question: will this fit my machine? Matthew Frederico\'s client, a company in the MSP space, had the catalogue and a Shopify store, but no dependable way to answer that question — or to keep parts, stock and orders straight between systems.',
                'On tiknix, Matthew built PartsDNA: a fitment finder that resolves the right part for a model, backed by a fitment graph his client curates in an admin builder. Around it sits a public catalogue with search suggestions, a parts API the app itself runs on, pipelines that keep Shopify products, inventory and orders in sync, and back-in-stock alerts for customers.',
                'Matthew founded tiknix, and PartsDNA is the platform doing the work it was built for: a real client, a real catalogue, and a fully fleshed-out tool built task by task on tiknix\'s AI dev team.',
            ],
            'stats' => [
                ['v' => '133',  'k' => 'Build tasks shipped'],
                ['v' => '1',    'k' => 'Shopify store kept in sync'],
                ['v' => '6',    'k' => 'Sync pipelines running'],
            ],
        ],
    ],

    [
        'slug'  => 'serenity-bbdc01',
        'url'   => 'https://serenity-bbdc01.tiknix.com/',
        'title' => 'Serenity Gemstones',
        'blurb' => 'A gemstone business run on its own SaaS — catalog, classes, events and QR tickets.',
        'sortOrder' => 5,
        'story' => [
            'order'       => 10,
            'founder'     => 'Kent Fullmer',
            'role'        => 'Founder, Serenity Gemstones',
            'image'       => '/uploads/showcase/serenity-case.jpg',
            'headline'    => 'From a business plan to a fully bespoke SaaS',
            'startedWith' => 'A business plan and an idea',
            'summary'     => 'Kent didn\'t start with wireframes or a spec sheet. He started with his business plan — how Serenity Gemstones should sell, teach and host — and turned it into software shaped exactly like the business.',
            'built'       => [
                'Gemstone catalog and storefront',
                'Online orders',
                'Class and event calendar',
                'Tickets with QR check-in at the door',
                'Business reports',
            ],
            'body' => [
                'Kent Fullmer had what most founders start with: a business plan and a clear picture of how Serenity Gemstones should run — the stones it sells, the classes it teaches, the events it hosts. What he didn\'t have was software that fit. Off-the-shelf meant a storefront from one vendor, booking from another and ticketing from a third, none of them shaped like his business.',
                'So he started a tiknix project with the plan itself. tiknix read it and turned it into build tasks — the catalog and storefront, the class and event calendar, tickets with QR check-in at the door, orders and reports. Kent steered: reviewing each task, asking for changes in plain English, approving what shipped.',
                'The result is Serenity: a fully bespoke SaaS the business runs on, with its own database, its own logins and every line of source code in Kent\'s hands. No per-seat fees, and nobody else setting the roadmap.',
            ],
            'stats' => [
                ['v' => '111',  'k' => 'Build tasks shipped'],
                ['v' => '1',    'k' => 'Business plan to start from'],
                ['v' => '100%', 'k' => 'Of the code, his'],
            ],
        ],
    ],
    [
        'slug'  => 'collectiq-302eb3',
        'url'   => 'https://collectiq-302eb3.tiknix.com/',
        'title' => 'CollectIQ',
        'blurb' => 'Collection cataloguing with AI image recognition, listed straight to eBay and MercadoLibre.',
        'sortOrder' => 40,
        'story' => [
            'order'       => 20,
            'founder'     => 'Fabian Duarte',
            'role'        => 'Founder, CollectIQ',
            'image'       => '/uploads/showcase/collectiq-302eb3.jpg',
            'headline'    => 'AI that knows what\'s in your collection',
            'startedWith' => 'An idea for smarter collecting',
            'summary'     => 'Fabian built CollectIQ: photograph a collectible and AI image recognition identifies it, categorises it and fills in the details — ready to catalogue, grade and list on eBay or MercadoLibre.',
            'built'       => [
                'Identification from a photo with AI vision',
                'Automatic categories and item details',
                'Grading fields for each kind of collectible',
                'eBay listings with item specifics filled in',
                'MercadoLibre listings',
            ],
            'body' => [
                'Collectors know the problem: a shelf of trading cards, coins, diecast cars or comics, and no quick way to record exactly what each piece is, keep it organised, or get it in front of buyers. Fabian Duarte set out to fix that with CollectIQ.',
                'In CollectIQ you take a photo and AI vision does the identifying — it recognises the collectible, files it under the right category and fills in the details a collector would otherwise type by hand, including grading fields specific to that kind of item. From there it can go straight to eBay, with the marketplace\'s item specifics already filled in, or to MercadoLibre.',
                'Fabian built it with tiknix\'s AI dev team, working hands-on in the code alongside it. The whole app — the image recognition, the catalogue, both marketplace integrations — is his.',
            ],
            'stats' => [
                ['v' => '119',  'k' => 'Build tasks shipped'],
                ['v' => '2',    'k' => 'Marketplaces connected'],
                ['v' => '100%', 'k' => 'Of the code, his'],
            ],
        ],
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
    // JSON text, so the column is TEXT from its first store and never widens (a widen
    // rebuilds the SQLite table). An entry without a story clears a stale one.
    $bean->storyJson      = isset($e['story']) ? json_encode($e['story'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) : '';
    if ($isNew) {
        $bean->enabled   = 1;      // only set on create — don't re-enable a manually disabled entry
        $bean->createdAt = $now;
    }
    $bean->updatedAt = $now;
    Bean::store($bean);
    echo ($isNew ? '  + created ' : '  · updated ') . $e['slug'] . " ({$e['title']})\n";
}
echo "done — " . count($entries) . " showcase entr(y|ies) seeded\n";
