<?php
/**
 * /settings/ini — list every editable INI file in conf/ + show templates
 * (.example.ini) whose actual sibling doesn't exist yet, with a one-click
 * "create from this template" button.
 *
 * Root-only entry point; the view trusts the controller's gate.
 */
$files     = $files ?? ['editable' => [], 'templates' => []];
$editable  = $files['editable']  ?? [];
$templates = $files['templates'] ?? [];
?>

<div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4">
    <div>
        <a href="/settings" class="text-decoration-none small"><i class="bi bi-arrow-left me-1"></i><?= htmlspecialchars(t('Back to Settings'), ENT_QUOTES, 'UTF-8') ?></a>
        <h1 class="fw-bold mb-1 mt-1"><?= htmlspecialchars(t('Configuration Files'), ENT_QUOTES, 'UTF-8') ?></h1>
        <p class="text-body-secondary mb-0 small">
            <?= htmlspecialchars(t('Edit'), ENT_QUOTES, 'UTF-8') ?> <code><?= htmlspecialchars(t('conf/*.ini'), ENT_QUOTES, 'UTF-8') ?></code> <?= htmlspecialchars(t('files in place. Validation rules + obfuscation
            for sensitive keys live in inline JSON comments — see'), ENT_QUOTES, 'UTF-8') ?> <code><?= htmlspecialchars(t('shopify.ini'), ENT_QUOTES, 'UTF-8') ?></code>
            <?= htmlspecialchars(t('for the convention.'), ENT_QUOTES, 'UTF-8') ?>
        </p>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-transparent">
        <i class="bi bi-pencil-square text-primary me-2"></i><strong><?= htmlspecialchars(t('Editable'), ENT_QUOTES, 'UTF-8') ?></strong>
        <span class="text-body-secondary small ms-2"><?= count($editable) ?> <?= count($editable) === 1 ? htmlspecialchars(t('file')) : htmlspecialchars(t('files')) ?></span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th><?= htmlspecialchars(t('File'), ENT_QUOTES, 'UTF-8') ?></th>
                    <th class="text-end"><?= htmlspecialchars(t('Size'), ENT_QUOTES, 'UTF-8') ?></th>
                    <th><?= htmlspecialchars(t('Modified'), ENT_QUOTES, 'UTF-8') ?></th>
                    <th class="text-center"><?= htmlspecialchars(t('Writable'), ENT_QUOTES, 'UTF-8') ?></th>
                    <th class="text-end pe-3" style="width:32px;"></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($editable)): ?>
                    <tr><td colspan="5" class="text-center py-5 text-body-secondary">
                        <?= htmlspecialchars(t('No editable INI files in'), ENT_QUOTES, 'UTF-8') ?> <code><?= htmlspecialchars(t('conf/'), ENT_QUOTES, 'UTF-8') ?></code>.
                    </td></tr>
                <?php else: foreach ($editable as $f):
                    // Whole row navigates to the editor — drops the explicit
                    // Edit button. Chevron in the trailing cell is the visual
                    // affordance. Anchor wraps for correct keyboard + middle-
                    // click behaviour; data-row-link drives the JS handler.
                    $editUrl = '/settings/iniedit?file=' . urlencode($f['name']);
                ?>
                    <tr class="ini-row-link" data-href="<?= htmlspecialchars($editUrl, ENT_QUOTES, 'UTF-8') ?>" tabindex="0">
                        <td>
                            <i class="bi bi-file-earmark-code me-2 text-primary"></i>
                            <a href="<?= htmlspecialchars($editUrl, ENT_QUOTES, 'UTF-8') ?>" class="text-decoration-none">
                                <code class="fw-semibold"><?= htmlspecialchars($f['name'], ENT_QUOTES, 'UTF-8') ?></code>
                            </a>
                        </td>
                        <td class="text-end font-monospace small text-body-secondary">
                            <?= number_format($f['size']) ?> B
                        </td>
                        <td class="small text-body-secondary">
                            <?= $f['mtime'] ? htmlspecialchars(date('M j, Y g:i A', $f['mtime']), ENT_QUOTES, 'UTF-8') : '—' ?>
                        </td>
                        <td class="text-center">
                            <?php if ($f['writable']): ?>
                                <span class="badge bg-success-subtle text-success"><?= htmlspecialchars(t('Yes'), ENT_QUOTES, 'UTF-8') ?></span>
                            <?php else: ?>
                                <span class="badge bg-warning-subtle text-warning" title="<?= htmlspecialchars(t('File is read-only on disk — fix permissions to edit'), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(t('Read-only'), ENT_QUOTES, 'UTF-8') ?></span>
                            <?php endif ?>
                        </td>
                        <td class="text-end pe-3 text-body-secondary">
                            <i class="bi bi-chevron-right"></i>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if (!empty($templates)): ?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-transparent">
        <i class="bi bi-file-earmark-plus text-success me-2"></i><strong><?= htmlspecialchars(t('Templates'), ENT_QUOTES, 'UTF-8') ?></strong>
        <span class="text-body-secondary small ms-2">
            <?= count($templates) ?> <?= htmlspecialchars(t("available — read-only scaffolds. \"Create\" generates a real config; if one already exists you'll see \"Edit existing\" instead."), ENT_QUOTES, 'UTF-8') ?>
        </span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th><?= htmlspecialchars(t('Template'), ENT_QUOTES, 'UTF-8') ?></th>
                    <th><?= htmlspecialchars(t('Target'), ENT_QUOTES, 'UTF-8') ?></th>
                    <th class="text-end"><?= htmlspecialchars(t('Size'), ENT_QUOTES, 'UTF-8') ?></th>
                    <th class="text-end pe-3" style="width:32px;"></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($templates as $t):
                    // Row click semantics: if the real config already exists,
                    // navigate to the editor. If not, submit the (create)
                    // form on the row. Either way, no separate button — the
                    // chevron is the affordance, hint text in the corner
                    // disambiguates the destructive create case.
                    $hasActual = !empty($t['actual_exists']);
                    $editUrl   = '/settings/iniedit?file=' . urlencode($t['actual_name']);
                ?>
                    <tr<?= $hasActual ? ' class="ini-row-link text-body-secondary" data-href="' . htmlspecialchars($editUrl, ENT_QUOTES, 'UTF-8') . '"' : ' class="ini-row-submit"' ?> tabindex="0">
                        <td>
                            <i class="bi bi-file-earmark-text me-2 text-success"></i>
                            <code class="text-body-secondary"><?= htmlspecialchars($t['name'], ENT_QUOTES, 'UTF-8') ?></code>
                        </td>
                        <td>
                            <i class="bi bi-arrow-right me-1 text-muted"></i>
                            <code class="fw-semibold"><?= htmlspecialchars($t['actual_name'], ENT_QUOTES, 'UTF-8') ?></code>
                            <?php if ($hasActual): ?>
                                <span class="badge bg-secondary-subtle text-secondary ms-1" title="<?= htmlspecialchars(t('The real config already exists — click row to edit'), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(t('exists'), ENT_QUOTES, 'UTF-8') ?></span>
                            <?php else: ?>
                                <span class="badge bg-success-subtle text-success ms-1" title="<?= htmlspecialchars(t('Click row to create the real config from this template'), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(t('click to create'), ENT_QUOTES, 'UTF-8') ?></span>
                            <?php endif ?>
                        </td>
                        <td class="text-end font-monospace small text-body-secondary">
                            <?= number_format($t['size']) ?> B
                        </td>
                        <td class="text-end pe-3 text-body-secondary">
                            <?php if ($hasActual): ?>
                                <i class="bi bi-chevron-right"></i>
                            <?php else: ?>
                                <form method="POST" action="/settings/initemplate" class="ini-create-form d-none">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="file" value="<?= htmlspecialchars($t['name'], ENT_QUOTES, 'UTF-8') ?>">
                                </form>
                                <i class="bi bi-plus-lg text-success"></i>
                            <?php endif ?>
                        </td>
                    </tr>
                <?php endforeach ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif ?>

<div class="alert alert-info small mb-0">
    <i class="bi bi-info-circle me-2"></i>
    <strong><?= htmlspecialchars(t('Validation hints &amp; obfuscation'), ENT_QUOTES, 'UTF-8') ?></strong> <?= htmlspecialchars(t('are stored as JSON in trailing comments —
    keeps the schema next to the value. Examples:'), ENT_QUOTES, 'UTF-8') ?>
    <pre class="mt-2 mb-0 small"><code>[shopify] ; {"obfuscate": ["client_secret", "provision_secret"]}
api_version = "2026-04" ; {"type": "string", "min": 7, "max": 7, "format": "####-##"}</code></pre>
</div>

<style>
    .ini-row-link, .ini-row-submit { cursor: pointer; }
    .ini-row-link:hover, .ini-row-submit:hover { background-color: var(--bs-tertiary-bg); }
    .ini-row-link:focus, .ini-row-submit:focus { outline: 2px solid var(--bs-primary); outline-offset: -2px; }
    /* Inline anchors inherit the row's focus + remove their own underline so
       the row hover reads as a single tap target. */
    .ini-row-link a, .ini-row-submit a { color: inherit; }
</style>
<script>
(function () {
    // Whole-row click → edit existing or submit create form. Skip when the
    // user clicked an actual interactive child (anchor, button, input) so
    // those keep their default behaviour for keyboard / context menu / etc.
    function shouldSkip(target) {
        return !!(target.closest && target.closest('a, button, input, select, textarea'));
    }

    document.querySelectorAll('.ini-row-link').forEach(function (row) {
        var href = row.dataset.href;
        if (!href) return;
        row.addEventListener('click', function (e) {
            if (shouldSkip(e.target)) return;
            window.location = href;
        });
        row.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter' && e.key !== ' ') return;
            if (shouldSkip(e.target)) return;
            e.preventDefault();
            window.location = href;
        });
    });

    document.querySelectorAll('.ini-row-submit').forEach(function (row) {
        var form = row.querySelector('form.ini-create-form');
        if (!form) return;
        row.addEventListener('click', function (e) {
            if (shouldSkip(e.target)) return;
            form.submit();
        });
        row.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter' && e.key !== ' ') return;
            if (shouldSkip(e.target)) return;
            e.preventDefault();
            form.submit();
        });
    });
})();
</script>

