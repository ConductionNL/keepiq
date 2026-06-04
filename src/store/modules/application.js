import { defineStore } from 'pinia'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { importPublicKey, rsaEncrypt } from '../../crypto/index.js'

/**
 * Pinia store for application management.
 *
 * Owns the registration flow (including the two-path response where a
 * generated private key is returned exactly once), the admin approval
 * queue, and the write-without-read flow that encrypts a secret against
 * an application's public certificate.
 */
export const useApplicationStore = defineStore('application', {
	state: () => ({
		/** @type {Array<object>} The applications visible to the current user. */
		applications: [],
		/** @type {object|null} The currently-opened application detail. */
		currentApplication: null,
		/** @type {Array<object>} Pending applications (admin approval queue). */
		pendingApplications: [],
		/** @type {number} Total applications matching the current query. */
		totalCount: 0,
		/** @type {boolean} Whether a request is in flight. */
		loading: false,
		/** @type {string|null} One-time display of a returned private key PEM. */
		oneTimePrivateKey: null,
	}),

	actions: {
		/**
		 * Fetch the list of applications visible to the current user.
		 *
		 * @param {object} [filters] Optional filters ({ status, type }).
		 * @param {string} [sort] Sort column.
		 * @param {number} [page] 1-based page number.
		 * @param {number} [limit] Page size.
		 */
		async fetchApplications(filters = {}, sort = 'created_at', page = 1, limit = 25) {
			this.loading = true
			try {
				const response = await axios.get(
					generateUrl('/apps/doriath/api/v1/applications'),
					{ params: { ...filters, sort, page, limit } },
				)
				this.applications = response.data.results || []
				this.totalCount = response.data.total || 0
			} finally {
				this.loading = false
			}
		},

		/**
		 * Fetch a single application by ID.
		 *
		 * @param {string} id The application ID.
		 * @return {Promise<object>} The application.
		 */
		async fetchApplication(id) {
			this.loading = true
			try {
				const response = await axios.get(
					generateUrl(`/apps/doriath/api/v1/applications/${id}`),
				)
				this.currentApplication = response.data
				return response.data
			} finally {
				this.loading = false
			}
		},

		/**
		 * Register a new application.
		 *
		 * When the response contains a one-time private key, it is stored in
		 * `oneTimePrivateKey` so the UI can present the download/copy dialog.
		 *
		 * @param {object} payload The registration payload ({ name, description, type, csr }).
		 * @return {Promise<object>} The created application.
		 */
		async registerApplication({ name, description = null, type = 'external', csr = null }) {
			this.loading = true
			try {
				const response = await axios.post(
					generateUrl('/apps/doriath/api/v1/applications'),
					{ name, description, type, csr },
				)
				const application = response.data.application
				this.applications.unshift(application)
				if (response.data.privateKey) {
					this.oneTimePrivateKey = response.data.privateKey
				}
				return application
			} finally {
				this.loading = false
			}
		},

		/**
		 * Delete an application (hard cascade; admin only).
		 *
		 * @param {string} id The application ID.
		 */
		async deleteApplication(id) {
			await axios.delete(generateUrl(`/apps/doriath/api/v1/applications/${id}`))
			this.applications = this.applications.filter(app => app.id !== id)
			this.pendingApplications = this.pendingApplications.filter(app => app.id !== id)
		},

		/**
		 * Approve a pending application (admin only).
		 *
		 * When the approved application had no CSR, a private key is returned
		 * once and stored in `oneTimePrivateKey`.
		 *
		 * @param {string} id The application ID.
		 * @return {Promise<object>} The approved application.
		 */
		async approveApplication(id) {
			const response = await axios.post(
				generateUrl(`/apps/doriath/api/v1/applications/${id}/approve`),
			)
			const application = response.data.application
			this.pendingApplications = this.pendingApplications.filter(app => app.id !== id)
			if (response.data.privateKey) {
				this.oneTimePrivateKey = response.data.privateKey
			}
			return application
		},

		/**
		 * Reject a pending application (hard delete; admin only).
		 *
		 * @param {string} id The application ID.
		 */
		async rejectApplication(id) {
			await axios.post(generateUrl(`/apps/doriath/api/v1/applications/${id}/reject`))
			this.pendingApplications = this.pendingApplications.filter(app => app.id !== id)
		},

		/**
		 * Fetch the pending approval queue (admin only).
		 */
		async fetchPending() {
			this.loading = true
			try {
				const response = await axios.get(
					generateUrl('/apps/doriath/api/v1/applications/pending'),
				)
				this.pendingApplications = response.data || []
			} finally {
				this.loading = false
			}
		},

		/**
		 * Write a secret for an application (write-without-read).
		 *
		 * Fetches the application's active public certificate, encrypts the
		 * secret value against the app's public key, and posts the encrypted
		 * blob. The writing user can never read the value back.
		 *
		 * @param {string} applicationId The owning application ID.
		 * @param {object} fields The plaintext fields ({ name, key, login, url }).
		 * @return {Promise<object>} The created secret response.
		 */
		async writeSecretForApplication(applicationId, fields) {
			// Resolve the application's active public certificate.
			const suitesResponse = await axios.get(
				generateUrl('/apps/doriath/api/v1/suites'),
				{ params: { ownerType: 'application', ownerId: applicationId } },
			)
			const suites = suitesResponse.data || []
			const suite = suites.find(s => s.status === 'active' && s.ownerId === applicationId)
			if (!suite || !suite.certificate) {
				throw new Error('No active encryption suite for this application')
			}

			const publicKey = await importPublicKey(suite.certificate)
			const ciphertext = await rsaEncrypt(JSON.stringify(fields), publicKey)

			const response = await axios.post(
				generateUrl('/apps/doriath/api/v1/secrets'),
				{
					ownerType: 'application',
					ownerId: applicationId,
					name: fields.name,
					encryptedValue: ciphertext,
				},
			)
			return response.data
		},

		/**
		 * Clear the transient one-time private key (on dialog close).
		 */
		clearOneTimePrivateKey() {
			this.oneTimePrivateKey = null
		},
	},
})
