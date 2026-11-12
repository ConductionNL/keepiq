/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Pins the edges of sharing with a group before it is split into named
 * steps: an answer without a group share or without members registers
 * nothing, the counts it returns, how the list of group shares is updated,
 * the error text a failure leaves, and how sharee search rows become
 * unique, labelled, capped groups.
 *
 * @spec openspec/specs/sharing-group/spec.md#requirement-share-with-a-group
 */

import axios from '@nextcloud/axios'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useGroupShareStore } from '../../src/store/modules/groupShare.js'
import { useShareStore } from '../../src/store/modules/share.js'

beforeEach(() => {
	setActivePinia(createPinia())
	vi.restoreAllMocks()
})

describe('shareWithGroup', () => {
	it('posts the group with its restriction and registers nothing without a group share', async () => {
		const post = vi.spyOn(axios, 'post').mockResolvedValue({
			data: { groupShare: null, members: [{ userId: 'u' }], skipped: '2' },
		})
		const store = useGroupShareStore()
		const encrypt = vi.spyOn(store, 'encryptForMembers')
		store.groupShares = [{ id: 'old' }]
		const result = await store.shareWithGroup('s-1', 'team', {
			useOnly: true,
			expiresAt: 'E',
		})
		expect(post).toHaveBeenCalledWith('/apps/keepiq/api/v1/secrets/s-1/group-shares', {
			groupId: 'team',
			useOnly: true,
			expiresAt: 'E',
		})
		expect(encrypt).not.toHaveBeenCalled()
		expect(result).toEqual({ received: 0, skipped: 2, refused: 0 })
		expect(store.groupShares).toEqual([{ id: 'old' }])
		expect(store.loading).toBe(false)
	})

	it('keeps a group share without members, replacing an older entry of it', async () => {
		vi.spyOn(axios, 'post').mockResolvedValue({
			data: { groupShare: { id: 'gs', v: 2 }, members: 'not a list' },
		})
		const store = useGroupShareStore()
		store.groupShares = [{ id: 'gs', v: 1 }, { id: 'other' }]
		const result = await store.shareWithGroup('s-1', 'team')
		expect(result).toEqual({ received: 0, skipped: 0, refused: 0 })
		expect(store.groupShares).toEqual([{ id: 'other' }, { id: 'gs', v: 2 }])
	})

	it('counts copies made, refused for a mismatch, and left out', async () => {
		vi.spyOn(axios, 'post').mockResolvedValue({
			data: {
				groupShare: { id: 'gs' },
				members: [{ userId: 'a' }, { userId: 'b' }, { userId: 'c' }, { userId: 'd' }],
				skipped: 1,
			},
		})
		const store = useGroupShareStore()
		const encrypt = vi.spyOn(store, 'encryptForMembers').mockResolvedValue(['rows'])
		const register = vi.spyOn(useShareStore(), 'registerBatch').mockResolvedValue({
			items: [
				{ status: 'created' },
				{ status: 'exists' },
				{ status: 'copy_fields_mismatch' },
				{ status: 'no_suite' },
			],
		})
		const result = await store.shareWithGroup('s-1', 'team')
		expect(encrypt).toHaveBeenCalledWith('s-1', 'gs', [
			{ userId: 'a' },
			{ userId: 'b' },
			{ userId: 'c' },
			{ userId: 'd' },
		])
		expect(register).toHaveBeenCalledWith(['rows'])
		expect(result).toEqual({ received: 2, skipped: 2, refused: 1 })
	})

	it("keeps the server's message, else the error's, else its own text", async () => {
		const post = vi.spyOn(axios, 'post')
		const store = useGroupShareStore()
		post.mockRejectedValueOnce({ response: { data: { message: 'no' } } })
		await expect(store.shareWithGroup('s', 'g')).rejects.toBeTruthy()
		expect(store.error).toBe('no')
		post.mockRejectedValueOnce(new Error('down'))
		await expect(store.shareWithGroup('s', 'g')).rejects.toBeTruthy()
		expect(store.error).toBe('down')
		post.mockRejectedValueOnce({})
		await expect(store.shareWithGroup('s', 'g')).rejects.toBeTruthy()
		expect(store.error).toBe('Failed to share')
		expect(store.loading).toBe(false)
	})
})

describe('searchGroups', () => {
	it('merges exact and other matches, drops empty and repeated ids, labels and caps them', async () => {
		const groups = Array.from({ length: 30 }, (_, i) => ({
			label: i % 2 ? `G${i}` : '',
			value: { shareWith: `g${i}` },
		}))
		const get = vi.spyOn(axios, 'get').mockResolvedValue({
			data: {
				ocs: {
					data: {
						exact: { groups: [{ label: 'Exact', value: { shareWith: 'g1' } }] },
						groups: [{ value: {} }, null, ...groups],
					},
				},
			},
		})
		const result = await useGroupShareStore().searchGroups('g')
		expect(get.mock.calls[0]).toMatchSnapshot()
		expect(result).toMatchSnapshot()
	})

	it('answers nothing for an answer without groups', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({
			data: { ocs: { data: { exact: { groups: 'x' } } } },
		})
		await expect(useGroupShareStore().searchGroups('g')).resolves.toEqual([])
		axios.get.mockResolvedValue({ data: null })
		await expect(useGroupShareStore().searchGroups('g')).resolves.toEqual([])
	})
})
