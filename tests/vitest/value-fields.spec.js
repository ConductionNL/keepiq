/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What the web app reads of a login's `totp` and `passkeys` (keepiq#1326): the
 * server declares them in the `loginValueFields` initial state, and the page
 * keeps no release number of its own.
 *
 * @spec openspec/specs/secrets/spec.md#requirement-create-secret
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

const loadState = vi.fn()
vi.mock('@nextcloud/initial-state', () => ({
	loadState: (...args) => loadState(...args),
}))

const { valueFieldsSince, valueFieldsSupported } =
	await import('../../src/utils/valueFields.js')

beforeEach(() => {
	loadState.mockReset()
})

describe('valueFieldsSupported', () => {
	it('reads the declaration from the loginValueFields initial state', () => {
		loadState.mockReturnValue({ since: '0.3.12' })

		expect(valueFieldsSupported()).toBe(true)
		expect(loadState).toHaveBeenCalledWith('keepiq', 'loginValueFields', null)
	})

	it.each([[null], [undefined], [''], ['0.3.12'], [true]])(
		'does not count %j as a declaration',
		(state) => {
			loadState.mockReturnValue(state)

			expect(valueFieldsSupported()).toBe(false)
		},
	)

	it('is false when the initial state cannot be read, as on a server that declares nothing', () => {
		loadState.mockImplementation(() => {
			throw new Error(
				'Could not find initial state loginValueFields of keepiq',
			)
		})

		expect(valueFieldsSupported()).toBe(false)
	})
})

describe('valueFieldsSince', () => {
	it('is the release the server says first stored them', () => {
		loadState.mockReturnValue({ since: '0.3.12' })

		expect(valueFieldsSince()).toBe('0.3.12')
	})

	it('is empty when the server declares none', () => {
		loadState.mockReturnValue(null)

		expect(valueFieldsSince()).toBe('')
	})
})
