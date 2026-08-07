<?php
/**
 * /settings/iniedit/<basename> — single-file editor.
 *
 * Renders one card per section, KVP rows inside. Each row's input type +
 * placeholder + helper text comes from the per-key validation meta. Keys
 * flagged via section-level `obfuscate: [...]` (or per-key obfuscate:true)
 * render as a password input with a show/hide toggle and an empty-submit
 * = leave-existing-value-alone semantics.
 *
 * Vars: $basename, $parsed (from IniFileService::parse), $writable
 */
$parsed   = $parsed ?? ['lines' => [], 'sections' => []];
$basename = $basename ?? '';
$sections = $parsed['sections'] ?? [];
$writable = $writable ?? false;

use app\services\Config\IniFileService;

// Sentinel used in form input names whenever a section name is empty
// (i.e. top-of-file kvps in section-less ini files like mailgun.ini).
// HTML name="sections[]..." collapses to sections[0] in PHP, which
// doesn't survive the round-trip to IniFileService — server translates
// this back to '' in Settings::saveini().
const INI_TOP_SENTINEL = '__top__';

/**
 * Decide the HTML input type for a value based on its declared meta.
 * Falls back to "text" for unknown types.
 */
$inputType = function (array $meta, bool $obfuscate): string {
    if ($obfuscate) return 'password';
    $t = strtolower((string)($meta['type'] ?? 'string'));
    return match ($t) {
        'int'   => 'number',
        'email' => 'email',
        'url'   => 'url',
        'bool'  => 'text', // rendered separately as a select
        default => 'text',
    };
};

/**
 * Mask a secret with first 4 + last 4 characters preserved, dots in the
 * middle. Short values (≤8 chars) get fully dotted to avoid leaking the
 * whole thing. Used as the placeholder on obfuscated inputs so the admin
 * can recognize the value without seeing it in full.
 */
$maskPreview = function (string $v): string {
    $len = strlen($v);
    if ($len === 0) return '';
    if ($len <= 8) return str_repeat('•', max($len, 6));
    return substr($v, 0, 4) . str_repeat('•', max(4, min(12, $len - 8))) . substr($v, -4);
};

/**
 * Helper text under each field — describes the validation rules so
 * the admin doesn't have to read the inline comment in the file.
 */
$helperText = function (array $meta): string {
    if (empty($meta)) return '';
    $bits = [];
    $t = strtolower((string)($meta['type'] ?? ''));
    if ($t)                    $bits[] = "type: <code>{$t}</code>";
    if (isset($meta['min']))   $bits[] = "min: " . htmlspecialchars((string)$meta['min'], ENT_QUOTES);
    if (isset($meta['max']))   $bits[] = "max: " . htmlspecialchars((string)$meta['max'], ENT_QUOTES);
    if (!empty($meta['format'])) $bits[] = "format: <code>" . htmlspecialchars((string)$meta['format'], ENT_QUOTES) . "</code>";
    return implode(' &middot; ', $bits);
};
?>

<style>
    .ini-key-row { padding: 0.5rem 0; border-bottom: 1px dashed var(--bs-border-color); }
    .ini-key-row:last-child { border-bottom: 0; }
    .ini-key-label code { font-weight: 600; color: var(--bs-body-color); }
    .ini-meta-help { font-size: 0.75rem; color: var(--bs-secondary-color); margin-top: 0.2rem; }
    .ini-section-header {
        display: flex; align-items: center; gap: 0.5rem;
        background: var(--bs-secondary-bg); padding: 0.5rem 0.85rem;
        border-bottom: 1px solid var(--bs-border-color);
    }
    .ini-section-header code { font-size: 0.95rem; font-weight: 700; }
    .ini-obfuscate-toggle { cursor: pointer; }
</style>

<div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4">
    <div>
        <a href="/settings/ini" class="text-decoration-none small">
            <i class="bi bi-arrow-left me-1"></i><?= htmlspecialchars(t('Back to Configuration Files'), ENT_QUOTES, 'UTF-8') ?>
        </a>
        <h1 class="fw-bold mb-1 mt-1">
            <i class="bi bi-file-earmark-code text-primary me-2"></i><code><?= htmlspecialchars($basename, ENT_QUOTES, 'UTF-8') ?></code>
        </h1>
    </div>
