/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Both list loads declare the whole query to the store before fetching: a
 * reload fetches page 1, paging fetches the target page, and neither drops
 * a filter. Options-object style, like SecretList.defaultView.spec.js.
 *
 * @spec openspec/specs/secrets/spec.md#requirement-list-and-pagination
 */

import { describe, expect, it, vi } from 'vitest'
import SecretList from '../../src/views/SecretList.vue'

const QUERY = {
	folderId: 'folder-1',
	search: 'mail',
	sort: 'name',
	typeId: 'type-login',
	state: 'active',
	favourite: true,
	tag: 'work',
}

/**
 * @return {object} A list instance with every filter set and a store spy.
 */
function list() {
	const calls = []
	const ctx = {
		selectedFolderId: QUERY.folderId,
		searchTerm: QUERY.search,
		sortField: QUERY.sort,
		typeFilter: QUERY.typeId,
		listState: QUERY.state,
		favouriteFilter: QUERY.favourite,
		tagFilter: QUERY.tag,
		secretStore: {
			setListQuery: vi.fn((query) => calls.push(['setListQuery', query])),
			fetchSecrets: vi.fn(async (options) => calls.push(['fetchSecrets', options])),
		},
		calls,
	}
	return Object.assign(ctx, SecretList.methods)
}

describe('SecretList list query', () => {
	it('reload declares the whole query, then fetches page 1', async () => {
		const ctx = list()
		await ctx.reload()
		expect(ctx.calls).toEqual([
			['setListQuery', QUERY],
			['fetchSecrets', { page: 1 }],
		])
		expect(Object.keys(ctx.calls[0][1])).toEqual(Object.keys(QUERY))
	})

	it('paging declares the same query, then fetches the target page', () => {
		const ctx = list()
		ctx.goToPage(3)
		expect(ctx.calls).toEqual([
			['setListQuery', QUERY],
			['fetchSecrets', { page: 3 }],
		])
		expect(Object.keys(ctx.calls[0][1])).toEqual(Object.keys(QUERY))
	})
})
