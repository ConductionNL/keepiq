/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Pins the folder store: the flat-list-to-tree build (orphans become roots),
 * the folder endpoints and payloads (customization keys forwarded only when
 * set, delete cascade as a query param and resolution as a body), local list
 * updates, and the offline fallback to the cached folder tree.
 *
 * @spec openspec/specs/secrets/spec.md#requirement-folder-management
 * @spec openspec/specs/secrets/spec.md#requirement-list-folder-children
 */

import axios from '@nextcloud/axios'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useFolderStore } from '../../src/store/modules/folder.js'
import { useOfflineStore } from '../../src/store/modules/offline.js'

const FOLDERS_URL = '/apps/keepiq/api/v1/folders'
const CACHED = [{ id: 'c1', name: 'Cached', parentId: null }]

describe('useFolderStore', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
	})

	describe('folderTree', () => {
		it('nests children under their parent and keeps orphans as roots', () => {
			const store = useFolderStore()
			store.folders = [
				{ id: 'a', name: 'A', parentId: null },
				{ id: 'b', name: 'B', parentId: 'a' },
				{ id: 'c', name: 'C', parentId: 'b' },
				{ id: 'd', name: 'D', parentId: 'gone' },
				{ id: 'e', name: 'E', parentId: 'a' },
			]

			const tree = store.folderTree

			expect(tree.map((f) => f.id)).toEqual(['a', 'd'])
			expect(tree[0].children.map((f) => f.id)).toEqual(['b', 'e'])
			expect(tree[0].children[0].children.map((f) => f.id)).toEqual(['c'])
			expect(tree[0].children[0].children[0].children).toEqual([])
			expect(tree[1]).toEqual({ id: 'd', name: 'D', parentId: 'gone', children: [] })
			// The flat list is not mutated.
			expect(store.folders[0].children).toBeUndefined()
		})

		it('is empty for no folders', () => {
			expect(useFolderStore().folderTree).toEqual([])
		})
	})

	describe('fetchFolders', () => {
		it('loads the folders and toggles loading', async () => {
			const get = vi.spyOn(axios, 'get').mockResolvedValue({ data: CACHED })
			const store = useFolderStore()

			const pending = store.fetchFolders()
			expect(store.loading).toBe(true)
			await pending

			expect(get).toHaveBeenCalledWith(FOLDERS_URL)
			expect(store.folders).toEqual(CACHED)
			expect(store.loading).toBe(false)
		})

		it('treats an empty body as no folders', async () => {
			vi.spyOn(axios, 'get').mockResolvedValue({ data: '' })
			const store = useFolderStore()
			store.folders = [{ id: 'x' }]
			await store.fetchFolders()
			expect(store.folders).toEqual([])
		})

		it('serves the cached folders without a request while cache-served', async () => {
			const get = vi.spyOn(axios, 'get')
			const offline = useOfflineStore()
			offline.servedFromCache = true
			offline.vault = { folders: CACHED }
			const store = useFolderStore()

			await store.fetchFolders()

			expect(get).not.toHaveBeenCalled()
			expect(store.folders).toEqual(CACHED)
			expect(store.loading).toBe(false)
		})

		it.each([
			['a Network Error message', new Error('Network Error')],
			['an ERR_NETWORK code', Object.assign(new Error('x'), { code: 'ERR_NETWORK' })],
			['a request without a response', Object.assign(new Error('x'), { request: {} })],
		])('falls back to the cached folders on %s', async (_label, err) => {
			vi.spyOn(axios, 'get').mockRejectedValue(err)
			const offline = useOfflineStore()
			offline.vault = { folders: CACHED }
			const store = useFolderStore()

			await store.fetchFolders()

			expect(store.folders).toEqual(CACHED)
			expect(offline.servedFromCache).toBe(true)
			expect(store.loading).toBe(false)
		})

		it('rethrows a network error when no folders are cached', async () => {
			const err = new Error('Network Error')
			vi.spyOn(axios, 'get').mockRejectedValue(err)
			const offline = useOfflineStore()
			offline.vault = { types: [] }
			const store = useFolderStore()

			await expect(store.fetchFolders()).rejects.toBe(err)
			expect(offline.servedFromCache).toBe(false)
			expect(store.loading).toBe(false)
		})

		it('rethrows a server error even with cached folders', async () => {
			const err = Object.assign(new Error('500'), {
				request: {},
				response: { status: 500 },
			})
			vi.spyOn(axios, 'get').mockRejectedValue(err)
			const offline = useOfflineStore()
			offline.vault = { folders: CACHED }
			const store = useFolderStore()

			await expect(store.fetchFolders()).rejects.toBe(err)
			expect(store.folders).toEqual([])
			expect(offline.servedFromCache).toBe(false)
		})
	})

	describe('createFolder', () => {
		it('defaults to a root folder without customization keys and appends the result', async () => {
			const post = vi
				.spyOn(axios, 'post')
				.mockResolvedValue({ data: { id: 'n1', name: 'Work' } })
			const store = useFolderStore()

			const created = await store.createFolder('Work')

			expect(post).toHaveBeenCalledWith(FOLDERS_URL, { name: 'Work', parentId: null })
			expect(created).toEqual({ id: 'n1', name: 'Work' })
			expect(store.folders).toEqual([{ id: 'n1', name: 'Work' }])
		})

		it('forwards the parent and only the two known, set customization keys', async () => {
			const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: { id: 'n2' } })
			const store = useFolderStore()

			await store.createFolder('Sub', 'p1', {
				customIcon: 'key',
				customColor: 'red',
				evil: 'dropped',
			})
			expect(post).toHaveBeenLastCalledWith(FOLDERS_URL, {
				name: 'Sub',
				parentId: 'p1',
				customIcon: 'key',
				customColor: 'red',
			})

			await store.createFolder('Sub', 'p1', { customIcon: null, customColor: undefined })
			expect(post).toHaveBeenLastCalledWith(FOLDERS_URL, { name: 'Sub', parentId: 'p1' })
		})
	})

	describe('updateFolder', () => {
		it('puts the change as given and replaces the folder in place', async () => {
			const put = vi
				.spyOn(axios, 'put')
				.mockResolvedValue({ data: { id: 'b', name: 'Renamed' } })
			const store = useFolderStore()
			store.folders = [{ id: 'a' }, { id: 'b', name: 'Old' }]
			const data = { customIcon: null, customColor: null }

			const updated = await store.updateFolder('b', data)

			expect(put).toHaveBeenCalledWith(`${FOLDERS_URL}/b`, data)
			expect(updated).toEqual({ id: 'b', name: 'Renamed' })
			expect(store.folders).toEqual([{ id: 'a' }, { id: 'b', name: 'Renamed' }])
		})

		it('leaves the list alone for a folder it does not hold', async () => {
			vi.spyOn(axios, 'put').mockResolvedValue({ data: { id: 'z' } })
			const store = useFolderStore()
			store.folders = [{ id: 'a' }]
			await store.updateFolder('z', { name: 'Z' })
			expect(store.folders).toEqual([{ id: 'a' }])
		})
	})

	it('fetchChildren returns the children summary', async () => {
		const body = { directSecretCount: 3, subfolders: [{ id: 's' }] }
		const get = vi.spyOn(axios, 'get').mockResolvedValue({ data: body })
		const store = useFolderStore()
		await expect(store.fetchChildren('f1')).resolves.toEqual(body)
		expect(get).toHaveBeenCalledWith(`${FOLDERS_URL}/f1/children`)
	})

	describe('deleteFolder', () => {
		it('sends an empty config by default and drops the folder', async () => {
			const del = vi.spyOn(axios, 'delete').mockResolvedValue({ data: {} })
			const store = useFolderStore()
			store.folders = [{ id: 'a' }, { id: 'b' }]

			await store.deleteFolder('a')

			expect(del).toHaveBeenCalledWith(`${FOLDERS_URL}/a`, {})
			expect(store.folders).toEqual([{ id: 'b' }])
		})

		it('sends the cascade mode as a query parameter', async () => {
			const del = vi.spyOn(axios, 'delete').mockResolvedValue({ data: {} })
			const store = useFolderStore()
			await store.deleteFolder('a', { cascade: 'trash' })
			expect(del).toHaveBeenCalledWith(`${FOLDERS_URL}/a`, {
				params: { cascade: 'trash' },
			})
		})

		it('sends a resolution body, defaulting direct secrets to move', async () => {
			const del = vi.spyOn(axios, 'delete').mockResolvedValue({ data: {} })
			const store = useFolderStore()
			const subfolders = { s1: 'delete' }

			await store.deleteFolder('a', { subfolders })
			expect(del).toHaveBeenLastCalledWith(`${FOLDERS_URL}/a`, {
				data: { subfolders, directSecrets: 'move' },
			})

			await store.deleteFolder('a', { subfolders, directSecrets: 'trash' })
			expect(del).toHaveBeenLastCalledWith(`${FOLDERS_URL}/a`, {
				data: { subfolders, directSecrets: 'trash' },
			})
		})

		it('keeps the folder when the delete fails', async () => {
			vi.spyOn(axios, 'delete').mockRejectedValue(new Error('409'))
			const store = useFolderStore()
			store.folders = [{ id: 'a' }]
			await expect(store.deleteFolder('a')).rejects.toThrow('409')
			expect(store.folders).toEqual([{ id: 'a' }])
		})
	})
})
