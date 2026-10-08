export type NormalizeResult = { ok: true; origin: string; host: string } | { ok: false; code: 'invalid_url' | 'insecure_url' }

function allowsHttp(hostname: string): boolean {
	return hostname === 'localhost' || hostname === '127.0.0.1' || hostname.endsWith('.test') || hostname.endsWith('.local')
}

/** A bare host, an origin or any Keepiq web app URL, reduced to its origin. */
export function normalizeOrigin(input: string): NormalizeResult {
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
	return { ok: true, origin: url.origin, host: url.host }
}
