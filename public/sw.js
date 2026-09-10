/*
 * Turnin service worker.
 *
 * READ THIS BEFORE ADDING ANYTHING TO A CACHE.
 *
 * Turnin holds employment data: who works which shift, in which unit, with
 * whom. A service worker cache is unencrypted, survives logout and is shared by
 * everyone using that browser profile. So the rule is inverted from the usual
 * PWA advice — nothing is cached unless it is provably identical for every
 * visitor and contains no personal data.
 *
 * Cached:
 *   - /assets/*            fingerprinted, immutable build output
 *   - /icons/*             static images
 *   - /manifest.webmanifest
 *   - /offline.html        the offline fallback shell
 *
 * Never cached:
 *   - every navigation (a page may be personalised the moment login exists)
 *   - /health, /api/*, /_profiler/*
 *   - anything that is not a same-origin GET
 *   - any response carrying Cache-Control: private / no-store, or Vary: Cookie
 *
 * See docs/SECURITY.md § Offline data.
 */

const VERSION = 'v1';
const SHELL_CACHE = `turnin-shell-${VERSION}`;
const ASSET_CACHE = `turnin-assets-${VERSION}`;
const OFFLINE_URL = '/offline.html';

const SHELL_FILES = [
	OFFLINE_URL,
	'/manifest.webmanifest',
	'/icons/icon-192.png',
	'/icons/icon-512.png',
];

const NEVER_CACHE = [/^\/health$/, /^\/api\//, /^\/_profiler/, /^\/_wdt/];

self.addEventListener('install', (event) => {
	event.waitUntil(
		caches
			.open(SHELL_CACHE)
			.then((cache) => cache.addAll(SHELL_FILES))
			.then(() => self.skipWaiting()),
	);
});

self.addEventListener('activate', (event) => {
	const keep = new Set([SHELL_CACHE, ASSET_CACHE]);

	event.waitUntil(
		caches
			.keys()
			.then((names) =>
				Promise.all(names.filter((name) => !keep.has(name)).map((name) => caches.delete(name))),
			)
			.then(() => self.clients.claim()),
	);
});

/** A response is only safe to keep if the server did not mark it as private. */
function isCacheable(response) {
	if (!response || !response.ok || response.type !== 'basic') {
		return false;
	}

	const cacheControl = response.headers.get('Cache-Control') || '';
	if (/no-store|private/i.test(cacheControl)) {
		return false;
	}

	const vary = response.headers.get('Vary') || '';
	return !/cookie|authorization/i.test(vary);
}

async function cacheFirst(request, cacheName) {
	const cached = await caches.match(request);
	if (cached) {
		return cached;
	}

	const response = await fetch(request);
	if (isCacheable(response)) {
		const cache = await caches.open(cacheName);
		cache.put(request, response.clone());
	}

	return response;
}

async function networkWithOfflineFallback(request) {
	try {
		return await fetch(request);
	} catch {
		const offline = await caches.match(OFFLINE_URL);
		return (
			offline ||
			new Response('Sin conexión', {
				status: 503,
				headers: { 'Content-Type': 'text/plain; charset=utf-8' },
			})
		);
	}
}

self.addEventListener('fetch', (event) => {
	const { request } = event;

	if (request.method !== 'GET') {
		return;
	}

	const url = new URL(request.url);
	if (url.origin !== self.location.origin) {
		return;
	}

	if (NEVER_CACHE.some((pattern) => pattern.test(url.pathname))) {
		return;
	}

	// Navigations always go to the network. Serving a page from cache is how a
	// PWA ends up showing one worker another worker's calendar.
	if (request.mode === 'navigate') {
		event.respondWith(networkWithOfflineFallback(request));
		return;
	}

	if (url.pathname.startsWith('/assets/')) {
		event.respondWith(cacheFirst(request, ASSET_CACHE));
		return;
	}

	if (url.pathname.startsWith('/icons/') || url.pathname === '/manifest.webmanifest') {
		event.respondWith(cacheFirst(request, SHELL_CACHE));
	}

	// Everything else: let the browser do its normal thing, uncached.
});
