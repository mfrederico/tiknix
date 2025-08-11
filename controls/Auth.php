<?php
/**
 * Authentication Controller
 * Handles login, logout, registration, password reset
 */

namespace app;

use \Flight as Flight;
use \RedBeanPHP\R as R;
use \Exception as Exception;

class Auth extends BaseControls\Control {
    
    /**
     * Show login form
     */
    public function login() {
        // Redirect if already logged in
        if (Flight::isLoggedIn()) {
            Flight::redirect('/dashboard');
            return;
        }
        
        $this->render('auth/login', [
            'title' => 'Login',
            'redirect' => Flight::request()->query->redirect ?? ''
        ]);
    }
    
    /**
     * Process login
     */
    public function dologin() {
        try {
            // TODO: Fix CSRF validation
            // Temporarily disabled for debugging
            // if (!$this->validateCSRF()) {
            //     return;
            // }
            
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
            $member = R::findOne('member', '(username = ? OR email = ?) AND status = ?', [$login, $login, 'active']);
            
            if (!$member || !password_verify($password, $member->password)) {
                $this->logger->warning('Failed login attempt', ['login' => $login]);
                $this->flash('error', 'Invalid credentials');
                Flight::redirect('/auth/login');
                return;
            }
            
            // Update last login
            $member->last_login = date('Y-m-d H:i:s');
            $member->login_count = ($member->login_count ?? 0) + 1;
            R::store($member);
            
            // Set session
            $_SESSION['member'] = $member->export();
            
            $this->logger->info('User logged in', ['id' => $member->id, 'username' => $member->username]);
            $this->flash('success', 'Welcome back, ' . ($member->username ?? $member->email) . '!');
            
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
        
        // Clear session
        session_destroy();
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
        
        if (empty($password) || strlen($password) < 6) {
            $errors[] = 'Password must be at least 6 characters';
        }
        
        if ($password !== $password_confirm) {
            $errors[] = 'Passwords do not match';
        }
        
        // Check if email exists
        if (R::count('member', 'email = ?', [$email]) > 0) {
            $errors[] = 'Email already registered';
        }
        
        // Check if username exists
        if (R::count('member', 'username = ?', [$username]) > 0) {
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
            $member = R::dispense('member');
            $member->email = $email;
            $member->username = $username;
            $member->password = password_hash($password, PASSWORD_DEFAULT);
            $member->level = LEVELS['MEMBER'];
            $member->status = 'active'; // Active immediately - no email verification
            $member->created_at = date('Y-m-d H:i:s');
            
            $id = R::store($member);
            
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
            
            $email = $this->sanitize($this->getParam('email'), 'email');
            
            if (empty($email)) {
                $this->flash('error', 'Email is required');
                Flight::redirect('/auth/forgot');
                return;
            }
            
            $member = R::findOne('member', 'email = ? AND status = ?', [$email, 'active']);
            
            if ($member) {
                // Generate reset token
                $token = bin2hex(random_bytes(32));
                $member->reset_token = $token;
                $member->reset_expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
                R::store($member);
                
                // Send reset email (implement your email service)
                $resetUrl = Flight::get('app.baseurl') . "/auth/reset?token={$token}";
                
                // TODO: Send email with $resetUrl
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
        
        $member = R::findOne('member', 'reset_token = ? AND reset_expires > ?', 
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
            $member = R::findOne('member', 'reset_token = ? AND reset_expires > ?', 
                [$token, date('Y-m-d H:i:s')]);
            
            if (!$member) {
                $this->flash('error', 'Invalid or expired reset link');
                Flight::redirect('/auth/login');
                return;
            }
            
            // Update password
            $member->password = password_hash($password, PASSWORD_DEFAULT);
            $member->reset_token = null;
            $member->reset_expires = null;
            R::store($member);
            
            $this->logger->info('Password reset completed', ['id' => $member->id]);
            
            $this->flash('success', 'Password reset successful! Please login with your new password');
            Flight::redirect('/auth/login');
            
        } catch (Exception $e) {
            $this->handleException($e, 'Password reset failed');
        }
    }
}