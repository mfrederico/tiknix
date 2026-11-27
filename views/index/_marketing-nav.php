<?php /* The marketing pages' top nav (front page, agencies, stories, pricing…). Anchors are absolute (/#how) so they work
   from any page; the logo goes home, the done-for-you front page (index/build). The phone and "Book a chat" are
   clicksimple.com's (_reach.php). A page may set $navCta = [label, url] before including this (the agency page
   offers its free self-serve start); the default is "Book a chat". */
$__reach = require __DIR__ . '/_reach.php';
$__cta = $navCta ?? ['Book a chat', $__reach['book']]; ?>
  <!-- NAV -->
  <nav class="nav">
    <a class="logo" href="/">
      <span class="logo-mark" aria-hidden="true"></span>
      <span class="logo-word">tiknix</span>
    </a>
    <div class="nav-links">
      <a class="hide-sm" href="/#how">How it works</a>
      <a class="hide-sm" href="/#examples">What we build</a>
      <a class="hide-xs" href="/#price">Pricing</a>
      <a class="hide-sm" href="/agencies">For agencies</a>
      <a class="hide-sm" href="<?= $__reach['phone_href'] ?>"><?= $__reach['phone'] ?></a>
      <a href="/auth/login">Sign in</a>
      <a class="nav-cta" href="<?= htmlspecialchars($__cta[1]) ?>"<?= str_starts_with($__cta[1], 'http') ? ' rel="noopener"' : '' ?>><?= htmlspecialchars($__cta[0]) ?></a>
    </div>
  </nav>
