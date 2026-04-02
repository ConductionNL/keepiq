/**
 * Build a favicon URL from a secret's URL using a configured favicon service.
 *
 * @param {string|null} url The URL of the website (e.g. 'https://example.com/login')
 * @param {string|null} faviconServiceUrl URL template with '{domain}' placeholder
 * @return {string|null} Resolved favicon URL, or null if not resolvable
 */
export function getFaviconUrl(url, faviconServiceUrl) {
	if (!faviconServiceUrl || !url) return null
	try {
		const domain = new URL(url).hostname
		return faviconServiceUrl.replace('{domain}', domain)
	} catch {
		return null
	}
}
