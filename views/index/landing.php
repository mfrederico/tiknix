<?php
/**
 * Primary tiknix.com landing page — marketing surface for the freelance-dev /
 * small-agency ICP. Standalone (no app layout), rendered by Index::index() on the
 * flagship host only; instance clones still get index/coming-soon.
 *
 * Vars: $showcase (array of showcase beans, may be empty)
 */
$logoV = @filemtime(dirname(__DIR__, 2) . '/public/img/tiknix.svg') ?: '1';
$hasShowcase = !empty($showcase);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title ?? 'tiknix — build a real app for every client') ?></title>
    <meta name="description" content="tiknix spins up a full-stack app per project with AI — isolated, integrated, and theirs to keep. First builder free, $49/mo per project after.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;600;700&display=swap" rel="stylesheet">
    <style>
        *{ margin:0; padding:0; box-sizing:border-box; }
        :root{
            --bg2:#060d20; --bg1:#0b1530; --panel:#0e1a38;
            --text:#eaedf5; --soft:#9ba4bd; --dim:#6b7593;
            --accent:#3b76f0; --accent2:#6b97f6; --good:#3ddc97;
            --line:rgba(255,255,255,0.08); --line2:rgba(255,255,255,0.14);
            --serif:'Playfair Display', Georgia, serif;
            --sans:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;
            --mono:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;
        }
        html{ background:var(--bg2); scroll-behavior:smooth; }
        body{
            font-family:var(--sans); color:var(--text); line-height:1.5;
            background:
                radial-gradient(1200px 640px at 50% -6%, rgba(59,118,240,0.20), transparent 60%),
                linear-gradient(180deg, var(--bg1) 0%, var(--bg2) 46%);
            -webkit-font-smoothing:antialiased;
        }
        a{ color:var(--accent2); text-decoration:none; }
        a:hover{ color:#9db8fb; }
        h1,h2,h3{ font-family:var(--serif); font-weight:600; letter-spacing:-0.01em; line-height:1.12; }
        .container{ width:min(1180px, 92vw); margin:0 auto; }
        .btn{ display:inline-flex; align-items:center; gap:9px; padding:14px 24px; border-radius:11px;
              font-weight:600; font-size:16px; transition:transform .1s ease, box-shadow .15s ease; }
        .btn:active{ transform:translateY(1px); }
        .btn-primary{ background:var(--accent); color:#fff; box-shadow:0 8px 26px rgba(59,118,240,0.4); }
        .btn-primary:hover{ color:#fff; }
        .btn-ghost{ border:1px solid var(--line2); color:var(--text); }
        .btn-ghost:hover{ color:var(--text); border-color:var(--soft); }
        .eyebrow{ font-size:13px; letter-spacing:0.14em; text-transform:uppercase; color:var(--accent2); font-weight:600; }
        .card{ border:1px solid var(--line); border-radius:16px; background:rgba(255,255,255,0.02); }
        .logo{ display:flex; align-items:center; gap:11px; color:var(--text); }
        .logo-mark{ width:32px; height:32px; flex:0 0 auto; background:currentColor;
            -webkit-mask:url(/img/tiknix.svg?v=<?= $logoV ?>) center/contain no-repeat;
                    mask:url(/img/tiknix.svg?v=<?= $logoV ?>) center/contain no-repeat; }
        .logo-word{ font-family:var(--serif); font-size:23px; font-weight:700; letter-spacing:-0.02em; }

        /* nav */
        .nav{ display:flex; align-items:center; justify-content:space-between; padding:22px 0; }
        .nav-links{ display:flex; align-items:center; gap:30px; font-size:15px; }
        .nav-links a{ color:var(--soft); } .nav-links a:hover{ color:var(--text); }
        .nav-cta{ padding:9px 18px; border-radius:9px; background:var(--accent); color:#fff !important; font-weight:600;
                  box-shadow:0 4px 18px rgba(59,118,240,0.36); }

        /* hero */
        .hero{ display:grid; grid-template-columns:1.05fr 0.95fr; gap:3.5rem; align-items:center;
               padding:56px 0 92px; }
        .hero h1{ font-size:clamp(34px, 4.4vw, 54px); text-wrap:pretty; }
        .hero .sub{ margin-top:22px; font-size:clamp(16px, 1.6vw, 18px); line-height:1.6; color:var(--soft); max-width:540px; }
        .hero-cta{ display:flex; flex-wrap:wrap; gap:14px; margin-top:32px; }
        .hero-fine{ margin-top:20px; font-size:14px; color:var(--dim); }
        .pill{ display:inline-flex; align-items:center; gap:8px; padding:6px 14px; border:1px solid var(--line2);
               border-radius:999px; font-size:13px; color:var(--soft); }
        .dot{ width:6px; height:6px; border-radius:999px; background:var(--good); box-shadow:0 0 8px var(--good); }

        /* hero visual */
        .frame{ border:1px solid var(--line2); border-radius:16px; overflow:hidden;
                background:linear-gradient(180deg,#12203f,#0c1730); box-shadow:0 30px 70px rgba(0,0,0,0.5); }
        .frame-bar{ display:flex; align-items:center; justify-content:space-between; padding:13px 16px; border-bottom:1px solid var(--line); }
        .tl{ width:11px; height:11px; border-radius:999px; display:inline-block; }
        .url{ font-family:var(--mono); font-size:12.5px; color:var(--soft); }
        .badge-iso{ display:inline-flex; align-items:center; gap:6px; font-size:12px; color:var(--good);
                    border:1px solid rgba(61,220,151,0.4); border-radius:999px; padding:4px 10px; }
        .stat{ border:1px solid var(--line); border-radius:10px; padding:14px; background:rgba(255,255,255,0.02); }
        .stat .k{ font-size:12px; color:var(--dim); }
        .stat .v{ font-size:24px; font-weight:700; font-family:var(--serif); margin-top:4px; }
        .chip{ display:inline-flex; align-items:center; gap:7px; font-size:12.5px; color:var(--soft);
               border:1px solid var(--line2); border-radius:8px; padding:7px 11px; }
        .frame-foot{ display:flex; align-items:center; gap:9px; padding:13px 22px; border-top:1px solid var(--line);
                     background:rgba(59,118,240,0.08); font-family:var(--mono); font-size:12.5px; color:var(--accent2); }

        /* generic bands + grids */
        .band{ padding:64px 0; border-top:1px solid var(--line); }
        .band-head{ text-align:center; margin-bottom:44px; }
        .band-head h2{ font-size:clamp(28px, 3.2vw, 38px); margin-top:14px; }
        .band-head p{ font-size:16px; color:var(--soft); margin-top:14px; }
        .grid-3{ display:grid; grid-template-columns:repeat(auto-fit, minmax(280px, 1fr)); gap:22px; }
        .grid-2{ display:grid; grid-template-columns:repeat(auto-fit, minmax(340px, 1fr)); gap:22px; }
        .ic{ width:46px; height:46px; border-radius:12px; background:rgba(59,118,240,0.14);
             display:flex; align-items:center; justify-content:center; color:var(--accent2); }
        .pcard{ padding:30px; }
        .pcard h3{ font-size:22px; margin-top:20px; }
        .pcard p{ font-size:15px; line-height:1.6; color:var(--soft); margin-top:11px; }
        .step-n{ font-family:var(--serif); font-size:34px; font-weight:700; color:var(--accent2); }

        /* integrations */
        .int-row{ display:flex; flex-wrap:wrap; align-items:center; justify-content:center; gap:14px; }
        .int{ display:inline-flex; align-items:center; gap:9px; padding:11px 20px; border:1px solid var(--line2);
              border-radius:11px; font-size:15px; font-weight:600; color:var(--text); }
        .int .d{ width:8px; height:8px; border-radius:999px; }

        /* showcase */
        .scard{ overflow:hidden; display:block; color:inherit; transition:transform .15s ease, border-color .15s ease; }
        .scard:hover{ transform:translateY(-4px); border-color:var(--line2); color:inherit; }
        .shot{ height:150px; display:flex; align-items:center; justify-content:center; overflow:hidden; }
        .shot img{ width:100%; height:100%; object-fit:cover; }
        .scard .body{ padding:20px; }
        .scard .t{ font-family:var(--serif); font-size:18px; font-weight:600; }
        .scard .b{ font-size:14px; color:var(--soft); margin-top:8px; line-height:1.55; }

        /* pricing */
        .price-grid{ display:grid; grid-template-columns:repeat(auto-fit, minmax(320px, 1fr)); gap:22px; max-width:840px; margin:0 auto; }
        .plan{ padding:34px; position:relative; }
        .plan .amt{ font-family:var(--serif); font-size:52px; font-weight:700; }
        .plan .lbl{ font-size:15px; color:var(--soft); font-weight:600; }
        .plan .fine{ font-size:14px; color:var(--dim); margin-top:6px; }
        .plan .feat{ display:flex; flex-direction:column; gap:12px; font-size:15px; color:var(--soft); }
        .plan .feat > div{ display:flex; gap:11px; }
        .ck{ color:var(--good); }
        .plan-hi{ border:1px solid var(--accent); background:linear-gradient(180deg, rgba(59,118,240,0.12), rgba(59,118,240,0.03)); }
        .ribbon{ position:absolute; top:-12px; right:26px; font-size:12px; font-weight:700; letter-spacing:0.04em;
                 background:var(--accent); color:#fff; padding:5px 12px; border-radius:999px; }
        .hr{ height:1px; background:var(--line); margin:22px 0; }

        /* faq */
        .faq-grid{ display:grid; grid-template-columns:repeat(auto-fit, minmax(340px, 1fr)); gap:22px; max-width:980px; margin:0 auto; }
        .qa{ padding:26px; }
        .qa h3{ font-size:18px; font-family:var(--sans); font-weight:700; }
        .qa p{ font-size:15px; line-height:1.6; color:var(--soft); margin-top:10px; }
        .code{ font-family:var(--mono); color:var(--accent2); }

        /* final + footer */
        .final{ text-align:center; padding:96px 0;
                background:radial-gradient(900px 400px at 50% 120%, rgba(59,118,240,0.22), transparent 60%); }
        .final h2{ font-size:clamp(30px, 4vw, 46px); max-width:760px; margin:0 auto; }
        .final p{ font-size:18px; color:var(--soft); margin-top:20px; }
        .foot{ padding:32px 0; border-top:1px solid var(--line); display:flex; flex-wrap:wrap; gap:16px;
               align-items:center; justify-content:space-between; }
        .foot-links{ display:flex; flex-wrap:wrap; gap:24px; font-size:14px; }
        .foot-links a{ color:var(--soft); } .foot-links a:hover{ color:var(--text); }

        @media (max-width: 900px){
            .hero{ grid-template-columns:1fr; gap:2.5rem; padding:36px 0 64px; }
            .hero-visual{ order:2; }
            .nav-links .hide-sm{ display:none; }
            .band{ padding:52px 0; }
            .final{ padding:72px 0; }
        }
    </style>
</head>
<body>

<div class="container">

  <!-- NAV -->
  <nav class="nav">
    <a class="logo" href="/">
      <span class="logo-mark" aria-hidden="true"></span>
      <span class="logo-word">tiknix</span>
    </a>
    <div class="nav-links">
      <a class="hide-sm" href="#how">How it works</a>
      <a class="hide-sm" href="#integrations">Integrations</a>
      <a href="/pricing">Pricing</a>
      <a href="/auth/login">Sign in</a>
      <a class="nav-cta" href="/auth/register">Start free</a>
    </div>
  </nav>

  <!-- HERO -->
  <section class="hero">
    <div>
      <span class="pill"><span class="dot"></span> For studios, agencies &amp; freelance developers</span>
      <h1 style="margin-top:26px;">Build a real app for every client —<br><span style="color:var(--accent2);">isolated, integrated, and theirs to keep.</span></h1>
      <p class="sub">tiknix spins up a full-stack app per project with AI — its own database, auth, and hard walls between every client. Wire up their Stripe or Shopify, then publish to their GitHub or domain.</p>
      <div class="hero-cta">
        <a class="btn btn-primary" href="/auth/register">Start your first project — free</a>
        <a class="btn btn-ghost" href="#how">See how it works
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m13 6 6 6-6 6"/></svg>
        </a>
      </div>
      <p class="hero-fine">First builder instance free · <span style="color:var(--soft);">$49/mo per project after</span> · no card to start</p>
    </div>

    <div class="hero-visual">
      <div class="frame">
        <div class="frame-bar">
          <div style="display:flex; align-items:center; gap:8px;">
            <span class="tl" style="background:#f0655b;"></span>
            <span class="tl" style="background:#f2bd4a;"></span>
            <span class="tl" style="background:#43c86a;"></span>
            <span class="url" style="margin-left:12px;">acme-co.tiknix.app</span>
          </div>
          <span class="badge-iso">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="10" width="16" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>
            Isolated
          </span>
        </div>
        <div style="padding:22px;">
          <div style="font-family:var(--serif); font-size:19px; font-weight:600;">Acme Co — Booking Portal</div>
          <div style="font-size:13px; color:var(--dim); margin-top:3px;">Client project · own database · own users</div>
          <div style="display:grid; grid-template-columns:repeat(3, minmax(0,1fr)); gap:12px; margin-top:18px;">
            <div class="stat"><div class="k">Bookings</div><div class="v">1,284</div></div>
            <div class="stat"><div class="k">Revenue</div><div class="v">$38k</div></div>
            <div class="stat"><div class="k">Members</div><div class="v">642</div></div>
          </div>
          <div style="display:flex; flex-wrap:wrap; gap:10px; margin-top:18px;">
            <span class="chip"><span class="dot"></span>Stripe connected</span>
            <span class="chip"><span class="dot"></span>Shopify connected</span>
          </div>
        </div>
        <div class="frame-foot">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 17 17 7"/><path d="M7 7h10v10"/></svg>
          Published → github.com/acme-co/booking
        </div>
      </div>
    </div>
  </section>

  <!-- PILLARS -->
  <section class="band" style="border-top:none; padding-top:0;">
    <div class="grid-3">
      <div class="card pcard">
        <div class="ic"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3 4 6v6c0 5 3.5 7.5 8 9 4.5-1.5 8-4 8-9V6z"/><path d="m9 12 2 2 4-4"/></svg></div>
        <h3>Walled off by default</h3>
        <p>Every project runs in its own environment — separate OS user, process, and data. One client can never reach another's. Isolation you can put in a contract.</p>
      </div>
      <div class="card pcard">
        <div class="ic"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v14c0 1.7 3.6 3 8 3s8-1.3 8-3V5"/><path d="M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3"/></svg></div>
        <h3>Real backend, real integrations</h3>
        <p>Not a static page: database, logins, admin, and encrypted connections to Stripe, Shopify, QuickBooks and more — each key scoped to the one project that owns it.</p>
      </div>
      <div class="card pcard">
        <div class="ic"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="6" cy="6" r="2.5"/><circle cx="6" cy="18" r="2.5"/><circle cx="18" cy="8" r="2.5"/><path d="M6 8.5v7"/><path d="M18 10.5c0 4-6 2-6 5.5"/></svg></div>
        <h3>Own the code, hand it off</h3>
        <p>Publish straight to your client's GitHub as a branch and pull request, or their own domain. No lock-in, no export tax — a normal app they can host themselves.</p>
      </div>
    </div>
  </section>

  <!-- HOW IT WORKS -->
  <section class="band" id="how">
    <div class="band-head">
      <div class="eyebrow">How it works</div>
      <h2>From brief to handoff in three moves</h2>
    </div>
    <div class="grid-3">
      <div class="card pcard">
        <div class="step-n">01</div>
        <h3 style="font-size:21px; margin-top:14px;">Describe the app</h3>
        <p>Tell the builder what your client needs. It scaffolds a real full-stack app — routes, models, admin — on conventions it keeps consistent.</p>
      </div>
      <div class="card pcard">
        <div class="step-n">02</div>
        <h3 style="font-size:21px; margin-top:14px;">Build &amp; connect</h3>
        <p>Iterate with AI, then wire up the client's own Stripe, Shopify or QuickBooks. Their keys, encrypted, scoped to this project alone.</p>
      </div>
      <div class="card pcard">
        <div class="step-n">03</div>
        <h3 style="font-size:21px; margin-top:14px;">Publish &amp; hand off</h3>
        <p>Ship to their repo or domain, bill them, and move to the next one. Cancel a project when it's done — you stop paying for it.</p>
      </div>
    </div>
  </section>

  <!-- INTEGRATIONS -->
  <section class="band" id="integrations" style="text-align:center;">
    <p style="font-size:14px; color:var(--dim); letter-spacing:0.04em; margin-bottom:26px;">Connect the tools your client already runs on</p>
    <div class="int-row">
      <span class="int"><span class="d" style="background:#635bff;"></span>Stripe</span>
      <span class="int"><span class="d" style="background:#95bf47;"></span>Shopify</span>
      <span class="int"><span class="d" style="background:#2ca01c;"></span>QuickBooks</span>
      <span class="int"><span class="d" style="background:#eaedf5;"></span>GitHub</span>
      <span class="int"><span class="d" style="background:#29a9eb;"></span>Telegram</span>
      <span class="int"><span class="d" style="background:#ff3d57;"></span>Monday</span>
    </div>
  </section>

  <!-- SHOWCASE -->
  <section class="band">
    <div style="display:flex; align-items:flex-end; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:34px;">
      <div>
        <div class="eyebrow">Built with tiknix</div>
        <h2 style="font-size:clamp(26px,3vw,34px); margin-top:12px;"><?= $hasShowcase ? 'Real apps people are shipping' : "The kind of thing you'll ship in an afternoon" ?></h2>
      </div>
      <?php if (!$hasShowcase): ?><span style="font-size:13px; color:var(--dim);">Example projects</span><?php endif; ?>
    </div>
    <div class="grid-3">
      <?php if ($hasShowcase): ?>
        <?php foreach ($showcase as $s): $spath = (string)($s->screenshotPath ?? ''); $ver = (int)($s->capturedAt ?? 0); ?>
          <a class="card scard" href="<?= htmlspecialchars((string)$s->url) ?>" target="_blank" rel="noopener">
            <div class="shot" style="background:linear-gradient(135deg,#1b2c54,#0e1a38);">
              <?php if ($spath !== ''): ?><img src="<?= htmlspecialchars($spath) ?>?v=<?= $ver ?>" alt="<?= htmlspecialchars((string)$s->title) ?> — built with tiknix" loading="lazy"><?php endif; ?>
            </div>
            <div class="body">
              <div class="t"><?= htmlspecialchars((string)$s->title) ?></div>
              <div class="b"><?= htmlspecialchars((string)$s->blurb) ?></div>
            </div>
          </a>
        <?php endforeach; ?>
      <?php else: ?>
        <div class="card scard">
          <div class="shot" style="background:linear-gradient(135deg,#1b2c54,#0e1a38);">
            <svg width="46" height="46" viewBox="0 0 24 24" fill="none" stroke="#6b97f6" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 9h18"/><path d="M8 4v16"/></svg>
          </div>
          <div class="body"><div class="t">Client booking portal</div><div class="b">Scheduling, reminders and Stripe deposits for a service business.</div></div>
        </div>
        <div class="card scard">
          <div class="shot" style="background:linear-gradient(135deg,#173a34,#0e1a38);">
            <svg width="46" height="46" viewBox="0 0 24 24" fill="none" stroke="#3ddc97" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="m7 15 4-5 3 3 5-7"/></svg>
          </div>
          <div class="body"><div class="t">Shopify ops dashboard</div><div class="b">Orders, inventory and QuickBooks sync in one place for a store owner.</div></div>
        </div>
        <div class="card scard">
          <div class="shot" style="background:linear-gradient(135deg,#2a2450,#0e1a38);">
            <svg width="46" height="46" viewBox="0 0 24 24" fill="none" stroke="#a78bfa" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M9 8h6"/><path d="M9 12h6"/><path d="M9 16h4"/></svg>
          </div>
          <div class="body"><div class="t">Internal CRM + portal</div><div class="b">Leads, members and a client-facing login, handed off to their own repo.</div></div>
        </div>
      <?php endif; ?>
    </div>
  </section>

  <!-- PRICING -->
  <section class="band">
    <div class="band-head">
      <div class="eyebrow">Pricing</div>
      <h2>Priced per project, like your invoices</h2>
      <p>Your first builder is free. Every project after is a flat monthly rate — clean COGS on a client engagement.</p>
    </div>
    <div class="price-grid">
      <div class="card plan">
        <div class="lbl">First builder instance</div>
        <div style="display:flex; align-items:baseline; gap:8px; margin-top:12px;"><span class="amt">Free</span></div>
        <div class="fine">forever, no card to start</div>
        <div class="hr"></div>
        <div class="feat">
          <div><span class="ck">✓</span> One full-stack builder instance</div>
          <div><span class="ck">✓</span> Its own isolated environment &amp; database</div>
          <div><span class="ck">✓</span> Connect Stripe, Shopify &amp; more</div>
          <div><span class="ck">✓</span> Publish to your GitHub</div>
        </div>
      </div>
      <div class="card plan plan-hi">
        <div class="ribbon">SCALE PER CLIENT</div>
        <div class="lbl" style="color:var(--text);">Each additional project</div>
        <div style="display:flex; align-items:baseline; gap:6px; margin-top:12px;"><span class="amt">$49</span><span style="font-size:17px; color:var(--soft);">/mo</span></div>
        <div class="fine">per builder instance · cancel one, stop paying</div>
        <div class="hr"></div>
        <div class="feat">
          <div><span class="ck">✓</span> Everything in the free instance</div>
          <div><span class="ck">✓</span> Unlimited projects — add one per client</div>
          <div><span class="ck">✓</span> Publish to their domain, or a container</div>
          <div><span class="ck">✓</span> Expensable to the engagement it belongs to</div>
        </div>
        <a class="btn btn-primary" style="display:flex; justify-content:center; margin-top:26px;" href="/auth/register">Start your first project — free</a>
      </div>
    </div>
  </section>

  <!-- FAQ -->
  <section class="band">
    <h2 style="font-size:clamp(26px,3vw,34px); text-align:center; margin-bottom:44px;">The questions every dev asks first</h2>
    <div class="faq-grid">
      <div class="card qa"><h3>Is the code mine?</h3><p>Yes. Publish it to your own (or your client's) GitHub and host it anywhere. It's a normal app, not a locked export.</p></div>
      <div class="card qa"><h3>How isolated are projects?</h3><p>Each runs under its own OS user and process with its own data — and can get a dedicated container. No project can read another's.</p></div>
      <div class="card qa"><h3>What's the stack?</h3><p><span class="code">PHP (FlightPHP)</span> + <span class="code">SQLite</span> — conventional and readable, so it stays maintainable long after handoff.</p></div>
      <div class="card qa"><h3>Can my client run it without tiknix?</h3><p>Yes — eject to their repo and host it themselves. tiknix is where you build it, not a place it's trapped.</p></div>
    </div>
  </section>

  <!-- FINAL CTA -->
  <section class="final">
    <h2>Ship your next client app on tiknix.</h2>
    <p>Isolated, integrated, and theirs to keep. Your first project is free.</p>
    <a class="btn btn-primary" style="margin-top:32px;" href="/auth/register">Start your first project — free</a>
  </section>

  <!-- FOOTER -->
  <footer class="foot">
    <a class="logo" href="/" style="gap:10px;">
      <span class="logo-mark" style="width:26px; height:26px;" aria-hidden="true"></span>
      <span class="logo-word" style="font-size:17px;">tiknix</span>
      <span style="font-size:13px; color:var(--dim); margin-left:8px;">&copy; <?= date('Y') ?> ClickSimple LLC</span>
    </a>
    <div class="foot-links">
      <a href="/pricing">Pricing</a>
      <a href="/index/security">Security</a>
      <a href="/privacy">Privacy</a>
      <a href="/terms">Terms</a>
    </div>
  </footer>

</div>
</body>
</html>
