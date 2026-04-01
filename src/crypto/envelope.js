/**
 * Shared envelope format for AES-256-GCM encrypted blobs.
 *
 * Format: [4 bytes: version][16 bytes: salt][12 bytes: IV][N bytes: ciphertext][16 bytes: GCM tag]
 * Base64-encoded for storage/transport.
 */

const ENVELOPE_VERSION = 1
const SALT_LENGTH = 16
const IV_LENGTH = 12
const TAG_LENGTH = 16
const HEADER_LENGTH = 4 + SALT_LENGTH + IV_LENGTH // version + salt + IV

/**
 * Encode an envelope from its components.
 *
 * @param {number} version
 * @param {Uint8Array} salt
 * @param {Uint8Array} iv
 * @param {Uint8Array} ciphertext (includes GCM tag appended by WebCrypto)
 * @returns {string} Base64-encoded envelope
 */
export function encodeEnvelope(version, salt, iv, ciphertext) {
	const buffer = new ArrayBuffer(4 + salt.byteLength + iv.byteLength + ciphertext.byteLength)
	const view = new DataView(buffer)

	view.setUint32(0, version, false) // big-endian

	const bytes = new Uint8Array(buffer)
	bytes.set(salt, 4)
	bytes.set(iv, 4 + salt.byteLength)
	bytes.set(ciphertext, 4 + salt.byteLength + iv.byteLength)

	return btoa(String.fromCharCode(...bytes))
}

/**
 * Decode a base64-encoded envelope into its components.
 *
 * @param {string} base64 Base64-encoded envelope
 * @returns {{ version: number, salt: Uint8Array, iv: Uint8Array, ciphertext: Uint8Array, tag: Uint8Array }}
 */
export function decodeEnvelope(base64) {
	const raw = Uint8Array.from(atob(base64), c => c.charCodeAt(0))

	if (raw.byteLength < HEADER_LENGTH + TAG_LENGTH) {
		throw new Error('Envelope too short')
	}

	const view = new DataView(raw.buffer, raw.byteOffset, raw.byteLength)
	const version = view.getUint32(0, false)

	if (version !== ENVELOPE_VERSION) {
		throw new Error(`Unsupported envelope version: ${version}`)
	}

	const salt = raw.slice(4, 4 + SALT_LENGTH)
	const iv = raw.slice(4 + SALT_LENGTH, 4 + SALT_LENGTH + IV_LENGTH)
	// WebCrypto AES-GCM returns ciphertext + tag concatenated
	const ciphertextWithTag = raw.slice(HEADER_LENGTH)

	return { version, salt, iv, ciphertextWithTag }
}

export { ENVELOPE_VERSION, SALT_LENGTH, IV_LENGTH, TAG_LENGTH }
