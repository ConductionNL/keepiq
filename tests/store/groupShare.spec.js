/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * useGroupShareStore: the three group-share routes and the per-member
 * fan-out through register-batch (sharing-02).
 *
 * @spec openspec/specs/sharing-group/spec.md#requirement-share-with-a-group
 */

import axios from '@nextcloud/axios'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useGroupShareStore } from '../../src/store/modules/groupShare.js'

// Sharing needs a vault-key proof (keepiq#818). The proof itself is built by
// sessionKeyProofHeaders; here it returns a fixed header and echoes the
// password, so the tests can see that each request carried a proof.
vi.mock('../../src/crypto/keyProof.js', async (importOriginal) => ({
	...(await importOriginal()),
	sessionKeyProofHeaders: vi.fn(
		async ({ purpose, boundValues, masterPassword }) => ({
			headers: {
				'X-Keepiq-Key-Proof': `proof(${purpose}|${(boundValues ?? []).join(',')})`,
			},
			masterPassword: masterPassword || 'from-prompt',
		}),
	),
}))
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
		const post = vi
			.spyOn(axios, 'post')
			.mockImplementation(async (url, body) => {
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
							status:
								row.targetUserId === 'dave' ? 'no_suite' : 'created',
						})),
					},
				}
			})
		useSecretStore().fetchSecret = vi.fn().mockResolvedValue({
			key: 'hunter2',
			login: 'alice',
			additionalFields: {},
		})
		const encrypt = vi
			.spyOn(useShareStore(), 'encryptForRecipient')
			.mockImplementation(async (snapshot, cert) => ({
				key: `enc(${snapshot.key},${cert})`,
			}))
		const store = useGroupShareStore()

		const result = await store.shareWithGroup('s-1', 'finance')

		expect(post).toHaveBeenNthCalledWith(
			1,
			'/apps/keepiq/api/v1/secrets/s-1/group-shares',
			{ groupId: 'finance' },
		)
		expect(encrypt).toHaveBeenCalledTimes(3)
		const [url, body, config] = post.mock.calls[1]
		expect(url).toBe('/apps/keepiq/api/v1/shares/register-batch')
		// keepiq#818: the batch registration carries a proof.
		expect(config.headers).toEqual({
			'X-Keepiq-Key-Proof': 'proof(share-register-batch|)',
		})
		expect(body.shares[0]).toEqual({
			sourceSecretId: 's-1',
			targetUserId: 'bob',
			encryptedKey: 'enc(hunter2,PEM-BOB)',
			encryptedLogin: null,
			encryptedAdditionalFields: null,
			encryptedTotp: null,
			encryptedPasskeys: null,
			groupShareId: 'gs-1',
		})
		// bob and carol received it; dave failed at registration and the
		// server skipped one member without a suite.
		expect(result).toEqual({ received: 2, skipped: 2, refused: 0 })
		expect(store.groupShares).toEqual([{ id: 'gs-1', groupId: 'finance' }])
	})

	it("encrypts a login's seed and passkeys into each member's copy (keepiq#1326)", async () => {
		useSecretStore().fetchSecret = vi.fn().mockResolvedValue({
			key: 'hunter2',
			login: '',
			additionalFields: null,
			totp: 'JBSWY3DPEHPK3PXP',
			passkeys: '[{"credentialId":"c1"}]',
		})
		const encrypt = vi
			.spyOn(useShareStore(), 'encryptForRecipient')
			.mockImplementation(async (snapshot, cert) =>
				Object.fromEntries(
					Object.entries(snapshot)
						.filter(([, value]) => value !== '')
						.map(([field, value]) => [field, `enc(${value},${cert})`]),
				),
			)

		const [row] = await useGroupShareStore().encryptForMembers('s-1', 'gs-1', [
			{ userId: 'bob', certificate: 'PEM-BOB' },
		])

		expect(encrypt.mock.calls[0][0]).toMatchObject({
			totp: 'JBSWY3DPEHPK3PXP',
			passkeys: '[{"credentialId":"c1"}]',
		})
		expect(row).toMatchObject({
			encryptedKey: 'enc(hunter2,PEM-BOB)',
			encryptedLogin: null,
			encryptedTotp: 'enc(JBSWY3DPEHPK3PXP,PEM-BOB)',
			encryptedPasskeys: 'enc([{"credentialId":"c1"}],PEM-BOB)',
		})
	})

	it('counts the members refused for a one-time code or passkeys apart from those without a suite', async () => {
		vi.spyOn(axios, 'post')
			.mockResolvedValueOnce({
				data: {
					groupShare: { id: 'gs-1', groupId: 'finance' },
					members: [
						{ userId: 'bob', certificate: 'PEM-BOB' },
						{ userId: 'carol', certificate: 'PEM-CAROL' },
					],
					skipped: 0,
				},
			})
			.mockResolvedValueOnce({
				data: {
					items: [
						{ targetUserId: 'bob', status: 'copy_fields_mismatch' },
						{ targetUserId: 'carol', status: 'no_suite' },
					],
				},
			})
		useSecretStore().fetchSecret = vi.fn().mockResolvedValue({
			key: 'hunter2',
			login: 'alice',
			additionalFields: {},
		})
		vi.spyOn(useShareStore(), 'encryptForRecipient').mockResolvedValue({
			key: 'enc',
		})

		const result = await useGroupShareStore().shareWithGroup('s-1', 'finance')

		expect(result).toEqual({ received: 0, skipped: 1, refused: 1 })
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
						exact: {
							groups: [
								{
									label: 'Finance',
									value: { shareWith: 'finance' },
								},
							],
						},
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

		expect(get.mock.calls[0][1].params).toMatchObject({
			search: 'fin',
			shareType: 1,
		})
		expect(groups).toEqual([
			{ id: 'finance', label: 'Finance' },
			{ id: 'board', label: 'Board' },
		])
	})
})
