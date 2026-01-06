<?php
/**
 * Authentication Controller
 * Handles login, logout, registration, password reset
 */

namespace app;

use \Flight as Flight;
use \app\Bean;
use \app\RateLimiter;
use \app\Mailer;
use \app\TwoFactorAuth;
use \Exception as Exception;

class Auth extends BaseControls\Control {
    
    /**
     * Show login form
     */
    public function login() {
        // Don't redirect if coming from a permission denied scenario
        // Check if we're in a redirect loop situation
        $redirect = Flight::request()->query->redirect ?? '';
        
        // If already logged in and NOT coming from a permission denied redirect
        if (Flight::isLoggedIn() && empty($redirect)) {
            Flight::redirect('/dashboard');
            return;
        }
        
        // If logged in but redirected here due to permission issues, show a message
        if (Flight::isLoggedIn() && !empty($redirect)) {
            $this->flash('error', 'You do not have permission to access that page.');
        }
        
        $this->render('auth/login', [
            'title' => 'Login',
            'redirect' => $redirect,
            'csrf' => Flight::csrf()->getTokenArray('/auth/dologin')
        ]);
    }
    
    /**
     * Process login
     */
    public function dologin() {
        try {
            // CSRF validation enabled for security
            if (!$this->validateCSRF()) {
                $this->flash('error', 'Security validation failed. Please try again.');
                Flight::redirect('/auth/login');
                return;
            }

            // Rate limiting - 5 attempts per 5 minutes per IP
            if (!RateLimiter::check('login', 5, 300)) {
                $retryAfter = RateLimiter::retryAfter('login', 300);
                $minutes = ceil($retryAfter / 60);
                $this->flash('error', "Too many login attempts. Please try again in {$minutes} minute(s).");
                Flight::redirect('/auth/login');
                return;
            }

            // Accept either username or email
            $request = Flight::request();
            $username = $request->data->username ?? '';
            $email = $request->data->email ?? '';
            $password = $request->data->password ?? '';
            $redirect = $request->data->redirect ?? '/dashboard';

            // Use username if provided, otherwise use email
            $login = $username ?: $email;
            
            // Validate input
            if (empty($login) || empty($password)) {
                $this->flash('error', 'Username/Email and password are required');
                Flight::redirect('/auth/login');
                return;
            }
            
            // Find member by username or email
            $member = Bean::findOne('member', '(username = ? OR email = ?) AND status = ?', [$login, $login, 'active']);
            
            if (!$member) {
                $this->logger->warning('Failed login attempt - user not found', ['login' => $login]);
                $this->flash('error', 'Invalid credentials');
                Flight::redirect('/auth/login');
                return;
            }

            // Check if user registered via OAuth and has no password
            if (!$member->password) {
                $this->logger->info('OAuth user attempted password login', ['login' => $login]);
                $this->flash('info', 'This account uses Google sign-in. Please click "Sign in with Google" to login.');
                Flight::redirect('/auth/login');
                return;
            }

            if (!password_verify($password, $member->password)) {
                $this->logger->warning('Failed login attempt - wrong password', ['login' => $login]);
                $this->flash('error', 'Invalid credentials');
                Flight::redirect('/auth/login');
                return;
            }
            
            // Update last login
            $member->lastLogin = date('Y-m-d H:i:s');
            $member->loginCount = ($member->loginCount ?? 0) + 1;
            Bean::store($member);

            // Clear rate limit on successful login
            RateLimiter::clear('login');

            // Check 2FA requirements
            if (TwoFactorAuth::needsSetup($member)) {
                // User needs to set up 2FA before continuing
                $_SESSION['2fa_pending_member_id'] = $member->id;
                $_SESSION['2fa_pending_redirect'] = $redirect;
                $this->logger->info('2FA setup required', ['id' => $member->id]);
                Flight::redirect('/auth/twofasetup');
                return;
            }

            if (TwoFactorAuth::needsVerification($member)) {
                // User needs to verify 2FA
                $_SESSION['2fa_pending_member_id'] = $member->id;
                $_SESSION['2fa_pending_redirect'] = $redirect;
                $this->logger->info('2FA verification required', ['id' => $member->id]);
                Flight::redirect('/auth/twofaverify');
                return;
            }

            // No 2FA needed or already trusted - complete login
            $_SESSION['member'] = $member->export();

            $this->logger->info('User logged in', ['id' => $member->id, 'username' => $member->username]);

            Flight::redirect($redirect);
            
        } catch (Exception $e) {
            $this->handleException($e, 'Login failed');
        }
    }
    
