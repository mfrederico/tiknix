#!/usr/bin/env php
<?php
/**
 * Database Initialization Script
 * Uses RedBeanPHP to create initial database structure and data
 * Database agnostic - works with MySQL, PostgreSQL, SQLite
 */

// Load bootstrap to get database connection
require_once __DIR__ . '/../bootstrap.php';

use RedBeanPHP\R;

// Initialize the application
$app = new \app\Bootstrap();

echo "Database Initialization Script\n";
echo "===============================\n\n";

try {
    // Test database connection
    R::testConnection();
    echo "✓ Database connection successful\n\n";
    
    // Initialize members table with initial users
    echo "Creating initial users...\n";
    
    // Create admin user
    $admin = R::findOne('member', 'username = ?', ['admin']);
    if (!$admin) {
        $admin = R::dispense('member');
        $admin->username = 'admin';
        $admin->email = 'admin@example.com';
        $admin->password = password_hash('admin123', PASSWORD_DEFAULT);
        $admin->level = 1; // ROOT level
        $admin->status = 'active';
        $admin->created = date('Y-m-d H:i:s');
        $admin->updated = date('Y-m-d H:i:s');
        R::store($admin);
        echo "  ✓ Created admin user (username: admin, password: admin123)\n";
    } else {
        echo "  - Admin user already exists\n";
    }
    
    // Create public user entity
    $publicUser = R::findOne('member', 'username = ?', ['public-user-entity']);
    if (!$publicUser) {
        $publicUser = R::dispense('member');
        $publicUser->username = 'public-user-entity';
        $publicUser->email = 'public@example.com';
        $publicUser->password = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
        $publicUser->level = 101; // PUBLIC level
        $publicUser->status = 'system';
        $publicUser->created = date('Y-m-d H:i:s');
        $publicUser->updated = date('Y-m-d H:i:s');
        R::store($publicUser);
        echo "  ✓ Created public-user-entity (system user)\n";
    } else {
        echo "  - Public user entity already exists\n";
    }
    
    // Create test member
    $testMember = R::findOne('member', 'username = ?', ['testuser']);
    if (!$testMember) {
        $testMember = R::dispense('member');
        $testMember->username = 'testuser';
        $testMember->email = 'test@example.com';
        $testMember->password = password_hash('test123', PASSWORD_DEFAULT);
        $testMember->level = 100; // MEMBER level
        $testMember->status = 'active';
        $testMember->created = date('Y-m-d H:i:s');
        $testMember->updated = date('Y-m-d H:i:s');
        R::store($testMember);
        echo "  ✓ Created test user (username: testuser, password: test123)\n";
    } else {
        echo "  - Test user already exists\n";
    }
    
    echo "\n";
    
    // Initialize authcontrol table with default permissions
    echo "Creating default permissions...\n";
    
    $permissions = [
        ['name' => 'admin.access', 'level' => 50, 'description' => 'Access admin panel'],
        ['name' => 'admin.users', 'level' => 50, 'description' => 'Manage users'],
        ['name' => 'admin.permissions', 'level' => 1, 'description' => 'Manage permissions'],
        ['name' => 'admin.contacts', 'level' => 50, 'description' => 'View and respond to contacts'],
        ['name' => 'member.dashboard', 'level' => 100, 'description' => 'Access member dashboard'],
        ['name' => 'member.profile', 'level' => 100, 'description' => 'Edit own profile'],
        ['name' => 'public.view', 'level' => 101, 'description' => 'View public pages'],
        ['name' => 'public.contact', 'level' => 101, 'description' => 'Submit contact form'],
        ['name' => 'docs.view', 'level' => 101, 'description' => 'View documentation'],
        ['name' => 'cli.execute', 'level' => 1, 'description' => 'Execute CLI commands'],
    ];
    
    foreach ($permissions as $perm) {
        $existing = R::findOne('authcontrol', 'name = ?', [$perm['name']]);
        if (!$existing) {
            $authcontrol = R::dispense('authcontrol');
            $authcontrol->name = $perm['name'];
            $authcontrol->level = $perm['level'];
            $authcontrol->description = $perm['description'];
            $authcontrol->created = date('Y-m-d H:i:s');
            R::store($authcontrol);
            echo "  ✓ Created permission: {$perm['name']}\n";
        } else {
            echo "  - Permission already exists: {$perm['name']}\n";
        }
    }
    
    echo "\n";
    
    // Create sample contact entries
    echo "Creating sample contact entries...\n";
    
    $contacts = R::count('contact');
    if ($contacts == 0) {
        $sampleContacts = [
            [
                'name' => 'John Doe',
                'email' => 'john@example.com',
                'subject' => 'Question about the framework',
                'message' => 'This is a sample contact message. Great framework!',
                'status' => 'unread'
            ],
            [
                'name' => 'Jane Smith',
                'email' => 'jane@example.com',
                'subject' => 'Feature request',
                'message' => 'It would be great to have OAuth integration.',
                'status' => 'read'
            ]
        ];
        
        foreach ($sampleContacts as $contactData) {
            $contact = R::dispense('contact');
            $contact->name = $contactData['name'];
            $contact->email = $contactData['email'];
            $contact->subject = $contactData['subject'];
            $contact->message = $contactData['message'];
            $contact->status = $contactData['status'];
            $contact->created = date('Y-m-d H:i:s');
            R::store($contact);
            echo "  ✓ Created sample contact from {$contactData['name']}\n";
        }
    } else {
        echo "  - Contact entries already exist\n";
    }
    
    echo "\n";
    
    // Create database indexes for better performance
    echo "Creating database indexes...\n";
    
    // RedBean will automatically create indexes on foreign keys
    // For additional indexes, we can use raw SQL (database-specific)
    $dbType = R::getDatabaseAdapter()->getDatabase()->getDatabaseType();
    
    if ($dbType === 'mysql' || $dbType === 'sqlite') {
        // Create indexes safely (ignore if already exists)
        $indexes = [
            'CREATE INDEX IF NOT EXISTS idx_member_username ON member(username)',
            'CREATE INDEX IF NOT EXISTS idx_member_email ON member(email)',
            'CREATE INDEX IF NOT EXISTS idx_member_level ON member(level)',
            'CREATE INDEX IF NOT EXISTS idx_authcontrol_name ON authcontrol(name)',
            'CREATE INDEX IF NOT EXISTS idx_authcontrol_level ON authcontrol(level)',
            'CREATE INDEX IF NOT EXISTS idx_contact_status ON contact(status)',
            'CREATE INDEX IF NOT EXISTS idx_contact_created ON contact(created)'
        ];
        
        foreach ($indexes as $indexSql) {
            try {
                R::exec($indexSql);
                echo "  ✓ Index created or verified\n";
            } catch (\Exception $e) {
                // Index might already exist in some database versions
                echo "  - Index might already exist (this is OK)\n";
            }
        }
    }
    
    echo "\n";
    echo "========================================\n";
    echo "✓ Database initialization complete!\n";
    echo "========================================\n\n";
    
    echo "You can now log in with:\n";
    echo "  Admin: username=admin, password=admin123\n";
    echo "  Test User: username=testuser, password=test123\n\n";
    
    echo "Database type: " . ucfirst($dbType) . "\n";
    
    if ($dbType === 'sqlite') {
        $dbPath = $app->config['database']['path'] ?? 'database/tiknix.db';
        echo "SQLite database location: " . realpath($dbPath) . "\n";
    }
    
} catch (\Exception $e) {
    echo "\n❌ Error: " . $e->getMessage() . "\n";
    echo "Please check your database configuration in conf/config.ini\n";
    exit(1);
}