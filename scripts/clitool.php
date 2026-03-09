#!/usr/bin/env php
<?php
/**
 * CannonWMS CLI Tool
 *
 * A comprehensive tool for RedBeanPHP + FlightPHP development:
 * - Create models with FUSE hooks (interactive wizard)
 * - Define relationships (own/shared)
 * - Generate controllers (CRUD/API/Both)
 * - Scaffold views with Bootstrap 5
 * - Manipulate bean data from command line
 *
 * USAGE:
 * ------
 * # Interactive model creation wizard:
 * php scripts/clitool.php --workspace=demo --wizard
 *
 * # Update bean data:
 * php scripts/clitool.php --workspace=demo --bean=member --data='{"id":1,"first_name":"bilbo"}'
 *
 * # Associate beans (many-to-many):
 * php scripts/clitool.php --workspace=demo --bean=member --associate=warehouse --data='{"id":1,"warehouse_id":5}'
 *
 * # Export bean as JSON:
 * php scripts/clitool.php --workspace=demo --bean=member --data='{"id":1}' --getjson
 *
 * # Scaffold model/control/view from existing table:
 * php scripts/clitool.php --workspace=demo --bean=product --scaffold=model,control,view
 *
 * # List all tables in workspace:
 * php scripts/clitool.php --workspace=demo --list
 */

if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

error_reporting(E_ALL);
$scriptDir = dirname(__FILE__);
$baseDir = dirname($scriptDir);
chdir($baseDir);

// Parse command line options
$options = getopt('', [
    'workspace:',
    'bean:',
    'data:',
    'associate:',
    'getjson',
    'getall',
    'find',
    'findone',
    'create',
    'findorcreate',
    'match:',
    'scaffold:',
    'wizard',
    'list',
    'sql:',
    'describe:',
    'exec:',
    'adduser:',
    'password:',
    'level:',
    'verbose',
    'help',
    'script',
    'dry-run',
    'limit:',
    'order:'
]);

if (isset($options['help']) || (count($options) === 0)) {
    showHelp();
    exit(0);
}

$verbose = isset($options['verbose']);
$dryRun = isset($options['dry-run']);

// Workspace handling
$workspace = $options['workspace'] ?? null;
if ($workspace) {
    $configFile = "conf/config.{$workspace}.ini";
    if (!file_exists($configFile)) {
        fwrite(STDERR, "Error: Workspace config not found: {$configFile}\n");
        exit(1);
    }
} else {
    $configFile = 'conf/config.ini';
}

// Bootstrap the application (handles autoloading, DB connection, etc.)
require_once $baseDir . '/lib/Bootstrap.php';
$app = new \app\Bootstrap($configFile);

// Load scaffold library
require_once $baseDir . '/lib/Scaffold/FieldRegistry.php';
require_once $baseDir . '/lib/Scaffold/Context.php';
require_once $baseDir . '/lib/Scaffold/Generators/GeneratorInterface.php';
require_once $baseDir . '/lib/Scaffold/Generators/ModelGenerator.php';
require_once $baseDir . '/lib/Scaffold/Generators/CrudControllerGenerator.php';
require_once $baseDir . '/lib/Scaffold/Generators/ApiControllerGenerator.php';
require_once $baseDir . '/lib/Scaffold/Generators/ViewGenerator.php';
require_once $baseDir . '/lib/Scaffold/Commands/WizardCommand.php';
require_once $baseDir . '/lib/Scaffold/Commands/ScaffoldCommand.php';
require_once $baseDir . '/lib/Scaffold/Commands/BeanCommand.php';
require_once $baseDir . '/lib/Scaffold/ScaffoldManager.php';

use app\Scaffold\ScaffoldManager;
use app\Scaffold\Commands\BeanCommand;

// Initialize scaffold manager
$manager = new ScaffoldManager($baseDir);
$manager->setVerbose($verbose);
$manager->setDryRun($dryRun);
$manager->setWorkspace($workspace);

// ============================================================================
// COMMAND ROUTING
// ============================================================================

if (isset($options['wizard'])) {
    $manager->runWizard();
    exit(0);
}

if (isset($options['list'])) {
    $beanCmd = new BeanCommand($manager);
    $beanCmd->listTables();
    exit(0);
}

// --describe=tablename  — Show column types for a table
if (isset($options['describe'])) {
    $table = $options['describe'];
    try {
        $rows = \RedBeanPHP\R::getAll("DESCRIBE `{$table}`");
        printf("%-30s %-25s %-6s %-6s %-10s %s\n", 'Field', 'Type', 'Null', 'Key', 'Default', 'Extra');
        echo str_repeat('-', 90) . "\n";
        foreach ($rows as $r) {
            printf("%-30s %-25s %-6s %-6s %-10s %s\n",
                $r['Field'], $r['Type'], $r['Null'], $r['Key'], $r['Default'] ?? 'NULL', $r['Extra']);
        }
    } catch (\Exception $e) {
        fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
        exit(1);
    }
    exit(0);
}

