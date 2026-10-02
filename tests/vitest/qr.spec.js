import QRCode from 'qrcode'
/**
 * @spec openspec/changes/vault-login-totp-codes/specs/login-one-time-codes/spec.md#requirement-a-login-can-carry-its-own-totp-seed
 *
 * A QR image picked in the Authenticator key field is decoded in the browser.
 * The QR is generated here from a test URI (qrcode, dev only) and rendered to
 * RGBA pixels the way a canvas would hand them over.
 */
import { describe, expect, it } from 'vitest'
import { decodeQrPixels } from '../../src/totp/qr.js'

const URI =
	'otpauth://totp/Example:alice@example.com?secret=GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ&issuer=Example'

/**
 * Render a QR matrix to RGBA pixels with a quiet zone, `scale` pixels a module.
 *
 * @param {string} text The text to encode.
 * @param {number} scale Pixels per module.
 * @return {{data: Uint8ClampedArray, width: number, height: number}} The pixels.
 */
function pixelsFor(text, scale = 4) {
	const qr = QRCode.create(text, { errorCorrectionLevel: 'M' })
	const size = qr.modules.size
	const quiet = 4
	const width = (size + quiet * 2) * scale
	const data = new Uint8ClampedArray(width * width * 4).fill(255)
	for (let y = 0; y < size; y++) {
		for (let x = 0; x < size; x++) {
			if (!qr.modules.get(y, x)) continue
			for (let dy = 0; dy < scale; dy++) {
				for (let dx = 0; dx < scale; dx++) {
					const px =
						((y + quiet) * scale + dy) * width + (x + quiet) * scale + dx
					data[px * 4] = 0
					data[px * 4 + 1] = 0
					data[px * 4 + 2] = 0
				}
			}
		}
	}
	return { data, width, height: width }
}

describe('decodeQrPixels', () => {
	it('reads the otpauth URI from a QR image', () => {
		const { data, width, height } = pixelsFor(URI)
		expect(decodeQrPixels(data, width, height)).toBe(URI)
	})

	it('answers an empty string for an image without a QR code', () => {
		const width = 64
		const data = new Uint8ClampedArray(width * width * 4).fill(255)
		expect(decodeQrPixels(data, width, width)).toBe('')
	})
})
