<?php
/**
 * AuthHandler - Native OpenSwoole authentication handler
 *
 * Handles login, logout, registration, password reset
 */

namespace Tiknix\Swoole\Handlers;

use OpenSwoole\Http\Request;
use OpenSwoole\Http\Response;
use Tiknix\Swoole\Session\SwooleSessionManager;
use RedBeanPHP\R;

class AuthHandler extends BaseHandler
{
    /**
     * Route the request to appropriate method
     */
    public function handle(string $action): void
    {
        match ($action) {
            'login' => $this->login(),
            'dologin' => $this->dologin(),
            'logout' => $this->logout(),
            'register' => $this->register(),
            'doregister' => $this->doregister(),
            'forgot' => $this->forgot(),
            'doforgot' => $this->doforgot(),
            'reset' => $this->reset(),
            'doreset' => $this->doreset(),
            default => $this->notFound($action),
        };
    }

    /**
     * Show login form
     */
    public function login(): void
    {
        $redirect = $this->getParam('redirect', '');

        // Already logged in?
        if ($this->isLoggedIn() && empty($redirect)) {
            $this->redirect('/dashboard');
            return;
        }

        // If logged in but redirected due to permission issues
        if ($this->isLoggedIn() && !empty($redirect)) {
            $this->flash('error', 'You do not have permission to access that page.');
        }

        // Google OAuth config values (passed to view for Flight::get() compatibility)
        $googleClientId = $this->config['social']['google_client_id'] ?? '';
        $googleClientSecret = $this->config['social']['google_client_secret'] ?? '';

        $this->render('auth/login', [
            'title' => 'Login',
            'redirect' => $redirect,
            'csrf' => $this->generateCsrfToken(),
            'google_client_id' => $googleClientId,
            'google_client_secret' => $googleClientSecret,
            'debug' => $this->config['app']['debug'] ?? false,
        ]);
    }

    /**
     * Process login form
     */
    public function dologin(): void
    {
        $method = $this->request->server['request_method'] ?? 'GET';
        if ($method !== 'POST') {
            $this->redirect('/auth/login');
            return;
        }

        // Validate CSRF
        if (!$this->validateCsrf()) {
            $this->flash('error', 'Security validation failed. Please try again.');
            $this->redirect('/auth/login');
            return;
        }

        // Get input
        $username = $this->getParam('username', '');
        $email = $this->getParam('email', '');
        $password = $this->getParam('password', '');
        $redirect = $this->getParam('redirect', '/dashboard');

        // Use username if provided, otherwise email
        $login = $username ?: $email;

        // Validate input
        if (empty($login) || empty($password)) {
            $this->flash('error', 'Username/Email and password are required');
            $this->redirect('/auth/login');
            return;
        }

        try {
            // Find member by username or email
            $member = R::findOne('member', '(username = ? OR email = ?) AND status = ?',
                [$login, $login, 'active']);

            if (!$member || !password_verify($password, $member->password)) {
                $this->log("Failed login attempt for: {$login}", 'WARNING');
                $this->flash('error', 'Invalid credentials');
                $this->redirect('/auth/login');
                return;
            }

            // Update last login
            $member->lastLogin = date('Y-m-d H:i:s');
            $member->loginCount = ($member->loginCount ?? 0) + 1;
            R::store($member);

            // Regenerate session for security
            $this->session->regenerate();

            // Set member in session
            $this->session->setMember($member->export());

            $this->log("User logged in: {$member->username} (ID: {$member->id})");

            // Start session cookie on response
            $this->session->start($this->response);
            $this->redirect($redirect);

        } catch (\Exception $e) {
            $this->log("Login error: " . $e->getMessage(), 'ERROR');
            $this->flash('error', 'Login failed. Please try again.');
            $this->redirect('/auth/login');
        }
    }

    /**
     * Logout user
     */
    public function logout(): void
    {
        $member = $this->getMember();
        if ($member) {
            $this->log("User logged out: {$member['username']} (ID: {$member['id']})");
        }

        // Clear session data but keep the session ID for flash messages
        $this->session->clear();

        // Add flash message using existing session
        $this->session->flash('success', 'You have been logged out');

        $this->redirect('/');
    }

    /**
     * Show registration form
     */
    public function register(): void
    {
        if ($this->isLoggedIn()) {
            $this->redirect('/dashboard');
            return;
        }

        $this->render('auth/register', [
            'title' => 'Register',
            'csrf' => $this->generateCsrfToken(),
        ]);
    }

    /**
     * Process registration
     */
    public function doregister(): void
    {
        $method = $this->request->server['request_method'] ?? 'GET';
        if ($method !== 'POST') {
            $this->register();
            return;
        }

        // Validate CSRF
        if (!$this->validateCsrf()) {
            $this->flash('error', 'Security validation failed.');
            $this->redirect('/auth/register');
            return;
        }

        // Get input
        $username = $this->sanitize($this->getParam('username'));
        $email = $this->sanitize($this->getParam('email'), 'email');
        $password = $this->getParam('password');
        $passwordConfirm = $this->getParam('password_confirm');

        // Validate
        $errors = [];

        if (empty($username) || strlen($username) < 3) {
            $errors[] = 'Username must be at least 3 characters';
        }

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Valid email is required';
        }

        if (empty($password) || strlen($password) < 6) {
            $errors[] = 'Password must be at least 6 characters';
        }

        if ($password !== $passwordConfirm) {
            $errors[] = 'Passwords do not match';
        }

        // Check existing email/username
        if (R::count('member', 'email = ?', [$email]) > 0) {
            $errors[] = 'Email already registered';
        }

