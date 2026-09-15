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
        <p class="lede">Security is built into how Tiknix works, not bolted on. Every project runs in its own
        isolated environment, kept private to you.</p>

        <div class="grid">
            <div class="card">
                <span class="ic">🧱</span>
                <h3>Your project is yours alone</h3>
                <p>Each project runs in its <strong>own isolated environment</strong>, walled off from every
                other member's. Your code, data, and secrets stay private to your project.</p>
            </div>
            <div class="card">
                <span class="ic">🔒</span>
                <h3>Encrypted connections</h3>
                <p>Traffic to and from Tiknix is protected with <strong>industry-standard encryption</strong>,
                so your data is safe in transit.</p>
            </div>
            <div class="card">
                <span class="ic">🔑</span>
                <h3>Protected credentials</h3>
                <p>Passwords are <strong>never stored in readable form</strong>, and any keys you connect are
                encrypted and kept private to your project.</p>
            </div>
            <div class="card">
                <span class="ic">🤖</span>
                <h3>Contained AI agents</h3>
                <p>When an AI agent works on your project, it's <strong>confined to that project alone</strong> —
                it can't reach anyone else's.</p>
            </div>
            <div class="card">
                <span class="ic">💳</span>
                <h3>Secure payments</h3>
                <p>Payments are handled by <strong>Stripe</strong>. We never store your card details.</p>
            </div>
            <div class="card">
                <span class="ic">✅</span>
                <h3>You stay in control</h3>
                <p>You own your projects and can delete them at any time, and
                <strong>two-factor authentication</strong> is available for extra protection.</p>
            </div>
        </div>

        <div class="honest">
            <p>No system is ever perfectly secure, so we recommend keeping your own backups of anything important
            and using a strong, unique password. If you ever spot something that doesn't look right, let us know
            at <a href="mailto:security@clicksimple.com">security@clicksimple.com</a>.</p>
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
