/*
 * Registers the service worker that makes Turnin installable and gives it a
 * usable offline fallback. The caching policy itself lives in public/sw.js —
 * read the comment at the top of that file before changing anything there.
 */
if ('serviceWorker' in navigator) {
	window.addEventListener('load', () => {
		navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(() => {
			// An unavailable service worker must never break the page: it only
			// removes offline support and installability.
		});
	});
}
