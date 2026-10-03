/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Sealing the raw vault unlock key to a new device's one-time X25519 key
 * (crypto-new-device-approval D3, D4). The server relays the sealed value
 * and cannot open it: only the requesting device holds the private key.
 */

import { decryptPrivateKeyWithRawKey, deriveUnlockKeyRaw } from './aes.js'
import { decodeEnvelope } from './envelope.js'
import { open, seal } from './hpke.js'

/** HPKE info for this purpose, so a sealed key cannot be reused elsewhere. */
export const DEVICE_APPROVAL_INFO = 'keepiq-device-approval-v1'

const encoder = new TextEncoder()

/**
 * @param {Uint8Array} bytes Bytes.
 * @return {string} Base64.
 */
function toBase64(bytes) {
	let binary = ''
	for (const byte of bytes) {
		binary += String.fromCharCode(byte)
	}
	return btoa(binary)
}

/**
 * @param {string} base64 Base64.
 * @return {Uint8Array} Bytes.
 */
export function fromBase64(base64) {
	const binary = atob(base64)
	const bytes = new Uint8Array(binary.length)
	for (let i = 0; i < binary.length; i++) {
		bytes[i] = binary.charCodeAt(i)
	}
	return bytes
}

/**
 * Rebuild the raw unlock key from the master password and check it against
 * the suite envelope, so a wrong password is caught before anything is sent.
 *
 * @param {string} masterPassword The master password, typed just now.
 * @param {string} privateKeyEnvelope The active suite's private-key envelope.
 * @return {Promise<Uint8Array>} The raw unlock key.
 * @spec openspec/changes/crypto-new-device-approval/specs/new-device-approval/spec.md#requirement-approval-seals-the-unlock-key-and-needs-proof-of-the-master-password
 */
export async function unlockKeyFromPassword(masterPassword, privateKeyEnvelope) {
	const { salt } = decodeEnvelope(privateKeyEnvelope)
	const raw = await deriveUnlockKeyRaw(masterPassword, salt)
	// Throws on a wrong password: AES-GCM refuses the envelope.
	await decryptPrivateKeyWithRawKey(privateKeyEnvelope, raw)
	return raw
}

/**
 * Seal the raw unlock key to a request's public key, bound to the request id.
 *
 * @param {Uint8Array} rawUnlockKey The raw unlock key.
 * @param {string} requestPublicKey The request's X25519 public key, base64.
 * @param {string} requestId The request id (the AAD).
 * @return {Promise<string>} The sealed key, base64 JSON `{enc, ciphertext}`.
 * @spec openspec/changes/crypto-new-device-approval/specs/new-device-approval/spec.md#requirement-approval-seals-the-unlock-key-and-needs-proof-of-the-master-password
 */
export async function sealUnlockKey(rawUnlockKey, requestPublicKey, requestId) {
	const { enc, ciphertext } = await seal(
		fromBase64(requestPublicKey),
		encoder.encode(DEVICE_APPROVAL_INFO),
		encoder.encode(requestId),
		rawUnlockKey,
	)
	return btoa(
		JSON.stringify({ enc: toBase64(enc), ciphertext: toBase64(ciphertext) }),
	)
}

/**
 * Open a sealed unlock key with the request's one-time private key.
 *
 * @param {string} sealed The sealed key from the pickup.
 * @param {CryptoKey} privateKey The request's one-time private key.
 * @param {Uint8Array} publicKeyRaw The request's raw public key.
 * @param {string} requestId The request id (the AAD).
 * @return {Promise<Uint8Array>} The raw unlock key.
 * @spec openspec/changes/crypto-new-device-approval/specs/new-device-approval/spec.md#requirement-pickup-is-one-time-and-unlocks-one-session
 */
export async function openUnlockKey(sealed, privateKey, publicKeyRaw, requestId) {
	const { enc, ciphertext } = JSON.parse(atob(sealed))
	return open(
		privateKey,
		publicKeyRaw,
		fromBase64(enc),
		encoder.encode(DEVICE_APPROVAL_INFO),
		encoder.encode(requestId),
		fromBase64(ciphertext),
	)
}

export { toBase64 }
