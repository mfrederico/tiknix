<?php
/**
 * BaseHandler - Base class for native OpenSwoole request handlers
 *
 * Provides common functionality for session, rendering, redirects, etc.
 */

namespace Tiknix\Swoole\Handlers;

use OpenSwoole\Http\Request;
use OpenSwoole\Http\Response;
use Tiknix\Swoole\Session\SwooleSessionManager;
use Tiknix\Swoole\View\ViewRenderer;
use RedBeanPHP\R;

abstract class BaseHandler
{
    protected Request $request;
    protected Response $response;
    protected SwooleSessionManager $session;
    protected ViewRenderer $view;
    protected array $config;

    public function __construct(
        Request $request,
        Response $response,
        SwooleSessionManager $session,
        array $config = []
    ) {
        $this->request = $request;
        $this->response = $response;
        $this->session = $session;
        $this->config = $config;

        // Initialize view renderer with global data
        $this->view = new ViewRenderer();
        $this->initViewGlobals();
    }

    /**
     * Initialize global view data
     */
    protected function initViewGlobals(): void
    {
        $member = $this->session->getMember();
        $isLoggedIn = $this->session->isLoggedIn();
        $flash = $this->session->getFlashMessages();

        // Don't treat public/system users as logged in
        // These are placeholder entities for system events, not real users
        if ($member && isset($member['username'])) {
            $username = strtolower($member['username'] ?? '');
            if (in_array($username, ['public', 'system', 'guest', 'public-user-entity'])) {
                $isLoggedIn = false;
                $member = null;
            }
        }

        // Set up a mock $_SESSION for template compatibility
        // This allows templates that use $_SESSION['flash'] to work
        $_SESSION = [
            'flash' => $flash,
            'member' => $member,
        ];

        $this->view->setGlobals([
            'member' => $member,
            'isLoggedIn' => $isLoggedIn,
            'site_name' => $this->config['app']['name'] ?? 'Tiknix',
            'site_description' => $this->config['app']['description'] ?? 'A modern PHP application',
            'menu' => $this->loadMenu(),
            'csrf' => $this->generateCsrfToken(),
            '_flash' => $flash,
            'debug' => $this->config['app']['debug'] ?? false,
        ]);
    }

    /**
     * Render a view with layout
     */
    protected function render(string $template, array $data = []): void
    {
        $html = $this->view->renderWithLayout($template, $data);
        $this->html($html);
    }

    /**
     * Render a partial (no layout)
     */
    protected function partial(string $template, array $data = []): void
    {
        $html = $this->view->render($template, $data);
        $this->html($html);
    }

    /**
     * Send HTML response
     */
    protected function html(string $content, int $status = 200): void
    {
        // Set session cookie before sending response
        $this->session->start($this->response);

        $this->response->status($status);
        $this->response->header('Content-Type', 'text/html; charset=UTF-8');
        $this->response->end($content);
    }

    /**
     * Send JSON response
     */
    protected function json(array $data, int $status = 200): void
    {
        // Set session cookie before sending response
        $this->session->start($this->response);

        $this->response->status($status);
        $this->response->header('Content-Type', 'application/json');
        $this->response->end(json_encode($data));
    }

