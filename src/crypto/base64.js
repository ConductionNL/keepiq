/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Byte-wise base64 for the Argon2id-sealed blobs: the link-share KDF module
 * and the vault backup envelope encode their salt and ciphertext with it.
 */

/**
 * Encode a Uint8Array as a base64 string.
 *
 * @param {Uint8Array} bytes The bytes to encode
 * @return {string} Base64 string
 */
export function toBase64(bytes) {
	let binary = ''
	for (let i = 0; i < bytes.length; i++) {
		binary += String.fromCharCode(bytes[i])
	}
	return btoa(binary)
}

/**
 * Decode a base64 string into a Uint8Array.
 *
 * @param {string} base64 The base64 string
 * @return {Uint8Array} Decoded bytes
 */
export function fromBase64(base64) {
	const binary = atob(base64)
	const bytes = new Uint8Array(binary.length)
	for (let i = 0; i < binary.length; i++) {
		bytes[i] = binary.charCodeAt(i)
	}
	return bytes
}
