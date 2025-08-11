<?php
/**
 * Bootstrap file - Initializes the application
 * This sets up all core components: autoloading, database, logging, sessions
 */

namespace app;

use \Flight as Flight;
use \RedBeanPHP\R as R;
use \Monolog\Logger;
use \Monolog\Handler\StreamHandler;
use \Monolog\Handler\RotatingFileHandler;
use \Monolog\Formatter\LineFormatter;

class Bootstrap {
    
    private $config;
    private $logger;
    private $cliHandler;
    
    public function __construct($configFile = 'conf/config.ini') {
        // Initialize autoloader first - this must come before any framework usage
        $this->initAutoloader();
        
        // Now load configuration (after autoloader so Flight class is available)
        $this->loadConfig($configFile);
        
        // Check for CLI mode and handle it
        $this->initCLI();
        
        // Initialize remaining components in order
        $this->initLogging();
        $this->initDatabase();
        $this->initSession();
        $this->initFlight();
        $this->initCORS();
        
        // Set up CLI member context if in CLI mode
        if ($this->cliHandler && \app\CliHandler::isCli()) {
            $this->cliHandler->setupMember();
        }
    }
    
    /**
     * Load configuration from INI file
     */
    private function loadConfig($configFile) {
        if (!file_exists($configFile)) {
            die("Configuration file not found: {$configFile}");
        }
        
        $this->config = parse_ini_file($configFile, true);
        
        // Set configuration in Flight
        foreach ($this->config as $section => $values) {
            if (is_array($values)) {
                foreach ($values as $key => $value) {
                    Flight::set("{$section}.{$key}", $value);
                }
            } else {
                Flight::set($section, $values);
            }
        }
    }
    
    /**
     * Initialize Composer autoloader
     */
    private function initAutoloader() {
        $vendorPath = __DIR__ . '/vendor/autoload.php';
        if (!file_exists($vendorPath)) {
            die("Vendor autoload not found. Please run: composer install");
        }
        require_once $vendorPath;
    }
    
    /**
     * Initialize CLI handler if running from command line
     */
    private function initCLI() {
        // Load CLI handler class
        require_once __DIR__ . '/lib/CliHandler.php';
        
        if (\app\CliHandler::isCli()) {
            $this->cliHandler = new \app\CliHandler();
            $this->cliHandler->process();
        }
    }
    
    /**
     * Initialize Monolog logging
     */
    private function initLogging() {
		$config = $this->config;

        $config['logLevel'] = $this->config['logging']['level'] ?? 'DEBUG';
        $config['logFile']  = $this->config['logging']['file'] ?? 'log/app.log';

        // Create log directory if it doesn't exist
        $logDir = dirname($config['logFile']);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

		if (empty($config['log.name'])) $config['log.name'] = 'app';

		// Set up logging for legacy 
		Flight::register('log', 'Monolog\Logger', array($config['log.name']), function($log) use ($config) {
			// Create logger
			$log = new Logger($config['log.name']);
			
			// Create formatter for better readability
			$formatter = new LineFormatter(
				"[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n",
				"Y-m-d H:i:s",
				true,
				true
			);
        
			// Add rotating file handler (new file each day, keep 30 days)
			$handler = new RotatingFileHandler($config['logFile'], 30, constant("Monolog\Logger::{$config['logLevel']}"));
			$handler->setFormatter($formatter);
			$log->pushHandler($handler);

			// Sets up the cached flight logger
			Flight::set('log',$log);
		});

		$this->logger = Flight::log();
    }
    
