/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The attachment store's in-browser preview (vault-item-clone-preview-and-print,
 * task 2.1): images, PDFs and plain text decrypt through the same helper as a
 * download into memory, and every other type, SVG included, is refused before
 * anything is fetched.
 *
 * @spec openspec/changes/vault-item-clone-preview-and-print/specs/secret-item-actions/spec.md#requirement-preview-an-attachment-in-the-browser
 */

import axios from '@nextcloud/axios'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { PREVIEW_KINDS, previewKind, useAttachmentStore } from '../../src/store/modules/attachment.js'

/**
 * The server's blob for some plaintext: iv || AES-GCM ciphertext, base64.
 *
 * @param {CryptoKey} key The file key.
 * @param {Uint8Array} plaintext The bytes.
 * @return {Promise<string>}
 */
async function sealed(key, plaintext) {
	const iv = crypto.getRandomValues(new Uint8Array(12))
	const ct = new Uint8Array(await crypto.subtle.encrypt({ name: 'AES-GCM', iv }, key, plaintext))
	const out = new Uint8Array(12 + ct.length)
	out.set(iv, 0)
	out.set(ct, 12)
	return btoa(String.fromCharCode(...out))
}

/**
 * A Blob's bytes (jsdom's Blob has no arrayBuffer()).
 *
 * @param {Blob} blob The blob.
 * @return {Promise<Uint8Array>}
 */
function bytesOf(blob) {
	return new Promise((resolve, reject) => {
		const reader = new FileReader()
		reader.onload = () => resolve(new Uint8Array(reader.result))
		reader.onerror = reject
		reader.readAsArrayBuffer(blob)
	})
}

let fileKey
let createObjectURL
let blobs

beforeEach(async () => {
	setActivePinia(createPinia())
	vi.restoreAllMocks()
	fileKey = await crypto.subtle.generateKey({ name: 'AES-GCM', length: 256 }, true, ['encrypt', 'decrypt'])
	blobs = []
	createObjectURL = vi.fn((blob) => {
		blobs.push(blob)
		return `blob:keepiq/${blobs.length}`
	})
	vi.stubGlobal('URL', Object.assign(Object.create(URL), { createObjectURL, revokeObjectURL: vi.fn() }))
})

afterEach(() => {
	vi.unstubAllGlobals()
})

function storeWithBlob(plaintext) {
	const store = useAttachmentStore()
	store.unwrapFileKey = vi.fn().mockResolvedValue(fileKey)
	const get = vi.spyOn(axios, 'get').mockImplementation(async () => ({ data: { blob: await sealed(fileKey, plaintext) } }))
	return { store, get }
}

describe('previewKind', () => {
	it('previews exactly the images, PDF and plain text, ignoring parameters and case', () => {
		expect(Object.keys(PREVIEW_KINDS).sort()).toEqual(
			['application/pdf', 'image/gif', 'image/jpeg', 'image/png', 'image/webp', 'text/plain'],
		)
		expect(previewKind('image/PNG')).toBe('image')
		expect(previewKind('application/pdf')).toBe('pdf')
		expect(previewKind('text/plain; charset=utf-8')).toBe('text')
		for (const type of ['image/svg+xml', 'text/html', 'application/zip', 'application/octet-stream', '', null, undefined, 'constructor', 'toString']) {
			expect(previewKind(type)).toBe(null)
		}
	})
})

describe('useAttachmentStore.preview', () => {
	it('decrypts a PNG into an in-memory object URL of its own type', async () => {
		const png = new Uint8Array([0x89, 0x50, 0x4e, 0x47, 1, 2, 3])
		const { store, get } = storeWithBlob(png)

		const preview = await store.preview({ id: 'att-1', wrappedFileKey: 'w', contentType: 'image/png', filename: 'recovery.png' })

		expect(get).toHaveBeenCalledWith(expect.stringContaining('/api/v1/attachments/att-1/blob'))
		expect(store.unwrapFileKey).toHaveBeenCalledWith('w')
		expect(preview).toEqual({ kind: 'image', url: 'blob:keepiq/1', text: null })
		expect(blobs[0].type).toBe('image/png')
		expect(await bytesOf(blobs[0])).toEqual(png)
		expect(store.loading).toBe(false)
	})

	it('decrypts plain text to the text itself, without an object URL', async () => {
		const { store } = storeWithBlob(new TextEncoder().encode('recovery codes\n1234-5678'))

		const preview = await store.preview({ id: 'att-2', wrappedFileKey: 'w', contentType: 'text/plain' })

		expect(preview).toEqual({ kind: 'text', url: null, text: 'recovery codes\n1234-5678' })
		expect(createObjectURL).not.toHaveBeenCalled()
	})

	it('gives a PDF an object URL typed application/pdf', async () => {
		const { store } = storeWithBlob(new TextEncoder().encode('%PDF-1.7'))
		const preview = await store.preview({ id: 'att-3', wrappedFileKey: 'w', contentType: 'application/pdf' })
		expect(preview.kind).toBe('pdf')
		expect(blobs[0].type).toBe('application/pdf')
	})

	it('refuses an SVG before anything is fetched or decrypted', async () => {
		const { store, get } = storeWithBlob(new Uint8Array([1]))

		await expect(store.preview({ id: 'att-4', wrappedFileKey: 'w', contentType: 'image/svg+xml' })).rejects.toThrow(
			'This file type cannot be previewed',
		)
		expect(get).not.toHaveBeenCalled()
		expect(store.unwrapFileKey).not.toHaveBeenCalled()
		expect(createObjectURL).not.toHaveBeenCalled()
	})

	it('reports a failed decrypt on the store and creates no URL', async () => {
		const { store } = storeWithBlob(new Uint8Array([1, 2]))
		store.unwrapFileKey = vi.fn().mockResolvedValue(
			await crypto.subtle.generateKey({ name: 'AES-GCM', length: 256 }, false, ['encrypt', 'decrypt']),
		)

		await expect(store.preview({ id: 'att-5', wrappedFileKey: 'w', contentType: 'image/png' })).rejects.toThrow()
		expect(store.error).toBeTruthy()
		expect(createObjectURL).not.toHaveBeenCalled()
		expect(store.loading).toBe(false)
	})
})
