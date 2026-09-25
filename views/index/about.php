<?php
/**
 * Who we are — the company behind tiknix and the person who builds it. Standalone like
 * the landing (no app layout), rendered by About::index() on the flagship host only.
 * Carries Person + Organization JSON-LD so "who makes tiknix" resolves here.
 */
$logoV = @filemtime(dirname(__DIR__, 2) . '/public/img/tiknix.svg') ?: '1';
$desc  = 'tiknix is built by ClickSimple LLC, a software shop in Mooresville, North Carolina. Founder and CTO Matthew Frederico has spent fifteen-plus years building the machinery under the button: ShipCannon, DealerYes, and tiknix itself.';
$site  = 'https://tiknix.com';
$jsonld = [
    '@context' => 'https://schema.org',
    '@graph' => [
        ['@type' => 'Organization', '@id' => $site . '/#org', 'name' => 'tiknix', 'url' => $site . '/', 'logo' => $site . '/img/tiknix.svg',
         'parentOrganization' => ['@type' => 'Organization', 'name' => 'ClickSimple LLC', 'url' => 'https://clicksimple.com/'],
         'founder' => ['@id' => 'https://clicksimple.com/#matt'],
         'address' => ['@type' => 'PostalAddress', 'addressLocality' => 'Mooresville', 'addressRegion' => 'NC', 'addressCountry' => 'US'],
         'sameAs' => ['https://clicksimple.com/', 'https://github.com/mfrederico']],
        ['@type' => 'Person', '@id' => 'https://clicksimple.com/#matt', 'name' => 'Matthew Frederico', 'jobTitle' => 'Founder & CTO',
         'worksFor' => ['@id' => $site . '/#org'], 'url' => $site . '/about', 'image' => $site . '/img/matt.jpg',
         'sameAs' => ['https://linkedin.com/in/mattfred', 'https://github.com/mfrederico', 'https://clicksimple.com/about.php'],
         'knowsAbout' => ['AI agent orchestration', 'Model Context Protocol', 'Ecommerce systems', 'Warehouse management software', 'Laravel', 'PHP', 'Shopify', 'Stripe']],
        ['@type' => 'AboutPage', '@id' => $site . '/about#page', 'url' => $site . '/about', 'name' => 'Who we are', 'mainEntity' => ['@id' => $site . '/#org']],
        ['@type' => 'BreadcrumbList', 'itemListElement' => [['@type' => 'ListItem', 'position' => 1, 'name' => 'tiknix', 'item' => $site . '/'], ['@type' => 'ListItem', 'position' => 2, 'name' => 'Who we are', 'item' => $site . '/about']]],
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title) ?></title>
    <meta name="description" content="<?= htmlspecialchars($desc) ?>">
    <link rel="canonical" href="<?= $site ?>/about">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="tiknix">
    <meta property="og:title" content="Who we are — tiknix">
    <meta property="og:description" content="<?= htmlspecialchars($desc) ?>">
    <meta property="og:url" content="<?= $site ?>/about">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,500;0,600;0,700;1,500&display=swap" rel="stylesheet">
    <?php include __DIR__ . '/_marketing-style.php'; ?>
    <style>
        .who-hero{ text-align:center; padding:48px 0 40px; }
        .who-hero h1{ font-size:clamp(36px,5vw,60px); margin:16px auto 0; max-width:820px; text-wrap:balance; }
        .who-hero h1 em{ font-style:italic; color:var(--accent2); }
        .who-hero .sub{ font-size:clamp(16px,1.6vw,19px); color:var(--soft); line-height:1.6; max-width:640px; margin:22px auto 0; }
        .who-hero .sub a{ color:var(--text); border-bottom:1px solid var(--line2); }
        .person{ display:grid; grid-template-columns:180px 1fr; gap:36px; align-items:start; max-width:920px; margin:0 auto; }
        .person .photo{ width:100%; aspect-ratio:1; height:auto; object-fit:cover; border-radius:16px; border:1px solid var(--line2); display:block; }
        .person h2{ font-size:clamp(26px,3vw,36px); margin:0; }
        .person .role{ font-size:13px; letter-spacing:.14em; text-transform:uppercase; color:var(--accent2); font-weight:600; margin:8px 0 18px; }
        .person p{ font-size:clamp(16px,1.5vw,18px); line-height:1.7; color:var(--soft); }
        .person p strong{ color:var(--text); }
        .person .links{ display:flex; flex-wrap:wrap; gap:18px; margin-top:22px; font-size:14px; font-weight:600; }
        .facts{ display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:16px; max-width:920px; margin:40px auto 0; }
        .facts .card{ padding:22px; }
        .facts .n{ font-family:var(--serif); font-size:30px; font-weight:600; color:var(--text); }
        .facts .k{ font-size:13px; color:var(--dim); margin-top:6px; line-height:1.4; }
        @media (max-width:640px){ .person{ grid-template-columns:1fr; } .person .photo{ max-width:140px; } }
    </style>
    <script type="application/ld+json"><?= json_encode($jsonld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
</head>
<body>

<div class="container">

  <?php include __DIR__ . '/_marketing-nav.php'; ?>

  <!-- HERO -->
  <section class="who-hero">
    <div class="eyebrow">Who we are</div>
    <h1>A small shop <em>that ships.</em></h1>
    <p class="sub">
      tiknix is built by <a href="https://clicksimple.com/" rel="noopener">ClickSimple LLC</a>, a software shop in
      Mooresville, North Carolina, building for clients since 2019. We build the thing, then we use it
      every day on client work. If it annoys us, it gets fixed.
    </p>
  </section>

  <!-- THE PERSON -->
  <section class="band">
    <div class="person">
      <img class="photo" src="/img/matt.jpg" width="800" height="800" alt="Matthew Frederico, founder and CTO" loading="lazy">
      <div>
        <h2>Matthew Frederico</h2>
        <div class="role">Founder &amp; CTO · Mooresville, NC</div>
        <p>
          Fifteen-plus years of building the machinery under the button. Matt was co-founder and CTO
          of a platform that grew from <strong>$100 million to $1.2 billion</strong> in revenue in under two
          years, while he built the engineering underneath it. He ran a team of nine engineers at a high-volume ecommerce and fulfillment business, and
          was the technical lead who unified Salesforce, Marketing Cloud and SAP data across four
          countries for a global tools maker. He built <a href="https://shipcannon.com" rel="noopener">ShipCannon</a>
          and CannonWMS, a warehouse system past 31 million packages, <a href="https://dealeryes.com" rel="noopener">DealerYes</a>
          for dealer networks, and tiknix, which he uses daily on the client projects that pay for it. He
          open-sourced FastMCPHP and a MariaDB-to-LLM bridge, writes Laravel, Node and Python, and runs AI
          agents in production.
        </p>
        <div class="links">
          <a href="https://linkedin.com/in/mattfred" rel="noopener">LinkedIn</a>
          <a href="https://github.com/mfrederico" rel="noopener">GitHub</a>
          <a href="/stories">His tiknix story</a>
          <a href="https://clicksimple.com/about.php" rel="noopener">ClickSimple</a>
        </div>
      </div>
    </div>

    <div class="facts">
      <div class="card"><div class="n">2019</div><div class="k">ClickSimple starts building for clients</div></div>
      <div class="card"><div class="n">31M+</div><div class="k">packages shipped through CannonWMS</div></div>
      <div class="card"><div class="n">~48k</div><div class="k">lines in the tiknix engine, and growing</div></div>
      <div class="card"><div class="n">1</div><div class="k">person to reach when something breaks</div></div>
    </div>
  </section>

  <!-- FINAL -->
  <section class="final">
    <div class="eyebrow">Say hello</div>
    <h2 style="margin-top:14px;">Want to see it before you sign up?</h2>
    <p>Matt will show you a real project being built. Thirty minutes, no slides.</p>
    <div style="display:flex; flex-wrap:wrap; justify-content:center; gap:14px; margin-top:30px;">
      <a class="btn btn-primary" href="/contact">Reach out</a>
      <a class="btn btn-ghost" href="/auth/register">Start your first project — free</a>
    </div>
  </section>

  <?php include __DIR__ . '/_marketing-foot.php'; ?>

</div>
</body>
</html>