    /**
     * Initialize RedBeanPHP database connection
     */
    private function initDatabase() {
        $dbConfig = $this->config['database'];
        
        if (empty($dbConfig)) {
            $this->logger->error('Database configuration missing');
            die('Database configuration missing');
        }
        
        try {
            // Construct DSN based on database type
            $type = $dbConfig['type'] ?? 'mysql';
            
            if ($type === 'sqlite') {
                // SQLite configuration
                $dbPath = $dbConfig['path'] ?? 'database/tiknix.db';
                // Create database directory if it doesn't exist
                $dbDir = dirname($dbPath);
                if (!is_dir($dbDir)) {
                    mkdir($dbDir, 0755, true);
                }
                $dsn = "sqlite:{$dbPath}";
                // Setup RedBean for SQLite (no user/pass needed)
                R::setup($dsn);
            } else {
                // MySQL/PostgreSQL configuration
                $host = $dbConfig['host'] ?? 'localhost';
                $port = $dbConfig['port'] ?? 3306;
                $name = $dbConfig['name'] ?? 'app';
                $user = $dbConfig['user'] ?? 'root';
                $pass = $dbConfig['pass'] ?? '';
                
                $dsn = "{$type}:host={$host};port={$port};dbname={$name}";
                
                // Setup RedBean
                R::setup($dsn, $user, $pass);
            }
            
            // Set freeze mode based on environment
            $freeze = $this->config['app']['environment'] === 'production';
            R::freeze($freeze);
            
            // Enable query logging in debug mode
            if ($this->config['app']['debug'] ?? false) {
                R::debug(true, 1);
            }
            
            $this->logger->info('Database connected');
            
        } catch (\Exception $e) {
            $this->logger->error('Database connection failed: ' . $e->getMessage());
            die('Database connection failed');
        }
    }
    
    /**
     * Initialize PHP session with security settings
     */
    private function initSession() {
        // Session configuration for security
        ini_set('session.use_only_cookies', 1);
        ini_set('session.use_strict_mode', 1);
        ini_set('session.cookie_httponly', 1);
        ini_set('session.cookie_samesite', 'Lax');
        
        // Use HTTPS for cookies in production
        if ($this->config['app']['environment'] === 'production') {
            ini_set('session.cookie_secure', 1);
        }
        
        // Set session name
        session_name($this->config['app']['session_name'] ?? 'APP_SESSION');
        
        // Set session lifetime (default 24 hours)
        $lifetime = $this->config['app']['session_lifetime'] ?? 86400;
        ini_set('session.gc_maxlifetime', $lifetime);
        session_set_cookie_params($lifetime);
        
        // Start session
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
            $this->logger->debug('Session started', ['id' => session_id()]);
        }
    }
    
    /**
     * Initialize Flight framework settings
     */
    private function initFlight() {
        // Set Flight configuration
        Flight::set('flight.views.path', __DIR__ . '/views');
        Flight::set('flight.log_errors', true);
        Flight::set('baseurl', $this->config['app']['baseurl'] ?? '/');
        Flight::set('debug', $this->config['app']['debug'] ?? false);
        Flight::set('build', $this->config['app']['build_mode'] ?? false);
        
        // Load FlightMap extensions
        require_once __DIR__ . '/lib/FlightMap.php';
        
        // Load utility functions if exists
        if (file_exists(__DIR__ . '/lib/functions.php')) {
            require_once __DIR__ . '/lib/functions.php';
        }
        
        $this->logger->info('Flight framework initialized');
    }
    
    /**
     * Initialize CORS headers for API access
     */
    private function initCORS() {
        // Only set CORS headers if configured
        if ($this->config['cors']['enabled'] ?? false) {
            $origin = $this->config['cors']['origin'] ?? '*';
            $methods = $this->config['cors']['methods'] ?? 'GET, POST, PUT, DELETE, OPTIONS';
            $headers = $this->config['cors']['headers'] ?? 'Content-Type, Authorization';
            
            header("Access-Control-Allow-Origin: {$origin}");
            header("Access-Control-Allow-Methods: {$methods}");
            header("Access-Control-Allow-Headers: {$headers}");
            
            // Handle preflight requests
            if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
                http_response_code(200);
                exit();
            }
        }
    }
    
    /**
     * Get logger instance
     */
    public function getLogger() {
        return $this->logger;
    }
    
    /**
     * Get configuration
     */
    public function getConfig($key = null) {
        if ($key === null) {
            return $this->config;
        }
        
        // Support dot notation
        $keys = explode('.', $key);
        $value = $this->config;
        
        foreach ($keys as $k) {
            if (isset($value[$k])) {
                $value = $value[$k];
            } else {
                return null;
            }
        }
        
        return $value;
    }
    
    /**
     * Run the application
     */
    public function run() {
        $this->logger->info('Starting Flight application');
        
        // Start Flight framework
        Flight::start();
    }
}
