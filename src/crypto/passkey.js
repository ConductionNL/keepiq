/**
 * WebAuthn PRF passkey vault-unlock crypto (passkey-vault-login §3).
 *
 * Derives an AES-256 key-encryption key (KEK) from an authenticator's
 * PRF output and wraps/unwraps the vault unlock key under it. The PRF
 * secret never leaves the authenticator; the server only ever holds the
 * wrapped envelope. No new algorithm — WebCrypto HKDF-SHA256 for the KEK
 * and AES-256-GCM for the envelope, matching src/crypto/envelope.js's
 * posture.
 *
 * @spec openspec/changes/passkey-vault-login/specs/passkey-vault-login/spec.md#requirement-prf-derived-unlock-key
 */

const HKDF_INFO = new TextEncoder().encode('doriath-passkey-kek-v1')
const IV_LENGTH = 12

/**
 * Whether WebAuthn (and therefore a possible PRF path) is present. PRF
 * itself is authenticator-specific and probed during the ceremony; this
 * only gates whether the passkey option is offered at all.
 *
 * @return {boolean}
 */
export function isPrfSupported() {
	return typeof window !== 'undefined'
		&& typeof window.PublicKeyCredential !== 'undefined'
		&& typeof navigator !== 'undefined'
		&& !!navigator.credentials
}

/**
 * Base64 encode bytes.
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
 * Base64 decode to bytes.
 *
 * @param {string} b64 Base64.
 * @return {Uint8Array} The bytes.
 */
function fromBase64(b64) {
	return Uint8Array.from(atob(b64), c => c.charCodeAt(0))
}

/**
 * Derive the AES-256-GCM KEK from a PRF output, salted by the
 * credential id so two authenticators never share a KEK.
 *
 * @param {ArrayBuffer|Uint8Array} prfOutput The authenticator PRF result.
 * @param {string} credentialId The base64url credential id (HKDF salt).
 * @return {Promise<CryptoKey>} The KEK (usages: encrypt, decrypt).
 */
export async function deriveKekFromPrf(prfOutput, credentialId) {
	const ikm = prfOutput instanceof Uint8Array ? prfOutput : new Uint8Array(prfOutput)
	const baseKey = await crypto.subtle.importKey('raw', ikm, 'HKDF', false, ['deriveKey'])
	return crypto.subtle.deriveKey(
		{
			name: 'HKDF',
			hash: 'SHA-256',
			salt: new TextEncoder().encode(credentialId),
			info: HKDF_INFO,
		},
		baseKey,
		{ name: 'AES-GCM', length: 256 },
		false,
		['encrypt', 'decrypt'],
	)
}

/**
 * Wrap the raw vault unlock key under the KEK, producing the envelope
 * stored server-side: base64([12-byte IV][ciphertext+GCM tag]).
 *
 * @param {CryptoKey} kek The PRF-derived KEK.
 * @param {Uint8Array} rawUnlockKey The raw 32-byte vault unlock key.
 * @return {Promise<string>} The base64 envelope.
 */
export async function wrapUnlockKey(kek, rawUnlockKey) {
	const iv = crypto.getRandomValues(new Uint8Array(IV_LENGTH))
	const ciphertext = new Uint8Array(
		await crypto.subtle.encrypt({ name: 'AES-GCM', iv }, kek, rawUnlockKey),
	)
	const framed = new Uint8Array(iv.length + ciphertext.length)
	framed.set(iv, 0)
	framed.set(ciphertext, iv.length)
	return toBase64(framed)
}

/**
 * Unwrap the raw vault unlock key from an envelope with the KEK.
 *
 * @param {CryptoKey} kek The PRF-derived KEK.
 * @param {string} envelope The base64 envelope from {@link wrapUnlockKey}.
 * @return {Promise<Uint8Array>} The raw 32-byte vault unlock key.
 * @throws {Error} When the KEK is wrong or the envelope is tampered (GCM auth).
 */
export async function unwrapUnlockKey(kek, envelope) {
	const framed = fromBase64(envelope)
	if (framed.length <= IV_LENGTH) {
		throw new Error('Passkey envelope too short')
	}
	const iv = framed.slice(0, IV_LENGTH)
	const ciphertext = framed.slice(IV_LENGTH)
	const raw = await crypto.subtle.decrypt({ name: 'AES-GCM', iv }, kek, ciphertext)
	return new Uint8Array(raw)
}

/**
 * base64url encode an ArrayBuffer (WebAuthn id encoding).
 *
 * @param {ArrayBuffer} buffer The buffer.
 * @return {string} base64url.
 */
export function toBase64Url(buffer) {
	return toBase64(new Uint8Array(buffer)).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '')
}

/**
 * base64url decode to a Uint8Array (WebAuthn id/challenge decoding).
 *
 * @param {string} value base64url.
 * @return {Uint8Array} The bytes.
 */
export function fromBase64Url(value) {
	const b64 = value.replace(/-/g, '+').replace(/_/g, '/') + '='.repeat((4 - (value.length % 4)) % 4)
	return fromBase64(b64)
}
