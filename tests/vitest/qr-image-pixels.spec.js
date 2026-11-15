/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The pixels read from a QR image file: the size it is scaled to, when the
 * browser resizes it while decoding, and the object URL always released.
 *
 * @spec openspec/changes/vault-login-totp-codes/specs/login-one-time-codes/spec.md#requirement-a-login-can-carry-its-own-totp-seed
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { readImagePixels } from '../../src/totp/qrImage.js'

let drawn
let revoked
let contextAvailable
let imageSize
let imageFails

/**
 * A stand-in for the browser's Image: loads (or fails) as soon as its src is set.
 */
class FakeImage {
	set src(value) {
		this.url = value
		Object.assign(this, imageSize)
		queueMicrotask(() => (imageFails ? this.onerror() : this.onload()))
	}
}

beforeEach(() => {
	drawn = null
	revoked = []
	contextAvailable = true
	imageSize = { naturalWidth: 400, naturalHeight: 200, width: 0, height: 0 }
	imageFails = false
	vi.stubGlobal('URL', {
		createObjectURL: () => 'blob:qr',
		revokeObjectURL: (url) => revoked.push(url),
	})
	vi.stubGlobal('Image', FakeImage)
	vi.stubGlobal('document', {
		createElement: () => {
			const canvas = {
				getContext: (kind, options) => {
					if (!contextAvailable) {
						return null
					}
					return {
						drawImage: (drawable, x, y, width, height) => {
							drawn = { drawable, x, y, width, height, canvas, kind, options }
						},
						getImageData: (x, y, width, height) => ({ data: 'pixels', width, height }),
					}
				},
			}
			return canvas
		},
	})
})

afterEach(() => {
	vi.unstubAllGlobals()
})

describe('readImagePixels', () => {
	it('draws a small image at its own size and releases the URL', async () => {
		vi.stubGlobal('createImageBitmap', vi.fn())
		const pixels = await readImagePixels({ type: 'image/png' })
		expect(pixels).toEqual({ data: 'pixels', width: 400, height: 200 })
		expect(drawn.drawable).toBeInstanceOf(FakeImage)
		expect(drawn.canvas.width).toBe(400)
		expect(drawn.canvas.height).toBe(200)
		expect(drawn.kind).toBe('2d')
		expect(drawn.options).toEqual({ willReadFrequently: true })
		expect(globalThis.createImageBitmap).not.toHaveBeenCalled()
		expect(revoked).toEqual(['blob:qr'])
	})

	it('falls back to width and height when there is no natural size', async () => {
		imageSize = { naturalWidth: 0, naturalHeight: 0, width: 30, height: 10 }
		const pixels = await readImagePixels({})
		expect(pixels).toEqual({ data: 'pixels', width: 30, height: 10 })
	})

	it('decodes a large image straight at the smaller size and closes the bitmap', async () => {
		imageSize = { naturalWidth: 3200, naturalHeight: 1000 }
		const bitmap = { close: vi.fn() }
		const createImageBitmap = vi.fn(async () => bitmap)
		vi.stubGlobal('createImageBitmap', createImageBitmap)
		const file = { type: 'image/png' }
		const pixels = await readImagePixels(file)
		expect(createImageBitmap).toHaveBeenCalledWith(file, {
			resizeWidth: 1600,
			resizeHeight: 500,
			resizeQuality: 'high',
		})
		expect(drawn.drawable).toBe(bitmap)
		expect(bitmap.close).toHaveBeenCalled()
		expect(pixels).toEqual({ data: 'pixels', width: 1600, height: 500 })
	})

	it('lets the canvas scale a large image when the browser cannot resize', async () => {
		imageSize = { naturalWidth: 1000, naturalHeight: 3200 }
		vi.stubGlobal('createImageBitmap', vi.fn(async () => {
			throw new Error('no resize options')
		}))
		const pixels = await readImagePixels({})
		expect(drawn.drawable).toBeInstanceOf(FakeImage)
		expect(pixels).toEqual({ data: 'pixels', width: 500, height: 1600 })

		vi.stubGlobal('createImageBitmap', undefined)
		await readImagePixels({})
		expect(drawn.drawable).toBeInstanceOf(FakeImage)
	})

	it('keeps at least one pixel on a side', async () => {
		imageSize = { naturalWidth: 100000, naturalHeight: 1 }
		vi.stubGlobal('createImageBitmap', undefined)
		const pixels = await readImagePixels({})
		expect(pixels).toEqual({ data: 'pixels', width: 1600, height: 1 })
	})

	it('refuses without a 2D context and still releases the URL', async () => {
		contextAvailable = false
		await expect(readImagePixels({})).rejects.toThrow('No 2D canvas context')
		expect(revoked).toEqual(['blob:qr'])
	})

	it('rejects an image that does not load and releases the URL', async () => {
		imageFails = true
		await expect(readImagePixels({})).rejects.toThrow('Image did not load')
		expect(revoked).toEqual(['blob:qr'])
	})
})
