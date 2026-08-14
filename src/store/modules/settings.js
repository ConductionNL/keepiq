import { generateUrl } from '@nextcloud/router'
import { defineStore } from 'pinia'

export const useSettingsStore = defineStore('settings', {
	state: () => ({
		settings: {},
		loading: false,
		hasOpenRegisters: false,
		isAdmin: false,
	}),

	getters: {
		getSettings: (state) => state.settings,
		getIsAdmin: (state) => state.isAdmin,
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
					generateUrl('/apps/doriath/api/settings'),
					{
						headers: { requesttoken: OC.requestToken },
					},
				)
				if (response.ok) {
					const data = await response.json()
					this.settings = data
					this.hasOpenRegisters = !!data?.openregisters
					this.isAdmin = !!data?.isAdmin
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
					generateUrl('/apps/doriath/api/settings'),
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
