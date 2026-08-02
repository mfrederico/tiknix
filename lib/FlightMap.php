<?php
use \Flight as Flight;
use \app\Bean;
use \Exception as Exception;
use \app\SimpleCsrf;

// Define permission levels - customize as needed
define('LEVELS', ['ROOT'=>1, 'ADMIN'=>50, 'MEMBER'=>100, 'PUBLIC'=>101]);
define('DEFAULT_LANG', 'EN');
define('BASEURL', 'example.com'); // Change this to your domain
define('CLASS_NAMESPACE', 'app'); // Change this to your app namespace

//**************************************************
// Register any classes here for pre-FlightMapping
//**************************************************
$_REGISTER_CLASSES = ['Log', 'Permissions', 'Member'];

// Register initial classes
foreach($_REGISTER_CLASSES as $_CLASS) {
    Flight::register($_CLASS, '\\'.CLASS_NAMESPACE.'\\'.$_CLASS);
}

// Register RedBeanPHP
Flight::register('R', '\RedBeanPHP\R');

// Firehose: register the fatal-error shutdown hook. Self-gates — only a live,
// firehose-provisioned instance actually reports (see lib/ErrorReporter.php).
if (class_exists('\\app\\ErrorReporter')) {
    \app\ErrorReporter::register();
}

/**
 * Core routing function - handles /class/method/operation/id pattern
 * This is the heart of the auto-routing system
 */
Flight::map('defaultRoute', function($prefix = '') {
    Flight::get('log')->debug('Default Route: ', [basename(__FILE__).'@'.__LINE__]);
    
    Flight::route($prefix.'/(@class(/@method(/@op(/@opid(/.*?)))))', 
    function($class = null, $function = null, $operation = null, $operationid = null, $route = null) {
        Flight::view()->set('LEVELS', LEVELS);
        
        // Default to index if not specified
        if (empty($class)) $class = 'index';
        if (empty($function)) $function = 'index';
        
        Flight::get('log')->debug("Checking permission for {$class}->{$function}");
        
        // Check permissions
        if (Flight::permissionFor($class, $function, Flight::getMember()->level)) {
            
            // Merge request data
            foreach (Flight::request()->data as $k=>$v) {
                $_REQUEST[$k] = $v;
            }
            
            // Set up parameters
            $params['operation'] = new \stdClass();
            $params['operation']->name = $operation;
            $params['operation']->type = $operationid;
            $params['route'] = $route;
            
            // Instantiate and call controller
            $classname = ucfirst($class);
            try {
                $classname = '\\'.CLASS_NAMESPACE.'\\'.$classname;

                // Check if controller class exists before trying to instantiate
                // A URL nobody implements is a visitor typo or a scanner, not a fault in
                // this application — so it is a warning. Logging routine 404s at ERROR
                // buried the real errors and made "any ERROR in the log is a bug" — the
                // rule the e2e suite enforces — impossible to hold.
                if (!class_exists($classname)) {
                    Flight::get('log')->warning("Controller not found: {$classname}");
                    Flight::notFound();
                    return;
                }

                // Only dispatch to real controllers: the resolved class's source
                // file must live under this project's controls/ directory. The
                // `app\` namespace also maps to lib/, so without this an attacker
                // could instantiate helper classes (Bean, PermissionCache, ...)
                // straight from a URL. Directory-based (not a name list) so new
                // controllers in any controls/ subdir work automatically.
                $controlsDir = realpath(dirname(__DIR__) . '/controls');
                $classFile = (new \ReflectionClass($classname))->getFileName();
                if ($controlsDir === false || $classFile === false
                    || strpos(realpath($classFile), $controlsDir . DIRECTORY_SEPARATOR) !== 0) {
                    Flight::get('log')->warning("Refused non-controller class: {$classname}");
                    Flight::notFound();
                    return;
                }

                $instance = new $classname;

                // Check if method exists and is callable
                if (method_exists($instance, $function)) {
                    $reflection = new ReflectionMethod($instance, $function);

                    // Only call if method is public
                    if ($reflection->isPublic()) {
                        Flight::get('log')->info("Calling: {$classname}->{$function}");
                        // Stash parsed route params so $this->opId()/opType() work
                        // (scaffolded CRUD reads the record id from the URL op segment).
                        if (method_exists($instance, 'setRouteParams')) {
                            $instance->setRouteParams($params);
                        }
                        $instance->$function($params);
                    } else {
                        Flight::get('log')->warning("Method not public: {$function}");
                        Flight::notFound();
                    }
                } else if (method_exists($instance, '_fallback')) {
                    // The controller opts into catching unknown sub-segments itself
                    // (e.g. the storefront maps /products/<sku> to a product page).
                    Flight::get('log')->info("Calling: {$classname}->_fallback({$function})");
                    if (method_exists($instance, 'setRouteParams')) {
                        $instance->setRouteParams($params);
                    }
                    $instance->_fallback($function, $params);
                } else {
                    Flight::get('log')->warning("Method not found: {$function}");
                    Flight::notFound();
                }
            } catch(\Throwable $e) {
                // Catch both Exception and Error (PHP 7+)
                Flight::get('log')->error("Controller error: ".$e->getMessage(), [
                    'controller' => $classname, 'method' => $function,
                    'file' => $e->getFile(), 'line' => $e->getLine(),
                ]);
                // Ship to the control-plane firehose (self-gates: only a live,
                // firehose-provisioned instance actually reports). Never throws.
                if (class_exists('\\app\\ErrorReporter')) {
                    \app\ErrorReporter::capture($e, 'controller', ['controller' => $classname, 'method' => $function]);
                }
                // A controller that THREW is a server error, not a missing page. This
                // called notFound(), so a broken route answered "404 Page Not Found" —
                // telling the visitor the thing they wanted does not exist, and telling
                // every monitor and crawler the same. The route exists and the code is
                // broken; those need different answers, and only one of them is worth
                // paging somebody about. Flight's mapped error handler renders 500.
                Flight::error($e);
            }
        } else {
            Flight::get('log')->warning("Permission denied: {$class}->{$function}");
            
            // If user is logged in, show forbidden error instead of redirecting to login
            if (Flight::isLoggedIn()) {
                // A real 403, for the same reason notFound() sends a real 404: a refusal
                // served with a 200 is invisible to logs, monitoring and every client
                // that decides what to do from the status code.
                Flight::response()->status(403);
                Flight::renderView('error/403', [
                    'title' => '403 - Forbidden',
                    'message' => 'You do not have permission to access this page.'
                ]);
            } else {
                // Only redirect to login if not logged in
                Flight::redirect('/auth/login?redirect='.urlencode(Flight::request()->url));
            }
        }
    });
});

