<?php

// OpenAPI tool registry management
Flight::route('GET /admin/openapi-tools', 'Controls_OpenapiToolRegistry@index');
Flight::route('GET /admin/openapi-tools/add', 'Controls_OpenapiToolRegistry@add');
Flight::route('POST /admin/openapi-tools/add', 'Controls_OpenapiToolRegistry@add');
Flight::route('GET /admin/openapi-tools/edit/@id', 'Controls_OpenapiToolRegistry@edit');
Flight::route('POST /admin/openapi-tools/edit/@id', 'Controls_OpenapiToolRegistry@edit');
Flight::route('GET /admin/openapi-tools/toggle/@id', 'Controls_OpenapiToolRegistry@toggle');
Flight::route('GET /admin/openapi-tools/delete/@id', 'Controls_OpenapiToolRegistry@delete');

// API versioning
Flight::route('GET /openapi-registry', 'Controls_OpenapiToolRegistry@index');
Flight::route('GET /openapi-registry/add', 'Controls_OpenapiToolRegistry@add');
Flight::route('POST /openapi-registry/add', 'Controls_OpenapiToolRegistry@add');
Flight::route('GET /openapi-registry/edit/@id', 'Controls_OpenapiToolRegistry@edit');
Flight::route('POST /openapi-registry/edit/@id', 'Controls_OpenapiToolRegistry@edit');
Flight::route('GET /openapi-registry/toggle/@id', 'Controls_OpenapiToolRegistry@toggle');
Flight::route('GET /openapi-registry/delete/@id', 'Controls_OpenapiToolRegistry@delete');

// Admin-only routes with middleware
Flight::group('/admin/openapi-tools', function() {
    Flight::before('start', function() {
        if (!is_admin()) {
            Flight::json(['error' => 'Admin access required'], 403);
            return false;
        }
    });
    
    Flight::route('GET /', 'Controls_OpenapiToolRegistry@index');
    Flight::route('GET /add', 'Controls_OpenapiToolRegistry@add');
    Flight::route('POST /add', 'Controls_OpenapiToolRegistry@add');
    Flight::route('GET /edit/@id', 'Controls_OpenapiToolRegistry@edit');
    Flight::route('POST /edit/@id', 'Controls_OpenapiToolRegistry@edit');
    Flight::route('GET /toggle/@id', 'Controls_OpenapiToolRegistry@toggle');
    Flight::route('GET /delete/@id', 'Controls_OpenapiToolRegistry@delete');
});

// Public routes with validation
Flight::group('/openapi-registry', function() {
    Flight::before('start', function() {
        $method = $_SERVER['REQUEST_METHOD'];
        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        
        // Allow GET for listing and POST for adding
        if (($method === 'GET' && ($path === '/openapi-registry' || strpos($path, '/edit/') !== false)) ||
            ($method === 'POST' && $path === '/openapi-registry/add')) {
            return;
        }
        
        Flight::json(['error' => 'Invalid request method'], 405);
    });
    
    Flight::route('GET /', 'Controls_OpenapiToolRegistry@index');
    Flight::route('GET /add', 'Controls_OpenapiToolRegistry@add');
    Flight::route('POST /add', 'Controls_OpenapiToolRegistry@add');
    Flight::route('GET /edit/@id', 'Controls_OpenapiToolRegistry@edit');
    Flight::route('POST /edit/@id', 'Controls_OpenapiToolRegistry@edit');
    Flight::route('GET /toggle/@id', 'Controls_OpenapiToolRegistry@toggle');
    Flight::route('GET /delete/@id', 'Controls_OpenapiToolRegistry@delete');
});