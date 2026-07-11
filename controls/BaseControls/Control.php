<?php
/**
 * Base Controller Class
 * All controllers should extend this class
 */

namespace app\BaseControls;

use \Flight as Flight;
use \app\Bean;
use \app\SimpleCsrf;
use \Exception as Exception;

abstract class Control {
    
    protected $logger;
    protected $member;
    protected $viewData = [];
    protected $routeParams = [];

    public function __construct() {
        $this->logger = Flight::get('log');
        $this->member = Flight::getMember();

        // i18n locale (no-op unless the Translatify package is installed). Locale
        // comes from the member's preference, overridable per-request with ?lang=xx
        // for testing; untranslated strings render their English source.
        if (class_exists('\Translatify\Translator')) {
            $locale = (string)($this->member->locale ?? '');
            $qlang  = (string)(Flight::request()->query->lang ?? '');
            if ($qlang !== '' && preg_match('/^[a-z]{2}(-[A-Z]{2})?$/', $qlang)) $locale = $qlang;
            if (!preg_match('/^[a-z]{2}(-[A-Z]{2})?$/', $locale)) $locale = 'en';
            \Translatify\Translator::register(dirname(__DIR__, 2) . '/lang')
                ->setLocale($locale)->setFallback('en');
        }

        // Initialize view data
        $this->viewData = [
            'member' => $this->member,
            'isLoggedIn' => Flight::isLoggedIn(),
            'menu' => Flight::loadMenu(),
            'title' => 'App',
            'csrf' => SimpleCsrf::getTokenArray()
        ];
        
        $this->logger->debug('Controller initialized: ' . get_class($this));
    }

    /**
     * Gate a whole controller to the control plane: the builder tooling (AI
     * Builder, Workbench, Agent Setup) is unavailable inside a sandbox instance.
     * Call from a controller constructor; redirects + halts when disabled.
     */
    protected function requireBuilderTools(string $label): void {
        if (!builder_tools_enabled()) {
            $this->flash('info', $label . ' is only available on the root control plane, not inside a sandbox instance.');
            Flight::redirect('/dashboard');
            exit;
        }
    }
    
    /**
     * Render a view with the sandwich layout (header/footer)
     */
    protected function render($template, $data = [], $layout = true) {
        // Merge with default view data
        $data = array_merge($this->viewData, $data);
        
        // Log the render action
        $this->logger->debug("Rendering view: {$template}");
        
        if ($layout) {
            // Render with layout (header/footer sandwich)
            Flight::render('layouts/header', $data, 'header_content');
            Flight::render($template, $data, 'body_content');
            Flight::render('layouts/footer', $data, 'footer_content');
            Flight::render('layouts/layout', $data);
        } else {
            // Render without layout (for AJAX, modals, etc)
            Flight::render($template, $data);
        }
    }
    
    /**
     * Check if user has required permission level
     */
    protected function requireLevel($level) {
        if (!Flight::hasLevel($level)) {
            $this->logger->warning('Access denied - insufficient level', [
                'required' => $level,
                'actual' => $this->member->level,
                'user' => $this->member->id
            ]);
            
            if (Flight::request()->ajax) {
                Flight::jsonError('Access denied', 403);
            } else {
                Flight::redirect('/error/forbidden');
            }
            return false;
        }
        return true;
    }
    
    /**
     * Check if user is logged in
     */
    protected function requireLogin() {
        if (!Flight::isLoggedIn()) {
            $this->logger->debug('Login required');
            
            if (Flight::request()->ajax) {
                Flight::jsonError('Login required', 401);
            } else {
                $redirect = urlencode(Flight::request()->url);
                Flight::redirect("/auth/login?redirect={$redirect}");
            }
            return false;
        }
        return true;
    }
    
    /**
     * Validate CSRF token
     */
    protected function validateCSRF() {
        if (Flight::request()->method !== 'GET') {
            if (!SimpleCsrf::validateRequest()) {
                $this->logger->warning('CSRF validation failed');

                if (Flight::request()->ajax) {
                    Flight::jsonError('CSRF validation failed', 403);
                } else {
                    Flight::redirect('/error/forbidden');
                }
                return false;
            }
        }
        return true;
    }
    
    /**
     * Get request parameter with optional default
     */
    protected function getParam($key, $default = null) {
        return Flight::request()->data->$key ?? 
               Flight::request()->query->$key ?? 
               $_REQUEST[$key] ?? 
               $default;
    }
    
    /**
     * Get all request parameters
     */
    protected function getParams() {
        return array_merge(
            (array)Flight::request()->query->getData(),
            (array)Flight::request()->data->getData(),
            $_REQUEST
        );
    }
    
