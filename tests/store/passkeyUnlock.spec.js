/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A second enrolled passkey unlocks the vault (keepiq#744).
 *
 * Each enrolment draws its own PRF salt. The authenticator decides which
 * passkey answers, so the unlock request must offer every credential its own
 * salt (evalByCredential); a single `eval` salt is the first passkey's, and
 * the second passkey then derives the wrong key.
 *
 * The authenticator is simulated: it answers with the SECOND credential and
 * returns, as its PRF output, the salt it was asked to evaluate for itself.
 *
 * @spec openspec/specs/passkey-vault-login/spec.md#requirement-passwordless-unlock-derives-the-unlock-key-client-side
 */

import axios from '@nextcloud/axios'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const derived = []
vi.mock('../../src/crypto/passkey.js', async (importOriginal) => {
	const original = await importOriginal()
	return {
		...original,
		deriveKekFromPrf: vi.fn(async (prfOutput, credentialId) => {
			derived.push({
				salt: Array.from(new Uint8Array(prfOutput)),
				credentialId,
			})
			return 'KEK'
		}),
		unwrapUnlockKey: vi.fn(async () => new Uint8Array([1, 2, 3])),
	}
})

import { usePasskeyStore } from '../../src/store/modules/passkey.js'
import { useSessionStore } from '../../src/store/modules/session.js'

const FIRST = {
	id: 'p1',
	credentialId: 'AQID',
	prfSalt: btoa('salt-one'),
	wrappedUnlockKey: 'W1',
}
const SECOND = {
	id: 'p2',
	credentialId: 'BAUG',
	prfSalt: btoa('salt-two'),
	wrappedUnlockKey: 'W2',
}

describe('passkey unlock with the second enrolled passkey (keepiq#744)', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		derived.length = 0
		vi.restoreAllMocks()
	})

	it('evaluates the PRF with the salt of the passkey that answered', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({
			data: { challenge: 'AAAA', credentials: [FIRST, SECOND] },
		})
		vi.spyOn(axios, 'post').mockResolvedValue({ data: {} })
		const unlock = vi
			.spyOn(useSessionStore(), 'unlockWithRawKey')
			.mockResolvedValue()

		let requested = null
		Object.defineProperty(globalThis.navigator, 'credentials', {
			configurable: true,
			value: {
				get: vi.fn(async (options) => {
					requested = options.publicKey.extensions.prf
					// The authenticator holds the SECOND passkey.
					const salt =
						requested.evalByCredential?.BAUG?.first
						?? requested.eval?.first
					return {
						rawId: Uint8Array.from([4, 5, 6]).buffer,
						getClientExtensionResults: () => ({
							prf: { results: { first: salt.buffer } },
						}),
					}
				}),
			},
		})

		await usePasskeyStore().unlockWithPasskey()

		expect(Object.keys(requested.evalByCredential).sort()).toEqual([
			'AQID',
			'BAUG',
		])
		expect(derived).toHaveLength(1)
		expect(derived[0].credentialId).toBe('BAUG')
		expect(String.fromCharCode(...derived[0].salt)).toBe('salt-two')
		expect(unlock).toHaveBeenCalledTimes(1)
	})

	/**
	 * An authenticator that answers with the given raw id and PRF output.
	 *
	 * @param {Array<number>} rawId The credential that answered.
	 * @param {ArrayBuffer|undefined} prf Its PRF output.
	 * @return {object} The get() spy.
	 */
	function authenticator(rawId, prf) {
		const get = vi.fn(async () => ({
			rawId: Uint8Array.from(rawId).buffer,
			getClientExtensionResults: () => ({ prf: { results: { first: prf } } }),
		}))
		Object.defineProperty(globalThis.navigator, 'credentials', {
			configurable: true,
			value: { get },
		})
		return get
	}

	it('asks the authenticator for every passkey, unlocks with the first one when the answer names none, and stamps it used', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({
			data: { challenge: 'a+b/', credentials: [FIRST, SECOND] },
		})
		const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: {} })
		vi.spyOn(useSessionStore(), 'unlockWithRawKey').mockResolvedValue()
		const get = authenticator([9, 9, 9], new Uint8Array([7]).buffer)

		await expect(usePasskeyStore().unlockWithPasskey()).resolves.toBe(true)

		const { publicKey } = get.mock.calls[0][0]
		expect(Array.from(new Uint8Array(publicKey.challenge))).toEqual([107, 230, 255])
		expect(publicKey.allowCredentials.map((c) => [c.type, Array.from(new Uint8Array(c.id))])).toEqual([
			['public-key', [1, 2, 3]],
			['public-key', [4, 5, 6]],
		])
		expect(publicKey.userVerification).toBe('preferred')
		expect(typeof publicKey.rpId).toBe('string')
		expect(derived[0].credentialId).toBe('AQID')
		expect(post).toHaveBeenCalledWith('/apps/keepiq/api/v1/passkeys/p1/used')
	})

	it('refuses without an enrolled passkey and without a PRF result', async () => {
		const get = vi.spyOn(axios, 'get').mockResolvedValue({
			data: { challenge: 'AAAA', credentials: [] },
		})
		const store = usePasskeyStore()
		await expect(store.unlockWithPasskey()).rejects.toThrow('No passkey enrolled for unlock')
		get.mockResolvedValue({ data: { challenge: 'AAAA', credentials: [FIRST] } })
		const unlock = vi.spyOn(useSessionStore(), 'unlockWithRawKey')
		authenticator([1, 2, 3], undefined)
		await expect(store.unlockWithPasskey()).rejects.toThrow('Passkey unlock failed — no PRF result')
		expect(unlock).not.toHaveBeenCalled()
	})
})
