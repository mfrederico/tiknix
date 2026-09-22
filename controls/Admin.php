<?php
namespace app;

use \Flight as Flight;
use \RedBeanPHP\R as R;
use \app\Bean;
use \Exception as Exception;
use app\BaseControls\Control;

class Admin extends Control {
    
    const ROOT_LEVEL = 1;
    const ADMIN_LEVEL = 50;
    const MEMBER_LEVEL = 100;
    const PUBLIC_LEVEL = 101;

    public function __construct() {
        parent::__construct();
        
        // Check if user is logged in
        if (!Flight::isLoggedIn()) {
            Flight::redirect('/auth/login?redirect=' . urlencode(Flight::request()->url));
            exit;
        }
        
        // Check if user has admin level
        if ($this->member->level > self::ADMIN_LEVEL) {
            $this->logger->warning('Unauthorized admin access attempt', [
                'member_id' => $this->member->id,
                'member_level' => $this->member->level,
                'ip' => Flight::request()->ip
            ]);
            Flight::redirect('/');
            exit;
        }
    }

    /**
     * Admin dashboard
     */
    public function index($params = []) {
        $this->viewData['title'] = 'Admin Dashboard';

        // Get system stats
        $this->viewData['stats'] = [
            'members' => Bean::count('member'),
            'permissions' => Bean::count('authcontrol'),
            'active_sessions' => $this->getActiveSessions(),
        ];

        // Get cache stats for dashboard (using consistent field names)
        $this->viewData['cache_stats'] = \app\PermissionCache::getStats();

        $this->render('admin/index', $this->viewData);
    }

    /**
     * /admin/login — there is no separate admin login. Send authenticated admins
     * to the panel; unauthenticated users are already redirected to /auth/login by
     * the permission layer before this runs.
     */
    public function login($params = []) {
        Flight::redirect('/admin');
    }

    /**
     * Member management
     */
    public function members($params = []) {
        $this->viewData['title'] = 'Member Management';
        
        $request = Flight::request();
        
        // Handle delete action
        if ($request->query->delete && is_numeric($request->query->delete)) {
            $this->deleteMember($request->query->delete);
            Flight::redirect('/admin/members');
            return;
        }
        
        // Handle bulk actions
        if ($request->method === 'POST' && !empty($request->data->bulk_action) && !empty($request->data->selected_members)) {
            if (Flight::csrf()->validateRequest()) {
                $this->handleBulkAction($request->data->bulk_action, $request->data->selected_members);
                Flight::redirect('/admin/members');
                return;
            }
        }
        
        // Get all members
        $this->viewData['members'] = Bean::findAll('member', 'ORDER BY created_at DESC');
        
        $this->render('admin/members', $this->viewData);
    }

