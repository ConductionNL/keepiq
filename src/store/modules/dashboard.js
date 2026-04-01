// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * useDashboardStore
 *
 * Pinia store for vault summary statistics displayed on the dashboard.
 */
import { defineStore } from 'pinia'
import { generateUrl } from '@nextcloud/router'

export const useDashboardStore = defineStore('dashboard', {
	state: () => ({
		/** Vault summary KPIs returned by /api/dashboard/summary */
		summary: {
			totalSecrets: 0,
			sharedSecrets: 0,
			totalFolders: 0,
			compromisedSecrets: 0,
			migrationPending: 0,
			migrationFailed: 0,
			pendingApps: 0,
			caHealthy: true,
		},
		loading: false,
		error: null,
	}),

	getters: {
		/**
		 * Returns true when the vault contains no secrets at all.
		 *
		 * @param {object} state Store state.
		 * @return {boolean}
		 */
		isEmpty: (state) => state.summary.totalSecrets === 0,

		/**
		 * Convenience accessor for migration counts.
		 *
		 * @param {object} state Store state.
		 * @return {boolean}
		 */
		hasMigrationIssues: (state) =>
			state.summary.migrationPending > 0 || state.summary.migrationFailed > 0,
	},

	actions: {
		/**
		 * Fetch the current vault summary from the backend.
		 *
		 * @return {Promise<object|null>} The summary object, or null on failure.
		 */
		async fetchSummary() {
			this.loading = true
			this.error = null
			try {
				const response = await fetch(
					generateUrl('/apps/app-template/api/dashboard/summary'),
					{ headers: { requesttoken: OC.requestToken } },
				)
				if (response.ok) {
					const data = await response.json()
					this.summary = { ...this.summary, ...data }
					return this.summary
				}
				this.error = `HTTP ${response.status}`
			} catch (err) {
				console.error('[DashboardStore] fetchSummary failed:', err)
				this.error = err.message
			} finally {
				this.loading = false
			}
			return null
		},
	},
})
