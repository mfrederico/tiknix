<?php
/**
 * The composer, laid over the form's three message fields after it renders: the HTML in the runtime's code editor
 * (components/php-editor: CodeMirror, highlights HTML) with a live preview beside it, a "Build text version" button
 * that asks this app's agent for the plain-text version (/messaging/derive), and a "Build text message" button that
 * asks it for a text message of at most 144 characters — each result lands in its own field and is yours to edit.
 * Vars: $form (body_email), $templates (optional: EmailTemplates::all() on the new-message page).
 */
$templates = $templates ?? [];
$editorId = 'msgHtml'; $editorCode = (string) ($form['body_email'] ?? ''); $editorHeight = '440px'; $editorReadonly = false;
?>
<div id="msgComposerParts" hidden>
  <div id="msgEditorRow" class="row g-3 mb-2">
    <div class="col-lg-6">
      <?php if ($templates): ?>
      <div class="d-flex gap-2 align-items-center mb-2 flex-wrap">
        <label class="small fw-semibold mb-0" for="msgTemplate">Start from</label>
        <select id="msgTemplate" class="form-select form-select-sm" style="max-width:16rem">
          <option value="">— a template —</option>
          <?php foreach ($templates as $t): ?><option value="<?= htmlspecialchars($t['id']) ?>" title="<?= htmlspecialchars($t['blurb']) ?>"><?= htmlspecialchars($t['name']) ?></option><?php endforeach; ?>
        </select>
        <button type="button" class="btn btn-sm btn-outline-primary" id="msgUseTemplate">Use it</button>
        <button type="button" class="btn btn-sm btn-link" id="msgUndoTemplate" hidden>Undo</button>
      </div>
      <?php endif; ?>
      <div class="fld-label small fw-semibold mb-1">HTML <span class="text-body-secondary fw-normal">— the email as sent; {first_name|there}, {unsubscribe_url}, {prefs_url} stay as written</span></div>
      <?php include \Flight::view()->getTemplate('components/php-editor'); ?>
    </div>
    <div class="col-lg-6">
      <div class="fld-label small fw-semibold mb-1">As it looks <span class="text-body-secondary fw-normal">— variables unfilled; the test fills yours</span></div>
      <iframe id="msgPreview" sandbox title="Preview" style="width:100%;height:440px;border:1px solid var(--bs-border-color);border-radius:.5rem;background:#fff"></iframe>
    </div>
  </div>
  <div id="msgTextTools" class="d-flex gap-2 align-items-center flex-wrap mb-1">
    <button type="button" class="btn btn-sm btn-outline-primary" data-derive="text"><i class="bi bi-magic"></i> Build text version</button>
    <span class="small text-body-secondary" data-derive-status="text">From the HTML, by this app's agent. Then edit it as you like.</span>
  </div>
  <div id="msgSmsTools" class="d-flex gap-2 align-items-center flex-wrap mb-1">
    <button type="button" class="btn btn-sm btn-outline-primary" data-derive="sms"><i class="bi bi-magic"></i> Build text message</button>
    <span class="small text-body-secondary" data-derive-status="sms">At most 144 characters, from the text version. <span data-sms-count></span></span>
  </div>
</div>
<script>
(function () {
  var TEMPLATES = <?= json_encode(array_column($templates, 'html', 'id'), JSON_UNESCAPED_SLASHES) ?>;
  var csrf = <?= json_encode(csrf_token()) ?>;
  var html = document.querySelector('textarea[name="body_email"]'), text = document.querySelector('textarea[name="body_text"]'), sms = document.querySelector('textarea[name="body_sms"]');
  if (!html) return;
  var box = function (el) { return el.closest('.col-md-6, .col-12, .col-6, .ui-form-field, .mb-3') || el.parentElement; };
  // the editor row takes the HTML field's place; the field itself stays, hidden, and carries the editor's text on submit
  // the editor component keeps its own hidden textarea named "code", which must not be submitted with this form (the message's code field is)
  var own = document.getElementById('msgHtml-textarea'); if (own) own.removeAttribute('name');
  var htmlBox = box(html); html.hidden = true; var htmlLabel = htmlBox.querySelector('label'); if (htmlLabel) htmlLabel.hidden = true;
  htmlBox.insertBefore(document.getElementById('msgEditorRow'), html);
  if (text) box(text).insertBefore(document.getElementById('msgTextTools'), text);
  if (sms) box(sms).insertBefore(document.getElementById('msgSmsTools'), sms);
  document.getElementById('msgComposerParts').hidden = false;
  var preview = document.getElementById('msgPreview'), last = null;
  function current() { return window.getEditorCode ? window.getEditorCode('msgHtml') : html.value; }
  function sync() { var v = current(); if (v === last) return; last = v; html.value = v; preview.srcdoc = v; }
  setInterval(sync, 400); sync();
  html.form.addEventListener('submit', sync);
  // templates (the new-message page): replace the HTML, with one undo
  var sel = document.getElementById('msgTemplate'), use = document.getElementById('msgUseTemplate'), undo = document.getElementById('msgUndoTemplate'), before = null;
  if (use) use.addEventListener('click', function () { var id = sel.value; if (!id || !TEMPLATES[id]) return; before = current(); window.setEditorCode('msgHtml', TEMPLATES[id]); undo.hidden = false; sync(); });
  if (undo) undo.addEventListener('click', function () { if (before === null) return; window.setEditorCode('msgHtml', before); before = null; undo.hidden = true; sync(); });
  // the two agent buttons
  function count() { var n = sms ? sms.value.length : 0; var el = document.querySelector('[data-sms-count]'); if (el) { el.textContent = n ? '(' + n + ' of 144)' : ''; el.classList.toggle('text-danger', n > 144); } }
  if (sms) { sms.addEventListener('input', count); count(); }
  document.querySelectorAll('[data-derive]').forEach(function (b) {
    b.addEventListener('click', async function () {
      var what = b.getAttribute('data-derive'), status = document.querySelector('[data-derive-status="' + what + '"]'), target = what === 'text' ? text : sms;
      sync();
      b.disabled = true; status.textContent = 'Asking the agent…'; status.classList.remove('text-danger');
      try {
        var r = await fetch('/messaging/derive', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
          body: new URLSearchParams({ csrf_token: csrf, what: what, html: current(), text: text ? text.value : '' }).toString() });
        var j = await r.json();
        if (!j.ok) throw new Error(j.reason || ('HTTP ' + r.status));
        target.value = j.text; target.dispatchEvent(new Event('input', { bubbles: true }));
        status.textContent = what === 'sms' ? (j.over ? 'The agent wrote ' + j.chars + ' characters; shorten it to 144.' : 'Done: ' + j.chars + ' characters. Edit it as you like.') : 'Done. Edit it as you like.';
        if (what === 'sms' && j.over) status.classList.add('text-danger');
        count();
      } catch (e) { status.textContent = e.message; status.classList.add('text-danger'); }
      b.disabled = false;
    });
  });
})();
</script>
