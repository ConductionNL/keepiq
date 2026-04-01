// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * useUserSettingsStore
 *
 * Pinia store for per-user preferences (session timeout, notification toggles).
 */
import { defineStore } from 'pinia'
import { generateUrl } from '@nextcloud/router'

export const useUserSettingsStore = defineStore('userSettings', {
	state: () => ({
		preferences: {
			sessionTimeout: 30,
			notifyShares: true,
			notifyRequests: true,
			notifyGroupShares: false,
			notifySecurity: true,
			defaultType: 'password',
			defaultView: 'list',
		},
		loading: false,
		error: null,
	}),

	actions: {
		/**
		 * Fetch the current user's preferences from the backend.
		 *
		 * @return {Promise<object|null>}
		 */
		async fetchPreferences() {
			this.loading = true
			this.error = null
			try {
				const response = await fetch(
					generateUrl('/apps/app-template/api/settings/user'),
					{ headers: { requesttoken: OC.requestToken } },
				)
				if (response.ok) {
					const data = await response.json()
					this.preferences = { ...this.preferences, ...data }
					return this.preferences
				}
				this.error = `HTTP ${response.status}`
			} catch (err) {
				console.error('[UserSettingsStore] fetchPreferences failed:', err)
				this.error = err.message
			} finally {
				this.loading = false
			}
			return null
		},

		/**
		 * Persist updated preferences to the backend.
		 *
		 * @param {object} data Preference fields to update.
		 * @return {Promise<object|null>}
		 */
		async updatePreferences(data) {
			this.loading = true
			this.error = null
			try {
				const response = await fetch(
					generateUrl('/apps/app-template/api/settings/user'),
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
					this.preferences = { ...this.preferences, ...updated }
					return this.preferences
				}
				this.error = `HTTP ${response.status}`
			} catch (err) {
				console.error('[UserSettingsStore] updatePreferences failed:', err)
				this.error = err.message
			} finally {
				this.loading = false
			}
			return null
		},
	},
})
