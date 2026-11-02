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
 * A mixin for an admin settings section that edits one settings object.
 *
 * On creation it GETs the object and hands it to the host's
 * `readSettings(data)`. `save()` PUTs the host's `settingsPayload()`; a
 * payload of `null` skips the request (the host has set `error`). After a
 * successful PUT it calls the host's optional `afterSave(payload)`. A failed
 * request puts its message in `error`.
 *
 * @param {string} path The settings route, e.g. `/apps/keepiq/api/settings/admin/policies`.
 * @return {object} The mixin.
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
				this.error = null
				const payload = this.settingsPayload()
				if (payload === null) {
					return
				}
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