    /**
     * Sanitize user input
     */
    protected function sanitize($input, $type = 'string') {
        switch ($type) {
            case 'email':
                return filter_var($input, FILTER_SANITIZE_EMAIL);
            
            case 'int':
                return (int)filter_var($input, FILTER_SANITIZE_NUMBER_INT);
            
            case 'float':
                return (float)filter_var($input, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
            
            case 'url':
                return filter_var($input, FILTER_SANITIZE_URL);
            
            case 'html':
                return htmlspecialchars(($input) ?? '', ENT_QUOTES, 'UTF-8');
            
            case 'string':
            default:
                // FILTER_SANITIZE_STRING is deprecated in PHP 8.1+
                // Use htmlspecialchars instead for basic string sanitization
                return htmlspecialchars($input ?? '', ENT_QUOTES, 'UTF-8');
        }
    }
    
    /**
     * Handle file upload
     */
    protected function handleUpload($fieldName, $allowedTypes = [], $maxSize = 5242880) {
        if (!isset($_FILES[$fieldName])) {
            throw new Exception('No file uploaded');
        }
        
        $file = $_FILES[$fieldName];
        
        // Check for upload errors
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('Upload failed with error code: ' . $file['error']);
        }
        
        // Check file size
        if ($file['size'] > $maxSize) {
            throw new Exception('File too large. Maximum size: ' . ($maxSize / 1048576) . 'MB');
        }
        
        // Check file type if restrictions are set
        if (!empty($allowedTypes)) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            
            if (!in_array($mimeType, $allowedTypes)) {
                throw new Exception('File type not allowed');
            }
        }
        
        // Generate safe filename
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $safeFilename = uniqid() . '_' . time() . '.' . $extension;
        
        return [
            'tmp_name' => $file['tmp_name'],
            'original_name' => $file['name'],
            'safe_name' => $safeFilename,
            'size' => $file['size'],
            'type' => $file['type']
        ];
    }
    
    /**
     * Send JSON response
     */
    protected function json($data, $code = 200) {
        Flight::json($data, $code);
    }
    
    /**
     * Send success JSON response
     */
    protected function jsonSuccess($data = [], $message = 'Success') {
        Flight::jsonSuccess($data, $message);
    }
    
    /**
     * Send error JSON response
     */
    protected function jsonError($message = 'Error', $code = 400) {
        Flight::jsonError($message, $code);
    }
    
    /**
     * Log and handle exceptions
     */
    protected function handleException(Exception $e, $userMessage = null) {
        $this->logger->error('Exception in ' . get_class($this) . ': ' . $e->getMessage(), [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ]);
        
        if (Flight::request()->ajax) {
            $this->jsonError($userMessage ?? 'An error occurred', 500);
        } else {
            Flight::set('error_message', $userMessage ?? 'An error occurred');
            $this->render('error/500', [
                'title' => 'Error',
                'message' => $userMessage ?? 'An error occurred',
                'debug' => Flight::get('debug') ? $e->getMessage() : null
            ]);
        }
    }
    
    /**
     * Start database transaction
     */
    protected function beginTransaction() {
        Bean::begin();
        $this->logger->debug('Transaction started');
    }
    
    /**
     * Commit database transaction
     */
    protected function commit() {
        Bean::commit();
        $this->logger->debug('Transaction committed');
    }
    
    /**
     * Rollback database transaction
     */
    protected function rollback() {
        Bean::rollback();
        $this->logger->debug('Transaction rolled back');
    }
    
    /**
     * Add flash message to session
     */
    protected function flash($type, $message) {
        if (!isset($_SESSION['flash'])) {
            $_SESSION['flash'] = [];
        }
        $_SESSION['flash'][] = [
            'type' => $type,
            'message' => $message
        ];
    }
    
    /**
     * Get and clear flash messages
     */
    protected function getFlashMessages() {
        $messages = $_SESSION['flash'] ?? [];
        unset($_SESSION['flash']);
        return $messages;
    }

    /**
     * Stash the router's parsed params on the instance so opId()/opType() can
     * read them. Called by FlightMap before dispatching the controller method.
     */
    public function setRouteParams(array $params) {
        $this->routeParams = $params;
    }

    /**
     * The URL "operation" segment — /class/method/<op>/<opid>. Scaffolded CRUD
     * uses this as the record id (e.g. /product/edit/5 -> opId() === '5').
     */
    protected function opId() {
        $op = $this->routeParams['operation'] ?? null;
        if (is_object($op)) return $op->name ?? null;
        if (is_array($op))  return $op['name'] ?? null;
        return null;
    }

    /**
     * The URL "opid" segment — /class/method/op/<opid>.
     */
    protected function opType() {
        $op = $this->routeParams['operation'] ?? null;
        if (is_object($op)) return $op->type ?? null;
        if (is_array($op))  return $op['type'] ?? null;
        return null;
    }

    /**
     * Decode a JSON request body to an array (for API controllers). Returns null
     * when the body is empty or not valid JSON.
     */
    protected function getJsonInput(): ?array {
        $raw = file_get_contents('php://input');
        if ($raw === '' || $raw === false) return null;
        $data = json_decode(($raw) ?? '', true);
        return is_array($data) ? $data : null;
    }

    /**
     * Guard: require the request be POST. Halts with 405 otherwise.
     */
    protected function requirePost() {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            Flight::halt(405, 'Method Not Allowed');
            return false;
        }
        return true;
    }
}