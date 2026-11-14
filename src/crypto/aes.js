/**
 * AES-256-GCM encryption/decryption using WebCrypto + PBKDF2-SHA256 key derivation.
 *
 * Used for encrypting/decrypting the user's RSA private key with their master password.
 */

import {
	decodeEnvelope,
	encodeEnvelope,
	ENVELOPE_VERSION,
	IV_LENGTH,
	SALT_LENGTH,
} from './envelope.js'

const PBKDF2_ITERATIONS = 600000
const PBKDF2_HASH = 'SHA-256'
const AES_ALGORITHM = 'AES-GCM'
const AES_KEY_BITS = 256

/**
 * How the private key is protected by the master password, as the functions
 * below do it. Read by the encryption overview.
 *
 * @spec openspec/changes/crypto-vault-encryption-details/specs/vault-encryption-details/spec.md#requirement-the-user-sees-what-protects-the-vault
 */
export const MASTER_KEY_PARAMETERS = Object.freeze({
	cipher: AES_ALGORITHM,
	keyBits: AES_KEY_BITS,
	kdf: 'PBKDF2',
	kdfHash: PBKDF2_HASH,
	iterations: PBKDF2_ITERATIONS,
	saltBytes: SALT_LENGTH,
})

/**
 * The algorithm of a fresh content key (an attachment's file key). Passed to
 * `crypto.subtle.generateKey` as is.
 *
 * @spec openspec/changes/crypto-vault-encryption-details/specs/vault-encryption-details/spec.md#requirement-the-user-sees-what-protects-the-vault
 */
export const CONTENT_KEY_PARAMETERS = Object.freeze({
	name: AES_ALGORITHM,
	length: AES_KEY_BITS,
})

/**
 * Derive an AES-256-GCM CryptoKey from a master password using PBKDF2-SHA256.
 *
 * @param {string} password The master password
 * @param {Uint8Array} salt 16-byte salt
 * @return {Promise<CryptoKey>} AES-GCM key
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
			hash: PBKDF2_HASH,
		},
		keyMaterial,
		{ name: AES_ALGORITHM, length: AES_KEY_BITS },
		false,
		['encrypt', 'decrypt'],
	)
}

/**
 * Encrypt a PEM private key with a master password.
 *
 * @param {string} pem PEM-encoded private key
 * @param {string} password The master password
 * @return {Promise<string>} Base64-encoded envelope
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
 * @return {Promise<string>} PEM-encoded private key
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

/**
 * Derive the raw bytes of the vault unlock key (the AES-256 key that
 * decrypts the private-key envelope) from the master password + the
 * envelope's own salt. Extractable so it can be re-wrapped under a
 * passkey PRF KEK at enrollment (passkey-vault-login §D1). The raw key
 * only exists transiently in memory, exactly like the master-password
 * path's derived key.
 *
 * @param {string} password The master password
 * @param {Uint8Array} salt The 16-byte salt from the private-key envelope
 * @return {Promise<Uint8Array>} The raw 32-byte unlock key
 */
export async function deriveUnlockKeyRaw(password, salt) {
	const encoder = new TextEncoder()
	const keyMaterial = await crypto.subtle.importKey(
		'raw',
		encoder.encode(password),
		'PBKDF2',
		false,
		['deriveBits'],
	)
	const bits = await crypto.subtle.deriveBits(
		{ name: 'PBKDF2', salt, iterations: PBKDF2_ITERATIONS, hash: PBKDF2_HASH },
		keyMaterial,
		256,
	)
	return new Uint8Array(bits)
}

/**
 * Decrypt a private-key envelope with the RAW unlock-key bytes recovered
 * from a passkey PRF envelope (passkey-vault-login §unlock step 7).
 * Cryptographically identical to {@link decryptPrivateKey} — same
 * AES-GCM over the same envelope — but keyed by the recovered raw key
 * instead of re-deriving from the master password.
 *
 * @param {string} envelope Base64-encoded private-key envelope
 * @param {Uint8Array} rawKey The raw 32-byte unlock key
 * @return {Promise<string>} PEM-encoded private key
 */
export async function decryptPrivateKeyWithRawKey(envelope, rawKey) {
	const { iv, ciphertextWithTag } = decodeEnvelope(envelope)
	const key = await crypto.subtle.importKey(
		'raw',
		rawKey,
		{ name: 'AES-GCM' },
		false,
		['decrypt'],
	)
	const plaintext = await crypto.subtle.decrypt(
		{ name: 'AES-GCM', iv },
		key,
		ciphertextWithTag,
	)
	return new TextDecoder().decode(plaintext)
}
