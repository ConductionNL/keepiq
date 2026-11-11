/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden for the base64 the device approval and metadata envelopes write
 * and read: every byte value round-trips, the text is standard padded
 * base64, and a metadata envelope written before stays readable.
 *
 * @spec openspec/specs/crypto-new-device-approval/spec.md
 */

import { describe, expect, it } from 'vitest'
import { fromBase64, toBase64 } from '../../src/crypto/deviceApproval.js'
import { decryptMetadata, encryptMetadata } from '../../src/crypto/metadata.js'

const ALL_BYTES = Uint8Array.from({ length: 256 }, (_, i) => i)

describe('device approval base64', () => {
	it('writes standard padded base64', () => {
		expect(toBase64(ALL_BYTES)).toBe(Buffer.from(ALL_BYTES).toString('base64'))
		expect(toBase64(Uint8Array.from([0xff]))).toBe('/w==')
		expect(toBase64(new Uint8Array(0))).toBe('')
	})

	it('reads it back byte for byte', () => {
		expect(fromBase64(Buffer.from(ALL_BYTES).toString('base64'))).toEqual(ALL_BYTES)
		expect(fromBase64('/w==')).toEqual(Uint8Array.from([0xff]))
		expect(fromBase64('')).toEqual(new Uint8Array(0))
	})
})

describe('metadata envelope base64', () => {
	it('round-trips a value through the envelope text', async () => {
		const key = await crypto.subtle.generateKey(
			{ name: 'AES-GCM', length: 256 },
			false,
			['encrypt', 'decrypt'],
		)
		const value = { note: 'ü€', bytes: [...ALL_BYTES] }
		const envelope = await encryptMetadata(key, value)
		expect(envelope).toMatch(/^[A-Za-z0-9+/]+={0,2}$/)
		expect(await decryptMetadata(key, envelope)).toEqual(value)
	})
})
