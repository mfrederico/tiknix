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
    
    public function __construct($configFile = null) {
        // Default config path relative to this file
        if ($configFile === null) {
            $configFile = __DIR__ . '/conf/config.ini';
        } elseif ($configFile[0] !== '/') {
            $configFile = __DIR__ . '/' . $configFile;
        }
        // PHP 8.5 turned many "null passed to a string parameter" calls into E_DEPRECATED
        // notices. Flight's error handler throws an ErrorException on anything still in
        // error_reporting(), so a single unguarded substr($bean->field) / strtotime(...) /
        // ucfirst(...) on a null fluid column would take down the whole request as a 404.
        // Drop deprecations from the throwing set — they are still logged, but never crash
        // a page. Real warnings and errors continue to throw and be handled as before.
        error_reporting(error_reporting() & ~E_DEPRECATED & ~E_USER_DEPRECATED);

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
        \Flight::set("core.config_file", $configFile);   // let the sidecar-kit Registry find core config from vendor/

        // Set timezone from config (defaults to UTC)
        $timezone = $this->config['app']['timezone'] ?? 'UTC';
        date_default_timezone_set($timezone);

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

        // ANCHORED TO THE INSTALL, never to the working directory.
        //
        // [logging] file is a relative path in every config we ship ("log/app.log"), and a
        // relative path resolves against the CWD. public/index.php does chdir(BASE_PATH)
        // so normal requests were fine — but anything else that boots the app from inside
        // public/ (an agent's one-off script, a debug probe) leaves the CWD at public/,
        // and the logger then writes public/log/app-<date>.log: application logs INSIDE
        // THE WEB ROOT. Two instances had one, and it was only not downloadable because
        // an nginx rule happens to block .log — a sibling file with any other extension
        // is served (verified: a .txt in that directory returned 200).
        //
        // __DIR__ is the install root by construction, so the path no longer depends on
        // who called us or from where. An absolute path in the config is honoured as-is.
        $logFile = $this->config['logging']['file'] ?? 'log/app.log';
        $config['logFile'] = ($logFile[0] ?? '') === '/' ? $logFile : __DIR__ . '/' . ltrim($logFile, './');

        // Containers log to stderr (Hyperlift/Docker capture the stream; the
        // container FS is ephemeral so a logfile is lost). Toggled by the LOG_STDERR
        // env (set in the Dockerfile) or [logging] stderr = true. Dev host: neither.
        $config['logStderr'] = filter_var(getenv('LOG_STDERR') ?: ($this->config['logging']['stderr'] ?? false), FILTER_VALIDATE_BOOLEAN);

        // Create log directory if it doesn't exist
        if (!$config['logStderr']) {
            $logDir = dirname($config['logFile']);
            if (!is_dir($logDir)) { mkdir($logDir, 0755, true); }
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
			$handler = $config['logStderr']
				? new StreamHandler('php://stderr', constant("Monolog\Logger::{$config['logLevel']}"))
				: new RotatingFileHandler($config['logFile'], 30, constant("Monolog\Logger::{$config['logLevel']}"));
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
            // Check if RedBeanPHP is already connected
            $alreadyConnected = false;
            try {
                $toolbox = R::getToolBox();
                if ($toolbox && $toolbox->getDatabaseAdapter()) {
                    $alreadyConnected = true;
                    $this->logger->debug('RedBeanPHP already connected, skipping setup');
                }
            } catch (\Exception $e) {
                // Not connected, proceed with setup
            }

            if (!$alreadyConnected) {
                // Construct DSN based on database type
                $type = $dbConfig['type'] ?? 'mysql';


                // Deploy-time override: a single DB_DSN env var (e.g. Hyperlift) drives
                // the connection; falls back to conf/config.ini when unset. Supports
                // mysql://, pgsql://, sqlite:... — see parse_db_dsn() in lib/functions.php.
                $envDsn = getenv('DB_DSN');
                if ($envDsn !== false && trim($envDsn) !== '') {
                    [$dsn, $dsnUser, $dsnPass] = \parse_db_dsn(trim($envDsn), __DIR__);
                    if ($dsnUser === null) {
                        R::setup($dsn);
                    } else {
                        R::setup($dsn, (string)$dsnUser, (string)$dsnPass);
                    }
                } elseif ($type === 'sqlite') {
                    // SQLite configuration - use absolute path relative to project root
                    $dbPath = $dbConfig['path'] ?? 'database/tiknix.db';
                    if ($dbPath[0] !== '/') {
                        $dbPath = __DIR__ . '/' . $dbPath;
                    }
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
            }
            
            // Set freeze mode based on environment
            // DB_FREEZE env (true/false) overrides; otherwise freeze in production.
            /* FROZEN BY DEFAULT. This used to freeze only in production, which meant never:
               every environment here is "development" and DB_FREEZE is unset, so the guard
               never once engaged. Fluid mode then treated any misspelled bean property as
               schema to build — that is where `authcontrol` got ghost `controller` and
               `operation` columns, NULL in all 171 rows, turning a wrong query into a
               merely-empty one.

               Schema growth still works: WorkspaceSchemaBuilder::build() thaws for the
               duration of the seeds and restores the previous state afterwards, so
               `clitool --build` behaves exactly as before. What no longer works is a
               request inventing a column, which was never intended and never announced.

               DB_FREEZE=false still forces fluid for anyone who needs the old behaviour. */
            $envFreeze = getenv('DB_FREEZE');
            $freeze    = ($envFreeze !== false)
                ? filter_var($envFreeze, FILTER_VALIDATE_BOOLEAN)
                : true;
            R::freeze($freeze);

            /* Fluid mode creates a column for any unknown bean property, silently — right
               for a seed, wrong for a typo. And the guard above has never engaged: every
               environment here is "development" and DB_FREEZE is unset, so nothing is ever
               frozen. That is how `authcontrol` grew ghost `controller`/`operation` columns
               that stayed NULL in every row and made a WRONG query look merely empty.

               Freezing is not the answer — it would also stop the seeds and generated
               features that legitimately grow the schema. So the change is made LOUD
               instead: every automatic CREATE TABLE / ADD COLUMN / widen logs at ERROR with
               the call site that caused it.

               The OODB must be rebuilt, not reused: it holds its own writer reference, so
               swapping only the ToolBox leaves the original writer in place and logs
               nothing. SQLite only — another driver keeps its own writer rather than
               silently losing the audit to a class that does not match it. */
            if (R::getWriter() instanceof \RedBeanPHP\QueryWriter\SQLiteT) {
                require_once __DIR__ . '/lib/SchemaAuditWriter.php';
                $auditAdapter = R::getDatabaseAdapter();
                $auditWriter  = new \app\SchemaAuditWriter($auditAdapter);
                R::configureFacadeWithToolbox(new \RedBeanPHP\ToolBox(
                    new \RedBeanPHP\OODB($auditWriter, $freeze),
                    $auditAdapter,
                    $auditWriter
                ));
            }
            
            // Enable query logging in debug mode
            if ($this->config['app']['debug'] ?? false) {
                R::debug(true, 1);
            }

            // Initialize Query Cache with CachedDatabaseAdapter
            if ($this->config['cache']['query_cache'] ?? false) {
                require_once __DIR__ . '/lib/CachedDatabaseAdapter.php';
                $cachedAdapter = new \app\CachedDatabaseAdapter(R::getDatabaseAdapter()->getDatabase());
                $toolbox = new \RedBeanPHP\ToolBox(R::getRedBean(), $cachedAdapter, R::getWriter());
                R::configureFacadeWithToolbox($toolbox);
                // Register the cached toolbox under the 'default' key too, so a later
                // R::selectDatabase('default') (after the security firewall switches to
                // its own DB and back) restores the CACHED adapter — not the plain
                // toolbox that setup() left in the registry. Without this, writes after
                // a selectDatabase swap bypass query-cache invalidation and leave stale
                // rows on admin lists (leads, vendors, workbench, etc.).
                // Point the query WRITER at the cached adapter too. Facade raw queries
                // (R::getAll/getCell/getCol) already run through the cached adapter, but ALL
                // bean CRUD (find/load/store/trash) goes through the WRITER's own adapter,
                // which setup() left as the plain, uncached one. That split meant bean writes
                // never invalidated the query cache — stale rows lingered on admin lists.
                // Rebinding the writer's adapter routes every bean op through the cache so
                // writes invalidate. Guarded: a RedBean internals change must not fatal boot.
                try {
                    $__wr = R::getWriter();
                    $__rp = new \ReflectionProperty($__wr, 'adapter');
                    $__rp->setValue($__wr, $cachedAdapter);
                } catch (\Throwable $__e) {
                    $this->logger->warning('CachedDatabaseAdapter: could not rebind writer adapter: ' . $__e->getMessage());
                }
                R::addToolBoxWithKey('default', $toolbox);
                // Store adapter in Flight so it survives R::selectDatabase() calls
                Flight::set('cachedDatabaseAdapter', $cachedAdapter);
                $this->logger->info('CachedDatabaseAdapter initialized');
            }


            // Sidecar workspace override: the AI Projects (workspace) sidecar owns task
            // data in a per-instance workbench.db. Set ONLY by workspace-sidecar-spawned
            // CLI children via the TIKNIX_WORKBENCH_DB env — INERT for core + normal
            // instances (env unset), so it never touches the default connection. Fluid so
            // the task tables (workbenchtask/tasklog/…) auto-create on first write.
            $wsDb = getenv("TIKNIX_WORKBENCH_DB");
            if ($wsDb !== false && trim($wsDb) !== "") {
                $wsDb = trim($wsDb);
                if (!R::hasDatabase("ws")) R::addDatabase("ws", "sqlite:" . $wsDb);
                R::selectDatabase("ws");
                R::freeze(false);
                $this->logger->info("Workspace DB selected: " . $wsDb);
            }
            $this->logger->info('Database connected');
            
        } catch (\Exception $e) {
            $this->logger->error('Database connection failed: ' . $e->getMessage());
            die('Database connection failed: '.$e->getMessage());
        }
    }
    
    /**
     * Initialize PHP session with security settings
     * Skips session initialization in CLI mode to avoid warnings
     */
    private function initSession() {
        // Skip session initialization in CLI mode
        if (php_sapi_name() === 'cli') {
            $this->logger->debug('Skipping session initialization in CLI mode');
            return;
        }

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

        // Load custom routes
        if (file_exists(__DIR__ . '/routes/mcp.php')) {
            require_once __DIR__ . '/routes/mcp.php';
        }

        // Register default route handler (catch-all for /class/method pattern)
        Flight::defaultRoute();

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
