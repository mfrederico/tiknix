<?php
/**
 * sidecar/app — a registered plugin embedded in the tiknix shell (keeps the left-nav).
 * The iframe loads /sidecar/launch/<plugin>, which mints the handoff token and SSO's
 * into the plugin inside the frame. Full-height by default (the app scrolls inside);
 * if the plugin postMessages its content height, we grow to fit.
 *
 * @var string $plugin  registered plugin key
 * @var string $label   plugin display label
 * @var string $origin  the plugin's origin (scheme://host), e.g. https://workbench.tiknix.com
 * @var string $to      a page to open inside the plugin ('' = its landing), already checked by Sidecar::landingPath
 *
 * allow= MUST name the plugin's origin. Without one the allowlist is 'src' — the
 * origin of the src attribute, i.e. tiknix.com — and the browser does not follow the
 * launch redirect into the plugin when computing it. Verified in Chromium: the
 * implicit form leaves the plugin with clipboard-read/write=false; naming the origin
 * (or pointing src straight at the plugin) grants them.
 */
?>
<div class="sidecar-embed">
  <iframe src="/sidecar/launch/<?= htmlspecialchars($plugin) ?><?= ($to ?? '') !== '' ? '?to=' . htmlspecialchars(rawurlencode($to)) : '' ?>"
          title="<?= htmlspecialchars($label) ?>"
          allow="clipboard-read <?= htmlspecialchars($origin) ?>; clipboard-write <?= htmlspecialchars($origin) ?>"
          referrerpolicy="same-origin"></iframe>
</div>

<style>
  /* Edge-to-edge below the sticky topbar; :has() cancels the .ui-content padding where supported. */
  .ui-content:has(.sidecar-embed) { padding: 0 !important; }
  .sidecar-embed { height: calc(100vh - var(--ui-topbar-height, 62px) - var(--ui-projectbar-height, 0px)); background: var(--bs-body-bg); }
  .sidecar-embed iframe { width: 100%; height: 100%; border: 0; display: block; }
  @media (max-width: 991.98px) { .sidecar-embed { height: calc(100vh - var(--ui-topbar-height, 62px) - var(--ui-projectbar-height, 0px)); } }
</style>

<script>
  // Optional dynamic height — a plugin can `parent.postMessage({tiknixHeight: N}, '*')`
  // to make the frame grow to its content instead of filling the viewport.
  //
  // WITH HYSTERESIS, because this is a feedback loop by construction: resizing the frame
  // changes the plugin's viewport, which can add or remove its scrollbar, which changes
  // the content width and therefore its height — so it reports again, and the frame
  // oscillates. It showed up as a scrollbar flashing on and off several times a second.
  // Ignoring small deltas breaks the cycle while still tracking real content changes.
  var lastH = 0;
  window.addEventListener('message', function (e) {
    if (!e || !e.data || typeof e.data.tiknixHeight !== 'number') return;
    var h = Math.max(320, Math.round(e.data.tiknixHeight));
    if (Math.abs(h - lastH) < 48) return;   // scrollbar-sized churn: not a real change
    lastH = h;
    var wrap = document.querySelector('.sidecar-embed');
    if (wrap) wrap.style.height = h + 'px';
  });
</script>
