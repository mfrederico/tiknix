#!/usr/bin/env php
<?php
/**
 * Reset or Create Admin User
 *
 * Creates or resets the admin user with specified credentials.
 *
 * Usage: php cli/reset-admin.php [--username=admin] [--password=admin123]
 */

// Define base path
define('BASE_PATH', dirname(__DIR__));
chdir(BASE_PATH);

// Load bootstrap
require_once BASE_PATH . '/bootstrap.php';

// Initialize application
$app = new \app\Bootstrap('conf/config.ini');

use \app\Bean;

// Parse command line arguments
$options = getopt('', ['username::', 'password::', 'email::', 'help']);

if (isset($options['help'])) {
    echo <<<HELP
Reset or Create Admin User

Usage: php cli/reset-admin.php [options]

Options:
  --username=NAME   Admin username (default: admin)
  --password=PASS   Admin password (default: admin123)
  --email=EMAIL     Admin email (default: admin@tiknix.local)
  --help            Show this help message

HELP;
    exit(0);
}

$username = $options['username'] ?? 'admin';
$password = $options['password'] ?? 'admin123';
$email = $options['email'] ?? 'admin@tiknix.local';

echo "=== Tiknix Admin Reset ===\n\n";

try {
    // Check if admin exists
    $admin = Bean::findOne('member', 'username = ?', [$username]);

    if ($admin) {
        echo "Found existing user: {$username} (ID: {$admin->id})\n";
        echo "Updating password and ensuring account is active...\n";

        $admin->password = password_hash($password, PASSWORD_DEFAULT);
        $admin->status = 'active';
        $admin->level = LEVELS['ROOT'] ?? 1; // Highest privilege
        $admin->updatedAt = date('Y-m-d H:i:s');
        Bean::store($admin);

        echo "\n✓ Password updated successfully!\n";
    } else {
        echo "Creating new admin user: {$username}\n";

        $admin = Bean::dispense('member');
        $admin->username = $username;
        $admin->email = $email;
        $admin->password = password_hash($password, PASSWORD_DEFAULT);
        $admin->status = 'active';
        $admin->level = LEVELS['ROOT'] ?? 1;
        $admin->createdAt = date('Y-m-d H:i:s');
        $id = Bean::store($admin);

        echo "\n✓ Admin user created with ID: {$id}\n";
    }

    echo "\n";
    echo "Login credentials:\n";
    echo "  Username: {$username}\n";
    echo "  Password: {$password}\n";
    echo "  Level: " . ($admin->level) . " (ROOT)\n";
    echo "\n";

    // Verify the password works
    $verify = Bean::findOne('member', 'username = ?', [$username]);
    if ($verify && password_verify($password, $verify->password)) {
        echo "✓ Password verification: OK\n";
    } else {
        echo "✗ Password verification: FAILED\n";
    }

    // Check status
    if ($verify && $verify->status === 'active') {
        echo "✓ Account status: active\n";
    } else {
        echo "✗ Account status: " . ($verify->status ?? 'unknown') . "\n";
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\nDone!\n";
