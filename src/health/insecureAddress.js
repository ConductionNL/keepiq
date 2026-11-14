/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The plain-http rule of the password health report (health-site-security-checks
 * D1). A login saved with an `http://` address sends its password in the clear,
 * except to a host that never leaves the machine or the private network:
 * `localhost`, loopback and private-network addresses are not flagged. Pure and
 * synchronous; the address is the login's own plain-text url and never leaves
 * the browser.
 *
 * @spec openspec/changes/health-site-security-checks/specs/health-site-checks/spec.md#requirement-flag-logins-saved-with-an-unencrypted-address
 */

/**
 * IPv4 ranges that stay on the machine or the private network: loopback,
 * RFC 1918 and link-local.
 *
 * @type {Array<[number, number, number]>} First octet, second octet from, second octet to.
 */
const PRIVATE_V4 = [
	[127, 0, 255],
	[10, 0, 255],
	[172, 16, 31],
	[192, 168, 168],
	[169, 254, 254],
]

/**
 * The host of an `http://` address, lower-cased and without brackets, or
 * null when the address is not plain http or does not parse.
 *
 * @param {unknown} address The saved address.
 * @return {string|null}
 */
function httpHost(address) {
	if (typeof address !== 'string' || !/^http:\/\//i.test(address.trim())) {
		return null
	}
	try {
		return new URL(address.trim()).hostname.toLowerCase().replace(/^\[|\]$/g, '')
	} catch {
		return null
	}
}

/**
 * Whether a host stays on the machine or the private network.
 *
 * @param {string} host A lower-cased host without brackets.
 * @return {boolean}
 */
export function isLocalHost(host) {
	if (host === 'localhost' || host.endsWith('.localhost')) {
		return true
	}
	const v4 = host.match(/^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})$/)
	if (v4) {
		const [first, second] = [Number(v4[1]), Number(v4[2])]
		return PRIVATE_V4.some(([a, from, to]) => first === a && second >= from && second <= to)
	}
	if (host.includes(':')) {
		// IPv6: loopback, unique local (fc00::/7) and link-local (fe80::/10).
		return host === '::1' || /^f[cd][0-9a-f]{0,2}:/.test(host) || /^fe[89ab][0-9a-f]?:/.test(host)
	}
	return false
}

/**
 * Whether an address sends a password in the clear: plain `http://` to a host
 * that is not local or private.
 *
 * @param {unknown} address The saved address.
 * @return {boolean}
 * @spec openspec/changes/health-site-security-checks/specs/health-site-checks/spec.md#requirement-flag-logins-saved-with-an-unencrypted-address
 */
export function isInsecureAddress(address) {
	const host = httpHost(address)
	return host !== null && host !== '' && !isLocalHost(host)
}

/**
 * The same address over https: only the scheme changes.
 *
 * @param {string} address An `http://` address.
 * @return {string}
 * @spec openspec/changes/health-site-security-checks/specs/health-site-checks/spec.md#scenario-a-vault-user-finds-a-login-saved-with-http
 */
export function httpsAddress(address) {
	return address.trim().replace(/^http:\/\//i, 'https://')
}
