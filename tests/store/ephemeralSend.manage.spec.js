/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Creating, listing and revoking ephemeral sends: what the create request
 * carries in fragment mode and in password mode (checked by opening the
 * ciphertext with the key the recipient would hold), the link it returns,
 * and the list kept in step with the server.
 *
 * @spec openspec/specs/ephemeral-send/spec.md#requirement-create-a-standalone-ephemeral-send
 * @spec openspec/specs/ephemeral-send/spec.md#requirement-manage-and-revoke-sends
 */

import axios from '@nextcloud/axios'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { deriveAesKeyArgon2id } from '../../src/crypto/argon2.js'
import { aesDecrypt, fromBase64, fromBase64Url } from '../../src/send/sendCrypto.js'
import { useEphemeralSendStore } from '../../src/store/modules/ephemeralSend.js'

/**
 * Open a sealed payload with a raw content key.
 *
 * @param {Uint8Array} rawKey The content key.
 * @param {string} encryptedPayload The ciphertext as the server stores it.
 * @return {Promise<string>} The plaintext.
 */
async function openWith(rawKey, encryptedPayload) {
	const key = await crypto.subtle.importKey(
		'raw',
		rawKey,
		{ name: 'AES-GCM' },
		false,
		['decrypt'],
	)
	return new TextDecoder().decode(await aesDecrypt(key, encryptedPayload))
}

let post
let get

beforeEach(() => {
	setActivePinia(createPinia())
	post = vi.spyOn(axios, 'post').mockResolvedValue({ data: { token: 'tok/1' } })
	get = vi
		.spyOn(axios, 'get')
		.mockResolvedValue({ data: [{ id: 's1' }, { id: 's2' }] })
})

afterEach(() => {
	vi.restoreAllMocks()
})

describe('createSend without a password', () => {
	it('posts only ciphertext and puts the key in the link fragment', async () => {
		const store = useEphemeralSendStore()
		const link = await store.createSend({
			payload: 'the secret',
			payloadType: 'text',
			maxViews: 2,
			ttlSeconds: 0,
			password: '',
		})

		expect(post).toHaveBeenCalledTimes(1)
		const [url, body] = post.mock.calls[0]
		expect(url).toBe('/apps/keepiq/api/v1/sends')
		expect(body).toEqual({
			encryptedPayload: expect.any(String),
			payloadType: 'text',
			maxViews: 2,
			ttlSeconds: 0,
			hasPassword: false,
		})
		expect(body.encryptedPayload).not.toContain('the secret')

		const [base, fragment] = link.split('#k=')
		expect(base).toBe(
			`${window.location.origin}/apps/keepiq/public/send/tok%2F1`,
		)
		expect(await openWith(fromBase64Url(fragment), body.encryptedPayload)).toBe(
			'the secret',
		)
	})

	it('sends a missing ttl as 0 and refreshes the list', async () => {
		const store = useEphemeralSendStore()
		await store.createSend({
			payload: 'x',
			payloadType: 'credential',
			maxViews: 1,
			ttlSeconds: undefined,
			password: '',
		})

		expect(post.mock.calls[0][1].ttlSeconds).toBe(0)
		expect(get).toHaveBeenCalledWith('/apps/keepiq/api/v1/sends')
		expect(store.sends).toEqual([{ id: 's1' }, { id: 's2' }])
	})
})

describe('createSend with a password', () => {
	it('wraps the key under the password and leaves it out of the link', async () => {
		const store = useEphemeralSendStore()
		const link = await store.createSend({
			payload: 'behind a password',
			payloadType: 'text',
			maxViews: 1,
			ttlSeconds: 3600,
			password: 'open sesame',
		})

		const body = post.mock.calls[0][1]
		expect(body.hasPassword).toBe(true)
		expect(body.ttlSeconds).toBe(3600)
		expect(fromBase64(body.argon2idSalt)).toHaveLength(16)
		expect(link).toBe(
			`${window.location.origin}/apps/keepiq/public/send/tok%2F1`,
		)
		expect(link).not.toContain('#')

		const kek = await deriveAesKeyArgon2id(
			'open sesame',
			fromBase64(body.argon2idSalt),
		)
		const rawKey = await aesDecrypt(kek, body.wrappedKey)
		expect(await openWith(rawKey, body.encryptedPayload)).toBe(
			'behind a password',
		)

		const wrongKek = await deriveAesKeyArgon2id(
			'wrong',
			fromBase64(body.argon2idSalt),
		)
		await expect(aesDecrypt(wrongKek, body.wrappedKey)).rejects.toThrow()
	})
})

describe('fetchSends', () => {
	it('keeps an empty answer as an empty list and clears loading', async () => {
		get.mockResolvedValueOnce({ data: null })
		const store = useEphemeralSendStore()
		store.sends = [{ id: 'old' }]

		await store.fetchSends()

		expect(store.sends).toEqual([])
		expect(store.loading).toBe(false)
	})

	it('clears loading when the request fails and passes the error on', async () => {
		get.mockRejectedValueOnce(new Error('offline'))
		const store = useEphemeralSendStore()

		await expect(store.fetchSends()).rejects.toThrow('offline')
		expect(store.loading).toBe(false)
	})
})

describe('revoke', () => {
	it('deletes the send and drops only it from the list', async () => {
		const del = vi.spyOn(axios, 'delete').mockResolvedValue({ data: {} })
		const store = useEphemeralSendStore()
		store.sends = [{ id: 'a' }, { id: 'b' }, { id: 'c' }]

		await store.revoke('b')

		expect(del).toHaveBeenCalledWith('/apps/keepiq/api/v1/sends/b')
		expect(store.sends).toEqual([{ id: 'a' }, { id: 'c' }])
	})

	it('keeps the list when the server refuses', async () => {
		vi.spyOn(axios, 'delete').mockRejectedValue(new Error('403'))
		const store = useEphemeralSendStore()
		store.sends = [{ id: 'a' }]

		await expect(store.revoke('a')).rejects.toThrow('403')
		expect(store.sends).toEqual([{ id: 'a' }])
	})
})
