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
            'icons' => []
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

    // Legacy routes - redirect to main page
    public function history($params = []) {
        Flight::redirect('/grocery');
    }

    public function view($params = []) {
        Flight::redirect('/grocery');
    }
}
