/**
 * AES-256-GCM encryption of plaintext metadata under an already-derived
 * CryptoKey (offline-readonly-cache §2.2 / D1).
 *
 * The offline cache stores secret ciphertext as-is (already server-safe,
 * ADR-003), but the plaintext metadata Doriath keeps for search — secret
 * name/url and folder names — must NOT sit in the clear on a stolen locked
 * device. This module encrypts arbitrary JSON metadata at rest under the
 * VAULT UNLOCK KEY (the AES key derived from the master password on unlock),
 * so the metadata only becomes readable after a successful offline unlock.
 *
 * Unlike src/crypto/aes.js (which derives a fresh key from a password per
 * call), these helpers take an existing non-extractable AES-GCM CryptoKey.
 * The stored form is base64([12-byte IV][ciphertext+GCM tag]).
 *
 * @spec openspec/specs/offline-readonly-cache/spec.md#requirement-online-sessions-write-through-an-encrypted-local-snapshot
 */

const IV_LENGTH = 12

/**
 * Encode bytes to base64.
 *
 * @param {Uint8Array} bytes The bytes.
 * @return {string} Base64.
 */
function toBase64(bytes) {
	let binary = ''
	for (let i = 0; i < bytes.length; i++) {
		binary += String.fromCharCode(bytes[i])
	}
	return btoa(binary)
}

/**
 * Decode base64 to bytes.
 *
 * @param {string} b64 Base64.
 * @return {Uint8Array} The bytes.
 */
function fromBase64(b64) {
	return Uint8Array.from(atob(b64), (c) => c.charCodeAt(0))
}

/**
 * Encrypt a JSON-serialisable value under an AES-GCM CryptoKey.
 *
 * @param {CryptoKey} key AES-GCM key (usages include 'encrypt').
 * @param {*} value Any JSON-serialisable value.
 * @return {Promise<string>} base64([IV][ciphertext+tag]).
 */
export async function encryptMetadata(key, value) {
	const iv = crypto.getRandomValues(new Uint8Array(IV_LENGTH))
	const plaintext = new TextEncoder().encode(JSON.stringify(value))
	const ciphertext = new Uint8Array(
		await crypto.subtle.encrypt({ name: 'AES-GCM', iv }, key, plaintext),
	)
	const framed = new Uint8Array(iv.length + ciphertext.length)
	framed.set(iv, 0)
	framed.set(ciphertext, iv.length)
	return toBase64(framed)
}

/**
 * Decrypt a value produced by {@link encryptMetadata}.
 *
 * @param {CryptoKey} key AES-GCM key (usages include 'decrypt').
 * @param {string} envelope base64([IV][ciphertext+tag]).
 * @return {Promise<*>} The original value.
 * @throws {Error} When the key is wrong or the envelope is tampered (GCM auth fails).
 */
export async function decryptMetadata(key, envelope) {
	const framed = fromBase64(envelope)
	if (framed.length <= IV_LENGTH) {
		throw new Error('Metadata envelope too short')
	}
	const iv = framed.slice(0, IV_LENGTH)
	const ciphertext = framed.slice(IV_LENGTH)
	const plaintext = await crypto.subtle.decrypt(
		{ name: 'AES-GCM', iv },
		key,
		ciphertext,
	)
	return JSON.parse(new TextDecoder().decode(plaintext))
}
