<div class="container py-4">
    <h1 class="h2 mb-4">Settings</h1>
    
    <div class="row">
        <div class="col-md-8">
            <div class="card">
                <div class="card-body">
                    <?php if (!empty($error)): ?>
                        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                    <?php endif; ?>
                    
                    <?php if (!empty($success)): ?>
                        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
                    <?php endif; ?>
                    
                    <form method="POST">
                        <?php if (!empty($csrf) && is_array($csrf)): ?>
                            <?php foreach ($csrf as $name => $value): ?>
                                <input type="hidden" name="<?= htmlspecialchars($name) ?>" value="<?= htmlspecialchars($value) ?>">
                            <?php endforeach; ?>
                        <?php endif; ?>
                        
                        <h5 class="mb-3">Notification Settings</h5>
                        
                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="email_notifications" 
                                       name="email_notifications" value="1"
                                       <?= Flight::getSetting('email_notifications', $member->id) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="email_notifications">
                                    Email notifications
                                </label>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="newsletter" 
                                       name="newsletter" value="1"
                                       <?= Flight::getSetting('newsletter', $member->id) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="newsletter">
                                    Subscribe to newsletter
                                </label>
                            </div>
                        </div>
                        
                        <hr class="my-4">
                        
                        <h5 class="mb-3">Privacy Settings</h5>
                        
                        <div class="mb-3">
                            <label for="profile_visibility" class="form-label">Profile Visibility</label>
                            <select class="form-select" id="profile_visibility" name="profile_visibility">
                                <option value="public" <?= Flight::getSetting('profile_visibility', $member->id) == 'public' ? 'selected' : '' ?>>Public</option>
                                <option value="members" <?= Flight::getSetting('profile_visibility', $member->id) == 'members' ? 'selected' : '' ?>>Members Only</option>
                                <option value="private" <?= Flight::getSetting('profile_visibility', $member->id) == 'private' ? 'selected' : '' ?>>Private</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="show_email" 
                                       name="show_email" value="1"
                                       <?= Flight::getSetting('show_email', $member->id) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="show_email">
                                    Show email address on profile
                                </label>
                            </div>
                        </div>
                        
                        <hr class="my-4">

                        <h5 class="mb-3">Two-Factor Authentication</h5>

                        <?php if (!empty($_SESSION['flash_error'])): ?>
                            <div class="alert alert-danger"><?= htmlspecialchars($_SESSION['flash_error']) ?></div>
                            <?php unset($_SESSION['flash_error']); ?>
                        <?php endif; ?>

                        <?php if (!empty($_SESSION['flash_success'])): ?>
                            <div class="alert alert-success"><?= htmlspecialchars($_SESSION['flash_success']) ?></div>
                            <?php unset($_SESSION['flash_success']); ?>
                        <?php endif; ?>

                        <div class="card mb-3 <?= $twofa_enabled ? 'border-success' : 'border-secondary' ?>">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="mb-1">
                                            <?php if ($twofa_enabled): ?>
                                                <i class="bi bi-shield-check text-success"></i> 2FA Enabled
                                            <?php else: ?>
                                                <i class="bi bi-shield text-muted"></i> 2FA Disabled
                                            <?php endif; ?>
                                        </h6>
                                        <small class="text-muted">
                                            <?php if ($twofa_required_reason === 'admin'): ?>
                                                Required for admin accounts
                                            <?php elseif ($twofa_required_reason === 'workbench'): ?>
                                                Required for workbench access
                                            <?php else: ?>
                                                Adds an extra layer of security
                                            <?php endif; ?>
                                        </small>
                                    </div>
                                    <div>
                                        <?php if ($twofa_enabled): ?>
                                            <?php if (!$twofa_required): ?>
                                                <button type="button" class="btn btn-outline-danger btn-sm"
                                                        data-bs-toggle="modal" data-bs-target="#disable2faModal">
                                                    Disable
                                                </button>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <a href="/member/setup2fa" class="btn btn-primary btn-sm">Enable 2FA</a>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <?php if ($twofa_enabled): ?>
                                <hr class="my-2">
                                <div class="d-flex justify-content-between align-items-center">
                                    <small>
                                        <i class="bi bi-key"></i>
                                        <?= $recovery_code_count ?> recovery codes remaining
                                    </small>
                                    <button type="button" class="btn btn-outline-secondary btn-sm"
                                            data-bs-toggle="modal" data-bs-target="#regenerateCodesModal">
                                        Regenerate Codes
                                    </button>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <hr class="my-4">

                        <h5 class="mb-3">Display Settings</h5>
                        
                        <div class="mb-3">
                            <label for="timezone" class="form-label">Timezone</label>
                            <select class="form-select" id="timezone" name="timezone">
                                <option value="UTC" <?= Flight::getSetting('timezone', $member->id) == 'UTC' ? 'selected' : '' ?>>UTC</option>
                                <option value="America/New_York" <?= Flight::getSetting('timezone', $member->id) == 'America/New_York' ? 'selected' : '' ?>>Eastern Time</option>
                                <option value="America/Chicago" <?= Flight::getSetting('timezone', $member->id) == 'America/Chicago' ? 'selected' : '' ?>>Central Time</option>
                                <option value="America/Denver" <?= Flight::getSetting('timezone', $member->id) == 'America/Denver' ? 'selected' : '' ?>>Mountain Time</option>
                                <option value="America/Los_Angeles" <?= Flight::getSetting('timezone', $member->id) == 'America/Los_Angeles' ? 'selected' : '' ?>>Pacific Time</option>
                                <option value="Europe/London" <?= Flight::getSetting('timezone', $member->id) == 'Europe/London' ? 'selected' : '' ?>>London</option>
                                <option value="Europe/Paris" <?= Flight::getSetting('timezone', $member->id) == 'Europe/Paris' ? 'selected' : '' ?>>Paris</option>
                                <option value="Asia/Tokyo" <?= Flight::getSetting('timezone', $member->id) == 'Asia/Tokyo' ? 'selected' : '' ?>>Tokyo</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label for="date_format" class="form-label">Date Format</label>
                            <select class="form-select" id="date_format" name="date_format">
                                <option value="Y-m-d" <?= Flight::getSetting('date_format', $member->id) == 'Y-m-d' ? 'selected' : '' ?>>2024-12-25</option>
                                <option value="m/d/Y" <?= Flight::getSetting('date_format', $member->id) == 'm/d/Y' ? 'selected' : '' ?>>12/25/2024</option>
                                <option value="d/m/Y" <?= Flight::getSetting('date_format', $member->id) == 'd/m/Y' ? 'selected' : '' ?>>25/12/2024</option>
                                <option value="F j, Y" <?= Flight::getSetting('date_format', $member->id) == 'F j, Y' ? 'selected' : '' ?>>December 25, 2024</option>
                            </select>
                        </div>
                        
                        <button type="submit" class="btn btn-primary">Save Settings</button>
                        <a href="/member/profile" class="btn btn-secondary">Cancel</a>
                    </form>
                </div>
            </div>
        </div>
        
        <div class="col-md-4">
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title">Quick Links</h5>
                    <div class="list-group">
                        <a href="/member/profile" class="list-group-item list-group-item-action">View Profile</a>
                        <a href="/member/edit" class="list-group-item list-group-item-action">Edit Profile</a>
                        <a href="/member/dashboard" class="list-group-item list-group-item-action">Dashboard</a>
                        <a href="/apikeys" class="list-group-item list-group-item-action">
                            <i class="bi bi-key"></i> API Keys
                        </a>
                        <?php if ($member->level <= 50): ?>
                            <a href="/admin/settings" class="list-group-item list-group-item-action">System Settings</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <?php if (!empty($settings)): ?>
            <div class="card mt-3">
                <div class="card-body">
                    <h5 class="card-title">Current Settings</h5>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <tbody>
                                <?php foreach ($settings as $setting): ?>
                                    <tr>
                                        <td><small><?= htmlspecialchars($setting->setting_key) ?></small></td>
                                        <td><small><?= htmlspecialchars(substr($setting->setting_value, 0, 20)) ?></small></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Disable 2FA Modal -->
