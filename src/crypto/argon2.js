/**
 * Argon2id-based key derivation and AES-256-GCM snapshot encryption for
 * link shares.
 *
 * Link share snapshots use a fundamentally different model from the rest
 * of Doriath: instead of RSA with the owner's key pair, the snapshot is
 * encrypted with a symmetric AES-256 key derived from a one-time link
 * password via Argon2id (memory-hard, WASM). The server never sees the
 * password or the derived key — all KDF and AES work happens here.
 *
 * The blob format is intentionally simpler than the private-key envelope
 * (envelope.js): `[12 bytes IV][ciphertext + GCM tag]`, base64-encoded.
 * The Argon2id salt is transmitted separately (and stored in its own DB
 * column) rather than embedded in the blob.
 */

// Fixed Argon2id parameters. These MUST match across creation and access
// so a snapshot encrypted at creation can be decrypted on the public page.
const ARGON2_MEMORY = 65536 // KiB (= 64 MiB)
const ARGON2_ITERATIONS = 3
const ARGON2_PARALLELISM = 1
const ARGON2_HASH_LENGTH = 32 // bytes (256-bit AES key)

const SALT_LENGTH = 16 // bytes
const IV_LENGTH = 12 // bytes

/**
 * Whether the current environment supports WebAssembly (required by the
 * Argon2 WASM binary).
 *
 * @return {boolean} True when WebAssembly is available
 */
export function isArgon2Supported() {
	return typeof WebAssembly === 'object' && typeof WebAssembly.instantiate === 'function'
}

/**
 * Lazily import the argon2-browser library so the ~200KB WASM payload is
 * only fetched when a link share feature is actually used.
 *
 * @return {Promise<object>} The argon2-browser module
 */
async function loadArgon2() {
	// argon2-browser is a declared dependency resolved at build time; the
	// import resolver cannot follow the lazy chunk import in the sandbox.
	const mod = await import(/* webpackChunkName: "argon2" */ 'argon2-browser') // eslint-disable-line import/no-unresolved
	return mod.default || mod
}

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
 * @return {Uint8Array} The decoded bytes
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
 * Derive a 256-bit AES key from a link password and salt via Argon2id.
 *
 * @param {string} password The link password
 * @param {Uint8Array} salt The 16-byte Argon2id salt
 * @return {Promise<Uint8Array>} The 32-byte derived key
 */
export async function deriveAesKeyArgon2id(password, salt) {
	if (!isArgon2Supported()) {
		throw new Error('WebAssembly is not supported in this browser')
	}

	const argon2 = await loadArgon2()
	const result = await argon2.hash({
		pass: password,
		salt,
		time: ARGON2_ITERATIONS,
		mem: ARGON2_MEMORY,
		parallelism: ARGON2_PARALLELISM,
		hashLen: ARGON2_HASH_LENGTH,
		type: argon2.ArgonType.Argon2id,
	})

	return new Uint8Array(result.hash)
}

/**
 * Import a raw 32-byte key as an AES-GCM CryptoKey.
 *
 * @param {Uint8Array} rawKey The 32-byte key
 * @return {Promise<CryptoKey>} The AES-GCM key
 */
async function importAesKey(rawKey) {
	return crypto.subtle.importKey(
		'raw',
		rawKey,
		{ name: 'AES-GCM', length: 256 },
		false,
		['encrypt', 'decrypt'],
	)
}

/**
 * Encrypt a JSON snapshot with a freshly generated salt + IV + Argon2id
 * key.
 *
 * @param {string} jsonString The serialized snapshot
 * @param {string} password The link password
 * @return {Promise<{blob: string, salt: string}>} Base64 blob and salt
 */
export async function encryptSnapshot(jsonString, password) {
	const salt = crypto.getRandomValues(new Uint8Array(SALT_LENGTH))
	const iv = crypto.getRandomValues(new Uint8Array(IV_LENGTH))
	const rawKey = await deriveAesKeyArgon2id(password, salt)
	const key = await importAesKey(rawKey)

	const encoder = new TextEncoder()
	const ciphertext = new Uint8Array(
		await crypto.subtle.encrypt(
			{ name: 'AES-GCM', iv },
			key,
			encoder.encode(jsonString),
		),
	)

	// Blob = [12-byte IV][ciphertext + GCM tag].
	const blob = new Uint8Array(iv.length + ciphertext.length)
	blob.set(iv, 0)
	blob.set(ciphertext, iv.length)

	return { blob: toBase64(blob), salt: toBase64(salt) }
}

/**
 * Decrypt a base64 snapshot blob with the link password and stored salt.
 *
 * Throws when the password is wrong (AES-GCM authentication tag mismatch).
 *
 * @param {string} base64Blob The base64-encoded blob ([IV][ciphertext+tag])
 * @param {string} base64Salt The base64-encoded Argon2id salt
 * @param {string} password The link password
 * @return {Promise<string>} The decrypted JSON snapshot
 */
export async function decryptSnapshot(base64Blob, base64Salt, password) {
	const blob = fromBase64(base64Blob)
	const salt = fromBase64(base64Salt)

	const iv = blob.slice(0, IV_LENGTH)
	const ciphertextWithTag = blob.slice(IV_LENGTH)

	const rawKey = await deriveAesKeyArgon2id(password, salt)
	const key = await importAesKey(rawKey)

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
 * @param {number} length The password length (default 20)
 * @return {string} The generated password
 */
export function generateLinkPassword(length = 20) {
	const alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%^&*-_=+'
	const randomValues = crypto.getRandomValues(new Uint32Array(length))
	let password = ''
	for (let i = 0; i < length; i++) {
		password += alphabet[randomValues[i] % alphabet.length]
	}
	return password
}
