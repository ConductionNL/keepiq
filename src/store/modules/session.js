import { defineStore } from 'pinia'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { decryptPrivateKey, importPrivateKey } from '../../crypto/index.js'

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
			const activeSuite = suites.find(s => s.status === 'active')

			if (!activeSuite) {
				throw new Error('No active EncryptionSuite found')
			}

			// Decrypt the private key using the master password (all in browser).
			const privateKeyPem = await decryptPrivateKey(
				activeSuite.privateKey,
				masterPassword,
			)

			// Import as non-extractable CryptoKey.
			const cryptoKey = await importPrivateKey(privateKeyPem)

			this.cryptoKey = cryptoKey
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
