<?php
/**
 * sidecar/app — a registered plugin embedded in the tiknix shell (keeps the left-nav).
 * The iframe loads /sidecar/launch/<plugin>, which mints the handoff token and SSO's
 * into the plugin inside the frame. Full-height by default (the app scrolls inside);
 * if the plugin postMessages its content height, we grow to fit.
 *
 * @var string $plugin  registered plugin key
 * @var string $label   plugin display label
 */
?>
<div class="sidecar-embed">
  <iframe src="/sidecar/launch/<?= htmlspecialchars($plugin) ?>"
          title="<?= htmlspecialchars($label) ?>"
          allow="clipboard-read; clipboard-write"
          referrerpolicy="same-origin"></iframe>
</div>

<style>
  /* Edge-to-edge below the sticky topbar; :has() cancels the .ui-content padding where supported. */
  .ui-content:has(.sidecar-embed) { padding: 0 !important; }
  .sidecar-embed { height: calc(100vh - var(--ui-topbar-height, 62px)); background: var(--bs-body-bg); }
  .sidecar-embed iframe { width: 100%; height: 100%; border: 0; display: block; }
  @media (max-width: 991.98px) { .sidecar-embed { height: calc(100vh - var(--ui-topbar-height, 62px)); } }
</style>

<script>
  // Optional dynamic height — a plugin can `parent.postMessage({tiknixHeight: N}, '*')`
  // to make the frame grow to its content instead of filling the viewport.
  window.addEventListener('message', function (e) {
    if (e && e.data && typeof e.data.tiknixHeight === 'number') {
      var wrap = document.querySelector('.sidecar-embed');
      if (wrap) wrap.style.height = Math.max(320, e.data.tiknixHeight) + 'px';
    }
  });
</script>