</div>

<?php if (!$writable): ?>
    <div class="alert alert-warning d-flex align-items-center gap-2 mb-3">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <?= htmlspecialchars(t('This file is read-only on disk — fix the file permissions before saving will work.'), ENT_QUOTES, 'UTF-8') ?>
    </div>
<?php endif ?>

<form method="POST" action="/settings/saveini">
    <?= csrf_field() ?>
    <input type="hidden" name="file" value="<?= htmlspecialchars($basename, ENT_QUOTES, 'UTF-8') ?>">

    <?php foreach ($sections as $secName => $section):
        // Skip the synthetic "global" section if it has no keys (most files
        // start straight with [section]).
        if ($secName === '' && empty($section['keys'])) continue;
        $secMeta = $section['meta'] ?? [];
        $secNameDisplay = $secName === '' ? t('(top-of-file)') : $secName;
        // Form-input name for this section: '' breaks PHP nested-array
        // parsing, so swap to a sentinel the controller reverses.
        $formSecName = $secName === '' ? INI_TOP_SENTINEL : $secName;
    ?>
        <div class="card border-0 shadow-sm mb-3">
            <div class="ini-section-header">
                <i class="bi bi-folder2 text-primary"></i>
                <code>[<?= htmlspecialchars($secNameDisplay, ENT_QUOTES, 'UTF-8') ?>]</code>
                <?php if (!empty($secMeta)): ?>
                    <span class="text-body-secondary small ms-2"
                          title="<?= htmlspecialchars(json_encode($secMeta), ENT_QUOTES, 'UTF-8') ?>">
                        <i class="bi bi-info-circle"></i> <?= htmlspecialchars(t('meta'), ENT_QUOTES, 'UTF-8') ?>
                    </span>
                <?php endif ?>
            </div>
            <div class="card-body py-2">
                <?php foreach ($section['keys'] as $keyName => $keyData):
                    $keyMeta   = $keyData['meta']  ?? [];
                    $value     = (string)($keyData['value'] ?? '');
                    $obf       = IniFileService::shouldObfuscate($keyName, $secMeta, $keyMeta);
                    $isBool    = strtolower((string)($keyMeta['type'] ?? '')) === 'bool';
                    $type      = $inputType($keyMeta, $obf);
                    $help      = $helperText($keyMeta);
                    $inputId   = 'ini-' . md5($secName . '|' . $keyName);
                ?>
                    <?php
                        $secNameAttr = htmlspecialchars($formSecName, ENT_QUOTES, 'UTF-8');
                        $keyNameAttr = htmlspecialchars($keyName,     ENT_QUOTES, 'UTF-8');
                        $rulesId     = $inputId . '-rules';
                        // Pre-fill the inline "edit rules" form with whatever
                        // metadata the file already declares. Empty fields are
                        // serialized out to {} on save (no rules).
                        $metaType   = (string)($keyMeta['type']   ?? '');
                        $metaMin    = isset($keyMeta['min'])      ? (string)$keyMeta['min']    : '';
                        $metaMax    = isset($keyMeta['max'])      ? (string)$keyMeta['max']    : '';
                        $metaFormat = (string)($keyMeta['format'] ?? '');
                        // shouldObfuscate baked auto-detect into $obf — only
                        // mark the checkbox checked when there's an EXPLICIT
                        // obfuscate flag in keyMeta, so unchecking actually
                        // writes {"obfuscate": false}.
                        $metaObfuscateChecked = !empty($keyMeta['obfuscate']);
                    ?>
                    <div class="ini-key-row" data-section="<?= $secNameAttr ?>" data-key="<?= $keyNameAttr ?>">
                        <div class="row g-2 align-items-center">
                            <div class="col-md-4 ini-key-label">
                                <label for="<?= $inputId ?>" class="form-label mb-0">
                                    <code><?= $keyNameAttr ?></code>
                                </label>
                                <?php if ($help): ?>
                                    <div class="ini-meta-help"><?= $help /* already escaped per-piece */ ?></div>
                                <?php endif ?>
                            </div>
                            <div class="col-md-7">
                                <?php if ($isBool): ?>
                                    <select id="<?= $inputId ?>"
                                            name="sections[<?= $secNameAttr ?>][keys][<?= $keyNameAttr ?>]"
                                            class="form-select form-select-sm">
                                        <?php $boolish = strtolower($value); ?>
                                        <option value="0" <?= in_array($boolish, ['', '0', 'false', 'no', 'off'], true) ? 'selected' : '' ?>><?= htmlspecialchars(t('false')) ?></option>
                                        <option value="1" <?= in_array($boolish, ['1', 'true', 'yes', 'on'], true)      ? 'selected' : '' ?>><?= htmlspecialchars(t('true')) ?></option>
                                    </select>
                                <?php elseif ($obf):
                                    // Placeholder shows first4...last4 of the
                                    // current value so the operator can
                                    // recognize which secret this is without
                                    // it leaking in plaintext. Empty input
                                    // leaves the stored value unchanged on
                                    // save (saveini handles the empty-keep).
                                    $masked = $maskPreview($value);
                                    $placeholder = $value !== ''
                                        ? $masked . ' (' . htmlspecialchars(t('leave blank to keep current')) . ')'
                                        : '';
                                ?>
                                    <div class="input-group input-group-sm">
                                        <input id="<?= $inputId ?>"
                                               type="password"
                                               name="sections[<?= $secNameAttr ?>][keys][<?= $keyNameAttr ?>]"
                                               class="form-control form-control-sm"
                                               value=""
                                               placeholder="<?= htmlspecialchars($placeholder, ENT_QUOTES, 'UTF-8') ?>"
                                               autocomplete="off">
                                        <button type="button"
                                                class="btn btn-outline-secondary ini-obfuscate-toggle"
                                                data-target="#<?= $inputId ?>"
                                                title="<?= htmlspecialchars(t('Show / hide value'), ENT_QUOTES, 'UTF-8') ?>"
                                                data-actual-value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                    </div>
                                <?php else: ?>
                                    <input id="<?= $inputId ?>"
                                           type="<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>"
                                           name="sections[<?= $secNameAttr ?>][keys][<?= $keyNameAttr ?>]"
                                           class="form-control form-control-sm font-monospace"
                                           value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>">
                                <?php endif ?>
                            </div>
                            <div class="col-md-1 text-end">
                                <button type="button"
                                        class="btn btn-sm btn-link text-body-secondary p-1 ini-row-rules"
                                        data-target="#<?= $rulesId ?>"
                                        title="<?= htmlspecialchars(t('Edit validation rules'), ENT_QUOTES, 'UTF-8') ?>">
                                    <i class="bi bi-sliders"></i>
                                </button>
                                <button type="button"
                                        class="btn btn-sm btn-link text-danger p-1 ini-row-delete"
                                        title="<?= htmlspecialchars(t('Delete this key'), ENT_QUOTES, 'UTF-8') ?>">
                                    <i class="bi bi-trash3"></i>
                                </button>
                            </div>
                        </div>

                        <!-- Edit rules: type / min / max / format / obfuscate.
                             Hidden until the gear icon is clicked. JS bundles
                             the values into a JSON string posted as
                             sections[<sec>][keyMetaJson][<key>]. -->
                        <div class="ini-rules-panel mt-2 ps-3" id="<?= $rulesId ?>" hidden>
                            <div class="row g-2 align-items-end small">
                                <div class="col-md-2">
                                    <label class="form-label mb-1 small"><?= htmlspecialchars(t('Type'), ENT_QUOTES, 'UTF-8') ?></label>
                                    <select class="form-select form-select-sm ini-rule-type">
                                        <?php foreach (['','string','int','bool','email','url','secret'] as $t): ?>
                                            <option value="<?= $t ?>" <?= $metaType === $t ? 'selected' : '' ?>>
                                                <?= $t === '' ? htmlspecialchars(t('(any)')) : htmlspecialchars($t) ?>
                                            </option>
                                        <?php endforeach ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label mb-1 small"><?= htmlspecialchars(t('Min'), ENT_QUOTES, 'UTF-8') ?></label>
                                    <input type="text" class="form-control form-control-sm ini-rule-min font-monospace"
                                           value="<?= htmlspecialchars($metaMin, ENT_QUOTES, 'UTF-8') ?>"
                                           placeholder="<?= htmlspecialchars(t('e.g. 7')) ?>">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label mb-1 small"><?= htmlspecialchars(t('Max'), ENT_QUOTES, 'UTF-8') ?></label>
                                    <input type="text" class="form-control form-control-sm ini-rule-max font-monospace"
                                           value="<?= htmlspecialchars($metaMax, ENT_QUOTES, 'UTF-8') ?>"
                                           placeholder="<?= htmlspecialchars(t('e.g. 7')) ?>">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label mb-1 small"><?= htmlspecialchars(t('Format'), ENT_QUOTES, 'UTF-8') ?> <span class="text-body-secondary">(<code>#</code><?= htmlspecialchars(t('=digit,'), ENT_QUOTES, 'UTF-8') ?> <code>A</code><?= htmlspecialchars(t('=letter)'), ENT_QUOTES, 'UTF-8') ?></span></label>
                                    <input type="text" class="form-control form-control-sm ini-rule-format font-monospace"
                                           value="<?= htmlspecialchars($metaFormat, ENT_QUOTES, 'UTF-8') ?>"
                                           placeholder="####-##">
                                </div>
                                <div class="col-md-3">
                                    <div class="form-check mt-3">
                                        <input class="form-check-input ini-rule-obfuscate" type="checkbox"
                                               id="<?= $rulesId ?>-obf" <?= $metaObfuscateChecked ? 'checked' : '' ?>>
                                        <label class="form-check-label small" for="<?= $rulesId ?>-obf">
                                            <?= htmlspecialchars(t('Obfuscate (mask in editor)'), ENT_QUOTES, 'UTF-8') ?>
                                        </label>
                                    </div>
                                </div>
                            </div>
                            <div class="form-text small mt-2">
                                <?= htmlspecialchars(t('Rules attach to this key as JSON in a trailing comment. Submit the form to apply.'), ENT_QUOTES, 'UTF-8') ?>
                            </div>
                        </div>

                        <!-- Hidden inputs synced by JS:
                             keyMetaJson is updated whenever the rules panel
                             changes; deletes appears (with the key name) only
                             after the row's trash button is hit. -->
                        <input type="hidden"
                               name="sections[<?= $secNameAttr ?>][keyMetaJson][<?= $keyNameAttr ?>]"
                               class="ini-row-metajson"
                               value="<?= htmlspecialchars(empty($keyMeta) ? '' : json_encode($keyMeta, JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                <?php endforeach ?>

                <!-- Add a new key to this section. JS clones the template
                     row and appends parallel name/value inputs. -->
                <div class="mt-3 pt-2 border-top">
                    <details>
                        <summary class="small text-body-secondary"><i class="bi bi-plus-circle me-1"></i><?= htmlspecialchars(t('Add a key to this section'), ENT_QUOTES, 'UTF-8') ?></summary>
                        <div class="ini-newkeys mt-2" data-section="<?= htmlspecialchars($formSecName, ENT_QUOTES, 'UTF-8') ?>"></div>
                        <button type="button"
                                class="btn btn-sm btn-outline-primary ini-add-newkey"
                                data-section="<?= htmlspecialchars($formSecName, ENT_QUOTES, 'UTF-8') ?>">
                            <i class="bi bi-plus-lg me-1"></i><?= htmlspecialchars(t('Add another row'), ENT_QUOTES, 'UTF-8') ?>
                        </button>
                    </details>
                </div>
            </div>
        </div>
    <?php endforeach ?>

    <!-- Add a new section. JS gives you a name + initial KVPs. -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body py-2">
            <details>
                <summary class="fw-semibold small"><i class="bi bi-plus-square me-1"></i><?= htmlspecialchars(t('Add a new section'), ENT_QUOTES, 'UTF-8') ?></summary>
                <div id="ini-newsections" class="mt-3"></div>
                <button type="button" id="ini-add-section" class="btn btn-sm btn-outline-primary mt-2">
                    <i class="bi bi-plus-lg me-1"></i><?= htmlspecialchars(t('Add section'), ENT_QUOTES, 'UTF-8') ?>
                </button>
            </details>
        </div>
    </div>

    <div class="d-flex justify-content-between align-items-center mt-3">
        <a href="/settings/ini" class="btn btn-outline-secondary"><?= htmlspecialchars(t('Cancel'), ENT_QUOTES, 'UTF-8') ?></a>
        <button type="submit" class="btn btn-primary" <?= $writable ? '' : 'disabled' ?>>
            <i class="bi bi-check2 me-1"></i><?= htmlspecialchars(t('Save changes'), ENT_QUOTES, 'UTF-8') ?>
        </button>
    </div>
