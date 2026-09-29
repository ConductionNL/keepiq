/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * useGroupShareStore: the three group-share routes and the per-member
 * fan-out through register-batch (sharing-02).
 *
 * @spec openspec/changes/sharing-group-share-entry-point/specs/sharing-group/spec.md#requirement-share-with-a-group
 */

import axios from '@nextcloud/axios'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useGroupShareStore } from '../../src/store/modules/groupShare.js'
import { useSecretStore } from '../../src/store/modules/secret.js'
import { useShareStore } from '../../src/store/modules/share.js'

describe('useGroupShareStore', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
	})

	it('lists the group shares of a secret', async () => {
		const get = vi
			.spyOn(axios, 'get')
			.mockResolvedValue({ data: [{ id: 'gs-1', groupId: 'finance' }] })
		const store = useGroupShareStore()

		await store.fetchGroupShares('s-1')

		expect(get).toHaveBeenCalledWith(
			'/apps/keepiq/api/v1/secrets/s-1/group-shares',
		)
		expect(store.groupShares).toEqual([{ id: 'gs-1', groupId: 'finance' }])
	})

	it('shares with a group: creates the group share, encrypts per member, registers the copies linked to it', async () => {
		const post = vi.spyOn(axios, 'post').mockImplementation(async (url, body) => {
			if (url.endsWith('/group-shares')) {
				return {
					data: {
						groupShare: { id: 'gs-1', groupId: 'finance' },
						members: [
							{ userId: 'bob', certificate: 'PEM-BOB' },
							{ userId: 'carol', certificate: 'PEM-CAROL' },
							{ userId: 'dave', certificate: 'PEM-DAVE' },
						],
						skipped: 1,
					},
				}
			}
			return {
				data: {
					items: body.shares.map((row) => ({
						targetUserId: row.targetUserId,
						status: row.targetUserId === 'dave' ? 'no_suite' : 'created',
					})),
				},
			}
		})
		useSecretStore().fetchSecret = vi
			.fn()
			.mockResolvedValue({ key: 'hunter2', login: 'alice', additionalFields: {} })
		const encrypt = vi
			.spyOn(useShareStore(), 'encryptForRecipient')
			.mockImplementation(async (snapshot, cert) => ({ key: `enc(${snapshot.key},${cert})` }))
		const store = useGroupShareStore()

		const result = await store.shareWithGroup('s-1', 'finance')

		expect(post).toHaveBeenNthCalledWith(
			1,
			'/apps/keepiq/api/v1/secrets/s-1/group-shares',
			{ groupId: 'finance' },
		)
		expect(encrypt).toHaveBeenCalledTimes(3)
		const [url, body] = post.mock.calls[1]
		expect(url).toBe('/apps/keepiq/api/v1/shares/register-batch')
		expect(body.shares[0]).toEqual({
			sourceSecretId: 's-1',
			targetUserId: 'bob',
			encryptedKey: 'enc(hunter2,PEM-BOB)',
			encryptedLogin: null,
			encryptedAdditionalFields: null,
			groupShareId: 'gs-1',
		})
		// bob and carol received it; dave failed at registration and the
		// server skipped one member without a suite.
		expect(result).toEqual({ received: 2, skipped: 2 })
		expect(store.groupShares).toEqual([{ id: 'gs-1', groupId: 'finance' }])
	})

	it('revokes a group share and drops it from the list', async () => {
		const del = vi.spyOn(axios, 'delete').mockResolvedValue({ data: {} })
		const store = useGroupShareStore()
		store.groupShares = [{ id: 'gs-1' }, { id: 'gs-2' }]

		await store.revokeGroupShare('gs-1')

		expect(del).toHaveBeenCalledWith('/apps/keepiq/api/v1/group-shares/gs-1')
		expect(store.groupShares).toEqual([{ id: 'gs-2' }])
	})

	it('searches groups through Nextcloud sharee search, groups only', async () => {
		const get = vi.spyOn(axios, 'get').mockResolvedValue({
			data: {
				ocs: {
					data: {
						exact: { groups: [{ label: 'Finance', value: { shareWith: 'finance' } }] },
						groups: [
							{ label: 'Finance', value: { shareWith: 'finance' } },
							{ label: 'Board', value: { shareWith: 'board' } },
						],
					},
				},
			},
		})
		const store = useGroupShareStore()

		const groups = await store.searchGroups('fin')

		expect(get.mock.calls[0][1].params).toMatchObject({ search: 'fin', shareType: 1 })
		expect(groups).toEqual([
			{ id: 'finance', label: 'Finance' },
			{ id: 'board', label: 'Board' },
		])
	})
})