    /**
     * Send success JSON
     */
    protected function jsonSuccess(array $data = [], string $message = 'Success'): void
    {
        $this->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ]);
    }

    /**
     * Send error JSON
     */
    protected function jsonError(string $message, int $status = 400): void
    {
        $this->json([
            'success' => false,
            'error' => $message,
        ], $status);
    }

    /**
     * Redirect to URL
     */
    protected function redirect(string $url, int $status = 302): void
    {
        // Set session cookie before redirecting
        $this->session->start($this->response);

        $this->response->status($status);
        $this->response->header('Location', $url);
        $this->response->end();
    }

    /**
     * Add flash message
     */
    protected function flash(string $type, string $message): void
    {
        $this->session->flash($type, $message);
    }

    /**
     * Get request parameter (POST, GET, or body)
     */
    protected function getParam(string $key, mixed $default = null): mixed
    {
        // Check POST first
        if (isset($this->request->post[$key])) {
            return $this->request->post[$key];
        }

        // Then GET
        if (isset($this->request->get[$key])) {
            return $this->request->get[$key];
        }

        return $default;
    }

    /**
     * Get all parameters
     */
    protected function getParams(): array
    {
        return array_merge(
            $this->request->get ?? [],
            $this->request->post ?? []
        );
    }

    /**
     * Sanitize input
     */
    protected function sanitize(mixed $input, string $type = 'string'): mixed
    {
        if ($input === null) {
            return '';
        }

        return match ($type) {
            'email' => filter_var($input, FILTER_SANITIZE_EMAIL),
            'int' => (int) filter_var($input, FILTER_SANITIZE_NUMBER_INT),
            'float' => (float) filter_var($input, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION),
            'url' => filter_var($input, FILTER_SANITIZE_URL),
            'html', 'string' => htmlspecialchars((string) $input, ENT_QUOTES, 'UTF-8'),
            default => htmlspecialchars((string) $input, ENT_QUOTES, 'UTF-8'),
        };
    }

    /**
     * Validate CSRF token
     * Respects the csrf_enabled config setting
     */
    protected function validateCsrf(): bool
    {
        // Check if CSRF is disabled in config
        $csrfEnabled = $this->config['security']['csrf_enabled'] ?? true;
        if (!$csrfEnabled) {
            return true; // Bypass validation when disabled
        }

        $token = $this->getParam('csrf_token') ?? $this->getParam('_token');
        $storedToken = $this->session->get('csrf_token');

        if (empty($token) || empty($storedToken)) {
            return false;
        }

        return hash_equals($storedToken, $token);
    }

    /**
     * Generate CSRF token
     */
    protected function generateCsrfToken(): array
    {
        $token = $this->session->get('csrf_token');
        if (empty($token)) {
            $token = bin2hex(random_bytes(32));
            $this->session->set('csrf_token', $token);
        }

        return [
            'csrf_token' => $token,
            '_token' => $token,
        ];
    }

    /**
     * Check if request is AJAX
     */
    protected function isAjax(): bool
    {
        return ($this->request->header['x-requested-with'] ?? '') === 'XMLHttpRequest'
            || str_contains($this->request->header['accept'] ?? '', 'application/json');
    }

    /**
     * Check if user is logged in
     */
    protected function isLoggedIn(): bool
    {
        return $this->session->isLoggedIn();
    }

    /**
     * Get current member
     */
    protected function getMember(): ?array
    {
        return $this->session->getMember();
    }

    /**
     * Require login - redirects if not logged in
     */
    protected function requireLogin(): bool
    {
        if (!$this->isLoggedIn()) {
            $redirect = urlencode($this->request->server['request_uri'] ?? '/');
            $this->redirect("/auth/login?redirect={$redirect}");
            return false;
        }
        return true;
    }

    /**
     * Require specific permission level
     */
    protected function requireLevel(int $level): bool
    {
        $member = $this->getMember();
        $memberLevel = $member['level'] ?? 101;

        if ($memberLevel > $level) {
            if ($this->isAjax()) {
                $this->jsonError('Access denied', 403);
            } else {
                $this->flash('error', 'You do not have permission to access this page.');
                $this->redirect('/');
            }
            return false;
        }
        return true;
    }

    /**
     * Load navigation menu (simplified version)
     */
    protected function loadMenu(): array
    {
        // Return a basic menu - can be enhanced to load from config/database
        return [
            ['label' => 'Home', 'url' => '/', 'icon' => 'house'],
            ['label' => 'Dashboard', 'url' => '/dashboard', 'icon' => 'speedometer2'],
        ];
    }

    /**
     * Log message (outputs to server console)
     */
    protected function log(string $message, string $level = 'INFO'): void
    {
        $timestamp = date('Y-m-d H:i:s');
        echo "[{$timestamp}] [{$level}] {$message}\n";
    }
}
