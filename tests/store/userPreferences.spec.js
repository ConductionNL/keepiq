/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The default item type and default list view the server already stored now
 * load, save and resolve (vault-20).
 *
 * @spec openspec/changes/vault-defaults-and-recently-used-widget/specs/vault-defaults/spec.md#requirement-default-item-type-and-view
 */

import axios from '@nextcloud/axios'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import {
	resolveDefaultTypeId,
	resolveDefaultView,
	useUserPreferencesStore,
} from '../../src/store/modules/userPreferences.js'

const TYPES = [
	{ id: 'type-login', name: 'login' },
	{ id: 'type-ssh', name: 'ssh_key' },
]

describe('user preferences store', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
	})

	it('loads both defaults from the user settings', async () => {
		const get = vi.spyOn(axios, 'get').mockResolvedValue({
			data: { default_secret_type: 'ssh_key', default_view: 'cards' },
		})
		const store = useUserPreferencesStore()

		await store.load()

		expect(get).toHaveBeenCalledWith(
			expect.stringContaining('/apps/keepiq/api/settings/user'),
		)
		expect(store.defaultSecretType).toBe('ssh_key')
		expect(store.defaultView).toBe('cards')
		expect(store.loaded).toBe(true)
	})

	it('loads once, however many screens ask', async () => {
		const get = vi.spyOn(axios, 'get').mockResolvedValue({ data: {} })
		const store = useUserPreferencesStore()

		await Promise.all([store.ensureLoaded(), store.ensureLoaded()])
		await store.ensureLoaded()

		expect(get).toHaveBeenCalledTimes(1)
	})

	it('keeps login and the list view when the settings cannot be read', async () => {
		vi.spyOn(axios, 'get').mockRejectedValue(new Error('offline'))
		const store = useUserPreferencesStore()

		await store.load()

		expect(store.defaultSecretType).toBe('login')
		expect(store.defaultView).toBe('list')
	})

	it('saves both defaults through the preferences endpoint', async () => {
		const put = vi.spyOn(axios, 'put').mockResolvedValue({ data: {} })
		const store = useUserPreferencesStore()

		await store.save({ defaultSecretType: 'ssh_key', defaultView: 'table' })

		expect(put).toHaveBeenCalledWith(
			expect.stringContaining('/apps/keepiq/api/settings/user'),
			{
				default_secret_type: 'ssh_key',
				default_view: 'table',
			},
		)
		expect(store.defaultSecretType).toBe('ssh_key')
		expect(store.defaultView).toBe('table')
	})

	it('resolves the saved type to its id', () => {
		expect(resolveDefaultTypeId('ssh_key', TYPES)).toBe('type-ssh')
	})

	it('falls back to login when the saved type was deleted', () => {
		expect(resolveDefaultTypeId('retired_type', TYPES)).toBe('type-login')
	})

	it('falls back to the first type when login is gone too', () => {
		expect(resolveDefaultTypeId('x', [{ id: 'type-note', name: 'note' }])).toBe(
			'type-note',
		)
		expect(resolveDefaultTypeId('x', [])).toBe(null)
	})

	it('opens an unknown saved view as the list', () => {
		expect(resolveDefaultView('cards')).toBe('cards')
		expect(resolveDefaultView('grid')).toBe('list')
		expect(resolveDefaultView(undefined)).toBe('list')
	})
})