/**
 * Permission checking function - Now uses PermissionCache for performance
 */
Flight::map('permissionFor', function($control, $function, $level = LEVELS['PUBLIC'], $wholeclass = false) {
    // Use the new PermissionCache for high-performance permission checking
    return \app\PermissionCache::check($control, $function, $level);
});

/**
 * Get current logged in member
 */
Flight::map('getMember', function() {
    if (!isset($_SESSION['member'])) {
        /* A guest is a BEAN too, not a hand-rolled stdClass.
         *
         * Returning two different types from one accessor made every caller's assumptions
         * true half the time. OODBBean implements ArrayAccess and stdClass does not, so
         * $member['level'] worked when signed in and was a FATAL when signed out — a 500
         * that only appears in the state nobody is looking at. A bean also carries its
         * FUSE model (models/Model_Member.php), so anything defined there works for a
         * guest instead of blowing up on a bare object.
         *
         * When the row is not in THIS database, fail CLOSED rather than fatally.
         *
         * This threw, briefly, on the reasoning that inventing an identity is a default in
         * the worst possible place. That was right about core and wrong about the system:
         * a SIDECAR runs against its own database and does not own identity at all, so the
         * row being absent there is normal, not broken — and throwing took every sidecar
         * down with a 500 on a page that was only going to render a login form.
         *
         * A dispensed PUBLIC-level bean is the safe answer instead: level 101 is the least
         * privilege there is, so every check denies. It is still a BEAN, so ArrayAccess and
         * the FUSE model work as they do everywhere else. Logged at ERROR because on core
         * it really would mean a broken install. */
        $guest = PUBLIC_USER_ID > 0 ? Bean::load('member', PUBLIC_USER_ID) : null;
        if ($guest && $guest->id) return $guest;

        Flight::get('log')?->error(
            'No public-user-entity row in the current database; falling back to a '
          . 'PUBLIC-level guest. Normal in a sidecar, a broken seed on core.'
        );

        $guest = Bean::dispense('member');
        $guest->level    = LEVELS['PUBLIC'];
        $guest->username = 'Guest';
        $guest->email    = '';
        return $guest;
    }
    
    // Refresh member data from database
    $member = Bean::load('member', $_SESSION['member']['id']);
    if ($member->id) {
        $_SESSION['member'] = $member->export();
        return $member;
    }
    
    // Invalid session
    unset($_SESSION['member']);
    return Flight::getMember(); // Return guest
});

/**
 * Check if user is logged in
 */
Flight::map('isLoggedIn', function() {
    return isset($_SESSION['member']) && $_SESSION['member']['id'] > 0;
});

/**
 * Check if user has permission level
 */
Flight::map('hasLevel', function($requiredLevel) {
    $member = Flight::getMember();
    return $member->level <= $requiredLevel;
});

/**
 * CSRF Protection - Simple session-based token
 */
