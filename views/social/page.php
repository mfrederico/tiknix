<?php
/**
 * Public social showcase page (server-rendered, cacheable). Self-contained — no app
 * shell. Vars in scope (from Social::renderPage): $page (socialpage bean), $items.
 */
$h        = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
$title    = (string)($page->title ?: ($page->handle ? '@' . ltrim((string)$page->handle, '@') : 'Showcase'));
$handle   = ltrim((string)($page->handle ?? ''), '@');
$igUrl    = (string)($page->externalUrl ?: ($handle !== '' ? 'https://instagram.com/' . $handle : ''));
$synced   = (string)($page->syncedAt ?? '');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $h($title) ?></title>
<style>
  :root { --bg:#0b1220; --panel:#111a2e; --text:#eaeef7; --muted:#9aa6bd; --line:rgba(255,255,255,.08); --accent:#e1306c; }
  * { box-sizing:border-box; margin:0; padding:0; }
  body { background:var(--bg); color:var(--text); font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; -webkit-font-smoothing:antialiased; }
  .wrap { max-width:1040px; margin:0 auto; padding:2.5rem 1.25rem 4rem; }
  header.hero { text-align:center; margin-bottom:2rem; }
  header.hero h1 { font-size:clamp(1.6rem,4vw,2.4rem); font-weight:800; letter-spacing:-.02em; }
  header.hero .handle { color:var(--muted); margin-top:.35rem; }
  header.hero a.ig { display:inline-flex; align-items:center; gap:.4rem; margin-top:1rem; padding:.5rem 1rem; border-radius:999px;
                     background:linear-gradient(135deg,#f09433,#e6683c,#dc2743,#cc2366,#bc1888); color:#fff; text-decoration:none; font-weight:600; font-size:.9rem; }
  .grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(220px,1fr)); gap:.75rem; }
  .card { position:relative; display:block; aspect-ratio:1/1; border-radius:14px; overflow:hidden; background:var(--panel); border:1px solid var(--line); text-decoration:none; color:inherit; }
  .card img { width:100%; height:100%; object-fit:cover; display:block; transition:transform .3s ease; }
  .card:hover img { transform:scale(1.05); }
  .card .badge { position:absolute; top:.5rem; right:.5rem; background:rgba(0,0,0,.55); border-radius:8px; padding:.15rem .4rem; font-size:.8rem; }
  .card .cap { position:absolute; inset:auto 0 0 0; padding:1.6rem .7rem .6rem; font-size:.78rem; line-height:1.35;
               background:linear-gradient(transparent, rgba(0,0,0,.82)); opacity:0; transition:opacity .2s; display:-webkit-box; -webkit-line-clamp:3; -webkit-box-orient:vertical; overflow:hidden; }
  .card:hover .cap { opacity:1; }
  .card .ph { width:100%; height:100%; display:grid; place-items:center; color:var(--muted); font-size:2rem; }
  .empty { text-align:center; color:var(--muted); padding:3rem 1rem; }
  footer { text-align:center; color:var(--muted); font-size:.78rem; margin-top:2.5rem; }
  footer a { color:var(--muted); }
</style>
</head>
<body>
  <div class="wrap">
    <header class="hero">
      <h1><?= $h($title) ?></h1>
      <?php if ($handle !== ''): ?><div class="handle">@<?= $h($handle) ?></div><?php endif; ?>
      <?php if ($igUrl !== ''): ?>
        <a class="ig" href="<?= $h($igUrl) ?>" target="_blank" rel="noopener">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2.2c3.2 0 3.6 0 4.9.07 1.2.06 1.8.25 2.2.42.6.22 1 .48 1.4.9.4.4.7.8.9 1.4.17.4.36 1 .42 2.2.06 1.3.07 1.7.07 4.9s0 3.6-.07 4.9c-.06 1.2-.25 1.8-.42 2.2-.22.6-.48 1-.9 1.4-.4.4-.8.7-1.4.9-.4.17-1 .36-2.2.42-1.3.06-1.7.07-4.9.07s-3.6 0-4.9-.07c-1.2-.06-1.8-.25-2.2-.42a3.8 3.8 0 0 1-1.4-.9 3.8 3.8 0 0 1-.9-1.4c-.17-.4-.36-1-.42-2.2C2.2 15.6 2.2 15.2 2.2 12s0-3.6.07-4.9c.06-1.2.25-1.8.42-2.2.22-.6.48-1 .9-1.4.4-.4.8-.7 1.4-.9.4-.17 1-.36 2.2-.42C8.4 2.2 8.8 2.2 12 2.2Zm0 1.8c-3.1 0-3.5 0-4.7.07-1.1.05-1.7.24-2.1.4-.5.2-.9.44-1.3.84-.4.4-.64.8-.84 1.3-.16.4-.35 1-.4 2.1C2.3 8.5 2.3 8.9 2.3 12s0 3.5.07 4.7c.05 1.1.24 1.7.4 2.1.2.5.44.9.84 1.3.4.4.8.64 1.3.84.4.16 1 .35 2.1.4 1.2.07 1.6.07 4.7.07s3.5 0 4.7-.07c1.1-.05 1.7-.24 2.1-.4.5-.2.9-.44 1.3-.84.4-.4.64-.8.84-1.3.16-.4.35-1 .4-2.1.07-1.2.07-1.6.07-4.7s0-3.5-.07-4.7c-.05-1.1-.24-1.7-.4-2.1a3.5 3.5 0 0 0-.84-1.3 3.5 3.5 0 0 0-1.3-.84c-.4-.16-1-.35-2.1-.4C15.5 4 15.1 4 12 4Zm0 3.06A4.94 4.94 0 1 1 12 17a4.94 4.94 0 0 1 0-9.88Zm0 1.8a3.14 3.14 0 1 0 0 6.28 3.14 3.14 0 0 0 0-6.28Zm5.14-.9a1.15 1.15 0 1 1-2.3 0 1.15 1.15 0 0 1 2.3 0Z"/></svg>
          View on Instagram
        </a>
      <?php endif; ?>
    </header>

    <?php if (empty($items)): ?>
      <div class="empty">No posts to show yet.</div>
    <?php else: ?>
      <div class="grid">
        <?php foreach ($items as $it):
          $src = (string)($it['thumbnail_url'] ?? '') ?: (string)($it['media_url'] ?? '');
          $kind = (string)($it['kind'] ?? 'photo');
          $link = (string)($it['permalink'] ?? '') ?: $igUrl;
        ?>
          <a class="card" href="<?= $h($link) ?>" target="_blank" rel="noopener">
            <?php if ($src !== ''): ?>
              <img loading="lazy" src="<?= $h($src) ?>" alt="<?= $h(mb_substr((string)($it['caption'] ?? ''), 0, 80)) ?>">
            <?php else: ?>
              <div class="ph">◇</div>
            <?php endif; ?>
            <?php if ($kind === 'reel' || $kind === 'video'): ?><span class="badge">▶</span>
            <?php elseif ($kind === 'carousel'): ?><span class="badge">▦</span><?php endif; ?>
            <?php if (!empty($it['caption'])): ?><div class="cap"><?= $h($it['caption']) ?></div><?php endif; ?>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <footer>
      <?php if ($synced !== ''): ?>Updated <?= $h($synced) ?> · <?php endif; ?>
      Powered by <a href="https://tiknix.com" target="_blank" rel="noopener">tiknix</a>
    </footer>
  </div>
</body>
</html>
