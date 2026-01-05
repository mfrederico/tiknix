<?php
namespace app;

use \Flight as Flight;
use \app\Bean;
use \app\TwoFactorAuth;
use \Exception as Exception;
use app\BaseControls\Control;

class Member extends Control {
    
    public function __construct() {
        parent::__construct();
        
        // Require login for all member pages
        if (!Flight::isLoggedIn()) {
            Flight::redirect('/auth/login?redirect=' . urlencode(Flight::request()->url));
            exit;
        }
    }
    
    /**
     * Member profile page
     */
    public function profile($params = []) {
        $this->viewData['title'] = 'My Profile';
        $this->render('member/profile', $this->viewData);
    }
    
    /**
     * Edit profile
     */
    public function edit($params = []) {
        if (Flight::request()->method === 'POST') {
            // Validate CSRF token
            if (!Flight::csrf()->validateRequest()) {
                $this->viewData['error'] = 'Invalid CSRF token';
            } else {
            
            $request = Flight::request();
            $member = Bean::load('member', $this->member->id);
            
            // Validate input
            $email = trim($request->data->email ?? '');
            $first_name = trim($request->data->first_name ?? '');
            $last_name = trim($request->data->last_name ?? '');
            $bio = trim($request->data->bio ?? '');
            
            if (empty($email)) {
                $this->viewData['error'] = 'Email is required';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->viewData['error'] = 'Invalid email format';
            } else {
                // Check for duplicate email (excluding current member)
                $existingEmail = Bean::findOne('member', 'email = ? AND id != ?', [$email, $member->id]);
                
                if ($existingEmail) {
                    $this->viewData['error'] = 'Email already exists';
                } else {
                    // Update allowed fields
                    $member->email = $email;
                    $member->firstName = $first_name;
                    $member->lastName = $last_name;
                    $member->bio = $bio;
                }
            }
            
            // Update password if provided
            if (!empty($request->data->password) || !empty($request->data->current_password)) {
                // Verify current password if changing password
                if (!empty($request->data->current_password)) {
                    if (!password_verify($request->data->current_password, $member->password)) {
                        $this->viewData['error'] = 'Current password is incorrect';
                    } else if (empty($request->data->password)) {
                        $this->viewData['error'] = 'Please enter a new password';
                    } else if ($request->data->password !== $request->data->password_confirm) {
                        $this->viewData['error'] = 'New passwords do not match';
                    } else if (strlen($request->data->password) < 8) {
                        $this->viewData['error'] = 'Password must be at least 8 characters';
                    } else {
                        $member->password = password_hash($request->data->password, PASSWORD_DEFAULT);
                        $this->viewData['success'] = 'Profile and password updated successfully';
                    }
                }
            }
            
            if (empty($this->viewData['error'])) {
                $member->updatedAt = date('Y-m-d H:i:s');
                
                try {
                    Bean::store($member);
                    $_SESSION['member'] = $member->export();
                    $this->member = $member; // Update current member object
                    $this->viewData['member'] = $member; // Update view data with new member
                    if (empty($this->viewData['success'])) {
                        $this->viewData['success'] = 'Profile updated successfully';
                    }
                    $this->logger->info('Member profile updated', ['member_id' => $member->id]);
                } catch (Exception $e) {
                    $this->logger->error('Failed to update member profile', [
                        'member_id' => $member->id,
                        'error' => $e->getMessage()
                    ]);
                    $this->viewData['error'] = 'Error updating profile: ' . $e->getMessage();
                }
            }
            }
        }
        
        $this->viewData['title'] = 'Edit Profile';
        $this->render('member/edit', $this->viewData);
    }
    
    /**
     * Member dashboard
     */
    public function dashboard($params = []) {
        $this->viewData['title'] = 'Dashboard';
        $this->render('member/dashboard', $this->viewData);
    }
    
