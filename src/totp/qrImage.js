/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Reads the authenticator key from a QR code image the user picks, entirely
 * in the browser: the image is drawn to a canvas and its pixels are handed to
 * jsQR, which is loaded on first use so it stays out of the main bundle. The
 * image never leaves the browser and nothing is stored.
 *
 * @spec openspec/changes/vault-login-totp-codes/specs/login-one-time-codes/spec.md#requirement-a-login-can-carry-its-own-totp-seed
 */

import { translate as t } from '@nextcloud/l10n'
import { otpauthFromQrText } from './authenticatorKey.js'

/** Longest side an image is scaled down to before decoding. */
const MAX_SIDE = 1600

/** Largest image file read: a QR screenshot is far smaller. */
export const MAX_IMAGE_BYTES = 10 * 1024 * 1024

/**
 * A refusal worded for the user; any other error is not shown as is.
 */
export class QrImageError extends Error {}

/**
 * A QR decoder with jsQR's signature: RGBA pixels in, the decoded text out.
 *
 * @typedef {(data: Uint8ClampedArray, width: number, height: number, options?: object) => ({data: string}|null)} QrDecoder
 */

/**
 * Load the jsQR decoder function, in its own chunk.
 *
 * @return {Promise<QrDecoder>} jsQR.
 * @spec exclude Lazy-import shim: unwraps the CommonJS default export.
 */
async function loadJsQr() {
	const module = await import(/* webpackChunkName: "qr-decoder" */ 'jsqr')
	const exported = module.default ?? module
	return typeof exported === 'function' ? exported : exported.default
}

/**
 * Load an image file far enough to know its size; browsers decode the
 * pixels only when it is drawn.
 *
 * @param {Blob} file The image.
 * @return {Promise<{image: HTMLImageElement, release: () => void}>} The image and a release of its object URL.
 * @spec exclude Browser image-loading plumbing.
 */
function loadImage(file) {
	const url = URL.createObjectURL(file)
	const release = () => URL.revokeObjectURL(url)
	return new Promise((resolve, reject) => {
		const image = new Image()
		image.onload = () => resolve({ image, release })
		image.onerror = () => {
			release()
			reject(new Error('Image did not load'))
		}
		image.src = url
	})
}

/**
 * The RGBA pixels of an image file, scaled down when it is large. Where the
 * browser can, the image is decoded straight at the smaller size.
 *
 * @param {Blob} file The image.
 * @return {Promise<{data: Uint8ClampedArray, width: number, height: number}>} The pixels.
 * @spec openspec/changes/vault-login-totp-codes/specs/login-one-time-codes/spec.md#requirement-a-login-can-carry-its-own-totp-seed
 */
export async function readImagePixels(file) {
	const { image, release } = await loadImage(file)
	try {
		const size = scaledSize(image)
		const drawable = (await resizedBitmap(file, size)) ?? image
		return drawnPixels(drawable, size)
	} finally {
		release()
	}
}

/**
 * The size to draw an image at: its own, or scaled down so its longest side
 * is MAX_SIDE, with at least one pixel on each side.
 *
 * @param {HTMLImageElement} image The loaded image.
 * @return {{width: number, height: number, scale: number}} The size and the scale applied.
 * @spec openspec/changes/vault-login-totp-codes/specs/login-one-time-codes/spec.md#requirement-a-login-can-carry-its-own-totp-seed
 */
function scaledSize(image) {
	const naturalWidth = image.naturalWidth || image.width
	const naturalHeight = image.naturalHeight || image.height
	const scale = Math.min(1, MAX_SIDE / Math.max(naturalWidth, naturalHeight))
	return {
		width: Math.max(1, Math.round(naturalWidth * scale)),
		height: Math.max(1, Math.round(naturalHeight * scale)),
		scale,
	}
}

/**
 * The image decoded straight at the smaller size, where it is scaled down and
 * the browser can resize while decoding.
 *
 * @param {Blob} file The image.
 * @param {{width: number, height: number, scale: number}} size The size to draw at.
 * @return {Promise<ImageBitmap|null>} The resized bitmap, or null to let the canvas scale the image.
 * @spec openspec/changes/vault-login-totp-codes/specs/login-one-time-codes/spec.md#requirement-a-login-can-carry-its-own-totp-seed
 */
async function resizedBitmap(file, size) {
	if (size.scale >= 1 || typeof createImageBitmap !== 'function') {
		return null
	}
	try {
		return await createImageBitmap(file, {
			resizeWidth: size.width,
			resizeHeight: size.height,
			resizeQuality: 'high',
		})
	} catch {
		// No resize options here: the canvas scales the image instead.
		return null
	}
}

/**
 * Draw the image on a canvas of the given size and read its RGBA pixels back.
 *
 * @param {HTMLImageElement|ImageBitmap} drawable The image or resized bitmap.
 * @param {{width: number, height: number}} size The size to draw at.
 * @return {ImageData} The pixels.
 * @throws {Error} When the browser offers no 2D canvas context.
 * @spec openspec/changes/vault-login-totp-codes/specs/login-one-time-codes/spec.md#requirement-a-login-can-carry-its-own-totp-seed
 */
