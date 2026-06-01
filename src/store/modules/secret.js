import { defineStore } from 'pinia'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { importPublicKey, rsaEncrypt, rsaDecrypt } from '../../crypto/index.js'
import { useSessionStore } from './session.js'

/**
 * Secret store — owns the client-side encrypt/decrypt boundary.
 *
 * The API returns ciphertext blobs for key/login/additionalFields. This store
 * decrypts them with the in-memory CryptoKey (session) for display, and
 * encrypts new values with the owner's public certificate before sending.
 */
export const useSecretStore = defineStore('secret', {
	state: () => ({
		/** @type {Array} List of secrets (metadata + ciphertext blobs) */
		secrets: [],
		/** @type {object|null} Currently-opened secret (decrypted fields) */
		currentSecret: null,
		/** @type {number} Total matching records for pagination */
		totalCount: 0,
		/** @type {boolean} */
		loading: false,
		/** @type {object} Active filters (folderId) */
		filters: { folderId: null },
		/** @type {object} Active sort */
		sort: { field: 'name', direction: 'asc' },
		/** @type {number} Current 1-based page */
		page: 1,
		/** @type {number} Page size */
		limit: 50,
	}),

	actions: {
		/**
		 * Fetch a page of secrets (metadata + ciphertext only — no decryption).
		 *
		 * @param {object} [options] Optional overrides for filters/sort/page.
		 */
		async fetchSecrets(options = {}) {
			this.loading = true
			try {
				const filters = { ...this.filters, ...(options.filters || {}) }
				const sort = { ...this.sort, ...(options.sort || {}) }
				const page = options.page || this.page

				const params = {
					sort: sort.field,
					direction: sort.direction,
					page,
					limit: this.limit,
				}
				if (filters.folderId) {
					params.folder_id = filters.folderId
				}

				const response = await axios.get(
					generateUrl('/apps/doriath/api/v1/secrets'),
					{ params },
				)

				this.secrets = response.data.items || []
				this.totalCount = response.data.total || 0
				this.filters = filters
				this.sort = sort
				this.page = page
			} finally {
				this.loading = false
			}
		},

		/**
		 * Fuzzy-search secrets by name/url.
		 *
		 * @param {string} term The search term.
		 */
		async searchSecrets(term) {
			this.loading = true
			try {
				const response = await axios.get(
					generateUrl('/apps/doriath/api/v1/secrets'),
					{ params: { search: term, page: 1, limit: this.limit } },
				)
				this.secrets = response.data.items || []
				this.totalCount = response.data.total || 0
			} finally {
				this.loading = false
			}
		},

		/**
		 * Fetch a single secret and decrypt its encrypted fields in the browser.
		 *
		 * @param {string} id The secret ID.
		 * @return {Promise<object>} The secret with decrypted key/login/additionalFields.
		 */
		async fetchSecret(id) {
			const session = useSessionStore()
			if (session.isLocked) {
				throw new Error('Vault is locked')
			}

			const response = await axios.get(
				generateUrl(`/apps/doriath/api/v1/secrets/${id}`),
			)
			const secret = response.data

			secret.key = await this.decryptField(secret.key)
			secret.login = await this.decryptField(secret.login)
			const additional = await this.decryptField(secret.additionalFields)
			secret.additionalFields = additional ? JSON.parse(additional) : null

			this.currentSecret = secret
			return secret
		},

		/**
		 * Decrypt a single ciphertext blob with the session CryptoKey.
		 *
		 * @param {string|null} blob The ciphertext blob.
		 * @return {Promise<string|null>} The plaintext, or null when empty.
		 */
		async decryptField(blob) {
			if (!blob) {
				return null
			}
			const session = useSessionStore()
			return rsaDecrypt(blob, session.cryptoKey)
		},

		/**
		 * Create a secret, encrypting key/login/additionalFields client-side.
		 *
		 * @param {object} data Plaintext secret data (name, url, key, login, ...).
		 * @return {Promise<object>} The created secret.
		 */
		async createSecret(data) {
			const session = useSessionStore()
			if (!session.certificate) {
				throw new Error('No encryption certificate available')
			}
			const publicKey = await importPublicKey(session.certificate)

			const payload = {
				name: data.name,
				url: data.url || null,
				typeId: data.typeId || null,
				folderId: data.folderId || null,
				key: await rsaEncrypt(data.key, publicKey),
			}
			if (data.login) {
				payload.login = await rsaEncrypt(data.login, publicKey)
			}
			if (data.additionalFields) {
				payload.additionalFields = await rsaEncrypt(
					JSON.stringify(data.additionalFields),
					publicKey,
				)
			}

			const response = await axios.post(
				generateUrl('/apps/doriath/api/v1/secrets'),
				payload,
			)
			return response.data
		},

		/**
		 * Update a secret, re-encrypting any changed encrypted fields.
		 *
		 * @param {string} id   The secret ID.
		 * @param {object} data The updated fields.
		 * @return {Promise<object>} The updated secret.
		 */
		async updateSecret(id, data) {
			const session = useSessionStore()
			const payload = { ...data }

			if (data.key !== undefined || data.login !== undefined || data.additionalFields !== undefined) {
				const publicKey = await importPublicKey(session.certificate)
				if (data.key !== undefined) {
					payload.key = await rsaEncrypt(data.key, publicKey)
				}
				if (data.login !== undefined) {
					payload.login = data.login ? await rsaEncrypt(data.login, publicKey) : null
				}
				if (data.additionalFields !== undefined) {
					payload.additionalFields = data.additionalFields
						? await rsaEncrypt(JSON.stringify(data.additionalFields), publicKey)
						: null
				}
			}

			const response = await axios.put(
				generateUrl(`/apps/doriath/api/v1/secrets/${id}`),
				payload,
			)
			return response.data
		},

		/**
		 * Delete a secret.
		 *
		 * @param {string} id The secret ID.
		 */
		async deleteSecret(id) {
			await axios.delete(generateUrl(`/apps/doriath/api/v1/secrets/${id}`))
			this.secrets = this.secrets.filter(s => s.id !== id)
		},
	},
})
