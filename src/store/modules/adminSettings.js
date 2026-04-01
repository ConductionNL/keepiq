// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * useAdminSettingsStore
 *
 * Pinia store for admin application settings (password policy, CA health,
 * application approval queue).
 */
import { defineStore } from 'pinia'
import { generateUrl } from '@nextcloud/router'

export const useAdminSettingsStore = defineStore('adminSettings', {
	state: () => ({
		settings: {
			passwordMinLength: 12,
			passwordMinScore: 3,
			caStatus: 'unknown',
		},
		applicationQueue: [],
		loading: false,
		loadingQueue: false,
		error: null,
	}),

	actions: {
		/**
		 * Fetch admin settings from the backend.
		 *
		 * @return {Promise<object|null>}
		 */
		async fetchSettings() {
			this.loading = true
			this.error = null
			try {
				const response = await fetch(
					generateUrl('/apps/app-template/api/settings/admin'),
					{ headers: { requesttoken: OC.requestToken } },
				)
				if (response.ok) {
					const data = await response.json()
					this.settings = { ...this.settings, ...data }
					return this.settings
				}
				this.error = `HTTP ${response.status}`
			} catch (err) {
				console.error('[AdminSettingsStore] fetchSettings failed:', err)
				this.error = err.message
			} finally {
				this.loading = false
			}
			return null
		},

		/**
		 * Persist admin settings to the backend.
		 *
		 * @param {object} data Settings fields to update.
		 * @return {Promise<object|null>}
		 */
		async updateSettings(data) {
			this.loading = true
			this.error = null
			try {
				const response = await fetch(
					generateUrl('/apps/app-template/api/settings/admin'),
					{
						method: 'PUT',
						headers: {
							'Content-Type': 'application/json',
							requesttoken: OC.requestToken,
						},
						body: JSON.stringify(data),
					},
				)
				if (response.ok) {
					const updated = await response.json()
					this.settings = { ...this.settings, ...updated }
					return this.settings
				}
				this.error = `HTTP ${response.status}`
			} catch (err) {
				console.error('[AdminSettingsStore] updateSettings failed:', err)
				this.error = err.message
			} finally {
				this.loading = false
			}
			return null
		},

		/**
		 * Approve an application in the queue.
		 *
		 * TODO (V1): Wire to ApplicationMapper once #5 lands.
		 *
		 * @param {string} appId The application identifier.
		 * @return {Promise<void>}
		 */
		async approveApplication(appId) {
			this.applicationQueue = this.applicationQueue.filter((a) => a.id !== appId)
		},

		/**
		 * Reject an application in the queue.
		 *
		 * TODO (V1): Wire to ApplicationMapper once #5 lands.
		 *
		 * @param {string} appId The application identifier.
		 * @return {Promise<void>}
		 */
		async rejectApplication(appId) {
			this.applicationQueue = this.applicationQueue.filter((a) => a.id !== appId)
		},
	},
})
