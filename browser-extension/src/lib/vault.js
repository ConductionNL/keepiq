/**
 * In-worker vault state — the extension's equivalent of the web client's
 * session store (`src/store/modules/session.js`), following the identical unlock
 * sequence (ADR-003 browser-user client-side WebCrypto path):
 *
 *   fetch active suite → decryptPrivateKey(envelope, masterPassword)
 *   → importPrivateKey (NON-EXTRACTABLE) → hold the CryptoKey in memory.
 *
 * or, for a passkey unlock, the raw unlock key unwrapped in the unlock window
 * → decryptPrivateKeyWithRawKey(envelope, rawKey) → the same import.
 *
 * State is kept PER ACCOUNT (extension-account-switching): each paired account
 * has its own key, suite and idle timer, and locks on its own. An OS lock or a
 * worker restart clears every account.
 *
 * The master password, the raw unlock key, the derived AES key, and the RSA
 * CryptoKey NEVER touch `storage.*` and never leave the worker.
 */

import {
	decryptPrivateKey,
	decryptPrivateKeyWithRawKey,
	importPrivateKey,
	importPublicKey,
	rsaDecrypt,
	rsaEncrypt,
} from '../crypto/index.js'
import { fetchActiveSuite } from './api.js'

// accountId → { cryptoKey, publicKey, suiteId, idleTimer }. An account that is
// not in the map is locked: no key material is present for it.
const accounts = new Map()

/**
 * Whether an account is unlocked (a CryptoKey is held for it).
 *
 * @param {string} accountId The account id.
 * @return {boolean}
 */
export function isUnlocked(accountId) {
	return !!accountId && accounts.has(accountId)
}

/**
 * The ids of every unlocked account.
 *
 * @return {string[]}
 */
export function unlockedAccounts() {
	return [...accounts.keys()]
}

/**
 * The suite an account's saves are encrypted to.
 *
 * @param {string} accountId The account id.
 * @return {string|null}
 */
export function activeSuiteId(accountId) {
	return accounts.get(accountId)?.suiteId ?? null
}

/**
 * The unlock-key epoch of the suite an account was unlocked with, or null.
 *
 * @param {string} accountId The account id.
 * @return {number|null}
 * @spec openspec/changes/clients-extension-gaps/specs/extension-lock/spec.md#requirement-a-changed-master-password-locks-the-extension
 */
export function activeSuiteEpoch(accountId) {
	return accounts.get(accountId)?.suiteEpoch ?? null
}

async function hold(accountId, suite, pem) {
	lock(accountId)
	accounts.set(accountId, {
		cryptoKey: await importPrivateKey(pem), // extractable: false
		publicKey: await importPublicKey(suite.certificate),
		suiteId: suite.id,
		// Rises when the master password changes (the key is re-wrapped).
		suiteEpoch: Number.isInteger(suite.unlockKeyEpoch)
			? suite.unlockKeyEpoch
			: null,
		idleTimer: null,
	})
}

/**
 * Unlock one account: fetch its active suite, decrypt the private key with the
 * master password, and import a non-extractable CryptoKey. Returns nothing
 * sensitive.
 *
 * @param {string} accountId The account id
 * @param {object} config The account's API config
 * @param {string} masterPassword The master password (used only here)
 * @return {Promise<void>}
 * @param {{suite?: object}} [options] A suite to use instead of fetching one.
 * @spec openspec/changes/clients-extension-gaps/specs/extension-unlock-and-accounts/spec.md#requirement-unlock-offline-and-say-what-went-wrong
 */
export async function unlock(accountId, config, masterPassword, options = {}) {
	// A suite from the vault snapshot unlocks while the server is away.
	const suite = options.suite || (await fetchActiveSuite(config))
	let pem
	try {
		pem = await decryptPrivateKey(suite.privateKey, masterPassword)
	} catch (e) {
		// AES-GCM refuses a key derived from the wrong password.
		if (e?.name === 'OperationError') {
			throw new Error('Invalid master password')
		}
		throw e
	}
	await hold(accountId, suite, pem)
}

/**
 * Unlock one account with the raw unlock key a passkey unwrapped
 * (extension-biometric-unlock). The raw key is used here and dropped.
 *
 * @param {string} accountId The account id
 * @param {object} config The account's API config
 * @param {Uint8Array} rawKey The raw 32-byte unlock key
 * @return {Promise<void>}
 * @param {{suite?: object}} [options] A suite to use instead of fetching one.
 * @spec openspec/changes/clients-extension-gaps/specs/extension-pin-unlock/spec.md#requirement-unlock-with-a-pin-until-the-browser-closes
 */
