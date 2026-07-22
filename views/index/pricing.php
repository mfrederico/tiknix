<?php $logoV = @filemtime(dirname(__DIR__, 2) . '/public/img/tiknix.svg') ?: '1'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title ?? 'Pricing — Tiknix') ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        :root {
            --bg1:#0b1530; --bg2:#060d20; --glow:rgba(59,118,240,0.20);
            --text:#eaedf5; --text-soft:#9ba4bd; --accent:#3b76f0; --accent-ink:#ffffff;
        }
        html { background: var(--bg2); }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            min-height: 100vh;
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            gap: 2rem;
            background:
                radial-gradient(1100px 520px at 50% -8%, var(--glow), transparent 62%),
                linear-gradient(160deg, var(--bg1) 0%, var(--bg2) 100%);
            color: var(--text); text-align: center;
            padding: clamp(2.5rem, 6vh, 4.5rem) 1.5rem;
        }
        .wrap { max-width: 520px; width: 100%; }
        .logo { display: inline-flex; align-items: center; gap: 0.7rem; margin-bottom: 1.5rem; color: var(--text); text-decoration: none; }
        .logo-mark {
            width: 48px; height: 48px; flex: 0 0 auto; background: currentColor;
            -webkit-mask: url(/img/tiknix.svg?v=<?= $logoV ?>) center / contain no-repeat;
                    mask: url(/img/tiknix.svg?v=<?= $logoV ?>) center / contain no-repeat;
        }
        .logo-word { font-family: 'Playfair Display', Georgia, serif; font-weight: 600; font-size: 2rem; line-height: 1; }
        .badge {
            display: inline-block; padding: 0.4rem 1rem; border: 1px solid rgba(255,255,255,0.4);
            border-radius: 999px; font-size: 0.8rem; letter-spacing: 0.15em; text-transform: uppercase;
            margin-bottom: 1.25rem; opacity: 0.9;
        }
        h1 { font-size: clamp(2rem, 6vw, 2.9rem); font-weight: 800; line-height: 1.12; margin-bottom: 0.75rem; }
        .lede { font-size: 1.05rem; line-height: 1.6; color: var(--text-soft); margin-bottom: 2rem; }
        .price-card {
            padding: 2rem 1.75rem; background: rgba(255,255,255,0.06);
            border: 1px solid rgba(255,255,255,0.15); border-radius: 20px; text-align: left;
        }
        .price-card.highlight { border-color: rgba(59,118,240,0.6); box-shadow: 0 14px 40px rgba(0,0,0,0.35); }
        .plan-name { font-size: 0.85rem; letter-spacing: 0.12em; text-transform: uppercase; color: var(--text-soft); text-align: center; }
        .price { text-align: center; margin: 0.5rem 0 0.25rem; }
        .price .amount { font-size: 3.4rem; font-weight: 800; line-height: 1; }
        .price .period { color: var(--text-soft); font-size: 1rem; }
        .price-sub { text-align: center; color: var(--text-soft); font-size: 0.9rem; margin-bottom: 1.5rem; }
        ul.features { list-style: none; margin: 0 0 0.5rem; padding: 0; }
        ul.features li { display: flex; align-items: flex-start; gap: 0.6rem; padding: 0.5rem 0; font-size: 0.98rem; line-height: 1.4; }
        ul.features li::before { content: "✓"; color: var(--accent); font-weight: 800; flex: 0 0 auto; }
        .form-card { margin-top: 1.75rem; padding: 1.5rem; background: rgba(255,255,255,0.07); border: 1px solid rgba(255,255,255,0.15); border-radius: 16px; text-align: left; }
        .form-card h2 { font-size: 1.1rem; margin-bottom: 1rem; text-align: center; }
        .form-card p.note { text-align: center; color: var(--text-soft); font-size: 0.85rem; margin-top: 0.9rem; margin-bottom: 0; }
        .field-row { display: flex; gap: 0.75rem; margin-bottom: 0.75rem; }
        .field-row input { flex: 1; }
        input {
            width: 100%; padding: 0.85rem 1rem; border: 1px solid rgba(255,255,255,0.3);
            border-radius: 10px; background: rgba(255,255,255,0.1); color: #fff; font-size: 1rem;
        }
        input::placeholder { color: rgba(255,255,255,0.6); }
        input:focus { outline: none; border-color: var(--accent); background: rgba(255,255,255,0.18); }
        button {
            width: 100%; margin-top: 0.5rem; padding: 0.9rem 1rem; border: none; border-radius: 10px;
            background: var(--accent); color: var(--accent-ink); font-size: 1rem; font-weight: 700; cursor: pointer;
            transition: transform 0.1s ease, opacity 0.15s ease;
        }
        button:hover { opacity: 0.92; } button:active { transform: scale(0.98); }
        .thank-you { margin-top: 1.75rem; padding: 1.5rem; background: rgba(255,255,255,0.12); border: 1px solid rgba(255,255,255,0.25); border-radius: 16px; font-size: 1.05rem; }
        .mini-links { margin-top: 1.5rem; font-size: 0.9rem; }
        .mini-links a { color: var(--text-soft); text-decoration: none; border-bottom: 1px solid rgba(255,255,255,0.2); padding-bottom: 1px; }
        .mini-links a:hover { color: var(--text); }
        @media (max-width: 480px) { .field-row { flex-direction: column; gap: 0.75rem; } }
    </style>
</head>
<body>
    <div class="wrap">
        <a class="logo" href="/" aria-label="tiknix home">
            <span class="logo-mark"></span>
            <span class="logo-word">tiknix</span>
        </a>
        <div class="badge">Pricing</div>
        <h1>Simple, honest pricing.</h1>
        <p class="lede">One price, everything included. Build as many apps as you like &mdash; you pay per builder instance.</p>

        <div class="price-card highlight">
            <div class="plan-name">Builder instance</div>
            <div class="price"><span class="amount">$10</span><span class="period"> / month</span></div>
            <div class="price-sub">per builder instance</div>
            <ul class="features">
                <li>Your own isolated instance &mdash; app, database &amp; web server</li>
                <li>AI agent harness with built-in primitives</li>
                <li>Sandboxed execution, safe to tinker</li>
                <li>Build on our servers, deploy to yours</li>
                <li>Unlimited edits while you build</li>
            </ul>
        </div>

        <?php if (!empty($subscribed)): ?>
            <div class="thank-you">
                🎉 You're on the list! We'll be in touch soon.
            </div>
        <?php else: ?>
            <div class="form-card">
                <h2>Join the waitlist</h2>
                <form method="post" action="/index/dolead">
                    <?= csrf_field() ?>
                    <div class="field-row">
                        <input type="text" name="first_name" placeholder="First name" required maxlength="100">
                        <input type="text" name="last_name" placeholder="Last name" required maxlength="100">
                    </div>
                    <input type="email" name="email" placeholder="Email address" required maxlength="255">
                    <button type="submit">Join the waitlist</button>
                </form>
                <p class="note">We're not open for sign-ups yet &mdash; join the list and we'll let you in.</p>
            </div>
        <?php endif; ?>

        <div class="mini-links"><a href="/">&larr; Back to home</a></div>
    </div>
</body>
</html>
