<?php
/**
 * Reset admin password
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../bootstrap.php';

use RedBeanPHP\R;
use app\Bootstrap;

// Initialize the application
$app = new Bootstrap();

// Find admin user
$admin = R::findOne('member', 'username = ?', ['admin']);
if ($admin) {
    $newPassword = 'admin123';
    $admin->password = password_hash($newPassword, PASSWORD_DEFAULT);
    $admin->updated_at = date('Y-m-d H:i:s');
    R::store($admin);
    echo "Admin password reset to: $newPassword\n";
} else {
    echo "Admin user not found\n";
}