// --sql='SELECT ...'  — Run a read-only SQL query, output as table or JSON
if (isset($options['sql'])) {
    $sql = $options['sql'];
    try {
        $rows = \RedBeanPHP\R::getAll($sql);
        if (empty($rows)) {
            echo "(no rows)\n";
        } else {
            echo json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        }
    } catch (\Exception $e) {
        fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
        exit(1);
    }
    exit(0);
}

// --exec='ALTER TABLE ...'  — Run a write SQL statement (DDL, UPDATE, etc.)
if (isset($options['exec'])) {
    $sql = $options['exec'];
    try {
        $affected = \RedBeanPHP\R::exec($sql);
        echo "OK — {$affected} row(s) affected\n";
    } catch (\Exception $e) {
        fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
        exit(1);
    }
    exit(0);
}

if (isset($options['scaffold'])) {
    $beanName = $options['bean'] ?? null;
    if (!$beanName) {
        fwrite(STDERR, "Error: --bean is required for scaffolding\n");
        exit(1);
    }
    $scaffoldParts = array_map('trim', explode(',', $options['scaffold']));
    $manager->runScaffold($beanName, $scaffoldParts);
    exit(0);
}

// --adduser=EMAIL --password=PASS [--level=N]  — Create a member account
if (isset($options['adduser'])) {
    $email = strtolower(trim($options['adduser']));
    $password = $options['password'] ?? null;
    $level = (int) ($options['level'] ?? 100);

    if (empty($email)) {
        fwrite(STDERR, "Error: --adduser requires an email address\n");
        exit(1);
    }
    if (empty($password)) {
        fwrite(STDERR, "Error: --password is required\n");
        exit(1);
    }

    $levelLabels = [1 => 'ROOT', 50 => 'ADMIN', 100 => 'MEMBER', 101 => 'PUBLIC'];
    $levelLabel = $levelLabels[$level] ?? "CUSTOM({$level})";

    // Check for existing member
    $existing = \app\Bean::findOne('member', 'email = ?', [$email]);
    if ($existing) {
        fwrite(STDERR, "Error: Member with email '{$email}' already exists (id={$existing->id})\n");
        exit(1);
    }

    $member = \app\Bean::dispense('member');
    $member->email = $email;
    $member->username = explode('@', $email)[0];
    $member->password_hash = password_hash($password, PASSWORD_DEFAULT);
    $member->first_name = '';
    $member->last_name = '';
    $member->level = $level;
    $member->status = 'active';
    $member->is_account_holder = ($level === 1) ? 1 : 0;
    $member->created_at = date('Y-m-d H:i:s');
    $member->updated_at = date('Y-m-d H:i:s');
    \app\Bean::store($member);

    echo "✓ Created member #{$member->id} — {$email} (level: {$level} / {$levelLabel})\n";
    exit(0);
}

if (isset($options['bean'])) {
    $beanName = $options['bean'];
    $data = isset($options['data']) ? json_decode($options['data'], true) : [];

    if (json_last_error() !== JSON_ERROR_NONE && isset($options['data'])) {
        fwrite(STDERR, "Error: Invalid JSON in --data: " . json_last_error_msg() . "\n");
        exit(1);
    }

    if (isset($options['create'])) {
        // --create creates a new record
        if (empty($data)) {
            fwrite(STDERR, "Error: --data is required for --create\n");
            exit(1);
        }
        $manager->runBeanCommand('create', $beanName, $data);
    } elseif (isset($options['findorcreate'])) {
        // --findorcreate finds by match fields or creates with all data
        if (empty($data)) {
            fwrite(STDERR, "Error: --data is required for --findorcreate\n");
            exit(1);
        }
        $matchFields = isset($options['match']) ? explode(',', $options['match']) : [];
        if (empty($matchFields)) {
            fwrite(STDERR, "Error: --match=field1,field2 is required for --findorcreate\n");
            exit(1);
        }
        $manager->runBeanCommand('findorcreate', $beanName, $data, null, $matchFields);
    } elseif (isset($options['getall'])) {
        // --getall doesn't require --data
        $getAllOptions = [
            'limit' => (int) ($options['limit'] ?? 50),
            'order' => $options['order'] ?? 'id DESC'
        ];
        $manager->runBeanCommand('getall', $beanName, $getAllOptions);
    } elseif (isset($options['getjson'])) {
        $manager->runBeanCommand('export', $beanName, $data);
    } elseif (isset($options['find'])) {
        $manager->runBeanCommand('find', $beanName, $data);
    } elseif (isset($options['findone'])) {
        $manager->runBeanCommand('findone', $beanName, $data);
    } elseif (isset($options['associate'])) {
        $manager->runBeanCommand('associate', $beanName, $data, $options['associate']);
    } elseif (!empty($data)) {
        $manager->runBeanCommand('update', $beanName, $data);
    } else {
        fwrite(STDERR, "Error: --data is required for bean operations (or use --getall to list all records)\n");
        exit(1);
    }
    exit(0);
}

showHelp();
exit(1);

// ============================================================================
// HELP
// ============================================================================

