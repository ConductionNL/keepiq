/**
 * The one reading of an item or tab url that matching, Launch and suggestions share:
 * a bare host (`github.com`, `localhost:8080`) means https, and anything but http(s) is no url.
 */
export function webUrl(raw: string | null | undefined): URL | null {
	const trimmed = raw?.trim()
	if (!trimmed) return null
	// `localhost:8080` is a host and port, not a scheme.
	const withScheme = /^[a-z][a-z\d+.-]*:(?!\d)/i.test(trimmed) ? trimmed : `https://${trimmed}`
	try {
		const url = new URL(withScheme)
		return (url.protocol === 'https:' || url.protocol === 'http:') && url.hostname ? url : null
	} catch {
		return null
	}
}
