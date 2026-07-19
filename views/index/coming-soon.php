<?php $logoV = @filemtime(dirname(__DIR__, 2) . '/public/img/tiknix.svg') ?: '1'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title ?? 'Coming Soon') ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #1e3a8a 0%, #6d28d9 100%);
            color: #fff;
            text-align: center;
            padding: 1.5rem;
        }
        .wrap { max-width: 640px; }
        .logo {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.85rem;
            margin-bottom: 2.25rem;
            color: #fff;          /* drives the mark (via mask) + wordmark */
        }
        /* The mark is a monochrome SVG painted with currentColor via mask, so it
           recolors to the theme (white here) while staying a single source file. */
        .logo-mark {
            width: 80px; height: 80px; flex: 0 0 auto;
            background: currentColor;
            -webkit-mask: url(/img/tiknix.svg?v=<?= $logoV ?>) center / contain no-repeat;
                    mask: url(/img/tiknix.svg?v=<?= $logoV ?>) center / contain no-repeat;
        }
        .logo-word {
            font-family: 'Playfair Display', Georgia, 'Times New Roman', serif;
            font-weight: 600;
            font-size: 3.4rem;
            line-height: 1;
            letter-spacing: 0.005em;
        }
        @media (max-width: 480px) {
            .logo-mark { width: 60px; height: 60px; }
            .logo-word { font-size: 2.6rem; }
        }
        .badge {
            display: inline-block;
            padding: 0.4rem 1rem;
            border: 1px solid rgba(255,255,255,0.4);
            border-radius: 999px;
            font-size: 0.8rem;
            letter-spacing: 0.15em;
            text-transform: uppercase;
            margin-bottom: 1.75rem;
            opacity: 0.9;
        }
        h1 {
            font-size: clamp(2.5rem, 8vw, 4.5rem);
            font-weight: 800;
            line-height: 1.1;
            margin-bottom: 1.25rem;
        }
        p {
            font-size: clamp(1rem, 3vw, 1.25rem);
            line-height: 1.6;
            color: rgba(255,255,255,0.85);
            margin-bottom: 2.5rem;
        }
        p strong { color: #fff; font-weight: 600; }
        .chips {
            display: flex; flex-wrap: wrap; gap: 0.5rem;
            justify-content: center; margin-bottom: 2.5rem;
        }
        .chips span {
            padding: 0.35rem 0.85rem;
            border: 1px solid rgba(255,255,255,0.25);
            border-radius: 999px;
            font-size: 0.85rem;
            background: rgba(255,255,255,0.08);
            opacity: 0.9;
        }
        p { margin-bottom: 1.5rem; }
        .dots { display: flex; gap: 0.5rem; justify-content: center; }
        .dots span {
            width: 10px; height: 10px;
            border-radius: 50%;
            background: #fff;
            opacity: 0.5;
            animation: pulse 1.4s ease-in-out infinite;
        }
        .dots span:nth-child(2) { animation-delay: 0.2s; }
        .dots span:nth-child(3) { animation-delay: 0.4s; }
        @keyframes pulse {
            0%, 100% { opacity: 0.3; transform: scale(0.85); }
            50% { opacity: 1; transform: scale(1.1); }
        }
        form { margin-top: 0.5rem; }
        .field-row { display: flex; gap: 0.75rem; margin-bottom: 0.75rem; }
        .field-row input { flex: 1; }
        input {
            width: 100%;
            padding: 0.85rem 1rem;
            border: 1px solid rgba(255,255,255,0.3);
            border-radius: 10px;
            background: rgba(255,255,255,0.1);
            color: #fff;
            font-size: 1rem;
        }
        input::placeholder { color: rgba(255,255,255,0.6); }
        input:focus { outline: none; border-color: #fff; background: rgba(255,255,255,0.18); }
        button {
            width: 100%;
            margin-top: 0.5rem;
            padding: 0.9rem 1rem;
            border: none;
            border-radius: 10px;
            background: #fff;
            color: #1e3a8a;
            font-size: 1rem;
            font-weight: 700;
            cursor: pointer;
            transition: transform 0.1s ease, opacity 0.15s ease;
        }
        button:hover { opacity: 0.92; }
        button:active { transform: scale(0.98); }
        .form-card {
            margin-top: 2rem;
            padding: 1.5rem;
            background: rgba(255,255,255,0.07);
            border: 1px solid rgba(255,255,255,0.15);
            border-radius: 16px;
            text-align: left;
        }
        .form-card h2 { font-size: 1.15rem; margin-bottom: 1rem; text-align: center; }
        .thank-you {
            margin-top: 2rem;
            padding: 1.5rem;
            background: rgba(255,255,255,0.12);
            border: 1px solid rgba(255,255,255,0.25);
            border-radius: 16px;
            font-size: 1.1rem;
        }
        @media (max-width: 480px) { .field-row { flex-direction: column; gap: 0.75rem; } }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="logo" role="img" aria-label="tiknix">
            <span class="logo-mark"></span>
            <span class="logo-word">tiknix</span>
        </div>
        <div class="badge">Coming Soon</div>
        <h1>Tiknix is an AI operating system.</h1>
        <p>An agent harness with the primitives every project needs built right in: a database, a web server, sandboxing, and much, much more. <strong>Build on our servers, deploy to yours.</strong></p>
        <div class="chips">
            <span>Database</span>
            <span>Web server</span>
            <span>Sandboxing</span>
            <span>&amp; much more</span>
        </div>
        <div class="dots"><span></span><span></span><span></span></div>

        <?php if (!empty($subscribed)): ?>
            <div class="thank-you">
                🎉 Thanks for signing up! We'll be in touch soon.
            </div>
        <?php else: ?>
            <div class="form-card">
                <h2>Be the first to know when we launch</h2>
                <form method="post" action="/index/dolead">
                    <?= csrf_field() ?>
                    <div class="field-row">
                        <input type="text" name="first_name" placeholder="First name" required maxlength="100">
                        <input type="text" name="last_name" placeholder="Last name" required maxlength="100">
                    </div>
                    <input type="email" name="email" placeholder="Email address" required maxlength="255">
                    <button type="submit">Notify Me</button>
                </form>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
