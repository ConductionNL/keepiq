/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The extension's rules for a use-only copy (sharing-use-only-and-expiring-
 * shares task 4.2): its own site only, a real password field only, no save
 * prompt, and a use report per fill.
 *
 * @spec openspec/changes/sharing-use-only-and-expiring-shares/specs/use-only-shares/spec.md#requirement-keepiqs-clients-never-reveal-a-use-only-value
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import { reportUseOnlyFill } from '../../browser-extension/src/lib/api.js'
import {
	allowedOnHost,
	blocksSavePrompt,
	filterForHost,
	useOnlyPasswordTarget,
} from '../../browser-extension/src/lib/useOnly.js'

const portal = {
	id: 'copy',
	name: 'Supplier portal',
	url: 'https://portal.supplier.example',
	useOnly: true,
}

describe('use-only in the extension', () => {
	afterEach(() => {
		vi.unstubAllGlobals()
	})

	it('fills a use-only copy on its own site', () => {
		expect(allowedOnHost(portal, 'portal.supplier.example')).toBe(true)
		expect(allowedOnHost(portal, 'login.supplier.example')).toBe(true)
	})

	it('refuses another site, a look-alike, and a copy without a URL', () => {
		expect(allowedOnHost(portal, 'attacker.example.net')).toBe(false)
		expect(allowedOnHost(portal, 'supplier.example.attacker.net')).toBe(false)
		expect(
			allowedOnHost({ ...portal, url: '' }, 'portal.supplier.example'),
		).toBe(false)
	})

	it('leaves normal secrets to the normal rules', () => {
		const normal = { id: 'n', url: 'https://other.example', useOnly: false }
		expect(filterForHost([portal, normal], 'attacker.example.net')).toEqual([
			normal,
		])
	})

	it('offers no save or update for a use-only login', () => {
		expect(blocksSavePrompt([portal], 'portal.supplier.example')).toBe(true)
		expect(
			blocksSavePrompt(
				[{ ...portal, useOnly: false }],
				'portal.supplier.example',
			),
		).toBe(false)
	})

	it('fills only a real password field', () => {
		expect(useOnlyPasswordTarget({ type: 'password' })).not.toBeNull()
		expect(useOnlyPasswordTarget({ type: 'text' })).toBeNull()
		expect(useOnlyPasswordTarget(null)).toBeNull()
	})

	it('reports a fill to the use-only route with the id only', async () => {
		const fetchMock = vi.fn().mockResolvedValue({
			ok: true,
			status: 200,
			json: async () => ({ status: 'recorded' }),
		})
		vi.stubGlobal('fetch', fetchMock)
		await reportUseOnlyFill(
			{ url: 'https://cloud.example', user: 'bob', appPassword: 'x' },
			'copy',
		)
		const [url, init] = fetchMock.mock.calls[0]
		expect(url).toBe(
			'https://cloud.example/index.php/apps/keepiq/api/v1/secrets/copy/used',
		)
		expect(init.method).toBe('POST')
		expect(init.body).toBeUndefined()
	})
})
