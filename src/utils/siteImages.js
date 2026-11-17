/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The site preview image (vault-website-addresses D4): built from the admin's
 * `site_preview_service_url` template, `{url}` replaced by the login's
 * encoded address. No template, no image and no request.
 *
 * @spec openspec/specs/website-addresses/spec.md#requirement-site-icons-and-previews-are-opt-in-per-instance
 */

import { loadState } from '@nextcloud/initial-state'

/**
 * The admin's preview template, '' when previews are off.
 *
 * @return {string}
 */
export function previewTemplate() {
	try {
		return loadState('keepiq', 'sitePreviewServiceUrl', '') || ''
	} catch {
		return ''
	}
}

/**
 * Why a template cannot be stored, or null; an empty one is valid (off).
 *
 * @param {string} template The template.
 * @param {string} placeholder `{domain}` or `{url}`.
 * @return {string|null}
 */
export function templateProblem(template, placeholder) {
	const value = String(template ?? '').trim()
	if (value === '') {
		return null
	}
	if (!value.includes(placeholder)) {
		return 'placeholder'
	}
	try {
		const parsed = new URL(value.replaceAll(placeholder, 'x'))
		return parsed.protocol === 'https:' && parsed.hostname !== '' ? null : 'https'
	} catch {
		return 'https'
	}
}

/**
 * The preview image address of a login, or null when previews are off or the
 * login has no web address.
 *
 * @param {string} template The admin's template, '' when off.
 * @param {string|null|undefined} url The login's main address.
 * @return {string|null}
 * @spec openspec/specs/website-addresses/spec.md#requirement-site-icons-and-previews-are-opt-in-per-instance
 */
export function previewImageUrl(template, url) {
	if (typeof template !== 'string' || template === '' || templateProblem(template, '{url}') !== null) {
		return null
	}
	const address = webAddress(url)
	return address ? template.replaceAll('{url}', encodeURIComponent(address)) : null
}

/**
 * The login's address as an http(s) address, a bare host read as https.
 *
 * @param {string|null|undefined} url The address.
 * @return {string|null}
 */
export function webAddress(url) {
	const raw = String(url ?? '').trim()
	if (raw === '') {
		return null
	}
	try {
		const parsed = new URL(/^[a-z][a-z0-9+.-]*:/i.test(raw) ? raw : `https://${raw}`)
		return parsed.protocol === 'https:' || parsed.protocol === 'http:' ? parsed.href : null
	} catch {
		return null
	}
}

/**
 * The site's well-known change-password address, for the login's main
 * address host (vault-website-addresses D3), or null without a web address.
 *
 * @param {string|null|undefined} url The login's main address.
 * @return {string|null}
 * @spec openspec/specs/website-addresses/spec.md#requirement-open-the-sites-change-password-page
 */
export function changePasswordUrl(url) {
	const address = webAddress(url)
	return address ? `https://${new URL(address).host}/.well-known/change-password` : null
}
