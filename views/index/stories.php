<?php
/**
 * Founder stories — the long form of the landing's founder section. Standalone like the
 * landing (no app layout), rendered by Index::stories() on the flagship host only.
 *
 * Vars: $stories (Model_Showcase::stories() — founder, role, headline, startedWith,
 *       summary, body[], built[], stats[{v,k}], image, slug, title, url)
 */
$logoV = @filemtime(dirname(__DIR__, 2) . '/public/img/tiknix.svg') ?: '1';
$names = array_map(fn($s) => $s['founder'], $stories);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title) ?></title>
    <meta name="description" content="How <?= htmlspecialchars(implode(', ', $names)) ?> built real software with tiknix — from a business plan, an idea or a client's catalogue to apps running live.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;600;700&display=swap" rel="stylesheet">
    <?php include __DIR__ . '/_marketing-style.php'; ?>
</head>
<body>

<div class="container">

  <?php include __DIR__ . '/_marketing-nav.php'; ?>

  <!-- HERO -->
  <section style="text-align:center; padding:48px 0 64px;">
    <div class="eyebrow">Founder stories</div>
    <h1 style="font-size:clamp(34px,4.6vw,56px); margin:16px auto 0; max-width:860px; text-wrap:balance;">
      They built real software. The common thread is <span style="color:var(--accent2);">tiknix</span>.
    </h1>
    <p style="font-size:clamp(16px,1.6vw,19px); color:var(--soft); line-height:1.6; max-width:640px; margin:22px auto 0;">
      A gemstone business, a collector&rsquo;s AI and a parts platform. Different people and different
      industries, each starting from something small and ending with software that runs.
    </p>
    <div style="display:flex; flex-wrap:wrap; justify-content:center; gap:10px; margin-top:30px;">
      <?php foreach ($stories as $st): ?>
        <a class="chip" href="#<?= htmlspecialchars($st['slug']) ?>"><?= htmlspecialchars($st['founder']) ?> &middot; <?= htmlspecialchars($st['title']) ?></a>
      <?php endforeach; ?>
    </div>
  </section>

  <?php foreach ($stories as $i => $st): ?>
  <article class="chapter" id="<?= htmlspecialchars($st['slug']) ?>">
    <div>
      <?php if (!empty($st['image'])): ?>
        <a class="pic" href="<?= htmlspecialchars($st['url']) ?>" target="_blank" rel="noopener" style="display:block;">
          <img src="<?= htmlspecialchars($st['image']) ?>" alt="<?= htmlspecialchars($st['title']) ?>, built by <?= htmlspecialchars($st['founder']) ?> with tiknix" loading="<?= $i === 0 ? 'eager' : 'lazy' ?>">
        </a>
      <?php endif; ?>
      <?php if (!empty($st['stats'])): ?>
        <div class="stats3">
          <?php foreach ($st['stats'] as $stat): ?>
            <div class="stat"><div class="v"><?= htmlspecialchars($stat['v']) ?></div><div class="k"><?= htmlspecialchars($stat['k']) ?></div></div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
    <div>
      <div class="eyebrow"><?= htmlspecialchars($st['title']) ?></div>
      <h2><?= htmlspecialchars($st['headline']) ?></h2>
      <div class="by"><b><?= htmlspecialchars($st['founder']) ?></b><?php if (!empty($st['role'])): ?> &middot; <?= htmlspecialchars($st['role']) ?><?php endif; ?></div>
      <?php if (!empty($st['startedWith'])): ?><div style="margin-top:14px;"><span class="started">Started with <b><?= htmlspecialchars($st['startedWith']) ?></b></span></div><?php endif; ?>
      <?php if (!empty($st['quote'])): ?>
        <blockquote style="margin-top:22px; padding-left:18px; border-left:3px solid var(--accent); font-family:var(--serif); font-size:20px; line-height:1.5;">&ldquo;<?= htmlspecialchars($st['quote']) ?>&rdquo;</blockquote>
      <?php endif; ?>
      <?php foreach ($st['body'] as $para): ?>
        <p class="lead"><?= htmlspecialchars($para) ?></p>
      <?php endforeach; ?>
      <?php if (!empty($st['built'])): ?>
        <div class="eyebrow" style="margin-top:28px;">What got built</div>
        <div class="built">
          <?php foreach ($st['built'] as $b): ?>
            <div><span class="ck">&#10003;</span><?= htmlspecialchars($b) ?></div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <a class="btn btn-ghost" href="<?= htmlspecialchars($st['url']) ?>" target="_blank" rel="noopener" style="margin-top:28px; padding:11px 20px; font-size:15px;">Visit <?= htmlspecialchars($st['title']) ?> &rarr;</a>
    </div>
  </article>
  <?php endforeach; ?>

  <!-- THE COMMON THREAD -->
  <section class="final" style="border-top:1px solid var(--line);">
    <div class="eyebrow">The common thread</div>
    <h2 style="margin-top:14px;">Start with what you have. Ship real software.</h2>
    <p>A plan, an idea or a client&rsquo;s catalogue is enough. Your first project is free.</p>
    <div style="display:flex; flex-wrap:wrap; justify-content:center; gap:14px; margin-top:30px;">
      <a class="btn btn-primary" href="/auth/register">Start yours free</a>
      <a class="btn btn-ghost" href="/pricing">See pricing</a>
    </div>
  </section>

  <?php include __DIR__ . '/_marketing-foot.php'; ?>

</div>
</body>
</html>
