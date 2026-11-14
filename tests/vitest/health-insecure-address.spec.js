/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The plain-http rule of the password health report and its place in the
 * engine: http is flagged, https, localhost, loopback and private-network
 * hosts are not, the finding carries the address for the Change to https
 * action, and the flag does not weigh on the vault score.
 *
 * @spec openspec/changes/health-site-security-checks/specs/health-site-checks/spec.md#requirement-flag-logins-saved-with-an-unencrypted-address
 */

import { describe, expect, it } from 'vitest'
import { analyse } from '../../src/health/engine.js'
import {
	httpsAddress,
	isInsecureAddress,
	isLocalHost,
} from '../../src/health/insecureAddress.js'

const digest = async (value) => `d:${value}`

describe('isInsecureAddress', () => {
	it('flags a plain http address on a public host', () => {
		expect(isInsecureAddress('http://intranet.example.org')).toBe(true)
		expect(isInsecureAddress('HTTP://Shop.Example.com/login?next=/')).toBe(true)
		expect(isInsecureAddress('  http://example.com  ')).toBe(true)
		expect(isInsecureAddress('http://8.8.8.8/')).toBe(true)
	})

	it('does not flag https, other schemes or no address', () => {
		for (const address of ['https://example.com', 'ftp://example.com', 'example.com', '', null, undefined, 42]) {
			expect(isInsecureAddress(address)).toBe(false)
		}
	})

	it('does not flag localhost, loopback or private-network hosts', () => {
		for (const address of [
			'http://localhost:8080',
			'http://app.localhost/',
			'http://127.0.0.1',
			'http://10.1.2.3/admin',
			'http://172.16.0.1',
			'http://172.31.255.255',
			'http://192.168.1.1',
			'http://169.254.10.10',
			'http://[::1]:3000/',
			'http://[fd12:3456::1]/',
			'http://[fe80::1]/',
		]) {
			expect(isInsecureAddress(address)).toBe(false)
		}
	})

	it('flags the public hosts next to the private ranges', () => {
		for (const address of ['http://172.15.0.1', 'http://172.32.0.1', 'http://192.169.0.1', 'http://11.0.0.1', 'http://[2001:db8::1]/']) {
			expect(isInsecureAddress(address)).toBe(true)
		}
	})

	it('does not flag an http address that does not parse', () => {
		expect(isInsecureAddress('http://')).toBe(false)
		expect(isInsecureAddress('http://exa mple.com')).toBe(false)
	})

	it('treats a host named like a private address as public', () => {
		expect(isLocalHost('192.168.example.com')).toBe(false)
		expect(isLocalHost('localhost.example.com')).toBe(false)
	})
})

describe('httpsAddress', () => {
	it('changes only the scheme', () => {
		expect(httpsAddress('http://intranet.example.org')).toBe('https://intranet.example.org')
		expect(httpsAddress('HTTP://example.com:8080/a?b=http://c')).toBe('https://example.com:8080/a?b=http://c')
	})
})

describe('engine: insecure-address', () => {
	it('flags a login saved with http and carries its address', async () => {
		const { findings, summary } = await analyse(
			[
				{ id: 'h', name: 'Intranet', url: 'http://intranet.example.org', value: 'c0rrect-H0rse-battery-staple-91!' },
				{ id: 's', name: 'Bank', url: 'https://bank.example.org', value: 'An0ther-L0ng-unique-value-55?' },
				{ id: 'l', name: 'Router', url: 'http://192.168.1.1', value: 'Y3t-an0ther-unique-value-31#' },
			],
			{ digest, stalenessThreshold: 'never' },
		)

		const flagged = findings.filter((f) => f.flags.includes('insecure-address'))
		expect(flagged.map((f) => f.id)).toEqual(['h'])
		expect(flagged[0].url).toBe('http://intranet.example.org')
		expect(summary.insecureAddressCount).toBe(1)
	})

	it('lists a login whose only finding is its address', async () => {
		const { findings } = await analyse(
			[{ id: 'h', name: 'Intranet', url: 'http://intranet.example.org', value: '' }],
			{ digest, stalenessThreshold: 'never' },
		)

		expect(findings).toEqual([
			expect.objectContaining({ id: 'h', flags: ['insecure-address'], score: null }),
		])
	})

	it('does not lower the vault score', async () => {
		const value = 'c0rrect-H0rse-battery-staple-91!'
		const http = await analyse([{ id: 'h', name: 'A', url: 'http://example.com', value }], { digest, stalenessThreshold: 'never' })
		const https = await analyse([{ id: 'h', name: 'A', url: 'https://example.com', value }], { digest, stalenessThreshold: 'never' })

		expect(http.score).toBe(https.score)
	})
})
