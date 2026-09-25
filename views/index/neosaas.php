<?php
/**
 * NeoSaaS manifesto — the canonical definition of the term. Standalone like the landing
 * (no app layout), rendered by Neosaas::index() on the flagship host only.
 *
 * Carries its own JSON-LD (Article + DefinedTerm + FAQPage) because search engines and AI
 * assistants are the second audience: the page exists so "what is NeoSaaS" resolves here.
 */
$logoV = @filemtime(dirname(__DIR__, 2) . '/public/img/tiknix.svg') ?: '1';
$desc  = 'NeoSaaS is first-party software with SaaS upkeep: built to fit one business exactly, owned by that business like first-party data is, hosted and maintained like SaaS, and never rented. Here is the argument, with a suit in it.';
$faqs  = [
    ['What is NeoSaaS?', 'Software built to fit one business exactly, owned by that business, and run wherever it chooses. It keeps what people like about SaaS, which is that someone hosts it, patches it and keeps it current, and drops what they hate: renting forever, paying per seat, and bending the company around a vendor\'s roadmap.'],
    ['What does "first-party software" have to do with it?', 'Everything. Marketers already split data into third-party, which you borrow, and first-party, which you own. Software splits the same way. Third-party SaaS is a product built for thousands of companies that you rent. First-party software is built for yours and belongs to you. NeoSaaS is first-party software with SaaS upkeep.'],
    ['How is it different from ordinary SaaS?', 'You rent SaaS and adjust your process to match it. Here the software is cut for your process, the code sits in your repository, and you can leave with it any time. Hosting and maintenance still happen, they just don\'t come bundled with a lease.'],
    ['How is it different from custom development?', 'Custom development usually means a long project, a big invoice, and then silence. This means the software is built fast, kept running by someone, priced per project rather than per seat, and changed the same week your business changes. tiknix is one way to get there; a small shop like ClickSimple is another.'],
    ['Isn\'t this just "build versus buy"?', 'Partly. Build-versus-buy assumes building is slow and expensive, which was true. AI planners and builders changed the cost, so the question now is whether you own what you use. The answer here is yes, always.'],
    ['Do I have to host it myself?', 'No. Someone hosts it, the way SaaS is hosted. The difference is that you can take it with you. On tiknix each project runs in its own isolated environment and can be published to your GitHub or your client\'s domain whenever you like.'],
    ['Who is it for?', 'Any business whose way of working is part of its advantage. Agencies building for clients, founders replacing a stack of subscriptions, operators with a process the big platforms never quite fit. If you have ever hired consultants to change your company so a tool would work, this is for you.'],
    ['What does it cost?', 'Whatever building costs, once, plus someone keeping it running. On tiknix that is a free first project, then $49 a month for your own projects and $99 for client projects, with no per-seat fees and no credits, because you bring your own model. Cancel a project and the app keeps running wherever you published it.'],
    ['Who coined NeoSaaS?', 'Matthew Frederico of ClickSimple, the company behind tiknix, ShipCannon and DealerYes. The phrase came out of watching companies spend years tailoring themselves to fit software they bought.'],
];
$site = 'https://tiknix.com';
$faqNodes = array_map(fn($f) => ['@type' => 'Question', 'name' => $f[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f[1]]], $faqs);
$jsonld = [
    '@context' => 'https://schema.org',
    '@graph' => [
        ['@type' => 'Organization', '@id' => $site . '/#org', 'name' => 'tiknix', 'url' => $site . '/', 'logo' => $site . '/img/tiknix.svg', 'parentOrganization' => ['@type' => 'Organization', 'name' => 'ClickSimple', 'url' => 'https://clicksimple.com/'], 'sameAs' => ['https://clicksimple.com/', 'https://github.com/mfrederico']],
        ['@type' => 'Person', '@id' => 'https://clicksimple.com/#matt', 'name' => 'Matthew Frederico', 'url' => 'https://clicksimple.com/about.php', 'sameAs' => ['https://github.com/mfrederico', 'https://linkedin.com/in/mattfred']],
        ['@type' => 'DefinedTerm', '@id' => $site . '/neosaas#term', 'name' => 'NeoSaaS', 'url' => $site . '/neosaas', 'description' => 'First-party software with SaaS upkeep: built to fit one business exactly, owned by that business, and run wherever it chooses, with the hosting and maintenance of SaaS and none of the renting.', 'inDefinedTermSet' => ['@type' => 'DefinedTermSet', 'name' => 'tiknix glossary', 'url' => $site . '/neosaas']],
        ['@type' => 'Article', '@id' => $site . '/neosaas#article', 'headline' => 'NeoSaaS: you can\'t grow into a suit that wasn\'t cut for you', 'description' => $desc, 'url' => $site . '/neosaas', 'mainEntityOfPage' => $site . '/neosaas', 'author' => ['@id' => 'https://clicksimple.com/#matt'], 'publisher' => ['@id' => $site . '/#org'], 'datePublished' => '2026-09-25', 'dateModified' => date('Y-m-d'), 'about' => ['@id' => $site . '/neosaas#term']],
        ['@type' => 'FAQPage', '@id' => $site . '/neosaas#faq', 'mainEntity' => $faqNodes],
        ['@type' => 'BreadcrumbList', 'itemListElement' => [['@type' => 'ListItem', 'position' => 1, 'name' => 'tiknix', 'item' => $site . '/'], ['@type' => 'ListItem', 'position' => 2, 'name' => 'NeoSaaS', 'item' => $site . '/neosaas']]],
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
    <link rel="canonical" href="<?= $site ?>/neosaas">
    <meta property="og:type" content="article">
    <meta property="og:site_name" content="tiknix">
    <meta property="og:title" content="NeoSaaS — software that fits you, that you own">
    <meta property="og:description" content="<?= htmlspecialchars($desc) ?>">
    <meta property="og:url" content="<?= $site ?>/neosaas">
    <meta name="twitter:card" content="summary">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,500;0,600;0,700;1,500&display=swap" rel="stylesheet">
    <?php include __DIR__ . '/_marketing-style.php'; ?>
    <style>
        .neo-hero{ text-align:center; padding:48px 0 56px; }
        .neo-hero h1{ font-size:clamp(36px,5vw,62px); margin:16px auto 0; max-width:900px; text-wrap:balance; }
        .neo-hero h1 em{ font-style:italic; color:var(--accent2); }
        .neo-hero .sub{ font-size:clamp(16px,1.6vw,19px); color:var(--soft); line-height:1.6; max-width:680px; margin:22px auto 0; }
        .neo-prose{ max-width:720px; margin:0 auto; }
        .neo-prose p{ font-size:clamp(16px,1.5vw,18px); line-height:1.7; color:var(--soft); margin-top:18px; }
        .neo-prose p strong{ color:var(--text); }
        .neo-prose h2{ font-size:clamp(26px,3vw,36px); margin-top:8px; }
        .neo-quote{ font-family:var(--serif); font-style:italic; font-size:clamp(22px,2.6vw,30px); line-height:1.3; color:var(--text);
                    text-align:center; max-width:760px; margin:0 auto; text-wrap:balance; }
        .neo-quote small{ display:block; margin-top:14px; font-family:var(--sans); font-style:normal; font-size:13px; letter-spacing:.14em; text-transform:uppercase; color:var(--accent2); font-weight:600; }
        .spiral{ display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:16px; margin-top:36px; }
        .spiral .card{ padding:22px; }
        .spiral .n{ font-family:var(--serif); font-size:26px; color:var(--accent2); }
        .spiral h3{ font-family:var(--sans); font-size:16px; font-weight:700; margin-top:10px; }
        .spiral p{ font-size:14px; line-height:1.55; color:var(--soft); margin-top:8px; }
        .defn{ border:1px solid var(--line2); border-radius:16px; padding:32px; background:rgba(59,118,240,0.06); max-width:820px; margin:36px auto 0; }
        .defn .eyebrow{ margin-bottom:12px; }
        .defn p{ font-size:clamp(17px,1.7vw,21px); line-height:1.55; color:var(--text); }
        .defn ul{ margin-top:18px; padding-left:0; list-style:none; display:grid; gap:10px; }
        .defn li{ font-size:15px; color:var(--soft); padding-left:26px; position:relative; line-height:1.5; }
        .defn li::before{ content:'✓'; color:var(--good); position:absolute; left:0; font-weight:700; }
        .two{ display:grid; grid-template-columns:repeat(auto-fit,minmax(320px,1fr)); gap:22px; margin-top:36px; }
        .two .card{ padding:28px; }
        .two h3{ font-family:var(--sans); font-size:17px; font-weight:700; }
        .two ul{ margin-top:14px; padding-left:0; list-style:none; display:grid; gap:9px; }
        .two li{ font-size:15px; color:var(--soft); padding-left:22px; position:relative; line-height:1.5; }
        .two .bad li::before{ content:'–'; color:#ef6f6f; position:absolute; left:0; }
        .two .good li::before{ content:'+'; color:var(--good); position:absolute; left:0; }
    </style>
    <script type="application/ld+json"><?= json_encode($jsonld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
</head>
<body>

<div class="container">

  <?php include __DIR__ . '/_marketing-nav.php'; ?>

  <!-- HERO -->
  <section class="neo-hero">
    <div class="eyebrow">NeoSaaS · a definition, with a suit in it</div>
    <h1>You can&rsquo;t grow into a suit <em>that wasn&rsquo;t cut for you.</em></h1>
    <p class="sub">
      Most business software is bought the way a man once bought a suit off a magazine ad. Here&rsquo;s the
      story, what it costs companies every year, and the word we use for the alternative.
    </p>
  </section>

  <!-- THE STORY -->
  <section class="band">
    <div class="neo-prose">
      <div class="eyebrow">The suit</div>
      <h2>A man wants a new suit.</h2>
      <p>
        He goes shopping, passes an ad on an endcap, and there&rsquo;s Dwayne Johnson looking magnificent in a
        charcoal two-piece. <em>I must have that suit.</em> So he buys it. The exact suit, the exact cut, in the
        exact size that fits the shape of Dwayne Johnson, fully expecting to look just as good.
      </p>
      <p>
        He walks out beaming. <strong>They&rsquo;ll respect me now.</strong> Sleeves past his knuckles. Inseam pooling
        over his shoes. Enough fabric in the chest to hide a second, smaller man.
      </p>
      <p>
        It&rsquo;s a silly picture, and everyone can see the fix: you get a suit cut for your own shape. We&rsquo;re all
        different, after all.
      </p>
      <p>
        Oddly enough, this is what most companies do with their &ldquo;big-boy&rdquo; software.
      </p>
    </div>
  </section>

  <!-- THE SPIRAL -->
  <section class="band">
    <div class="band-head">
      <div class="eyebrow">What it costs</div>
      <h2>The tailoring goes the wrong direction.</h2>
      <p>The pattern repeats in company after company. It runs on a schedule you can almost set your watch by.</p>
    </div>
    <div class="spiral">
      <div class="card"><div class="n">01</div><h3>The contract</h3><p>Multi-year, multi-million, for a system that almost fits the company&rsquo;s current shape. It looked great on someone else.</p></div>
      <div class="card"><div class="n">02</div><h3>Sunk cost</h3><p>Eighteen months in, it still doesn&rsquo;t fit. The CTO now has to justify the whole contract, so the problem becomes the company.</p></div>
      <div class="card"><div class="n">03</div><h3>The tailors</h3><p>Consultants and integrators arrive to fit and trim. Not the software. The business. Workflows get rewritten to match the screens.</p></div>
      <div class="card"><div class="n">04</div><h3>Software first</h3><p>A few years on, the company has drifted from what it loved doing to a &ldquo;software-first&rdquo; company, forever bulking and cutting to fit the suit.</p></div>
      <div class="card"><div class="n">05</div><h3>The reckoning</h3><p>Layoffs, ugly meetings, finger-wagging. The CFO and CEO want justification for a system that never supported the actual workflow.</p></div>
    </div>
  </section>

  <!-- THE DEFINITION -->
  <section class="band" id="definition">
    <div class="band-head">
      <div class="eyebrow">The word for the alternative</div>
      <h2>NeoSaaS</h2>
    </div>
    <div class="defn">
      <div class="eyebrow">Definition</div>
      <p>
        <strong>NeoSaaS</strong> is software built to fit one business exactly, owned by that business, and run wherever
        it chooses. It keeps the part of SaaS people like, which is that someone hosts it, patches it and keeps it
        current. It drops the part they hate: renting forever, paying per seat, and bending the company around a
        vendor&rsquo;s roadmap.
      </p>
      <p style="margin-top:14px; font-size:clamp(15px,1.4vw,17px); color:var(--soft);">
        The short way to say it: <strong style="color:var(--text);">first-party software</strong>. Marketers already know the
        difference between third-party data they borrow and first-party data they own. Software splits the same way.
        Third-party SaaS is rented. First-party software is yours. NeoSaaS is first-party software with SaaS upkeep.
      </p>
      <ul>
        <li>Cut to your process, measured on you rather than on a thousand other customers.</li>
        <li>Owned outright. The code lives in your repository, and you can leave with it.</li>
        <li>Hosted and maintained like SaaS, priced like a project instead of a lease.</li>
        <li>Changed the week your business changes, not the quarter the vendor gets to it.</li>
      </ul>
    </div>

    <div class="two">
      <div class="card">
        <h3>Third-party SaaS <span style="color:var(--dim); font-weight:400;">(the suit off the ad)</span></h3>
        <ul class="bad">
          <li>One product, cut for thousands of companies, adjusted by none of them.</li>
          <li>Per seat, per month, forever. Hire someone, pay more.</li>
          <li>The feature you need is &ldquo;on the roadmap.&rdquo; It has been for a while.</li>
          <li>Your data lives at their house. Cancel, and it all evaporates.</li>
          <li>Credits, tokens and meters decide how much you get to build.</li>
          <li>You hire tailors to change the company until the software fits.</li>
        </ul>
      </div>
      <div class="card" style="border-color:rgba(59,118,240,0.45);">
        <h3>NeoSaaS <span style="color:var(--dim); font-weight:400;">(first-party, with upkeep)</span></h3>
        <ul class="good">
          <li>Built around how your business actually runs today.</li>
          <li>You own every line. It sits in your GitHub, or your client&rsquo;s.</li>
          <li>Hosted, patched and watched, without a lease attached.</li>
          <li>Priced per project. Cancel one, stop paying, keep the app.</li>
          <li>Your own model, no credits or tokens. Nobody meters your builds.</li>
          <li>When the business changes, the software changes. Same week.</li>
        </ul>
      </div>
    </div>
  </section>

  <!-- IN PRACTICE -->
  <section class="band">
    <div class="neo-prose">
      <div class="eyebrow">In practice</div>
      <h2>This is what tiknix is for.</h2>
      <p>
        Building used to be the slow, expensive option, which is why everyone bought the suit off the ad. AI
        planners and builders changed the cost. On tiknix you describe what the business needs, a planner and a
        set of builders produce a real full-stack app with its own database, logins and admin, and a reviewer
        checks their work. It runs in its own isolated environment, connects to the Stripe, Shopify or QuickBooks
        account it belongs to, and publishes to your GitHub or your client&rsquo;s domain whenever you say.
      </p>
      <p>
        You bring your own model, Claude Code or any API key, so nobody meters your builds. The first project is
        free. After that it is $49 a month for a project of your own and $99 for one you build for a client, and
        if you cancel one the app keeps running wherever you put it. That is the whole deal in one pricing line: <strong>you were never renting.</strong>
        It is first-party software from the first commit.
      </p>
      <p>
        Need someone to cut it for you rather than build it yourself? <a href="https://clicksimple.com/">ClickSimple</a>,
        the company behind tiknix, does that for businesses with a process worth keeping.
      </p>
    </div>
  </section>

  <!-- THE TL;DR -->
  <section class="band">
    <p class="neo-quote">
      You probably never needed the suit. You probably needed the Jack Black version: shorts and a flannel shirt.
      <small>Keep it light. Build it with love, attention and intention.</small>
    </p>
  </section>

  <!-- FAQ -->
  <section class="band" id="faq">
    <div class="band-head">
      <div class="eyebrow">Questions</div>
      <h2>Straight answers</h2>
    </div>
    <div class="faq-grid">
      <?php foreach ($faqs as $f): ?>
      <div class="card qa"><h3><?= htmlspecialchars($f[0]) ?></h3><p><?= htmlspecialchars($f[1]) ?></p></div>
      <?php endforeach; ?>
    </div>
  </section>

  <!-- FINAL -->
  <section class="final">
    <div class="eyebrow">Start with your own measurements</div>
    <h2 style="margin-top:14px;">Build the thing that fits.</h2>
    <p>Your first project is free. No card, no lease, and the code is yours from the first commit.</p>
    <div style="display:flex; flex-wrap:wrap; justify-content:center; gap:14px; margin-top:30px;">
      <a class="btn btn-primary" href="/auth/register">Start your first project — free</a>
      <a class="btn btn-ghost" href="/#how">See how it works</a>
    </div>
  </section>

  <?php include __DIR__ . '/_marketing-foot.php'; ?>

</div>
</body>
</html>
