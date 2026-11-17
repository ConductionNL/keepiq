/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The public (recipient) side of `useLinkShareStore`: fetching a link share
 * by token, confirming the decryption, and opening the snapshot with the
 * link password.
 *
 * @spec openspec/changes/implement-link-sharing/tasks.md#task-8.1
 */

import axios from '@nextcloud/axios'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { encryptSnapshot } from '../../src/crypto/index.js'
import { useLinkShareStore } from '../../src/store/modules/linkShare.js'

describe('useLinkShareStore public access', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
	})

	it('fetchPublicLinkShare reads the share by its encoded token', async () => {
		const get = vi.spyOn(axios, 'get').mockResolvedValue({
			data: { encryptedSecretSnapshot: 'blob', argon2idSalt: 'salt' },
		})
		const store = useLinkShareStore()

		const share = await store.fetchPublicLinkShare('a/b c')

		expect(get.mock.calls[0][0]).toMatch(/\/apps\/keepiq\/api\/v1\/public\/link-shares\/a%2Fb%20c$/)
		expect(share).toEqual({ encryptedSecretSnapshot: 'blob', argon2idSalt: 'salt' })
	})

	it('fetchPublicLinkShare lets a not-found answer through to the caller', async () => {
		vi.spyOn(axios, 'get').mockRejectedValue({ response: { status: 404 } })
		const store = useLinkShareStore()

		await expect(store.fetchPublicLinkShare('gone')).rejects.toEqual({ response: { status: 404 } })
	})

	it('confirmPublicLinkShare posts an empty body to the confirm URL and returns the usage', async () => {
		const post = vi.spyOn(axios, 'post').mockResolvedValue({
			data: { usageCount: 1, usageLimit: 3, remaining: 2 },
		})
		const store = useLinkShareStore()

		const usage = await store.confirmPublicLinkShare('tok?1')

		expect(post.mock.calls[0][0]).toMatch(/\/apps\/keepiq\/api\/v1\/public\/link-shares\/tok%3F1\/confirm$/)
		expect(post.mock.calls[0][1]).toEqual({})
		expect(usage).toEqual({ usageCount: 1, usageLimit: 3, remaining: 2 })
	})

	it('decryptPublicSnapshot opens the blob with the right password', async () => {
		const snapshot = { title: 'Router', fields: { password: 'p4ss' } }
		const { blob, salt } = await encryptSnapshot(JSON.stringify(snapshot), 'correct horse')
		const store = useLinkShareStore()

		const opened = await store.decryptPublicSnapshot(
			{ encryptedSecretSnapshot: blob, argon2idSalt: salt },
			'correct horse',
		)

		expect(opened).toEqual(snapshot)
	})

	it('decryptPublicSnapshot refuses a wrong password', async () => {
		const { blob, salt } = await encryptSnapshot(JSON.stringify({ title: 'x' }), 'correct horse')
		const store = useLinkShareStore()

		await expect(
			store.decryptPublicSnapshot({ encryptedSecretSnapshot: blob, argon2idSalt: salt }, 'wrong'),
		).rejects.toBeDefined()
	})
})
