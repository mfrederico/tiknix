#!/usr/bin/env php
<?php
/**
 * Initialize contact message tables
 * Run this script to create the contact and contact_response tables
 */

// Load bootstrap
require_once dirname(__DIR__) . '/bootstrap.php';

use \RedBeanPHP\R as R;

// Initialize the application
$app = new app\Bootstrap('conf/config.ini');

echo "Initializing contact message tables...\n";

try {
    // Create a sample contact message to force table creation
    $contact = R::dispense('contact');
    $contact->name = 'Test User';
    $contact->email = 'test@example.com';
    $contact->subject = 'Test Message';
    $contact->message = 'This is a test message that can be deleted.';
    $contact->category = 'general';
    $contact->status = 'new';
    $contact->ipAddress = '127.0.0.1';
    $contact->userAgent = 'CLI/Init';
    $contact->memberId = null;
    $contact->createdAt = date('Y-m-d H:i:s');
    $contact->readAt = null;
    $contact->respondedAt = null;
    $contact->respondedBy = null;
    $contact->updatedAt = null;
    $id = R::store($contact);
    
    echo "✓ Created contact table\n";
    
    // Create a sample response to force table creation
    $response = R::dispense('contactresponse');
    $response->contactId = $id;
    $response->adminId = 1;
    $response->response = 'Test response';
    $response->createdAt = date('Y-m-d H:i:s');
    R::store($response);

    echo "✓ Created contactresponse table\n";

    // Clean up test data using beans
    $responses = R::find('contactresponse', 'contact_id = ?', [$id]);
    foreach ($responses as $resp) {
        R::trash($resp);
    }
    R::trash($contact);
    
    echo "✓ Cleaned up test data\n";
    
    // Add indexes for better performance
    try {
        R::exec('CREATE INDEX idx_contact_status ON contact(status)');
        R::exec('CREATE INDEX idx_contact_created ON contact(created_at)');
        R::exec('CREATE INDEX idx_contact_member ON contact(member_id)');
        R::exec('CREATE INDEX idx_response_contact ON contactresponse(contact_id)');
        echo "✓ Added database indexes\n";
    } catch (Exception $e) {
        echo "! Indexes may already exist (this is OK)\n";
    }
    
    // Show table structure
    echo "\nTable structure created:\n";
    echo "------------------------\n";
    
    $columns = R::inspect('contact');
    echo "\ncontact table:\n";
    foreach ($columns as $col => $type) {
        echo "  - {$col}: {$type}\n";
    }
    
    $columns = R::inspect('contactresponse');
    echo "\ncontactresponse table:\n";
    foreach ($columns as $col => $type) {
        echo "  - {$col}: {$type}\n";
    }
    
    echo "\n✅ Contact tables initialized successfully!\n";
    echo "You can now use the contact form at /contact\n";
    echo "Admins can manage messages at /contact/admin\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}