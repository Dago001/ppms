const CACHE_NAME = 'nis-ppms-offline-v6';

const PRECACHE_ASSETS = [
    '/nisposting/',
    '/nisposting/dashboard',
    '/nisposting/search',
    '/nisposting/analytics',
    '/nisposting/reports',
    '/nisposting/personnel',
    '/nisposting/notifications',
    '/nisposting/profile',
    '/nisposting/offline.html',
    '/nisposting/manifest.json',
    '/nisposting/assets/images/logo.png',
    '/nisposting/assets/images/logo2.png',
    '/nisposting/assets/images/tech_building.jpg',
    '/nisposting/assets/css/style2.css',
    '/nisposting/assets/css/additional-styles.css',
    '/nisposting/assets/js/network-resilience.js',
    'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css'
];

// Helper: Network Fetch with Timeout (2.5 seconds)
function fetchWithTimeout(request, timeoutMs = 2500) {
    return new Promise((resolve, reject) => {
        const timeoutId = setTimeout(() => {
            reject(new Error('Network timeout (Slow connection)'));
        }, timeoutMs);

        fetch(request).then(response => {
            clearTimeout(timeoutId);
            resolve(response);
        }).catch(err => {
            clearTimeout(timeoutId);
            reject(err);
        });
    });
}

// Install Event: Pre-cache offline assets
self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => {
            console.log('[ServiceWorker] Pre-caching offline application assets...');
            return cache.addAll(PRECACHE_ASSETS).catch((err) => {
                console.warn('[ServiceWorker] Some pre-cache assets skipped:', err);
            });
        }).then(() => self.skipWaiting())
    );
});

// Activate Event: Clean up legacy caches
self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((cacheNames) => {
            return Promise.all(
                cacheNames.map((cache) => {
                    if (cache !== CACHE_NAME) {
                        console.log('[ServiceWorker] Deleting old cache version:', cache);
                        return caches.delete(cache);
                    }
                })
            );
        }).then(() => self.clients.claim())
    );
});

// Fetch Event: Fast Network Timeout + Cache Fallback Strategy
self.addEventListener('fetch', (event) => {
    if (event.request.method !== 'GET') return;

    const requestUrl = new URL(event.request.url);
    if (!requestUrl.protocol.startsWith('http')) return;

    // For static assets (CSS, JS, Fonts, Images): Cache First with Stale-While-Revalidate
    const isStaticAsset = requestUrl.pathname.match(/\.(css|js|woff2?|png|jpg|jpeg|svg|ico)$/i) ||
                          requestUrl.hostname.includes('cdnjs.cloudflare.com');

    if (isStaticAsset) {
        event.respondWith(
            caches.match(event.request).then((cachedResponse) => {
                const fetchPromise = fetch(event.request).then((networkResponse) => {
                    if (networkResponse && networkResponse.status === 200) {
                        const responseToCache = networkResponse.clone();
                        caches.open(CACHE_NAME).then(cache => cache.put(event.request, responseToCache));
                    }
                    return networkResponse;
                }).catch(() => {/* Ignore network errors for background revalidation */});

                return cachedResponse || fetchPromise;
            })
        );
        return;
    }

    // For Dynamic Pages: Network-First with 2.5s Timeout, then Cache
    event.respondWith(
        fetchWithTimeout(event.request, 2500)
            .then((networkResponse) => {
                if (networkResponse && networkResponse.status === 200) {
                    const responseToCache = networkResponse.clone();
                    caches.open(CACHE_NAME).then((cache) => {
                        cache.put(event.request, responseToCache);
                    });
                }
                return networkResponse;
            })
            .catch(() => {
                return caches.match(event.request).then((cachedResponse) => {
                    if (cachedResponse) {
                        return cachedResponse;
                    }
                    if (event.request.headers.get('accept') && event.request.headers.get('accept').includes('text/html')) {
                        return caches.match('/nisposting/offline.html');
                    }
                });
            })
    );
});
