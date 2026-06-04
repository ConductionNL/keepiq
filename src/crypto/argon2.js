/**
 * Argon2id key derivation + AES-256-GCM snapshot encryption for link shares.
 *
 * Link share snapshots use a symmetric AES-256 key derived from a one-time
 * link password via Argon2id (memory-hard KDF). This is intentionally a
 * DIFFERENT scheme from the RSA private-key envelope in aes.js / envelope.js:
 * the salt is stored in its own database column (not embedded in the blob),
 * and the blob format is simply `[12-byte IV][ciphertext + GCM tag]`.
 *
 * Argon2id is not available in WebCrypto, so it is provided by the
 * `argon2-browser` WASM library, which is lazy-loaded on first use to keep it
 * out of the initial vault bundle (~200 KB WASM payload).
 *
 * Fixed parameters (MUST match between creation and access):
 *   memory 64 MiB (65536 KiB), iterations 3, parallelism 1, output 32 bytes.
 */

/** Argon2id memory cost in KiB (64 MiB). */
const ARGON2_MEMORY_KIB = 65536

/** Argon2id iteration (time) cost. */
const ARGON2_ITERATIONS = 3

/** Argon2id parallelism. */
const ARGON2_PARALLELISM = 1

/** Derived key length in bytes (AES-256). */
const KEY_LENGTH = 32

/** AES-GCM IV length in bytes. */
const IV_LENGTH = 12

/** Argon2id salt length in bytes. */
const SALT_LENGTH = 16

/**
 * Encode a Uint8Array as a base64 string.
 *
 * @param {Uint8Array} bytes The bytes to encode
 * @return {string} Base64 string
 */
function toBase64(bytes) {
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
function fromBase64(base64) {
	const binary = atob(base64)
	const bytes = new Uint8Array(binary.length)
	for (let i = 0; i < binary.length; i++) {
		bytes[i] = binary.charCodeAt(i)
	}
	return bytes
}

/**
 * Whether the runtime supports WebAssembly (required for Argon2id).
 *
 * @return {boolean} True when WASM is available
 */
export function isArgon2Supported() {
	return typeof WebAssembly === 'object' && typeof WebAssembly.instantiate === 'function'
}

/**
 * Derive a 256-bit AES key from a password and salt via Argon2id (WASM).
 *
 * @param {string} password The link password
 * @param {Uint8Array} salt The 16-byte salt
 * @return {Promise<CryptoKey>} An importable AES-GCM key
 */
export async function deriveAesKeyArgon2id(password, salt) {
	if (!isArgon2Supported()) {
		throw new Error('WebAssembly is required for Argon2id but is not available')
	}

	// Lazy-load the WASM KDF so it is excluded from the initial vault bundle.
	// argon2-browser is installed with the secrets-feature wiring (tasks.md §6.1);
	// the import is intentionally lazy and dynamic.
	// eslint-disable-next-line import/no-unresolved
	const argon2 = await import('argon2-browser')

	const result = await argon2.hash({
		pass: password,
		salt,
		type: argon2.ArgonType.Argon2id,
		mem: ARGON2_MEMORY_KIB,
		time: ARGON2_ITERATIONS,
		parallelism: ARGON2_PARALLELISM,
		hashLen: KEY_LENGTH,
	})

	return crypto.subtle.importKey(
		'raw',
		result.hash,
		{ name: 'AES-GCM', length: 256 },
		false,
		['encrypt', 'decrypt'],
	)
}

/**
 * Encrypt a JSON snapshot string into a link share blob.
 *
 * Generates a fresh salt and IV, derives the AES key via Argon2id, and
 * returns the base64 blob (`[IV][ciphertext + tag]`) plus the base64 salt
 * (stored server-side in its own column).
 *
 * @param {string} jsonString The serialized plaintext snapshot
 * @param {string} password The generated link password
 * @return {Promise<{blob: string, salt: string}>} Base64 blob and salt
 */
export async function encryptSnapshot(jsonString, password) {
	const salt = crypto.getRandomValues(new Uint8Array(SALT_LENGTH))
	const iv = crypto.getRandomValues(new Uint8Array(IV_LENGTH))
	const key = await deriveAesKeyArgon2id(password, salt)

	const ciphertext = new Uint8Array(
		await crypto.subtle.encrypt(
			{ name: 'AES-GCM', iv },
			key,
			new TextEncoder().encode(jsonString),
		),
	)

	const blob = new Uint8Array(iv.length + ciphertext.length)
	blob.set(iv, 0)
	blob.set(ciphertext, iv.length)

	return { blob: toBase64(blob), salt: toBase64(salt) }
}

/**
 * Decrypt a link share blob with a password and the stored salt.
 *
 * Throws when the password is wrong (AES-GCM authentication tag mismatch).
 *
 * @param {string} base64Blob The base64 `[IV][ciphertext + tag]` blob
 * @param {string} base64Salt The base64 Argon2id salt
 * @param {string} password The entered link password
 * @return {Promise<string>} The decrypted JSON snapshot string
 */
export async function decryptSnapshot(base64Blob, base64Salt, password) {
	const blob = fromBase64(base64Blob)
	const salt = fromBase64(base64Salt)
	const iv = blob.slice(0, IV_LENGTH)
	const ciphertextWithTag = blob.slice(IV_LENGTH)

	const key = await deriveAesKeyArgon2id(password, salt)

	const plaintext = await crypto.subtle.decrypt(
		{ name: 'AES-GCM', iv },
		key,
		ciphertextWithTag,
	)

	return new TextDecoder().decode(plaintext)
}

/**
 * Generate a high-entropy random link password.
 *
 * Produces a 20-character password from an alphanumeric + symbol alphabet
 * using crypto.getRandomValues (rejection sampling to avoid modulo bias).
 *
 * @return {string} A 20-character random password
 */
export function generateLinkPassword() {
	const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789!@#$%^&*-_=+'
	const length = 20
	const max = Math.floor(256 / alphabet.length) * alphabet.length
	const out = []
	while (out.length < length) {
		const buf = crypto.getRandomValues(new Uint8Array(length))
		for (let i = 0; i < buf.length && out.length < length; i++) {
			if (buf[i] < max) {
				out.push(alphabet[buf[i] % alphabet.length])
			}
		}
	}
	return out.join('')
}