</form>

<script>
(function () {
    'use strict';

    // Helper to escape HTML attribute payload — used for section names
    // that go into name="sections[...][newKeyNames][]" attributes.
    function attr(s) { return String(s).replace(/[&<>"']/g, c =>
        ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

    function newKeyRow(sectionName, namePrefix) {
        var div = document.createElement('div');
        div.className = 'row g-2 align-items-center mb-2';
        div.innerHTML =
            '<div class="col-md-4">' +
              '<input type="text" placeholder="key_name" class="form-control form-control-sm font-monospace" ' +
                'name="' + namePrefix + '[newKeyNames][]" pattern="[A-Za-z_][A-Za-z0-9_.-]*">' +
            '</div>' +
            '<div class="col-md-7">' +
              '<input type="text" placeholder="<?= htmlspecialchars(t('value'), ENT_QUOTES, 'UTF-8') ?>" class="form-control form-control-sm font-monospace" ' +
                'name="' + namePrefix + '[newKeyValues][]">' +
            '</div>' +
            '<div class="col-md-1 text-end">' +
              '<button type="button" class="btn btn-sm btn-link text-danger ini-remove-row" title="<?= htmlspecialchars(t('Remove'), ENT_QUOTES, 'UTF-8') ?>"><i class="bi bi-x-lg"></i></button>' +
            '</div>';
        div.querySelector('.ini-remove-row').addEventListener('click', () => div.remove());
        return div;
    }

    // Per-section "Add another row" — appends a new KVP into that section's
    // newKeys array. The form submit handler in the controller pairs the
    // parallel newKeyNames/newKeyValues arrays back into a real map.
    document.querySelectorAll('.ini-add-newkey').forEach(btn => {
        var section   = btn.dataset.section;
        var container = document.querySelector('.ini-newkeys[data-section="' + (section.replace(/"/g, '\\"')) + '"]');
        var prefix    = 'sections[' + section + ']';
        // Seed with one row when the details first opens (lazy via click).
        btn.addEventListener('click', () => container.appendChild(newKeyRow(section, prefix)));
    });

    // New-section flow: name field + an "Add row" that grows kvp inputs
    // under newSections[<name>][newKeyNames|Values][].
    var nsContainer = document.getElementById('ini-newsections');
    document.getElementById('ini-add-section').addEventListener('click', () => {
        var wrap = document.createElement('div');
        wrap.className = 'card mb-3';
        wrap.innerHTML =
            '<div class="card-body py-2">' +
              '<div class="row g-2 align-items-center mb-2">' +
                '<div class="col-md-4">' +
                  '<input type="text" class="form-control form-control-sm font-monospace ini-newsection-name" ' +
                    'placeholder="new_section" pattern="[A-Za-z][A-Za-z0-9_.-]*" required>' +
                '</div>' +
                '<div class="col-md-7 small text-body-secondary">' +
                  '<?= htmlspecialchars(t('Section header — must start with a letter, then letters / digits / _ . -'), ENT_QUOTES, 'UTF-8') ?>' +
                '</div>' +
                '<div class="col-md-1 text-end">' +
                  '<button type="button" class="btn btn-sm btn-link text-danger ini-remove-section" title="<?= htmlspecialchars(t('Remove section'), ENT_QUOTES, 'UTF-8') ?>"><i class="bi bi-x-lg"></i></button>' +
                '</div>' +
              '</div>' +
              '<div class="ini-newsection-rows"></div>' +
              '<button type="button" class="btn btn-sm btn-outline-primary ini-add-section-row mt-2">' +
                '<i class="bi bi-plus-lg me-1"></i><?= htmlspecialchars(t('Add key'), ENT_QUOTES, 'UTF-8') ?>' +
              '</button>' +
            '</div>';
        nsContainer.appendChild(wrap);

        var nameInput = wrap.querySelector('.ini-newsection-name');
        var rowsHost  = wrap.querySelector('.ini-newsection-rows');
        var addBtn    = wrap.querySelector('.ini-add-section-row');
        wrap.querySelector('.ini-remove-section').addEventListener('click', () => wrap.remove());

        function syncNamesPrefix() {
            var name = nameInput.value.trim() || '__';
            // Re-walk all rows under this wrap and reassign the [name] prefix
            // so server-side groups them under newSections[<currentName>].
            wrap.querySelectorAll('.ini-newsection-rows input').forEach(inp => {
                var role = inp.dataset.role; // 'name' | 'value'
                inp.name = 'newSections[' + name + '][newKey' + (role === 'name' ? 'Names' : 'Values') + '][]';
            });
        }
        nameInput.addEventListener('input', syncNamesPrefix);

        function addRow() {
            var row = document.createElement('div');
            row.className = 'row g-2 align-items-center mb-2';
            row.innerHTML =
                '<div class="col-md-4">' +
                  '<input type="text" placeholder="key_name" data-role="name" ' +
                    'class="form-control form-control-sm font-monospace" pattern="[A-Za-z_][A-Za-z0-9_.-]*">' +
                '</div>' +
                '<div class="col-md-7">' +
                  '<input type="text" placeholder="<?= htmlspecialchars(t('value'), ENT_QUOTES, 'UTF-8') ?>" data-role="value" ' +
                    'class="form-control form-control-sm font-monospace">' +
                '</div>' +
                '<div class="col-md-1 text-end">' +
                  '<button type="button" class="btn btn-sm btn-link text-danger ini-remove-row" title="<?= htmlspecialchars(t('Remove'), ENT_QUOTES, 'UTF-8') ?>"><i class="bi bi-x-lg"></i></button>' +
                '</div>';
            row.querySelector('.ini-remove-row').addEventListener('click', () => { row.remove(); syncNamesPrefix(); });
            rowsHost.appendChild(row);
            syncNamesPrefix();
        }
        addBtn.addEventListener('click', addRow);
        addRow(); // seed one
    });

    // Show / hide for obfuscated values.
    document.querySelectorAll('.ini-obfuscate-toggle').forEach(btn => {
        btn.addEventListener('click', () => {
            var input = document.querySelector(btn.dataset.target);
            if (!input) return;
            input.type = input.type === 'password' ? 'text' : 'password';
            var icon = btn.querySelector('i');
            if (icon) icon.className = input.type === 'password' ? 'bi bi-eye' : 'bi bi-eye-slash';
        });
    });

    // ── Edit-rules panel toggle (gear icon per row).
    document.querySelectorAll('.ini-row-rules').forEach(btn => {
        btn.addEventListener('click', () => {
            var panel = document.querySelector(btn.dataset.target);
            if (!panel) return;
            panel.hidden = !panel.hidden;
            btn.classList.toggle('text-primary', !panel.hidden);
            btn.classList.toggle('text-body-secondary', panel.hidden);
        });
    });

    // ── Sync the hidden keyMetaJson input whenever any rule input changes.
    // Server expects a single JSON string per key — empty fields collapse
    // to {} (no rules). Build the object only with non-empty entries so
    // the resulting metadata is minimal.
    function syncMetaForRow(row) {
        var typeEl   = row.querySelector('.ini-rule-type');
        var minEl    = row.querySelector('.ini-rule-min');
        var maxEl    = row.querySelector('.ini-rule-max');
        var fmtEl    = row.querySelector('.ini-rule-format');
        var obfEl    = row.querySelector('.ini-rule-obfuscate');
        var hidden   = row.querySelector('.ini-row-metajson');
        if (!hidden) return;

        var meta = {};
        if (typeEl && typeEl.value)        meta.type      = typeEl.value;
        if (minEl  && minEl.value  !== '') meta.min       = isNaN(Number(minEl.value)) ? minEl.value : Number(minEl.value);
        if (maxEl  && maxEl.value  !== '') meta.max       = isNaN(Number(maxEl.value)) ? maxEl.value : Number(maxEl.value);
        if (fmtEl  && fmtEl.value)         meta.format    = fmtEl.value;
        // Only emit the obfuscate flag when the checkbox state differs from
        // the auto-detect default, so the metadata stays clean for keys we
        // already mask via heuristic.
        if (obfEl) {
            // Always include when checked; include false only when explicitly
            // unchecked (so user can opt out of heuristic masking).
            if (obfEl.checked || obfEl.dataset.explicitOff === '1') {
                meta.obfuscate = !!obfEl.checked;
            }
        }
        hidden.value = Object.keys(meta).length === 0 ? '' : JSON.stringify(meta);
    }
    document.querySelectorAll('.ini-key-row').forEach(row => {
        // Mark obfuscate as "explicitly off" once the user touches it, so
        // unchecking writes {"obfuscate": false} instead of being treated
        // as "no opinion".
        var obfEl = row.querySelector('.ini-rule-obfuscate');
        if (obfEl) obfEl.addEventListener('change', () => {
            obfEl.dataset.explicitOff = '1';
            syncMetaForRow(row);
        });
        ['ini-rule-type','ini-rule-min','ini-rule-max','ini-rule-format'].forEach(cls => {
            var el = row.querySelector('.' + cls);
            if (el) el.addEventListener('input',  () => syncMetaForRow(row));
            if (el) el.addEventListener('change', () => syncMetaForRow(row));
        });
    });

    // ── Delete row.
    // Strategy: don't physically remove the row from the DOM (the user's
    // value/meta inputs still need to round-trip if they cancel via reload),
    // just mark it for deletion. We add a hidden input named
    // sections[<sec>][deletes][] = <key>, hide the row, and disable any
    // value/meta inputs so they don't compete with the delete on the server.
    document.querySelectorAll('.ini-row-delete').forEach(btn => {
        btn.addEventListener('click', () => {
            var row = btn.closest('.ini-key-row');
            if (!row) return;
            var keyName = row.dataset.key;
            var secName = row.dataset.section;
            if (!confirm('<?= htmlspecialchars(t('Delete key \":name\"? Saves take effect when you submit the form.'), ENT_QUOTES, 'UTF-8') ?>'.replace(':name', keyName))) return;
            // Disable inputs in this row so they're skipped on submit.
            row.querySelectorAll('input, select, textarea').forEach(el => el.disabled = true);
            // Append the hidden delete marker — outside the row so it's
            // safe even if the row is removed visually.
            var hidden = document.createElement('input');
            hidden.type  = 'hidden';
            hidden.name  = 'sections[' + secName + '][deletes][]';
            hidden.value = keyName;
            row.parentNode.insertBefore(hidden, row.nextSibling);
            // Visual confirmation — strike through + dim the row.
            row.style.opacity      = '0.45';
            row.style.textDecoration = 'line-through';
            row.style.pointerEvents = 'none';
            // Replace the trash button with a small notice + undo so the
            // user has a way out before submit.
            var actionsCol = row.querySelector('.col-md-1');
            if (actionsCol) {
                actionsCol.innerHTML = '<span class="badge bg-danger-subtle text-danger"><?= htmlspecialchars(t('queued for delete'), ENT_QUOTES, 'UTF-8') ?></span>';
            }
        });
    });
})();
</script>

