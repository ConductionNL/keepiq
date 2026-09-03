/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A refresh must reproduce the list the user is looking at.
 *
 * Reported live: move a secret out of a folder and every other secret in the
 * vault appeared in that folder. The list passed `folderId` / `typeId` /
 * `search` as call arguments on every fetch and nothing ever wrote them to
 * `filters`, so they stayed permanently null — and the refreshes that run after
 * a mutation (the detail sidebar's after edit/move/delete, FolderMoveDialog's
 * after moving vault contents) call `fetchSecrets()` with NO arguments. Every
 * one of them therefore re-queried the whole vault and replaced the folder's
 * contents with it. The sidebar's comment asserted the opposite: "fetchSecrets()
 * with no options reuses the store's active filters/sort/page".
 *
 * The mirror-image risk is the reason for WHOLE_VAULT: once a bare fetch reuses
 * the stored query, the bulk paths must NOT, or an export would silently shrink
 * to whichever folder was being browsed. Both directions are pinned here.
 *
 * @spec openspec/specs/secrets/spec.md#requirement-list-and-pagination
 */

import axios from '@nextcloud/axios'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useSecretStore } from '../../src/store/modules/secret.js'

/**
 * The `params` of the most recent GET.
 *
 * @return {object} Query params axios was called with.
 */
function lastParams() {
	const calls = axios.get.mock.calls
	return calls[calls.length - 1][1].params
}

describe('secret store — the stored list query', () => {
	let store

	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
		store = useSecretStore()
		vi.spyOn(axios, 'get').mockResolvedValue({
			data: { items: [], total: 0, page: 1 },
		})
	})

	it('sends no folder filter before the list has declared one', async () => {
		await store.fetchSecrets()
		expect('folderId' in lastParams()).toBe(false)
	})

	// THE BUG: this is the sidebar's refresh after a move.
	it('reuses the browsed folder on a refresh that passes nothing', async () => {
		store.setListQuery({ folderId: 'folder-8' })
		await store.fetchSecrets()

		expect(lastParams().folderId).toBe('folder-8')
	})

	it('reuses the search term and type filter too', async () => {
		store.setListQuery({ search: 'db', typeId: 'passkey' })
		await store.fetchSecrets()

		expect(lastParams().search).toBe('db')
		expect(lastParams().typeId).toBe('passkey')
	})

	it('keeps the sort field, so a refresh does not reorder the list', async () => {
		store.setListQuery({ sort: 'updatedAt' })
		await store.fetchSecrets()

		expect(lastParams().sort).toBe('updatedAt')
	})

	it('lets an explicit option override the stored query', async () => {
		store.setListQuery({ folderId: 'folder-8' })
		await store.fetchSecrets({ folderId: 'folder-9' })

		expect(lastParams().folderId).toBe('folder-9')
	})

	// Vault root: null must clear the filter, not be ignored as "not given".
	it('clears the folder filter when the list returns to the vault root', async () => {
		store.setListQuery({ folderId: 'folder-8' })
		store.setListQuery({ folderId: null })
		await store.fetchSecrets()

		expect('folderId' in lastParams()).toBe(false)
	})

	it('ignores keys the list did not mention', async () => {
		store.setListQuery({ folderId: 'folder-8', search: 'db' })
		store.setListQuery({ search: '' })

		expect(store.filters.folderId).toBe('folder-8')
		expect(store.filters.search).toBe('')
	})

	// The other direction: an export must stay the whole vault.
	it('fetches the whole vault even while a folder is being browsed', async () => {
		store.setListQuery({ folderId: 'folder-8', typeId: 'passkey', search: 'db' })
		await store.fetchAllSecrets()

		const params = lastParams()
		expect('folderId' in params).toBe(false)
		expect('typeId' in params).toBe(false)
		expect('search' in params).toBe(false)
	})

	it('still honours an explicit scope passed to fetchAllSecrets', async () => {
		store.setListQuery({ folderId: 'folder-8' })
		await store.fetchAllSecrets({ folderId: 'folder-9' })

		expect(lastParams().folderId).toBe('folder-9')
	})

	it('does not let a whole-vault fetch wipe what the list is showing', async () => {
		store.setListQuery({ folderId: 'folder-8', typeId: 'passkey' })
		await store.fetchAllSecrets()

		expect(store.filters.folderId).toBe('folder-8')
		expect(store.filters.typeId).toBe('passkey')
	})
})
