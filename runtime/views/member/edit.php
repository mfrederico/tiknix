<div class="container py-4">
    <h1 class="h2 mb-4">Edit Profile</h1>
    
    <div class="row">
        <div class="col-md-8">
            <div class="card">
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
                        
                        <h5 class="mb-3">Account Information</h5>
                        
                        <div class="mb-3">
                            <label for="username" class="form-label">Username</label>
                            <input type="text" class="form-control" id="username" value="<?= htmlspecialchars(($member->username) ?? '') ?>" readonly>
                            <small class="form-text text-muted">Username cannot be changed</small>
                        </div>
                        
                        <div class="mb-3">
                            <label for="email" class="form-label">Email Address</label>
                            <input type="email" class="form-control" id="email" name="email" 
                                   value="<?= htmlspecialchars(($member->email) ?? '') ?>" required>
                        </div>
                        
                        <hr class="my-4">
                        
                        <h5 class="mb-3">Change Password</h5>
                        <p class="text-muted">Leave blank to keep current password</p>
                        
                        <?php
                        /* autocomplete="new-password" on the CURRENT password box is deliberate,
                           not a copy-paste slip. The semantically correct "current-password" is
                           an instruction to password managers to fill it — which is precisely
                           what we don't want here: this is a profile form, the password section
                           is optional, and a box that fills itself made people think they were
                           being asked for a password just to change their name.
                           autocomplete="off" does not work; browsers ignore it on password
                           inputs. "new-password" is the value they actually honour. */
                        ?>
                        <div class="mb-3">
                            <label for="current_password" class="form-label">Current Password</label>
                            <input type="password" class="form-control" id="current_password" name="current_password"
                                   autocomplete="new-password" data-lpignore="true" data-1p-ignore data-bwignore>
                        </div>
                        
                        <div class="mb-3">
                            <label for="password" class="form-label">New Password</label>
                            <input type="password" class="form-control" id="password" name="password" autocomplete="new-password">
                            <small class="form-text text-muted">Minimum 8 characters</small>
                        </div>
                        
                        <div class="mb-3">
                            <label for="password_confirm" class="form-label">Confirm New Password</label>
                            <input type="password" class="form-control" id="password_confirm" name="password_confirm" autocomplete="new-password">
                        </div>
                        
                        <hr class="my-4">
                        
                        <h5 class="mb-3">Profile Details</h5>
                        
                        <div class="mb-3">
                            <label for="display_name" class="form-label">Display Name</label>
                            <input type="text" class="form-control" id="display_name" name="display_name"
                                   value="<?= htmlspecialchars(($member->displayName) ?? '') ?>"
                                   maxlength="60" autocomplete="name">
                            <div class="form-text">Shown to other people — including in "X invited you" emails. Leave blank to use your name or username.</div>
                        </div>
                        <div class="mb-3">
                            <label for="first_name" class="form-label">First Name</label>
                            <input type="text" class="form-control" id="first_name" name="first_name"
                                   value="<?= htmlspecialchars((string)($member->firstName ?? '')) ?>">
                        </div>

                        <div class="mb-3">
                            <label for="last_name" class="form-label">Last Name</label>
                            <input type="text" class="form-control" id="last_name" name="last_name"
                                   value="<?= htmlspecialchars((string)($member->lastName ?? '')) ?>">
                        </div>

                        <div class="mb-3">
                            <label for="bio" class="form-label">Bio</label>
                            <textarea class="form-control" id="bio" name="bio" rows="3"><?= htmlspecialchars($member->bio ?? '') ?></textarea>
                        </div>
                        
                        <div class="d-flex justify-content-between">
                            <a href="/member/profile" class="btn btn-secondary">Cancel</a>
                            <button type="submit" class="btn btn-primary">Save Changes</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        
        <div class="col-md-4">
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title">Account Status</h5>
                    <dl>
                        <dt>Account Level</dt>
                        <dd>
                            <?= level_name((int)$member->level) ?>
                        </dd>
                        
                        <dt>Status</dt>
                        <dd><?= htmlspecialchars(($member->status) ?? '') ?></dd>
                        
                        <dt>Member Since</dt>
                        <dd><?= date('F j, Y', strtotime($member->created_at ?? 'now')) ?></dd>
                        
                        <dt>Last Updated</dt>
                        <dd><?= date('F j, Y', strtotime($member->updated_at ?? 'now')) ?></dd>
                    </dl>
                </div>
            </div>
            
            <div class="card mt-3">
                <div class="card-body">
                    <h5 class="card-title">Password Requirements</h5>
                    <ul class="small">
                        <li>Minimum 8 characters</li>
                        <li>Mix of letters and numbers recommended</li>
                        <li>Special characters encouraged</li>
                        <li>Avoid common words</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>