<?php // ui: hand-made — a settings card: a choice per kind of message and channel
/** @var array $rows @var array $scopes @var ?string $scopeCategory @var ?bool $digest @var ?string $digestCategory @var array $text */
$h = fn($v) => htmlspecialchars((string) $v);
?>
<div class="page-col">
<?= \app\Ui::page(['title' => 'What reaches you', 'lead' => 'Every message we send belongs to one of these. Choose how each one reaches you.',
    'back' => ['label' => 'Settings', 'url' => '/me/settings']]) ?>
<div class="card mb-4" id="reaches"><div class="card-body">
    <?php if ($text['offered']): ?>
    <p class="text-muted small mb-3">Texts are your choice and start off. They cost you nothing from us, and each kind sends about one text a week at most.
        Every text ends with "Reply STOP or:" and a link that stops them. Replying STOP is not read yet, so the link is the way to stop them.
        <?= $text['connected'] ? '' : ' Texting is not set up on this site yet, so choosing texts cannot send you a code.' ?>
        <?= $text['verified'] ? '' : ($text['canProve'] ? ' The first time, we text a code to the phone on your profile to confirm it is yours.' : ' This site cannot confirm a phone number yet, so texts cannot be turned on.') ?></p>
    <?php else: ?>
    <p class="text-muted small mb-3">This site sends email only.</p>
    <?php endif; ?>
    <?php if ($text['pending'] !== null): ?>
        <form method="post" action="/msgpref/textcode" class="border rounded p-3 mb-3"><?= csrf_field() ?>
            <label class="form-label fw-semibold" for="textcode">Code we texted to <?= $h($text['pending']) ?></label>
            <div class="d-flex flex-wrap gap-2">
                <input class="form-control w-auto" id="textcode" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="10" required>
                <button class="btn btn-primary" type="submit">Turn texts on</button>
                <button class="btn btn-outline-secondary" type="submit" name="cancel" value="1" formnovalidate>Keep texts off</button>
            </div>
        </form>
    <?php endif; ?>
    <form method="post" action="/msgpref/save"><?= csrf_field() ?>
        <?php foreach ($rows as $row): ?>
            <div class="mb-3">
                <div class="fw-semibold"><?= $h($row['label']) ?></div>
                <div class="text-muted small mb-1"><?= $h($row['description']) ?></div>
                <?php if (!$row['canOptOut']): ?>
                    <span class="badge text-bg-secondary">Always on</span>
                <?php else: foreach ($row['channels'] as $ch => $on): $nm = 'msg[' . $row['code'] . '][' . $ch . ']'; ?>
                    <div class="d-flex align-items-center gap-3 mb-1">
                        <span class="text-muted" style="min-width:3.5rem"><?= $h(ucfirst($ch)) ?></span>
                        <div class="form-check form-check-inline mb-0"><input class="form-check-input" type="radio" name="<?= $h($nm) ?>" id="<?= $h($row['code'] . '-' . $ch) ?>-1" value="1"<?= $on ? ' checked' : '' ?>><label class="form-check-label" for="<?= $h($row['code'] . '-' . $ch) ?>-1">On</label></div>
                        <div class="form-check form-check-inline mb-0"><input class="form-check-input" type="radio" name="<?= $h($nm) ?>" id="<?= $h($row['code'] . '-' . $ch) ?>-0" value="0"<?= $on ? '' : ' checked' ?>><label class="form-check-label" for="<?= $h($row['code'] . '-' . $ch) ?>-0">Off</label></div>
                    </div>
                <?php endforeach; endif; ?>
                <?php $hasDigest = $digest !== null && $row['code'] === $digestCategory; $hasScopes = $scopeCategory !== null && $row['code'] === $scopeCategory; ?>
                <?php if ($hasDigest || $hasScopes): ?>
                    <div class="mt-2 ps-3 border-start">
                        <?php if ($hasDigest): ?>
                        <input type="hidden" name="digest" value="0">
                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input" type="checkbox" role="switch" id="digest" name="digest" value="1"<?= $digest ? ' checked' : '' ?>>
                            <label class="form-check-label" for="digest">Weekly digest</label>
                        </div>
                        <?php endif; ?>
                        <?php if ($hasScopes): if ($scopes === []): ?>
                            <div class="text-muted small">You are not in any group yet.</div>
                        <?php else: foreach ($scopes as $i => $s): ?>
                            <input type="hidden" name="notify[<?= $h($s['key']) ?>]" value="0">
                            <div class="form-check form-switch mb-1">
                                <input class="form-check-input" type="checkbox" role="switch" id="notify-<?= (int) $i ?>" name="notify[<?= $h($s['key']) ?>]" value="1"<?= $s['on'] ? ' checked' : '' ?>>
                                <label class="form-check-label" for="notify-<?= (int) $i ?>">Notify me about <?= $h($s['label']) ?></label>
                            </div>
                        <?php endforeach; endif; endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        <button class="btn btn-primary" type="submit">Save</button>
    </form>
</div></div>
</div>
