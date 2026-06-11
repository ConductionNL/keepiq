/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Pinia store for the doriath dashboard summary feed.
 *
 * Wraps `GET /api/dashboard/summary` (served by DashboardController). The
 * summary payload powers the four KPI cards, the migration banner, the
 * pending-apps card, the CA health card, and the recent-secrets widget.
 *
 * @spec openspec/changes/implement-dashboard-settings/tasks.md#task-3.6
 */

import { defineStore } from 'pinia'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export const useDashboardStore = defineStore('dashboard', {
	state: () => ({
		/**
		 * @type {object|null}
		 *   The most recent summary payload, with the shape documented in
		 *   `lib/Service/DashboardService.php`.
		 */
		summary: null,
		/** @type {boolean} Whether a request is in flight. */
		isLoading: false,
		/** @type {string|null} The last error message (or null). */
		error: null,
	}),

	getters: {
		/**
		 * Whether the user has zero secrets — drives the empty state in the
		 * Dashboard view.
		 *
		 * @param {object} state The store state.
		 * @return {boolean}
		 */
		isEmpty: (state) => {
			if (state.summary == null) {
				return false
			}
			return Number(state.summary.total_secrets || 0) === 0
		},

		/**
		 * Number of pending applications surfaced on the dashboard
		 * (admin-only widget).
		 *
		 * @param {object} state The store state.
		 * @return {number}
		 */
		pendingAppsCount: (state) => Number(state.summary?.pending_apps_count || 0),

		/**
		 * Migration banner status (in_progress / completed_with_errors / null).
		 *
		 * @param {object} state The store state.
		 * @return {string|null}
		 */
		migrationStatus: (state) => state.summary?.migration_status || null,
	},

	actions: {
		/**
		 * Hydrate the summary payload.
		 *
		 * @return {Promise<void>}
		 */
		async fetchSummary() {
			this.isLoading = true
			this.error = null
			try {
				const response = await axios.get(
					generateUrl('/apps/doriath/api/dashboard/summary'),
				)
				this.summary = response.data || null
			} catch (e) {
				this.error = e?.response?.data?.message || e?.message || 'Failed to load dashboard summary'
				throw e
			} finally {
				this.isLoading = false
			}
		},
	},
})
