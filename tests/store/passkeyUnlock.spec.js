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
})
