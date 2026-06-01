/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Favicon resolution for secrets.
 *
 * Privacy-first: no external service is contacted unless an admin has
 * configured `favicon_service_url` (with a `{domain}` placeholder). When the
 * service is disabled or the URL is empty, callers fall back to type icons.
 */

import { loadState } from '@nextcloud/initial-state'

/**
 * Read the admin-configured favicon service URL template, if any.
 *
 * @return {string} The template (with a {domain} placeholder), or '' when disabled.
 */
function faviconServiceUrl() {
	try {
		return loadState('doriath', 'favicon_service_url', '') || ''
	} catch {
		return ''
	}
}

/**
 * Extract the hostname from a secret URL.
 *
 * @param {string|null} url The secret URL.
 * @return {string|null} The hostname, or null when not parseable.
 */
export function domainFromUrl(url) {
	if (!url) {
		return null
	}
	try {
		const withScheme = url.includes('://') ? url : `https://${url}`
		return new URL(withScheme).hostname || null
	} catch {
		return null
	}
}

/**
 * Resolve the favicon URL for a secret, or null when none is available.
 *
 * @param {string|null} url The secret URL.
 * @return {string|null} A favicon image URL, or null to fall back to a type icon.
 */
export function resolveFaviconUrl(url) {
	const template = faviconServiceUrl()
	if (!template) {
		return null
	}

	const domain = domainFromUrl(url)
	if (!domain) {
		return null
	}

	return template.replace('{domain}', encodeURIComponent(domain))
}