Flight::map('csrf', function() {
    return new class {
        public function getTokenArray(): array {
            return SimpleCsrf::getTokenArray();
        }
        public function validateRequest(): bool {
            return SimpleCsrf::validateRequest();
        }
        public function field(): string {
            return SimpleCsrf::field();
        }
    };
});

/**
 * Render view with common data
 */
Flight::map('renderView', function($template, $data = []) {
    // Add common data to all views
    $data['member'] = Flight::getMember();
    $data['isLoggedIn'] = Flight::isLoggedIn();
    $data['levels'] = LEVELS;
    $data['baseurl'] = Flight::get('baseurl');
    $data['csrf'] = SimpleCsrf::getTokenArray();
    
    Flight::render($template, $data);
});


/**
 * JSON response helpers
 */
Flight::map('jsonSuccess', function($data = [], $message = 'Success') {
    Flight::json([
        'success' => true,
        'message' => $message,
        'data' => $data
    ]);
});

Flight::map('jsonError', function($message = 'Error', $code = 400) {
    Flight::json([
        'success' => false,
        'message' => $message
    ], $code);
});

/**
 * Load site menu (customize for your app)
 */
Flight::map('loadMenu', function() {
    $menu = [];
    
    // Public menu items
    $menu[] = ['url' => '/', 'label' => 'Home', 'icon' => 'home'];
    
    if (Flight::isLoggedIn()) {
        // Member menu items
        $menu[] = ['url' => '/dashboard', 'label' => 'Dashboard', 'icon' => 'dashboard'];
        $menu[] = ['url' => '/member/profile', 'label' => 'Profile', 'icon' => 'user'];
        
        // Admin menu items
        if (Flight::hasLevel(LEVELS['ADMIN'])) {
            $menu[] = ['url' => '/admin', 'label' => 'Admin', 'icon' => 'cog'];
        }
        
        $menu[] = ['url' => '/auth/logout', 'label' => 'Logout', 'icon' => 'sign-out'];
    } else {
        $menu[] = ['url' => '/auth/login', 'label' => 'Login', 'icon' => 'sign-in'];
        $menu[] = ['url' => '/auth/register', 'label' => 'Register', 'icon' => 'user-plus'];
    }
    
    return $menu;
});

/**
 * Error handlers
 */
Flight::map('notFound', function() {
    // Send a real HTTP 404 (not a soft-404). A 200 status on the error page
    // pollutes logs, gets soft-404s indexed, and destroys the status-code
    // signal used to spot URL scanning. stop() also prevents execution from
    // continuing past the render (which was emitting stray redirects).
    //
    // ...but it must NOT stop() here. Flight v3 wraps the route handler in its own
    // output buffer and writes that buffer into the response only once the handler
    // returns. Stopping from inside it sent the headers while the rendered page was
    // still sitting in the buffer, so every 404 on every instance arrived as a blank
    // page with a Content-Length promising a body that never came. Rendering and
    // returning is enough: every caller returns immediately after this, which is what
    // the stop() was standing in for.
    Flight::response()->status(404);
    Flight::renderView('error/404', [
        'title' => '404 - Page Not Found'
    ]);
});

Flight::map('error', function($ex) {
    // Log full exception details including backtrace
    Flight::get('log')->error('Exception: ' . $ex->getMessage(), [
        'file' => $ex->getFile(),
        'line' => $ex->getLine(),
        'trace' => $ex->getTraceAsString()
    ]);
    
    // Prepare error data for view
    $errorData = [
        'title' => '500 - Server Error',
        'error' => 'An error occurred'
    ];
    
    // In debug/development mode, pass full exception details
    if (Flight::get('debug') || Flight::get('development')) {
        $errorData['exception'] = $ex;
        $errorData['error'] = $ex->getMessage();
        $errorData['file'] = $ex->getFile();
        $errorData['line'] = $ex->getLine();
        $errorData['trace'] = $ex->getTrace();
        $errorData['traceString'] = $ex->getTraceAsString();
    }
    
    // SAY it is an error. Without this the 500 page went out with HTTP 200: a page reading
    // "500 - Server Error" that every client, proxy, cache and uptime check was told had
    // succeeded. Nothing upstream can distinguish a broken request from a working one, and
    // an outage looks green on the dashboard — which is exactly how this survived unnoticed
    // until a deleted controller started throwing.
    Flight::response()->status(500);
    Flight::renderView('error/500', $errorData);
});

/**
 * Utility functions
 */
Flight::map('isOn', function($val) {
    if (empty($val)) return false;
    if (is_string($val)) return preg_match('/ON|1|TRUE|YES|ALWAYS|DO/', strtoupper($val));
    elseif (is_bool($val)) return $val;
    else return false;
});

