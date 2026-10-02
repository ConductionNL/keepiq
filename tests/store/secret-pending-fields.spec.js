/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A filled secret request no longer wipes the owner's other extra fields
 * (keepiq#750). The server keeps the filled blob pending; the owner's store
 * merges it into the stored blob on open and writes the merge back, telling
 * the server how many pending blobs it merged.
 *
 * rsaEncrypt/rsaDecrypt are mocked as ENC(<plain>), like the other secret
 * store specs; the real crypto is covered in tests/vitest.
 *
 * @spec openspec/specs/secret-requests/spec.md#requirement-requestable-fields
 */

import axios from '@nextcloud/axios'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../../src/crypto/index.js', () => ({
	importPublicKey: vi.fn(async () => 'PUBKEY_HANDLE'),
	rsaEncrypt: vi.fn(async (value) => `ENC(${value})`),
	rsaDecrypt: vi.fn(async (value) => {
		const match = /^ENC\((.*)\)$/s.exec(String(value))
		if (!match) {
			throw new Error('cannot decrypt')
		}
		return match[1]
	}),
}))

import { useSecretStore } from '../../src/store/modules/secret.js'
import { useSessionStore } from '../../src/store/modules/session.js'

/**
 * A secret as the server serves it after a fill on a secret with fields.
 *
 * @param {Array<string>} pending The pending ciphertexts.
 * @return {object}
 */
function served(pending) {
	return {
		id: 's1',
		name: 'Supplier API',
		key: 'ENC(pw)',
		additionalFields: 'ENC({"A":"a","B":"b"})',
		pendingAdditionalFields: pending,
	}
}

describe('secret store: pending request-filled extra fields (keepiq#750)', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
		const session = useSessionStore()
		session.certificate = 'PEM'
		session.cryptoKey = { fake: 'unlocked' }
	})

	it('shows the owner fields and the filled field together', async () => {
		const decrypted = await useSecretStore().decryptSecret(
			served(['ENC({"C":"c"})']),
		)

		expect(decrypted.additionalFields).toEqual({ A: 'a', B: 'b', C: 'c' })
		expect(decrypted.mergedPending).toBe(1)
	})

	it('lets a later fill win for a member both fills name', async () => {
		const decrypted = await useSecretStore().decryptSecret(
			served(['ENC({"C":"old"})', 'ENC({"C":"new"})']),
		)

		expect(decrypted.additionalFields.C).toBe('new')
		expect(decrypted.mergedPending).toBe(2)
	})

	it('does not report a merge it could not complete', async () => {
		const decrypted = await useSecretStore().decryptSecret(
			served(['ENC({"C":"c"})', 'UNDECRYPTABLE']),
		)

		expect(decrypted.additionalFields).toEqual({ A: 'a', B: 'b', C: 'c' })
		expect(decrypted.mergedPending).toBe(0)
	})

	it('writes the merged blob back on open and says how many it merged', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({
			data: served(['ENC({"C":"c"})']),
		})
		const put = vi.spyOn(axios, 'put').mockResolvedValue({ data: { id: 's1' } })

		const store = useSecretStore()
		const opened = await store.fetchSecret('s1')

		expect(put).toHaveBeenCalledTimes(1)
		const body = put.mock.calls[0][1]
		expect(body.additionalFields).toBe('ENC({"A":"a","B":"b","C":"c"})')
		expect(body.mergedPending).toBe(1)
		expect(opened.additionalFields).toEqual({ A: 'a', B: 'b', C: 'c' })
		expect(opened.mergedPending).toBe(0)
	})

	it('writes nothing back when nothing is pending', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: served([]) })
		const put = vi.spyOn(axios, 'put')

		await useSecretStore().fetchSecret('s1')

		expect(put).not.toHaveBeenCalled()
	})
})