export async function unlockWithRawKey(accountId, config, rawKey, options = {}) {
	// A suite from the vault snapshot unlocks while the server is away.
	const suite = options.suite || (await fetchActiveSuite(config))
	const pem = await decryptPrivateKeyWithRawKey(suite.privateKey, rawKey)
	await hold(accountId, suite, pem)
}

/**
 * Lock one account: clear its key material and timer.
 *
 * @param {string} accountId The account id.
 * @return {void}
 */
// Called with an account id whenever that account locks, for whatever
// reason (button, idle timer, OS lock, disconnect).
const lockListeners = new Set()

/**
 * Be told when an account locks.
 *
 * @param {(accountId: string) => void} listener Called with the account id.
 * @return {() => void} Stops the listener.
 */
export function onLock(listener) {
	lockListeners.add(listener)
	return () => lockListeners.delete(listener)
}

export function lock(accountId) {
	const state = accounts.get(accountId)
	if (!state) return
	if (state.idleTimer) clearTimeout(state.idleTimer)
	accounts.delete(accountId)
	for (const listener of lockListeners) {
		try {
			listener(accountId)
		} catch {
			// A listener never stops the lock.
		}
	}
}

/** Lock every account (OS lock, manual lock-all, worker restart). */
export function lockAll() {
	for (const id of [...accounts.keys()]) lock(id)
}

function keyOf(accountId) {
	const state = accounts.get(accountId)
	if (!state) throw new Error('vault is locked')
	return state
}

/**
 * Decrypt one ciphertext field (RSA-OAEP chunked) with an account's key.
 * Throws if that account is locked.
 *
 * @param {string} accountId The account id
 * @param {string} ciphertext base64 chunked ciphertext (or '' → '')
 * @return {Promise<string>}
 */
export async function decryptField(accountId, ciphertext) {
	const { cryptoKey } = keyOf(accountId)
	if (!ciphertext) return ''
	return rsaDecrypt(ciphertext, cryptoKey)
}

/**
 * Decrypt the autofill-relevant fields of a secret row.
 *
 * @param {string} accountId The account id
 * @param {object} secret A blob row ({ key, login, ... })
 * @return {Promise<{ login: string, secret: string }>}
 */
export async function decryptSecret(accountId, secret) {
	const [login, value] = await Promise.all([
		decryptField(accountId, secret.login || ''),
		decryptField(accountId, secret.key || ''),
	])
	return { login, secret: value }
}

/**
 * Encrypt a plaintext value to an account's suite certificate.
 * Throws if that account is locked.
 *
 * @param {string} accountId The account id
 * @param {string} plaintext
 * @return {Promise<string>} base64 chunked ciphertext
 */
export async function encryptField(accountId, plaintext) {
	const { publicKey } = keyOf(accountId)
	if (!plaintext) return ''
	return rsaEncrypt(plaintext, publicKey)
}

/**
 * (Re)arm one account's idle auto-lock timer. Any activity resets it; expiry
 * locks that account only.
 *
 * @param {string} accountId The account id
 * @param {number} idleMs Idle timeout in ms
 * @return {void}
 */
export function armIdleLock(accountId, idleMs) {
	const state = accounts.get(accountId)
	if (!state) return
	if (state.idleTimer) clearTimeout(state.idleTimer)
	state.idleTimer = null
	if (!idleMs || idleMs <= 0) return
	state.idleTimer = setTimeout(() => lock(accountId), idleMs)
}

/**
 * A view of one account with the single-account interface the passkey
 * orchestrator was written against (isUnlocked, decryptField, encryptField,
 * activeSuiteId), bound to whichever account `idOf` names at call time.
 *
 * @param {function(): Promise<string|null>} idOf Resolves the account id.
 * @return {object} The bound vault.
 */
export function boundTo(idOf) {
	return {
		isUnlocked: async () => isUnlocked(await idOf()),
		activeSuiteId: async () => activeSuiteId(await idOf()),
		decryptField: async (ciphertext) => decryptField(await idOf(), ciphertext),
		encryptField: async (plaintext) => encryptField(await idOf(), plaintext),
	}
}
