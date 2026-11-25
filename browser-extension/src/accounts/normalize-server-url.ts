export type NormalizeResult =
	| { ok: true; serverUrl: string; origin: string; host: string }
	| { ok: false; code: 'invalid_url' | 'insecure_url' }

function allowsHttp(hostname: string): boolean {
	return hostname === 'localhost' || hostname === '127.0.0.1' || hostname.endsWith('.test')
}

/**
 * The host permission for a server, which the popup requests and the background and popup check: its origin, except
 * that Firefox gets the host without the port. Firefox grants a pattern with a port, but its requests to that origin
 * stay under CORS, so a server on a port other than the default one could not be reached; a pattern without a port
 * covers every port of the host there.
 *
 * @spec openspec/changes/clients-extension-firefox-and-safari-builds/specs/clients-browser-builds/spec.md#scenario-firefox-reaches-a-server-on-its-own-port
 */
export function serverOrigins(serverUrl: string, target: string = import.meta.env.BROWSER): string[] {
	const url = new URL(serverUrl)
	return [target === 'firefox' ? `${url.protocol}//${url.hostname}/*` : `${url.origin}/*`]
}

/** Where a pasted Nextcloud page URL stops being the install path. */
const NEXTCLOUD_ROUTE = /\/(index\.php|remote\.php|ocs|apps|settings|login|core|s|f|call)(\/|$)/

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
