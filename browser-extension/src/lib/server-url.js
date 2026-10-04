/**
 * The address of a Keepiq server, as the extension stores it: scheme, host,
 * port and any Nextcloud subfolder, without a page path, query or fragment.
 * Plain http is refused except for a local development host, so an app
 * password never travels in clear.
 *
 * Pure: no DOM, no network.
 *
 * @spec openspec/changes/clients-extension-gaps/specs/extension-pairing/spec.md#requirement-a-server-address-is-https-and-stored-clean
 */

// Where a pasted Nextcloud page address stops being the server address.
const PAGE_PATH = /\/(index\.php|apps|login|ocs|remote\.php|settings|s)(\/|$)/

/**
 * Whether a host is a local development host, where http is allowed.
 *
 * @param {string} hostname The host name.
 * @return {boolean}
 */
export function isLocalHost(hostname) {
	const host = hostname.toLowerCase().replace(/^\[|\]$/g, '')
	return (
		host === 'localhost'
		|| host === '127.0.0.1'
		|| host === '::1'
		|| host.endsWith('.localhost')
		|| host.endsWith('.test')
		|| host.endsWith('.local')
	)
}

/**
 * Whether a stored server address may be used: https, or http on a local host.
 *
 * @param {string} url The stored address.
 * @return {boolean}
 */
export function isSecureServerUrl(url) {
	let parsed
	try {
		parsed = new URL(url)
	} catch {
		return false
	}
	if (parsed.protocol === 'https:') return true
	return parsed.protocol === 'http:' && isLocalHost(parsed.hostname)
}

/**
 * Clean up a server address as typed or pasted.
 *
 * @param {string} raw What the user entered.
 * @return {string} The address to store, without a trailing slash.
 * @throws {Error} When it is not a usable https address.
 */
export function normalizeServerUrl(raw) {
	let text = String(raw ?? '').trim()
	if (text === '') throw new Error('Enter the address of your Nextcloud')
	if (!/^[a-z][a-z0-9+.-]*:\/\//i.test(text)) text = 'https://' + text
	let parsed
	try {
		parsed = new URL(text)
	} catch {
		throw new Error('This is not a valid address')
	}
	if (parsed.protocol !== 'https:' && parsed.protocol !== 'http:') {
		throw new Error('This is not a valid address')
	}
	if (parsed.username || parsed.password) {
		throw new Error('Leave the user name and password out of the address')
	}
	if (parsed.protocol === 'http:' && !isLocalHost(parsed.hostname)) {
		throw new Error(
			'Keepiq needs an https address, so your app password is never sent in clear',
		)
	}
	const cut = parsed.pathname.search(PAGE_PATH)
	const folder = (
		cut === -1 ? parsed.pathname : parsed.pathname.slice(0, cut)
	).replace(/\/+$/, '')
	return parsed.origin + folder
}