    /**
     * Edit member
     */
    public function editMember($params = []) {
        $request = Flight::request();
        $memberId = $request->query->id ?? null;
        
        if (!$memberId) {
            Flight::redirect('/admin/members');
            return;
        }
        
        $member = Bean::load('member', $memberId);
        if (!$member->id) {
            Flight::redirect('/admin/members');
            return;
        }
        
        if ($request->method === 'POST') {
            // Validate CSRF
            if (!Flight::csrf()->validateRequest()) {
                $this->viewData['error'] = 'Invalid CSRF token';
            } else {
                // Validate input
                $username = trim($request->data->username ?? '');
                $email = trim($request->data->email ?? '');
                $level = intval($request->data->level ?? $member->level);
                $status = $request->data->status ?? $member->status;
                // System accounts (root admin / public user) must never have their
                // level or status changed here — the disabled <select> posts a
                // placeholder value, so keep whatever is stored.
                if ($member->id == SYSTEM_ADMIN_ID || $member->id == PUBLIC_USER_ID) {
                    $level  = (int) $member->level;
                    $status = $member->status;
                }
                
                if (empty($username)) {
                    $this->viewData['error'] = 'Username is required';
                } elseif (empty($email)) {
                    $this->viewData['error'] = 'Email is required';
                } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $this->viewData['error'] = 'Invalid email format';
                } elseif (strlen($username) < 3) {
                    $this->viewData['error'] = 'Username must be at least 3 characters long';
                } else {
                    // Check for duplicate username/email (excluding current member)
                    $existingUsername = Bean::findOne('member', 'username = ? AND id != ?', [$username, $member->id]);
                    $existingEmail = Bean::findOne('member', 'email = ? AND id != ?', [$email, $member->id]);
                    
                    if ($existingUsername) {
                        $this->viewData['error'] = 'Username already exists';
                    } elseif ($existingEmail) {
                        $this->viewData['error'] = 'Email already exists';
                    } else {
                        // Update member
                        $member->username = $username;
                        $member->email = $email;
                        $member->level = $level;
                        $member->status = $status;

                        // Profile fields. An admin editing an account should be able to
                        // fix all of it — display_name in particular, because that is the
                        // name everyone else sees ("X invited you", team lists) and a bad
                        // one was previously only fixable by the member themselves.
                        $display = trim($request->data->display_name ?? '');
                        $member->displayName = $display !== '' ? $display : null;
                        $member->firstName   = trim($request->data->first_name ?? '');
                        $member->lastName    = trim($request->data->last_name ?? '');
                        $member->bio         = trim($request->data->bio ?? '');
                        $avatar = trim($request->data->avatar_url ?? '');
                        $member->avatarUrl   = $avatar !== '' ? $avatar : null;

                        // Per-member FREE-project allowance (ProjectQuota::freeCapFor). 0 = the
                        // global default. NOT gated by the system-account check above — the
                        // operator's own account is exactly the one they raise to "unlimited".
                        if (isset($request->data->free_projects)) {
                            $member->freeProjects = max(0, (int) $request->data->free_projects);
                        }

                        // Update password if provided
                        if (!empty($request->data->password)) {
                            if (strlen($request->data->password) < 8) {
                                $this->viewData['error'] = 'Password must be at least 8 characters long';
                            } else {
                                $member->password = password_hash($request->data->password, PASSWORD_DEFAULT);
                            }
                        }
                        
                        if (empty($this->viewData['error'])) {
                            $member->updatedAt = date('Y-m-d H:i:s');

                            try {
                                Bean::store($member);

                                /* Billing follows the account status — suspend somebody here
                                   and the nightly run must stop invoicing them, not keep
                                   charging a card for an account that no longer works.
                                   Called unconditionally: it compares the current status and
                                   is a no-op when nothing needs changing. Non-fatal. */
                                \app\BillingLifecycle::syncFor((int) $member->id);

                                // Persist per-member feature flags eligible for this level.
                                $submittedFeatures = (array)($request->data->features ?? []);
                                foreach (\app\Feature::catalogForLevel((int)$member->level) as $fkey => $fmeta) {
                                    \app\Feature::setEnabled($fkey, !empty($submittedFeatures[$fkey]), (int)$member->id);
                                }

                                // Account recovery: someone lost their authenticator. Clears
                                // the secret and the recovery codes, so a member in
                                // TwoFactorAuth::REQUIRED_LEVELS is walked through setup
                                // again at their next login rather than being locked out.
                                if (!empty($request->data->reset_2fa) && \app\TwoFactorAuth::isEnabled($member)) {
                                    \app\TwoFactorAuth::disable($member);
                                    $this->logger->warning('2FA reset by admin', [
                                        'member_id' => $member->id,
                                        'reset_by'  => $this->member->id,
                                    ]);
                                    $this->viewData['success'] = 'Member updated, and two-factor authentication was reset.';
                                }

                                if (empty($this->viewData['success'])) {
                                    $this->viewData['success'] = 'Member updated successfully';
                                }
                                $this->logger->info('Member updated by admin', [
                                    'member_id' => $member->id,
                                    'updated_by' => $this->member->id
                                ]);
                            } catch (Exception $e) {
                                $this->logger->error('Failed to update member', [
                                    'member_id' => $member->id,
                                    'error' => $e->getMessage()
                                ]);
                                $this->viewData['error'] = 'Error updating member: ' . $e->getMessage();
                            }
                        }
                    }
                }
            }
        }
        
        // ASK, don't read the cache and hope somebody filled it.
        //
        // firstBuildAt is stamped LAZILY by Invite::hasBuilt(), which scans each project's
        // own workbench.db. This page rendered the bare column instead of calling it, so a
        // member who had built read "Hasn't built anything yet — their invitations stay
        // locked" until they happened to open /invites themselves and stamp it. That is
        // the exact question an admin comes to this page to answer, reported backwards.
        \app\Invite::hasBuilt((int) $member->id);
        $member = Bean::load('member', (int) $member->id);   // re-read: hasBuilt may have stamped

        $this->viewData['title'] = 'Edit Member';
        $this->viewData['editMember'] = $member;

        // Who brought them in. Read from member.invited_by rather than the invite row, so
        // it survives invitations being tidied away, and guarded because the inviter's
        // account may since have been deleted.
        $this->viewData['invitedBy'] = null;
        if (!empty($member->invitedBy)) {
            $inviter = Bean::load('member', (int) $member->invitedBy);
            if ($inviter->id) $this->viewData['invitedBy'] = $inviter;
        }
        // How many people they have brought in, for the same reason an admin looks at the
        // inviter in the first place.
        $this->viewData['invitedCount'] = Bean::count('member', 'invited_by = ?', [(int) $member->id]);
        $this->viewData['twofaEnabled'] = \app\TwoFactorAuth::isEnabled($member);