function drawnPixels(drawable, size) {
	const { width, height } = size
	const canvas = document.createElement('canvas')
	canvas.width = width
	canvas.height = height
	const context = canvas.getContext('2d', { willReadFrequently: true })
	if (!context) {
		throw new Error('No 2D canvas context')
	}
	context.drawImage(drawable, 0, 0, width, height)
	drawable.close?.()
	return context.getImageData(0, 0, width, height)
}

/**
 * Read the `otpauth://totp/` link from a QR code image.
 *
 * @param {Blob} file The image the user picked.
 * @param {object} [deps] Replaceable steps, for tests.
 * @param {(file: Blob) => Promise<ImageData>} [deps.readPixels] Image file to pixels.
 * @param {() => Promise<QrDecoder>} [deps.loadDecoder] Resolves the jsQR function.
 * @return {Promise<string>} The link.
 * @throws {QrImageError} With a message to show when the file is not an image
 *   or too large, the reader cannot load, it holds no QR code, or its QR code
 *   is not an authenticator key.
 * @spec openspec/changes/vault-login-totp-codes/specs/login-one-time-codes/spec.md#requirement-a-login-can-carry-its-own-totp-seed
 */
export async function decodeQrImage(file, deps = {}) {
	assertDecodableFile(file)
	const image = await readPixelsOrRefuse(file, deps.readPixels ?? readImagePixels)
	const jsQR = await loadDecoderOrRefuse(deps.loadDecoder ?? loadJsQr)
	const text = scanQrText(jsQR, image)
	try {
		return otpauthFromQrText(text)
	} catch (e) {
		throw new QrImageError(e.message)
	}
}

/**
 * Refuse a file that is not an image, or one over the size cap, before reading it.
 *
 * @param {Blob} file The image the user picked.
 * @return {void}
 * @throws {QrImageError} When the file cannot be decoded.
 * @spec openspec/changes/vault-login-totp-codes/specs/login-one-time-codes/spec.md#requirement-a-login-can-carry-its-own-totp-seed
 */
function assertDecodableFile(file) {
	if (!file || (file.type && !String(file.type).startsWith('image/'))) {
		throw new QrImageError(t('keepiq', 'Choose an image file with a QR code.'))
	}
	if (typeof file.size === 'number' && file.size > MAX_IMAGE_BYTES) {
		throw new QrImageError(
			t('keepiq', 'This image is too large. Choose an image under 10 MB.'),
		)
	}
}

/**
 * The pixels of the file, or a refusal the user can read.
 *
 * @param {Blob} file The image the user picked.
 * @param {(file: Blob) => Promise<ImageData>} readPixels Image file to pixels.
 * @return {Promise<ImageData>} The pixels.
 * @throws {QrImageError} When the image cannot be read.
 * @spec openspec/changes/vault-login-totp-codes/specs/login-one-time-codes/spec.md#requirement-a-login-can-carry-its-own-totp-seed
 */
async function readPixelsOrRefuse(file, readPixels) {
	try {
		return await readPixels(file)
	} catch {
		throw new QrImageError(t('keepiq', 'This image could not be read.'))
	}
}

/**
 * The jsQR function, or a refusal the user can read.
 *
 * @param {() => Promise<QrDecoder>} loadDecoder Resolves the jsQR function.
 * @return {Promise<QrDecoder>} The decoder.
 * @throws {QrImageError} When the reader cannot load.
 * @spec openspec/changes/vault-login-totp-codes/specs/login-one-time-codes/spec.md#requirement-a-login-can-carry-its-own-totp-seed
 */
async function loadDecoderOrRefuse(loadDecoder) {
	try {
		return await loadDecoder()
	} catch {
		throw new QrImageError(
			t(
				'keepiq',
				'The QR code reader could not be loaded. Check your connection and try again.',
			),
		)
	}
}

/**
 * The text the QR code in the pixels holds.
 *
 * @param {QrDecoder} jsQR The decoder.
 * @param {ImageData} image The pixels.
 * @return {string} The QR text.
 * @throws {QrImageError} When the pixels cannot be scanned or hold no QR code.
 * @spec openspec/changes/vault-login-totp-codes/specs/login-one-time-codes/spec.md#requirement-a-login-can-carry-its-own-totp-seed
 */
function scanQrText(jsQR, image) {
	let result
	try {
		result = jsQR(image.data, image.width, image.height, {
			inversionAttempts: 'attemptBoth',
		})
	} catch {
		throw new QrImageError(t('keepiq', 'This image could not be read.'))
	}
	if (!result || !result.data) {
		throw new QrImageError(t('keepiq', 'No QR code was found in this image.'))
	}
	return result.data
}
