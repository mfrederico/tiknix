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
    <script>document.documentElement.setAttribute('data-theme', localStorage.getItem('tk_theme') || 'admin');</script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        /* Palettes. Default (:root) matches the tiknix admin dark navy; the others
           are futuristic alternatives you can preview via the top-right dropdown. */
        :root, [data-theme="admin"] {
            --bg1:#0b1530; --bg2:#060d20; --glow:rgba(59,118,240,0.20);
            --text:#eaedf5; --text-soft:#9ba4bd; --accent:#3b76f0; --accent-ink:#ffffff;
        }
        [data-theme="deep-space"] {
            --bg1:#0a0e1a; --bg2:#131a2e; --glow:rgba(91,124,250,0.22);
            --text:#e9eef7; --text-soft:#98a3bd; --accent:#5b7cfa; --accent-ink:#ffffff;
        }
        [data-theme="twilight"] {
            --bg1:#1a2140; --bg2:#2e2450; --glow:rgba(150,120,220,0.20);
            --text:#e9e6f5; --text-soft:#b6afce; --accent:#8b7bd6; --accent-ink:#ffffff;
        }
        [data-theme="cyber"] {
            --bg1:#0b1220; --bg2:#0e1b24; --glow:rgba(34,211,238,0.16);
            --text:#e6f0f5; --text-soft:#93b0bd; --accent:#22d3ee; --accent-ink:#04222a;
        }
        [data-theme="graphite"] {
            --bg1:#131417; --bg2:#1b1e26; --glow:rgba(124,140,248,0.15);
            --text:#e8eaf0; --text-soft:#a0a6b5; --accent:#7c8cf8; --accent-ink:#0c0f1a;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background:
                radial-gradient(1100px 520px at 50% -8%, var(--glow), transparent 62%),
                linear-gradient(160deg, var(--bg1) 0%, var(--bg2) 100%);
            background-attachment: fixed;
            color: var(--text);
            text-align: center;
            padding: 1.5rem;
            transition: color 0.25s ease;
        }
        .wrap { max-width: 640px; }
        .logo {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.85rem;
            margin-bottom: 2.25rem;
            color: var(--text);   /* drives the mark (via mask) + wordmark */
        }
        /* The mark is a monochrome SVG painted with currentColor via mask, so it
           recolors to the theme (white here) while staying a single source file. */
        .logo-mark {
            width: 80px; height: 80px; flex: 0 0 auto;
            background: currentColor;
            -webkit-mask: url(/img/tiknix.svg?v=<?= $logoV ?>) center / contain no-repeat;
                    mask: url(/img/tiknix.svg?v=<?= $logoV ?>) center / contain no-repeat;
            animation: funnel-breathe 4.5s ease-in-out infinite;
            will-change: transform, filter;
        }
        /* Subtle "ideas flowing" pulse — a slow breath with a soft glow. */
        @keyframes funnel-breathe {
            0%, 100% { transform: scale(1);     filter: drop-shadow(0 0 2px rgba(255,255,255,0.12)); }
            50%      { transform: scale(1.045); filter: drop-shadow(0 0 10px rgba(255,255,255,0.42)); }
        }
        @media (prefers-reduced-motion: reduce) {
            .logo-mark { animation: none; }
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
            color: var(--text-soft);
            margin-bottom: 2.5rem;
        }
        p strong { color: var(--text); font-weight: 600; }
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
        input:focus { outline: none; border-color: var(--accent); background: rgba(255,255,255,0.18); }
        button {
            width: 100%;
            margin-top: 0.5rem;
            padding: 0.9rem 1rem;
            border: none;
            border-radius: 10px;
            background: var(--accent);
            color: var(--accent-ink);
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
        .theme-picker { position: fixed; top: 1rem; right: 1rem; z-index: 20; }
        .theme-picker select {
            background: rgba(255,255,255,0.08);
            color: var(--text);
            border: 1px solid rgba(255,255,255,0.22);
            border-radius: 8px;
            padding: 0.4rem 0.6rem;
            font-size: 0.78rem;
            cursor: pointer;
            backdrop-filter: blur(6px);
        }
        .theme-picker select option { color: #111; }
        @media (max-width: 480px) { .field-row { flex-direction: column; gap: 0.75rem; } }
    </style>
</head>
<body>
    <div class="theme-picker">
        <select id="themeSelect" aria-label="Preview color theme">
            <option value="admin">Admin match</option>
            <option value="deep-space">Deep space</option>
            <option value="twilight">Muted twilight</option>
            <option value="cyber">Cyber teal</option>
            <option value="graphite">Graphite</option>
        </select>
    </div>
    <script>
        (function () {
            var sel = document.getElementById('themeSelect');
            sel.value = localStorage.getItem('tk_theme') || 'admin';
            sel.addEventListener('change', function () {
                document.documentElement.setAttribute('data-theme', sel.value);
                localStorage.setItem('tk_theme', sel.value);
            });
        })();
    </script>
    <div class="wrap">
        <div class="logo" role="img" aria-label="tiknix">
            <span class="logo-mark"></span>
            <span class="logo-word">tiknix</span>
        </div>
        <h1>Tiknix is an AI operating system.</h1>
        <p>Build full working applications with the primitives you need for any project are built right in: a database, a web server, sandboxing, and much, much more. <br /><strong>Build with our system, deploy to yours.</strong></p>
        <div class="chips">
            <span>Database</span>
            <span>Web server</span>
            <span>Sandboxing</span>
            <span>&amp; much more</span>
        </div>
        <!-- <div class="dots"><span></span><span></span><span></span></div> -->

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
