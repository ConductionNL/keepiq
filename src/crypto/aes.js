/**
 * AES-256-GCM encryption/decryption using WebCrypto + PBKDF2-SHA256 key derivation.
 *
 * Used for encrypting/decrypting the user's RSA private key with their master password.
 */

import { encodeEnvelope, decodeEnvelope, ENVELOPE_VERSION, SALT_LENGTH, IV_LENGTH } from './envelope.js'

const PBKDF2_ITERATIONS = 600000

/**
 * Derive an AES-256-GCM CryptoKey from a master password using PBKDF2-SHA256.
 *
 * @param {string} password The master password
 * @param {Uint8Array} salt 16-byte salt
 * @returns {Promise<CryptoKey>} AES-GCM key
 */
export async function deriveAesKey(password, salt) {
	const encoder = new TextEncoder()
	const keyMaterial = await crypto.subtle.importKey(
		'raw',
		encoder.encode(password),
		'PBKDF2',
		false,
		['deriveKey'],
	)

	return crypto.subtle.deriveKey(
		{
			name: 'PBKDF2',
			salt,
			iterations: PBKDF2_ITERATIONS,
			hash: 'SHA-256',
		},
		keyMaterial,
		{ name: 'AES-GCM', length: 256 },
		false,
		['encrypt', 'decrypt'],
	)
}

/**
 * Encrypt a PEM private key with a master password.
 *
 * @param {string} pem PEM-encoded private key
 * @param {string} password The master password
 * @returns {Promise<string>} Base64-encoded envelope
 */
export async function encryptPrivateKey(pem, password) {
	const salt = crypto.getRandomValues(new Uint8Array(SALT_LENGTH))
	const iv = crypto.getRandomValues(new Uint8Array(IV_LENGTH))
	const key = await deriveAesKey(password, salt)

	const encoder = new TextEncoder()
	const ciphertext = new Uint8Array(
		await crypto.subtle.encrypt(
			{ name: 'AES-GCM', iv },
			key,
			encoder.encode(pem),
		),
	)

	return encodeEnvelope(ENVELOPE_VERSION, salt, iv, ciphertext)
}

/**
 * Decrypt a PEM private key from an AES envelope using a master password.
 *
 * @param {string} envelope Base64-encoded envelope
 * @param {string} password The master password
 * @returns {Promise<string>} PEM-encoded private key
 */
export async function decryptPrivateKey(envelope, password) {
	const { salt, iv, ciphertextWithTag } = decodeEnvelope(envelope)
	const key = await deriveAesKey(password, salt)

	const plaintext = await crypto.subtle.decrypt(
		{ name: 'AES-GCM', iv },
		key,
		ciphertextWithTag,
	)

	return new TextDecoder().decode(plaintext)
}
