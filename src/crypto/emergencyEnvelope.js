/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Client-side recovery-envelope crypto for emergency access (add-emergency-access
 * design D1/D2). The grantor's EncryptionSuite private key is hybrid-encrypted so
 * that ONLY the grantee can open it: a fresh random AES-256-GCM key encrypts the
 * PKCS8 private-key PEM, and that AES key is RSA-OAEP encrypted to the grantee's
 * public certificate (the same encrypt-to-recipient primitive user sharing uses,
 * applied to a private key because a 4096-bit key exceeds one RSA-OAEP block).
 *
 * Everything here runs in the browser. The raw private key exists only
 * transiently while the envelope is built and is discarded afterwards; only the
 * grantee-encrypted envelope ciphertext is ever sent to the server, which never
 * receives a usable key (ADR-003 zero-knowledge).
 */

import { importPublicKey, rsaDecrypt, rsaEncrypt } from './index.js'

/** The envelope format version + algorithm tag. */
export const ENVELOPE_VERSION = 1
export const ENVELOPE_ALG = 'RSA-OAEP+AES-256-GCM'

/**
 * Base64-encode a byte array.
 *
 * @param {Uint8Array} bytes The bytes.
 * @return {string} The base64 string.
 */
function toBase64(bytes) {
	return btoa(String.fromCharCode(...bytes))
}

/**
 * Decode a base64 string into bytes.
 *
 * @param {string} b64 The base64 string.
 * @return {Uint8Array} The bytes.
 */
function fromBase64(b64) {
	return Uint8Array.from(atob(b64), (c) => c.charCodeAt(0))
}

/**
 * Build the grantee-encrypted recovery envelope from the grantor's private key.
 *
 * @param {string} privateKeyPem        The grantor's PKCS8 private-key PEM.
 * @param {string} granteeCertificatePem The grantee's public certificate/key PEM.
 * @return {Promise<string>} The serialized envelope (JSON string) — only this
 *   ciphertext is sent to the server; the raw private key never leaves the browser.
 */
export async function buildRecoveryEnvelope(privateKeyPem, granteeCertificatePem) {
	if (!privateKeyPem || !granteeCertificatePem) {
		throw new Error('A private key and a grantee certificate are required')
	}

	// Fresh random symmetric key + IV for this envelope.
	const aesKey = await crypto.subtle.generateKey(
		{ name: 'AES-GCM', length: 256 },
		true,
		['encrypt', 'decrypt'],
	)
	const iv = crypto.getRandomValues(new Uint8Array(12))

	const ctBuffer = await crypto.subtle.encrypt(
		{ name: 'AES-GCM', iv },
		aesKey,
		new TextEncoder().encode(privateKeyPem),
	)

	// RSA-wrap the raw AES key to the grantee's public certificate.
	const rawAes = new Uint8Array(await crypto.subtle.exportKey('raw', aesKey))
	const granteePublicKey = await importPublicKey(granteeCertificatePem)
	const encKey = await rsaEncrypt(toBase64(rawAes), granteePublicKey)

	return JSON.stringify({
		v: ENVELOPE_VERSION,
		alg: ENVELOPE_ALG,
		encKey,
		iv: toBase64(iv),
		ct: toBase64(new Uint8Array(ctBuffer)),
	})
}

/**
 * Open a recovery envelope with the grantee's OWN private key, recovering the
 * grantor's private-key PEM in the grantee's browser.
 *
 * @param {string}    envelopeJson      The serialized envelope.
 * @param {CryptoKey} granteePrivateKey The grantee's in-session RSA private key.
 * @return {Promise<string>} The grantor's PKCS8 private-key PEM.
 */
export async function openRecoveryEnvelope(envelopeJson, granteePrivateKey) {
	const envelope =
		typeof envelopeJson === 'string' ? JSON.parse(envelopeJson) : envelopeJson
	if (
		!envelope
		|| envelope.v !== ENVELOPE_VERSION
		|| !envelope.encKey
		|| !envelope.iv
		|| !envelope.ct
	) {
		throw new Error('Malformed recovery envelope')
	}

	// Recover the symmetric key with the grantee's own private key.
	const rawAesB64 = await rsaDecrypt(envelope.encKey, granteePrivateKey)
	const rawAes = fromBase64(rawAesB64)
	const aesKey = await crypto.subtle.importKey(
		'raw',
		rawAes,
		{ name: 'AES-GCM' },
		false,
		['decrypt'],
	)

	const ptBuffer = await crypto.subtle.decrypt(
		{ name: 'AES-GCM', iv: fromBase64(envelope.iv) },
		aesKey,
		fromBase64(envelope.ct),
	)

	return new TextDecoder().decode(ptBuffer)
}
