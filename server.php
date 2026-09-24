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

/*
 * TASK WORKSPACES ONLY: a missing image is shown as a labelled placeholder.
 *
 * A Task Board workspace (<project>/.aibuilder/wt/…, or a legacy clone under core's
 * projects/) has no uploads — public/uploads and secure/uploads are gitignored — so every
 * image in its preview was broken, including the ones an app serves itself (Serenity's
 * /img/<id>/webp resizer). Here, an image request that finds nothing — a missing file, or
 * the app answering 4xx/5xx — gets an SVG that SAYS it is a placeholder and names the path,
 * so it cannot be mistaken for the real picture. The live site never runs this router
 * (nginx + FPM serve it), so a real missing image there is still a real 404.
 */
$inTaskWorkspace = (bool) preg_match('#/\.aibuilder/wt/[^/]+$|/tiknix/projects/\d+/#', __DIR__);
$wantsImage = str_starts_with(strtolower($_SERVER['HTTP_ACCEPT'] ?? ''), 'image/')
    || in_array(strtolower(pathinfo($uri, PATHINFO_EXTENSION)), ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'avif'], true);

function workspacePlaceholder(string $uri): string {
    $w = 400; $h = 300;
    if (preg_match('/^(\d{2,4})x(\d{2,4})$/', (string) ($_GET['size'] ?? ''), $m)) { $w = (int) $m[1]; $h = (int) $m[2]; }
    $label = htmlspecialchars(mb_strimwidth($uri, 0, 48, '…'), ENT_QUOTES | ENT_XML1);
    $fs = max(10, (int) round(min($w, $h) / 14));
    return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $w . '" height="' . $h . '" viewBox="0 0 ' . $w . ' ' . $h . '">'
         . '<rect width="100%" height="100%" fill="#e9ecef" stroke="#adb5bd" stroke-dasharray="6 4"/>'
         . '<text x="50%" y="46%" text-anchor="middle" font-family="sans-serif" font-size="' . ($fs + 4) . '" fill="#495057">Placeholder image</text>'
         . '<text x="50%" y="58%" text-anchor="middle" font-family="sans-serif" font-size="' . $fs . '" fill="#6c757d">not in this task workspace</text>'
         . '<text x="50%" y="68%" text-anchor="middle" font-family="monospace" font-size="' . max(9, $fs - 2) . '" fill="#868e96">' . $label . '</text></svg>';
}

function sendWorkspacePlaceholder(string $uri): bool {
    http_response_code(200);   // an <img> does not render an error response's body
    header('Content-Type: image/svg+xml');
    header('X-Tiknix-Placeholder: missing-in-task-workspace');
    header('Cache-Control: no-store');
    echo workspacePlaceholder($uri);
    return true;
}

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

// A missing image FILE in a task workspace — before the framework turns it into an HTML 404.
if ($inTaskWorkspace && $wantsImage && $extension !== '' && !is_file($publicPath) && !is_file($rootPath)) {
    return sendWorkspacePlaceholder($uri);
}

// An image the APP serves (e.g. /img/<id>/webp) that it cannot produce here. Its error
// cannot be caught in this process — Flight and image controllers discard every output
// buffer before they send — so ask the app in a SECOND request to this same server and look
// at the answer: an image passes through untouched, an error becomes the placeholder. Only
// with PHP_CLI_SERVER_WORKERS > 1 (the Task Board starts its test server with 4): on a
// single-worker server the inner request would wait on this one forever.
if ($inTaskWorkspace && $wantsImage && empty($_SERVER['HTTP_X_TIKNIX_PLACEHOLDER_PROBE'])
    && (int) getenv('PHP_CLI_SERVER_WORKERS') > 1) {
    $headers = ['X-Tiknix-Placeholder-Probe: 1'];
    foreach (['HTTP_ACCEPT' => 'Accept', 'HTTP_COOKIE' => 'Cookie', 'HTTP_USER_AGENT' => 'User-Agent', 'HTTP_IF_NONE_MATCH' => 'If-None-Match'] as $k => $h) {
        if (!empty($_SERVER[$k])) $headers[] = "{$h}: {$_SERVER[$k]}";
    }
    $ch = curl_init('http://127.0.0.1:' . (int) $_SERVER['SERVER_PORT'] . $_SERVER['REQUEST_URI']);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_HTTPHEADER => $headers,
                            CURLOPT_TIMEOUT => 60, CURLOPT_FOLLOWLOCATION => false]);
    $resp = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    if ($resp === false || $code >= 400) return sendWorkspacePlaceholder($uri);
    $hsize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    http_response_code($code);
    foreach (explode("\r\n", substr($resp, 0, $hsize)) as $line) {
        if (preg_match('/^(Content-Type|Cache-Control|ETag|Last-Modified|Location):/i', $line)) header($line);
    }
    echo substr($resp, $hsize);
    return true;
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