        if (R::count('member', 'username = ?', [$username]) > 0) {
            $errors[] = 'Username already taken';
        }

        if (!empty($errors)) {
            $this->render('auth/register', [
                'title' => 'Register',
                'errors' => $errors,
                'data' => [
                    'username' => $username,
                    'email' => $email,
                ],
                'csrf' => $this->generateCsrfToken(),
            ]);
            return;
        }

        try {
            // Create member
            $member = R::dispense('member');
            $member->email = $email;
            $member->username = $username;
            $member->password = password_hash($password, PASSWORD_DEFAULT);
            $member->level = 100; // LEVELS['MEMBER']
            $member->status = 'active';
            $member->createdAt = date('Y-m-d H:i:s');

            $id = R::store($member);

            $this->log("New user registered: {$username} (ID: {$id})");

            // Auto-login
            $memberData = $member->export();
            $memberData['id'] = $id;
            $this->session->setMember($memberData);

            $this->flash('success', 'Welcome! Your account has been created.');
            $this->session->start($this->response);
            $this->redirect('/dashboard');

        } catch (\Exception $e) {
            $this->log("Registration error: " . $e->getMessage(), 'ERROR');
            $this->render('auth/register', [
                'title' => 'Register',
                'errors' => ['Registration failed. Please try again.'],
                'data' => [
                    'username' => $username,
                    'email' => $email,
                ],
                'csrf' => $this->generateCsrfToken(),
            ]);
        }
    }

    /**
     * Show forgot password form
     */
    public function forgot(): void
    {
        $this->render('auth/forgot', [
            'title' => 'Forgot Password',
            'csrf' => $this->generateCsrfToken(),
        ]);
    }

    /**
     * Process forgot password
     */
    public function doforgot(): void
    {
        if (!$this->validateCsrf()) {
            $this->redirect('/auth/forgot');
            return;
        }

        $email = $this->sanitize($this->getParam('email'), 'email');

        if (empty($email)) {
            $this->flash('error', 'Email is required');
            $this->redirect('/auth/forgot');
            return;
        }

        try {
            $member = R::findOne('member', 'email = ? AND status = ?', [$email, 'active']);

            if ($member) {
                // Generate reset token
                $token = bin2hex(random_bytes(32));
                $member->resetToken = $token;
                $member->resetExpires = date('Y-m-d H:i:s', strtotime('+1 hour'));
                R::store($member);

                $resetUrl = ($this->config['app']['baseurl'] ?? '') . "/auth/reset?token={$token}";
                $this->log("Password reset requested for: {$email}");

                // TODO: Send email with $resetUrl
            }

            // Always show success to prevent email enumeration
            $this->flash('success', 'If the email exists, a reset link has been sent');
            $this->redirect('/auth/login');

        } catch (\Exception $e) {
            $this->log("Forgot password error: " . $e->getMessage(), 'ERROR');
            $this->flash('error', 'Password reset failed');
            $this->redirect('/auth/forgot');
        }
    }

    /**
     * Show reset password form
     */
    public function reset(): void
    {
        $token = $this->getParam('token');

        if (empty($token)) {
            $this->flash('error', 'Invalid reset link');
            $this->redirect('/auth/login');
            return;
        }

        $member = R::findOne('member', 'reset_token = ? AND reset_expires > ?',
            [$token, date('Y-m-d H:i:s')]);

        if (!$member) {
            $this->flash('error', 'Invalid or expired reset link');
            $this->redirect('/auth/login');
            return;
        }

        $this->render('auth/reset', [
            'title' => 'Reset Password',
            'token' => $token,
            'csrf' => $this->generateCsrfToken(),
        ]);
    }

    /**
     * Process password reset
     */
    public function doreset(): void
    {
        if (!$this->validateCsrf()) {
            $this->redirect('/auth/login');
            return;
        }

        $token = $this->getParam('token');
        $password = $this->getParam('password');
        $passwordConfirm = $this->getParam('password_confirm');

        if (empty($token) || empty($password)) {
            $this->flash('error', 'Invalid request');
            $this->redirect('/auth/login');
            return;
        }

        if (strlen($password) < 8) {
            $this->flash('error', 'Password must be at least 8 characters');
            $this->redirect("/auth/reset?token={$token}");
            return;
        }

        if ($password !== $passwordConfirm) {
            $this->flash('error', 'Passwords do not match');
            $this->redirect("/auth/reset?token={$token}");
            return;
        }

        try {
            $member = R::findOne('member', 'reset_token = ? AND reset_expires > ?',
                [$token, date('Y-m-d H:i:s')]);

            if (!$member) {
                $this->flash('error', 'Invalid or expired reset link');
                $this->redirect('/auth/login');
                return;
            }

            // Update password
            $member->password = password_hash($password, PASSWORD_DEFAULT);
            $member->resetToken = null;
            $member->resetExpires = null;
            R::store($member);

            $this->log("Password reset completed for member ID: {$member->id}");

            $this->flash('success', 'Password reset successful! Please login with your new password');
            $this->redirect('/auth/login');

        } catch (\Exception $e) {
            $this->log("Password reset error: " . $e->getMessage(), 'ERROR');
            $this->flash('error', 'Password reset failed');
            $this->redirect('/auth/login');
        }
    }

    /**
     * Handle unknown actions
     */
    private function notFound(string $action): void
    {
        $this->response->status(404);
        $this->response->header('Content-Type', 'text/html');
        $this->response->end("<h1>404 Not Found</h1><p>Auth action not found: {$action}</p>");
    }
}