<div class="modal fade" id="disable2faModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="/member/disable2fa">
                <?php if (!empty($csrf) && is_array($csrf)): ?>
                    <?php foreach ($csrf as $name => $value): ?>
                        <input type="hidden" name="<?= htmlspecialchars($name) ?>" value="<?= htmlspecialchars($value) ?>">
                    <?php endforeach; ?>
                <?php endif; ?>
                <div class="modal-header">
                    <h5 class="modal-title">Disable Two-Factor Authentication</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle"></i>
                        Disabling 2FA will make your account less secure.
                    </div>
                    <div class="mb-3">
                        <label for="disable_password" class="form-label">Enter your password to confirm</label>
                        <input type="password" class="form-control" id="disable_password" name="password" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Disable 2FA</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Regenerate Codes Modal -->
<div class="modal fade" id="regenerateCodesModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="/member/regenerateCodes">
                <?php if (!empty($csrf) && is_array($csrf)): ?>
                    <?php foreach ($csrf as $name => $value): ?>
                        <input type="hidden" name="<?= htmlspecialchars($name) ?>" value="<?= htmlspecialchars($value) ?>">
                    <?php endforeach; ?>
                <?php endif; ?>
                <div class="modal-header">
                    <h5 class="modal-title">Regenerate Recovery Codes</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i>
                        This will invalidate all existing recovery codes and generate new ones.
                    </div>
                    <div class="mb-3">
                        <label for="regen_password" class="form-label">Enter your password to confirm</label>
                        <input type="password" class="form-control" id="regen_password" name="password" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Generate New Codes</button>
                </div>
            </form>
        </div>
    </div>
</div>