<?php
/**
 * Security card — Cloudflare Turnstile (human verification on the sign-up form).
 *
 * A per-install security connection: the keys stored here gate THIS site's own
 * registration. Included by both connection pages (the control-plane hub and an
 * instance's own page); it always manages the CURRENT install's keys.
 *
 * Expects: $turnstile = ['configured'=>bool, 'source'=>'connection'|'config'|'none', 'site_masked'=>string]
 */
$ts        = $turnstile ?? ['configured' => false, 'source' => 'none', 'site_masked' => ''];
$tsOn      = !empty($ts['configured']);
$tsSource  = (string) ($ts['source'] ?? 'none');
$tsMasked  = (string) ($ts['site_masked'] ?? '');
$tsCsrf    = function_exists('csrf_token') ? csrf_token() : '';
?>
<h2 class="h6 text-uppercase text-body-secondary fw-semibold mb-2 mt-4" style="letter-spacing:.06em">Security</h2>
<div class="card shadow-sm mb-2" id="ts-card">
  <div class="card-body">
    <div class="d-flex align-items-start gap-3">
      <div class="rounded-circle bg-primary-subtle d-flex align-items-center justify-content-center flex-shrink-0" style="width:44px;height:44px">
        <i class="bi bi-shield-check fs-5 text-primary"></i>
      </div>
      <div class="flex-grow-1">
        <div class="d-flex align-items-center gap-2 flex-wrap">
          <span class="fw-semibold">Human verification</span>
          <?php if (!empty($ts['broken'])): ?>
            <span class="badge text-bg-danger">Stored key unreadable — every verification is REFUSED</span>
          <?php elseif ($tsOn && $tsSource === 'connection'): ?>
            <span class="badge text-bg-success">On</span>
          <?php elseif ($tsOn && $tsSource === 'config'): ?>
            <span class="badge text-bg-warning">On — from config seed</span>
          <?php else: ?>
            <span class="badge text-bg-secondary">Off</span>
          <?php endif; ?>
        </div>
        <div class="text-body-secondary small mt-1">
          Cloudflare Turnstile challenges the sign-up form so bots can't create accounts.
          Keys are stored here, encrypted with this install's own key.
        </div>
        <?php if (!empty($ts['broken'])): ?>
          <div class="alert alert-danger small mt-2 mb-0">
            The secret stored here cannot be decrypted with this install's current <code>[security] app_key</code>
            (rotated?). Forms that use human verification are refusing every submission until you save the keys again below.
          </div>
        <?php endif; ?>

        <?php if ($tsOn && $tsSource === 'connection'): ?>
          <div class="small mt-2">Site key <code><?= htmlspecialchars($tsMasked) ?></code> — verified against Cloudflare.</div>
        <?php elseif ($tsOn && $tsSource === 'config'): ?>
          <div class="alert alert-warning py-2 px-3 small mt-2 mb-0">
            Turnstile is currently enabled from <code>conf/config.ini</code>. Save the keys below to manage them here
            (encrypted, no config edit) instead.
          </div>
        <?php endif; ?>

        <details class="mt-3"<?= $tsOn ? '' : ' open' ?>>
          <summary class="small text-secondary" style="cursor:pointer">
            <?= $tsOn && $tsSource === 'connection' ? 'Replace keys' : 'Add your Turnstile keys' ?>
          </summary>
          <form id="ts-form" class="row g-2 align-items-end mt-1">
            <?= csrf_field() ?>
            <div class="col-12">
              <label class="form-label small mb-1">Site key <span class="text-secondary">(public)</span></label>
              <input type="text" name="site_key" class="form-control form-control-sm" placeholder="0x4AAAAAAA…" autocomplete="off" spellcheck="false" required>
            </div>
            <div class="col-12">
              <label class="form-label small mb-1">Secret key <span class="text-secondary">(stored encrypted)</span></label>
              <input type="password" name="secret_key" class="form-control form-control-sm" placeholder="0x4AAAAAAA…" autocomplete="off" required>
            </div>
            <div class="col-12 d-flex align-items-center gap-2 mt-2">
              <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-shield-lock me-1"></i>Save &amp; verify</button>
              <?php if ($tsOn && $tsSource === 'connection'): ?>
                <button type="button" id="ts-forget" class="btn btn-sm btn-outline-danger"><i class="bi bi-x-lg me-1"></i>Disable</button>
              <?php endif; ?>
              <a href="https://dash.cloudflare.com/?to=/:account/turnstile" target="_blank" rel="noopener" class="small text-decoration-none ms-auto">
                Get keys <i class="bi bi-box-arrow-up-right"></i>
              </a>
            </div>
            <div class="col-12"><div id="ts-msg" class="small mt-1"></div></div>
          </form>
        </details>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  var csrf = <?= json_encode($tsCsrf) ?>;
  var form = document.getElementById('ts-form');
  var msg  = document.getElementById('ts-msg');
  function post(url, body, btn){
    if (btn) btn.disabled = true;
    return fetch(url, {
      method: 'POST',
      headers: {'Content-Type':'application/x-www-form-urlencoded','X-CSRF-TOKEN':csrf,'X-Requested-With':'XMLHttpRequest'},
      body: new URLSearchParams(body).toString()
    }).then(function(r){ return r.json(); }).finally(function(){ if (btn) btn.disabled = false; });
  }
  if (form) form.addEventListener('submit', function(e){
    e.preventDefault();
    var btn = form.querySelector('button[type=submit]');
    if (msg) { msg.className = 'small mt-1 text-body-secondary'; msg.textContent = 'Verifying with Cloudflare…'; }
    var data = new URLSearchParams(new FormData(form));
    data.set('csrf_token', csrf);
    post('/connections/turnstilesave', data, btn).then(function(j){
      if (j && j.success) { location.reload(); }
      else if (msg) { msg.className = 'small mt-1 text-danger'; msg.textContent = (j && j.message) || 'Could not save the keys.'; }
    }).catch(function(){ if (msg) { msg.className = 'small mt-1 text-danger'; msg.textContent = 'Could not save the keys.'; } });
  });
  var forget = document.getElementById('ts-forget');
  if (forget) forget.addEventListener('click', function(){
    if (!confirm('Turn off human verification for this site? The sign-up form will no longer challenge bots.')) return;
    post('/connections/turnstileforget', {csrf_token: csrf}, forget).then(function(j){
      if (j && j.success) { location.reload(); }
      else alert((j && j.message) || 'Could not disable.');
    }).catch(function(){ alert('Could not disable.'); });
  });
})();
</script>
