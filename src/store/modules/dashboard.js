import { defineStore } from 'pinia'
import { generateUrl } from '@nextcloud/router'

/**
 * Pinia store for the Doriath dashboard summary.
 *
 * Fetches the per-user vault summary from GET /api/dashboard/summary and caches
 * it for the session so tab switches within the dashboard don't refetch.
 */
export const useDashboardStore = defineStore('dashboard', {
	state: () => ({
		summary: null,
		isLoading: false,
		error: null,
	}),

	getters: {
		/**
		 * Whether the vault is empty (no secrets yet) — drives the empty state.
		 *
		 * @param {object} state Store state.
		 * @return {boolean} True when the summary reports zero secrets.
		 */
		isEmpty: (state) => (state.summary?.total_secrets ?? 0) === 0,
	},

	actions: {
		/**
		 * Fetch the dashboard summary from the API and populate state.
		 *
		 * @return {object|null} Summary payload, or null on failure.
		 * @spec openspec/changes/implement-dashboard-settings/tasks.md#task-3.6
		 */
		async fetchSummary() {
			this.isLoading = true
			this.error = null
			try {
				const response = await fetch(generateUrl('/apps/doriath/api/dashboard/summary'), {
					headers: { requesttoken: OC.requestToken },
				})
				if (response.ok) {
					const data = await response.json()
					this.summary = data
					return data
				}
				this.error = `HTTP ${response.status}`
			} catch (error) {
				console.error('Failed to fetch dashboard summary:', error)
				this.error = error.message
			} finally {
				this.isLoading = false
			}
			return null
		},
	},
})
