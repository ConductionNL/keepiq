/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The two site directory categories: a login for a site that offers one-time
 * codes and holds no seed is listed under Two-factor available, one for a site
 * that offers passkeys with no passkey for it under Passkey available, and
 * nothing is listed while the directory is off. Uses the small directory
 * fixture: one one-time code site and one passkey site.
 *
 * @spec openspec/changes/health-site-security-checks/specs/health-site-checks/spec.md#requirement-flag-unused-two-factor-login-and-available-passkeys
 */

import { describe, expect, it } from 'vitest'
import { analyse, vaultScore } from '../../src/health/engine.js'
import {
	directoryEntry,
	hostOf,
	siteDomain,
	vaultSiteFacts,
} from '../../src/health/siteDirectory.js'
import directory from '../fixtures/site-directory.json'

const digest = async (value) => `d:${value}`

/**
 * A login row as the health store builds it.
 *
 * @param {object} fields The fields to set.
 * @return {object}
 */
function login(fields) {
	return {
		id: fields.id,
		name: fields.id,
		url: fields.url,
		value: `unique-${fields.id}-Xq7!pLm2#Rv9`,
		keyUpdatedAt: null,
		hasSeed: fields.hasSeed ?? false,
		hasPasskey: fields.hasPasskey ?? false,
	}
}

/**
 * The flags of each finding by id.
 *
 * @param {Array<object>} rows The rows.
 * @param {object|null} siteDirectory The directory copy.
 * @return {Promise<object>}
 */
async function flagsOf(rows, siteDirectory) {
	const { findings, summary } = await analyse(rows, { digest, siteDirectory })
	return {
		flags: Object.fromEntries(findings.map((f) => [f.id, f.flags])),
		findings,
		summary,
	}
}

describe('site directory matching', () => {
	it.each([
		['https://github.com/login', 'github.com', 'github.com'],
		['github.com', 'github.com', 'github.com'],
		['https://login.example.co.uk/x', 'login.example.co.uk', 'example.co.uk'],
		['HTTPS://Sub.Example.COM', 'sub.example.com', 'example.com'],
	])('%s has host %s and domain %s', (url, host, domain) => {
		expect(hostOf(url)).toBe(host)
		expect(siteDomain(url)).toBe(domain)
	})

	it.each([
		'',
		null,
		'ftp://github.com',
		'not a url at all',
		'javascript:alert(1)',
	])('%s has no host', (url) => {
		expect(hostOf(url)).toBeNull()
		expect(directoryEntry(directory.totp, url)).toBeNull()
	})

	it('finds an entry by host and by registrable domain, with its documentation link', () => {
		expect(directoryEntry(directory.totp, 'https://github.com')).toEqual({
			domain: 'github.com',
			documentation: directory.totp['github.com'],
		})
		expect(
			directoryEntry(directory.passkeys, 'https://www.example.com/sign-in'),
		).toEqual({
			domain: 'example.com',
			documentation: 'https://example.com/help/passkeys',
		})
		expect(directoryEntry(directory.totp, 'https://example.com')).toBeNull()
	})

	it('drops a documentation link that is not https', () => {
		expect(
			directoryEntry({ 'a.org': 'http://a.org/2fa' }, 'https://a.org'),
		).toEqual({ domain: 'a.org', documentation: null })
		expect(directoryEntry({ 'a.org': '' }, 'a.org')).toEqual({
			domain: 'a.org',
			documentation: null,
		})
	})

	it("collects the vault's own authenticator and passkey items by domain", () => {
		const types = new Map([
			[1, 'password'],
			[2, 'totp'],
			[3, 'passkey'],
		])
		const facts = vaultSiteFacts(
			[
				{ typeId: 2, url: 'https://accounts.github.com' },
				{ typeId: 3, url: 'example.com' },
				{ typeId: 1, url: 'https://other.org' },
				{ typeId: 3, url: null },
			],
			types,
		)

		expect([...facts.seedDomains]).toEqual(['github.com'])
		expect([...facts.passkeyDomains]).toEqual(['example.com'])
	})
})

describe('analyse with the site directory', () => {
	it('lists a login without a seed for a site that offers one-time codes', async () => {
		const { flags, findings, summary } = await flagsOf(
			[login({ id: 'gh', url: 'https://github.com' })],
			directory,
		)

		expect(flags.gh).toContain('two-factor-available')
		expect(flags.gh).not.toContain('passkey-available')
		expect(findings[0].siteDocs).toEqual({
			twoFactor: directory.totp['github.com'],
			passkey: null,
		})
		expect(summary.twoFactorAvailableCount).toBe(1)
		expect(summary.passkeyAvailableCount).toBe(0)
	})

	it('does not list a login that already holds a seed', async () => {
		const { flags } = await flagsOf(
			[login({ id: 'gh', url: 'https://github.com', hasSeed: true })],
			directory,
		)

		expect(flags.gh ?? []).not.toContain('two-factor-available')
	})

	it('lists a login for a passkey site only without a passkey for it', async () => {
		const { flags } = await flagsOf(
			[
				login({ id: 'without', url: 'https://example.com' }),
				login({
					id: 'with',
					url: 'https://example.com/account',
					hasPasskey: true,
				}),
			],
			directory,
		)

		expect(flags.without).toContain('passkey-available')
		expect(flags.with ?? []).not.toContain('passkey-available')
	})

	it('lists nothing and adds no link while the directory is off', async () => {
		const { flags, findings, summary } = await flagsOf(
			[login({ id: 'gh', url: 'https://github.com' })],
			null,
		)

		expect(flags.gh ?? []).not.toContain('two-factor-available')
		expect(findings.every((f) => f.siteDocs === undefined)).toBe(true)
		expect(summary.twoFactorAvailableCount).toBe(0)
	})

	it('leaves the score alone', async () => {
		const rows = [
			login({ id: 'gh', url: 'https://github.com' }),
			login({ id: 'ex', url: 'https://example.com' }),
		]
		const on = await analyse(rows, { digest, siteDirectory: directory })
		const off = await analyse(rows, { digest, siteDirectory: null })

		expect(on.score).toBe(off.score)
		expect(vaultScore(on.summary)).toBe(on.score)
	})
})
