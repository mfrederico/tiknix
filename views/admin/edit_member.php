<?php
/**
 * Admin member editor.
 *
 * Two halves: everything an admin can CHANGE on the left, everything about the account
 * they can only LOOK AT on the right. Keeping them apart matters here — the read-only
 * side is the evidence you use to decide what to change (who invited them, whether
 * they've ever logged in, whether they've built anything yet), and mixing it into the
 * form makes it look editable when it isn't.
 */
$isSystem = ($editMember->id == PUBLIC_USER_ID || $editMember->id == SYSTEM_ADMIN_ID);

/** Dates in this table are written by different code paths; some are empty, some are epochs. */
$when = function ($v, string $fmt = 'Y-m-d H:i') {
    if (empty($v)) return null;
    $t = is_numeric($v) ? (int) $v : strtotime((string) $v);
    return $t ? date($fmt, $t) : null;
};
?>
<div class="container py-4">
    <div class="row justify-content-center g-4">
        <div class="col-lg-7">
            <div class="card">
                <div class="card-header">
                    <h4 class="mb-0"><?= htmlspecialchars(($title) ?? '') ?></h4>
                </div>
                <div class="card-body">
                    <?php if (!empty($error)): ?>
                        <div class="alert alert-danger"><?= htmlspecialchars(($error) ?? '') ?></div>
                    <?php endif; ?>

                    <?php if (!empty($success)): ?>
                        <div class="alert alert-success"><?= htmlspecialchars(($success) ?? '') ?></div>
                    <?php endif; ?>

                    <form method="POST">
                        <?php if (!empty($csrf) && is_array($csrf)): ?>
                            <?php foreach ($csrf as $name => $value): ?>
                                <input type="hidden" name="<?= htmlspecialchars(($name) ?? '') ?>" value="<?= htmlspecialchars(($value) ?? '') ?>">
                            <?php endforeach; ?>
                        <?php endif; ?>

                        <h5 class="mb-3">Account</h5>

                        <div class="mb-3">
                            <label for="username" class="form-label">Username</label>
                            <input type="text" class="form-control" id="username" name="username"
                                   value="<?= htmlspecialchars(($editMember->username) ?? '') ?>" required
                                   <?= $isSystem ? 'readonly' : '' ?>>
                        </div>

                        <div class="mb-3">
                            <label for="email" class="form-label">Email</label>
                            <input type="email" class="form-control" id="email" name="email"
                                   value="<?= htmlspecialchars(($editMember->email) ?? '') ?>" required>
                        </div>

                        <div class="mb-3">
                            <label for="password" class="form-label">Password</label>
                            <?php /* Same reasoning as the member's own profile form: this box is
                                     optional, so a password manager filling it is noise. */ ?>
                            <input type="password" class="form-control" id="password" name="password"
                                   autocomplete="new-password" data-lpignore="true" data-1p-ignore data-bwignore>
                            <small class="form-text text-muted">Leave blank to keep current password</small>
                        </div>

                        <div class="row">
                            <div class="col-sm-6 mb-3">
                                <label for="level" class="form-label">User Level</label>
                                <select class="form-select" id="level" name="level" required <?= $isSystem ? 'disabled' : '' ?>>
                                    <option value="0" <?= $editMember->level == 0 ? 'selected' : '' ?>>ROOT (0)</option>
                                    <option value="1" <?= $editMember->level == 1 ? 'selected' : '' ?>>ROOT (1)</option>
                                    <option value="50" <?= $editMember->level == 50 ? 'selected' : '' ?>>ADMIN (50)</option>
                                    <option value="100" <?= $editMember->level == 100 ? 'selected' : '' ?>>MEMBER (100)</option>
                                    <option value="101" <?= $editMember->level == 101 ? 'selected' : '' ?>>PUBLIC (101)</option>
                                </select>
                                <?php if ($isSystem): ?>
                                    <input type="hidden" name="level" value="101">
                                <?php endif; ?>
                            </div>

                            <div class="col-sm-6 mb-3">
                                <label for="status" class="form-label">Status</label>
                                <select class="form-select" id="status" name="status" required <?= $isSystem ? 'disabled' : '' ?>>
                                    <option value="active" <?= $editMember->status === 'active' ? 'selected' : '' ?>>Active</option>
                                    <option value="suspended" <?= $editMember->status === 'suspended' ? 'selected' : '' ?>>Suspended</option>
                                    <option value="inactive" <?= $editMember->status === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                                </select>
                                <?php if ($isSystem): ?>
                                    <input type="hidden" name="status" value="active">
                                    <small class="form-text text-muted">System user - status cannot be changed</small>
                                <?php endif; ?>
                            </div>
                        </div>

                        <hr class="my-4">
                        <h5 class="mb-3">Profile</h5>

                        <div class="mb-3">
                            <label for="display_name" class="form-label">Display Name</label>
                            <input type="text" class="form-control" id="display_name" name="display_name"
                                   value="<?= htmlspecialchars(($editMember->displayName) ?? '') ?>" maxlength="60">
                            <small class="form-text text-muted">
                                What other people see &mdash; including in &ldquo;X invited you&rdquo; emails.
                                Blank falls back to their name, then their username.
                            </small>
                        </div>

                        <div class="row">
                            <div class="col-sm-6 mb-3">
                                <label for="first_name" class="form-label">First Name</label>
                                <input type="text" class="form-control" id="first_name" name="first_name"
                                       value="<?= htmlspecialchars(($editMember->firstName) ?? '') ?>">
                            </div>
                            <div class="col-sm-6 mb-3">
                                <label for="last_name" class="form-label">Last Name</label>
                                <input type="text" class="form-control" id="last_name" name="last_name"
                                       value="<?= htmlspecialchars(($editMember->lastName) ?? '') ?>">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="bio" class="form-label">Bio</label>
                            <textarea class="form-control" id="bio" name="bio" rows="3"><?= htmlspecialchars(($editMember->bio) ?? '') ?></textarea>
                        </div>

                        <div class="mb-3">
                            <label for="avatar_url" class="form-label">Avatar URL</label>
                            <input type="url" class="form-control" id="avatar_url" name="avatar_url"
                                   value="<?= htmlspecialchars(($editMember->avatarUrl) ?? '') ?>"
                                   placeholder="https://…">
                            <small class="form-text text-muted">Set automatically when they sign in with Google.</small>
                        </div>

                        <?php if (!empty($featureFlags)): ?>
                        <hr class="my-4">
                        <h5 class="mb-3">Features</h5>
                        <div class="mb-3">
                            <div class="border rounded p-2">
                                <?php foreach ($featureFlags as $ff): ?>
                                <div class="form-check form-switch mb-2">
                                    <input class="form-check-input" type="checkbox" role="switch"
                                           id="feature_<?= htmlspecialchars(($ff['key']) ?? '') ?>"
                                           name="features[<?= htmlspecialchars(($ff['key']) ?? '') ?>]" value="1"
                                           <?= !empty($ff['enabled']) ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="feature_<?= htmlspecialchars(($ff['key']) ?? '') ?>">
                                        <?= htmlspecialchars(($ff['label']) ?? '') ?>
                                    </label>
                                    <div class="form-text mt-0"><?= htmlspecialchars(($ff['blurb']) ?? '') ?></div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <small class="form-text text-muted">Only features available to this member's level are shown.</small>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($twofaEnabled)): ?>
                        <hr class="my-4">
                        <h5 class="mb-3">Two-Factor Authentication</h5>
                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="reset_2fa" name="reset_2fa" value="1">
                                <label class="form-check-label" for="reset_2fa">
                                    Reset two-factor authentication
                                </label>
                                <div class="form-text">
                                    For someone who has lost their authenticator. Clears their secret and
                                    recovery codes; they set it up again at their next login.
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <div class="d-flex justify-content-between mt-4">
                            <a href="/admin/members" class="btn btn-secondary">Cancel</a>
                            <button type="submit" class="btn btn-primary">Update Member</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card">
                <div class="card-header"><strong>Account</strong></div>
                <div class="card-body">
                    <dl class="row mb-0 small">
                        <dt class="col-5">ID</dt>
                        <dd class="col-7"><?= (int) $editMember->id ?></dd>

                        <dt class="col-5">Created</dt>
                        <dd class="col-7"><?= htmlspecialchars($when($editMember->createdAt, 'Y-m-d') ?? '—') ?></dd>

                        <dt class="col-5">Updated</dt>
                        <dd class="col-7"><?= htmlspecialchars($when($editMember->updatedAt) ?? '—') ?></dd>

                        <dt class="col-5">Last login</dt>
                        <dd class="col-7">
                            <?php $ll = $when($editMember->lastLogin); ?>
                            <?php if ($ll): ?>
                                <?= htmlspecialchars($ll) ?>
                            <?php else: ?>
                                <span class="text-danger">Never</span>
                            <?php endif; ?>
                        </dd>

                        <dt class="col-5">Logins</dt>
                        <dd class="col-7"><?= (int) ($editMember->loginCount ?? 0) ?></dd>

                        <dt class="col-5">Two-factor</dt>
                        <dd class="col-7">
                            <?php if (!empty($twofaEnabled)): ?>
                                <span class="badge bg-success">Enabled</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">Off</span>
                            <?php endif; ?>
                        </dd>

                        <?php if (!empty($editMember->needsPasswordSetup)): ?>
                        <dt class="col-5">Password</dt>
                        <dd class="col-7"><span class="badge bg-warning text-dark">Not set yet</span></dd>
                        <?php endif; ?>
                    </dl>
                </div>
            </div>

            <div class="card mt-4">
                <div class="card-header"><strong>How they got here</strong></div>
                <div class="card-body">
                    <?php if (!empty($invitedBy)): ?>
                        <div class="small text-muted mb-1">Invited by</div>
                        <div class="mb-2">
                            <a href="/admin/editMember?id=<?= (int) $invitedBy->id ?>">
                                <?= htmlspecialchars((string) ($invitedBy->displayName ?: ($invitedBy->username ?: $invitedBy->email))) ?>
                            </a>
                            <div class="small text-muted"><?= htmlspecialchars((string) $invitedBy->email) ?></div>
                        </div>
                        <?php $ia = $when($editMember->invitedAt, 'j M Y'); ?>
                        <?php if ($ia): ?>
                            <div class="small text-muted">on <?= htmlspecialchars($ia) ?></div>
                        <?php endif; ?>
                    <?php else: ?>
                        <?php /* Not a gap in the record — accounts created before invites existed,
                                 and any an admin made by hand, genuinely have no inviter. */ ?>
                        <div class="small text-muted">
                            No inviter on record &mdash; this account was created directly.
                        </div>
                    <?php endif; ?>

                    <hr>

                    <div class="small text-muted mb-1">First build</div>
                    <?php $fb = $when($editMember->firstBuildAt, 'j M Y'); ?>
                    <?php if ($fb): ?>
                        <div><?= htmlspecialchars($fb) ?></div>
                    <?php else: ?>
                        <?php /* Worth saying plainly: this is the condition that unlocks their
                                 own invitations, so it is the answer to "why can't they invite?" */ ?>
                        <div class="text-warning-emphasis">
                            Hasn't built anything yet &mdash; their invitations stay locked until they do.
                        </div>
                    <?php endif; ?>

                    <hr>

                    <div class="small text-muted mb-1">People they've invited</div>
                    <div><?= (int) ($invitedCount ?? 0) ?></div>
                </div>
            </div>
        </div>
    </div>
</div>
