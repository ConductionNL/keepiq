import { defineStore } from 'pinia'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * Pinia store for the append-only audit trail (add-secret-audit-trail §5.1).
 *
 * Wraps the read-only `/api/v1/audit` surface: per-secret activity
 * (owner-scoped), the session user's personal activity, and the admin
 * instance-wide filtered + paginated view. The store never mutates audit
 * entries — the log is append-only at the API surface — and the admin CSV
 * export is generated client-side from the fetched rows (no server download
 * endpoint).
 *
 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-5.1
 */
export const useAuditStore = defineStore('audit', {
	state: () => ({
		/** @type {Array<object>} Entries for the currently opened secret. */
		secretEntries: [],
		/** @type {Array<object>} The session user's own recent operations. */
		personalEntries: [],
		/** @type {Array<object>} The admin instance-wide page of entries. */
		adminEntries: [],
		/** @type {number} Total matching entries for the admin filter. */
		adminTotal: 0,
		/** @type {number} Current admin page (1-based). */
		adminPage: 1,
		/** @type {number} Admin page size. */
		adminLimit: 50,
		/** @type {object} The active admin filter. */
		adminFilters: {
			eventType: '',
			actor: '',
			objectType: '',
			objectId: '',
			from: '',
			to: '',
		},
		/** @type {boolean} Whether a request is in flight. */
		loading: false,
	}),

	getters: {
		/**
		 * Total number of admin pages for the current filter + page size.
		 *
		 * @param {object} state The store state.
		 * @return {number}
		 */
		adminPageCount: (state) => Math.max(1, Math.ceil(state.adminTotal / state.adminLimit)),
	},

	actions: {
		/**
		 * Fetch the activity for one secret (owner-scoped). A 404 (non-owned
		 * or nonexistent — indistinguishable) clears the list rather than
		 * surfacing an error.
		 *
		 * @param {string} secretId The secret ID.
		 * @return {Promise<void>}
		 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-5.1
		 */
		async fetchSecretActivity(secretId) {
			this.loading = true
			try {
				const response = await axios.get(
					generateUrl(`/apps/doriath/api/v1/audit/secret/${secretId}`),
				)
				this.secretEntries = response.data.entries || []
			} catch (e) {
				this.secretEntries = []
			} finally {
				this.loading = false
			}
		},

		/**
		 * Fetch the session user's personal activity.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-5.1
		 */
		async fetchPersonalActivity() {
			this.loading = true
			try {
				const response = await axios.get(
					generateUrl('/apps/doriath/api/v1/audit/me'),
				)
				this.personalEntries = response.data.entries || []
			} finally {
				this.loading = false
			}
		},

		/**
		 * Build the query params for the admin filtered request.
		 *
		 * @param {number} page The 1-based page.
		 * @return {object} The axios params object (empty filters omitted).
		 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-5.1
		 */
		buildAdminParams(page) {
			const params = { page, limit: this.adminLimit }
			Object.keys(this.adminFilters).forEach((key) => {
				const value = this.adminFilters[key]
				if (value !== '' && value !== null && value !== undefined) {
					params[key] = value
				}
			})
			return params
		},

		/**
		 * Fetch one admin page with the current filters.
		 *
		 * @param {number} [page] The 1-based page to fetch (default current).
		 * @return {Promise<void>}
		 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-5.1
		 */
		async fetchAdminAudit(page = this.adminPage) {
			this.loading = true
			try {
				const response = await axios.get(
					generateUrl('/apps/doriath/api/v1/audit'),
					{ params: this.buildAdminParams(page) },
				)
				this.adminEntries = response.data.entries || []
				this.adminTotal = response.data.total || 0
				this.adminPage = response.data.page || page
				this.adminLimit = response.data.limit || this.adminLimit
			} finally {
				this.loading = false
			}
		},

		/**
		 * Apply a new filter set and reload from page 1.
		 *
		 * @param {object} filters The (partial) filter object.
		 * @return {Promise<void>}
		 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-5.1
		 */
		async applyAdminFilters(filters) {
			this.adminFilters = { ...this.adminFilters, ...filters }
			await this.fetchAdminAudit(1)
		},

		/**
		 * Fetch every matching admin page for the current filter and return the
		 * accumulated rows — used by the client-side CSV export so the file
		 * reflects the whole filter result, not just the visible page.
		 *
		 * @return {Promise<Array<object>>} All matching entries.
		 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-5.5
		 */
		async fetchAllAdminForExport() {
			const all = []
			let page = 1
			let pages = 1
			do {
				const response = await axios.get(
					generateUrl('/apps/doriath/api/v1/audit'),
					{ params: this.buildAdminParams(page) },
				)
				const entries = response.data.entries || []
				all.push(...entries)
				const total = response.data.total || 0
				const limit = response.data.limit || this.adminLimit
				pages = Math.max(1, Math.ceil(total / limit))
				page += 1
			} while (page <= pages)
			return all
		},
	},
})
