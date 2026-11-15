/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden for the user sharee search: one request to Nextcloud's sharee
 * search for users only, exact matches first, each id once, a row without
 * an id passed over, labelled by the display name or else the id, and
 * capped at the page size.
 *
 * @spec openspec/specs/user-sharing/spec.md#requirement-recipient-shareability-lookup
 */

import axios from '@nextcloud/axios'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useShareStore } from '../../src/store/modules/share.js'

beforeEach(() => {
	setActivePinia(createPinia())
})

afterEach(() => {
	vi.restoreAllMocks()
})

describe('searchSharees', () => {
	it('merges exact and other matches, drops empty and repeated ids, labels and caps them', async () => {
		const users = Array.from({ length: 30 }, (_, i) => ({
			label: i % 2 ? `User ${i}` : '',
			value: { shareWith: `u${i}` },
		}))
		const get = vi.spyOn(axios, 'get').mockResolvedValue({
			data: {
				ocs: {
					data: {
						exact: { users: [{ label: 'Exact', value: { shareWith: 'u1' } }] },
						users: [{ value: {} }, null, { label: 'Zero', value: { shareWith: 0 } }, ...users],
					},
				},
			},
		})
		const result = await useShareStore().searchSharees('u', 5)
		expect(get.mock.calls[0]).toMatchSnapshot()
		expect(result).toEqual([
			{ id: 'u1', label: 'Exact' },
			{ id: '0', label: 'Zero' },
			{ id: 'u0', label: 'u0' },
			{ id: 'u2', label: 'u2' },
			{ id: 'u3', label: 'User 3' },
		])
	})

	it('answers nothing for an answer without users', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({
			data: { ocs: { data: { exact: { users: 'x' } } } },
		})
		await expect(useShareStore().searchSharees('u')).resolves.toEqual([])
		axios.get.mockResolvedValue({ data: null })
		await expect(useShareStore().searchSharees('u')).resolves.toEqual([])
	})
})
