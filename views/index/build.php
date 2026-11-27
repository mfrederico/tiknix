<?php
/**
 * tiknix.com's front page (Index::index, owner's call 2026-11-27): "we build your business app in a week".
 * A done-for-you offer to business owners: $1,500 for the first version, built in a week, then $49 a month for
 * hosting, updates and small changes. The agency platform pitch it replaced at "/" lives on at /agencies.
 *
 * The form posts to the runtime's /contact/submit as the "build" category (lib/controlplane.php registers it for the
 * queue only, so /contact's own dropdown does not offer it), so it gets that form's bot defences (honeypot, form_ts,
 * CSRF, Turnstile, per-IP limit), lands in Support messages under "Build my app" and is announced to the admins.
 * "Your business" is the message's subject: the queue lists each request by the business that sent it. The fields sit behind Turnstile until it
 * passes, as on /contact (only a stranger is asked: Turnstile::asks()).
 *
 * Standalone (no app layout), on the marketing pages' design (_marketing-style, -nav, -foot). Every claim on this
 * page is one the platform keeps: each app has its own domain and database (app\Host), connects its own accounts
 * (Connections), takes change requests from its own site (Ask for a change), and is an ordinary app in Git.
 */
$logoV = @filemtime(\app\Paths::runtime() . '/public/img/tiknix.svg') ?: '1';   // _marketing-style's logo cache-buster
$title = 'tiknix — your business app, built in a week';
$desc = 'Tell us what your business needs. We build your app in a week: your own domain, your data, your payments and email connected. $1,500 for the first version, then $49 a month for hosting, updates and small changes.';
$tsKey = \app\Turnstile::asks() ? \app\Turnstile::siteKey() : '';
$reach = require __DIR__ . '/_reach.php';   // clicksimple.com's phone and "Book a chat"
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
    <meta property="og:title" content="Your business app, built in a week.">
    <meta property="og:description" content="<?= htmlspecialchars($desc) ?>">
    <meta property="og:url" content="https://tiknix.com/">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="Your business app, built in a week.">
    <meta name="twitter:description" content="<?= htmlspecialchars($desc) ?>">
    <link rel="canonical" href="https://tiknix.com/">
    <link href="/rt/vendor/fonts/playfair.css" rel="stylesheet">
    <?php include __DIR__ . '/_marketing-style.php'; ?>
    <style>
        .lede-c{ max-width:720px; margin:14px auto 0; }
        .note-band{ margin-top:26px; text-align:center; font-size:15px; color:var(--soft); }

        /* hero: the week, day by day */
        .week{ padding:24px; }
        .week .k{ font-size:12px; letter-spacing:.12em; text-transform:uppercase; color:var(--accent2); font-weight:700; }
        .week ol{ list-style:none; margin-top:14px; display:grid; gap:10px; }
        .week li{ display:grid; grid-template-columns:78px 1fr; gap:12px; align-items:start; padding:12px 14px;
                  border:1px solid var(--line); border-radius:12px; background:rgba(255,255,255,0.02); }
        .week .d{ font-family:var(--mono); font-size:12px; color:var(--accent2); padding-top:2px; }
        .week b{ display:block; font-size:15px; }
        .week span.s{ display:block; font-size:13.5px; color:var(--soft); margin-top:3px; line-height:1.5; }
        .week li.live{ border-color:rgba(61,220,151,0.45); background:rgba(61,220,151,0.06); }
        .week li.live .d{ color:var(--good); }

        /* what we build */
        .kinds{ display:grid; grid-template-columns:repeat(3, 1fr); gap:16px; }
        .kinds .card{ padding:22px; }
        .kinds h3{ font-size:19px; }
        .kinds p{ font-size:14.5px; color:var(--soft); line-height:1.6; margin-top:8px; }
        @media (max-width:900px){ .kinds{ grid-template-columns:1fr 1fr; } }
        @media (max-width:520px){ .kinds{ grid-template-columns:1fr; } }

        /* how it works */
        .flow{ display:grid; grid-template-columns:repeat(3, 1fr); gap:14px; }
        .flow .card{ padding:22px; }
        .flow .n{ font-family:var(--serif); font-size:30px; font-weight:700; color:var(--accent2); }
        .flow h3{ font-size:18px; margin-top:8px; }
        .flow p{ font-size:14.5px; color:var(--soft); line-height:1.6; margin-top:8px; }
        @media (max-width:900px){ .flow{ grid-template-columns:1fr; } }

        /* the form */
        .ask{ max-width:720px; margin:0 auto; padding:30px; }
        .ask label{ display:block; font-size:14px; font-weight:600; margin-bottom:6px; }
        .ask .row2{ display:grid; grid-template-columns:1fr 1fr; gap:16px; }
        .ask .f{ margin-bottom:18px; }
        .ask input[type=text], .ask input[type=email], .ask textarea{
            width:100%; padding:12px 14px; border-radius:10px; border:1px solid var(--line2);
            background:rgba(255,255,255,0.04); color:var(--text); font:inherit; font-size:15px; }
        .ask input:focus, .ask textarea:focus{ outline:2px solid var(--accent); outline-offset:1px; border-color:transparent; }
        .ask textarea{ min-height:150px; resize:vertical; line-height:1.55; }
        .ask .help{ font-size:13px; color:var(--dim); margin-top:6px; }
        .ask .send{ display:flex; flex-wrap:wrap; align-items:center; gap:16px; margin-top:6px; }
        .ask .send .fine{ font-size:13.5px; color:var(--dim); }
        .ts-gate{ text-align:center; padding:8px 0 4px; }
        .ts-gate p{ font-size:14px; color:var(--soft); margin-bottom:12px; }
        .ts-gate .cf-turnstile{ display:inline-block; }
        .hp{ position:absolute; left:-9999px; top:auto; width:1px; height:1px; overflow:hidden; }
        @media (max-width:620px){ .ask{ padding:22px; } .ask .row2{ grid-template-columns:1fr; gap:0; } }
        @media (prefers-reduced-motion: reduce){ *{ transition:none !important; animation:none !important; scroll-behavior:auto !important; } }
    </style>
