import { defineStore } from 'pinia'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * Pinia store for the registered-application admin queue + user
 * registration flow (implement-application-mgmt §9).
 *
 * The store wraps the `/api/v1/applications` REST surface and surfaces
 * the one-time private key returned by `register` / `approve` for the
 * PrivateKeyDownloadDialog. The private key MUST never be persisted —
 * it lives only in transient store state until the dialog is dismissed.
 *
 * @spec openspec/changes/implement-application-mgmt/tasks.md#task-9.1
 */
export const useApplicationStore = defineStore('application', {
	state: () => ({
		/** @type {Array<object>} The current page of applications. */
		applications: [],
		/** @type {object|null} The currently opened application. */
		currentApplication: null,
		/** @type {Array<object>} The list of pending applications (admin queue). */
		pendingApplications: [],
		/** @type {number} Total number of matching applications. */
		totalCount: 0,
		/** @type {boolean} Whether a request is in flight. */
		loading: false,
		/** @type {string|null} The one-time private-key PEM returned by register/approve. */
		oneTimePrivateKey: null,
		/** @type {string|null} The application ID the one-time key belongs to. */
		oneTimePrivateKeyAppId: null,
	}),

	getters: {
		/**
		 * Number of pending applications (drives the dashboard
		 * pending_apps_count card and the queue badge).
		 *
		 * @param {object} state The store state.
		 * @return {number}
		 */
		pendingCount: (state) => state.pendingApplications.length,
	},

	actions: {
		/**
		 * Fetch the applications visible to the current user. Admins see
		 * all; non-admin users see their own registrations plus active
		 * applications.
		 *
		 * @return {Promise<void>}
		 */
		async fetchApplications() {
			this.loading = true
			try {
				const response = await axios.get(
					generateUrl('/apps/doriath/api/v1/applications'),
				)
				this.applications = response.data || []
				this.totalCount = this.applications.length
			} finally {
				this.loading = false
			}
		},

		/**
		 * Fetch a single application by id.
		 *
		 * @param {string} id The application ID.
		 * @return {Promise<object>}
		 */
		async fetchApplication(id) {
			this.loading = true
			try {
				const response = await axios.get(
					generateUrl(`/apps/doriath/api/v1/applications/${id}`),
				)
				this.currentApplication = response.data
				return this.currentApplication
			} finally {
				this.loading = false
			}
		},

		/**
		 * Fetch the admin pending-approval queue. Caller must be in the
		 * admin group; the API returns 403 otherwise.
		 *
		 * @return {Promise<void>}
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
		 * Register a new application. Admins auto-approve; non-admin
		 * callers create a pending row. When the server generates a
		 * keypair (no CSR supplied) the response carries `private_key`
		 * — the caller MUST surface it via PrivateKeyDownloadDialog.
		 *
		 * @param {object} payload The registration payload.
		 * @param {string} payload.name The application name.
		 * @param {string|null} [payload.description] Optional description.
		 * @param {string} [payload.type='external'] Application type.
		 * @param {string|null} [payload.csr] Optional PKCS#10 CSR PEM.
		 * @return {Promise<object>} The created application row.
		 */
		async registerApplication(payload) {
			const body = {
				name: payload.name,
				description: payload.description ?? null,
				type: payload.type ?? 'external',
				csr: payload.csr ?? null,
			}
			const response = await axios.post(
				generateUrl('/apps/doriath/api/v1/applications'),
				body,
			)
			const data = response.data || {}

			if (data.private_key) {
				this.oneTimePrivateKey = data.private_key
				this.oneTimePrivateKeyAppId = data.id ?? null
			}

			this.applications.push(data)
			if (data.status === 'pending') {
				this.pendingApplications.push(data)
			}
			return data
		},

		/**
		 * Approve a pending application. Admin-only.
		 *
		 * Mirrors registerApplication: when the original request had no
		 * CSR, the approval call generates the keypair server-side and
		 * returns `private_key` — surface via PrivateKeyDownloadDialog.
		 *
		 * @param {string} id The application ID.
		 * @return {Promise<object>} The approved application row.
		 */
		async approveApplication(id) {
			const response = await axios.post(
				generateUrl(`/apps/doriath/api/v1/applications/${id}/approve`),
				{},
			)
			const data = response.data || {}

			if (data.private_key) {
				this.oneTimePrivateKey = data.private_key
				this.oneTimePrivateKeyAppId = id
			}

			this.pendingApplications = this.pendingApplications.filter((a) => a.id !== id)
			const idx = this.applications.findIndex((a) => a.id === id)
			if (idx !== -1) {
				this.applications.splice(idx, 1, { ...this.applications[idx], ...data })
			} else if (data.id) {
				this.applications.push(data)
			}
			return data
		},

		/**
		 * Reject (hard-delete) a pending application. Admin-only.
		 *
		 * @param {string} id The application ID.
		 * @return {Promise<void>}
		 */
		async rejectApplication(id) {
			await axios.post(
				generateUrl(`/apps/doriath/api/v1/applications/${id}/reject`),
				{},
			)
			this.pendingApplications = this.pendingApplications.filter((a) => a.id !== id)
			this.applications = this.applications.filter((a) => a.id !== id)
		},

		/**
		 * Delete an application. Admin-only. The server performs the
		 * cascade (secrets + EncryptionSuite + SecretRequests).
		 *
		 * @param {string} id The application ID.
		 * @return {Promise<void>}
		 */
		async deleteApplication(id) {
			await axios.delete(
				generateUrl(`/apps/doriath/api/v1/applications/${id}`),
			)
			this.applications = this.applications.filter((a) => a.id !== id)
			this.pendingApplications = this.pendingApplications.filter((a) => a.id !== id)
			if (this.currentApplication?.id === id) {
				this.currentApplication = null
			}
		},

		/**
		 * Clear the one-time private key from store state. The caller
		 * MUST invoke this after the PrivateKeyDownloadDialog has been
		 * acknowledged and dismissed.
		 *
		 * @return {void}
		 */
		clearOneTimePrivateKey() {
			this.oneTimePrivateKey = null
			this.oneTimePrivateKeyAppId = null
		},
	},
})
