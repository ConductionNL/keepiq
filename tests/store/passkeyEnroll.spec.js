/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Enrolling a passkey on an authenticator that already unlocks the vault.
 *
 * Every enrolment uses the same user handle, so a platform authenticator
 * (Touch ID, Windows Hello) that already holds a Keepiq passkey replaces it.
 * Seen live on 2 Oct 2026: the earlier row stayed listed as active but could
 * never unlock again. Enrolment now excludes the active passkeys, and the
 * authenticator's refusal becomes a clear message instead.
 *
 * @spec openspec/specs/passkey-vault-login/spec.md#requirement-passkey-enrollment-requires-an-unlocked-vault
 */

import axios from '@nextcloud/axios'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { usePasskeyStore } from '../../src/store/modules/passkey.js'
import { useSessionStore } from '../../src/store/modules/session.js'

const ACTIVE = { credentialId: 'AQID', prfSalt: btoa('salt'), wrappedUnlockKey: 'W' }

/**
 * Answer the two GETs enrol makes: the challenge and the login options.
 *
 * @param {Array<object>} credentials The active passkeys the server lists.
 */
function mockServer(credentials) {
	vi.spyOn(axios, 'get').mockImplementation(async (url) => {
		if (url.includes('login-options')) {
			return { data: { challenge: 'AAAA', credentials } }
		}
		return { data: { challenge: 'AAAA' } }
	})
}

describe('passkey enrolment on an authenticator that already unlocks the vault', () => {
	let create

	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
		const session = useSessionStore()
		session.cryptoKey = {}
		session.encryptedPrivateKey = 'ENVELOPE'
		create = vi.fn()
		vi.stubGlobal('navigator', { credentials: { create, get: vi.fn() } })
	})

	afterEach(() => {
		vi.unstubAllGlobals()
	})

	it('excludes the passkeys that unlock the vault now', async () => {
		mockServer([ACTIVE])
		create.mockRejectedValue(new Error('stop after create'))

		await expect(usePasskeyStore().enroll('pw', 'Laptop')).rejects.toThrow(
			'stop after create',
		)

		const options = create.mock.calls[0][0].publicKey
		expect(options.excludeCredentials).toHaveLength(1)
		expect(options.excludeCredentials[0].type).toBe('public-key')
		expect(Array.from(new Uint8Array(options.excludeCredentials[0].id))).toEqual(
			[1, 2, 3],
		)
	})

	it('excludes nothing when no passkey is active', async () => {
		mockServer([])
		create.mockRejectedValue(new Error('stop after create'))

		await expect(usePasskeyStore().enroll('pw', 'Laptop')).rejects.toThrow()

		expect(create.mock.calls[0][0].publicKey.excludeCredentials).toEqual([])
	})

	it('says why when the authenticator refuses because it is excluded', async () => {
		mockServer([ACTIVE])
		create.mockRejectedValue(
			new DOMException('credential excluded', 'InvalidStateError'),
		)

		await expect(usePasskeyStore().enroll('pw', 'Laptop')).rejects.toThrow(
			'This authenticator already unlocks your vault',
		)
	})
})
