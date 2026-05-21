import { defineStore } from 'pinia'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { rsaEncrypt, rsaDecrypt, importPublicKey } from '../../crypto/rsa.js'
import { useSessionStore } from './session.js'

export const useSecretStore = defineStore('secret', {
	state: () => ({
		/** @type {object[]} List of secrets (metadata only) */
		secrets: [],
		/** @type {object|null} Currently viewed/decrypted secret */
		currentSecret: null,
		/** @type {number} Total number of secrets (for pagination) */
		totalCount: 0,
		/** @type {boolean} Loading state for list operations */
		loading: false,
		/** @type {boolean} Loading state for sidebar/detail */
		detailLoading: false,
		/** @type {object} Active filters */
		filters: {},
		/** @type {string|null} Sort field (null = server default) */
		sort: null,
		/** @type {string|null} Sort direction (null = server default) */
		direction: null,
		/** @type {number} Current page (1-indexed) */
		page: 1,
		/** @type {boolean} Whether the sidebar should open in edit mode */
		editRequested: false,
		/** @type {string|null} Active folder filter for the list */
		currentFolderId: null,
		/** @type {boolean} Whether the list is showing root-only secrets */
		currentRootOnly: false,
	}),

	actions: {
		/**
		 * Fetch a paginated list of secrets.
		 *
		 * @param {string|null} folderId Optional folder to filter by
		 * @param {boolean} rootOnly if it should get the root folder or not
		 * @return {Promise<void>}
		 */
		async fetchSecrets(folderId = null, rootOnly = false) {
			this.currentFolderId = folderId
			this.currentRootOnly = rootOnly
			this.loading = true
			try {
				const params = {
					page: this.page,
					limit: 50,
				}
				if (this.sort) {
					params.sort = this.sort
					params.direction = this.direction ?? 'ASC'
				}
				if (rootOnly) {
					params.folderId = 'root'
				} else if (folderId) {
					params.folderId = folderId
				}
				const response = await axios.get(
					generateUrl('/apps/doriath/api/v1/secrets'),
					{ params },
				)
				this.secrets = response.data.results ?? []
				this.totalCount = response.data.total ?? this.secrets.length
			} finally {
				this.loading = false
			}
		},

		/**
		 * Re-fetch secrets using the most recently applied filter context.
		 * Use this from components that need to refresh the list but don't
		 * own the filter state (e.g. the sidebar).
		 *
		 * @return {Promise<void>}
		 */
		async refetchSecrets() {
			await this.fetchSecrets(this.currentFolderId, this.currentRootOnly)
		},

		/**
		 * Fetch and decrypt a single secret by ID.
		 *
		 * @param {string} id Secret ID
		 * @return {Promise<void>}
		 */
		async fetchSecret(id) {
			this.detailLoading = true
			try {
				const response = await axios.get(
					generateUrl(`/apps/doriath/api/v1/secrets/${id}`),
				)
				const secret = { ...response.data }

				// Show sidebar immediately with metadata.
				this.currentSecret = { ...secret }

				// Attempt client-side decryption.
				const session = useSessionStore()
				console.debug('Doriath: cryptoKey present:', !!session.cryptoKey, 'key blob length:', secret.key?.length)
				if (session.cryptoKey) {
					try {
						if (secret.key) {
							console.debug('Doriath: Decrypting key field...')
							secret.key = await rsaDecrypt(secret.key, session.cryptoKey)
							console.debug('Doriath: Key decrypted successfully')
						}
						if (secret.login) {
							console.debug('Doriath: Decrypting login field...')
							secret.login = await rsaDecrypt(secret.login, session.cryptoKey)
							console.debug('Doriath: Login decrypted successfully')
						}
						if (secret.additionalFields && typeof secret.additionalFields === 'string') {
							console.debug('Doriath: Decrypting additionalFields...')
							const plaintext = await rsaDecrypt(secret.additionalFields, session.cryptoKey)
							try {
								secret.additionalFields = JSON.parse(plaintext)
							} catch (e) {
								console.warn('Doriath: additionalFields decrypted but not valid JSON; keeping raw string', e)
								secret.additionalFields = plaintext
							}
						}
						this.currentSecret = { ...secret }
					} catch (e) {
						console.error('Doriath: Failed to decrypt secret fields', e)
					}
				} else {
					console.warn('Doriath: No cryptoKey in session, cannot decrypt')
				}
			} finally {
				this.detailLoading = false
			}
		},

		/**
		 * Create a new secret, encrypting sensitive fields first.
		 *
		 * @param {object} data Secret data including key, login, additionalFields
		 * @return {Promise<object>} The created secret
		 */
		async createSecret(data) {
			const session = useSessionStore()
			const payload = { ...data }

			console.debug('Doriath createSecret: certificate present:', !!session.certificate, 'cryptoKey present:', !!session.cryptoKey)
			if (session.certificate) {
				const publicKey = await importPublicKey(session.certificate)

				// Round-trip test: encrypt then immediately decrypt to verify key pair match.
				try {
					const testBlob = await rsaEncrypt('round-trip-test', publicKey)
					const testResult = await rsaDecrypt(testBlob, session.cryptoKey)
					console.debug('Doriath createSecret: round-trip test passed:', testResult)
				} catch (e) {
					console.error('Doriath createSecret: ROUND-TRIP TEST FAILED — public key does not match private key!', e)
				}

				if (payload.key) {
					payload.key = await rsaEncrypt(payload.key, publicKey)
				}
				if (payload.login) {
					payload.login = await rsaEncrypt(payload.login, publicKey)
				}
				if (payload.additionalFields) {
					payload.additionalFields = await rsaEncrypt(JSON.stringify(payload.additionalFields), publicKey)
				}
			}

			const response = await axios.post(
				generateUrl('/apps/doriath/api/v1/secrets'),
				payload,
			)
			return response.data
		},

		/**
		 * Update an existing secret, encrypting any changed sensitive fields.
		 *
		 * @param {string} id Secret ID
		 * @param {object} data Updated fields
		 * @return {Promise<object>} The updated secret
		 */
		async updateSecret(id, data) {
			const session = useSessionStore()
			const payload = { ...data }

			if (session.certificate) {
				const publicKey = await importPublicKey(session.certificate)
				if (payload.key !== undefined && payload.key !== null) {
					payload.key = await rsaEncrypt(payload.key, publicKey)
				}
				if (payload.login !== undefined && payload.login !== null) {
					payload.login = await rsaEncrypt(payload.login, publicKey)
				}
				if (payload.additionalFields) {
					payload.additionalFields = await rsaEncrypt(JSON.stringify(payload.additionalFields), publicKey)
				}
			}

			const response = await axios.put(
				generateUrl(`/apps/doriath/api/v1/secrets/${id}`),
				payload,
			)
			return response.data
		},

		/**
		 * Delete a secret by ID.
		 *
		 * @param {string} id Secret ID
		 * @return {Promise<void>}
		 */
		async deleteSecret(id) {
			await axios.delete(
				generateUrl(`/apps/doriath/api/v1/secrets/${id}`),
			)
			this.secrets = this.secrets.filter(s => s.id !== id)
			if (this.currentSecret?.id === id) {
				this.currentSecret = null
			}
		},

		/**
		 * Search secrets by term with pagination.
		 *
		 * @param {string} term Search term
		 * @param {number} page Page number
		 * @return {Promise<void>}
		 */
		async searchSecrets(term, page = 1) {
			this.loading = true
			try {
				const response = await axios.get(
					generateUrl('/apps/doriath/api/v1/secrets/search'),
					{ params: { term, page } },
				)
				this.secrets = response.data.results ?? []
				this.totalCount = response.data.total ?? this.secrets.length
				this.page = page
			} finally {
				this.loading = false
			}
		},
	},
})
