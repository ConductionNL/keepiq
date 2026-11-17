/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The registrable-domain rule the web app shares with the browser extension.
 *
 * @spec openspec/changes/archive/2026-11-17-health-site-security-checks/tasks.md#task-3.1
 */

import { getDomain } from 'tldts'
import { describe, expect, it, vi } from 'vitest'
import { registrableDomainOf } from '../../src/shared/registrableDomain.js'

describe('registrableDomainOf', () => {
	it.each([
		['login.example.co.uk', 'example.co.uk'],
		['a.b.example.com', 'example.com'],
		['github.com', 'github.com'],
		['alice.github.io', 'alice.github.io'],
		['192.168.1.10', '192.168.1.10'],
		['localhost', 'localhost'],
		['intranet', 'intranet'],
	])('%s → %s', (host, domain) => {
		expect(registrableDomainOf(host, getDomain)).toBe(domain)
	})

	it.each([null, undefined, ''])('has none without a host (%s)', (host) => {
		const lookup = vi.fn()
		expect(registrableDomainOf(host, lookup)).toBeNull()
		expect(lookup).not.toHaveBeenCalled()
	})

	it('asks the lookup to count private suffixes', () => {
		const lookup = vi.fn().mockReturnValue('example.com')
		registrableDomainOf('www.example.com', lookup)
		expect(lookup).toHaveBeenCalledWith('www.example.com', { allowPrivateDomains: true })
	})
})
