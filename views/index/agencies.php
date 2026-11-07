<?php
/**
 * tiknix.com's front page (Index::index): Tiknix for agencies. Build bespoke software for clients with the AI you
 * already pay for, run one app for many clients (each domain its own database, connections and files: app\Host),
 * take the clients' requests as they come (Ask for a change → Change requests), and keep no one locked in.
 *
 * Standalone (no app layout), on the marketing pages' design (_marketing-style, -nav, -foot). The NewSaaS
 * manifesto stays at /newsaas; the old marketing landing (/index/landing) redirects here.
 * Every claim on this page is checked against the code: Host (per-domain data), ChangeAsk/SiteChanges (client
 * requests), MemberAi/Your AI (bring your own model), ProjectQuota and views/index/pricing.php (price).
 */
$logoV = @filemtime(\app\Paths::runtime() . '/public/img/tiknix.svg') ?: '1';
$title = 'tiknix for agencies — build bespoke software once, run it for every client';
$desc = 'Build bespoke software for your clients with the AI you already pay for. Run one app across many client domains, each with its own database, payments and mail. The code is an ordinary app in a Git repository you control. First project free, then $49 a month per project, your whole team included.';
$start = 'https://start.tiknix.com/start';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title) ?></title>
    <meta name="description" content="<?= htmlspecialchars($desc) ?>">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="tiknix">
    <meta property="og:title" content="Build it once. Run it for every client.">
    <meta property="og:description" content="<?= htmlspecialchars($desc) ?>">
    <meta property="og:url" content="https://tiknix.com/">
    <meta property="og:image" content="https://tiknix.com/img/og-agencies.png">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:image:alt" content="tiknix for agencies: one app, many client domains, each with its own database, payments and mail.">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="Build it once. Run it for every client.">
    <meta name="twitter:description" content="<?= htmlspecialchars($desc) ?>">
    <meta name="twitter:image" content="https://tiknix.com/img/og-agencies.png">
    <link rel="canonical" href="https://tiknix.com/">
    <link href="/rt/vendor/fonts/playfair.css" rel="stylesheet">
    <?php include __DIR__ . '/_marketing-style.php'; ?>
    <style>
        /* one app → many client domains */
        .fan{ padding:26px; }
        .fan-app{ border:1px solid rgba(59,118,240,0.55); border-radius:14px; padding:16px 18px;
                  background:linear-gradient(180deg, rgba(59,118,240,0.18), rgba(59,118,240,0.05)); }
        .fan-app .k{ font-size:12px; letter-spacing:.12em; text-transform:uppercase; color:var(--accent2); font-weight:700; }
        .fan-app .t{ font-family:var(--serif); font-size:21px; font-weight:600; margin-top:4px; }
        .fan-app .s{ font-size:13px; color:var(--soft); margin-top:6px; }
        .fan-lines{ display:block; width:100%; height:34px; color:var(--line2); }
        .sites{ display:grid; grid-template-columns:repeat(3, 1fr); gap:10px; }
        .site{ border:1px solid var(--line2); border-radius:12px; padding:12px; background:rgba(255,255,255,0.025); min-width:0; }
        .site .d{ font-family:var(--mono); font-size:11.5px; color:var(--text); line-height:1.35; }
        .site ul{ list-style:none; margin-top:9px; display:grid; gap:5px; }
        .site li{ font-size:12px; color:var(--soft); display:flex; gap:6px; align-items:center; }
        .site li::before{ content:""; width:6px; height:6px; border-radius:2px; background:var(--good); flex:none; }
        .fan-foot{ margin-top:16px; font-size:13px; color:var(--dim); text-align:center; }
        @media (max-width:520px){ .sites{ grid-template-columns:1fr; } .fan-lines{ display:none; } .fan-app{ margin-bottom:10px; } }

        /* shared vs each client's own */
        .split{ display:grid; grid-template-columns:1fr 1fr; gap:22px; }
        .split .card{ padding:30px; }
        .split h3{ font-size:22px; }
        .split .what{ margin-top:18px; display:grid; gap:12px; }
        .split .what div{ display:flex; gap:11px; font-size:15px; color:var(--soft); line-height:1.55; }
        .split .what b{ color:var(--text); font-weight:600; }
        .mark{ flex:none; width:22px; height:22px; border-radius:7px; display:flex; align-items:center; justify-content:center;
               font-size:12px; font-weight:700; background:rgba(59,118,240,0.16); color:var(--accent2); }
        .mark.own{ background:rgba(61,220,151,0.14); color:var(--good); }
        @media (max-width:900px){ .split{ grid-template-columns:1fr; } }

        /* the client's request, as it travels */
        .flow{ display:grid; grid-template-columns:repeat(4, 1fr); gap:14px; counter-reset:f; }
        .flow .card{ padding:22px; position:relative; }
        .flow .n{ font-family:var(--serif); font-size:30px; font-weight:700; color:var(--accent2); }
        .flow h3{ font-size:18px; margin-top:8px; }
        .flow p{ font-size:14.5px; color:var(--soft); line-height:1.6; margin-top:8px; }
        .flow .who{ display:inline-block; margin-top:12px; font-size:12px; color:var(--dim); border:1px solid var(--line); border-radius:999px; padding:3px 10px; }
        @media (max-width:900px){ .flow{ grid-template-columns:1fr 1fr; } }
        @media (max-width:520px){ .flow{ grid-template-columns:1fr; } }

        .lede-c{ max-width:720px; margin:14px auto 0; }
        .note-band{ margin-top:26px; text-align:center; font-size:15px; color:var(--soft); }
        .manifesto{ display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:18px; padding:26px 30px; margin-top:30px; }
        .manifesto p{ font-size:16px; color:var(--soft); max-width:640px; }
        .manifesto b{ color:var(--text); font-family:var(--serif); font-size:20px; display:block; margin-bottom:4px; }
        @media (prefers-reduced-motion: reduce){ *{ transition:none !important; animation:none !important; scroll-behavior:auto !important; } }
    </style>
