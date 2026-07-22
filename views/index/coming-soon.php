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
        /* Palette — matched to the tiknix admin dark-navy theme. */
        :root {
            --bg1:#0b1530; --bg2:#060d20; --glow:rgba(59,118,240,0.20);
            --text:#eaedf5; --text-soft:#9ba4bd; --accent:#3b76f0; --accent-ink:#ffffff;
        }
        html { background: var(--bg2); }   /* dark base so a tall page never flashes white */
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: flex-start;
            gap: 3rem;
            background:
                radial-gradient(1100px 520px at 50% -8%, var(--glow), transparent 62%),
                linear-gradient(160deg, var(--bg1) 0%, var(--bg2) 100%);
            color: var(--text);
            text-align: center;
            padding: clamp(2.5rem, 6vh, 4.5rem) 1.5rem;
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
        @media (max-width: 480px) { .field-row { flex-direction: column; gap: 0.75rem; } }

        /* ---- "Built with Tiknix" showcase rail ------------------------------ */
        .showcase { width: min(1040px, 94vw); }
        .showcase-h { font-size: clamp(1.4rem, 4vw, 1.8rem); font-weight: 800; margin-bottom: 0.3rem; }
        .showcase-sub { font-size: 0.95rem; color: var(--text-soft); margin-bottom: 1.5rem; }
        .rail {
            display: flex; gap: 1rem; overflow-x: auto;
            scroll-snap-type: x mandatory;
            padding: 0.5rem 0.25rem 0.75rem;
            scrollbar-width: none;
            -webkit-overflow-scrolling: touch;
        }
        .rail::-webkit-scrollbar { display: none; }
        .scard {
            flex: 0 0 min(430px, 84vw);
            scroll-snap-align: center;
            text-decoration: none; color: inherit;
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.12);
            border-radius: 16px; overflow: hidden;
            transition: transform 0.18s ease, border-color 0.18s ease, box-shadow 0.18s ease;
        }
        .scard:hover, .scard:focus-visible {
            transform: translateY(-4px); outline: none;
            border-color: rgba(59,118,240,0.65);
            box-shadow: 0 12px 30px rgba(0,0,0,0.35);
        }
        .frame { position: relative; background: #0b1530; }
        .bar {
            display: flex; gap: 6px; align-items: center;
            padding: 8px 12px; background: rgba(255,255,255,0.06);
            border-bottom: 1px solid rgba(255,255,255,0.08);
        }
        .bar i { width: 10px; height: 10px; border-radius: 50%; background: rgba(255,255,255,0.22); }
        .frame img {
            display: block; width: 100%; height: 250px;
            object-fit: cover; object-position: top center; background: #0b1530;
        }
        .meta { padding: 0.9rem 1.05rem 1.1rem; text-align: left; }
        .stitle { display: block; font-weight: 700; font-size: 1.05rem; }
        .sblurb { display: block; color: var(--text-soft); font-size: 0.9rem; margin: 0.25rem 0 0.55rem; line-height: 1.45; }
        .slink { display: inline-block; color: var(--accent); font-size: 0.85rem; font-weight: 600; }
        .rail-dots { display: flex; gap: 0.5rem; justify-content: center; margin-top: 0.4rem; }
        .rail-dots button {
            width: 8px; height: 8px; padding: 0; border: none; border-radius: 50%;
            background: rgba(255,255,255,0.3); cursor: pointer;
            transition: transform 0.15s ease, background 0.15s ease;
        }
        .rail-dots button.active { background: var(--accent); transform: scale(1.35); }
        /* Desktop: fit all cards across (no scroll / no dots); mobile keeps the swipe rail. */
        @media (min-width: 760px) {
            .rail { overflow-x: visible; justify-content: center; }
            .scard { flex: 1 1 0; min-width: 0; }
            .rail-dots { display: none; }
        }
        .mini-links { margin-top: 1.25rem; font-size: 0.9rem; }
        .mini-links a { color: var(--text-soft); text-decoration: none; border-bottom: 1px solid rgba(255,255,255,0.2); padding-bottom: 1px; }
        .mini-links a:hover { color: var(--text); }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="logo" role="img" aria-label="tiknix">
            <span class="logo-mark"></span>
            <span class="logo-word">tiknix</span>
        </div>
        <h1>Tiknix is an AI operating system.</h1>
        <p>Build full, working applications &mdash; the primitives every project needs are built right in: a database, a web server, sandboxing, and much, much more. <br /><strong>Build with our system, deploy to yours.</strong></p>
        <div class="chips">
            <span>Database</span>
            <span>Web server</span>
            <span>Sandboxing</span>
            <span>&amp; much more</span>
        </div>
    </div>

    <?php if (!empty($showcase)): ?>
    <section class="showcase" aria-label="Built with Tiknix">
        <div class="showcase-h">Built with Tiknix</div>
        <div class="showcase-sub">Real apps people are shipping right now.</div>
        <div class="rail" id="rail">
            <?php foreach ($showcase as $s):
                $spath = (string)($s->screenshotPath ?? '');
                $fsPath = dirname(__DIR__, 2) . '/public' . $spath;
                $ver = @filemtime($fsPath) ?: '1';
            ?>
            <a class="scard" href="<?= htmlspecialchars((string)$s->url) ?>" target="_blank" rel="noopener">
                <div class="frame">
                    <div class="bar"><i></i><i></i><i></i></div>
                    <img src="<?= htmlspecialchars($spath) ?>?v=<?= $ver ?>" alt="<?= htmlspecialchars((string)$s->title) ?> — built with Tiknix" loading="lazy">
                </div>
                <div class="meta">
                    <span class="stitle"><?= htmlspecialchars((string)$s->title) ?></span>
                    <span class="sblurb"><?= htmlspecialchars((string)$s->blurb) ?></span>
                    <span class="slink">View live &#8599;</span>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
        <div class="rail-dots" id="railDots"></div>
    </section>
    <?php endif; ?>

    <div class="wrap">
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
        <div class="mini-links"><a href="/index/pricing">See pricing &rarr;</a></div>
    </div>

    <script>
    (function () {
        var rail = document.getElementById('rail');
        var dotsWrap = document.getElementById('railDots');
        if (!rail || !dotsWrap) return;
        var cards = Array.prototype.slice.call(rail.querySelectorAll('.scard'));
        // Only run the carousel when the rail actually overflows (mobile). On desktop all
        // cards fit, so there's nothing to scroll — hide the dots and skip auto-advance.
        if (cards.length <= 1 || (rail.scrollWidth - rail.clientWidth) < 8) { dotsWrap.style.display = 'none'; return; }

        var idx = 0, timer = null, paused = false;
        cards.forEach(function (_, k) {
            var b = document.createElement('button');
            b.type = 'button';
            b.setAttribute('aria-label', 'Show showcase item ' + (k + 1));
            b.addEventListener('click', function () { go(k, true); });
            dotsWrap.appendChild(b);
        });
        var dots = Array.prototype.slice.call(dotsWrap.children);
        function setActive(k) { dots.forEach(function (d, j) { d.classList.toggle('active', j === k); }); }
        function go(k, user) {
            idx = (k + cards.length) % cards.length;
            var c = cards[idx];
            rail.scrollTo({ left: c.offsetLeft - (rail.clientWidth - c.clientWidth) / 2, behavior: 'smooth' });
            setActive(idx);
            if (user) restart();
        }
        function restart() { if (timer) clearInterval(timer); timer = setInterval(function () { if (!paused) go(idx + 1); }, 4200); }

        var st;
        rail.addEventListener('scroll', function () {
            clearTimeout(st);
            st = setTimeout(function () {
                var center = rail.scrollLeft + rail.clientWidth / 2, best = 0, bestD = Infinity;
                cards.forEach(function (c, k) {
                    var d = Math.abs((c.offsetLeft + c.clientWidth / 2) - center);
                    if (d < bestD) { bestD = d; best = k; }
                });
                idx = best; setActive(idx);
            }, 90);
        }, { passive: true });
        ['mouseenter', 'touchstart', 'focusin'].forEach(function (e) { rail.addEventListener(e, function () { paused = true; }, { passive: true }); });
        ['mouseleave', 'touchend', 'focusout'].forEach(function (e) { rail.addEventListener(e, function () { paused = false; }, { passive: true }); });

        setActive(0); restart();
    })();
    </script>
</body>
</html>
