/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { defineStore } from 'pinia'

/** The type a new secret starts as when nothing usable was saved. */
export const FALLBACK_TYPE = 'login'

/** The list views CnIndexPage offers on the secret list, in toggle order. */
export const VIEW_CHOICES = Object.freeze(['list', 'cards', 'table'])

/**
 * The view the secret list opens in: the saved one when the list offers it,
 * otherwise the list view.
 *
 * @param {string|undefined|null} saved The saved `default_view`.
 * @return {string} One of VIEW_CHOICES.
 * @spec openspec/specs/vault-defaults/spec.md#requirement-default-item-type-and-view
 */
export function resolveDefaultView(saved) {
	return VIEW_CHOICES.includes(saved) ? saved : VIEW_CHOICES[0]
}

/**
 * The id of the type the create dialog preselects: the saved type when it
 * still exists, else Login, else the first type there is.
 *
 * @param {string|undefined|null} saved The saved `default_secret_type` (a type name).
 * @param {Array<{id: string, name: string}>} types The secret types.
 * @return {string|null} A type id, or null when there are no types.
 * @spec openspec/specs/vault-defaults/spec.md#requirement-default-item-type-and-view
 */
export function resolveDefaultTypeId(saved, types) {
	const match =
		types.find((type) => type.name === saved)
		?? types.find((type) => type.name === FALLBACK_TYPE)
		?? types[0]
	return match ? match.id : null
}

/** In-flight load, shared so screens that ask at once cause one request. */
let pending = null

/**
 * The default item type and default list view a user chose (vault-20),
 * stored server-side as the `default_secret_type` and `default_view` user
 * preferences.
 *
 * @spec openspec/specs/vault-defaults/spec.md#requirement-default-item-type-and-view
 */
export const useUserPreferencesStore = defineStore('userPreferences', {
	state: () => ({
		/** @type {string} The default item type, by type name. */
		defaultSecretType: FALLBACK_TYPE,
		/** @type {string} The default list view. */
		defaultView: VIEW_CHOICES[0],
		/** @type {boolean} Whether the saved values were read. */
		loaded: false,
	}),

	actions: {
		/**
		 * Read the saved defaults. When they cannot be read, Login and the
		 * list view stay.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/vault-defaults/spec.md#requirement-default-item-type-and-view
		 */
		async load() {
			try {
				const response = await axios.get(
					generateUrl('/apps/keepiq/api/settings/user'),
				)
				const data = response.data || {}
				this.defaultSecretType = data.default_secret_type || FALLBACK_TYPE
				this.defaultView = resolveDefaultView(data.default_view)
			} catch {
				// Keep the defaults; the screens work without the preference.
			}
			this.loaded = true
		},

		/**
		 * Read the saved defaults once per page load.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/vault-defaults/spec.md#requirement-default-item-type-and-view
		 */
		ensureLoaded() {
			if (this.loaded) {
				return Promise.resolve()
			}
			if (pending === null) {
				pending = this.load().finally(() => {
					pending = null
				})
			}
			return pending
		},

		/**
		 * Save one or both defaults and apply them at once.
		 *
		 * @param {{defaultSecretType?: string, defaultView?: string}} changes The new values.
		 * @return {Promise<void>}
		 * @spec openspec/specs/vault-defaults/spec.md#requirement-default-item-type-and-view
		 */
		async save(changes) {
			const body = {}
			if (changes.defaultSecretType !== undefined) {
				body.default_secret_type = changes.defaultSecretType
			}
			if (changes.defaultView !== undefined) {
				body.default_view = resolveDefaultView(changes.defaultView)
			}
			await axios.put(generateUrl('/apps/keepiq/api/settings/user'), body)
			if (body.default_secret_type !== undefined) {
				this.defaultSecretType = body.default_secret_type
			}
			if (body.default_view !== undefined) {
				this.defaultView = body.default_view
			}
		},
	},
})
