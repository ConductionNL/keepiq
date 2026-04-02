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
		/** @type {boolean} */
		loading: false,
		/** @type {object} Active filters */
		filters: {},
		/** @type {string} Sort field */
		sort: 'name',
		/** @type {string} Sort direction */
		direction: 'ASC',
		/** @type {number} Current page (1-indexed) */
		page: 1,
	}),

	actions: {
		/**
		 * Fetch a paginated list of secrets.
		 *
		 * @param {string|null} folderId Optional folder to filter by
		 * @return {Promise<void>}
		 */
		async fetchSecrets(folderId = null) {
			this.loading = true
			try {
				const params = {
					sort: this.sort,
					direction: this.direction,
					page: this.page,
					limit: 50,
				}
				if (folderId) {
					params.folderId = folderId
				}
				const response = await axios.get(
					generateUrl('/apps/doriath/api/v1/secrets'),
					{ params },
				)
				this.secrets = response.data.results ?? response.data
				this.totalCount = response.data.total ?? this.secrets.length
			} finally {
				this.loading = false
			}
		},

		/**
		 * Fetch and decrypt a single secret by ID.
		 *
		 * @param {string} id Secret ID
		 * @return {Promise<void>}
		 */
		async fetchSecret(id) {
			this.loading = true
			try {
				const response = await axios.get(
					generateUrl(`/apps/doriath/api/v1/secrets/${id}`),
				)
				const secret = { ...response.data }
				const session = useSessionStore()

				if (session.cryptoKey) {
					if (secret.key) {
						secret.key = await rsaDecrypt(secret.key, session.cryptoKey)
					}
					if (secret.login) {
						secret.login = await rsaDecrypt(secret.login, session.cryptoKey)
					}
					if (secret.additionalFields) {
						const decrypted = {}
						for (const [field, value] of Object.entries(secret.additionalFields)) {
							decrypted[field] = value ? await rsaDecrypt(value, session.cryptoKey) : value
						}
						secret.additionalFields = decrypted
					}
				}

				this.currentSecret = secret
			} finally {
				this.loading = false
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

			if (session.certificate) {
				const publicKey = await importPublicKey(session.certificate)
				if (payload.key) {
					payload.key = await rsaEncrypt(payload.key, publicKey)
				}
				if (payload.login) {
					payload.login = await rsaEncrypt(payload.login, publicKey)
				}
				if (payload.additionalFields) {
					const encrypted = {}
					for (const [field, value] of Object.entries(payload.additionalFields)) {
						encrypted[field] = value ? await rsaEncrypt(value, publicKey) : value
					}
					payload.additionalFields = encrypted
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
					const encrypted = {}
					for (const [field, value] of Object.entries(payload.additionalFields)) {
						encrypted[field] = value ? await rsaEncrypt(value, publicKey) : value
					}
					payload.additionalFields = encrypted
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
				this.secrets = response.data.results ?? response.data
				this.totalCount = response.data.total ?? this.secrets.length
				this.page = page
			} finally {
				this.loading = false
			}
		},
	},
})