        $this->viewData['featureFlags'] = [];
        foreach (\app\Feature::catalogForLevel((int)$member->level) as $fkey => $fmeta) {
            $this->viewData['featureFlags'][] = [
                'key'     => $fkey,
                'label'   => $fmeta['label'],
                'blurb'   => $fmeta['blurb'],
                'enabled' => \app\Feature::isEnabled($fkey, (int)$member->id, (int)$member->level),
            ];
        }
        
        $this->render('admin/edit_member', $this->viewData);
    }

    /**
     * Add new member
     */
    public function addMember($params = []) {
        $request = Flight::request();
        
        if ($request->method === 'POST') {
            // Validate CSRF
            if (!Flight::csrf()->validateRequest()) {
                $this->viewData['error'] = 'Invalid CSRF token';
            } else {
                // Validate input
                $username = trim($request->data->username ?? '');
                $email = trim($request->data->email ?? '');
                $password = $request->data->password ?? '';
                $level = intval($request->data->level ?? 100);
                $status = $request->data->status ?? 'active';
                
                if (empty($username)) {
                    $this->viewData['error'] = 'Username is required';
                } elseif (empty($email)) {
                    $this->viewData['error'] = 'Email is required';
                } elseif (empty($password)) {
                    $this->viewData['error'] = 'Password is required';
                } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $this->viewData['error'] = 'Invalid email format';
                } elseif (strlen($username) < 3) {
                    $this->viewData['error'] = 'Username must be at least 3 characters long';
                } elseif (strlen($password) < 8) {
                    $this->viewData['error'] = 'Password must be at least 8 characters long';
                } else {
                    // Check for duplicate username/email
                    $existingUsername = Bean::findOne('member', 'username = ?', [$username]);
                    $existingEmail = Bean::findOne('member', 'email = ?', [$email]);
                    
                    if ($existingUsername) {
                        $this->viewData['error'] = 'Username already exists';
                    } elseif ($existingEmail) {
                        $this->viewData['error'] = 'Email already exists';
                    } else {
                        // Create new member
                        $member = Bean::dispense('member');
                        $member->username = $username;
                        $member->email = $email;
                        $member->password = password_hash($password, PASSWORD_DEFAULT);
                        $member->level = $level;
                        $member->status = $status;
                        // Stamped like every other creation path: a NULL tier reads as
                        // "unset", and the grandfather migration would sweep it into legacy.
                        $member->planTier = 'free';
                        $member->planProjectCap = \app\ProjectQuota::FREE_CAP;
                        $member->createdAt = date('Y-m-d H:i:s');
                        $member->updatedAt = date('Y-m-d H:i:s');

                        try {
                            Bean::store($member);

                            // An admin-created member is a billing subject too. Non-fatal.
                            \app\SignupFlow::ensureTenantFor((int) $member->id);

                            $this->logger->info('New member created by admin', [
                                'member_id' => $member->id,
                                'username' => $username,
                                'created_by' => $this->member->id
                            ]);
                            Flight::redirect('/admin/members');
                            return;
                        } catch (Exception $e) {
                            $this->logger->error('Failed to create member', [
                                'username' => $username,
                                'error' => $e->getMessage()
                            ]);
                            $this->viewData['error'] = 'Error creating member: ' . $e->getMessage();
                        }
                    }
                }
            }
        }
        
        $this->viewData['title'] = 'Add New Member';
        $this->render('admin/add_member', $this->viewData);
    }

    /**
     * Permission management
     */
    public function permissions($params = []) {
        $this->viewData['title'] = 'Permission Management';
        
        // Handle delete action. Must be a POST with a CSRF token: deleting an
        // authcontrol row is destructive (the route falls to default-deny), and
        // a bare GET `?delete=` sink is CSRF-triggerable from any page an admin
        // loads (e.g. <img src="/admin/permissions?delete=5">).
        $deleteId = (int) $this->getParam('delete', 0);
        if ($deleteId > 0) {
            if (!$this->requirePost()) return;
            $auth = Bean::load('authcontrol', $deleteId);
            if ($auth->id) {
                Bean::trash($auth);
                $this->logger->info('Deleted permission', ['id' => $deleteId]);
            }
            Flight::redirect('/admin/permissions');
            return;
        }
        
        // Get all permissions grouped by control
        $_auths = Bean::findAll('authcontrol', 'ORDER BY control ASC, method ASC');
        $auths = [];
        
        foreach ($_auths as $_control) {
            $auths[$_control['control']][$_control['method']] = $_control->export();
        }
        
        $this->viewData['authControls'] = $auths;
        
        $this->render('admin/permissions', $this->viewData);
    }

    /**
     * Edit permission
     */
    public function editPermission($params = []) {
        $request = Flight::request();
        $permId = $request->query->id ?? null;
        
        if (!$permId) {
            // Create new permission
            $permission = Bean::dispense('authcontrol');
        } else {
            $permission = Bean::load('authcontrol', $permId);
            if (!$permission->id && $permId) {
                Flight::redirect('/admin/permissions');
                return;
            }
        }
        
        if ($request->method === 'POST') {
            // Validate CSRF
            if (!Flight::csrf()->validateRequest()) {
                $this->viewData['error'] = 'Invalid CSRF token';
            } else {
                // Update permission
                $permission->control = $request->data->control ?? '';
                $permission->method = $request->data->method ?? '';
                $permission->level = intval($request->data->level ?? 101);
                $permission->description = $request->data->description ?? '';
                $permission->linkorder = intval($request->data->linkorder ?? 0);
                
                if (!$permission->id) {
                    $permission->validcount = 0;
                    $permission->createdAt = date('Y-m-d H:i:s');
                }
                
                try {
                    Bean::store($permission);
                    Flight::redirect('/admin/permissions');
                    return;
                } catch (Exception $e) {
                    $this->viewData['error'] = 'Error saving permission: ' . $e->getMessage();
                }
            }
        }
        
        $this->viewData['title'] = $permId ? 'Edit Permission' : 'Add Permission';
        $this->viewData['permission'] = $permission;
        
        $this->render('admin/edit_permission', $this->viewData);
    }

    /**
     * System settings
     */
    public function settings($params = []) {
        $this->viewData['title'] = 'System Settings';
        $request = Flight::request();

        /* The real HTTP verb, not $request->method: Flight derives that from ?_method= too
           (CVE-2026-42551), and SimpleCsrf::validateRequest() — which reads the real verb —
           waves a GET through. Asking the same question of two different sources is how a
           GET dressed as a POST reaches the save with validation skipped. */
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) === 'POST') {
            // Validate CSRF
            if (!Flight::csrf()->validateRequest()) {
                $this->viewData['error'] = 'Invalid CSRF token';
            } else {
                /* ONLY the fields this form has. This loop used to store every posted key as a
                   SYSTEM setting, skipping two names ('csrf_token', 'csrf_token_name') that
                   the form never sends — the real field is '_csrf_token', so the token itself
                   was saved as a setting on every submit.

                   That was the small half. System settings are also where install flags live
                   (install.concept.<name>, lib/Feature.php), so any ADMIN could post one and
                   switch a concept on — past the ROOT gate, the verify step and the seeds that
                   /admin/conceptenable exists to enforce. A settings form decides what it
                   saves; the request does not. Member::settings already works this way. */
                static $writableSettings = [
                    'site_name', 'site_description', 'site_logo',
                    'registration_enabled', 'default_user_level', 'session_timeout',
                    'maintenance_mode', 'debug_mode',
                    'twofa_whitelist_enabled', 'twofa_ip_whitelist',
                ];
                $refused = [];
                foreach ($request->data as $key => $value) {
                    if (in_array($key, $writableSettings, true)) {
                        Flight::setSetting($key, $value, 0);
                    } elseif ($key !== '_csrf_token') {
                        $refused[] = (string) $key;
                    }
                }
                if ($refused) {
                    $this->logger->warning('Admin settings: refused keys this form does not own', [
                        'keys' => $refused, 'member_id' => $this->member->id,
                    ]);
                }
                $this->viewData['success'] = 'Settings updated successfully';
            }
        }
        
        // Get current system settings (owned by SYSTEM_ADMIN_ID)
        $this->viewData['settings'] = Bean::findAll('settings', 'member_id = ?', [SYSTEM_ADMIN_ID]);
        
        $this->render('admin/settings', $this->viewData);
    }

    /**
     * Delete member
     */
    private function deleteMember($id) {
        // Don't allow deleting self
        if ($id == $this->member->id) {
            $this->logger->warning('Attempted to delete self', ['member_id' => $id]);
            return;
        }

        // Never allow deleting protected system members
        if ($id == SYSTEM_ADMIN_ID || $id == PUBLIC_USER_ID) {
            $this->logger->warning('Attempted to delete protected system member', ['member_id' => $id]);
            return;
        }

        $member = Bean::load('member', $id);
        if ($member->id) {
            // Additional protection for critical accounts
            if ($member->level <= self::ADMIN_LEVEL && $member->id != $this->member->id) {
                // Only ROOT users can delete ADMIN users
                if ($this->member->level > self::ROOT_LEVEL) {
                    $this->logger->warning('Non-root user attempted to delete admin', [
                        'target_member_id' => $id,
                        'target_level' => $member->level,
                        'admin_id' => $this->member->id,
                        'admin_level' => $this->member->level
                    ]);
                    return;
                }
            }
            
            try {
                // Log member details before deletion
                $this->logger->info('Deleting member', [
                    'id' => $id,
                    'username' => $member->username,
                    'email' => $member->email,
                    'level' => $member->level,
                    'deleted_by' => $this->member->id
                ]);
                
                /* Stop billing BEFORE the row goes: the tenant slug lives on it, and
                   afterwards there is nothing left to say which tenant to stop. Non-fatal —
                   see BillingLifecycle. */
                \app\BillingLifecycle::onDelete((int) $id);

                Bean::trash($member);

                $this->logger->info('Member deleted successfully', ['id' => $id]);
            } catch (Exception $e) {
                $this->logger->error('Failed to delete member', [
                    'id' => $id,
                    'error' => $e->getMessage()
                ]);
            }
        } else {
            $this->logger->warning('Attempted to delete non-existent or protected user', ['id' => $id]);
        }
    }

    /**
     * Get active sessions count
     */
    private function getActiveSessions() {
        // This is a simple implementation - you might want to track sessions in database
        try {
            $sessionPath = session_save_path();
            if (is_readable($sessionPath)) {
                return count(scandir($sessionPath)) - 2; // Subtract . and ..
            }
        } catch (Exception $e) {
            // If we can't read session directory, just return estimate
        }
        return 1; // At least current user is active
    }
    
    /**
     * Handle bulk actions for members
     */
    private function handleBulkAction($action, $selectedMembers) {
        if (!is_array($selectedMembers)) {
            return;
        }
        
        $count = 0;
        
        switch ($action) {
            case 'activate':
                foreach ($selectedMembers as $memberId) {
                    // Skip protected system members
                    if (is_numeric($memberId) && $memberId != SYSTEM_ADMIN_ID && $memberId != PUBLIC_USER_ID) {
                        $member = Bean::load('member', $memberId);
                        if ($member->id) {
                            $member->status = 'active';
                            $member->updatedAt = date('Y-m-d H:i:s');
                            Bean::store($member);
                            // Billing follows the account status. Non-fatal.
                            \app\BillingLifecycle::syncFor((int) $member->id);
                            $count++;
                        }
                    }
                }
                $this->logger->info("Bulk activated $count members", ['admin_id' => $this->member->id]);
                break;

            case 'suspend':
                foreach ($selectedMembers as $memberId) {
                    // Skip self and protected system members
                    if (is_numeric($memberId) && $memberId != $this->member->id && $memberId != SYSTEM_ADMIN_ID && $memberId != PUBLIC_USER_ID) {
                        $member = Bean::load('member', $memberId);
                        if ($member->id) {
                            $member->status = 'suspended';
                            $member->updatedAt = date('Y-m-d H:i:s');
                            Bean::store($member);
                            // Billing follows the account status. Non-fatal.
                            \app\BillingLifecycle::syncFor((int) $member->id);
                            $count++;
                        }
                    }
                }
                $this->logger->info("Bulk suspended $count members", ['admin_id' => $this->member->id]);
                break;

            case 'delete':
                foreach ($selectedMembers as $memberId) {
                    // Skip self and protected system members
                    if (is_numeric($memberId) && $memberId != $this->member->id && $memberId != SYSTEM_ADMIN_ID && $memberId != PUBLIC_USER_ID) {
                        $member = Bean::load('member', $memberId);
                        if ($member->id) {
                            // Same protection as single delete
                            if ($member->level <= self::ADMIN_LEVEL && $this->member->level > self::ROOT_LEVEL) {
                                continue; // Skip admin deletion by non-root
                            }
                            Bean::trash($member);
                            $count++;
                        }
                    }
                }
                $this->logger->info("Bulk deleted $count members", ['admin_id' => $this->member->id]);
                break;
        }
    }

    /**
     * Cache management page
     */
    /**
     * /admin/instances — unattended auto-triage, per instance.
     *
     * Admin-only by the constructor above, which is the point: turning this on lets the
     * control plane launch headless agent builds against a client's repo with nobody
     * watching, so it is a spend decision and not a preference the client sets. There is
     * deliberately no member-facing route — see Model_Instance::setAutoTriage.
     */
    public function instances($params = []) {
        $this->viewData['title'] = 'Instances — Unattended Builds';
        $request = Flight::request();

        if ($request->method === 'POST') {
            if (!Flight::csrf()->validateRequest()) {
                $this->flash('error', 'CSRF validation failed');
                Flight::redirect('/admin/instances');
                return;
            }
            $id  = (int) ($request->data->instance_id ?? 0);
            $on  = !empty($request->data->enabled);
            $why = trim((string) ($request->data->note ?? ''));

            $inst = Bean::load('instance', $id);
            if (!$inst->id) {
                $this->flash('error', 'No such instance');
            } else {
                // The model writes the flag and the audit row together, so they cannot
                // come apart, and returns false when nothing actually changed.
                $changed = $inst->setAutoTriage($on, (int) $this->member->id, $why);
                $this->flash(
                    $changed ? 'success' : 'info',
                    $changed
                        ? sprintf('Unattended builds %s for %s.', $on ? 'ENABLED' : 'disabled', $inst->slug)
                        : sprintf('%s was already %s — nothing recorded.', $inst->slug, $on ? 'enabled' : 'disabled')
                );
            }
            Flight::redirect('/admin/instances');
            return;
        }

        $rows = [];
        foreach (Bean::findAll('instance', 'ORDER BY slug') as $inst) {
            $last  = $inst->lastAudit('auto_triage');
            $byId  = $last ? (int) $last->memberId : 0;
            $rows[] = [
                'bean'    => $inst,
                'on'      => $inst->autoTriageOn(),
                'last'    => $last,
                'by'      => $byId ? (string) (Bean::load('member', $byId)->email ?: 'member #' . $byId) : '',
                'owner'   => (string) (Bean::load('member', (int) $inst->memberId)->email ?: ''),
            ];
        }

        $this->viewData['rows'] = $rows;
        // Whole-trail view: who granted what, when, across every instance.
        $this->viewData['trail'] = Bean::findAll('instanceaudit', 'ORDER BY id DESC LIMIT 25');
        $this->render('admin/instances', $this->viewData);
    }

    public function cache() {
        // Check admin permission
        if (!$this->requireLevel(self::ADMIN_LEVEL)) {
            return;
        }

        // Handle cache actions
        if ($this->getParam('action')) {
            $action = $this->getParam('action');

            switch ($action) {
                case 'clear':
                    // Clear permission cache
                    \app\PermissionCache::clear();

                    // Clear query cache if available
                    // Every cached connection, not just the default one — see
                    // Bean::flushQueryCache. Clearing one prefix while reporting "cache
                    // cleared" left secondary connections serving rows.
                    $flushed = \app\Bean::flushQueryCache();
                    if ($flushed) {
                        $this->flash('success', 'Permission and query caches cleared successfully');
                    } else {
                        $this->flash('success', 'Permission cache cleared successfully');
                    }

                    Flight::redirect('/admin/cache');
                    return;

                case 'clear_query':
                    // Clear only query cache
                    // Every cached connection, not just the default one — see
                    // Bean::flushQueryCache. Clearing one prefix while reporting "cache
                    // cleared" left secondary connections serving rows.
                    $flushed = \app\Bean::flushQueryCache();
                    if ($flushed) {
                        $this->flash('success', 'Query cache cleared successfully');
                    } else {
                        $this->flash('error', 'Query cache not available');
                    }
                    Flight::redirect('/admin/cache');
                    return;

                case 'reload':
                    $stats = \app\PermissionCache::reload();
                    $this->flash('success', 'Permission cache reloaded with ' . count($stats) . ' entries');
                    Flight::redirect('/admin/cache');
                    return;

                case 'warmup':
                    $stats = \app\PermissionCache::warmup();
                    $this->flash('success', 'Cache warmed up successfully');
                    Flight::redirect('/admin/cache');
                    return;
            }
        }

        // Get cache statistics
        $this->viewData['cache_stats'] = \app\PermissionCache::getStats();
        $this->viewData['permissions'] = \app\PermissionCache::getAll();

        // Query cache: EVERY cached connection, not just the default one.
        //
        // This read Flight::get('cachedDatabaseAdapter'), which is always core's own
        // database. Once secondary connections gained the cache, the page showed one of
        // them and silently omitted the rest — so a connection could be caching, or
        // failing to, with nothing on screen either way.
        $connections = [];
        foreach (\app\Bean::cacheAdapters() as $key => $ad) {
            $connections[$key] = $ad->getCacheStats() + ['identified' => $ad->identified()];
        }
        $this->viewData['query_cache_connections'] = $connections;

        // Kept so the existing summary card still renders: the default connection.
        $this->viewData['query_cache_stats'] = $connections['default'] ?? null;

        // The version store decides whether ANY of the above is trustworthy. When it stops
        // answering, caching disables itself and logs at ERROR — correct, but previously
        // invisible here, so the page kept showing a healthy hit rate for a cache that was
        // not running.
        $this->viewData['version_store'] = \app\CacheVersionStoreFactory::fromConfig()->stats();

        // Get OPcache stats if available
        if (function_exists('opcache_get_status')) {
            try {
                $this->viewData['opcache_stats'] = @opcache_get_status(false);
            } catch (\Throwable $e) {
                $this->viewData['opcache_stats'] = null;
            }
        }

        // Check if APCu is available and working
        // Note: APCu functions may exist but require apc.enable_cli=1 for CLI
        $this->viewData['apcu_available'] = function_exists('apcu_cache_info')
            && ini_get('apc.enabled')
            && (php_sapi_name() !== 'cli' || ini_get('apc.enable_cli'));

        if ($this->viewData['apcu_available']) {
            try {
                $this->viewData['apcu_info'] = @apcu_cache_info();
            } catch (\Throwable $e) {
                // APCu function exists but is not fully enabled (e.g., in CLI mode)
                $this->viewData['apcu_available'] = false;
                $this->viewData['apcu_info'] = null;
            }
        }

        $this->render('admin/cache', $this->viewData);
    }

    /* ---- Concepts (COMPONENTS_PLAN.md) ------------------------------------------------
     *
     * ROOT, not ADMIN, even though admin::* is 50: switching a concept on makes new code
     * routable and runs its seeds against the schema. That is the same blast radius as the
     * raw INI editor, so it carries the same level — by an authcontrol row AND by the check
     * in each method, because a row can be edited.
     *
     * This screen SWITCHES concepts. It deliberately cannot INSTALL one: the instance pool
     * user can write controls/, so a web install would be the web process writing executable
     * PHP into its own tree. Installing is a build task (clitool --concept-install, in a
     * worktree, reviewed, merged).
     */

    /** GET /admin/concepts — what is installed, whether each could be switched on, and the catalog. */
    public function concepts($params = []) {
        if (!$this->requireLevel(self::ROOT_LEVEL)) return;

        $registry = \app\Concepts::instance();
        $installed = [];
        foreach ($registry->scan() as $name => $row) {
            $row['problems'] = $row['manifest'] !== null ? $registry->verify($name) : [];
            try {
                $row['provenance'] = $registry->provenance($name);
            } catch (\app\ConceptException $e) {
                $row['provenance'] = null;
                $row['problems'][] = $e->getMessage();
            }
            $installed[$name] = $row;
        }

        // Unreachable is shown as unreachable. An empty list here would read as "the
        // catalog has nothing", which is a different fact.
        $catalog = ['results' => [], 'broken' => [], 'source' => null, 'error' => null];
        try {
            $found = \app\ConceptCatalog::forInstall()->search('', 50);
            $catalog = ['error' => null] + $found;
        } catch (\app\ConceptException $e) {
            $catalog['error'] = $e->getMessage();
        }

        // An install goes into the SELECTED PROJECT, as a build — not into this install's live
        // tree. So the catalog rows are judged against that project, and the button names it.
        $project = $this->conceptProject();
        foreach ($catalog['results'] as &$row) {
            $row['in_project'] = $project !== null && is_dir($project['dir'] . '/' . \app\Concepts::DIR . '/' . $row['name']);
        }
        unset($row);

        $this->render('admin/concepts', [
            'title'     => 'Plugins',
            'installed' => $installed,
            'catalog'   => $catalog,
            'project'   => $project,
        ]);
    }

    /**
     * The project an install would go into. On core: the one selected in the header, or
     * null. On an instance there is nothing to select — the install IS the project
     * ('here' => true), and the catalog is judged against this tree. An instance cannot
     * queue the build itself (plan-ingest resolves the project in core's registry), so its
     * Install button asks the control plane (ConceptCatalog::requestInstall).
     */
    private function conceptProject(): ?array {
        if (!is_core_install()) {
            $dir = dirname(__DIR__);
            return [
                'id'   => 0,
                'slug' => explode('.', basename($dir), 2)[0],
                'name' => Flight::siteName(),
                'dir'  => $dir,
                'here' => true,
            ];
        }
        $inst = \app\ProjectContext::current((int) $this->member->id);
        if ($inst === null) return null;
        return [
            'id'   => (int) $inst->id,
            'slug' => (string) $inst->slug,
            'name' => (string) ($inst->displayName ?? '') !== '' ? (string) $inst->displayName : (string) $inst->slug,
            'dir'  => \Model_Instance::dirFrom((string) $inst->slug, (string) ($inst->app ?? '')),
            'here' => false,
        ];
    }

    /**
     * POST /admin/conceptinstall — queue a plugin install for the selected project.
     *
     * It does NOT copy files. It runs scripts/concept-install.php, which writes an install
     * PLAN into the project and ingests it; the plan is then approved and run in Builder,
     * and the executor installs, commits and merges. A copy into the live tree would be
     * invisible to every build agent, because worktrees are cut from the committed base.
     */
    public function conceptinstall($params = []) {
        if (!$this->requireLevel(self::ROOT_LEVEL)) return;
        if (!$this->conceptPost()) return;

        $name = (string) $this->getParam('name', '');
        if (!preg_match('/^[a-z][a-z0-9]*$/D', $name)) {
            $this->flash('error', 'That is not a plugin name.');
            Flight::redirect('/admin/concepts');
            return;
        }
        $project = $this->conceptProject();
        if ($project === null) {
            $this->flash('error', 'Select a project first — a plugin is installed INTO a project, as a build.');
            Flight::redirect('/admin/concepts');
            return;
        }
        if (!empty($project['here'])) {
            // A project: the install must end as a commit on its branch, and the build runs
            // on the control plane — so ask it, with this install's own broker key.
            try {
                $r = \app\ConceptCatalog::forInstall()->requestInstall($name);
            } catch (\app\ConceptException $e) {
                $this->logger->warning('Concept install request failed', ['concept' => $name, 'why' => $e->getMessage()]);
                $this->flash('error', self::oneLine($e->getMessage()));
                Flight::redirect('/admin/concepts');
                return;
            }
            $this->logger->info('Concept install requested from the control plane', ['concept' => $name, 'queued' => $r['queued'], 'member_id' => $this->member->id]);
            $this->flash($r['queued'] ? 'success' : 'error', self::oneLine($r['message']));
            Flight::redirect('/admin/concepts');
            return;
        }

        $r = \app\ConceptCatalog::queueInstall($name, $project, (int) $this->member->id);
        if ($r['ok']) {
            $this->logger->info('Concept install queued', ['concept' => $name, 'project' => $project['slug'], 'member_id' => $this->member->id]);
            $enable = 'cd ' . $project['dir'] . ' && php scripts/clitool.php --concept-enable=' . $name;
            $this->flash('success', "Installing '{$name}' into {$project['name']} — a build with no agent, usually under a minute; "
                . "watch it in Builder. Once it has merged, switch it on with: {$enable}  (or that project's Plugins page, where it has one).");
        } else {
            $this->logger->warning('Concept install not queued', ['concept' => $name, 'project' => $project['slug'], 'said' => $r['said']]);
            $this->flash('error', "Could not queue '{$name}' for {$project['name']}: " . $r['said']);
        }
        Flight::redirect('/admin/concepts');
    }

    /** POST /admin/conceptenable — verify, run the concept's seeds, switch it on. */
    public function conceptenable($params = []) {
        if (!$this->requireLevel(self::ROOT_LEVEL)) return;
        if (!$this->conceptPost()) return;
        $name = (string) $this->getParam('name', '');
        try {
            $seeded = \app\Concepts::instance()->enable($name);
            \app\PermissionCache::clear();   // its seeds may have added routes
            $this->logger->info('Concept enabled', ['concept' => $name, 'member_id' => $this->member->id, 'seeds' => $seeded]);
            $this->flash('success', "Concept '{$name}' enabled" . ($seeded ? ' — ' . count($seeded) . ' seed file(s) ran.' : '.'));
            $this->syncGuidanceAfter($name);
        } catch (\app\ConceptException $e) {
            $this->logger->warning('Concept enable refused', ['concept' => $name, 'member_id' => $this->member->id, 'why' => $e->getMessage()]);
            $this->flash('error', self::oneLine($e->getMessage()));
        }
        Flight::redirect('/admin/concepts');
    }

    /**
     * The flag flipped; now CLAUDE.md's managed block must say so (AgentGuidance). A failure
     * here does not undo the enable — it is reported beside it, with the fix.
     */
    private function syncGuidanceAfter(string $name): void {
        try {
            $r = \app\Concepts::instance()->syncGuidance();
            foreach ($r['notes'] as $n) $this->flash('warning', self::oneLine($n));
        } catch (\RuntimeException $e) {
            $this->logger->error('Agent guidance not synced after concept change', ['concept' => $name, 'why' => $e->getMessage()]);
            $this->flash('warning', 'CLAUDE.md was not regenerated: ' . self::oneLine($e->getMessage()));
        }
    }

    /**
     * POST /admin/conceptdisable — switch it off. Never forced from here: a concept whose
     * beans still hold rows is refused, and overriding that is a deliberate CLI action
     * (clitool --concept-disable=NAME --force). Data is kept either way.
     */
    public function conceptdisable($params = []) {
        if (!$this->requireLevel(self::ROOT_LEVEL)) return;
        if (!$this->conceptPost()) return;
        $name = (string) $this->getParam('name', '');
        try {
            \app\Concepts::instance()->disable($name);
            \app\PermissionCache::clear();
            $this->logger->info('Concept disabled', ['concept' => $name, 'member_id' => $this->member->id]);
            $this->flash('success', "Concept '{$name}' disabled. Its tables and rows are kept.");
            $this->syncGuidanceAfter($name);
        } catch (\app\ConceptException $e) {
            $this->logger->warning('Concept disable refused', ['concept' => $name, 'member_id' => $this->member->id, 'why' => $e->getMessage()]);
            $this->flash('error', self::oneLine($e->getMessage()));
        }
        Flight::redirect('/admin/concepts');
    }

    /** These two change state, so: the real HTTP verb must be POST, and the CSRF token must hold. */
    private function conceptPost(): bool {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
            $this->flash('error', 'That action must be submitted from the Concepts page.');
            Flight::redirect('/admin/concepts');
            return false;
        }
        return $this->validateCSRF();
    }

    /** A multi-line refusal ("cannot be enabled:\n  - a\n  - b") as one flash line. */
    private static function oneLine(string $message): string {
        return trim(preg_replace('/\s*\n\s*-\s*/', ' • ', $message));
    }

    /**
     * Clear cache after permission updates
     */
    private function clearPermissionCache() {
        // Clear the permission cache when permissions are modified
        \app\PermissionCache::clear();
        $this->logger->info('Permission cache cleared after update');
    }
}