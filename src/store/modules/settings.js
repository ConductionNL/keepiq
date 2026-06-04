import { defineStore } from 'pinia'
import { generateUrl } from '@nextcloud/router'

export const useSettingsStore = defineStore('settings', {
	state: () => ({
		settings: {},
		loading: false,
		hasOpenRegisters: false,
		isAdmin: false,
		adminSettings: null,
		userPreferences: null,
	}),

	getters: {
		getSettings: (state) => state.settings,
		getIsAdmin: (state) => state.isAdmin,
		/**
		 * The master-password policy floors from the admin settings.
		 *
		 * @param {object} state Store state.
		 * @return {object} Policy object with minLength / minScore.
		 */
		passwordPolicy: (state) => ({
			minLength: state.adminSettings?.master_password_min_length ?? 12,
			minScore: state.adminSettings?.master_password_min_score ?? 3,
		}),
		/**
		 * The user's current session-timeout preference.
		 *
		 * @param {object} state Store state.
		 * @return {string} Session timeout enum (session|10min|30min).
		 */
		sessionTimeout: (state) => state.userPreferences?.session_timeout ?? 'session',
		/**
		 * The user's notification toggles.
		 *
		 * @param {object} state Store state.
		 * @return {object} Map of notification category → boolean.
		 */
		notificationToggles: (state) => ({
			notify_shares: state.userPreferences?.notify_shares ?? true,
			notify_requests: state.userPreferences?.notify_requests ?? true,
			notify_group_shares: state.userPreferences?.notify_group_shares ?? true,
			notify_security: state.userPreferences?.notify_security ?? true,
		}),
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
				const response = await fetch(generateUrl('/apps/doriath/api/settings'), {
					headers: { requesttoken: OC.requestToken },
				})
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
				const response = await fetch(generateUrl('/apps/doriath/api/settings'), {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
					body: JSON.stringify(settings),
				})
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

		/**
		 * Fetch the administrator-configurable settings from the API.
		 *
		 * @return {object|null} Admin settings payload, or null on failure.
		 * @spec openspec/changes/implement-dashboard-settings/tasks.md#task-6.1
		 */
		async fetchAdminSettings() {
			try {
				const response = await fetch(generateUrl('/apps/doriath/api/settings/admin'), {
					headers: { requesttoken: OC.requestToken },
				})
				if (response.ok) {
					const data = await response.json()
					this.adminSettings = data
					return data
				}
			} catch (error) {
				console.error('Failed to fetch admin settings:', error)
			}
			return null
		},

		/**
		 * Persist administrator-configurable settings to the API.
		 *
		 * @param {object} data Admin settings to save.
		 * @return {object|null} Updated admin settings, or null on failure.
		 * @spec openspec/changes/implement-dashboard-settings/tasks.md#task-6.1
		 */
		async saveAdminSettings(data) {
			try {
				const response = await fetch(generateUrl('/apps/doriath/api/settings/admin'), {
					method: 'PUT',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
					body: JSON.stringify(data),
				})
				if (response.ok) {
					const updated = await response.json()
					this.adminSettings = updated
					return updated
				}
			} catch (error) {
				console.error('Failed to save admin settings:', error)
			}
			return null
		},

		/**
		 * Fetch the current user's preferences from the API.
		 *
		 * @return {object|null} User preferences payload, or null on failure.
		 * @spec openspec/changes/implement-dashboard-settings/tasks.md#task-6.1
		 */
		async fetchUserPreferences() {
			try {
				const response = await fetch(generateUrl('/apps/doriath/api/settings/user'), {
					headers: { requesttoken: OC.requestToken },
				})
				if (response.ok) {
					const data = await response.json()
					this.userPreferences = data
					return data
				}
			} catch (error) {
				console.error('Failed to fetch user preferences:', error)
			}
			return null
		},

		/**
		 * Persist the current user's preferences to the API.
		 *
		 * @param {object} data User preferences to save (whitelisted server-side).
		 * @return {object|null} Updated user preferences, or null on failure.
		 * @spec openspec/changes/implement-dashboard-settings/tasks.md#task-6.1
		 */
		async saveUserPreferences(data) {
			try {
				const response = await fetch(generateUrl('/apps/doriath/api/settings/user'), {
					method: 'PUT',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
					body: JSON.stringify(data),
				})
				if (response.ok) {
					const updated = await response.json()
					this.userPreferences = updated
					return updated
				}
			} catch (error) {
				console.error('Failed to save user preferences:', error)
			}
			return null
		},
	},
})
