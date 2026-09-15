<?php
/*
 * Security overview — public trust/marketing page. Rendered by Index::security() at
 * /index/security; index::* = 101 (public). Self-contained (own <head>/CSS), matching
 * pricing.php's marketing style.
 *
 * Every claim here must stay TRUE. As of 2026-09-15 all of these are live: per-instance
 * OS isolation (uid + open_basedir, cross-tenant reads proven blocked), bwrap-jailed build
 * agents with an egress firewall, salted password hashes, encrypted integration keys,
 * Stripe (no card numbers stored), TOTP 2FA for admins. If any of that changes, change
 * this page.
 */
$logoV = @filemtime(dirname(__DIR__, 2) . '/public/img/tiknix.svg') ?: '1';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title ?? 'Security — Tiknix') ?></title>
    <meta name="description" content="How Tiknix protects your projects: per-project isolation, encryption, and least-privilege access.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;600;700&display=swap" rel="stylesheet">
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        :root{--bg1:#0b1530;--bg2:#060d20;--glow:rgba(59,118,240,0.20);--text:#eaedf5;--text-soft:#9ba4bd;--accent:#3b76f0;--line:rgba(255,255,255,0.15);--card:rgba(255,255,255,0.06)}
        html{background:var(--bg2)}
        body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:var(--text);
            background:radial-gradient(1100px 520px at 50% -8%,var(--glow),transparent 62%),linear-gradient(160deg,var(--bg1) 0%,var(--bg2) 100%);min-height:100vh}
        .wrap{max-width:980px;margin:0 auto;padding:clamp(2.5rem,6vh,4rem) 1.5rem}
        .logo{display:inline-flex;align-items:center;gap:.7rem;margin-bottom:1.75rem;color:var(--text);text-decoration:none}
        .logo-mark{width:44px;height:44px;flex:0 0 auto;background:currentColor;-webkit-mask:url(/img/tiknix.svg?v=<?= $logoV ?>) center/contain no-repeat;mask:url(/img/tiknix.svg?v=<?= $logoV ?>) center/contain no-repeat}
        .logo-word{font-family:'Playfair Display',Georgia,serif;font-weight:600;font-size:1.9rem;line-height:1}
        .badge{display:inline-block;padding:.4rem 1rem;border:1px solid rgba(255,255,255,.4);border-radius:999px;font-size:.78rem;letter-spacing:.15em;text-transform:uppercase;margin-bottom:1.1rem;opacity:.9}
        h1{font-size:clamp(2rem,6vw,3rem);font-weight:800;line-height:1.12;margin-bottom:.75rem}
        .lede{font-size:1.08rem;line-height:1.6;color:var(--text-soft);max-width:640px;margin-bottom:2.5rem}
        .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:1.1rem;margin-bottom:2.5rem}
        .card{padding:1.5rem 1.5rem 1.6rem;background:var(--card);border:1px solid var(--line);border-radius:18px}
        .card .ic{font-size:1.4rem;line-height:1;margin-bottom:.7rem;display:inline-block}
        .card h3{font-size:1.08rem;font-weight:700;margin-bottom:.5rem;letter-spacing:-.01em}
        .card p{font-size:.95rem;line-height:1.55;color:var(--text-soft)}
        .card p strong{color:var(--text)}
        .honest{background:rgba(255,255,255,.05);border:1px solid var(--line);border-radius:18px;padding:1.6rem 1.75rem;margin-bottom:2rem}
        .honest h2{font-size:1.15rem;margin-bottom:.6rem}
        .honest p{color:var(--text-soft);line-height:1.6;font-size:.97rem;margin-bottom:.6rem}
        .honest a{color:var(--accent)}
        .cta{display:flex;gap:.9rem;flex-wrap:wrap;align-items:center;margin-bottom:2rem}
        .btn{display:inline-block;padding:.85rem 1.5rem;border-radius:10px;font-weight:700;text-decoration:none;font-size:1rem}
        .btn.primary{background:var(--accent);color:#fff}
        .btn.ghost{border:1px solid var(--line);color:var(--text)}
        .mini-links{font-size:.9rem;color:var(--text-soft)}
        .mini-links a{color:var(--text-soft);text-decoration:none;border-bottom:1px solid rgba(255,255,255,.2);margin-right:1.25rem}
        .mini-links a:hover{color:var(--text)}
    </style>
</head>
<body>
    <div class="wrap">
        <a class="logo" href="/" aria-label="tiknix home"><span class="logo-mark"></span><span class="logo-word">tiknix</span></a>
        <div class="badge">Security</div>
        <h1>How we protect your projects</h1>
        <p class="lede">Every project you build on Tiknix runs in its own walled-off environment,
        separated from every other member's at the operating-system level. Here's what that means in practice.</p>

        <div class="grid">
            <div class="card">
                <span class="ic">🧱</span>
                <h3>Per-project isolation</h3>
                <p>Each project runs as its <strong>own operating-system user</strong> in its own sandbox, with
                a hard boundary (<code>open_basedir</code>) around its files. One member's project
                <strong>cannot read another's</strong> code, database, or secrets — enforced by the OS, not just
                by application rules.</p>
            </div>
            <div class="card">
                <span class="ic">🔒</span>
                <h3>Encrypted in transit</h3>
                <p>All traffic to and from Tiknix uses <strong>HTTPS</strong>. Session cookies are marked Secure
                and HttpOnly, so they never travel in the clear and can't be read by page scripts.</p>
            </div>
            <div class="card">
                <span class="ic">🔑</span>
                <h3>Credentials &amp; secrets</h3>
                <p>Passwords are stored only as <strong>salted hashes</strong> — never in plain text. Integration
                keys you connect (APIs, providers) are <strong>encrypted at rest</strong> and scoped to your
                project.</p>
            </div>
            <div class="card">
                <span class="ic">🤖</span>
                <h3>Sandboxed build agents</h3>
                <p>When an AI agent builds in your project, it runs inside a <strong>locked-down jail</strong> —
                no access to the host, your home directory, or other projects, and its network egress is
                firewalled. It can touch <em>your</em> project and nothing else.</p>
            </div>
            <div class="card">
                <span class="ic">💳</span>
                <h3>Payments handled by Stripe</h3>
                <p>Billing runs through <strong>Stripe</strong>. Your full card number never touches — and is never
                stored on — our servers; we keep only a billing reference and your plan status.</p>
            </div>
            <div class="card">
                <span class="ic">🗑️</span>
                <h3>Your data, your control</h3>
                <p>You own your projects. Delete one and it's <strong>archived briefly for recovery, then
                permanently removed</strong>. Admin accounts can require <strong>two-factor authentication</strong>.</p>
            </div>
        </div>

        <div class="honest">
            <h2>Honest about the limits</h2>
            <p>No platform is perfectly secure, and we won't pretend otherwise. Isolation and least-privilege
            access are built into how Tiknix works, but you should still keep your own backups of anything
            important and use a strong, unique password.</p>
            <p>For the strongest separation, published/production projects can run in their own dedicated
            container. Found something that looks wrong? Tell us at
            <a href="mailto:security@clicksimple.com">security@clicksimple.com</a> — we take it seriously.</p>
        </div>

        <div class="cta">
            <a class="btn primary" href="/auth/register">Start free</a>
            <a class="btn ghost" href="/index/pricing">See pricing</a>
        </div>
        <div class="mini-links">
            <a href="/">&larr; Home</a>
            <a href="/privacy">Privacy</a>
            <a href="/terms">Terms</a>
        </div>
    </div>
</body>
</html>
