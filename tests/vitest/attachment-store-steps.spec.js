/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Pins the attachment store's list, upload, download and delete paths step
 * by step: the requests, the rows a listing becomes (also when metadata does
 * not decrypt), the defaults for a missing type or name, the locked-vault
 * refusal, and the error text each failure leaves.
 *
 * @spec openspec/specs/encrypted-attachments/spec.md#requirement-client-side-encrypted-attachment-upload
 * @spec openspec/specs/encrypted-attachments/spec.md#requirement-single-blob-envelope-with-per-recipient-key-wrapping
 */

import axios from '@nextcloud/axios'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useAttachmentStore } from '../../src/store/modules/attachment.js'
import { useSessionStore } from '../../src/store/modules/session.js'
import { sharedKeyPair } from './fixtures/rsa-fixtures.js'

describe('attachment store steps', () => {
	beforeEach(async () => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
		const keys = await sharedKeyPair()
		const session = useSessionStore()
		session.certificate = keys.publicKeyPem
		session.cryptoKey = keys.privateKey
	}, 30000)

	/**
	 * Upload one file and answer the listing with what was posted.
	 *
	 * @param {File} file The file.
	 * @return {Promise<object>} The store and the posted body.
	 */
	async function uploaded(file) {
		let body = null
		const post = vi.spyOn(axios, 'post').mockImplementation(async (url, data) => {
			body = data
			return { data: {} }
		})
		const get = vi.spyOn(axios, 'get').mockImplementation(async () => ({
			data: [
				{
					id: 'a1',
					sizeBytes: 3,
					createdAt: 'T',
					wrappedFileKey: body.wrappedFileKey,
					encryptedMetadata: body.encryptedMetadata,
				},
				{
					id: 'a2',
					sizeBytes: 4,
					createdAt: 'T2',
					wrappedFileKey: body.wrappedFileKey,
					encryptedMetadata: 'bm90IGNpcGhlcnRleHQ=',
				},
			],
		}))
		const store = useAttachmentStore()
		await store.upload('sec-1', file)
		return { store, body, post, get }
	}

	it('uploads to the secret, lists it back with its metadata and an undecryptable row as unnamed', async () => {
		const { store, post, get } = await uploaded(
			new File(['abc'], 'a.txt', { type: 'text/plain' }),
		)
		expect(post.mock.calls[0][0]).toBe('/apps/keepiq/api/v1/secrets/sec-1/attachments')
		expect(Object.keys(post.mock.calls[0][1])).toEqual([
			'blob',
			'encryptedMetadata',
			'wrappedFileKey',
		])
		expect(get).toHaveBeenCalledWith('/apps/keepiq/api/v1/secrets/sec-1/attachments')
		expect(store.attachments.map(({ wrappedFileKey, ...row }) => row)).toEqual([
			{ id: 'a1', sizeBytes: 3, createdAt: 'T', filename: 'a.txt', contentType: 'text/plain' },
			{ id: 'a2', sizeBytes: 4, createdAt: 'T2', filename: null, contentType: null },
		])
		expect(store.loading).toBe(false)
		expect(store.error).toBe(null)
	}, 30000)

	it('records a file without a type as application/octet-stream', async () => {
		const { store } = await uploaded(new File(['abc'], 'b.bin'))
		expect(store.attachments[0].contentType).toBe('application/octet-stream')
	}, 30000)

	it('refuses to upload while the vault is locked', async () => {
		useSessionStore().certificate = null
		const post = vi.spyOn(axios, 'post')
		const store = useAttachmentStore()
		await expect(store.upload('sec-1', new File(['x'], 'x'))).rejects.toThrow(
			'Vault is locked',
		)
		expect(post).not.toHaveBeenCalled()
		expect(store.loading).toBe(false)
	})

	it.each([
		['upload', (s) => s.upload('sec-1', new File(['x'], 'x')), 'post', 'Upload failed'],
		['remove', (s) => s.remove('sec-1', 'a1'), 'delete', 'Delete failed'],
		['fetchAttachments', (s) => s.fetchAttachments('sec-1'), 'get', 'Failed to load attachments'],
		['download', (s) => s.download({ id: 'a1', wrappedFileKey: 'w' }), 'get', 'Download failed'],
	])('%s keeps the server message, else the error message, else its own text', async (name, act, verb, fallback) => {
		const store = useAttachmentStore()
		const call = vi.spyOn(axios, verb)
		call.mockRejectedValueOnce({ response: { data: { message: 'server says' } } })
		await expect(act(store)).rejects.toBeTruthy()
		expect(store.error).toBe('server says')
		call.mockRejectedValueOnce(new Error('plain'))
		await expect(act(store)).rejects.toBeTruthy()
		expect(store.error).toBe('plain')
		call.mockRejectedValueOnce({})
		await expect(act(store)).rejects.toBeTruthy()
		expect(store.error).toBe(fallback)
		expect(store.loading).toBe(false)
	}, 30000)

	it('deletes the attachment and lists the secret again', async () => {
		const del = vi.spyOn(axios, 'delete').mockResolvedValue({})
		const get = vi.spyOn(axios, 'get').mockResolvedValue({ data: null })
		const store = useAttachmentStore()
		await store.remove('sec-1', 'a1')
		expect(del).toHaveBeenCalledWith('/apps/keepiq/api/v1/attachments/a1')
		expect(get).toHaveBeenCalledWith('/apps/keepiq/api/v1/secrets/sec-1/attachments')
		expect(store.attachments).toEqual([])
	})

	it('saves a download under its name and type, or the defaults', async () => {
		const { store, body } = await uploaded(new File(['abc'], 'a.txt'))
		vi.spyOn(axios, 'get').mockResolvedValue({ data: { blob: body.blob } })
		const blobs = []
		URL.createObjectURL = vi.fn((blob) => {
			blobs.push(blob)
			return 'blob:1'
		})
		URL.revokeObjectURL = vi.fn()
		const clicks = []
		const previous = global.document
		global.document = {
			createElement: () => ({
				click() {
					clicks.push([this.href, this.download])
				},
			}),
		}
		await store.download({ id: 'a1', wrappedFileKey: body.wrappedFileKey })
		await store.download({
			id: 'a1',
			wrappedFileKey: body.wrappedFileKey,
			filename: 'n.txt',
			contentType: 'text/plain',
		})
		expect(axios.get).toHaveBeenCalledWith('/apps/keepiq/api/v1/attachments/a1/blob')
		expect(blobs.map((b) => b.type)).toEqual(['application/octet-stream', 'text/plain'])
		expect(await blobs[0].text()).toBe('abc')
		expect(clicks).toEqual([
			['blob:1', 'attachment'],
			['blob:1', 'n.txt'],
		])
		expect(URL.revokeObjectURL).toHaveBeenCalledTimes(2)
		global.document = previous
	}, 30000)
})
