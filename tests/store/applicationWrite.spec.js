/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Pins the payload of a secret written for an application before the write
 * is split into named steps: the defaults, the key always encrypted (an empty
 * one too), the login only when set, and the additional fields encrypted as
 * given or as JSON.
 *
 * @spec openspec/specs/application-mgmt/spec.md#requirement-attribute-secrets-to-application
 */

import axios from '@nextcloud/axios'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useApplicationStore } from '../../src/store/modules/application.js'

vi.mock('../../src/crypto/index.js', () => ({
	importPublicKey: vi.fn(async (pem) => `KEY(${pem})`),
	rsaEncrypt: vi.fn(async (value, key) => `ENC(${value}|${key})`),
}))

describe('writeSecretForApplication payloads', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
	})

	/**
	 * Write for app-1 and return what was posted.
	 *
	 * @param {object} data The plaintext fields.
	 * @return {Promise<Array>} The post call and the result.
	 */
	async function written(data) {
		const store = useApplicationStore()
		vi.spyOn(store, 'fetchCertificate').mockResolvedValue('PEM')
		const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: { id: 's' } })
		const result = await store.writeSecretForApplication('app-1', data)
		return [post.mock.calls.at(-1), result]
	}

	it('fills the defaults and encrypts an empty key', async () => {
		expect(await written({ name: 'X' })).toEqual([
			[
				'/apps/keepiq/api/v1/secrets',
				{
					name: 'X',
					url: null,
					typeId: null,
					folderId: null,
					key: 'ENC(|KEY(PEM))',
					ownerType: 'application',
					ownerId: 'app-1',
				},
			],
			{ id: 's' },
		])
	})

	it('encrypts a login and the additional fields, as text or as JSON', async () => {
		const [[, object]] = await written({
			name: 'X',
			url: 'u',
			typeId: 't',
			folderId: 'f',
			key: 42,
			login: 7,
			additionalFields: { pin: '1' },
		})
		expect(object).toMatchObject({
			url: 'u',
			typeId: 't',
			folderId: 'f',
			key: 'ENC(42|KEY(PEM))',
			login: 'ENC(7|KEY(PEM))',
			additionalFields: 'ENC({"pin":"1"}|KEY(PEM))',
		})
		const [[, text]] = await written({ name: 'X', additionalFields: '{"a":1}', login: '' })
		expect(text.additionalFields).toBe('ENC({"a":1}|KEY(PEM))')
		expect('login' in text).toBe(false)
		const [[, none]] = await written({ name: 'X', login: null, additionalFields: '' })
		expect('login' in none).toBe(false)
		expect('additionalFields' in none).toBe(false)
	})
})
