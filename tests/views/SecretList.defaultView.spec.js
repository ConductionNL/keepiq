/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The secret list opens in the view the user saved as default (vault-20).
 * Options-object style, like SecretList.listViews.spec.js.
 *
 * @spec openspec/specs/vault-defaults/spec.md#requirement-default-item-type-and-view
 */

import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import SecretList from '../../src/views/SecretList.vue'
import { useUserPreferencesStore } from '../../src/store/modules/userPreferences.js'

describe('SecretList default view', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	it('opens in the saved view', () => {
		useUserPreferencesStore().defaultView = 'cards'

		expect(SecretList.computed.listViewMode.call({})).toBe('cards')
	})

	it('opens in the list view when nothing usable was saved', () => {
		useUserPreferencesStore().defaultView = 'grid'

		expect(SecretList.computed.listViewMode.call({})).toBe('list')
	})

	it('loads the saved preferences when the list mounts', async () => {
		const prefs = useUserPreferencesStore()
		prefs.ensureLoaded = vi.fn().mockResolvedValue()

		await SecretList.methods.loadViewPreference.call({})

		expect(prefs.ensureLoaded).toHaveBeenCalled()
	})
})
