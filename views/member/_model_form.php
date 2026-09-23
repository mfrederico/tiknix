<?php
/**
 * One model-connection form (new or edit). Included by _models.php with
 * $form (a summary, or blanks for new), $formId, $presets, $tiers, $h.
 */
?>
<form method="POST" action="/member/modelsave" class="row g-2 small">
  <?= csrf_field() ?>
  <input type="hidden" name="id" value="<?= (int) $form['id'] ?>">
  <div class="col-md-4">
    <label class="form-label mb-0">Start from</label>
    <select class="form-select form-select-sm mc-preset" name="preset">
      <?php foreach ($presets as $k => $p): ?>
        <option value="<?= $h($k) ?>" <?= $form['preset'] === $k ? 'selected' : '' ?>><?= $h($p['label']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-md-8">
    <label class="form-label mb-0">Name</label>
    <input class="form-control form-control-sm" name="name" maxlength="60" value="<?= $h($form['name']) ?>" required>
  </div>
  <div class="col-md-6">
    <label class="form-label mb-0">Base URL</label>
    <input class="form-control form-control-sm font-monospace" name="base_url" value="<?= $h($form['base_url']) ?>" placeholder="https://ollama.com" required>
  </div>
  <div class="col-md-3">
    <label class="form-label mb-0">Protocol</label>
    <select class="form-select form-select-sm" name="protocol">
      <option value="anthropic" <?= $form['protocol'] === 'anthropic' ? 'selected' : '' ?>>Anthropic Messages (builds)</option>
      <option value="openai" <?= $form['protocol'] === 'openai' ? 'selected' : '' ?>>OpenAI chat (chat only)</option>
    </select>
  </div>
  <div class="col-md-3">
    <label class="form-label mb-0">Auth</label>
    <select class="form-select form-select-sm" name="auth">
      <option value="bearer" <?= $form['auth'] === 'bearer' ? 'selected' : '' ?>>Bearer token</option>
      <option value="api_key" <?= $form['auth'] === 'api_key' ? 'selected' : '' ?>>x-api-key (Anthropic)</option>
      <option value="none" <?= $form['auth'] === 'none' ? 'selected' : '' ?>>None (local server)</option>
    </select>
  </div>
  <div class="col-12">
    <label class="form-label mb-0">API key</label>
    <input class="form-control form-control-sm font-monospace" name="api_key" type="password" autocomplete="off"
           placeholder="<?= $form['key_status'] === 'set' ? 'saved — leave blank to keep it' : 'paste your key' ?>">
    <?php if ($form['key_status'] !== 'unset' && (int) $form['id'] > 0): ?>
      <div class="form-check mt-1"><input class="form-check-input" type="checkbox" name="clear_key" value="1" id="mc-clear-<?= $h($formId) ?>">
        <label class="form-check-label" for="mc-clear-<?= $h($formId) ?>">Remove the saved key</label></div>
    <?php endif; ?>
    <div class="form-text mc-hint"><?= $h($presets[$form['preset']]['hint'] ?? '') ?></div>
  </div>
  <datalist id="mc-models-<?= $h($formId) ?>"></datalist>
  <?php foreach ($tiers as $t => $label): ?>
    <div class="col-md-4">
      <label class="form-label mb-0"><?= $h($label) ?> model<?= $t === 'haiku' ? ' <span class="text-muted">(optional)</span>' : '' ?></label>
      <input class="form-control form-control-sm font-monospace" name="<?= $h($t) ?>_model" list="mc-models-<?= $h($formId) ?>"
             value="<?= $h($form[$t . '_model']) ?>" <?= $t === 'haiku' ? '' : 'required' ?>>
    </div>
  <?php endforeach; ?>
  <div class="col-12">
    <button class="btn btn-sm btn-primary" type="submit"><?= (int) $form['id'] > 0 ? 'Save changes' : 'Add connection' ?></button>
    <?php if ((int) $form['id'] > 0): ?><span class="text-muted ms-2">Then Test to list this endpoint's models.</span><?php endif; ?>
  </div>
</form>
