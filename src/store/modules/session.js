import { defineStore } from 'pinia'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { decryptPrivateKey, importPrivateKey } from '../../crypto/index.js'

const DEFAULT_TIMEOUT = 600000 // 10 minutes

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
