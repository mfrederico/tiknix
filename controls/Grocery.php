<?php
/**
 * Grocery List Controller - PWA Version
 *
 * Offline-first grocery list that stores all data in localStorage.
 * No login required, no server-side data storage.
 */

namespace app;

use \Flight as Flight;
use app\BaseControls\Control;

class Grocery extends Control {

    /**
     * Main grocery list page (single-page PWA)
     * All data operations happen in JavaScript via localStorage
     */
    public function index($params = []) {
        $this->viewData['title'] = 'Grocery List';
        // Render without layout - PWA has its own complete HTML structure
        $this->render('grocery/index', $this->viewData, false);
    }

    /**
     * Serve the PWA manifest
     */
    public function manifest($params = []) {
        header('Content-Type: application/manifest+json');
        echo json_encode([
            'name' => 'Grocery List',
            'short_name' => 'Grocery',
            'description' => 'Simple offline grocery list',
            'start_url' => '/grocery',
            'display' => 'standalone',
            'background_color' => '#198754',
            'theme_color' => '#198754',
            'icons' => [
                [
                    'src' => '/grocery/icon/192',
                    'sizes' => '192x192',
                    'type' => 'image/png',
                    'purpose' => 'any maskable'
                ],
                [
                    'src' => '/grocery/icon/512',
                    'sizes' => '512x512',
                    'type' => 'image/png',
                    'purpose' => 'any maskable'
                ]
            ]
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * Serve the service worker
     */
    public function sw($params = []) {
        header('Content-Type: application/javascript');
        header('Service-Worker-Allowed: /');
        echo $this->getServiceWorkerCode();
        exit;
    }

    /**
     * Generate service worker JavaScript
     */
    private function getServiceWorkerCode(): string {
        $version = 'v2';
        return <<<JS
const CACHE_NAME = 'grocery-cache-{$version}';
const ASSETS_TO_CACHE = [
    '/grocery',
    '/css/app.css',
    '/js/app.js',
    'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css',
    'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js',
    'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css',
    'https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js',
    'https://code.jquery.com/jquery-3.7.1.min.js'
];

// Install event - cache assets
self.addEventListener('install', event => {
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then(cache => cache.addAll(ASSETS_TO_CACHE))
            .then(() => self.skipWaiting())
    );
});

// Activate event - clean old caches
self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys().then(keys => {
            return Promise.all(
                keys.filter(key => key !== CACHE_NAME)
                    .map(key => caches.delete(key))
            );
        }).then(() => self.clients.claim())
    );
});

// Fetch event - serve from cache, fallback to network
self.addEventListener('fetch', event => {
    // Only handle GET requests
    if (event.request.method !== 'GET') return;

    event.respondWith(
        caches.match(event.request)
            .then(cached => {
                if (cached) return cached;
                return fetch(event.request).then(response => {
                    // Don't cache non-successful responses
                    if (!response || response.status !== 200) {
                        return response;
                    }
                    // Clone and cache
                    const toCache = response.clone();
                    caches.open(CACHE_NAME).then(cache => {
                        cache.put(event.request, toCache);
                    });
                    return response;
                });
            })
            .catch(() => {
                // Return cached /grocery page for navigation requests
                if (event.request.mode === 'navigate') {
                    return caches.match('/grocery');
                }
            })
    );
});
JS;
    }

    /**
     * Generate PWA icon dynamically
     * Creates a simple grocery cart icon with the app color
     */
    public function icon($params = []) {
        $size = isset($params['size']) ? (int)$params['size'] : 192;
        // Clamp size between 48 and 512
        $size = max(48, min(512, $size));

        // Create image
        $img = imagecreatetruecolor($size, $size);

        // Enable alpha blending
        imagealphablending($img, false);
        imagesavealpha($img, true);

        // Colors - green theme (#198754)
        $green = imagecolorallocate($img, 25, 135, 84);
        $white = imagecolorallocate($img, 255, 255, 255);
        $transparent = imagecolorallocatealpha($img, 0, 0, 0, 127);

        // Fill with transparent (for maskable icon)
        imagefill($img, 0, 0, $transparent);

        // Draw filled circle as background
        $center = $size / 2;
        $radius = ($size / 2) - 2;
        imagefilledellipse($img, (int)$center, (int)$center, (int)($radius * 2), (int)($radius * 2), $green);

        // Draw a simple cart shape (basket icon)
        $scale = $size / 100;
        $offsetX = $size * 0.25;
        $offsetY = $size * 0.28;

        // Cart body (trapezoid-ish shape)
        $cartPoints = [
            (int)($offsetX + 15 * $scale), (int)($offsetY + 20 * $scale),  // top left
            (int)($offsetX + 45 * $scale), (int)($offsetY + 20 * $scale),  // top right
            (int)($offsetX + 40 * $scale), (int)($offsetY + 40 * $scale),  // bottom right
            (int)($offsetX + 20 * $scale), (int)($offsetY + 40 * $scale),  // bottom left
        ];
        imagefilledpolygon($img, $cartPoints, $white);

        // Handle
        $handleWidth = (int)max(2, 3 * $scale);
        imagesetthickness($img, $handleWidth);
        imagearc($img,
            (int)($offsetX + 30 * $scale),
            (int)($offsetY + 12 * $scale),
            (int)(24 * $scale),
            (int)(20 * $scale),
            180, 360, $white);

        // Wheels
        $wheelRadius = (int)max(3, 4 * $scale);
        imagefilledellipse($img, (int)($offsetX + 23 * $scale), (int)($offsetY + 47 * $scale), $wheelRadius * 2, $wheelRadius * 2, $white);
        imagefilledellipse($img, (int)($offsetX + 37 * $scale), (int)($offsetY + 47 * $scale), $wheelRadius * 2, $wheelRadius * 2, $white);

        // Output as PNG
        header('Content-Type: image/png');
        header('Cache-Control: public, max-age=31536000');
        imagepng($img);
        imagedestroy($img);
        exit;
    }

    // Legacy routes - redirect to main page
    public function history($params = []) {
        Flight::redirect('/grocery');
    }

    public function view($params = []) {
        Flight::redirect('/grocery');
    }
}
