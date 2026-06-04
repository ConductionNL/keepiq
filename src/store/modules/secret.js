import { defineStore } from 'pinia'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { rsaEncrypt, rsaDecrypt, importPublicKey } from '../../crypto/index.js'
import { useSessionStore } from './session.js'

/**
 * Pinia store for secrets.
 *
 * The store owns the end-to-end model: it sends RSA ciphertext to the API
 * (encrypted in the browser with the owner's public certificate) and
 * decrypts the blobs it receives using the session CryptoKey. The server
 * never sees plaintext (ADR-003).
 */
export const useSecretStore = defineStore('secret', {
	state: () => ({
		/** @type {Array<object>} The current page of secrets (metadata + ciphertext). */
		secrets: [],
		/** @type {object|null} The currently opened secret with decrypted fields. */
		currentSecret: null,
		/** @type {number} Total number of matching secrets (for pagination). */
		totalCount: 0,
		/** @type {boolean} Whether a request is in flight. */
		loading: false,
		/** @type {object} Active list filters. */
		filters: { folderId: null, search: '' },
		/** @type {object} Active sort. */
		sort: { field: 'name', direction: 'asc' },
		/** @type {number} The current 1-based page. */
		page: 1,
		/** @type {number} The page size. */
		limit: 50,
	}),

	actions: {
		/**
		 * Fetch a page of secrets (list or search).
		 *
		 * @param {object} options Optional overrides for filters/sort/page.
		 * @return {Promise<void>}
		 */
		async fetchSecrets(options = {}) {
			this.loading = true
			try {
				const params = {
					page: options.page ?? this.page,
					limit: options.limit ?? this.limit,
					sort: options.sort ?? this.sort.field,
					direction: options.direction ?? this.sort.direction,
				}
				const folderId = options.folderId ?? this.filters.folderId
				if (folderId) {
					params.folderId = folderId
				}
				const search = options.search ?? this.filters.search
				if (search) {
					params.search = search
				}

				const response = await axios.get(
					generateUrl('/apps/doriath/api/v1/secrets'),
					{ params },
				)
				this.secrets = response.data.items || []
				this.totalCount = response.data.total || 0
				this.page = response.data.page || 1
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
			this.loading = true
			try {
				const response = await axios.get(
					generateUrl(`/apps/doriath/api/v1/secrets/${id}`),
				)
				const secret = response.data
				this.currentSecret = await this.decryptSecret(secret)
				return this.currentSecret
			} finally {
				this.loading = false
			}
		},

		/**
		 * Decrypt the encrypted fields of a secret using the session CryptoKey.
		 *
		 * @param {object} secret The secret with ciphertext blobs.
		 * @return {Promise<object>} A copy of the secret with plaintext fields.
		 */
		async decryptSecret(secret) {
			const session = useSessionStore()
			if (!session.cryptoKey) {
				throw new Error('Vault is locked')
			}

			const decrypted = { ...secret }
			if (secret.key) {
				decrypted.key = await rsaDecrypt(secret.key, session.cryptoKey)
			}
			if (secret.login) {
				decrypted.login = await rsaDecrypt(secret.login, session.cryptoKey)
			}
			if (secret.additionalFields) {
				const json = await rsaDecrypt(secret.additionalFields, session.cryptoKey)
				try {
					decrypted.additionalFields = JSON.parse(json)
				} catch {
					decrypted.additionalFields = json
				}
			}
			return decrypted
		},

		/**
		 * Create a secret, encrypting the sensitive fields in the browser first.
		 *
		 * @param {object} data Plaintext fields (name, url, key, login, additionalFields, ...).
		 * @return {Promise<object>} The created secret (server response).
		 */
		async createSecret(data) {
			const session = useSessionStore()
			if (!session.certificate) {
				throw new Error('Vault is locked')
			}
			const publicKey = await importPublicKey(session.certificate)

			const payload = {
				name: data.name,
				url: data.url ?? null,
				typeId: data.typeId ?? null,
				folderId: data.folderId ?? null,
				key: await rsaEncrypt(String(data.key ?? ''), publicKey),
			}
			if (data.login !== undefined && data.login !== null && data.login !== '') {
				payload.login = await rsaEncrypt(String(data.login), publicKey)
			}
			if (data.additionalFields) {
				const json = typeof data.additionalFields === 'string'
					? data.additionalFields
					: JSON.stringify(data.additionalFields)
				payload.additionalFields = await rsaEncrypt(json, publicKey)
			}

			const response = await axios.post(
				generateUrl('/apps/doriath/api/v1/secrets'),
				payload,
			)
			return response.data
		},

		/**
		 * Update a secret. Sensitive fields are re-encrypted before submission.
		 *
		 * @param {string} id The secret ID.
		 * @param {object} data The fields to change.
		 * @return {Promise<object>} The updated secret (server response).
		 */
		async updateSecret(id, data) {
			const session = useSessionStore()
			const payload = {}

			for (const field of ['name', 'url', 'typeId', 'folderId']) {
				if (data[field] !== undefined) {
					payload[field] = data[field]
				}
			}

			if (data.key !== undefined || data.login !== undefined || data.additionalFields !== undefined) {
				if (!session.certificate) {
					throw new Error('Vault is locked')
				}
				const publicKey = await importPublicKey(session.certificate)
				if (data.key !== undefined) {
					payload.key = await rsaEncrypt(String(data.key), publicKey)
				}
				if (data.login !== undefined) {
					payload.login = data.login
						? await rsaEncrypt(String(data.login), publicKey)
						: null
				}
				if (data.additionalFields !== undefined) {
					const json = typeof data.additionalFields === 'string'
						? data.additionalFields
						: JSON.stringify(data.additionalFields)
					payload.additionalFields = await rsaEncrypt(json, publicKey)
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
		 * @return {Promise<void>}
		 */
		async deleteSecret(id) {
			await axios.delete(generateUrl(`/apps/doriath/api/v1/secrets/${id}`))
			this.secrets = this.secrets.filter(s => s.id !== id)
		},

		/**
		 * Search secrets by name or url (fuzzy).
		 *
		 * @param {string} term The search term.
		 * @return {Promise<void>}
		 */
		async searchSecrets(term) {
			this.filters.search = term
			this.page = 1
			await this.fetchSecrets({ search: term, page: 1 })
		},
	},
})
