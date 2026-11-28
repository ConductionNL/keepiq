/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Text for tests of the single-block PEM rule: base64 of a certificate-shaped
 * DER around a second RSA-4096 key (the secondary fixture key), without
 * padding. Tests put it next to a real certificate and use the second key's
 * private half to check that nothing was encrypted to it.
 */

import { vi } from 'vitest'
import {
	pemToDer,
	RSA4096_SECONDARY_PUBLIC_KEY_SPKI_PEM,
	secondaryKeyPair,
} from './rsa-fixtures.js'

/**
 * @param {number} tag The DER tag.
 * @param {...Uint8Array} parts The value, in parts.
 * @return {Uint8Array} One DER element.
 */
export function derElement(tag, ...parts) {
	const length = parts.reduce((sum, part) => sum + part.length, 0)
	const header =
		length < 0x80
			? [tag, length]
			: length < 0x100
				? [tag, 0x81, length]
				: [tag, 0x82, length >> 8, length & 0xff]
	const out = new Uint8Array(header.length + length)
	out.set(header)
	let offset = header.length
	for (const part of parts) {
		out.set(part, offset)
		offset += part.length
	}
	return out
}

/**
 * @param {Uint8Array} bytes Bytes.
 * @return {string} Base64.
 */
export function toBase64(bytes) {
	return btoa(String.fromCharCode(...bytes))
}

/**
 * The text, the second key's SubjectPublicKeyInfo and its private key.
 *
 * @return {Promise<{text: string, spki: Uint8Array, privateKey: CryptoKey}>}
 */
export async function otherKeyText() {
	const spki = pemToDer(RSA4096_SECONDARY_PUBLIC_KEY_SPKI_PEM)
	const empty = derElement(0x30)
	for (let serialLength = 1; serialLength <= 3; serialLength++) {
		const tbs = derElement(
			0x30,
			derElement(0x02, new Uint8Array(serialLength).fill(1)),
			empty,
			empty,
			empty,
			empty,
			spki,
		)
		const der = derElement(0x30, tbs, empty, derElement(0x03, Uint8Array.of(0)))
		if (der.length % 3 === 0) {
			const { privateKey } = await secondaryKeyPair()
			return { text: toBase64(der), spki, privateKey }
		}
	}
	throw new Error('no unpadded size found')
}

/**
 * Record the SubjectPublicKeyInfo of every key imported for encryption from
 * now on, to tell which key a value was encrypted to.
 *
 * @return {Array<Uint8Array>} Filled as keys are imported.
 */
export function recordEncryptionKeys() {
	const keys = []
	const importKey = crypto.subtle.importKey.bind(crypto.subtle)
	vi.spyOn(crypto.subtle, 'importKey').mockImplementation(
		(format, data, algorithm, extractable, usages) => {
			if (format === 'spki' && usages.includes('encrypt')) {
				keys.push(new Uint8Array(data))
			}
			return importKey(format, data, algorithm, extractable, usages)
		},
	)
	return keys
}