    /**
     * Logout user
     */
    public function logout() {
        if (isset($_SESSION['member'])) {
            $this->logger->info('User logged out', ['id' => $_SESSION['member']['id']]);
        }
        
        // Properly clear session data
        $_SESSION = array();
        
        // Destroy session cookie
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        
        // Destroy the session
        session_destroy();
        
        // Start a new session for flash messages
        session_start();
        $this->flash('success', 'You have been logged out');
        
        Flight::redirect('/');
    }
    
    /**
     * Show registration form
     */
    public function register() {
        // Redirect if already logged in
        if (Flight::isLoggedIn()) {
            Flight::redirect('/dashboard');
            return;
        }
        
        $this->render('auth/register', [
            'title' => 'Register'
        ]);
    }
    
    /**
     * Process registration (simple version - no email verification)
     */
    public function doregister() {
        $request = Flight::request();

        // Handle both GET and POST for easier testing
        if ($request->method === 'GET') {
            $this->register();
            return;
        }

        // Rate limiting - 5 registrations per hour per IP
        if (!RateLimiter::check('register', 5, 3600)) {
            $retryAfter = RateLimiter::retryAfter('register', 3600);
            $minutes = ceil($retryAfter / 60);
            $this->render('auth/register', [
                'title' => 'Register',
                'errors' => ["Too many registration attempts. Please try again in {$minutes} minute(s)."],
                'data' => $request->data->getData()
            ]);
            return;
        }

        // Get input
        $username = $this->sanitize($request->data->username);
        $email = $this->sanitize($request->data->email, 'email');
        $password = $request->data->password;
        $password_confirm = $request->data->password_confirm;
        
        // Simple validation
        $errors = [];
        
        if (empty($username) || strlen($username) < 3) {
            $errors[] = 'Username must be at least 3 characters';
        }
        
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Valid email is required';
        }
        
        if (empty($password) || strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters';
        }
        
        if ($password !== $password_confirm) {
            $errors[] = 'Passwords do not match';
        }
        
        // Check if email exists
        if (Bean::count('member', 'email = ?', [$email]) > 0) {
            $errors[] = 'Email already registered';
        }
        
        // Check if username exists
        if (Bean::count('member', 'username = ?', [$username]) > 0) {
            $errors[] = 'Username already taken';
        }
        
        if (!empty($errors)) {
            $this->render('auth/register', [
                'title' => 'Register',
                'errors' => $errors,
                'data' => $request->data->getData()
            ]);
            return;
        }
        
