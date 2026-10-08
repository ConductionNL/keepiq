export type NormalizeResult =
	| { ok: true; serverUrl: string; origin: string; host: string }
	| { ok: false; code: 'invalid_url' | 'insecure_url' }

function allowsHttp(hostname: string): boolean {
	return hostname === 'localhost' || hostname === '127.0.0.1' || hostname.endsWith('.test')
}

/** Where a pasted Nextcloud page URL stops being the install path. */
const NEXTCLOUD_ROUTE = /\/(index\.php|remote\.php|ocs|apps|settings|login)(\/|$)/

/**
 * A bare host, an origin or any Nextcloud page URL, reduced to the server URL:
 * the origin plus the install subpath, without a trailing slash.
 */
export function normalizeServerUrl(input: string): NormalizeResult {
	const trimmed = input.trim()
	if (!trimmed) return { ok: false, code: 'invalid_url' }
	let url: URL
	try {
		url = new URL(/^[a-z][a-z0-9+.-]*:\/\//i.test(trimmed) ? trimmed : `https://${trimmed}`)
	} catch {
		return { ok: false, code: 'invalid_url' }
	}
	if (url.protocol !== 'https:' && url.protocol !== 'http:') return { ok: false, code: 'invalid_url' }
	if (url.protocol === 'http:' && !allowsHttp(url.hostname)) return { ok: false, code: 'insecure_url' }
	const route = url.pathname.search(NEXTCLOUD_ROUTE)
	const path = (route === -1 ? url.pathname : url.pathname.slice(0, route)).replace(/\/+$/, '')
	return { ok: true, serverUrl: `${url.origin}${path}`, origin: url.origin, host: url.host }
}
