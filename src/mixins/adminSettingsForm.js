/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * The message to show for a failed settings request.
 *
 * @param {object} e The thrown error.
 * @return {string|undefined} The server's message, else the error's own.
 */
function errorText(e) {
	return e?.response?.data?.message || e?.message
}

/**
 * The methods a section using the mixin provides.
 *
 * @typedef {object} AdminSettingsFormHost
 * @property {function(object): void} readSettings Takes the loaded settings into the form.
 * @property {function(): object} settingsPayload The settings to store.
 * @property {function(): (string|null)} [validateSettings] The message to show instead of saving, or null when the form can be saved.
 * @property {function(object): void} [afterSave] Runs after a successful save, with the stored payload.
 */

/**
 * A mixin for an admin settings section that edits one settings object.
 *
 * On creation it GETs the object and hands it to the host's `readSettings`.
 * `save()` PUTs the host's `settingsPayload()`, unless `validateSettings()`
 * returns a message, which then shows in `error`. A failed request puts its
 * message in `error`.
 *
 * @param {string} path The settings route, e.g. `/apps/keepiq/api/settings/admin/policies`.
 * @return {object} The mixin; its host implements {@link AdminSettingsFormHost}.
 */
export function adminSettingsForm(path) {
	return {
		data() {
			return { error: null }
		},

		async created() {
			try {
				const response = await axios.get(generateUrl(path))
				this.readSettings(response.data)
			} catch (e) {
				this.error = errorText(e)
			}
		},

		methods: {
			/**
			 * Store the section's payload.
			 *
			 * @return {Promise<void>}
			 */
			async save() {
				this.error = this.validateSettings?.() || null
				if (this.error) {
					return
				}
				const payload = this.settingsPayload()
				try {
					await axios.put(generateUrl(path), payload)
					this.afterSave?.(payload)
				} catch (e) {
					this.error = errorText(e)
				}
			},
		},
	}
}
