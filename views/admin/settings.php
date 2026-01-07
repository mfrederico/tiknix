<div class="container py-4">
    <h1 class="h2 mb-4">System Settings</h1>
    
    <?php if (!empty($error)): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    
    <?php if (!empty($success)): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    
    <div class="card">
        <div class="card-header">
            <h5>Application Settings</h5>
        </div>
        <div class="card-body">
            <form method="POST">
                <?php if (!empty($csrf) && is_array($csrf)): ?>
                    <?php foreach ($csrf as $name => $value): ?>
                        <input type="hidden" name="<?= htmlspecialchars($name) ?>" value="<?= htmlspecialchars($value) ?>">
                    <?php endforeach; ?>
                <?php endif; ?>
                
                <div class="mb-3">
                    <label for="site_name" class="form-label">Site Name</label>
                    <input type="text" class="form-control" id="site_name" name="site_name" 
                           value="<?= htmlspecialchars(Flight::getSetting('site_name', 0) ?? 'TikNix') ?>">
                </div>
                
                <div class="mb-3">
                    <label for="site_description" class="form-label">Site Description</label>
                    <textarea class="form-control" id="site_description" name="site_description" rows="3"><?= htmlspecialchars(Flight::getSetting('site_description', 0) ?? '') ?></textarea>
                </div>
                
                <div class="mb-3">
                    <label for="maintenance_mode" class="form-label">Maintenance Mode</label>
                    <select class="form-select" id="maintenance_mode" name="maintenance_mode">
                        <option value="0" <?= Flight::getSetting('maintenance_mode', 0) == '0' ? 'selected' : '' ?>>Disabled</option>
                        <option value="1" <?= Flight::getSetting('maintenance_mode', 0) == '1' ? 'selected' : '' ?>>Enabled</option>
                    </select>
                    <small class="form-text text-muted">When enabled, only admins can access the site</small>
                </div>
                
                <div class="mb-3">
                    <label for="registration_enabled" class="form-label">User Registration</label>
                    <select class="form-select" id="registration_enabled" name="registration_enabled">
                        <option value="1" <?= Flight::getSetting('registration_enabled', 0) != '0' ? 'selected' : '' ?>>Enabled</option>
                        <option value="0" <?= Flight::getSetting('registration_enabled', 0) == '0' ? 'selected' : '' ?>>Disabled</option>
                    </select>
                </div>
                
                <div class="mb-3">
                    <label for="default_user_level" class="form-label">Default User Level</label>
                    <select class="form-select" id="default_user_level" name="default_user_level">
                        <option value="100" <?= Flight::getSetting('default_user_level', 0) == '100' ? 'selected' : '' ?>>MEMBER (100)</option>
                        <option value="101" <?= Flight::getSetting('default_user_level', 0) == '101' ? 'selected' : '' ?>>PUBLIC (101)</option>
                    </select>
                    <small class="form-text text-muted">Level assigned to new registrations</small>
                </div>
                
                <div class="mb-3">
                    <label for="session_timeout" class="form-label">Session Timeout (minutes)</label>
                    <input type="number" class="form-control" id="session_timeout" name="session_timeout" 
                           value="<?= htmlspecialchars(Flight::getSetting('session_timeout', 0) ?? '60') ?>">
                </div>
                
                <div class="mb-3">
                    <label for="debug_mode" class="form-label">Debug Mode</label>
                    <select class="form-select" id="debug_mode" name="debug_mode">
                        <option value="0" <?= Flight::getSetting('debug_mode', 0) == '0' ? 'selected' : '' ?>>Production</option>
                        <option value="1" <?= Flight::getSetting('debug_mode', 0) == '1' ? 'selected' : '' ?>>Development</option>
                    </select>
                    <small class="form-text text-muted">Shows detailed error messages when enabled</small>
                </div>
                
                <button type="submit" class="btn btn-primary">Save Settings</button>
            </form>
        </div>
    </div>

    <div class="card mt-4">
        <div class="card-header">
            <h5>Two-Factor Authentication Settings</h5>
        </div>
        <div class="card-body">
            <form method="POST">
                <?php if (!empty($csrf) && is_array($csrf)): ?>
                    <?php foreach ($csrf as $name => $value): ?>
                        <input type="hidden" name="<?= htmlspecialchars($name) ?>" value="<?= htmlspecialchars($value) ?>">
                    <?php endforeach; ?>
                <?php endif; ?>

                <div class="mb-3">
                    <label for="twofa_ip_whitelist" class="form-label">2FA IP Whitelist</label>
                    <textarea class="form-control font-monospace" id="twofa_ip_whitelist" name="twofa_ip_whitelist" rows="4" placeholder="127.0.0.1&#10;192.168.1.0/24&#10;10.0.0.0/8"><?= htmlspecialchars(Flight::getSetting('twofa_ip_whitelist', 0) ?? '') ?></textarea>
                    <small class="form-text text-muted">
                        One IP or CIDR range per line. Requests from these IPs will bypass 2FA verification.<br>
                        <strong>Security note:</strong> Only add trusted internal/VPN IPs. Never whitelist public IPs.
                    </small>
                </div>

                <div class="mb-3">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="twofa_whitelist_enabled" name="twofa_whitelist_enabled" value="1" <?= Flight::getSetting('twofa_whitelist_enabled', 0) == '1' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="twofa_whitelist_enabled">
                            Enable IP whitelist bypass
                        </label>
                    </div>
                    <small class="form-text text-muted">When enabled, IPs in the whitelist skip 2FA verification (not setup)</small>
                </div>

                <div class="alert alert-info mb-3">
                    <strong>Current Request IP:</strong> <code><?= htmlspecialchars($_SERVER['REMOTE_ADDR'] ?? 'unknown') ?></code>
                </div>

                <button type="submit" class="btn btn-primary">Save 2FA Settings</button>
            </form>
        </div>
    </div>
    
    <?php if (!empty($settings)): ?>
        <div class="card mt-4">
            <div class="card-header">
                <h5>All System Settings</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Key</th>
                                <th>Value</th>
                                <th>Updated</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($settings as $setting): ?>
                                <tr>
                                    <td><code><?= htmlspecialchars($setting->setting_key) ?></code></td>
                                    <td><?= htmlspecialchars(substr($setting->setting_value, 0, 100)) ?></td>
                                    <td><?= $setting->updated_at ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>