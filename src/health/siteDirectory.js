/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Site checks against the site directory, in the browser only
 * (health-site-security-checks D2/D4). The directory copy is public and the
 * same for every user; which logins match it, and the vault facts it is
 * compared with, never leave the browser.
 *
 * @spec openspec/changes/health-site-security-checks/specs/health-site-checks/spec.md#requirement-flag-unused-two-factor-login-and-available-passkeys
 */

import { getDomain } from 'tldts'
import { registrableDomainOf } from '../shared/registrableDomain.js'

/**
 * The hostname of a saved address: a bare host (`github.com`) means https,
 * and anything but http(s) has none.
 *
 * @param {string|null|undefined} raw The saved address.
 * @return {string|null} The lowercase hostname.
 * @spec openspec/changes/health-site-security-checks/tasks.md#task-3.2
 */
export function hostOf(raw) {
	const trimmed = typeof raw === 'string' ? raw.trim() : ''
	if (trimmed === '') {
		return null
	}
	// `localhost:8080` is a host and port, not a scheme.
	const withScheme = /^[a-z][a-z\d+.-]*:(?!\d)/i.test(trimmed) ? trimmed : `https://${trimmed}`
	try {
		const url = new URL(withScheme)
		if ((url.protocol !== 'https:' && url.protocol !== 'http:') || !url.hostname) {
			return null
		}
		return url.hostname.toLowerCase()
	} catch {
		return null
	}
}

/**
 * The registrable domain of a saved address, by the rule the extension uses.
 *
 * @param {string|null|undefined} raw The saved address.
 * @return {string|null}
 * @spec openspec/changes/health-site-security-checks/tasks.md#task-3.2
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
 * @spec openspec/changes/health-site-security-checks/tasks.md#task-3.2
 */
export function directoryEntry(list, raw) {
	if (!list || typeof list !== 'object') {
		return null
	}
	const host = hostOf(raw)
	if (host === null) {
		return null
	}
	for (const domain of [host, siteDomain(raw)]) {
		if (domain && Object.hasOwn(list, domain)) {
			const documentation = typeof list[domain] === 'string' && list[domain].startsWith('https://')
				? list[domain]
				: null
			return { domain, documentation }
		}
	}
	return null
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
 * @spec openspec/changes/health-site-security-checks/tasks.md#task-3.2
 */
export function vaultSiteFacts(secrets, typeNameById) {
	const seedDomains = new Set()
	const passkeyDomains = new Set()
	for (const secret of secrets) {
		const typeName = typeNameById.get(secret?.typeId)
		const domain = typeName === 'totp' || typeName === 'passkey' ? siteDomain(secret.url) : null
		if (domain === null) {
			continue
		}
		if (typeName === 'totp') {
			seedDomains.add(domain)
		} else {
			passkeyDomains.add(domain)
		}
	}
	return { seedDomains, passkeyDomains }
}
