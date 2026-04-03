import { defineStore } from 'pinia'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { encryptPrivateKey, decryptPrivateKey, importPrivateKey, importPublicKey, rsaEncrypt, rsaDecrypt } from '../../crypto/index.js'
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
		 */
		async createSuite(masterPassword) {
			this.loading = true
			try {
				// Phase 1: Server generates the key pair, signs the certificate,
				// and returns the private key encrypted with a temporary passphrase.
				const response = await axios.post(
					generateUrl('/apps/doriath/api/v1/suites'),
				)

				const { suite, encryptedPrivateKey, passphrase, publicKeyPem } = response.data

				// Decrypt the private key PEM using the temporary passphrase.
				// OpenSSL PEM encryption uses a different format than our AES-GCM envelope,
				// but the PEM itself is a standard PKCS#8 encrypted PEM that we can decrypt
				// by passing it through openssl. However, WebCrypto can't decrypt OpenSSL
				// PEM encryption directly. We use a workaround: the server encrypted with
				// openssl_pkey_export(passphrase:), which produces PKCS#8 encrypted PEM.
				// We need to import this as a CryptoKey using the passphrase.

				// Import the passphrase-encrypted PKCS#8 private key.
				// WebCrypto doesn't support PKCS#8 encrypted PEM import directly.
				// Instead, we'll use the server's EncryptService format: ask the server
				// to provide the private key encrypted with our AES-GCM envelope format
				// using the passphrase as the password.

				// Actually: the passphrase + encrypted PK use our standard AES-GCM envelope.
				// The server used EncryptService::encryptPrivateKey(pem, passphrase).
				// We can decrypt with our standard decryptPrivateKey(blob, passphrase).
				const privateKeyPem = await decryptPrivateKey(encryptedPrivateKey, passphrase)

				// Import as non-extractable CryptoKey.
				const cryptoKey = await importPrivateKey(privateKeyPem)

				// Re-encrypt the private key with the master password (our AES-GCM envelope).
				const masterEncryptedPk = await encryptPrivateKey(privateKeyPem, masterPassword)

				// Phase 2: Send the master-password-encrypted blob back to the server.
				await axios.put(
					generateUrl(`/apps/doriath/api/v1/suites/${suite.id}/private-key`),
					{ encryptedPrivateKey: masterEncryptedPk },
				)

				this.currentSuite = suite

				// Set up the session.
				const session = useSessionStore()
				session.cryptoKey = cryptoKey
				session.encryptedPrivateKey = masterEncryptedPk
				session.certificate = publicKeyPem
				session.suiteId = suite.id
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
		 */
		async initiateCompromiseRecovery(oldPassword, newPassword) {
			// Phase 1: Server generates new key pair, signs cert, returns
			// encrypted PK + temp passphrase. Also marks old suite compromised.
			const response = await axios.post(
				generateUrl('/apps/doriath/api/v1/suites/compromise-recovery'),
			)

			const { newSuite, encryptedPrivateKey, passphrase, publicKeyPem, migration } = response.data

			// Decrypt the new private key with the temp passphrase.
			const privateKeyPem = await decryptPrivateKey(encryptedPrivateKey, passphrase)

			// Import as non-extractable CryptoKey.
			const cryptoKey = await importPrivateKey(privateKeyPem)

			// Re-encrypt with the new master password.
			const masterEncryptedPk = await encryptPrivateKey(privateKeyPem, newPassword)

			// Phase 2: Send the master-password-encrypted blob back.
			await axios.put(
				generateUrl(`/apps/doriath/api/v1/suites/${newSuite.id}/private-key`),
				{ encryptedPrivateKey: masterEncryptedPk },
			)

			this.migrationStatus = migration
			this.currentSuite = newSuite

			// Update session with the new keys.
			const session = useSessionStore()
			session.cryptoKey = cryptoKey
			session.encryptedPrivateKey = masterEncryptedPk
			session.certificate = publicKeyPem
			session.suiteId = newSuite.id
			session.lastActivity = Date.now()

			// Migrate all secrets: decrypt with old private key, re-encrypt with new public key.
			const oldEncryptedPk = response.data.oldEncryptedPrivateKey
			let hasErrors = false

			if (oldEncryptedPk) {
				try {
					// Decrypt the old private key with the old master password.
					const oldPrivateKeyPem = await decryptPrivateKey(oldEncryptedPk, oldPassword)
					const oldCryptoKey = await importPrivateKey(oldPrivateKeyPem)

					// Import the new public key for re-encryption.
					const newPublicKey = await importPublicKey(publicKeyPem)

					// Fetch all secrets (they're still encrypted with the old suite).
					const secretsResponse = await axios.get(
						generateUrl('/apps/doriath/api/v1/secrets'),
						{ params: { limit: 9999 } },
					)
					const secrets = secretsResponse.data.results ?? []

					for (const secret of secrets) {
						try {
							// Fetch the full secret with encrypted blobs (migration endpoint
							// bypasses the suite status check).
							const detailResp = await axios.get(
								generateUrl(`/apps/doriath/api/v1/secrets/${secret.id}/for-migration`),
							)
							const detail = detailResp.data

							// Decrypt with old key, re-encrypt with new key.
							const migratedData = { newSuiteId: newSuite.id }

							if (detail.key) {
								const plainKey = await rsaDecrypt(detail.key, oldCryptoKey)
								migratedData.key = await rsaEncrypt(plainKey, newPublicKey)
							}
							if (detail.login) {
								const plainLogin = await rsaDecrypt(detail.login, oldCryptoKey)
								migratedData.login = await rsaEncrypt(plainLogin, newPublicKey)
							}
							if (detail.additionalFields) {
								const plainAf = await rsaDecrypt(detail.additionalFields, oldCryptoKey)
								migratedData.additionalFields = await rsaEncrypt(plainAf, newPublicKey)
							}

							// Send re-encrypted data to server.
							await axios.put(
								generateUrl(`/apps/doriath/api/v1/secrets/${secret.id}/migrate`),
								migratedData,
							)
						} catch (e) {
							console.error(`Doriath: Failed to migrate secret ${secret.id}`, e)
							hasErrors = true
						}
					}
				} catch (e) {
					console.error('Doriath: Failed to decrypt old private key for migration', e)
					hasErrors = true
				}
			}

			// Complete the migration.
			await axios.post(
				generateUrl(`/apps/doriath/api/v1/migrations/${migration.id}/complete`),
				{ hasErrors },
			)
			await this.fetchMigrationStatus()

			// Refresh the secret list so compromised flags are visible.
			try {
				const { useSecretStore } = await import('./secret.js')
				const secretStore = useSecretStore()
				await secretStore.fetchSecrets()
			} catch {
				// Secret store may not be initialized yet — that's fine.
			}

			return { migration, hasErrors }
		},

		/**
		 * Revoke the current user's active encryption suite.
		 *
		 * @param {string} reason The reason for revocation
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
		},

		/**
		 * Check migration status.
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
