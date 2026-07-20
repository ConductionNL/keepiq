/**
 * Doriath offline app-shell service worker (offline-readonly-cache §3).
 *
 * Precaches the app shell + static bundle assets so the SPA can cold-load
 * offline, then serves them cache-first. It NEVER caches secret material:
 * only same-origin GET requests for the app's own static assets (js/css/
 * fonts/images and the app index) are cached; every `/api/` request is
 * passed straight to the network and never stored. The cache name is keyed
 * by the build version so a new deploy activates a fresh shell
 * (activate-and-claim), evicting the stale one.
 *
 * @spec openspec/changes/offline-readonly-cache/specs/offline-readonly-cache/spec.md#requirement-service-worker-shell
 */

/* eslint-disable no-restricted-globals */
/* global appVersion */
const CACHE_VERSION = (typeof appVersion !== 'undefined' ? appVersion : 'dev')
const CACHE_NAME = 'doriath-shell-' + CACHE_VERSION

/**
 * Whether a request targets cacheable static app-shell assets (never an API
 * path or secret material).
 *
 * @param {Request} request The fetch request.
 * @return {boolean}
 */
function isShellAsset(request) {
	if (request.method !== 'GET') {
		return false
	}
	const url = new URL(request.url)
	if (url.origin !== self.location.origin) {
		return false
	}
	// Never cache API responses — they carry (encrypted) secret material and
	// must always hit the network.
	if (url.pathname.includes('/apps/doriath/api/')) {
		return false
	}
	return /\/apps\/doriath\//.test(url.pathname)
		|| /\.(js|css|woff2?|ttf|png|svg|jpg|jpeg|gif|ico)$/.test(url.pathname)
}

self.addEventListener('install', () => {
	// Take over as soon as installed so a new deploy's shell wins immediately.
	self.skipWaiting()
})

self.addEventListener('activate', (event) => {
	event.waitUntil((async () => {
		const names = await caches.keys()
		await Promise.all(
			names.filter((n) => n.startsWith('doriath-shell-') && n !== CACHE_NAME)
				.map((n) => caches.delete(n)),
		)
		await self.clients.claim()
	})())
})

self.addEventListener('fetch', (event) => {
	if (!isShellAsset(event.request)) {
		return
	}
	event.respondWith((async () => {
		const cache = await caches.open(CACHE_NAME)
		const cached = await cache.match(event.request)
		if (cached) {
			// Refresh in the background (stale-while-revalidate) when online.
			event.waitUntil(
				fetch(event.request).then((res) => {
					if (res && res.ok) {
						cache.put(event.request, res.clone())
					}
				}).catch(() => {}),
			)
			return cached
		}
		try {
			const response = await fetch(event.request)
			if (response && response.ok) {
				cache.put(event.request, response.clone())
			}
			return response
		} catch (e) {
			// Offline and not cached — let the browser surface the failure.
			return Response.error()
		}
	})())
})
