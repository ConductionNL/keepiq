import { loadState } from '@nextcloud/initial-state'

/**
 * Resolve the configured favicon service URL template, if any.
 *
 * The admin setting `favicon_service_url` uses `{domain}` as a placeholder.
 * When it is empty (the privacy-respecting default), no external favicon is
 * fetched and callers fall back to type icons.
 *
 * @return {string} The template, or '' when favicons are disabled.
 */
function faviconTemplate() {
	try {
		return loadState('doriath', 'faviconServiceUrl', '') || ''
	} catch {
		return ''
	}
}

/**
 * Extract the hostname from a secret URL.
 *
 * @param {string|null} url The secret URL.
 * @return {string|null} The hostname, or null when not resolvable.
 */
export function extractDomain(url) {
	if (!url) {
		return null
	}
	try {
		const candidate = url.includes('://') ? url : `https://${url}`
		return new URL(candidate).hostname || null
	} catch {
		return null
	}
}

/**
 * Resolve a favicon URL for a secret's URL, or null when disabled/unresolvable.
 *
 * @param {string|null} url The secret URL.
 * @return {string|null} The favicon URL, or null to use the type icon.
 */
export function resolveFaviconUrl(url) {
	const template = faviconTemplate()
	if (!template) {
		return null
	}
	const domain = extractDomain(url)
	if (!domain) {
		return null
	}
	return template.replace('{domain}', encodeURIComponent(domain))
}

/**
 * Map a secret type name to a Material Design Icon component name.
 *
 * @param {string} typeName The secret type machine name.
 * @return {string} The vue-material-design-icons component name.
 */
export function typeIconName(typeName) {
	const map = {
		login: 'Key',
		api_key: 'CodeTags',
		ssh_key: 'Console',
		certificate: 'ShieldCheck',
		note: 'NoteText',
		database: 'Database',
	}
	return map[typeName] || 'Key'
}
