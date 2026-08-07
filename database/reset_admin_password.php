<?php
/**
 * Reset admin password
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../bootstrap.php';

use app\Bootstrap;
use app\Bean;

// Initialize the application
$app = new Bootstrap();

// Find admin user
$admin = Bean::findOne('member', 'username = ?', ['admin']);
if ($admin) {
    // Generate a strong random password with a CSPRNG, using an unambiguous
    // alphabet (no 0/O/1/I/l) so it's easy to read off the terminal.
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
    $newPassword = '';
    for ($i = 0; $i < 16; $i++) {
        $newPassword .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }

    $admin->password = password_hash($newPassword, PASSWORD_DEFAULT);
    $admin->updatedAt = date('Y-m-d H:i:s');
    Bean::store($admin);

    echo "\n========================================\n";
    echo "Admin password has been reset.\n";
    echo "  Username: admin\n";
    echo "  Password: {$newPassword}\n";
    echo "========================================\n";
    echo "Save this now — it is hashed in the database and not recoverable.\n\n";
} else {
    echo "Admin user not found\n";
}