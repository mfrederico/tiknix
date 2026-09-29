<?php
/**
 * API Routes
 * JSON API endpoints for AJAX operations
 */

use \Flight as Flight;

// PHP Code Validation
Flight::route('POST /api/validatephp', function() {
    $controller = new \app\Api();
    $controller->validatephp([]);
});

// Tool Metadata extraction
Flight::route('POST /api/toolmetadata', function() {
    $controller = new \app\Api();
    $controller->toolmetadata([]);
});