    /**
     * Member settings
     */
    public function settings($params = []) {
        $request = Flight::request();
        if ($request->method === 'POST') {
            // Save settings
            foreach ($request->data as $key => $value) {
                if ($key !== 'csrf_token' && $key !== 'csrf_token_name') {
                    Flight::setSetting($key, $value, $this->member->id);
                }
            }
            $this->viewData['success'] = 'Settings saved successfully';
        }

        // Get user settings
        $this->viewData['settings'] = Bean::findAll('settings', 'member_id = ?', [$this->member->id]);

        // 2FA status
        $this->viewData['twofa_enabled'] = TwoFactorAuth::isEnabled($this->member);
        $this->viewData['twofa_required'] = TwoFactorAuth::isRequired($this->member);
        $this->viewData['twofa_required_reason'] = TwoFactorAuth::getRequiredReason($this->member);
        $this->viewData['recovery_code_count'] = TwoFactorAuth::getRemainingRecoveryCodeCount($this->member);

        $this->viewData['title'] = 'Settings';
        $this->render('member/settings', $this->viewData);
    }

    /**
     * Start 2FA setup - generate secret and show QR code
     */
    public function setup2fa($params = []) {
        if (TwoFactorAuth::isEnabled($this->member)) {
            Flight::redirect('/member/settings');
            return;
        }

        // Generate new secret
        $secret = TwoFactorAuth::generateSecret();
        $_SESSION['2fa_setup_secret'] = $secret;

        // Generate QR code
        $qrCode = TwoFactorAuth::generateQrCode($secret, $this->member->email);

        $this->viewData['title'] = 'Setup Two-Factor Authentication';
        $this->viewData['secret'] = $secret;
        $this->viewData['qr_code'] = $qrCode;
        $this->render('member/setup2fa', $this->viewData);
    }

    /**
     * Verify and enable 2FA
     */
    public function enable2fa($params = []) {
        $request = Flight::request();

        if ($request->method !== 'POST') {
            Flight::redirect('/member/setup2fa');
            return;
        }

        $secret = $_SESSION['2fa_setup_secret'] ?? null;
        $code = trim($request->data->code ?? '');

        if (!$secret) {
            Flight::redirect('/member/setup2fa');
            return;
        }

        $result = TwoFactorAuth::enable($this->member, $secret, $code);

        if ($result['success']) {
            unset($_SESSION['2fa_setup_secret']);

            // Show recovery codes
            $this->viewData['title'] = 'Two-Factor Authentication Enabled';
            $this->viewData['recovery_codes'] = $result['recovery_codes'];
            $this->render('member/2fa_enabled', $this->viewData);
        } else {
            $this->viewData['error'] = $result['error'];
            $this->viewData['title'] = 'Setup Two-Factor Authentication';
            $this->viewData['secret'] = $secret;
            $this->viewData['qr_code'] = TwoFactorAuth::generateQrCode($secret, $this->member->email);
            $this->render('member/setup2fa', $this->viewData);
        }
    }

    /**
     * Disable 2FA
     */
    public function disable2fa($params = []) {
        $request = Flight::request();

        if ($request->method !== 'POST') {
            Flight::redirect('/member/settings');
            return;
        }

        // Require password confirmation
        $password = $request->data->password ?? '';

        if (!password_verify($password, $this->member->password)) {
            $_SESSION['flash_error'] = 'Incorrect password';
            Flight::redirect('/member/settings');
            return;
        }

        // Check if 2FA is required for this user
        if (TwoFactorAuth::isRequired($this->member)) {
            $_SESSION['flash_error'] = 'Two-factor authentication is required for your account and cannot be disabled';
            Flight::redirect('/member/settings');
            return;
        }

        TwoFactorAuth::disable($this->member);
        $_SESSION['flash_success'] = 'Two-factor authentication has been disabled';
        Flight::redirect('/member/settings');
    }

    /**
     * Regenerate recovery codes
     */
    public function regenerateCodes($params = []) {
        $request = Flight::request();

        if ($request->method !== 'POST') {
            Flight::redirect('/member/settings');
            return;
        }

        if (!TwoFactorAuth::isEnabled($this->member)) {
            Flight::redirect('/member/settings');
            return;
        }

        // Require password confirmation
        $password = $request->data->password ?? '';

        if (!password_verify($password, $this->member->password)) {
            $_SESSION['flash_error'] = 'Incorrect password';
            Flight::redirect('/member/settings');
            return;
        }

        $codes = TwoFactorAuth::regenerateRecoveryCodes($this->member);

        $this->viewData['title'] = 'New Recovery Codes';
        $this->viewData['recovery_codes'] = $codes;
        $this->render('member/2fa_enabled', $this->viewData);
    }
}