</head>
<body>
<div class="container">
<?php include __DIR__ . '/_marketing-nav.php'; ?>

  <!-- HERO -->
  <header class="hero">
    <div>
      <span class="pill"><span class="dot"></span> For agencies and studios</span>
      <h1 style="margin-top:22px;">Build it once.<br>Run it for every client.</h1>
      <p class="sub">tiknix is where your agency builds bespoke software for clients with the AI you already pay for.
        One app can serve many clients, each on their own domain with their own database, payments and mail.
        The code is an ordinary app in a Git repository you control, not something only we can read.</p>
      <div class="hero-cta">
        <a class="btn btn-primary" href="<?= $start ?>">Start your first project, free</a>
        <a class="btn btn-ghost" href="#one-app">See how one app serves many clients</a>
      </div>
      <p class="hero-fine">Bring your own Claude subscription or API key. First project free, then $49 a month per project, with your whole team included.</p>
    </div>
    <div class="hero-visual">
      <div class="card fan" role="img" aria-label="One app, built once, running on three client domains. Each domain has its own database, payments, mail and files.">
        <div class="fan-app">
          <div class="k">One app, built once</div>
          <div class="t">Bookings &amp; orders</div>
          <div class="s">Code, plugins and the builder are shared. Fix it once and every client has the fix.</div>
        </div>
        <svg class="fan-lines" viewBox="0 0 300 34" preserveAspectRatio="none" aria-hidden="true">
          <path d="M150 0 V12 M150 12 H50 V34 M150 12 V34 M150 12 H250 V34" fill="none" stroke="currentColor" stroke-width="1.5"/>
        </svg>
        <div class="sites">
          <?php foreach ([['book.harbourstudio.com', 'Stripe'], ['orders.northfieldbakery.co', 'PayPal'], ['portal.ridgewaydental.net', 'QuickBooks']] as [$d, $pay]): ?>
          <div class="site">
            <div class="d" title="<?= $d ?>"><?= str_replace('.', '.<wbr>', $d) /* a narrow card breaks the address at its dots */ ?></div>
            <ul><li>Its own database</li><li><?= $pay ?> account</li><li>Mail from its domain</li><li>Name, logo, settings</li></ul>
          </div>
          <?php endforeach; ?>
        </div>
        <p class="fan-foot">Example client sites. Each one's data stays in its own database.</p>
      </div>
    </div>
  </header>

  <!-- ONE APP, MANY CLIENTS -->
  <section class="band" id="one-app">
    <div class="band-head">
      <div class="eyebrow">One app, many clients</div>
      <h2>Your product, their domain, their data</h2>
      <p class="lede-c">Most agency work is the same app with a different logo. With tiknix you build that app once, then point each
        client's domain at it. Every domain runs as its own site with its own world behind it.</p>
    </div>
    <div class="split">
      <div class="card">
        <h3>Shared by every client</h3>
        <div class="what">
          <div><span class="mark">1</span><span><b>The code.</b> One codebase to build, review and improve. A fix ships to every client on the next update.</span></div>
          <div><span class="mark">2</span><span><b>Plugins.</b> Shop, tickets, messaging, cookie consent and more, installed once for all.</span></div>
          <div><span class="mark">3</span><span><b>The builder.</b> The AI that plans and builds changes works on the one app.</span></div>
        </div>
      </div>
      <div class="card">
        <h3>Each client's own</h3>
        <div class="what">
          <div><span class="mark own">✓</span><span><b>Database and log.</b> Separate files, so one client's records never sit beside another's.</span></div>
          <div><span class="mark own">✓</span><span><b>Connections.</b> Their own Stripe or PayPal, Mailgun, storage and more, locked with their own key.</span></div>
          <div><span class="mark own">✓</span><span><b>Settings and brand.</b> A config of its own: the site's name, logo and options.</span></div>
          <div><span class="mark own">✓</span><span><b>Files and automations.</b> Uploads, cache and pipeline runs kept under that domain.</span></div>
        </div>
      </div>
    </div>
    <p class="note-band">Adding a client is adding a domain to the project: no second app to maintain, and no extra charge per domain.</p>
  </section>

  <!-- CLIENTS IN THE LOOP -->
  <section class="band" id="clients">
    <div class="band-head">
      <div class="eyebrow">Iterate with your clients</div>
      <h2>Take their notes where they work</h2>
      <p class="lede-c">Your client's staff ask for changes from their own site, with screenshots, without a tiknix account.
        You decide what gets built, and they hear when it is live.</p>
    </div>
    <div class="flow">
      <div class="card"><div class="n">01</div><h3>They ask</h3><p>"Ask for a change" on their own site: what they want, the page it is about, and screenshots they paste or capture.</p><span class="who">Your client</span></div>
      <div class="card"><div class="n">02</div><h3>You talk it through</h3><p>It arrives as a change request and a conversation. Your reply reaches them by email, and their reply comes back.</p><span class="who">Your team</span></div>
      <div class="card"><div class="n">03</div><h3>You build it</h3><p>"Build it" hands the request, page and screenshots to the builder. You approve the plan; each change is checked in a real browser before it goes live.</p><span class="who">Your AI, your call</span></div>
      <div class="card"><div class="n">04</div><h3>They hear it's live</h3><p>When the build finishes, the request is marked done and they are told, on their site and by email.</p><span class="who">Automatic</span></div>
    </div>
    <p class="note-band">You choose who may ask, person by person. Nothing changes on a client's site until someone on your team presses Build.</p>
  </section>

  <!-- BRING YOUR OWN AI -->
  <section class="band" id="ai">
    <div class="band-head">
      <div class="eyebrow">Bring your own AI</div>
      <h2>Use the AI subscription you already have</h2>
      <p class="lede-c">tiknix is a harness, not a reseller. Connect your Claude subscription or an API key once, and every project
        builds with it. No credits and no markup on tokens: you pay your AI provider directly, at your own rates.</p>
    </div>
    <div class="grid-3">
      <div class="card pcard"><div class="step-n">A</div><h3>A place to build</h3><p>Each project gets its own server, database, tests and a browser to check its work, so the AI builds a working app, not a code snippet.</p></div>
      <div class="card pcard"><div class="step-n">B</div><h3>A record that remembers</h3><p>Decisions, lessons and what each request built are kept in the project's notebook. The next change starts from what the last one learned.</p></div>
      <div class="card pcard"><div class="step-n">C</div><h3>Undo, always</h3><p>Every build is a checkpoint. If a change is wrong, go back to how it was and ask again.</p></div>
    </div>
  </section>

  <!-- NO LOCK-IN -->
  <section class="band" id="own">
    <div class="band-head">
      <div class="eyebrow">No lock-in</div>
      <h2>The code is yours, and your client's</h2>
      <p class="lede-c">Every app is an ordinary PHP app in its own Git repository: Flight for routes, RedBean for data, Composer for packages.
        Your developers can read and change every line.</p>
    </div>
    <div class="grid-3">
      <div class="card pcard"><h3>Sync to your GitHub</h3><p>Connect a repository and changes go both ways: builds arrive as pull requests, and what your developers push comes back to the app.</p></div>
      <div class="card pcard"><h3>Copy it to your servers</h3><p>Send the app's code to a server of your own over rsync or SSH, whenever you choose, or keep it running here behind your clients' domains.</p></div>
      <div class="card pcard"><h3>Nothing tangled</h3><p>Each client's data lives in its own database, never mixed with another client's, so handing one client their part is handing over their files.</p></div>
    </div>
    <div class="card manifesto">
      <p><b>Why owned software beats rented software</b>Third-party SaaS makes a business bend to fit its screens. Software cut for one business, owned by it and kept up like SaaS, does the opposite. We call it NewSaaS.</p>
      <a class="btn btn-ghost" href="/newsaas">Read the NewSaaS story</a>
    </div>
  </section>

  <!-- INTEGRATIONS -->
  <section class="band" id="integrations">
    <div class="band-head">
      <div class="eyebrow">Integrations</div>
      <h2>Connect what each client already uses</h2>
      <p class="lede-c">Every domain connects its own accounts. One client takes payments with Stripe, the next with PayPal.</p>
    </div>
    <div class="int-row">
      <?php foreach ([['Stripe', '#635bff'], ['PayPal', '#2b8be6'], ['Shopify', '#95bf47'], ['QuickBooks', '#2ca01c'], ['Mailgun', '#f06b66'],
                      ['HubSpot', '#ff7a59'], ['Klaviyo', '#e8e5df'], ['Microsoft 365', '#29a9eb'], ['Monday', '#ff3d57'], ['S3 storage', '#f4a836'],
                      ['GitHub', '#eaedf5'], ['Text messages', '#3ddc97'], ['Google Analytics', '#f9ab00']] as [$n, $c]): ?>
        <span class="int"><span class="d" style="background:<?= $c ?>;"></span><?= $n ?></span>
      <?php endforeach; ?>
    </div>
  </section>

  <!-- PRICING -->
  <section class="band" id="pricing">
    <div class="band-head">
      <div class="eyebrow">Pricing</div>
      <h2>Per project, never per seat</h2>
      <p class="lede-c">A project is one app with all of its client domains. Your whole team works on it at no extra cost.</p>
    </div>
    <div class="price-grid">
      <div class="card plan">
        <div class="lbl">Your first project</div>
        <div class="amt" style="color:var(--good);">Free</div>
        <div class="fine">No card to start. The code you build is yours to keep.</div>
        <div class="hr"></div>
        <div class="feat"><div><span class="ck">✓</span>One app, with its own server and database</div><div><span class="ck">✓</span>Your own AI subscription or key</div><div><span class="ck">✓</span>Solo workspace</div></div>
      </div>
      <div class="card plan plan-hi">
        <span class="ribbon">For agencies</span>
        <div class="lbl">Each project after that</div>
        <div class="amt">$49<span style="font-size:18px; color:var(--soft); font-family:var(--sans); font-weight:600;"> / month</span></div>
        <div class="fine">Per project, not per seat.</div>
        <div class="hr"></div>
        <div class="feat"><div><span class="ck">✓</span>As many client domains as the app serves</div><div><span class="ck">✓</span>Your whole team included</div><div><span class="ck">✓</span>Client change requests and GitHub sync</div></div>
      </div>
    </div>
    <p class="note-band"><a href="/pricing">Pricing in full</a></p>
  </section>

  <!-- FAQ -->
  <section class="band" id="faq">
    <div class="band-head"><div class="eyebrow">Questions</div><h2>Straight answers</h2></div>
    <div class="faq-grid">
      <div class="card qa"><h3>Do my clients need a tiknix account?</h3><p>No. Their staff sign in to their own site. You switch "Ask for changes" on for the people who should send requests, and their requests reach your team.</p></div>
      <div class="card qa"><h3>Can one client see another's data?</h3><p>No. Each domain has its own database, connections and files. They share code, not records.</p></div>
      <div class="card qa"><h3>Can each client look different?</h3><p>Yes. Each domain has its own settings, including its name and logo, and its own accounts for payments and mail.</p></div>
      <div class="card qa"><h3>Which AI does it use?</h3><p>Yours. Connect a Claude subscription or an API key on "Your AI" once, and your projects build with it. You pay your provider, not us.</p></div>
      <div class="card qa"><h3>What if a client wants to leave?</h3><p>The code is a Git repository you can sync to GitHub or copy to their server, and their data is already in a database of its own. Talk to us and we will help you move it.</p></div>
      <div class="card qa"><h3>What does a client site cost?</h3><p>Nothing extra. You pay $49 a month for the project, however many client domains it serves. Your first project is free.</p></div>
    </div>
  </section>

  <!-- FINAL -->
  <section class="final">
    <h2>Build your agency's product once. Sell it to every client.</h2>
    <p>Start with your first project free, using the AI you already have.</p>
    <div style="margin-top:30px; display:flex; flex-wrap:wrap; gap:14px; justify-content:center;">
      <a class="btn btn-primary" href="<?= $start ?>">Start your first project, free</a>
      <a class="btn btn-ghost" href="/contact">Talk to us about your agency</a>
    </div>
  </section>

<?php include __DIR__ . '/_marketing-foot.php'; ?>
</div>
</body>
</html>
