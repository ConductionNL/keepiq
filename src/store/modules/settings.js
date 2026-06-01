import { defineStore } from 'pinia'
import axios from '@nextcloud/axios'
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
		 * Master-password policy (min length + min score) from admin settings.
		 *
		 * @param {object} state Store state.
		 * @return {object} Policy with minLength / minScore.
		 * @spec openspec/changes/implement-dashboard-settings/specs/admin-settings/spec.md
		 */
		passwordPolicy: (state) => ({
			minLength: state.adminSettings?.min_password_length ?? 12,
			minScore: state.adminSettings?.min_password_score ?? 3,
		}),
		/**
		 * The CA status block from the admin settings response (if present).
		 *
		 * @param {object} state Store state.
		 * @return {object|null} CA status or null.
		 * @spec openspec/changes/implement-dashboard-settings/specs/admin-settings/spec.md
		 */
		caStatus: (state) => state.adminSettings?.ca_status ?? null,
		/**
		 * Current user's session-timeout preference.
		 *
		 * @param {object} state Store state.
		 * @return {string} Session timeout enum (session|10min|30min).
		 * @spec openspec/changes/implement-dashboard-settings/specs/user-settings/spec.md
		 */
		sessionTimeout: (state) => state.userPreferences?.session_timeout ?? 'session',
		/**
		 * Current user's notification toggle preferences.
		 *
		 * @param {object} state Store state.
		 * @return {object} Map of notification toggle keys to booleans.
		 * @spec openspec/changes/implement-dashboard-settings/specs/user-settings/spec.md
		 */
		notificationToggles: (state) => ({
			notify_shares: state.userPreferences?.notify_shares ?? true,
			notify_requests: state.userPreferences?.notify_requests ?? true,
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
		 * Fetch the administrator settings (password policy, CA status).
		 *
		 * @return {object|null} Admin settings, or null on failure.
		 * @spec openspec/changes/implement-dashboard-settings/specs/admin-settings/spec.md
		 */
		async fetchAdminSettings() {
			try {
				const response = await axios.get(generateUrl('/apps/doriath/api/settings/admin'))
				this.adminSettings = response.data
				return response.data
			} catch (error) {
				console.error('Failed to fetch admin settings:', error)
				return null
			}
		},

		/**
		 * Persist administrator settings. Throws on validation (400) so the
		 * caller can surface the inline error.
		 *
		 * @param {object} data Admin settings to save.
		 * @return {object} Updated admin settings.
		 * @spec openspec/changes/implement-dashboard-settings/specs/admin-settings/spec.md
		 */
		async saveAdminSettings(data) {
			const response = await axios.put(generateUrl('/apps/doriath/api/settings/admin'), data)
			this.adminSettings = { ...this.adminSettings, ...response.data.config }
			return response.data.config
		},

		/**
		 * Fetch the current user's preferences (session timeout, toggles).
		 *
		 * @return {object|null} User preferences, or null on failure.
		 * @spec openspec/changes/implement-dashboard-settings/specs/user-settings/spec.md
		 */
		async fetchUserPreferences() {
			try {
				const response = await axios.get(generateUrl('/apps/doriath/api/settings/user'))
				this.userPreferences = response.data
				return response.data
			} catch (error) {
				console.error('Failed to fetch user preferences:', error)
				return null
			}
		},

		/**
		 * Persist a partial update of the current user's preferences.
		 *
		 * @param {object} data Preference keys to update.
		 * @return {object|null} Updated preferences, or null on failure.
		 * @spec openspec/changes/implement-dashboard-settings/specs/user-settings/spec.md
		 */
		async saveUserPreferences(data) {
			try {
				const response = await axios.put(generateUrl('/apps/doriath/api/settings/user'), data)
				this.userPreferences = response.data.preferences
				return response.data.preferences
			} catch (error) {
				console.error('Failed to save user preferences:', error)
				return null
			}
		},
	},
})
