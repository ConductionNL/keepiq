/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Pins the secret-type store: the types endpoint and the create/update
 * payloads (scope default, fields only when an array), in-place replace and
 * delete of the local list, and the offline fallback — a cache-served vault
 * skips the network, a network error falls back to the cached types and marks
 * the vault cache-served, any other error is rethrown.
 *
 * @spec openspec/specs/secrets/spec.md#requirement-secret-types
 * @spec openspec/specs/admin-secret-types/spec.md#requirement-item-type-definitions
 */

import axios from '@nextcloud/axios'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useOfflineStore } from '../../src/store/modules/offline.js'
import { useSecretTypeStore } from '../../src/store/modules/secretType.js'

const TYPES_URL = '/apps/keepiq/api/v1/secret-types'
const CACHED = [{ id: 'cached', name: 'cached', label: 'Cached' }]

describe('useSecretTypeStore', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
	})

	it('typesById maps each type by id', () => {
		const store = useSecretTypeStore()
		store.types = [
			{ id: 'login', label: 'Login' },
			{ id: 'note', label: 'Note' },
		]
		expect(store.typesById).toEqual({
			login: { id: 'login', label: 'Login' },
			note: { id: 'note', label: 'Note' },
		})
	})

	describe('fetchTypes', () => {
		it('loads the types from the server and toggles loading', async () => {
			const get = vi
				.spyOn(axios, 'get')
				.mockResolvedValue({ data: [{ id: 'login' }] })
			const store = useSecretTypeStore()

			const pending = store.fetchTypes()
			expect(store.loading).toBe(true)
			await pending

			expect(get).toHaveBeenCalledWith(TYPES_URL)
			expect(store.types).toEqual([{ id: 'login' }])
			expect(store.loading).toBe(false)
		})

		it('treats an empty body as no types', async () => {
			vi.spyOn(axios, 'get').mockResolvedValue({ data: null })
			const store = useSecretTypeStore()
			await store.fetchTypes()
			expect(store.types).toEqual([])
		})

		it('serves the cached types without a request while the vault is cache-served', async () => {
			const get = vi.spyOn(axios, 'get')
			const offline = useOfflineStore()
			offline.servedFromCache = true
			offline.vault = { types: CACHED }
			const store = useSecretTypeStore()

			await store.fetchTypes()

			expect(get).not.toHaveBeenCalled()
			expect(store.types).toEqual(CACHED)
			expect(store.loading).toBe(false)
		})

		it('still fetches when cache-served but no types are cached', async () => {
			const get = vi.spyOn(axios, 'get').mockResolvedValue({ data: [{ id: 'x' }] })
			const offline = useOfflineStore()
			offline.servedFromCache = true
			offline.vault = {}
			const store = useSecretTypeStore()

			await store.fetchTypes()

			expect(get).toHaveBeenCalledOnce()
			expect(store.types).toEqual([{ id: 'x' }])
		})

		it.each([
			['a Network Error message', Object.assign(new Error('Network Error'))],
			['an ERR_NETWORK code', Object.assign(new Error('x'), { code: 'ERR_NETWORK' })],
			['a request without a response', Object.assign(new Error('x'), { request: {} })],
		])('falls back to the cached types on %s', async (_label, err) => {
			vi.spyOn(axios, 'get').mockRejectedValue(err)
			const offline = useOfflineStore()
			offline.vault = { types: CACHED }
			const store = useSecretTypeStore()

			await store.fetchTypes()

			expect(store.types).toEqual(CACHED)
			expect(offline.servedFromCache).toBe(true)
			expect(store.loading).toBe(false)
		})

		it('rethrows a network error when nothing is cached', async () => {
			const err = new Error('Network Error')
			vi.spyOn(axios, 'get').mockRejectedValue(err)
			const offline = useOfflineStore()
			const store = useSecretTypeStore()

			await expect(store.fetchTypes()).rejects.toBe(err)
			expect(offline.servedFromCache).toBe(false)
			expect(store.loading).toBe(false)
		})

		it('rethrows a server error even with cached types', async () => {
			const err = Object.assign(new Error('500'), {
				request: {},
				response: { status: 500 },
			})
			vi.spyOn(axios, 'get').mockRejectedValue(err)
			const offline = useOfflineStore()
			offline.vault = { types: CACHED }
			const store = useSecretTypeStore()

			await expect(store.fetchTypes()).rejects.toBe(err)
			expect(store.types).toEqual([])
			expect(offline.servedFromCache).toBe(false)
		})
	})

	describe('createType', () => {
		it('defaults the scope to user, omits non-array fields and appends the result', async () => {
			const post = vi
				.spyOn(axios, 'post')
				.mockResolvedValue({ data: { id: 't1', name: 'wifi' } })
			const store = useSecretTypeStore()
			store.types = [{ id: 'login' }]

			const created = await store.createType({
				name: 'wifi',
				label: 'Wi-Fi',
				fields: 'not-an-array',
			})

			expect(post).toHaveBeenCalledWith(TYPES_URL, {
				name: 'wifi',
				label: 'Wi-Fi',
				scope: 'user',
			})
			expect(created).toEqual({ id: 't1', name: 'wifi' })
			expect(store.types).toEqual([{ id: 'login' }, { id: 't1', name: 'wifi' }])
		})

		it('forwards an explicit scope and a field list', async () => {
			const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: { id: 't2' } })
			const store = useSecretTypeStore()
			const fields = [{ key: 'ssid', type: 'text' }]

			await store.createType({ name: 'wifi', label: 'Wi-Fi', scope: 'global', fields })

			expect(post).toHaveBeenCalledWith(TYPES_URL, {
				name: 'wifi',
				label: 'Wi-Fi',
				scope: 'global',
				fields,
			})
		})
	})

	describe('updateType', () => {
		it('sends only the label when no field list is given and replaces the type in place', async () => {
			const put = vi
				.spyOn(axios, 'put')
				.mockResolvedValue({ data: { id: 'b', label: 'New' } })
			const store = useSecretTypeStore()
			store.types = [{ id: 'a' }, { id: 'b', label: 'Old' }, { id: 'c' }]

			const updated = await store.updateType('b', 'New')

			expect(put).toHaveBeenCalledWith(`${TYPES_URL}/b`, { label: 'New' })
			expect(updated).toEqual({ id: 'b', label: 'New' })
			expect(store.types).toEqual([{ id: 'a' }, { id: 'b', label: 'New' }, { id: 'c' }])
		})

		it('sends the field list when given', async () => {
			const put = vi.spyOn(axios, 'put').mockResolvedValue({ data: { id: 'b' } })
			const store = useSecretTypeStore()
			const fields = [{ key: 'pin' }]

			await store.updateType('b', 'Card', fields)

			expect(put).toHaveBeenCalledWith(`${TYPES_URL}/b`, { label: 'Card', fields })
		})

		it('leaves the list alone when the type is not loaded', async () => {
			vi.spyOn(axios, 'put').mockResolvedValue({ data: { id: 'z' } })
			const store = useSecretTypeStore()
			store.types = [{ id: 'a' }]
			await expect(store.updateType('z', 'Z')).resolves.toEqual({ id: 'z' })
			expect(store.types).toEqual([{ id: 'a' }])
		})
	})

	describe('deleteType', () => {
		it('deletes and drops only that type', async () => {
			const del = vi.spyOn(axios, 'delete').mockResolvedValue({ data: {} })
			const store = useSecretTypeStore()
			store.types = [{ id: 'a' }, { id: 'b' }]

			await store.deleteType('a')

			expect(del).toHaveBeenCalledWith(`${TYPES_URL}/a`)
			expect(store.types).toEqual([{ id: 'b' }])
		})

		it('keeps the type when the delete fails', async () => {
			vi.spyOn(axios, 'delete').mockRejectedValue(new Error('403'))
			const store = useSecretTypeStore()
			store.types = [{ id: 'a' }]
			await expect(store.deleteType('a')).rejects.toThrow('403')
			expect(store.types).toEqual([{ id: 'a' }])
		})
	})
})
