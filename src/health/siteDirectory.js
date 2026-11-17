/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Site checks against the site directory, in the browser only
 * (health-site-security-checks D2/D4). The directory copy is public and the
 * same for every user; which logins match it, and the vault facts it is
 * compared with, never leave the browser.
 *
 * @spec openspec/specs/health-site-checks/spec.md#requirement-flag-unused-two-factor-login-and-available-passkeys
 */

import { getDomain } from 'tldts'
import { registrableDomainOf } from '../shared/registrableDomain.js'

/**
 * The hostname of a saved address: a bare host (`github.com`) means https,
 * and anything but http(s) has none.
 *
 * @param {string|null|undefined} raw The saved address.
 * @return {string|null} The lowercase hostname.
 * @spec openspec/changes/archive/2026-11-17-health-site-security-checks/tasks.md#task-3.2
 */
export function hostOf(raw) {
	const trimmed = typeof raw === 'string' ? raw.trim() : ''
	if (trimmed === '') {
		return null
	}
	// `localhost:8080` is a host and port, not a scheme.
	const withScheme = /^[a-z][a-z\d+.-]*:(?!\d)/i.test(trimmed)
		? trimmed
		: `https://${trimmed}`
	return webHostname(withScheme)
}

/**
 * The lowercase hostname of an absolute http(s) address, null for any other
 * scheme, an empty host or an unparsable address.
 *
 * @param {string} address An address with a scheme.
 * @return {string|null}
 * @spec openspec/changes/archive/2026-11-17-health-site-security-checks/tasks.md#task-3.2
 */
function webHostname(address) {
	let url
	try {
		url = new URL(address)
	} catch {
		return null
	}
	if (url.protocol !== 'https:' && url.protocol !== 'http:') {
		return null
	}
	return url.hostname ? url.hostname.toLowerCase() : null
}

/**
 * The registrable domain of a saved address, by the rule the extension uses.
 *
 * @param {string|null|undefined} raw The saved address.
 * @return {string|null}
 * @spec openspec/changes/archive/2026-11-17-health-site-security-checks/tasks.md#task-3.2
 */
export function siteDomain(raw) {
	return registrableDomainOf(hostOf(raw), getDomain)
}

/**
 * The directory entry of a saved address: its host first, then its
 * registrable domain, so `login.example.com` finds `example.com`.
 *
 * @param {Object<string, string>|null|undefined} list Domain -> documentation URL ('' for none).
 * @param {string|null|undefined} raw The saved address.
 * @return {{domain: string, documentation: string|null}|null}
 * @spec openspec/changes/archive/2026-11-17-health-site-security-checks/tasks.md#task-3.2
 */
export function directoryEntry(list, raw) {
	const host = list && typeof list === 'object' ? hostOf(raw) : null
	if (host === null) {
		return null
	}
	const domain = [host, siteDomain(raw)].find(
		(candidate) => candidate && Object.hasOwn(list, candidate),
	)
	return domain ? { domain, documentation: httpsOrNull(list[domain]) } : null
}

/**
 * A directory documentation value when it is an https address, else null.
 *
 * @param {string|null|undefined} value The directory's value for a domain.
 * @return {string|null}
 * @spec openspec/changes/archive/2026-11-17-health-site-security-checks/tasks.md#task-3.2
 */
function httpsOrNull(value) {
	return typeof value === 'string' && value.startsWith('https://') ? value : null
}

/**
 * The registrable domains the vault already holds a second factor or a passkey
 * for as their own items: an authenticator (`totp`) item matching a host, a
 * `passkey` item by its relying party id, which it mirrors into `url`
 * (design D4).
 *
 * @param {Array<object>} secrets The listed secrets, plaintext fields only.
 * @param {Map<string|number, string>} typeNameById Secret type id -> type name.
 * @return {{seedDomains: Set<string>, passkeyDomains: Set<string>}}
 * @spec openspec/changes/archive/2026-11-17-health-site-security-checks/tasks.md#task-3.2
 */
export function vaultSiteFacts(secrets, typeNameById) {
	const seedDomains = new Set()
	const passkeyDomains = new Set()
	const setByType = new Map([
		['totp', seedDomains],
		['passkey', passkeyDomains],
	])
	for (const secret of secrets) {
		const set = setByType.get(typeNameById.get(secret?.typeId))
		const domain = set ? siteDomain(secret.url) : null
		if (domain !== null) {
			set.add(domain)
		}
	}
	return { seedDomains, passkeyDomains }
}
