/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Favourites, tags and last used in the secret store
 * (vault-favourites-tags-and-last-used): the list asks for a favourite or
 * tag filter and keeps asking on a refresh, the whole-vault fetch an export
 * uses never carries them, and the star and tag actions hit their own
 * endpoints and update the row in place.
 *
 * @spec openspec/changes/vault-favourites-tags-and-last-used/specs/vault-list-organisation/spec.md#requirement-favourite-items-per-holder
 * @spec openspec/changes/vault-favourites-tags-and-last-used/specs/vault-list-organisation/spec.md#requirement-tags-per-holder
 * @spec openspec/changes/vault-favourites-tags-and-last-used/specs/vault-list-organisation/spec.md#requirement-sort-by-date-last-used
 */

import axios from '@nextcloud/axios'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useSecretStore } from '../../src/store/modules/secret.js'

/**
 * The `params` of the most recent GET to the secrets list.
 *
 * @return {object} Query params axios was called with.
 */
function lastListParams() {
	const calls = axios.get.mock.calls.filter(([url]) =>
		url.endsWith('/api/v1/secrets'),
	)
	return calls[calls.length - 1][1].params
}

describe('secret store: favourites, tags and last used', () => {
	let store

	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
		store = useSecretStore()
		vi.spyOn(axios, 'get').mockImplementation(async (url) => {
			if (url.endsWith('/api/v1/tags')) {
				return { data: { tags: [{ tag: 'finance', count: 2 }] } }
			}
			return { data: { items: [], total: 0, page: 1 } }
		})
	})

	it('asks for no favourite or tag filter by default', async () => {
		await store.fetchSecrets()
		expect('favourite' in lastListParams()).toBe(false)
		expect('tag' in lastListParams()).toBe(false)
	})

	it('asks for favourites and a tag, and keeps asking on a bare refresh', async () => {
		store.setListQuery({ favourite: true, tag: 'finance' })
		await store.fetchSecrets()
		expect(lastListParams().favourite).toBe(1)
		expect(lastListParams().tag).toBe('finance')
	})

	it('sorts by last used, newest first', async () => {
		store.setListQuery({ sort: 'last_used_at' })
		await store.fetchSecrets()
		expect(lastListParams().sort).toBe('last_used_at')
		expect(lastListParams().direction).toBe('desc')
	})

	it('goes back to ascending when the sort returns to name', async () => {
		store.setListQuery({ sort: 'last_used_at' })
		store.setListQuery({ sort: 'name' })
		await store.fetchSecrets()
		expect(lastListParams().direction).toBe('asc')
	})

	it('never narrows the whole-vault fetch an export uses', async () => {
		store.setListQuery({ favourite: true, tag: 'finance' })
		await store.fetchAllSecrets()
		expect('favourite' in lastListParams()).toBe(false)
		expect('tag' in lastListParams()).toBe(false)
	})

	it('stars through PUT and marks the row in place', async () => {
		const put = vi
			.spyOn(axios, 'put')
			.mockResolvedValue({ data: { id: 'a', favourite: true } })
		store.secrets = [{ id: 'a', favourite: false }]
		await store.setFavourite('a', true)
		expect(put).toHaveBeenCalledWith(
			expect.stringContaining('/api/v1/secrets/a/favourite'),
			{ favourite: true },
		)
		expect(store.secrets[0].favourite).toBe(true)
	})

	it('drops an unstarred row from the Favourites view', async () => {
		vi.spyOn(axios, 'put').mockResolvedValue({
			data: { id: 'a', favourite: false },
		})
		store.setListQuery({ favourite: true })
		store.secrets = [
			{ id: 'a', favourite: true },
			{ id: 'b', favourite: true },
		]
		store.totalCount = 2
		await store.setFavourite('a', false)
		expect(store.secrets.map((s) => s.id)).toEqual(['b'])
		expect(store.totalCount).toBe(1)
	})

	it('sets tags through PUT, keeps the server normalised set, and reloads the tag list', async () => {
		const put = vi
			.spyOn(axios, 'put')
			.mockResolvedValue({ data: { id: 'a', tags: ['on call'] } })
		store.secrets = [{ id: 'a', tags: [] }]
		await store.setTags('a', ['On Call'])
		expect(put).toHaveBeenCalledWith(
			expect.stringContaining('/api/v1/secrets/a/tags'),
			{ tags: ['On Call'] },
		)
		expect(store.secrets[0].tags).toEqual(['on call'])
		expect(store.tags).toEqual([{ tag: 'finance', count: 2 }])
	})

	it('removes one tag in bulk and leaves the others', async () => {
		const put = vi.spyOn(axios, 'put').mockImplementation(async (url, body) => ({
			data: { id: url.split('/').at(-2), tags: body.tags },
		}))
		store.secrets = [
			{ id: 'a', tags: ['finance', 'on call'] },
			{ id: 'b', tags: ['finance'] },
		]
		await store.changeTagInBulk(['a', 'b'], 'finance', false)
		expect(put).toHaveBeenCalledTimes(2)
		expect(store.secrets[0].tags).toEqual(['on call'])
		expect(store.secrets[1].tags).toEqual([])
	})

	it('adds one tag in bulk without duplicating it', async () => {
		const put = vi.spyOn(axios, 'put').mockImplementation(async (url, body) => ({
			data: { id: url.split('/').at(-2), tags: body.tags },
		}))
		store.secrets = [
			{ id: 'a', tags: ['finance'] },
			{ id: 'b', tags: [] },
		]
		await store.changeTagInBulk(['a', 'b'], 'finance', true)
		expect(put).toHaveBeenCalledTimes(1)
		expect(store.secrets[1].tags).toEqual(['finance'])
	})
})
