/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The authenticator key a user enters on a login, typed or read from a QR
 * image: what is accepted, how it is stored, and the readable refusals.
 *
 * @spec openspec/changes/vault-login-totp-codes/specs/login-one-time-codes/spec.md#requirement-a-login-can-carry-its-own-totp-seed
 */

import { describe, expect, it, vi } from 'vitest'
import {
	authenticatorKeyError,
	normaliseAuthenticatorKey,
	otpauthFromQrText,
} from '../../src/totp/authenticatorKey.js'
import {
	decodeQrImage,
	MAX_IMAGE_BYTES,
	QrImageError,
} from '../../src/totp/qrImage.js'

const LINK =
	'otpauth://totp/Bank:alice@example.org?secret=JBSWY3DPEHPK3PXP&issuer=Bank'

describe('authenticator key entry', () => {
	it('accepts an otpauth://totp/ link and a base32 secret', () => {
		expect(authenticatorKeyError(LINK)).toBe('')
		expect(authenticatorKeyError('JBSW Y3DP EHPK 3PXP')).toBe('')
		expect(authenticatorKeyError('')).toBe('')
		expect(authenticatorKeyError('   ')).toBe('')
	})

	it.each([
		['a HOTP link', 'otpauth://hotp/Bank?secret=JBSWY3DPEHPK3PXP&counter=1'],
		['a link without a secret', 'otpauth://totp/Bank?issuer=Bank'],
		['text outside base32', 'not-a-seed!'],
		['a web address', 'https://example.org'],
	])('refuses %s', (_label, value) => {
		expect(authenticatorKeyError(value)).not.toBe('')
	})

	it('stores a link trimmed and a secret without spacing, upper-cased', () => {
		expect(normaliseAuthenticatorKey(`  ${LINK} `)).toBe(LINK)
		expect(normaliseAuthenticatorKey(' jbsw y3dp ehpk 3pxp ')).toBe(
			'JBSWY3DPEHPK3PXP',
		)
		expect(normaliseAuthenticatorKey('')).toBe('')
	})

	it('takes only an otpauth://totp/ link from QR text', () => {
		expect(otpauthFromQrText(LINK)).toBe(LINK)
		expect(() => otpauthFromQrText('https://example.org')).toThrow(
			/does not hold an authenticator key/,
		)
		expect(() => otpauthFromQrText('JBSWY3DPEHPK3PXP')).toThrow(
			/does not hold an authenticator key/,
		)
		expect(() =>
			otpauthFromQrText('otpauth://hotp/Bank?secret=JBSWY3DPEHPK3PXP'),
		).toThrow(/does not hold an authenticator key/)
	})
})

describe('decodeQrImage', () => {
	const image = { type: 'image/png' }
	const pixels = { data: new Uint8ClampedArray(16), width: 2, height: 2 }

	it('returns the link a QR image holds', async () => {
		const jsQR = vi.fn(() => ({ data: LINK }))
		const link = await decodeQrImage(image, {
			readPixels: async () => pixels,
			loadDecoder: async () => jsQR,
		})
		expect(link).toBe(LINK)
		expect(jsQR).toHaveBeenCalledWith(pixels.data, 2, 2, {
			inversionAttempts: 'attemptBoth',
		})
	})

	it('says so when no QR code is found', async () => {
		await expect(
			decodeQrImage(image, {
				readPixels: async () => pixels,
				loadDecoder: async () => () => null,
			}),
		).rejects.toThrow('No QR code was found in this image.')
	})

	it('refuses a QR code that is not an authenticator key', async () => {
		await expect(
			decodeQrImage(image, {
				readPixels: async () => pixels,
				loadDecoder: async () => () => ({ data: 'https://example.org' }),
			}),
		).rejects.toThrow(/does not hold an authenticator key/)
	})

	it('refuses a file that is not an image, and one that cannot be read', async () => {
		await expect(
			decodeQrImage({ type: 'text/plain' }, { readPixels: vi.fn() }),
		).rejects.toThrow('Choose an image file with a QR code.')
		await expect(
			decodeQrImage(image, {
				readPixels: async () => {
					throw new Error('broken')
				},
			}),
		).rejects.toThrow('This image could not be read.')
	})

	it('refuses an image over the size cap before reading it', async () => {
		const readPixels = vi.fn()
		await expect(
			decodeQrImage(
				{ type: 'image/png', size: MAX_IMAGE_BYTES + 1 },
				{ readPixels },
			),
		).rejects.toThrow('This image is too large. Choose an image under 10 MB.')
		expect(readPixels).not.toHaveBeenCalled()
	})

	it('words a reader that fails to load for the user', async () => {
		const failure = decodeQrImage(image, {
			readPixels: async () => pixels,
			loadDecoder: async () => {
				throw new Error('Loading chunk qr-decoder failed.')
			},
		})
		await expect(failure).rejects.toBeInstanceOf(QrImageError)
		await expect(failure).rejects.toThrow(
			'The QR code reader could not be loaded. Check your connection and try again.',
		)
	})

	it('loads the real decoder lazily and finds nothing in a blank image', async () => {
		const blank = {
			data: new Uint8ClampedArray(64 * 64 * 4).fill(255),
			width: 64,
			height: 64,
		}
		await expect(
			decodeQrImage(image, { readPixels: async () => blank }),
		).rejects.toThrow('No QR code was found in this image.')
	})
})
