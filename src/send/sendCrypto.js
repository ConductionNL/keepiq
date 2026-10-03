/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Ephemeral-send crypto, shared by the web app and the browser extension.
 *
 * The payload is encrypted AES-256-GCM with a fresh content key. Without a
 * password the raw key rides the URL fragment (`#k=`), which the browser
 * never transmits. Pure module: no network, no framework.
 *
 * @spec openspec/specs/ephemeral-send/spec.md#requirement-create-a-standalone-ephemeral-send
 */

const IV_LENGTH = 12

/**
 * Base64-encode bytes.
 *
 * @param {Uint8Array} bytes The bytes.
 * @return {string}
 */
export function toBase64(bytes) {
	let binary = ''
	for (const b of bytes) {
		binary += String.fromCharCode(b)
	}
	return btoa(binary)
}

/**
 * Base64-decode to bytes.
 *
 * @param {string} base64 The base64 string.
 * @return {Uint8Array}
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
 * URL-fragment-safe base64url encoding.
 *
 * @param {Uint8Array} bytes The bytes.
 * @return {string}
 */
export function toBase64Url(bytes) {
	return toBase64(bytes).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '')
}

/**
 * Decode a base64url string.
 *
 * @param {string} base64url The base64url string.
 * @return {Uint8Array}
 */
export function fromBase64Url(base64url) {
	const base64 = base64url.replace(/-/g, '+').replace(/_/g, '/')
	return fromBase64(base64 + '='.repeat((4 - (base64.length % 4)) % 4))
}

/**
 * AES-256-GCM encrypt with an IV-prefixed base64 result.
 *
 * @param {CryptoKey} key The AES key.
 * @param {Uint8Array} plaintext The plaintext bytes.
 * @return {Promise<string>} base64(IV||ciphertext).
 */
export async function aesEncrypt(key, plaintext) {
	const iv = crypto.getRandomValues(new Uint8Array(IV_LENGTH))
	const ciphertext = new Uint8Array(
		await crypto.subtle.encrypt({ name: 'AES-GCM', iv }, key, plaintext),
	)
	const combined = new Uint8Array(iv.length + ciphertext.length)
	combined.set(iv, 0)
	combined.set(ciphertext, iv.length)
	return toBase64(combined)
}

/**
 * AES-256-GCM decrypt an IV-prefixed base64 blob.
 *
 * @param {CryptoKey} key The AES key.
 * @param {string} blob base64(IV||ciphertext).
 * @return {Promise<Uint8Array>} The plaintext bytes.
 */
export async function aesDecrypt(key, blob) {
	const combined = fromBase64(blob)
	const iv = combined.slice(0, IV_LENGTH)
	const ciphertext = combined.slice(IV_LENGTH)
	return new Uint8Array(
		await crypto.subtle.decrypt({ name: 'AES-GCM', iv }, key, ciphertext),
	)
}

/**
 * Encrypt a send's payload under a fresh content key.
 *
 * @param {string} payload The plaintext.
 * @return {Promise<{encryptedPayload: string, rawKey: Uint8Array}>}
 *   The ciphertext for the server and the raw key, which never goes there.
 * @spec openspec/specs/ephemeral-send/spec.md#requirement-create-a-standalone-ephemeral-send
 */
export async function sealPayload(payload) {
	const contentKey = await crypto.subtle.generateKey(
		{ name: 'AES-GCM', length: 256 },
		true,
		['encrypt', 'decrypt'],
	)
	const rawKey = new Uint8Array(await crypto.subtle.exportKey('raw', contentKey))
	const encryptedPayload = await aesEncrypt(
		contentKey,
		new TextEncoder().encode(payload),
	)
	return { encryptedPayload, rawKey }
}

/**
 * The recipient link for a send. Without a password the content key is
 * appended as a URL fragment; with one, the link carries no key.
 *
 * @param {string} publicBase Origin plus `/…/apps/keepiq/public`.
 * @param {string} token The send token.
 * @param {Uint8Array|null} rawKey The content key, or null for a password send.
 * @return {string}
 * @spec openspec/specs/ephemeral-send/spec.md#requirement-create-a-standalone-ephemeral-send
 */
export function sendLink(publicBase, token, rawKey) {
	const base = `${publicBase}/send/${encodeURIComponent(token)}`
	return rawKey ? `${base}#k=${toBase64Url(rawKey)}` : base
}
