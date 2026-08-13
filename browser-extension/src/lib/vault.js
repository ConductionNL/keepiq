/**
 * In-worker vault state — the extension's equivalent of the web client's
 * session store (`src/store/modules/session.js`), following the identical unlock
 * sequence (ADR-003 browser-user client-side WebCrypto path):
 *
 *   fetch active suite → decryptPrivateKey(envelope, masterPassword)
 *   → importPrivateKey (NON-EXTRACTABLE) → hold the CryptoKey in memory.
 *
 * The master password, the derived AES key, and the RSA CryptoKey NEVER touch
 * `storage.*` and never leave the worker. Field values are RSA-OAEP decrypted on
 * demand; new/updated secrets are encrypted to the suite certificate.
 *
 * This module holds module-scoped state (the service worker is a singleton). All
 * of it is cleared on lock.
 */

import {
	decryptPrivateKey,
	importPrivateKey,
	importPublicKey,
	rsaDecrypt,
	rsaEncrypt,
} from '../crypto/index.js'
import { fetchActiveSuite } from './api.js'

// Locked state: no key material present.
let cryptoKey = null // non-extractable RSA private key
let publicKey = null // suite certificate public key (for encrypting saves)
let suiteId = null
let idleTimer = null

/** Whether the vault is unlocked (a CryptoKey is held). */
export function isUnlocked() {
	return cryptoKey !== null
}

export function activeSuiteId() {
	return suiteId
}

/**
 * Unlock: fetch the active suite, decrypt its private key with the master
 * password, and import a non-extractable CryptoKey. Returns nothing sensitive.
 *
 * @param {object} config The paired API config
 * @param {string} masterPassword The master password (used only here)
 * @return {Promise<void>}
 */
export async function unlock(config, masterPassword) {
	const suite = await fetchActiveSuite(config)
	const pem = await decryptPrivateKey(suite.privateKey, masterPassword)
	cryptoKey = await importPrivateKey(pem) // extractable: false
	publicKey = await importPublicKey(suite.certificate)
	suiteId = suite.id
}

/** Lock: clear ALL key material and derived state. */
export function lock() {
	cryptoKey = null
	publicKey = null
	suiteId = null
	if (idleTimer) {
		clearTimeout(idleTimer)
		idleTimer = null
	}
}

/**
 * Decrypt one ciphertext field (RSA-OAEP chunked) with the in-memory key.
 * Throws if locked.
 *
 * @param {string} ciphertext base64 chunked ciphertext (or '' → '')
 * @return {Promise<string>}
 */
export async function decryptField(ciphertext) {
	if (!cryptoKey) throw new Error('vault is locked')
	if (!ciphertext) return ''
	return rsaDecrypt(ciphertext, cryptoKey)
}

/**
 * Decrypt the autofill-relevant fields of a secret row.
 *
 * @param {object} secret A blob row ({ key, login, ... })
 * @return {Promise<{ login: string, secret: string }>}
 */
export async function decryptSecret(secret) {
	const [login, value] = await Promise.all([
		decryptField(secret.login || ''),
		decryptField(secret.key || ''),
	])
	return { login, secret: value }
}

/**
 * Encrypt a plaintext value to the suite certificate (for save/update capture).
 * Throws if locked.
 *
 * @param {string} plaintext
 * @return {Promise<string>} base64 chunked ciphertext
 */
export async function encryptField(plaintext) {
	if (!publicKey) throw new Error('vault is locked')
	if (!plaintext) return ''
	return rsaEncrypt(plaintext, publicKey)
}

/**
 * (Re)arm the idle auto-lock timer. Any activity resets it; expiry locks.
 *
 * @param {number} idleMs Idle timeout in ms
 * @return {void}
 */
export function armIdleLock(idleMs) {
	if (idleTimer) clearTimeout(idleTimer)
	if (!idleMs || idleMs <= 0) return
	idleTimer = setTimeout(() => lock(), idleMs)
}
