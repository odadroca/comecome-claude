/**
 * Service Worker for ComeCome PWA
 * Provides offline functionality
 * Network-first for pages, cache-first for static assets
 */

const CACHE_NAME = 'comecome-v0.11.0';
const STATIC_ASSETS = [
    '/assets/css/pico.min.css',
    '/assets/css/custom.css',
    '/assets/css/comecome-theme.css',
    '/assets/js/app.js',
    '/manifest.json',
    // Self-hosted fonts (offline-first; design refresh)
    '/assets/fonts/lexend-300.woff2',
    '/assets/fonts/lexend-400.woff2',
    '/assets/fonts/lexend-500.woff2',
    '/assets/fonts/lexend-600.woff2',
    '/assets/fonts/lexend-700.woff2',
    '/assets/fonts/atkinson-400.woff2',
    '/assets/fonts/atkinson-700.woff2',
    '/assets/fonts/atkinson-400-italic.woff2',
    '/assets/fonts/atkinson-700-italic.woff2'
];

// Install event - cache static assets only
self.addEventListener('install', event => {
    self.skipWaiting(); // Activate immediately
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then(cache => cache.addAll(STATIC_ASSETS))
    );
});

// Activate event - clean up old caches and claim clients
self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys().then(cacheNames => {
            return Promise.all(
                cacheNames.map(cacheName => {
                    if (cacheName !== CACHE_NAME) {
                        return caches.delete(cacheName);
                    }
                })
            );
        }).then(() => self.clients.claim()) // Take control immediately
    );
});

// Fetch event - network-first for pages, cache-first for static assets
self.addEventListener('fetch', event => {
    const url = new URL(event.request.url);

    // Skip non-GET requests (POST to APIs, etc.)
    if (event.request.method !== 'GET') return;

    // For HTML pages and PHP - always network-first
    if (event.request.mode === 'navigate' ||
        url.pathname.endsWith('.php') ||
        url.pathname === '/') {
        event.respondWith(
            fetch(event.request)
                .catch(() => caches.match(event.request))
        );
        return;
    }

    // For API calls - network only, never cache
    if (url.pathname.startsWith('/api/')) {
        event.respondWith(fetch(event.request));
        return;
    }

    // For static assets (CSS, JS, images) - cache-first with network fallback
    event.respondWith(
        caches.match(event.request)
            .then(response => {
                if (response) return response;
                return fetch(event.request).then(networkResponse => {
                    // Cache the new response for future use
                    if (networkResponse.ok) {
                        const clone = networkResponse.clone();
                        caches.open(CACHE_NAME).then(cache => cache.put(event.request, clone));
                    }
                    return networkResponse;
                });
            })
    );
});
