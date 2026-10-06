import { generateUrl } from '@nextcloud/router'
import { defineStore } from 'pinia'

export const useSettingsStore = defineStore('settings', {
	state: () => ({
		settings: {},
		loading: false,
		isAdmin: false,
		/** @type {string[]} The admin areas the user holds (admin-scoped-roles). */
		adminAreas: [],
	}),

	getters: {
		getSettings: (state) => state.settings,
		getIsAdmin: (state) => state.isAdmin,
		/**
		 * Whether the user holds one admin area.
		 *
		 * @param {object} state The store state
		 * @return {function(string): boolean} Area key to held
		 * @spec openspec/changes/archive/2026-10-04-admin-scoped-roles/tasks.md#2.5
		 */
		holdsArea: (state) => (area) => state.adminAreas.includes(area),
	},

	actions: {
		/**
		 * Load app + user settings (and admin/openregister flags) from the API.
		 *
		 * @return {object|null} Settings payload, or null on failure.
		 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-8
		 */
		async fetchSettings() {
			this.loading = true
			try {
				const response = await fetch(
					generateUrl('/apps/keepiq/api/settings'),
					{
						headers: { requesttoken: OC.requestToken },
					},
				)
				if (response.ok) {
					const data = await response.json()
					this.settings = data
					this.isAdmin = !!data?.isAdmin
					this.adminAreas = Array.isArray(data?.adminAreas)
						? data.adminAreas
						: []
					return data
				}
			} catch (error) {
				console.error('Failed to fetch settings:', error)
			} finally {
				this.loading = false
			}
			return null
		},

		/**
		 * Persist settings to the API and update local state on success.
		 *
		 * @param {object} settings Settings to save.
		 * @return {object|null} Updated settings, or null on failure.
		 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-8
		 */
		async saveSettings(settings) {
			this.loading = true
			try {
				const response = await fetch(
					generateUrl('/apps/keepiq/api/settings'),
					{
						method: 'POST',
						headers: {
							'Content-Type': 'application/json',
							requesttoken: OC.requestToken,
						},
						body: JSON.stringify(settings),
					},
				)
				if (response.ok) {
					const data = await response.json()
					this.settings = data
					return data
				}
			} catch (error) {
				console.error('Failed to save settings:', error)
			} finally {
				this.loading = false
			}
			return null
		},
	},
})