        try {
            // Create member
            $member = Bean::dispense('member');
            $member->email = $email;
            $member->username = $username;
            $member->password = password_hash($password, PASSWORD_DEFAULT);
            $member->level = LEVELS['MEMBER'];
            $member->status = 'active'; // Active immediately - no email verification
            $member->createdAt = date('Y-m-d H:i:s');
            
            $id = Bean::store($member);
            
            Flight::get('log')->info('New user registered', ['id' => $id, 'username' => $username]);
            
            // Auto-login after registration
            $_SESSION['member'] = $member->export();
            $_SESSION['member']['id'] = $id;
            
            $this->flash('success', 'Welcome to ' . Flight::get('app.name') . '! Your account has been created.');
            Flight::redirect('/dashboard');
            
        } catch (\Exception $e) {
            Flight::get('log')->error('Registration failed: ' . $e->getMessage());
            $this->render('auth/register', [
                'title' => 'Register',
                'errors' => ['Registration failed. Please try again.'],
                'data' => $request->data->getData()
            ]);
        }
    }
    
    /**
     * Show forgot password form
     */
    public function forgot() {
        $this->render('auth/forgot', [
            'title' => 'Forgot Password'
        ]);
    }
    
    /**
     * Process forgot password
     */
    public function doforgot() {
        try {
            // Validate CSRF
            if (!$this->validateCSRF()) {
                return;
            }

            // Rate limiting - 3 attempts per 15 minutes per IP
            if (!RateLimiter::check('forgot_password', 3, 900)) {
                $retryAfter = RateLimiter::retryAfter('forgot_password', 900);
                $minutes = ceil($retryAfter / 60);
                $this->flash('error', "Too many reset requests. Please try again in {$minutes} minute(s).");
                Flight::redirect('/auth/forgot');
                return;
            }

            $email = $this->sanitize($this->getParam('email'), 'email');
            
            if (empty($email)) {
                $this->flash('error', 'Email is required');
                Flight::redirect('/auth/forgot');
                return;
            }
            
            $member = Bean::findOne('member', 'email = ? AND status = ?', [$email, 'active']);

            if ($member) {
                // Block password reset for OAuth-only users (they have no password set)
                // They should add a password from Account Settings while logged in
                if ($member->googleId && !$member->password) {
                    $this->logger->info('OAuth user attempted password reset', ['email' => $email]);
                    // Still show generic message to prevent email enumeration
                    $this->flash('success', 'If the email exists, a reset link has been sent');
                    Flight::redirect('/auth/login');
                    return;
                }

                // Generate reset token
                $token = bin2hex(random_bytes(32));
                $member->resetToken = $token;
                $member->resetExpires = date('Y-m-d H:i:s', strtotime('+1 hour'));
                Bean::store($member);
                
                // Send reset email
                $resetUrl = Flight::get('app.baseurl') . "/auth/reset?token={$token}";

                // Send the password reset email
                $name = $member->displayName ?? $member->username ?? $email;
                if (Mailer::isConfigured()) {
                    Mailer::sendPasswordReset($email, $name, $resetUrl);
                } else {
                    $this->logger->warning('Mailer not configured - password reset email not sent');
                }

                $this->logger->info('Password reset requested', ['email' => $email]);
            }
            
            // Always show success to prevent email enumeration
            $this->flash('success', 'If the email exists, a reset link has been sent');
            Flight::redirect('/auth/login');
            
        } catch (Exception $e) {
            $this->handleException($e, 'Password reset failed');
        }
    }
    
    /**
     * Show reset password form
     */
    public function reset() {
        $token = $this->getParam('token');
        
        if (empty($token)) {
            $this->flash('error', 'Invalid reset link');
            Flight::redirect('/auth/login');
            return;
        }
        
        $member = Bean::findOne('member', 'reset_token = ? AND reset_expires > ?', 
            [$token, date('Y-m-d H:i:s')]);
        
        if (!$member) {
            $this->flash('error', 'Invalid or expired reset link');
            Flight::redirect('/auth/login');
            return;
        }
        
        $this->render('auth/reset', [
            'title' => 'Reset Password',
            'token' => $token
        ]);
    }
    
    /**
     * Process password reset
     */
    public function doreset() {
        try {
            // Validate CSRF
            if (!$this->validateCSRF()) {
                return;
            }
            
            $token = $this->getParam('token');
            $password = $this->getParam('password');
            $password_confirm = $this->getParam('password_confirm');
            
            // Validate input
            if (empty($token) || empty($password)) {
                $this->flash('error', 'Invalid request');
                Flight::redirect('/auth/login');
                return;
            }
            
            if (strlen($password) < 8) {
                $this->flash('error', 'Password must be at least 8 characters');
                Flight::redirect("/auth/reset?token={$token}");
                return;
            }
            
            if ($password !== $password_confirm) {
                $this->flash('error', 'Passwords do not match');
                Flight::redirect("/auth/reset?token={$token}");
                return;
            }
            
            // Find member
            $member = Bean::findOne('member', 'reset_token = ? AND reset_expires > ?', 
                [$token, date('Y-m-d H:i:s')]);
            
            if (!$member) {
                $this->flash('error', 'Invalid or expired reset link');
                Flight::redirect('/auth/login');
                return;
            }
            
            // Update password
            $member->password = password_hash($password, PASSWORD_DEFAULT);
            $member->resetToken = null;
            $member->resetExpires = null;
            Bean::store($member);
            
            $this->logger->info('Password reset completed', ['id' => $member->id]);
            
            $this->flash('success', 'Password reset successful! Please login with your new password');
            Flight::redirect('/auth/login');

        } catch (Exception $e) {
            $this->handleException($e, 'Password reset failed');
        }
    }

    // ==================== Google OAuth ====================

    /**
     * Redirect to Google OAuth login
     */
    public function google() {
        require_once __DIR__ . '/../lib/plugins/GoogleAuth.php';

        if (!\app\plugins\GoogleAuth::isConfigured()) {
            $this->flash('error', 'Google sign-in is not configured');
            Flight::redirect('/auth/login');
            return;
        }

        try {
            $url = \app\plugins\GoogleAuth::getLoginUrl();
            Flight::redirect($url);
        } catch (Exception $e) {
            $this->logger->error('Google OAuth error: ' . $e->getMessage());
            $this->flash('error', 'Failed to initialize Google sign-in');
            Flight::redirect('/auth/login');
        }
    }

    /**
     * Handle Google OAuth callback
     */
    public function googlecallback() {
        require_once __DIR__ . '/../lib/plugins/GoogleAuth.php';

        $code = $_GET['code'] ?? null;
        $state = $_GET['state'] ?? null;
        $error = $_GET['error'] ?? null;

        // Handle OAuth errors
        if ($error) {
            $this->logger->warning('Google OAuth denied', ['error' => $error]);
            $this->flash('error', 'Google sign-in was cancelled');
            Flight::redirect('/auth/login');
            return;
        }

        // Process the callback
        $result = \app\plugins\GoogleAuth::handleCallback($code, $state);

        if (!$result['success']) {
            $this->flash('error', 'Google sign-in failed: ' . ($result['error'] ?? 'Unknown error'));
            Flight::redirect('/auth/login');
            return;
        }

        $member = $result['member'];

        // Check if account is active
        if ($member->status !== 'active') {
            $this->flash('error', 'Your account is not active. Please contact support.');
            Flight::redirect('/auth/login');
            return;
        }

        // Check 2FA requirements for Google OAuth users too
        if (TwoFactorAuth::needsSetup($member)) {
            $_SESSION['2fa_pending_member_id'] = $member->id;
            $_SESSION['2fa_pending_redirect'] = '/dashboard';
            $this->logger->info('2FA setup required for OAuth user', ['id' => $member->id]);
            Flight::redirect('/auth/2fa-setup');
            return;
        }

        if (TwoFactorAuth::needsVerification($member)) {
            $_SESSION['2fa_pending_member_id'] = $member->id;
            $_SESSION['2fa_pending_redirect'] = '/dashboard';
            $this->logger->info('2FA verification required for OAuth user', ['id' => $member->id]);
            Flight::redirect('/auth/2fa-verify');
            return;
        }

        // Set session
        $_SESSION['member'] = $member->export();

        $this->logger->info('User logged in via Google', [
            'id' => $member->id,
            'email' => $member->email
        ]);

        $displayName = $member->display_name ?: $member->username ?: $member->email;
        $this->flash('success', "Welcome, {$displayName}!");

        Flight::redirect('/dashboard');
    }

    // ==================== Two-Factor Authentication ====================

    /**
     * Show 2FA setup page with QR code
     */
    public function twofaSetup() {
        // Must have pending 2FA setup
        $memberId = $_SESSION['2fa_pending_member_id'] ?? null;
        if (!$memberId) {
            Flight::redirect('/auth/login');
            return;
        }

        $member = Bean::load('member', $memberId);
        if (!$member->id) {
            unset($_SESSION['2fa_pending_member_id']);
            Flight::redirect('/auth/login');
            return;
        }

        $request = Flight::request();

        if ($request->method === 'POST') {
            // Verify the code and enable 2FA
            if (!$this->validateCSRF()) {
                return;
            }

            $secret = $this->getParam('secret');
            $code = $this->getParam('code');

            if (empty($secret) || empty($code)) {
                $this->render('auth/2fa-setup', [
                    'title' => 'Set Up Two-Factor Authentication',
                    'secret' => $secret ?: TwoFactorAuth::generateSecret(),
                    'qrCode' => TwoFactorAuth::generateQrCode($secret, $member->email),
                    'errors' => ['Please enter the verification code']
                ]);
                return;
            }

            $result = TwoFactorAuth::enable($member, $secret, $code);

            if (!$result['success']) {
                $this->render('auth/2fa-setup', [
                    'title' => 'Set Up Two-Factor Authentication',
                    'secret' => $secret,
                    'qrCode' => TwoFactorAuth::generateQrCode($secret, $member->email),
                    'errors' => [$result['error']]
                ]);
                return;
            }

            // Show recovery codes
            $_SESSION['2fa_recovery_codes'] = $result['recovery_codes'];
            Flight::redirect('/auth/twofarecoverycodes');
            return;
        }

        // Generate new secret for setup
        $secret = TwoFactorAuth::generateSecret();
        $qrCode = TwoFactorAuth::generateQrCode($secret, $member->email);

        $this->render('auth/2fa-setup', [
            'title' => 'Set Up Two-Factor Authentication',
            'secret' => $secret,
            'qrCode' => $qrCode
        ]);
    }

    /**
     * Show recovery codes after 2FA setup
     */
    public function twofaRecoveryCodes() {
        $memberId = $_SESSION['2fa_pending_member_id'] ?? null;
        $recoveryCodes = $_SESSION['2fa_recovery_codes'] ?? null;

        if (!$memberId || !$recoveryCodes) {
            Flight::redirect('/auth/login');
            return;
        }

        $this->render('auth/2fa-recovery-codes', [
            'title' => 'Recovery Codes',
            'recoveryCodes' => $recoveryCodes
        ]);
    }

    /**
     * Confirm recovery codes saved and complete login
     */
    public function twofaConfirmSaved() {
        $memberId = $_SESSION['2fa_pending_member_id'] ?? null;
        $redirect = $_SESSION['2fa_pending_redirect'] ?? '/dashboard';

        if (!$memberId) {
            Flight::redirect('/auth/login');
            return;
        }

        $member = Bean::load('member', $memberId);
        if (!$member->id) {
            unset($_SESSION['2fa_pending_member_id']);
            Flight::redirect('/auth/login');
            return;
        }

        // Clear pending state
        unset($_SESSION['2fa_pending_member_id']);
        unset($_SESSION['2fa_pending_redirect']);
        unset($_SESSION['2fa_recovery_codes']);

        // Complete login
        $_SESSION['member'] = $member->export();

        $this->logger->info('2FA setup completed', ['id' => $member->id]);
        $this->flash('success', 'Two-factor authentication is now enabled!');

        Flight::redirect($redirect);
    }

    /**
     * Show 2FA verification page
     */
    public function twofaVerify() {
        $memberId = $_SESSION['2fa_pending_member_id'] ?? null;
        if (!$memberId) {
            Flight::redirect('/auth/login');
            return;
        }

        $member = Bean::load('member', $memberId);
        if (!$member->id) {
            unset($_SESSION['2fa_pending_member_id']);
            Flight::redirect('/auth/login');
            return;
        }

        $request = Flight::request();

        // Check for localStorage trust token (sent via hidden field or query param)
        $trustToken = $this->getParam('trust_token');
        if ($trustToken) {
            $trustedMemberId = TwoFactorAuth::validateTrustToken($trustToken);
            if ($trustedMemberId === (int)$member->id) {
                // Valid trust token - skip 2FA
                $redirect = $_SESSION['2fa_pending_redirect'] ?? '/dashboard';
                unset($_SESSION['2fa_pending_member_id']);
                unset($_SESSION['2fa_pending_redirect']);

                $_SESSION['member'] = $member->export();
                TwoFactorAuth::trustDevice(); // Also set session for this login

                $this->logger->info('2FA skipped via trust token', ['id' => $member->id]);
                Flight::redirect($redirect);
                return;
            }
            // Invalid token - continue to show form
            $this->logger->debug('Invalid 2FA trust token', ['id' => $member->id]);
        }

        if ($request->method === 'POST') {
            if (!$this->validateCSRF()) {
                return;
            }

            $code = $this->getParam('code');
            $trustDevice = (bool)$this->getParam('trust_device');

            if (empty($code)) {
                $this->render('auth/2fa-verify', [
                    'title' => 'Two-Factor Authentication',
                    'errors' => ['Please enter the verification code']
                ]);
                return;
            }

            if (!TwoFactorAuth::verify($member, $code)) {
                $this->logger->warning('2FA verification failed', ['id' => $member->id]);
                $this->render('auth/2fa-verify', [
                    'title' => 'Two-Factor Authentication',
                    'errors' => ['Invalid verification code. Please try again.']
                ]);
                return;
            }

            // Generate trust token for localStorage if requested
            $newTrustToken = null;
            if ($trustDevice) {
                TwoFactorAuth::trustDevice(); // Also set session
                $newTrustToken = TwoFactorAuth::generateTrustToken($member->id);
            }

            // Clear pending state and complete login
            $redirect = $_SESSION['2fa_pending_redirect'] ?? '/dashboard';
            unset($_SESSION['2fa_pending_member_id']);
            unset($_SESSION['2fa_pending_redirect']);

            $_SESSION['member'] = $member->export();

            $this->logger->info('2FA verification successful', ['id' => $member->id]);

            // If we have a trust token, show success page with JS to store it
            if ($newTrustToken) {
                $this->render('auth/2fa-success', [
                    'title' => 'Verification Successful',
                    'trustToken' => $newTrustToken,
                    'redirect' => $redirect
                ]);
                return;
            }

            Flight::redirect($redirect);
            return;
        }

        $this->render('auth/2fa-verify', [
            'title' => 'Two-Factor Authentication'
        ]);
    }

    /**
     * Set password for new accounts (created via team invite)
     */
    public function setpassword() {
        // Check for pending team join (from invite auto-creation)
        $pendingJoin = $_SESSION['pending_team_join'] ?? null;

        if (!$pendingJoin) {
            $this->flash('error', 'Invalid request');
            Flight::redirect('/auth/login');
            return;
        }

        $member = Bean::load('member', $pendingJoin['member_id']);
        if (!$member->id || !$member->needsPasswordSetup) {
            unset($_SESSION['pending_team_join']);
            Flight::redirect('/auth/login');
            return;
        }

        $request = Flight::request();

        if ($request->method === 'POST') {
            if (!$this->validateCSRF()) {
                return;
            }

            $password = $this->getParam('password');
            $passwordConfirm = $this->getParam('password_confirm');
            $errors = [];

            // Validate password
            if (empty($password)) {
                $errors[] = 'Password is required';
            } elseif (strlen($password) < 6) {
                $errors[] = 'Password must be at least 6 characters';
            } elseif ($password !== $passwordConfirm) {
                $errors[] = 'Passwords do not match';
            }

            if (!empty($errors)) {
                $this->render('auth/setpassword', [
                    'title' => 'Set Your Password',
                    'email' => $member->email,
                    'errors' => $errors
                ]);
                return;
            }

            // Set password
            $member->password = password_hash($password, PASSWORD_DEFAULT);
            $member->needsPasswordSetup = 0;
            $member->updatedAt = date('Y-m-d H:i:s');
            Bean::store($member);

            // Complete the team join
            $invitationToken = $pendingJoin['invitation_token'];
            $invitation = Bean::findOne('teaminvitation', 'token = ? AND accepted_at IS NULL', [$invitationToken]);

            if ($invitation) {
                $team = Bean::load('team', $invitation->teamId);

                if ($team->id && $team->isActive) {
                    // Create membership
                    $membership = Bean::dispense('teammember');
                    $membership->teamId = $team->id;
                    $membership->memberId = $member->id;
                    $membership->role = $invitation->role;
                    $membership->canRunTasks = in_array($invitation->role, ['admin', 'member']) ? 1 : 0;
                    $membership->canEditTasks = in_array($invitation->role, ['admin', 'member']) ? 1 : 0;
                    $membership->canDeleteTasks = $invitation->role === 'admin' ? 1 : 0;
                    $membership->joinedAt = date('Y-m-d H:i:s');
                    Bean::store($membership);

                    // Mark invitation as accepted
                    $invitation->acceptedAt = date('Y-m-d H:i:s');
                    Bean::store($invitation);

                    $this->logger->info('User joined team via invite', [
                        'member_id' => $member->id,
                        'team_id' => $team->id,
                        'role' => $invitation->role
                    ]);
                }
            }

            // Clear pending state
            unset($_SESSION['pending_team_join']);

            $this->logger->info('Password set for new account', ['id' => $member->id]);

            // Log them out and require full login (including 2FA if applicable)
            $this->flash('success', 'Account created! Please log in with your new password.');
            Flight::redirect('/auth/login');
            return;
        }

        $this->render('auth/setpassword', [
            'title' => 'Set Your Password',
            'email' => $member->email
        ]);
    }
}