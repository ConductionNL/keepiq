import { defineStore } from 'pinia'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { generateKeyPair, encryptPrivateKey, decryptPrivateKey, importPrivateKey } from '../../crypto/index.js'
import { useSessionStore } from './session.js'

export const useEncryptionSuiteStore = defineStore('encryptionSuite', {
	state: () => ({
		/** @type {object|null} Current active suite */
		currentSuite: null,
		/** @type {object|null} Migration status */
		migrationStatus: null,
		/** @type {boolean} */
		loading: false,
	}),

	actions: {
		/**
		 * Fetch the current user's active suite.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-7
		 */
		async fetchSuite() {
			this.loading = true
			try {
				const response = await axios.get(
					generateUrl('/apps/doriath/api/v1/suites'),
				)
				const suites = response.data
				this.currentSuite = suites.find(s => s.status === 'active') || null
			} finally {
				this.loading = false
			}
		},

		/**
		 * Create a new EncryptionSuite (first-time setup).
		 *
		 * @param {string} masterPassword
		 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-7
		 */
		async createSuite(masterPassword) {
			this.loading = true
			try {
				const { publicKeyPem, privateKey } = await generateKeyPair()

				// Export private key as PEM for encryption.
				const pkcs8 = await crypto.subtle.exportKey('pkcs8', privateKey)
				const privateKeyPem = '-----BEGIN PRIVATE KEY-----\n'
					+ btoa(String.fromCharCode(...new Uint8Array(pkcs8))).match(/.{1,64}/g).join('\n')
					+ '\n-----END PRIVATE KEY-----'

				const encryptedPk = await encryptPrivateKey(privateKeyPem, masterPassword)

				const response = await axios.post(
					generateUrl('/apps/doriath/api/v1/suites'),
					{
						publicKey: publicKeyPem,
						encryptedPrivateKey: encryptedPk,
					},
				)

				this.currentSuite = response.data

				// Immediately unlock the session.
				const session = useSessionStore()
				const cryptoKey = await importPrivateKey(privateKeyPem)
				session.cryptoKey = cryptoKey
				session.encryptedPrivateKey = encryptedPk
				session.certificate = response.data.certificate
				session.suiteId = response.data.id
				session.lastActivity = Date.now()
			} finally {
				this.loading = false
			}
		},

		/**
		 * Change master password (routine — re-wrap private key only).
		 *
		 * @param {string} oldPassword
		 * @param {string} newPassword
		 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-7
		 */
		async changePassword(oldPassword, newPassword) {
			const session = useSessionStore()

			// Decrypt private key with old password.
			const privateKeyPem = await decryptPrivateKey(
				session.encryptedPrivateKey,
				oldPassword,
			)

			// Re-encrypt with new password.
			const newEncryptedPk = await encryptPrivateKey(privateKeyPem, newPassword)

			// Update on server.
			await axios.put(
				generateUrl(`/apps/doriath/api/v1/suites/${session.suiteId}/private-key`),
				{ encryptedPrivateKey: newEncryptedPk },
			)

			session.encryptedPrivateKey = newEncryptedPk
		},

		/**
		 * Initiate compromise recovery (full key rotation).
		 *
		 * @param {string} oldPassword
		 * @param {string} newPassword
		 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-7
		 */
		async initiateCompromiseRecovery(oldPassword, newPassword) {
			const { publicKeyPem, privateKey } = await generateKeyPair()

			// Export new private key as PEM.
			const pkcs8 = await crypto.subtle.exportKey('pkcs8', privateKey)
			const newPrivateKeyPem = '-----BEGIN PRIVATE KEY-----\n'
				+ btoa(String.fromCharCode(...new Uint8Array(pkcs8))).match(/.{1,64}/g).join('\n')
				+ '\n-----END PRIVATE KEY-----'

			const newEncryptedPk = await encryptPrivateKey(newPrivateKeyPem, newPassword)

			const response = await axios.post(
				generateUrl('/apps/doriath/api/v1/suites/compromise-recovery'),
				{
					publicKey: publicKeyPem,
					encryptedPrivateKey: newEncryptedPk,
				},
			)

			this.migrationStatus = response.data.migration
			this.currentSuite = response.data.newSuite

			// Suite rotation invalidates every cached ciphertext (encrypted to
			// the now-dead key) — evict the offline snapshot (offline-readonly-
			// cache §D4). Lazy import avoids a static store cycle.
			try {
				const { useOfflineStore } = await import('./offline.js')
				await useOfflineStore().evict()
			} catch (e) {
				// Offline cache absent — nothing to evict.
			}

			// If there are no secrets to migrate, complete the migration immediately.
			// When secrets exist, the browser will decrypt/re-encrypt each one
			// and then call completeMigration.
			const migrationId = response.data.migration.id
			await axios.post(
				generateUrl(`/apps/doriath/api/v1/migrations/${migrationId}/complete`),
				{ hasErrors: false },
			)
			await this.fetchMigrationStatus()

			return {
				migration: response.data.migration,
				oldEncryptedPrivateKey: response.data.oldEncryptedPrivateKey,
				oldPassword,
				newPrivateKey: privateKey,
			}
		},

		/**
		 * Revoke the current user's active encryption suite.
		 *
		 * @param {string} reason The reason for revocation
		 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-7
		 */
		async revokeSuite(reason) {
			if (!this.currentSuite) {
				throw new Error('No active suite to revoke')
			}

			const response = await axios.post(
				generateUrl(`/apps/doriath/api/v1/suites/${this.currentSuite.id}/revoke`),
				{ reason },
			)

			this.currentSuite = response.data

			// A revoked suite must not leave a readable offline copy behind.
			try {
				const { useOfflineStore } = await import('./offline.js')
				await useOfflineStore().evict()
			} catch (e) {
				// Offline cache absent — nothing to evict.
			}
		},

		/**
		 * Check migration status.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-7
		 */
		async fetchMigrationStatus() {
			try {
				const response = await axios.get(
					generateUrl('/apps/doriath/api/v1/migrations/status'),
				)
				this.migrationStatus = response.data.status === 'none' ? null : response.data
			} catch {
				this.migrationStatus = null
			}
		},
	},
})
