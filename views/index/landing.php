<?php
/**
 * Primary tiknix.com landing page — marketing surface for the freelance-dev /
 * small-agency ICP. Standalone (no app layout), rendered by Index::index() on the
 * flagship host only; instance clones still get index/coming-soon.
 *
 * Vars: $showcase (array of showcase beans, may be empty)
 */
$logoV = @filemtime(\app\Paths::runtime() . '/public/img/tiknix.svg') ?: '1';
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
    <style>
        /* The hero asks the wizard's first question itself; the answer rides into start.tiknix. */
        .ask{ margin-top:30px; max-width:560px; }
        .ask label{ display:block; font-family:var(--serif); font-size:20px; font-weight:600; margin-bottom:10px; }
        .ask-row{ display:flex; gap:10px; flex-wrap:wrap; }
        .ask-row input{ flex:1 1 260px; min-width:0; padding:14px 16px; border-radius:11px; border:1px solid var(--line2);
                        background:rgba(255,255,255,0.04); color:var(--text); font:inherit; font-size:16px; }
        .ask-row input::placeholder{ color:var(--dim); }
        .ask-row input:focus{ outline:none; border-color:var(--accent2); box-shadow:0 0 0 3px rgba(59,118,240,0.25); }
        .ask-how{ display:inline-block; margin-top:14px; font-size:14px; color:var(--soft); }
        /* A real plan the wizard wrote, cut off — "see what it would write for yours". */
        .peek{ position:relative; max-width:860px; margin:0 auto; border:1px solid var(--line2); border-radius:16px;
               background:linear-gradient(180deg, rgba(255,255,255,0.035), rgba(255,255,255,0.01)); overflow:hidden; }
        .peek-bar{ display:flex; align-items:center; gap:10px; padding:12px 18px; border-bottom:1px solid var(--line);
                   font-family:var(--mono); font-size:12.5px; color:var(--soft); }
        .peek-body{ padding:24px 28px 0; max-height:840px; overflow:hidden; }
        .peek-body h3{ font-size:22px; margin-bottom:16px; }
        .peek-body h4{ font-family:var(--sans); font-size:13px; letter-spacing:0.08em; text-transform:uppercase; color:var(--accent2); margin:22px 0 8px; }
        .peek-body .quote{ border-left:3px solid var(--line2); padding:4px 0 4px 14px; color:var(--text); font-style:italic; }
        .peek-body ul{ margin:0; padding-left:20px; color:var(--soft); } .peek-body li{ margin:4px 0; }
        .peek-body li b{ color:var(--text); font-weight:600; }
        .peek-body table{ width:100%; border-collapse:collapse; font-size:14px; }
        .peek-body td{ padding:7px 10px 7px 0; border-bottom:1px solid var(--line); color:var(--soft); vertical-align:top; }
        .peek-body td code{ font-family:var(--mono); font-size:13px; color:var(--text); }
        .peek-body .lvl{ font-family:var(--mono); font-size:12px; color:var(--dim); white-space:nowrap; }
        .peek-fade{ position:absolute; left:0; right:0; bottom:0; height:230px; display:flex; flex-direction:column; align-items:center;
                    justify-content:flex-end; gap:10px; padding-bottom:30px; text-align:center;
                    background:linear-gradient(180deg, rgba(8,14,32,0) 0%, rgba(8,14,32,0.92) 38%, #080e20 58%); }
        .peek-fade p{ color:var(--soft); font-size:15px; margin:0; }
        @media (max-width: 520px){ .peek-body{ padding:20px 18px 0; max-height:1000px; } .peek-body .lvl{ display:none; } }
    </style>
</head>
<body>

<div class="container">

  <?php include __DIR__ . '/_marketing-nav.php'; ?>

  <!-- HERO -->
  <section class="hero">
    <div>
      <span class="pill"><span class="dot"></span> Code sovereignty</span>
      <h1 style="margin-top:26px;">Describe it. We build it.<br><span style="color:var(--accent2);">You own it.</span></h1>
      <p class="sub">A real app with its own database, sign-in and connections to Stripe, Shopify and more — running in a week. Publish it to your GitHub, host it anywhere, leave anytime.</p>
      <?php /* The wizard's first question, asked right here: the answer goes to start.tiknix.com/start?about=…,
               shown there and kept as the first answer, so the visitor is already one question in. */ ?>
      <form class="ask" action="https://start.tiknix.com/start" method="get">
        <label for="ask-about">What does your business do?</label>
        <div class="ask-row">
          <input id="ask-about" name="about" maxlength="300" required autocomplete="off"
                 placeholder="e.g. We groom dogs from two vans">
          <button class="btn btn-primary" type="submit">Show me my plan</button>
        </div>
        <a class="ask-how" href="#plan">See a plan it wrote &darr;</a>
      </form>
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

  <!-- PLAN PEEK: real output of the Get-started wizard (its PlanRenderer) for a gemstone shop that runs
       classes — rendered from those answers, nothing added; shown half, the rest behind the wizard. -->
  <section class="band" id="plan" style="border-top:none;">
    <div class="band-head">
      <div class="eyebrow">A real plan</div>
      <h2>The plan tiknix wrote for a gemstone shop</h2>
      <p>Six plain questions in, this is what came out &mdash; the shop&rsquo;s problem in its own words, turned into an app to build.</p>
    </div>
    <div class="peek">
      <div class="peek-bar"><span class="tl" style="background:#f0655b;"></span><span class="tl" style="background:#f2bd4a;"></span><span class="tl" style="background:#43c86a;"></span><span style="margin-left:6px;">PLAN.md</span></div>
      <div class="peek-body" aria-hidden="false">
        <h3>A gemstone and crystal shop that runs hands-on classes &mdash; build plan</h3>
        <h4>What&rsquo;s painful right now</h4>
        <div class="quote">Class sign-ups live in a spreadsheet and a paper list; people pay at the door and seats get double-booked.</div>
        <h4>The end goal</h4>
        <div class="quote">Sell seats for classes online, take payment up front, and keep one list of who is coming.</div>
        <h4>People and roles</h4>
        <ul>
          <li><b>Visitor</b> browses and books</li>
          <li><b>Attendee</b> sees their tickets</li>
          <li><b>Door staff / admin</b> checks people in, manages events</li>
        </ul>
        <h4>Pages</h4>
        <table>
          <tr><td><code>/events</code></td><td>Public calendar and catalogue</td><td class="lvl">anyone</td></tr>
          <tr><td><code>/events/view</code></td><td>One event and its booking</td><td class="lvl">anyone</td></tr>
          <tr><td><code>/tickets</code></td><td>My tickets</td><td class="lvl">signed in</td></tr>
          <tr><td><code>/checkin</code></td><td>Door check-in</td><td class="lvl">staff</td></tr>
          <tr><td><code>/admin/events</code></td><td>Manage events and orders</td><td class="lvl">staff</td></tr>
        </table>
        <h4>Phases</h4>
        <ul>
          <li><b>Phase 1</b> &mdash; Foundation: accounts, roles, branding and permission rows</li>
          <li><b>Phase 2</b> &mdash; Branding, event catalogue, public calendar and host pages</li>
          <li><b>Phase 3</b> &mdash; Stripe checkout with seat holds</li>
          <li><b>Phase 4</b> &mdash; QR tickets, emails, door check-in</li>
          <li><b>Phase 5</b> &mdash; Recurring series, reminders, reports</li>
        </ul>
      </div>
      <div class="peek-fade">
        <p>&hellip; and the data model, connections, acceptance checks.</p>
        <a class="btn btn-primary" href="#ask-about" onclick="setTimeout(function(){var i=document.getElementById('ask-about'); if(i) i.focus();},350)">See what it would write for yours &rarr;</a>
      </div>
    </div>
  </section>

  <!-- PILLARS -->
  <section class="band" style="border-top:none; padding-top:0;">
    <div class="grid-3">
      <div class="card pcard">
        <div class="ic"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3 4 6v6c0 5 3.5 7.5 8 9 4.5-1.5 8-4 8-9V6z"/><path d="m9 12 2 2 4-4"/></svg></div>
        <h3>Walled off by default</h3>
        <p>Every project runs in its own container with its own data. One client can never reach another's.</p>
      </div>
      <div class="card pcard">
        <div class="ic"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v14c0 1.7 3.6 3 8 3s8-1.3 8-3V5"/><path d="M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3"/></svg></div>
        <h3>Real backend, real integrations</h3>
        <p>Database, logins, admin, and Stripe, Shopify or QuickBooks — each key locked to its own project.</p>
      </div>
      <div class="card pcard">
        <div class="ic"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="6" cy="6" r="2.5"/><circle cx="6" cy="18" r="2.5"/><circle cx="18" cy="8" r="2.5"/><path d="M6 8.5v7"/><path d="M18 10.5c0 4-6 2-6 5.5"/></svg></div>
        <h3>Own every line — no lock-in</h3>
        <p>Publish to your GitHub, host it anywhere, walk away anytime.</p>
      </div>
    </div>
  </section>

  <!-- WHO IT'S FOR -->
  <section class="band">
    <div class="band-head">
      <div class="eyebrow">Collaboration by design</div>
      <h2>Your whole team, in one project</h2>
      <p>Developers, PMs and clients in one project — build in plain language or in code, and review a live preview together. <strong>No per-seat fees.</strong></p>
    </div>
    <div class="grid-2">
      <div class="card pcard">
        <div class="ic"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="m8 8-4 4 4 4"/><path d="m16 8 4 4-4 4"/><path d="m13 5-2 14"/></svg></div>
        <h3>For developers</h3>
        <div class="feat" style="margin-top:14px;">
          <div><span class="ck">✓</span> Readable, conventional PHP — no boilerplate</div>
          <div><span class="ck">✓</span> The client's own services, keys per project</div>
          <div><span class="ck">✓</span> Publish to GitHub, self-host anytime</div>
        </div>
      </div>
      <div class="card pcard">
        <div class="ic"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M7 9h4"/><path d="M7 13h6"/><path d="m16 16 1.5 1.5L21 14"/></svg></div>
        <h3>For product &amp; project managers</h3>
        <div class="feat" style="margin-top:14px;">
          <div><span class="ck">✓</span> Describe it in plain language</div>
          <div><span class="ck">✓</span> Click, share and demo it as it's built</div>
          <div><span class="ck">✓</span> No waiting on the engineering queue</div>
          <div><span class="ck">✓</span> Hand off a real app with the client's name on it</div>
        </div>
      </div>
    </div>
  </section>

  <!-- AI DEV TEAM -->
  <section class="band">
    <div class="band-head">
      <div class="eyebrow">Your AI dev team</div>
      <h2>A whole dev team, on tap</h2>
      <p>Brief the goal. Agents plan it, build it in parallel and review each other's work — you steer.</p>
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
        <p>Plain language, no code. You get a real full-stack app: data, logins, admin.</p>
      </div>
      <div class="card pcard">
        <div class="step-n">02</div>
        <h3 style="font-size:21px; margin-top:14px;">Build &amp; connect</h3>
        <p>Refine it with AI, then connect the client's Stripe, Shopify or QuickBooks.</p>
      </div>
      <div class="card pcard">
        <div class="step-n">03</div>
        <h3 style="font-size:21px; margin-top:14px;">Publish &amp; hand off</h3>
        <p>Ship to their repo or domain. Cancel the project when you're done.</p>
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
      <p>Build what your client keeps renting — and hand them the keys.</p>
    </div>
    <div class="grid-3">
      <div class="card pcard">
        <div class="ic"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/></svg></div>
        <h3 style="font-size:20px;">Shopify apps &amp; storefronts</h3>
        <p>Orders, inventory, custom checkout — built to fit, not rented.</p>
      </div>
      <div class="card pcard">
        <div class="ic"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0"/></svg></div>
        <h3 style="font-size:20px;">The SaaS you'd rather own</h3>
        <p>CRM, scheduling, dashboards — built once, owned outright.</p>
      </div>
      <div class="card pcard">
        <div class="ic"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M9 8h6"/><path d="M9 12h6"/><path d="M9 16h4"/></svg></div>
        <h3 style="font-size:20px;">Client portals &amp; internal tools</h3>
        <p>Member logins, dashboards and admin — one isolated app per client.</p>
      </div>
    </div>
  </section>

  <!-- FOUNDER STORIES: the people behind the showcase (scripts/seed-showcase.php) -->
  <?php if (!empty($stories)): ?>
  <section class="band" id="stories">
    <div class="band-head">
      <div class="eyebrow">Founder stories</div>
      <h2><?= count($stories) === 1 ? 'A founder' : ucfirst(['', 'one', 'two', 'three', 'four', 'five', 'six'][count($stories)] ?? (string) count($stories)) . ' founders' ?>. One common thread.</h2>
      <p>Different people, different industries &mdash; all shipped on tiknix.</p>
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
          <div><b>An idea, not a spec</b>A plan, a problem, a catalogue &mdash; enough to start.</div>
          <div><b>An AI dev team did the heavy lifting</b>They described it, reviewed each task, approved what shipped.</div>
          <div><b>Real software, running</b>Their own database and logins, live &mdash; not a prototype.</div>
        </div>
        <div style="display:flex; flex-wrap:wrap; gap:12px; margin-top:22px;">
          <a class="btn btn-primary" href="https://start.tiknix.com/start" style="padding:11px 20px; font-size:15px;">Start yours free</a>
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
      <p>First project free. Then per project, never per seat. <strong>Bring your own model</strong> &mdash; no credits, no meter.</p>
    </div>
    <div class="price-grid">
      <div class="card plan">
        <div class="lbl">First project</div>
        <div style="display:flex; align-items:baseline; gap:8px; margin-top:12px;"><span class="amt">Free</span></div>
        <div class="fine">forever, no card to start</div>
        <div class="hr"></div>
        <div class="feat">
          <div><span class="ck">✓</span> One full-stack app</div>
          <div><span class="ck">✓</span> Its own container &amp; database</div>
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
      <a class="btn btn-primary" href="https://start.tiknix.com/start">Start your first project — free</a>
      <div style="font-size:14px; color:var(--dim);">No card to start. Add a card when you add your second project.</div>
    </div>
  </section>

  <!-- FAQ -->
  <section class="band">
    <h2 style="font-size:clamp(26px,3vw,34px); text-align:center; margin-bottom:44px;">The questions everyone asks first</h2>
    <div class="faq-grid">
      <div class="card qa"><h3>Do I need my own AI model?</h3><p>Yes. Plug in Claude Code or any API key and build as much as you like &mdash; we never sell credits or meter your builds.</p></div>
      <div class="card qa"><h3>Do I need to write code?</h3><p>No. Describe it in plain language; developers can drop into the code anytime.</p></div>
      <div class="card qa"><h3>Can my team work on a project together?</h3><p>Yes &mdash; every paid project includes your whole team at <strong>no per-seat cost</strong>. (The free project is solo.)</p></div>
      <div class="card qa"><h3>Is the code mine?</h3><p>Every line. Publish to your GitHub, host it anywhere, keep it if you leave.</p></div>
      <div class="card qa"><h3>Why build it instead of buying SaaS?</h3><p>You stop renting: build it once, own it, change it on your terms. We call it <a href="/neosaas">NeoSaaS</a>.</p></div>
      <div class="card qa"><h3>Can I build a Shopify app?</h3><p>Yes &mdash; embedded apps, storefront tools, ops dashboards. The store's keys stay locked to that project.</p></div>
      <div class="card qa"><h3>How isolated are projects?</h3><p>Each project runs in its own container with its own data. No project can read another's.</p></div>
      <div class="card qa"><h3>What's the stack?</h3><p><span class="code">PHP (FlightPHP)</span> + <span class="code">SQLite</span> &mdash; conventional and readable, easy to maintain after handoff.</p></div>
      <div class="card qa"><h3>Can my client run it without tiknix?</h3><p>Yes &mdash; publish to their repo and they host it themselves.</p></div>
    </div>
  </section>

  <!-- FINAL CTA -->
  <section class="final">
    <div class="eyebrow" style="justify-content:center;">Now's the time</div>
    <h2 style="margin-top:14px;">Bring the idea. Leave with real software.</h2>
    <p>A running, custom app &mdash; yours to own, every line.</p>
    <div class="hero-cta" style="justify-content:center; margin-top:32px;">
      <a class="btn btn-primary" href="https://start.tiknix.com/start">Start your first project — free</a>
      <a class="btn btn-ghost" href="/contact">Reach out — I'd love to show you</a>
    </div>
  </section>

  <?php include __DIR__ . '/_marketing-foot.php'; ?>

</div>
</body>
</html>
