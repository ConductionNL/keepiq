import { defineStore } from 'pinia'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { decryptPrivateKey, importPrivateKey } from '../../crypto/index.js'
import { deriveAesKey, decryptPrivateKeyWithRawKey } from '../../crypto/aes.js'
import { decodeEnvelope } from '../../crypto/envelope.js'

const DEFAULT_TIMEOUT = 600000 // 10 minutes

/**
 * Lock-time hooks invoked when the vault locks. The password-health store
 * registers its `reset` here so locking discards all derived health state +
 * terminates the worker, without a static circular import.
 *
 * @type {Array<Function>}
 */
const lockHooks = []

/**
 * Register a callback to run when the vault locks (e.g. health-store reset).
 *
 * @param {Function} fn The lock callback.
 * @return {void}
 * @spec openspec/changes/password-health/specs/password-health/spec.md#requirement-client-side-health-analysis
 */
export function onVaultLock(fn) {
	if (typeof fn === 'function' && !lockHooks.includes(fn)) {
		lockHooks.push(fn)
	}
}

export const useSessionStore = defineStore('session', {
	state: () => ({
		/** @type {CryptoKey|null} RSA private key (extractable: false) */
		cryptoKey: null,
		/** @type {CryptoKey|null} AES key derived from master password */
		aesKey: null,
		/** @type {number} Session timeout in ms */
		timeout: DEFAULT_TIMEOUT,
		/** @type {number} Last activity timestamp */
		lastActivity: Date.now(),
		/** @type {string|null} Encrypted private key blob from server */
		encryptedPrivateKey: null,
		/** @type {string|null} Public certificate PEM */
		certificate: null,
		/** @type {string|null} Suite ID */
		suiteId: null,
	}),

	getters: {
		isLocked: (state) => state.cryptoKey === null,
	},

	actions: {
		/**
		 * Unlock the vault with the master password.
		 *
		 * @param {string} masterPassword
		 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-7
		 */
		async unlock(masterPassword) {
			// Fetch the user's encryption suite from the API.
			const response = await axios.get(
				generateUrl('/apps/doriath/api/v1/suites'),
			)
			const suites = response.data
			const activeSuite = suites.find((s) => s.status === 'active')

			if (!activeSuite) {
				throw new Error('No active EncryptionSuite found')
			}

			await this.unlockFromBlob({
				privateKeyEnvelope: activeSuite.privateKey,
				certificate: activeSuite.certificate,
				suiteId: activeSuite.id,
				masterPassword,
			})
		},

		/**
		 * Shared unlock from a private-key envelope — used by both the online
		 * path (suite fetched from the API) and the offline path (suite blob
		 * read from the IndexedDB snapshot). Cryptographically identical: same
		 * master-password KDF, same non-extractable CryptoKey. Also derives the
		 * vault unlock AES key (offline-readonly-cache §2.2 / D1) used to
		 * encrypt/decrypt cached plaintext metadata at rest.
		 *
		 * @param {object} params The unlock inputs.
		 * @param {string} params.privateKeyEnvelope The AES-wrapped private-key envelope.
		 * @param {string} params.certificate The suite certificate PEM.
		 * @param {string} params.suiteId The suite id.
		 * @param {string} params.masterPassword The master password (never leaves the browser).
		 * @return {Promise<void>}
		 * @spec openspec/changes/offline-readonly-cache/specs/offline-readonly-cache/spec.md#requirement-offline-unlock
		 */
		async unlockFromBlob({
			privateKeyEnvelope,
			certificate,
			suiteId,
			masterPassword,
		}) {
			// Decrypt the private key using the master password (all in browser).
			const privateKeyPem = await decryptPrivateKey(
				privateKeyEnvelope,
				masterPassword,
			)

			// Import as non-extractable CryptoKey.
			const cryptoKey = await importPrivateKey(privateKeyPem)

			// Derive the vault unlock AES key from the SAME salt the private-key
			// envelope carries — this key encrypts cached metadata at rest.
			const { salt } = decodeEnvelope(privateKeyEnvelope)
			this.aesKey = await deriveAesKey(masterPassword, salt)

			this.cryptoKey = cryptoKey
			this.encryptedPrivateKey = privateKeyEnvelope
			this.certificate = certificate
			this.suiteId = suiteId
			this.lastActivity = Date.now()
		},

		/**
		 * Unlock the vault from a raw unlock-key recovered via a passkey PRF
		 * envelope (passkey-vault-login §unlock steps 7-8). Reaches the exact
		 * same end-state as a master-password unlock — a non-extractable RSA
		 * CryptoKey in memory — with no master password involved.
		 *
		 * @param {Uint8Array} rawUnlockKey The recovered raw vault unlock key.
		 * @return {Promise<void>}
		 * @spec openspec/specs/passkey-vault-login/spec.md#requirement-passwordless-unlock-derives-the-unlock-key-client-side
		 */
		async unlockWithRawKey(rawUnlockKey) {
			const response = await axios.get(
				generateUrl('/apps/doriath/api/v1/suites'),
			)
			const activeSuite = response.data.find((s) => s.status === 'active')
			if (!activeSuite) {
				throw new Error('No active EncryptionSuite found')
			}

			const privateKeyPem = await decryptPrivateKeyWithRawKey(
				activeSuite.privateKey,
				rawUnlockKey,
			)
			this.cryptoKey = await importPrivateKey(privateKeyPem)
			// The raw unlock key IS the AES metadata key (offline cache §D1).
			this.aesKey = await crypto.subtle.importKey(
				'raw',
				rawUnlockKey,
				{ name: 'AES-GCM' },
				false,
				['encrypt', 'decrypt'],
			)
			this.encryptedPrivateKey = activeSuite.privateKey
			this.certificate = activeSuite.certificate
			this.suiteId = activeSuite.id
			this.lastActivity = Date.now()
		},

		/**
		 * Lock the vault — clear all keys from memory.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-7
		 */
		lock() {
			this.cryptoKey = null
			this.aesKey = null
			this.encryptedPrivateKey = null
			this.certificate = null
			this.suiteId = null
			// Discard all derived health state + terminate the worker so no
			// score/digest/finding survives a locked vault (password-health D2).
			for (const hook of lockHooks) {
				try {
					hook()
				} catch {
					// A failing lock hook must never block the lock itself.
				}
			}
		},

		/**
		 * Check if the session has timed out.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-7
		 */
		checkTimeout() {
			if (this.cryptoKey === null) {
				return
			}

			if (Date.now() - this.lastActivity > this.timeout) {
				this.lock()
			}
		},

		/**
		 * Update last activity timestamp.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-7
		 */
		updateActivity() {
			this.lastActivity = Date.now()
		},
	},
})
