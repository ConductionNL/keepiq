/**
 * Doriath offline app-shell service worker (offline-readonly-cache §3).
 *
 * Precaches the app shell and its own JS/CSS at install time so the SPA can
 * cold-load with no network, and serves that cache when — and only when — the
 * browser is offline. It NEVER caches secret material: `/apps/doriath/api/`
 * requests are excluded from the precache and are never stored.
 *
 * The cache name is keyed by the build version so a new deploy activates a
 * fresh shell (activate-and-claim), evicting the stale one.
 *
 * ⚠️ THE ONLINE PATH IS NOT INTERPOSED ON. See the fetch listener.
 *
 * @spec openspec/specs/offline-readonly-cache/spec.md#requirement-the-app-shell-loads-offline-via-a-service-worker
 */

// No `/* global appVersion */`: eslint's browser globals already define
// `appVersion`, so declaring it again is a `no-redeclare` error. The
// `typeof` guard below is what actually makes this safe — it works whether or
// not the build injected a value.
const CACHE_VERSION = typeof appVersion !== 'undefined' ? appVersion : 'dev'
const CACHE_NAME = 'doriath-shell-' + CACHE_VERSION

/**
 * The app-shell document. The worker is served from the app root, so its
 * registration scope IS the shell URL (`…/index.php/apps/doriath/`). Any
 * in-app route falls back to this one document when the network is gone —
 * the SPA router then resolves the requested route client-side.
 *
 * @type {string}
 */
const SHELL_URL = self.registration.scope

/**
 * Whether a request is a top-level document navigation.
 *
 * @param {Request} request The fetch request.
 * @return {boolean}
 */
function isNavigation(request) {
	return request.mode === 'navigate' || request.destination === 'document'
}

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
	return (
		/\/apps\/doriath\//.test(url.pathname)
		|| /\.(js|css|woff2?|ttf|png|svg|jpg|jpeg|gif|ico)$/.test(url.pathname)
	)
}

/**
 * Precache the app shell and the doriath JS/CSS it loads.
 *
 * The shell HTML is fetched once and scanned for its own `src`/`href` assets,
 * so the offline shell is genuinely bootable rather than an HTML document whose
 * scripts are missing. Nothing under `/apps/doriath/api/` is ever added.
 *
 * Failures are swallowed: a browser that installs the worker while offline
 * simply has no offline shell yet, which is strictly better than a failed
 * install.
 *
 * @return {Promise<void>} Resolves once the attempt finishes.
 */
async function precacheShell() {
	try {
		const response = await fetch(SHELL_URL, { credentials: 'same-origin' })
		if (!response || !response.ok) {
			return
		}
		const html = await response.clone().text()
		const cache = await caches.open(CACHE_NAME)
		await cache.put(SHELL_URL, response)

		const assets = new Set()
		const pattern = /(?:src|href)="([^"]+\.(?:js|css)(?:\?[^"]*)?)"/g
		let match = pattern.exec(html)
		while (match !== null) {
			const href = match[1].replace(/&amp;/g, '&')
			if (
				href.includes('/apps/doriath/')
				&& !href.includes('/apps/doriath/api/')
			) {
				assets.add(new URL(href, SHELL_URL).toString())
			}
			match = pattern.exec(html)
		}

		await Promise.all(
			[...assets].map(async (url) => {
				try {
					const asset = await fetch(url, { credentials: 'same-origin' })
					if (asset && asset.ok) {
						await cache.put(url, asset)
					}
				} catch (e) {
					// One missing asset must not abort the rest of the precache.
				}
			}),
		)
	} catch (e) {
		// Offline at install time — nothing to precache yet.
	}
}

self.addEventListener('install', (event) => {
	// Take over as soon as installed so a new deploy's shell wins immediately.
	self.skipWaiting()
	// Precaching happens HERE, which is where the offline requirement is
	// actually satisfied. Previously nothing was precached at all: entries only
	// appeared in the cache as a side effect of serving live traffic, which is
	// exactly the path that broke the app (see the fetch listener).
	event.waitUntil(precacheShell())
})

self.addEventListener('activate', (event) => {
	event.waitUntil(
		(async () => {
			const names = await caches.keys()
			await Promise.all(
				names
					.filter(
						(n) => n.startsWith('doriath-shell-') && n !== CACHE_NAME,
					)
					.map((n) => caches.delete(n)),
			)
			await self.clients.claim()
		})(),
	)
})

self.addEventListener('fetch', (event) => {
	const request = event.request

	// ── The online path is deliberately NOT interposed on ────────────────────
	//
	// This worker used to call `event.respondWith()` for every same-origin
	// `/apps/doriath/` GET, serving it cache-first and writing the response into
	// the cache with:
	//
	//     const response = await fetch(event.request)
	//     if (response && response.ok) { cache.put(event.request, response.clone()) }
	//     return response
	//
	// The moment `clients.claim()` put a page under this worker's control, those
	// requests started failing outright. Verified on CI (doriath run
	// 30798827764, Playwright traces):
	//
	//   • workflows/audit-trail.spec.ts — the first, still-uncontrolled GET of
	//     `/index.php/apps/doriath/lock` returned `200 text/html`;
	//     `…/serviceworker.js` was then fetched and claimed the client; the very
	//     next GET of that same URL came back with status `-1` / `x-unknown`
	//     (`net::ERR_FAILED`). 15 e2e specs failed on it.
	//   • workflows/folder-sharing.spec.ts — the lazily-loaded
	//     `doriath-node_modules_argon2-browser_dist_argon2_wasm.js` chunk, which
	//     is requested only AFTER the worker has taken control, failed the same
	//     way: status `-1`. The Share dialog surfaced
	//     "Loading chunk … failed" and password-protected link shares could not
	//     be created at all.
	//
	// For a user that means: a reload, a bookmark, or any second Doriath link
	// lands on the browser's network-error page, and link sharing is broken.
	//
	// The offline cache is a RESILIENCE feature, not a performance one — it buys
	// nothing while the network is up. So while the browser is online this
	// worker returns without calling `respondWith()` at all, which makes it a
	// no-op and leaves the online path byte-identical to having no service
	// worker registered. Requests are only served from the cache when the
	// browser reports itself offline, which is the only situation the feature
	// exists for, and even then a cache miss falls through to the network.
	if (request.method !== 'GET') {
		return
	}
	if (!isNavigation(request) && !isShellAsset(request)) {
		return
	}
	if (!self.navigator || self.navigator.onLine !== false) {
		return
	}

	event.respondWith(
		(async () => {
			try {
				const cached = await caches.match(request)
				if (cached) {
					return cached
				}
				if (isNavigation(request)) {
					// Any in-app route resolves to the one precached shell document;
					// the SPA router takes it from there.
					const shell = await caches.match(SHELL_URL)
					if (shell) {
						return shell
					}
				}
			} catch (e) {
				// A cache failure must never be worse than not having a worker.
			}
			// Nothing cached — try the network anyway; `navigator.onLine` is a hint,
			// not a guarantee.
			return fetch(request)
		})(),
	)
})
