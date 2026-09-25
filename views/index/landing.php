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
    <meta name="description" content="tiknix spins up a full-stack app per project with AI — isolated, integrated, and theirs to keep. Bring your own model, no credits. First project free, then $49/mo per project.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;600;700&display=swap" rel="stylesheet">
    <?php include __DIR__ . '/_marketing-style.php'; ?>
</head>
<body>

<div class="container">

  <?php include __DIR__ . '/_marketing-nav.php'; ?>

  <!-- HERO -->
  <section class="hero">
    <div>
      <span class="pill"><span class="dot"></span> Code sovereignty</span>
      <h1 style="margin-top:26px;">A real, custom app — built, deployed, and running in a week.<br><span style="color:var(--accent2);">Yours to keep, full source in hand.</span></h1>
      <p class="sub">Describe what you need and tiknix's AI builds the real thing — its own database, auth, and encrypted connections to Stripe, Shopify and more. Publish it to your own (or your client's) GitHub, host it anywhere, walk away anytime. Not a subscription you rent and never control — <em>you</em> own every line, whether you review each one or never touch the code.</p>
      <div class="hero-cta">
        <a class="btn btn-primary" href="/auth/register">Start your first project — free</a>
        <a class="btn btn-ghost" href="#how">See how it works
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m13 6 6 6-6 6"/></svg>
        </a>
      </div>
      <p class="hero-fine">First project free · <span style="color:var(--soft);">$49/mo per project after</span> · bring your own model, no credits · no card to start</p>
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
        <h3>Own every line — no lock-in</h3>
        <p>The code the AI writes is yours. Publish it to your (or your client's) GitHub, host it anywhere, walk away anytime. Software sovereignty — not another subscription you rent and never control.</p>
      </div>
    </div>
  </section>

  <!-- WHO IT'S FOR -->
  <section class="band">
    <div class="band-head">
      <div class="eyebrow">Collaboration by design</div>
      <h2>Your whole team, in one project</h2>
      <p>Developers, PMs and clients work in the same project — direct the build in plain language or drop into the code, review a live preview together, and ship as one. Every paid project includes your whole team, with <strong>no per-seat fees</strong>.</p>
    </div>
    <div class="grid-2">
      <div class="card pcard">
        <div class="ic"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="m8 8-4 4 4 4"/><path d="m16 8 4 4-4 4"/><path d="m13 5-2 14"/></svg></div>
        <h3>For developers</h3>
        <div class="feat" style="margin-top:14px;">
          <div><span class="ck">✓</span> Skip the boilerplate — real, conventional PHP you can read and own</div>
          <div><span class="ck">✓</span> Connect the client's real services, keys scoped per project</div>
          <div><span class="ck">✓</span> Publish to your (or their) GitHub and self-host anytime</div>
        </div>
      </div>
      <div class="card pcard">
        <div class="ic"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M7 9h4"/><path d="M7 13h6"/><path d="m16 16 1.5 1.5L21 14"/></svg></div>
        <h3>For product &amp; project managers</h3>
        <div class="feat" style="margin-top:14px;">
          <div><span class="ck">✓</span> Describe what the client needs in plain language — no code to start</div>
          <div><span class="ck">✓</span> Watch it become a working app you can click, share and demo</div>
          <div><span class="ck">✓</span> Move a project forward without waiting in the engineering queue</div>
          <div><span class="ck">✓</span> Hand the client a real, isolated app with their name on it</div>
        </div>
      </div>
    </div>
  </section>

  <!-- AI DEV TEAM -->
  <section class="band">
    <div class="band-head">
      <div class="eyebrow">Your AI dev team</div>
      <h2>A whole dev team, on tap</h2>
      <p>No contractors to chase, no ticket backlog to groom. Brief the goal and tiknix's agents plan it, build it in parallel, and review each other's work — a planner, builders, and a reviewer you never had to hire. You watch it happen and steer.</p>
    </div>

    <div class="frame" style="max-width:820px; margin:0 auto;">
      <div class="frame-bar">
        <div style="display:flex; align-items:center; gap:8px;">
          <span class="tl" style="background:#f0655b;"></span>
          <span class="tl" style="background:#f2bd4a;"></span>
          <span class="tl" style="background:#43c86a;"></span>
          <span class="url" style="margin-left:12px;">Builder · Acme Co — Booking Portal</span>
        </div>
        <span class="badge-iso" style="color:var(--accent2); border-color:rgba(59,118,240,0.4);"><span class="dot-b" style="width:9px; height:9px;"></span> Building</span>
      </div>
      <div style="padding:20px 22px;">
        <div style="font-size:13px; color:var(--dim);">Goal</div>
        <div style="font-size:16px; font-weight:600; margin-top:3px;">"Add online booking with Stripe deposits and an admin schedule."</div>

        <div class="roles-row" style="margin-top:16px;">
          <span class="role-chip"><span class="dot" style="background:var(--accent2); box-shadow:none;"></span>Planner</span>
          <span class="role-chip"><span class="dot-b" style="width:8px; height:8px;"></span>Builder</span>
          <span class="role-chip"><span class="dot-b" style="width:8px; height:8px;"></span>Builder</span>
          <span class="role-chip"><span class="ring" style="width:10px; height:10px;"></span>Reviewer</span>
        </div>

        <div class="tasklist">
          <div class="task">
            <span class="st"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--good)" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="m8.3 12 2.5 2.6 4.9-5.4"/></svg></span>
            Plan the data model &amp; routes<span class="role">Planner</span>
          </div>
          <div class="task">
            <span class="st"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--good)" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="m8.3 12 2.5 2.6 4.9-5.4"/></svg></span>
            Build the booking form &amp; calendar<span class="role">Builder</span>
          </div>
          <div class="task">
            <span class="st"><span class="dot-b"></span></span>
            Wire Stripe deposit checkout<span class="role">Builder · 62%</span>
          </div>
          <div class="task q">
            <span class="st"><span class="ring"></span></span>
            Add the admin schedule view<span class="role">Queued</span>
          </div>
          <div class="task q">
            <span class="st"><span class="ring"></span></span>
            Review &amp; test the full flow<span class="role">Reviewer</span>
          </div>
        </div>
      </div>
      <div class="frame-foot" style="color:var(--soft); background:rgba(255,255,255,0.02);">
        <span style="color:var(--good);">✓ 2 done</span> · <span style="color:var(--accent2);">1 building</span> · 2 queued — you steer, they ship
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
        <p>Say what your client needs in plain language — no code to start. tiknix scaffolds a real full-stack app: routes, models, admin, all on consistent conventions.</p>
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

  <!-- WHAT YOU BUILD (use cases + sovereignty) -->
  <section class="band">
    <div class="band-head">
      <div class="eyebrow">What you build — and own</div>
      <h2>From Shopify apps to the SaaS you'd rather own</h2>
      <p>Whatever you ship, it's yours — every line, no lock-in. Build the thing your client keeps renting and hand them software they actually control.</p>
    </div>
    <div class="grid-3">
      <div class="card pcard">
        <div class="ic"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/></svg></div>
        <h3 style="font-size:20px;">Shopify apps &amp; storefronts</h3>
        <p>Build the embedded app or store tool you need — orders, inventory, custom checkout — instead of renting one that half-fits. The store's keys stay scoped to that one project.</p>
      </div>
      <div class="card pcard">
        <div class="ic"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0"/></svg></div>
        <h3 style="font-size:20px;">The SaaS you'd rather own</h3>
        <p>Replace the monthly subscription your client keeps paying — CRM, scheduling, dashboards — built once and owned outright. No per-seat fees, no vendor setting the roadmap.</p>
      </div>
      <div class="card pcard">
        <div class="ic"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M9 8h6"/><path d="M9 12h6"/><path d="M9 16h4"/></svg></div>
        <h3 style="font-size:20px;">Client portals &amp; internal tools</h3>
        <p>Member logins, back-office dashboards and admin — each isolated per client and handed off to their own repo. Real software with their name on it, not a locked SaaS seat.</p>
      </div>
    </div>
  </section>

  <!-- FOUNDER STORIES: the people behind the showcase (scripts/seed-showcase.php) -->
  <?php if (!empty($stories)): ?>
  <section class="band" id="stories">
    <div class="band-head">
      <div class="eyebrow">Founder stories</div>
      <h2><?= count($stories) === 1 ? 'A founder' : ucfirst(['', 'one', 'two', 'three', 'four', 'five', 'six'][count($stories)] ?? (string) count($stories)) . ' founders' ?>. One common thread.</h2>
      <p>Different people, different industries, real software shipped &mdash; and every one of them built it on tiknix.</p>
    </div>
    <div class="grid-3">
      <?php foreach ($stories as $st): ?>
        <div class="card fcard">
          <a class="shot" href="/stories#<?= htmlspecialchars($st['slug']) ?>">
            <?php if (!empty($st['image'])): ?><img src="<?= htmlspecialchars($st['image']) ?>" alt="<?= htmlspecialchars($st['title']) ?>, built by <?= htmlspecialchars($st['founder']) ?> with tiknix" loading="lazy"><?php endif; ?>
          </a>
          <div class="body">
            <div>
              <div class="who"><?= htmlspecialchars($st['founder']) ?></div>
              <div class="role"><?= htmlspecialchars($st['role'] ?? '') ?></div>
            </div>
            <?php if (!empty($st['startedWith'])): ?><span class="started">Started with <b><?= htmlspecialchars($st['startedWith']) ?></b></span><?php endif; ?>
            <div class="sum"><?= htmlspecialchars($st['summary']) ?></div>
            <a class="more" href="/stories#<?= htmlspecialchars($st['slug']) ?>">Read <?= htmlspecialchars(strtok($st['founder'], ' ')) ?>&rsquo;s story &rarr;</a>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="card thread">
      <div>
        <div class="eyebrow">The common thread</div>
        <h3 style="margin-top:10px;">They built it on <em>tiknix</em>.</h3>
      </div>
      <div>
        <div class="thread-pts">
          <div><b>An idea, not a spec</b>A business plan, a problem worth solving, a client&rsquo;s catalogue. That was enough to start.</div>
          <div><b>An AI dev team did the heavy lifting</b>They described what they wanted, reviewed each task and approved what shipped.</div>
          <div><b>Real software, running</b>Their own database, their own logins, running live &mdash; not a prototype.</div>
        </div>
        <div style="display:flex; flex-wrap:wrap; gap:12px; margin-top:22px;">
          <a class="btn btn-primary" href="/auth/register" style="padding:11px 20px; font-size:15px;">Start yours free</a>
          <a class="btn btn-ghost" href="/stories" style="padding:11px 20px; font-size:15px;">Read their stories</a>
        </div>
      </div>
    </div>
  </section>
  <?php endif; ?>

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
        <?php foreach ($showcase as $s): if (trim((string) ($s->storyJson ?? '')) !== '') continue; $spath = (string)($s->screenshotPath ?? ''); $ver = (int)($s->capturedAt ?? 0); ?>
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
  <section class="band" id="pricing">
    <div class="band-head">
      <div class="eyebrow">Pricing</div>
      <h2>Priced per project, like your invoices</h2>
      <p>Your first project is free. After that you pay per project, never per seat. <strong>Bring your own model</strong> &mdash; Claude Code or any API key &mdash; and build without credits, tokens or a meter.</p>
    </div>
    <div class="price-grid">
      <div class="card plan">
        <div class="lbl">First project</div>
        <div style="display:flex; align-items:baseline; gap:8px; margin-top:12px;"><span class="amt">Free</span></div>
        <div class="fine">forever, no card to start</div>
        <div class="hr"></div>
        <div class="feat">
          <div><span class="ck">✓</span> One full-stack builder instance</div>
          <div><span class="ck">✓</span> Its own isolated environment &amp; database</div>
          <div><span class="ck">✓</span> Unlimited edits on your own model</div>
          <div><span class="ck">✓</span> Publish to your GitHub</div>
        </div>
      </div>
      <div class="card plan plan-hi">
        <div class="ribbon">SCALE PER CLIENT</div>
        <div class="lbl" style="color:var(--text);">Each additional project</div>
        <div style="display:flex; align-items:baseline; gap:6px; margin-top:12px;"><span class="amt">$49</span><span style="font-size:17px; color:var(--soft);">/mo</span></div>
        <div class="fine">per project · add or remove any time</div>
        <div class="hr"></div>
        <div class="feat">
          <div><span class="ck">✓</span> Everything in the free project</div>
          <div><span class="ck">✓</span> Add one per client — no ceiling</div>
          <div><span class="ck">✓</span> Your whole team, no per-seat fees</div>
          <div><span class="ck">✓</span> Custom domain, or publish to theirs</div>
        </div>
      </div>
    </div>
    <div style="display:flex; flex-direction:column; align-items:center; gap:12px; margin-top:34px;">
      <a class="btn btn-primary" href="/auth/register">Start your first project — free</a>
      <div style="font-size:14px; color:var(--dim);">No card to start. Add a card when you add your second project.</div>
    </div>
  </section>

  <!-- FAQ -->
  <section class="band">
    <h2 style="font-size:clamp(26px,3vw,34px); text-align:center; margin-bottom:44px;">The questions everyone asks first</h2>
    <div class="faq-grid">
      <div class="card qa"><h3>Do I need my own AI model?</h3><p>Yes, and that is the point. Plug in Claude Code or any API key and build as much as you like. We never sell credits, tokens or a meter, so nobody rations your builds, and the model relationship is yours like the app is.</p></div>
      <div class="card qa"><h3>Do I need to write code?</h3><p>No. Start by describing what you want in plain language and refine from there. Developers can drop into the code anytime — project leads never have to.</p></div>
      <div class="card qa"><h3>Can my team work on a project together?</h3><p>Yes — every paid project includes your whole team at <strong>no per-seat cost</strong>. Invite teammates, share a live preview with the client, and review in the same place you build. (The free project is a solo workspace.)</p></div>
      <div class="card qa"><h3>Is the code mine?</h3><p>Completely — every line the AI generates is yours. Publish it to your own GitHub, host it anywhere, and keep it if you ever leave. No lock-in, no proprietary runtime holding it hostage.</p></div>
      <div class="card qa"><h3>Why build it instead of buying SaaS?</h3><p>Because you stop renting. A tool you build here is a one-time asset you own and change on your terms — no per-seat fees, no vendor setting your roadmap or raising the price. We call it <a href="/neosaas">NeoSaaS</a>.</p></div>
      <div class="card qa"><h3>Can I build a Shopify app?</h3><p>Yes — connect a store and build the embedded app, storefront tool or ops dashboard you need. The store's keys stay encrypted and scoped to that one project.</p></div>
      <div class="card qa"><h3>How isolated are projects?</h3><p>Each runs under its own OS user and process with its own data — and can get a dedicated container. No project can read another's.</p></div>
      <div class="card qa"><h3>What's the stack?</h3><p><span class="code">PHP (FlightPHP)</span> + <span class="code">SQLite</span> — conventional and readable, so it stays maintainable long after handoff.</p></div>
      <div class="card qa"><h3>Can my client run it without tiknix?</h3><p>Yes — eject to their repo and host it themselves. tiknix is where you build it, not a place it's trapped.</p></div>
    </div>
  </section>

  <!-- FINAL CTA -->
  <section class="final">
    <div class="eyebrow" style="justify-content:center;">Now's the time</div>
    <h2 style="margin-top:14px;">A great time to get excited about your business again.</h2>
    <p>If you're a product-minded person with a brilliant software idea, tiknix turns it into the real thing — running, custom, and yours to own, every line.</p>
    <div class="hero-cta" style="justify-content:center; margin-top:32px;">
      <a class="btn btn-primary" href="/auth/register">Start your first project — free</a>
      <a class="btn btn-ghost" href="/contact">Reach out — I'd love to show you</a>
    </div>
  </section>

  <?php include __DIR__ . '/_marketing-foot.php'; ?>

</div>
</body>
</html>
