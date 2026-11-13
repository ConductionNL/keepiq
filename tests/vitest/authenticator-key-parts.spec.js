/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The key stored for TOTP parameters held one by one (CXF, KeePass), and the
 * refusal for a QR reader that throws on the pixels it is given.
 *
 * @spec openspec/changes/vault-login-totp-codes/specs/login-one-time-codes/spec.md#requirement-import-and-export-keep-a-logins-code-and-passkeys-on-it
 */

import { describe, expect, it } from 'vitest'
import { authenticatorKeyFromParts } from '../../src/totp/authenticatorKey.js'
import { decodeQrImage } from '../../src/totp/qrImage.js'

const SECRET = 'JBSWY3DPEHPK3PXP'

describe('authenticatorKeyFromParts', () => {
	it('returns an empty key when there is no secret', () => {
		expect(authenticatorKeyFromParts({})).toBe('')
		expect(authenticatorKeyFromParts({ secret: '  ' })).toBe('')
		expect(authenticatorKeyFromParts({ secret: null })).toBe('')
	})

	it('returns the bare secret, compacted and upper-cased, for default parameters', () => {
		expect(authenticatorKeyFromParts({ secret: 'jbsw y3dp ehpk 3pxp' })).toBe(SECRET)
		expect(
			authenticatorKeyFromParts({
				secret: SECRET,
				period: '',
				digits: null,
				algorithm: undefined,
				issuer: '',
				account: '',
			}),
		).toBe(SECRET)
		expect(
			authenticatorKeyFromParts({
				secret: SECRET,
				period: '30',
				digits: 6,
				algorithm: 'hmac-sha-1',
			}),
		).toBe(SECRET)
	})

	it('refuses a bare secret the generator cannot use', () => {
		expect(() => authenticatorKeyFromParts({ secret: '1!' })).toThrow()
	})

	it('builds a link with the label when there is an issuer or account', () => {
		expect(
			authenticatorKeyFromParts({ secret: SECRET, issuer: 'Bank', account: 'alice' }),
		).toBe(
			'otpauth://totp/Bank%3Aalice?secret=JBSWY3DPEHPK3PXP&period=30&digits=6&algorithm=SHA1&issuer=Bank',
		)
		expect(authenticatorKeyFromParts({ secret: SECRET, account: 'alice' })).toBe(
			'otpauth://totp/alice?secret=JBSWY3DPEHPK3PXP&period=30&digits=6&algorithm=SHA1',
		)
	})

	it('builds a link for non-default period, digits or algorithm', () => {
		expect(
			authenticatorKeyFromParts({
				secret: SECRET,
				period: 60,
				digits: '8',
				algorithm: 'SHA-256',
			}),
		).toBe(
			'otpauth://totp/?secret=JBSWY3DPEHPK3PXP&period=60&digits=8&algorithm=SHA256',
		)
		expect(authenticatorKeyFromParts({ secret: SECRET, algorithm: 'HMAC-SHA-512' })).toBe(
			'otpauth://totp/?secret=JBSWY3DPEHPK3PXP&period=30&digits=6&algorithm=SHA512',
		)
	})

	it('refuses parameters the generator does not keep', () => {
		expect(() =>
			authenticatorKeyFromParts({ secret: SECRET, digits: 12 }),
		).toThrow()
	})
})

describe('decodeQrImage reader failure', () => {
	it('words a reader that throws on the pixels', async () => {
		await expect(
			decodeQrImage(
				{ type: 'image/png' },
				{
					readPixels: async () => ({ data: new Uint8ClampedArray(16), width: 2, height: 2 }),
					loadDecoder: async () => () => {
						throw new Error('bad pixels')
					},
				},
			),
		).rejects.toThrow('This image could not be read.')
	})

	it('accepts a file without a type or size', async () => {
		await expect(
			decodeQrImage(
				{},
				{
					readPixels: async () => ({ data: new Uint8ClampedArray(16), width: 2, height: 2 }),
					loadDecoder: async () => () => ({ data: '' }),
				},
			),
		).rejects.toThrow('No QR code was found in this image.')
		await expect(decodeQrImage(null, {})).rejects.toThrow(
			'Choose an image file with a QR code.',
		)
	})
})
