<?php
/**
 * Curated settings form. One card per group, one row per declared field.
 *
 * Vars: $groups, $writable, $isRoot  (from controls/Settings::index)
 *
 * No defaults are invented here — an undefined variable should error loudly
 * rather than render a page that quietly claims 2FA is off.
 */
?>
<div class="container py-4" style="max-width: 860px;">

    <div class="d-flex align-items-center justify-content-between mb-4">
        <div>
            <h1 class="h3 mb-1"><?= htmlspecialchars(t('Settings'), ENT_QUOTES, 'UTF-8') ?></h1>
            <p class="text-muted mb-0 small">
                <?= htmlspecialchars(t('These write directly to conf/config.ini. The value shown is the one in force.'), ENT_QUOTES, 'UTF-8') ?>
            </p>
        </div>
        <?php if ($isRoot): ?>
            <a href="/settings/ini" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-file-earmark-code me-1"></i><?= htmlspecialchars(t('Raw config files'), ENT_QUOTES, 'UTF-8') ?>
            </a>
        <?php endif; ?>
    </div>

    <?php if (!$writable): ?>
        <div class="alert alert-warning d-flex align-items-center">
            <i class="bi bi-exclamation-triangle me-2"></i>
            <div>
                <strong><?= htmlspecialchars(t('conf/config.ini is not writable.'), ENT_QUOTES, 'UTF-8') ?></strong>
                <div class="small"><?= htmlspecialchars(t('Changes cannot be saved until the web user can write it.'), ENT_QUOTES, 'UTF-8') ?></div>
            </div>
        </div>
    <?php endif; ?>

    <form method="POST" action="/settings/save">
        <?= csrf_field() ?>

        <?php foreach ($groups as $group): ?>
            <div class="card mb-3">
                <div class="card-header bg-transparent fw-semibold">
                    <?= htmlspecialchars($group['group'], ENT_QUOTES, 'UTF-8') ?>
                </div>
                <div class="card-body">
                    <?php foreach ($group['items'] as $i => $item):
                        $id   = $item['section'] . '.' . $item['key'];
                        $name = 'f[' . $id . ']';
                    ?>
                        <div class="<?= $i > 0 ? 'mt-4 pt-3 border-top' : '' ?>">
                            <?php if ($item['type'] === 'bool'): ?>
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" role="switch"
                                           id="<?= htmlspecialchars($id, ENT_QUOTES, 'UTF-8') ?>"
                                           name="<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>"
                                           value="1"
                                           <?= $item['value'] ? 'checked' : '' ?>
                                           <?= $writable ? '' : 'disabled' ?>>
                                    <label class="form-check-label fw-medium"
                                           for="<?= htmlspecialchars($id, ENT_QUOTES, 'UTF-8') ?>">
                                        <?= htmlspecialchars(t($item['label']), ENT_QUOTES, 'UTF-8') ?>
                                    </label>
                                </div>
                            <?php else: ?>
                                <label class="form-label fw-medium"
                                       for="<?= htmlspecialchars($id, ENT_QUOTES, 'UTF-8') ?>">
                                    <?= htmlspecialchars(t($item['label']), ENT_QUOTES, 'UTF-8') ?>
                                </label>
                                <input class="form-control" style="max-width: 12rem;"
                                       type="<?= $item['type'] === 'int' ? 'number' : 'text' ?>"
                                       <?php if ($item['type'] === 'int'): ?>
                                           min="<?= (int)($item['min'] ?? 0) ?>" max="<?= (int)($item['max'] ?? 999999) ?>"
                                       <?php endif; ?>
                                       id="<?= htmlspecialchars($id, ENT_QUOTES, 'UTF-8') ?>"
                                       name="<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>"
                                       value="<?= htmlspecialchars((string)$item['value'], ENT_QUOTES, 'UTF-8') ?>"
                                       <?= $writable ? '' : 'disabled' ?>>
                            <?php endif; ?>

                            <div class="form-text ms-1">
                                <?= htmlspecialchars(t($item['hint']), ENT_QUOTES, 'UTF-8') ?>
                            </div>

                            <div class="small text-muted ms-1 mt-1">
                                <code><?= htmlspecialchars('[' . $item['section'] . '] ' . $item['key'], ENT_QUOTES, 'UTF-8') ?></code>
                                <?php if (!$item['present']): ?>
                                    <span class="badge bg-secondary-subtle text-secondary-emphasis ms-1">
                                        <?= htmlspecialchars(t('not set — showing the built-in default'), ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>

        <?php if ($writable): ?>
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-check2 me-1"></i><?= htmlspecialchars(t('Save settings'), ENT_QUOTES, 'UTF-8') ?>
            </button>
        <?php endif; ?>
    </form>
</div>
