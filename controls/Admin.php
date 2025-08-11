<?php
namespace app;

use \Flight as Flight;
use \RedBeanPHP\R as R;
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
            'members' => R::count('member'),
            'permissions' => R::count('authcontrol'),
            'active_sessions' => $this->getActiveSessions(),
        ];
        
        $this->render('admin/index', $this->viewData);
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
        
        // Get all members
        $this->viewData['members'] = R::findAll('member', 'ORDER BY created_at DESC');
        
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
        
        $member = R::load('member', $memberId);
        if (!$member->id) {
            Flight::redirect('/admin/members');
            return;
        }
        
        if ($request->method === 'POST') {
            // Validate CSRF
            if (!Flight::csrf()->validateRequest()) {
                $this->viewData['error'] = 'Invalid CSRF token';
            } else {
                // Update member
                $member->username = $request->data->username ?? $member->username;
                $member->email = $request->data->email ?? $member->email;
                $member->level = intval($request->data->level ?? $member->level);
                $member->status = $request->data->status ?? $member->status;
                
                // Update password if provided
                if (!empty($request->data->password)) {
                    $member->password = password_hash($request->data->password, PASSWORD_DEFAULT);
                }
                
                $member->updated_at = date('Y-m-d H:i:s');
                
                try {
                    R::store($member);
                    $this->viewData['success'] = 'Member updated successfully';
                } catch (Exception $e) {
                    $this->viewData['error'] = 'Error updating member: ' . $e->getMessage();
                }
            }
        }
        
        $this->viewData['title'] = 'Edit Member';
        $this->viewData['editMember'] = $member;
        
        $this->render('admin/edit_member', $this->viewData);
    }

    /**
     * Permission management
     */
    public function permissions($params = []) {
        $this->viewData['title'] = 'Permission Management';
        
        $request = Flight::request();
        
        // Handle delete action
        if ($request->query->delete && is_numeric($request->query->delete)) {
            $auth = R::load('authcontrol', $request->query->delete);
            if ($auth->id) {
                R::trash($auth);
                $this->logger->info('Deleted permission', ['id' => $request->query->delete]);
            }
            Flight::redirect('/admin/permissions');
            return;
        }
        
        // Get all permissions grouped by control
        $_auths = R::findAll('authcontrol', 'ORDER BY control ASC, method ASC');
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
            $permission = R::dispense('authcontrol');
        } else {
            $permission = R::load('authcontrol', $permId);
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
                    $permission->created_at = date('Y-m-d H:i:s');
                }
                
                try {
                    R::store($permission);
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
        
        if (Flight::request()->method === 'POST') {
            // Validate CSRF
            if (!Flight::csrf()->validateRequest()) {
                $this->viewData['error'] = 'Invalid CSRF token';
            } else {
                // Update settings
                foreach ($request->data as $key => $value) {
                    if ($key !== 'csrf_token' && $key !== 'csrf_token_name') {
                        Flight::setSetting($key, $value, 0); // System-wide setting
                    }
                }
                $this->viewData['success'] = 'Settings updated successfully';
            }
        }
        
        // Get current settings
        $this->viewData['settings'] = R::findAll('settings', 'member_id = 0');
        
        $this->render('admin/settings', $this->viewData);
    }

    /**
     * Delete member
     */
    private function deleteMember($id) {
        // Don't allow deleting self or system users
        if ($id == $this->member->id) {
            return;
        }
        
        $member = R::load('member', $id);
        if ($member->id && $member->username !== 'public-user-entity') {
            R::trash($member);
            $this->logger->info('Deleted member', ['id' => $id]);
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
}