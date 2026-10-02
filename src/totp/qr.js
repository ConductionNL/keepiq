/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Read a TOTP seed out of a QR image, in the browser (vault-login-totp-codes
 * design D4). The image never leaves the page: it is drawn on a canvas that is
 * never attached to the document, its pixels are decoded with jsQR, and the
 * canvas is dropped.
 *
 * @spec openspec/changes/vault-login-totp-codes/specs/login-one-time-codes/spec.md#requirement-a-login-can-carry-its-own-totp-seed
 */

import jsQR from 'jsqr'

/**
 * Decode QR pixels (RGBA, as a canvas hands them over).
 *
 * @param {Uint8ClampedArray} data The RGBA pixels.
 * @param {number} width The image width.
 * @param {number} height The image height.
 * @return {string} The QR text, or '' when no QR code is found.
 */
export function decodeQrPixels(data, width, height) {
	const result = jsQR(data, width, height)
	return result?.data ?? ''
}

/**
 * Decode the QR code in an image file picked by the user.
 *
 * @param {File|Blob} file The picked image.
 * @return {Promise<string>} The QR text, or '' when the image holds none or
 *   cannot be read.
 */
export async function decodeQrFile(file) {
	try {
		const bitmap = await createImageBitmap(file)
		const canvas = document.createElement('canvas')
		canvas.width = bitmap.width
		canvas.height = bitmap.height
		const context = canvas.getContext('2d')
		context.drawImage(bitmap, 0, 0)
		const pixels = context.getImageData(0, 0, bitmap.width, bitmap.height)
		bitmap.close?.()
		return decodeQrPixels(pixels.data, pixels.width, pixels.height)
	} catch {
		return ''
	}
}
