import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { defineStore } from 'pinia'

/**
 * Allow-listed per-user dashboard preference keys. The server enforces
 * the same allow-list — the client mirror here lets the UI silently
 * filter unsupported keys before the request leaves the browser.
 */
export const ALLOWED_DASHBOARD_KEYS = Object.freeze([
	'default_view',
	'sort_field',
	'sort_direction',
	'show_recent_widget',
	'show_kpi_widget',
	'show_migration_banner',
])

/**
 * Pinia store for per-user dashboard preferences
 * (implement-dashboard-settings §6.1 / W18).
 *
 * Backs the `GET/PUT/DELETE /api/v1/dashboard-settings` surface served
 * by DashboardSettingsController. The store hydrates a single
 * `settings` object keyed by preference name and exposes `get` /
 * `setMany` / `reset` actions for the user-settings dialog.
 *
 * @spec openspec/changes/implement-dashboard-settings/tasks.md#task-6.1
 */
export const useDashboardSettingsStore = defineStore('dashboardSettings', {
	state: () => ({
		/** @type {Record<string,string>} Current settings, key -> value. */
		settings: {},
		/** @type {boolean} Whether a request is in flight. */
		loading: false,
		/** @type {string|null} The last error message (or null). */
		error: null,
	}),

	getters: {
		/**
		 * Whether any settings have been hydrated (used to gate the
		 * dialog's first render).
		 *
		 * @param {object} state The store state.
		 * @return {boolean}
		 */
		hasSettings: (state) => Object.keys(state.settings).length > 0,
	},

	actions: {
		/**
		 * Hydrate the full per-user settings map from the server.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/user-settings/spec.md#requirement-default-view-preference-v1
		 * @spec openspec/specs/user-settings/spec.md#requirement-default-secret-type-v1
		 */
		async fetchAll() {
			this.loading = true
			this.error = null
			try {
				const response = await axios.get(
					generateUrl('/apps/keepiq/api/v1/dashboard-settings'),
				)
				const data = response.data || {}
				this.settings = data.settings ?? data ?? {}
			} catch (err) {
				this.error = err?.message ?? String(err)
				throw err
			} finally {
				this.loading = false
			}
		},

		/**
		 * Get a single setting value, falling back to the supplied
		 * default when not set.
		 *
		 * @param {string} key The preference key.
		 * @param {string|null} [fallback] The fallback.
		 * @return {string|null}
		 */
		get(key, fallback = null) {
			return this.settings[key] ?? fallback
		},

		/**
		 * Persist a single setting. Returns the updated value or throws.
		 *
		 * Unknown keys are silently filtered (the server also rejects
		 * them, but this short-circuits a 400 round-trip for typos).
		 *
		 * @param {string} key The preference key.
		 * @param {string} value The preference value.
		 * @return {Promise<string|null>}
		 */
		async set(key, value) {
			if (ALLOWED_DASHBOARD_KEYS.includes(key) === false) {
				return null
			}
			return this.setMany({ [key]: value }).then(
				() => this.settings[key] ?? null,
			)
		},

		/**
		 * Persist a batch of settings in a single PUT.
		 *
		 * Filters keys against ALLOWED_DASHBOARD_KEYS; an empty filtered
		 * payload short-circuits to a no-op.
		 *
		 * @param {Record<string,string>} updates The preference updates.
		 * @return {Promise<Record<string,string>>}
		 * @spec openspec/specs/user-settings/spec.md#requirement-default-view-preference-v1
		 * @spec openspec/specs/user-settings/spec.md#requirement-default-secret-type-v1
		 */
		async setMany(updates) {
			const filtered = {}
			for (const [key, value] of Object.entries(updates)) {
				if (ALLOWED_DASHBOARD_KEYS.includes(key)) {
					filtered[key] = value
				}
			}
			if (Object.keys(filtered).length === 0) {
				return this.settings
			}

			this.loading = true
			this.error = null
			try {
				const response = await axios.put(
					generateUrl('/apps/keepiq/api/v1/dashboard-settings'),
					{ settings: filtered },
				)
				const data = response.data || {}
				const merged = data.settings ?? data ?? {}
				this.settings = { ...this.settings, ...merged }
				return this.settings
			} catch (err) {
				this.error = err?.message ?? String(err)
				throw err
			} finally {
				this.loading = false
			}
		},

		/**
		 * Reset (delete) all per-user dashboard preferences for the
		 * current user. The dashboard reverts to the admin defaults.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/user-settings/spec.md#requirement-user-settings-dialog-mvp
		 */
		async reset() {
			this.loading = true
			this.error = null
			try {
				await axios.delete(
					generateUrl('/apps/keepiq/api/v1/dashboard-settings'),
				)
				this.settings = {}
			} catch (err) {
				this.error = err?.message ?? String(err)
				throw err
			} finally {
				this.loading = false
			}
		},
	},
})
