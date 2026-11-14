/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The shared DER OID decoder: the first byte carries the first two arcs,
 * later arcs are base-128 with the high bit marking continuation.
 *
 * @spec openspec/specs/federated-sharing/spec.md#requirement-certificate-lookup-is-signed-allowlisted-and-verified-in-the-browser
 */

import { describe, expect, it } from 'vitest'
import { decodeOid } from '../../src/crypto/oid.js'

/**
 * @param {Array<number>} content The OID content bytes.
 * @return {string} The decoded OID, read from inside a larger buffer.
 */
function decode(content) {
	const bytes = Uint8Array.from([0xff, 0xff, ...content, 0xff])
	return decodeOid(bytes, { start: 2, end: 2 + content.length })
}

describe('decodeOid', () => {
	it('splits the first byte into the first two arcs', () => {
		expect(decode([0x55, 0x04, 0x03])).toBe('2.5.4.3')
	})

	it('reads multi-byte arcs', () => {
		expect(decode([0x2a, 0x86, 0x48, 0x86, 0xf7, 0x0d, 0x01, 0x01, 0x0b])).toBe(
			'1.2.840.113549.1.1.11',
		)
	})

	it('reads only the given range', () => {
		expect(decode([0x2b, 0x06])).toBe('1.3.6')
	})
})
