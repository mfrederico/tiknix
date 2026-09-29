<?php
/**
 * Translations admin page. Embeds the translatify package's shipped editor
 * partial. Variables (rows, locales, baseLocale, saveUrl, newLocaleUrl,
 * scanUrl, csrfToken) are provided by controls/Translations.php and are in
 * scope here; $editorView is the absolute path to the package partial.
 */
?>
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h3 class="mb-0">Translations</h3>
        <a href="/admin" class="btn btn-sm btn-outline-secondary">&larr; Admin</a>
    </div>
    <p class="text-muted">
        English strings are the keys. Edit a cell to translate; changes save on blur.
        Use <strong>Scan source files</strong> to harvest new <code>t('…')</code> strings into <code>en.json</code>.
    </p>

    <?php if (!empty($editorView) && is_file($editorView)): ?>
        <?php include $editorView; ?>
    <?php else: ?>
        <div class="alert alert-warning">Translatify editor view not found. Is the package installed?</div>
    <?php endif; ?>
</div>
