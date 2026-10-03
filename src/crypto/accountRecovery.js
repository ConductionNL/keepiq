/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The browser side of organisation account recovery
 * (crypto-organisation-account-recovery D1 to D4). Every private key here is
 * opened, used and dropped in the browser; the server only ever receives a
 * public key, a wrapped copy, an envelope or a sealed result.
 */

import { fromBase64, toBase64 } from './deviceApproval.js'
import { buildRecoveryEnvelope, openRecoveryEnvelope } from './emergencyEnvelope.js'
import { open, seal } from './hpke.js'
import { generateKeyPair, importPrivateKey } from './rsa.js'

/** HPKE info for the handoff, so a sealed key cannot be reused elsewhere. */
export const ACCOUNT_RECOVERY_INFO = 'keepiq-account-recovery-v1'

const encoder = new TextEncoder()
const decoder = new TextDecoder()

/**
 * PEM-encode PKCS#8 bytes.
 *
 * @param {ArrayBuffer} pkcs8 The private key bytes.
 * @return {string}
 */
function privateKeyPem(pkcs8) {
	const body = btoa(String.fromCharCode(...new Uint8Array(pkcs8)))
		.match(/.{1,64}/g)
		.join('\n')
	return `-----BEGIN PRIVATE KEY-----\n${body}\n-----END PRIVATE KEY-----`
}

/**
 * Create the organisation recovery key in this officer's browser and wrap
 * the private half to every officer's certificate (D2). The returned object
 * holds only the public key and the wrapped copies; the private key is not
 * kept anywhere.
 *
 * @param {Record<string,string>} officerCertificates Officer uid to certificate PEM.
 * @return {Promise<{publicKey: string, copies: Record<string,string>}>}
 * @spec openspec/changes/crypto-organisation-account-recovery/specs/organisation-account-recovery/spec.md#requirement-the-recovery-private-key-is-generated-and-held-by-officers-only
 */
export async function createRecoveryKey(officerCertificates) {
	const pair = await generateKeyPair()
	const pem = privateKeyPem(
		await crypto.subtle.exportKey('pkcs8', pair.privateKey),
	)
	const copies = {}
	for (const [uid, certificate] of Object.entries(officerCertificates)) {
		copies[uid] = await buildRecoveryEnvelope(pem, certificate)
	}
	return { publicKey: pair.publicKeyPem, copies }
}

/**
 * Wrap this user's suite private key to the recovery certificate (D1).
 *
 * @param {string} userPrivateKeyPem The user's private key, decrypted just now.
 * @param {string} recoveryCertificatePem The active recovery certificate.
 * @return {Promise<string>} The enrolment envelope.
 * @spec openspec/changes/crypto-organisation-account-recovery/specs/organisation-account-recovery/spec.md#requirement-users-enrol-by-wrapping-their-own-key-to-the-recovery-certificate
 */
export function buildEnrolmentEnvelope(userPrivateKeyPem, recoveryCertificatePem) {
	return buildRecoveryEnvelope(userPrivateKeyPem, recoveryCertificatePem)
}

/**
 * The officer's handoff (D4): open the officer's own copy of the recovery
 * key with their session key, open the user's enrolment with it, and seal
 * the user's private key to the request key. Nothing is kept.
 *
 * @param {object} material The handoff material from the server.
 * @param {string} material.wrappedRecoveryKey The officer's own copy.
 * @param {string} material.envelope The user's enrolment envelope.
 * @param {string} material.requestPublicKey The request's X25519 key, base64.
 * @param {CryptoKey} officerSessionKey The officer's in-session private key.
 * @param {string} requestId The request id (the AAD).
 * @return {Promise<string>} The sealed result.
 * @spec openspec/changes/crypto-organisation-account-recovery/specs/organisation-account-recovery/spec.md#requirement-the-recovered-key-reaches-only-the-requesting-browser
 */
export async function sealHandoff(material, officerSessionKey, requestId) {
	const recoveryPem = await openRecoveryEnvelope(
		material.wrappedRecoveryKey,
		officerSessionKey,
	)
	const recoveryKey = await importPrivateKey(recoveryPem)
	const userPem = await openRecoveryEnvelope(material.envelope, recoveryKey)
	const { enc, ciphertext } = await seal(
		fromBase64(material.requestPublicKey),
		encoder.encode(ACCOUNT_RECOVERY_INFO),
		encoder.encode(requestId),
		encoder.encode(userPem),
	)
	return btoa(
		JSON.stringify({ enc: toBase64(enc), ciphertext: toBase64(ciphertext) }),
	)
}

/**
 * Open the sealed result in the browser that filed the request.
 *
 * @param {string} sealed The sealed result.
 * @param {CryptoKey} requestPrivateKey The request's one-time private key.
 * @param {Uint8Array} requestPublicKeyRaw The request's raw public key.
 * @param {string} requestId The request id (the AAD).
 * @return {Promise<string>} The user's private key PEM.
 * @spec openspec/changes/crypto-organisation-account-recovery/specs/organisation-account-recovery/spec.md#requirement-the-recovered-key-reaches-only-the-requesting-browser
 */
export async function openHandoff(
	sealed,
	requestPrivateKey,
	requestPublicKeyRaw,
	requestId,
) {
	const { enc, ciphertext } = JSON.parse(atob(sealed))
	const plain = await open(
		requestPrivateKey,
		requestPublicKeyRaw,
		fromBase64(enc),
		encoder.encode(ACCOUNT_RECOVERY_INFO),
		encoder.encode(requestId),
		fromBase64(ciphertext),
	)
	return decoder.decode(plain)
}

/**
 * A one-time X25519 key pair whose private half cannot be exported, so it
 * can be kept in IndexedDB bound to the request (D3).
 *
 * @return {Promise<{privateKey: CryptoKey, publicKeyRaw: Uint8Array}>}
 * @spec openspec/changes/crypto-organisation-account-recovery/specs/organisation-account-recovery/spec.md#requirement-a-recovery-request-carries-a-one-time-key-and-a-verification-phrase
 */
export async function generateRequestKeyPair() {
	const pair = await crypto.subtle.generateKey({ name: 'X25519' }, false, [
		'deriveBits',
	])
	const publicKeyRaw = new Uint8Array(
		await crypto.subtle.exportKey('raw', pair.publicKey),
	)
	return { privateKey: pair.privateKey, publicKeyRaw }
}
