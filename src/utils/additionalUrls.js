/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The extra website addresses of a login (vault-website-addresses), in the
 * form the server stores them: trimmed, without empty entries or repeats
 * (ignoring case), in the order given, at most MAX_URLS.
 *
 * @spec openspec/specs/website-addresses/spec.md#requirement-a-login-carries-several-website-addresses
 */

/** The most extra addresses one login carries (SecretUrlService::MAX_URLS). */
export const MAX_URLS = 20

/** The longest extra address (SecretUrlService::MAX_LENGTH). */
export const MAX_LENGTH = 512

/**
 * The list as stored.
 *
 * @param {Array<unknown>|null|undefined} value The typed addresses.
 * @return {Array<string>}
 * @spec openspec/specs/website-addresses/spec.md#requirement-a-login-carries-several-website-addresses
 */
export function normaliseUrls(value) {
	const seen = new Set()
	const list = []
	for (const entry of Array.isArray(value) ? value : []) {
		const url = typeof entry === 'string' ? entry.trim() : ''
		const key = url.toLowerCase()
		if (url === '' || seen.has(key)) {
			continue
		}
		seen.add(key)
		list.push(url)
	}
	return list.slice(0, MAX_URLS)
}

/**
 * Whether two lists store the same addresses in the same order.
 *
 * @param {Array<unknown>|null|undefined} a One list.
 * @param {Array<unknown>|null|undefined} b The other.
 * @return {boolean}
 * @spec openspec/specs/website-addresses/spec.md#requirement-a-login-carries-several-website-addresses
 */
export function sameUrls(a, b) {
	const left = normaliseUrls(a)
	const right = normaliseUrls(b)
	return (
		left.length === right.length
		&& left.every((url, index) => url === right[index])
	)
}

/**
 * The addresses for display, each with a link when it is a web address. An
 * address without a scheme links over https, as the main address's host does.
 *
 * @param {Array<unknown>|null|undefined} value The stored addresses.
 * @return {Array<{url: string, href: string|null}>}
 * @spec openspec/specs/website-addresses/spec.md#requirement-a-login-carries-several-website-addresses
 */
export function extraUrlLinks(value) {
	return normaliseUrls(value).map((url) => ({ url, href: webHref(url) }))
}

/**
 * The link for one address, or null when it is not a web address.
 *
 * @param {string} url The address.
 * @return {string|null}
 */
function webHref(url) {
	const candidate = /^[a-z][a-z0-9+.-]*:/i.test(url) ? url : `https://${url}`
	try {
		const parsed = new URL(candidate)
		return parsed.protocol === 'https:' || parsed.protocol === 'http:'
			? parsed.href
			: null
	} catch {
		return null
	}
}
