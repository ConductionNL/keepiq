/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Pinia store backing the in-app vault summary dashboard. Fetches and
 * caches the GET /api/dashboard/summary response.
 *
 * @spec openspec/changes/implement-dashboard-settings/specs/dashboard/spec.md
 */

import { defineStore } from 'pinia'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export const useDashboardStore = defineStore('dashboard', {
	state: () => ({
		summary: null,
		isLoading: false,
		error: null,
	}),

	getters: {
		/**
		 * Whether the vault is empty (no secrets). Drives the empty state.
		 *
		 * @param {object} state Store state.
		 * @return {boolean} True when the user has zero secrets.
		 * @spec openspec/changes/implement-dashboard-settings/specs/dashboard/spec.md
		 */
		isEmpty: (state) => (state.summary?.total_secrets ?? 0) === 0,
	},

	actions: {
		/**
		 * Fetch the vault summary from the API and cache it in state.
		 *
		 * @return {object|null} Summary payload, or null on failure.
		 * @spec openspec/changes/implement-dashboard-settings/specs/dashboard/spec.md
		 */
		async fetchSummary() {
			this.isLoading = true
			this.error = null
			try {
				const response = await axios.get(generateUrl('/apps/doriath/api/dashboard/summary'))
				this.summary = response.data
				return response.data
			} catch (error) {
				this.error = error.response?.data?.message || error.message
				console.error('Failed to fetch dashboard summary:', error)
				return null
			} finally {
				this.isLoading = false
			}
		},
	},
})
