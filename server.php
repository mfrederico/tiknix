<?php
/**
 * Tiknix Development Server Router
 * ================================
 *
 * This file enables the PHP built-in server to work with Tiknix's
 * routing system. It handles static files and routes all other
 * requests through the framework.
 *
 * Usage:
 *   php -S localhost:8000 server.php
 *
 * Or use the serve.sh script:
 *   ./serve.sh
 *   ./serve.sh --port=8080
 *   ./serve.sh --host=0.0.0.0 --port=8000
 */

// Get the requested URI
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

// Define static file extensions
$staticExtensions = [
    'css', 'js', 'png', 'jpg', 'jpeg', 'gif', 'ico', 'svg', 'webp',
    'woff', 'woff2', 'ttf', 'eot', 'otf',
    'pdf', 'zip', 'tar', 'gz',
    'mp3', 'mp4', 'webm', 'ogg',
    'json', 'xml', 'txt', 'map'
];

// Check if this is a static file in public/
$publicPath = __DIR__ . '/public' . $uri;
$rootPath = __DIR__ . $uri;

// Get file extension
$extension = strtolower(pathinfo($uri, PATHINFO_EXTENSION));

// Serve static files
if (in_array($extension, $staticExtensions)) {
    // Check public directory first
    if (file_exists($publicPath) && is_file($publicPath)) {
        return serveStatic($publicPath, $extension);
    }
    // Then check root directory
    if (file_exists($rootPath) && is_file($rootPath)) {
        return serveStatic($rootPath, $extension);
    }
}

// Check for actual files in public directory (like favicon.ico, robots.txt)
if ($uri !== '/' && file_exists($publicPath) && is_file($publicPath)) {
    return serveStatic($publicPath, $extension);
}

// Route everything else through the framework
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';

// Include the main entry point
require_once __DIR__ . '/public/index.php';

/**
 * Serve a static file with proper MIME type
 */
function serveStatic($filepath, $extension) {
    $mimeTypes = [
        'css'   => 'text/css',
        'js'    => 'application/javascript',
        'json'  => 'application/json',
        'xml'   => 'application/xml',
        'txt'   => 'text/plain',
        'html'  => 'text/html',
        'htm'   => 'text/html',
        'png'   => 'image/png',
        'jpg'   => 'image/jpeg',
        'jpeg'  => 'image/jpeg',
        'gif'   => 'image/gif',
        'ico'   => 'image/x-icon',
        'svg'   => 'image/svg+xml',
        'webp'  => 'image/webp',
        'woff'  => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf'   => 'font/ttf',
        'eot'   => 'application/vnd.ms-fontobject',
        'otf'   => 'font/otf',
        'pdf'   => 'application/pdf',
        'zip'   => 'application/zip',
        'mp3'   => 'audio/mpeg',
        'mp4'   => 'video/mp4',
        'webm'  => 'video/webm',
        'ogg'   => 'audio/ogg',
        'map'   => 'application/json',
    ];

    $mime = $mimeTypes[$extension] ?? 'application/octet-stream';

    header("Content-Type: $mime");
    header("Content-Length: " . filesize($filepath));

    // Cache static files for development (1 hour)
    header("Cache-Control: public, max-age=3600");

    readfile($filepath);
    return false; // PHP built-in server will not process further
}