function showHelp(): void {
    echo <<<HELP
CannonWMS CLI Tool - RedBeanPHP + FlightPHP Development Helper

USAGE:
  php scripts/clitool.php [options]

OPTIONS:
  --workspace=NAME   Workspace/tenant to operate on (loads conf/config.NAME.ini)
  --wizard           Interactive model creation wizard
  --list             List all bean tables in the workspace database
  --bean=NAME        Bean/table name for operations
  --data=JSON        JSON data for bean operations
  --associate=NAME   Associate bean with another (many-to-many)
  --getjson          Export bean as JSON (requires id in data)
  --getall           Get all records from a bean (no --data needed)
  --find             Find records by any field(s) in data (returns array)
  --findone          Find single record by any field(s) in data
  --findorcreate     Find by match fields, or create if not found
  --match=FIELDS     Comma-separated fields for findorcreate lookup
  --create           Create a new record (no id needed)
  --limit=N          Limit results (default: 50, used with --getall/--find)
  --order=FIELD      Order by field (default: "id DESC")
  --scaffold=PARTS   Generate files (model,control,view,api,all)
  --adduser=EMAIL    Create a member account (requires --password)
  --password=PASS    Password for --adduser
  --level=N          Permission level for --adduser (1=ROOT, 50=ADMIN, 100=MEMBER; default: 100)
  --describe=TABLE   Show column types for a table (DESCRIBE)
  --sql=QUERY        Run a read-only SQL query (SELECT, SHOW, etc.)
  --exec=STATEMENT   Run a write SQL statement (ALTER, UPDATE, INSERT, etc.)
  --verbose          Show detailed output
  --dry-run          Show what would be done without writing files
  --help             Show this help

EXAMPLES:
  # Interactive wizard to create a new model
  php scripts/clitool.php --workspace=demo --wizard

  # Update a member record
  php scripts/clitool.php --workspace=demo --bean=member \\
      --data='{"id":1,"first_name":"Bilbo","last_name":"Baggins"}'

  # Associate member with warehouse (many-to-many)
  php scripts/clitool.php --workspace=demo --bean=member \\
      --associate=warehouse --data='{"id":1,"warehouse_id":3}'

  # Export bean as JSON (by ID)
  php scripts/clitool.php --workspace=demo --bean=member \\
      --data='{"id":1}' --getjson

  # Get all records from a bean (no --data needed)
  php scripts/clitool.php --workspace=demo --bean=warehouse --getall

  # Get all with custom limit and order
  php scripts/clitool.php --workspace=demo --bean=member --getall --limit=100 --order="created_at ASC"

  # Find records by any field (returns array)
  php scripts/clitool.php --workspace=demo --bean=order \\
      --data='{"status":"pending"}' --find

  # Find single record
  php scripts/clitool.php --workspace=demo --bean=member \\
      --data='{"email":"admin@example.com"}' --findone

  # Find or create a record (uses control+method as lookup key)
  php scripts/clitool.php --workspace=demo --bean=authcontrol \\
      --findorcreate --match=control,method \\
      --data='{"control":"shopify","method":"callback","level":101}'

  # Create a new record
  php scripts/clitool.php --workspace=demo --bean=warehouse \\
      --create --data='{"name":"Main Warehouse","code":"WH-001"}'

  # Scaffold model, controller, and views from existing table
  php scripts/clitool.php --workspace=demo --bean=product \\
      --scaffold=model,control,view

  # Scaffold everything (model + CRUD controller + views)
  php scripts/clitool.php --workspace=demo --bean=product --scaffold=all

  # List all tables
  php scripts/clitool.php --workspace=demo --list

  # Describe a table's columns
  php scripts/clitool.php --workspace=demo --describe=order

  # Run a read query
  php scripts/clitool.php --workspace=demo --sql="SELECT id, order_number, status FROM \`order\` LIMIT 5"

  # Create a user (default level 100 = MEMBER)
  php scripts/clitool.php --workspace=demo --adduser=jane@example.com --password=secret123

  # Create a root admin user
  php scripts/clitool.php --workspace=demo --adduser=admin@example.com --password=secret123 --level=1

  # Run a DDL/write statement
  php scripts/clitool.php --workspace=demo --exec="ALTER TABLE \`order\` MODIFY COLUMN order_number VARCHAR(191) NULL"

CUSTOM FIELD WIDGETS:
  When defining fields in the wizard, you can specify custom widgets:
    email:string:widget=email          # Email input with icon
    password:string:widget=password    # Password with toggle visibility
    start_date:datetime:widget=fancyDateSelector  # Your custom widget

  Create custom widgets in: lib/Scaffold/Templates/fields/

TEMPLATE CUSTOMIZATION:
  All generated code comes from templates in lib/Scaffold/Templates/:
    model.php              - RedBeanPHP FUSE model
    controller/crud.php    - Session-based CRUD controller
    controller/api.php     - Stateless API controller
    view/index.php         - List view with table
    view/edit.php          - Create/edit form
    fields/*.php           - Form field widgets


HELP;
}