</head>
<body>
<div class="container">
<?php include __DIR__ . '/_marketing-nav.php'; ?>

  <!-- HERO -->
  <header class="hero">
    <div>
      <span class="pill"><span class="dot"></span> Built for your business, not rented</span>
      <h1 style="margin-top:22px;">Your business app,<br>built in a week.</h1>
      <p class="sub">Bookings, orders, invoices, a customer portal, the spreadsheet your team has outgrown:
        tell us what your business needs and we build it for you. In a week you are using it, on your own
        domain, with your data and your accounts connected.</p>
      <div class="hero-cta">
        <a class="btn btn-primary" href="<?= $reach['book'] ?>" rel="noopener">Book a chat</a>
        <a class="btn btn-ghost" href="#start">Tell us what you need</a>
      </div>
      <p class="hero-fine">Or call <a href="<?= $reach['phone_href'] ?>"><?= $reach['phone'] ?></a>.
        $1,500 for the first version, built in a week. Then $49 a month for hosting, updates and small changes.</p>
    </div>
    <div class="hero-visual">
      <div class="card week" role="img" aria-label="A week, from your first message to your app going live.">
        <div class="k">Your week</div>
        <ol>
          <li><span class="d">Day 1</span><span><b>We talk it through</b><span class="s">What it must do, who uses it, what it connects to.</span></span></li>
          <li><span class="d">Days 2–5</span><span><b>We build it</b><span class="s">You see it take shape on its own address and tell us what to change.</span></span></li>
          <li><span class="d">Day 6</span><span><b>You try it with your team</b><span class="s">Real data, real logins, last fixes.</span></span></li>
          <li class="live"><span class="d">Day 7</span><span><b>It's live</b><span class="s">On your domain. Your team uses it from Monday.</span></span></li>
        </ol>
      </div>
    </div>
  </header>

  <!-- WHAT WE BUILD -->
  <section class="band" id="examples">
    <div class="band-head">
      <div class="eyebrow">What we build</div>
      <h2>The app your business keeps working around</h2>
      <p class="lede-c">Most businesses run on a patchwork of spreadsheets, inboxes and tools that almost fit.
        We build the one that does: yours, shaped around how you already work.</p>
    </div>
    <div class="kinds">
      <div class="card"><h3>Bookings &amp; scheduling</h3><p>Customers book online, staff see their day, reminders go out by email or text.</p></div>
      <div class="card"><h3>Orders &amp; inventory</h3><p>Take orders, track stock and fulfilment, and connect your Shopify store or warehouse.</p></div>
      <div class="card"><h3>Invoices &amp; payments</h3><p>Send invoices, take card payments with Stripe or PayPal, and keep QuickBooks in step.</p></div>
      <div class="card"><h3>Customer portals</h3><p>A place your clients sign in to see their orders, documents, cases or appointments.</p></div>
      <div class="card"><h3>Leads &amp; follow-up</h3><p>Capture enquiries, track every lead through to a sale, and follow up on time.</p></div>
      <div class="card"><h3>Your team's own tools</h3><p>The internal system for jobs, checklists, approvals or reports that no off-the-shelf app quite covers.</p></div>
    </div>
  </section>

  <!-- HOW IT WORKS -->
  <section class="band" id="how">
    <div class="band-head">
      <div class="eyebrow">How it works</div>
      <h2>From a message to a working app</h2>
    </div>
    <div class="flow">
      <div class="card"><div class="n">01</div><h3>Tell us what you need</h3><p>Book a 30-minute chat, call us, or write a few sentences below. Either way we start with how your business works.</p></div>
      <div class="card"><div class="n">02</div><h3>We build it in a week</h3><p>We agree what the first version does, then build it on its own address so you can watch it come together and steer it.</p></div>
      <div class="card"><div class="n">03</div><h3>Use it, and keep improving it</h3><p>It goes live on your domain. Ask for changes from inside your own app, with screenshots, and we take care of them.</p></div>
    </div>
    <p class="note-band">If what you need is more than a week's work, we will say so on the first call and quote it before anything starts.</p>
  </section>

  <!-- WHAT YOU GET -->
  <section class="band" id="yours">
    <div class="band-head">
      <div class="eyebrow">Yours, not rented</div>
      <h2>Software that fits you, and stays yours</h2>
    </div>
    <div class="grid-3">
      <div class="card pcard"><h3>Your domain, your data</h3><p>It runs on your own address with its own database. Your records are never mixed with anyone else's.</p></div>
      <div class="card pcard"><h3>Your accounts connected</h3><p>Stripe or PayPal, your email, QuickBooks, Shopify, storage and more, signed in with your own accounts.</p></div>
      <div class="card pcard"><h3>The code is yours</h3><p>An ordinary app in a Git repository. If you ever want to take it elsewhere, it goes with you.</p></div>
    </div>
  </section>

  <!-- PRICING -->
  <section class="band" id="price">
    <div class="band-head">
      <div class="eyebrow">Pricing</div>
      <h2>One price to build it, a small one to keep it running</h2>
    </div>
    <div class="price-grid">
      <div class="card plan plan-hi">
        <span class="ribbon">Built in a week</span>
        <div class="lbl">Your first version</div>
        <div class="amt">$1,500</div>
        <div class="fine">Fixed price, agreed before we start.</div>
        <div class="hr"></div>
        <div class="feat"><div><span class="ck">✓</span>Designed around how your business works</div><div><span class="ck">✓</span>Live on your domain in a week</div><div><span class="ck">✓</span>Your payments, email and tools connected</div></div>
      </div>
      <div class="card plan">
        <div class="lbl">After that</div>
        <div class="amt">$49<span style="font-size:18px; color:var(--soft); font-family:var(--sans); font-weight:600;"> / month</span></div>
        <div class="fine">Hosting, updates and small changes.</div>
        <div class="hr"></div>
        <div class="feat"><div><span class="ck">✓</span>Kept running, backed up and up to date</div><div><span class="ck">✓</span>Ask for changes from inside your app</div><div><span class="ck">✓</span>Your whole team, no per-seat fees</div></div>
      </div>
    </div>
  </section>

  <!-- FAQ -->
  <section class="band" id="faq">
    <div class="band-head"><div class="eyebrow">Questions</div><h2>Straight answers</h2></div>
    <div class="faq-grid">
      <div class="card qa"><h3>Can it really be done in a week?</h3><p>For a focused first version, yes. On the first call we agree exactly what that version does. Anything bigger is quoted up front, never discovered later.</p></div>
      <div class="card qa"><h3>What counts as a small change?</h3><p>The everyday kind: a new field, a report, a change to a page or an email. Something new and large is quoted like the first version.</p></div>
      <div class="card qa"><h3>Do I need to be technical?</h3><p>No. You tell us how your business works; we turn that into the app. You will never be asked to write code.</p></div>
      <div class="card qa"><h3>Who owns the app?</h3><p>You do. It is an ordinary app in a Git repository, and your data sits in its own database.</p></div>
      <div class="card qa"><h3>What if I stop paying monthly?</h3><p>We hand you the code and your data, and help you move it if you want to run it somewhere else.</p></div>
      <div class="card qa"><h3>Do you build for agencies too?</h3><p>Yes, in a different way: agencies build on tiknix themselves. See <a href="/agencies">tiknix for agencies</a>.</p></div>
    </div>
  </section>

  <!-- THE FORM -->
  <section class="band" id="start">
    <div class="band-head">
      <div class="eyebrow">Get started</div>
      <h2>Tell us what your business needs</h2>
      <p class="lede-c">Rather talk? <a href="<?= $reach['book'] ?>" rel="noopener">Book a 30-minute chat</a> or call
        <a href="<?= $reach['phone_href'] ?>"><?= $reach['phone'] ?></a>. Or write a few sentences below and we reply within one business day.</p>
    </div>
    <form class="card ask" method="POST" action="/contact/submit">
      <?= csrf_field() ?>
      <input type="hidden" name="category" value="build">
      <input type="hidden" name="form_ts" value="<?= time() ?>">
      <?php /* The honeypot: no person sees it, a form-filler completes it (Contact::submit). No placeholder:
               a screen reader and a bot would both read it. */ ?>
      <div class="hp" aria-hidden="true"><label>Website<input type="text" name="website" tabindex="-1" autocomplete="off" value=""></label></div>

      <?php if ($tsKey !== ''): ?>
      <div class="ts-gate" id="ts-gate">
        <p>Please confirm you're a person to open the form.</p>
        <?= \app\Turnstile::widget(['callback' => 'tsVerified', 'expired_callback' => 'tsReset', 'error_callback' => 'tsReset', 'theme' => 'dark']) ?>
      </div>
      <div id="ask-fields" hidden>
      <?php endif; ?>

        <div class="row2">
          <div class="f"><label for="b-name">Your name</label><input type="text" id="b-name" name="name" autocomplete="name" required maxlength="120"></div>
          <div class="f"><label for="b-email">Email</label><input type="email" id="b-email" name="email" autocomplete="email" required maxlength="190"></div>
        </div>
        <div class="f"><label for="b-business">Your business</label><input type="text" id="b-business" name="subject" autocomplete="organization" required maxlength="160">
          <div class="help">Its name, and what it does in a few words.</div></div>
        <div class="f"><label for="b-need">What should the app do?</label><textarea id="b-need" name="message" required maxlength="5000"></textarea>
          <div class="help">Who would use it, what they do today, and what you wish it did for you.</div></div>
        <div class="send">
          <button class="btn btn-primary" type="submit">Send it to us</button>
          <span class="fine">$1,500 for the first version, then $49 a month. Nothing is charged until you agree what we build.</span>
        </div>

      <?php if ($tsKey !== ''): ?>
      </div>
      <script>
        // The fields open once Turnstile passes, and close again if its answer expires (as on /contact).
        function tsVerified(){ document.getElementById('ask-fields').hidden = false;
          var p = document.querySelector('#ts-gate p'); if (p) p.textContent = 'Thanks. Tell us about your business below.'; }
        function tsReset(){ document.getElementById('ask-fields').hidden = true; }
      </script>
      <?php endif; ?>
    </form>
  </section>

<?php include __DIR__ . '/_marketing-foot.php'; ?>
</div>
</body>
</html>
