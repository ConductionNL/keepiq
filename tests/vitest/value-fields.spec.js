/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The release gate of a login's `totp` and `passkeys` (keepiq#1326), read from
 * the `appVersion` initial state as the browser extension reads a server version:
 * a prerelease counts as its release, an `-unstable` build always passes, and a
 * missing or unreadable version fails open.
 *
 * @spec openspec/specs/secrets/spec.md#requirement-create-secret
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

const loadState = vi.fn()
vi.mock('@nextcloud/initial-state', () => ({
	loadState: (...args) => loadState(...args),
}))

const { TOTP_PASSKEYS_FIELDS_SINCE, valueFieldsSupported, versionStoresValueFields } =
	await import('../../src/utils/valueFields.js')

describe('versionStoresValueFields', () => {
	it.each([
		[TOTP_PASSKEYS_FIELDS_SINCE, true],
		['0.3.13', true],
		['0.4.0', true],
		['1.0.0', true],
		['0.3.12-beta.20261008125823', true],
		['0.3.11', false],
		['0.2.99', false],
		['0.3.11-beta.1', false],
	])('%s stores them: %s', (version, expected) => {
		expect(versionStoresValueFields(version)).toBe(expected)
	})

	it('lets an unstable build through, as a development checkout reports an older version', () => {
		expect(versionStoresValueFields('0.3.9-unstable.20261019140000')).toBe(true)
	})

	it.each([[''], [null], [undefined], ['not-a-version'], ['0.3']])(
		'fails open for %j',
		(version) => {
			expect(versionStoresValueFields(version)).toBe(true)
		},
	)
})

describe('valueFieldsSupported', () => {
	beforeEach(() => {
		loadState.mockReset()
	})

	it('reads the server version from the appVersion initial state', () => {
		loadState.mockReturnValue('0.3.11')

		expect(valueFieldsSupported()).toBe(false)
		expect(loadState).toHaveBeenCalledWith('keepiq', 'appVersion', '')
	})

	it('passes a server that stores them', () => {
		loadState.mockReturnValue('0.3.12')

		expect(valueFieldsSupported()).toBe(true)
	})

	it('fails open when the initial state cannot be read', () => {
		loadState.mockImplementation(() => {
			throw new Error('Could not find initial state appVersion of keepiq')
		})

		expect(valueFieldsSupported()).toBe(true)
	})
})