Flight::map('isOff', function($val) {
    if (is_string($val)) return preg_match('/OFF|0|FALSE|NO|NEVER|NOT/', strtoupper($val));
    elseif (is_bool($val)) return !$val;
    else return false;
});

/**
 * Protected system members - cannot be deleted
 */
/**
 * The bootstrap root admin — owns system settings, and cannot be deleted.
 *
 * RESOLVED, for the same reason PUBLIC_USER_ID below is. `define(..., 1)` happens to be
 * right on every instance built so far, and is WRONG on a database seeded from empty:
 * services/Schema/Seeds/01_Member.php stores a `__schema_seed_` padding row to size the
 * columns BEFORE creating the admin, then trashes it — so a from-scratch reseed puts the
 * admin at id 2 and id 1 at nothing. Verified by building one.
 *
 * Looked up by username, which is what the seed actually keys on, with a fall back to the
 * most senior active account so a renamed admin still resolves.
 */
// Resolved against CORE's database explicitly, via CoreDb::with(). A bare lookup here
// runs on whatever connection is current, and in a SIDECAR that is its own
// data/<name>.db — which has no member table at all, so the id came back 0 and every
// comparison against it silently matched nobody. CoreDb exists precisely for this and
// its docblock already records two earlier instances of the same mistake.
$__systemAdminId = (int) \app\CoreDb::with(function () {
    $sa = \app\Bean::findOne('member', 'username = ?', ['admin']);
    if (!$sa || !$sa->id) {
        $sa = \app\Bean::findOne('member', 'level <= ? AND status = ? ORDER BY level ASC, id ASC',
                                 [LEVELS['ADMIN'], 'active']);
    }
    return (int) ($sa->id ?? 0);
}, 0);
if ($__systemAdminId === 0) {
    Flight::get('log')?->error('No root admin in the core database; system-account protections cannot match');
}
define('SYSTEM_ADMIN_ID', $__systemAdminId);
unset($__systemAdminId);

/**
 * The public-user-entity row — the account unauthenticated visitors are treated as.
 *
 * RESOLVED, not hard-coded. This was `define('PUBLIC_USER_ID', 2)` and no member with id
 * 2 exists: the row is id 10 here, and would be a different id in every instance clone,
 * because it is seeded rather than fixed. Three things were quietly wrong as a result —
 * CliHandler loaded an EMPTY bean and ran as a member with no id and no level; admin's
 * "these accounts cannot be deleted" guard never matched, leaving the public user
 * editable and deletable; and Mcp logged a member_id pointing at nothing.
 *
 * Looked up by username, which is what actually identifies it. 0 if the seed is missing,
 * and that is worth knowing rather than papering over — every comparison against it then
 * fails closed instead of matching a real account.
 */
// Against CORE's database, explicitly — see the note on SYSTEM_ADMIN_ID above. A bare
// lookup resolves on the ambient connection, which in a sidecar is its own
// data/<name>.db with no member table at all.
$__publicUserId = (int) \app\CoreDb::with(function () {
    $pu = \app\Bean::findOne('member', 'username = ?', ['public-user-entity']);
    return (int) ($pu->id ?? 0);
}, 0);
if ($__publicUserId === 0) {
    Flight::get('log')?->error('No public-user-entity row in the core database; guest identity is unresolved');
}
define('PUBLIC_USER_ID', $__publicUserId);
unset($__publicUserId);

/**
 * Setting management helpers
 * Pass memberId=0 for system-wide settings (stored under SYSTEM_ADMIN_ID)
 */
Flight::map('getSetting', function($key, $memberId = null) {
    if ($memberId === null) {
        $member = Flight::getMember();
        $memberId = $member->id ?? SYSTEM_ADMIN_ID;
    }

    // System-wide settings (member_id=0) are owned by SYSTEM_ADMIN_ID
    if ($memberId === 0) {
        $memberId = SYSTEM_ADMIN_ID;
    }

    $setting = Bean::findOne('settings', 'member_id = ? AND setting_key = ?', [$memberId, $key]);
    return $setting ? $setting->settingValue : null;
});

Flight::map('setSetting', function($key, $value, $memberId = null) {
    if ($memberId === null) {
        $member = Flight::getMember();
        $memberId = $member->id ?? SYSTEM_ADMIN_ID;
    }

    // System-wide settings (member_id=0) are owned by SYSTEM_ADMIN_ID
    if ($memberId === 0) {
        $memberId = SYSTEM_ADMIN_ID;
    }

    $setting = Bean::findOne('settings', 'member_id = ? AND setting_key = ?', [$memberId, $key]);

    if (!$setting) {
        $setting = Bean::dispense('settings');
        $setting->memberId = $memberId;
        $setting->settingKey = $key;
    }
    $setting->settingValue = $value;
    $setting->updatedAt = date('Y-m-d H:i:s');

    return Bean::store($setting);
});
