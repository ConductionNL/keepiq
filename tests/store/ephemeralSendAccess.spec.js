/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Pins anonymous access to an ephemeral send, path by path, before it is
 * split into named steps: the content key from the link fragment or from the
 * password, the decrypt, the view confirmed, and a failed password reported
 * with the attempts left while any other failure is passed on.
 *
 * @spec openspec/specs/ephemeral-send/spec.md#requirement-anonymous-recipient-access-with-no-account
 */

import axios from '@nextcloud/axios'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../../src/crypto/argon2.js', () => ({
	deriveAesKeyArgon2id: vi.fn(async (password, salt) => ({ kek: password, salt: Array.from(salt) })),
}))
vi.mock('../../src/send/sendCrypto.js', async (importOriginal) => ({
	...(await importOriginal()),
	aesDecrypt: vi.fn(async (key, ciphertext) => {
		if (ciphertext === 'BAD') {
			throw new Error('cannot decrypt')
		}
		return key.kek !== undefined
			? new Uint8Array([1, 2, 3, 4])
			: new TextEncoder().encode(`plain:${ciphertext}`)
	}),
}))

const { useEphemeralSendStore } = await import('../../src/store/modules/ephemeralSend.js')

beforeEach(() => {
	setActivePinia(createPinia())
	vi.restoreAllMocks()
	vi.spyOn(crypto.subtle, 'importKey').mockImplementation(async (format, raw) => ({
		imported: Array.from(new Uint8Array(raw)),
	}))
})

/**
 * Answer the three public send endpoints.
 *
 * @param {object} access The access answer.
 * @param {object} [extra] The confirm and failure answers.
 * @return {object} The post spy.
 */
function endpoints(access, extra = {}) {
	return vi.spyOn(axios, 'post').mockImplementation(async (url) => {
		if (url.endsWith('/access')) {
			return { data: access }
		}
		if (url.endsWith('/confirm')) {
			return { data: extra.confirm ?? {} }
		}
		return { data: extra.failure ?? {} }
	})
}

describe('accessSend', () => {
	it('opens a fragment-mode send with the key in the link and confirms the view', async () => {
		const post = endpoints(
			{ hasPassword: false, encryptedPayload: 'CT', payloadType: 'text' },
			{ confirm: { burned: true } },
		)
		const result = await useEphemeralSendStore().accessSend('a/b', 'AQID', '')
		expect(post.mock.calls.map(([url]) => url)).toEqual([
			'/apps/keepiq/api/v1/public/sends/a%2Fb/access',
			'/apps/keepiq/api/v1/public/sends/a%2Fb/confirm',
		])
		expect(crypto.subtle.importKey.mock.calls[0].slice(2)).toEqual([
			{ name: 'AES-GCM' },
			false,
			['decrypt'],
		])
		expect(result).toEqual({ payload: 'plain:CT', payloadType: 'text', burned: true })
	})

	it('unwraps the content key with the password in password mode', async () => {
		endpoints({
			hasPassword: true,
			argon2idSalt: 'AQI=',
			wrappedKey: 'WRAPPED',
			encryptedPayload: 'CT',
			payloadType: 'file',
		})
		const result = await useEphemeralSendStore().accessSend('tok', '', 'pw')
		expect(crypto.subtle.importKey.mock.calls[0][1]).toEqual(new Uint8Array([1, 2, 3, 4]))
		expect(result).toEqual({ payload: 'plain:CT', payloadType: 'file', burned: false })
	})

	it('reports a failed password with the attempts left, without confirming', async () => {
		const post = endpoints(
			{ hasPassword: true, argon2idSalt: 'AQI=', wrappedKey: 'W', encryptedPayload: 'BAD' },
			{ failure: { attemptsLeft: 3, burned: false } },
		)
		const error = await useEphemeralSendStore().accessSend('tok', '', 'pw').catch((e) => e)
		expect([error.message, error.attemptsLeft, error.burned]).toEqual(['wrong-password', 3, false])
		expect(post.mock.calls.map(([url]) => url.split('/').pop())).toEqual(['access', 'failure'])
		post.mockImplementation(async (url) => ({ data: url.endsWith('/access') ? { hasPassword: true, argon2idSalt: '', encryptedPayload: 'BAD' } : { burned: true } }))
		const burned = await useEphemeralSendStore().accessSend('tok', '', 'pw').catch((e) => e)
		expect([burned.attemptsLeft, burned.burned]).toEqual([0, true])
	})

	it('passes any failure of a fragment-mode send on as it is', async () => {
		const post = endpoints({ hasPassword: false, encryptedPayload: 'BAD' })
		await expect(useEphemeralSendStore().accessSend('tok', 'AQID', '')).rejects.toThrow('cannot decrypt')
		expect(post).toHaveBeenCalledTimes(1)
	})
})
