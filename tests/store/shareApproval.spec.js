/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * useShareApprovalStore: the owner answers a share request or a new group
 * member (#747). Approving encrypts in the tab and registers one copy;
 * denying calls the deny endpoint.
 *
 * @spec openspec/specs/user-sharing/spec.md#requirement-share-request-recipient-initiated
 * @spec openspec/specs/user-sharing/spec.md#requirement-new-group-member-owner-notification
 */

import axios from '@nextcloud/axios'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useSecretStore } from '../../src/store/modules/secret.js'
import { useShareStore } from '../../src/store/modules/share.js'
import { useShareApprovalStore } from '../../src/store/modules/shareApproval.js'

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

const REQUEST = { sourceSecretId: 's-1', requesterId: 'bob', targetUserId: 'carol' }

/**
 * Stub the server: recipient lookup, register-batch and the confirm calls.
 *
 * @param {object} options What the server answers.
 * @param {boolean} [options.shareable] Whether the recipient has a suite.
 * @param {string} [options.status] The register-batch status.
 * @return {object} The axios.post spy.
 */
function stubServer({ shareable = true, status = 'created' } = {}) {
	useSecretStore().fetchSecret = vi
		.fn()
		.mockResolvedValue({ key: 'hunter2', login: 'alice', additionalFields: {} })
	vi.spyOn(useShareStore(), 'encryptForRecipient').mockImplementation(
		async (snapshot, cert) => ({ key: `enc(${snapshot.key},${cert})` }),
	)
	return vi.spyOn(axios, 'post').mockImplementation(async (url, body) => {
		if (url.endsWith('/shares/recipient-certificates')) {
			return {
				data: {
					recipients: [
						shareable
							? {
									userId: body.userIds[0],
									shareable: true,
									certificate: 'PEM',
								}
							: { userId: body.userIds[0], shareable: false },
					],
				},
			}
		}
		if (url.endsWith('/shares/register-batch')) {
			return { data: { items: [{ status }] } }
		}
		return { data: {} }
	})
}

describe('useShareApprovalStore', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
	})

	it('approves a share request: encrypts for the target, registers a direct copy, then confirms', async () => {
		const post = stubServer()

		const status = await useShareApprovalStore().approveShareRequest(REQUEST)

		expect(status).toBe('created')
		const urls = post.mock.calls.map(([url]) => url)
		expect(urls).toEqual([
			'/apps/keepiq/api/v1/shares/recipient-certificates',
			'/apps/keepiq/api/v1/shares/register-batch',
			'/apps/keepiq/api/v1/share-requests/approve',
		])
		// keepiq#818: the batch registration carries a proof.
		expect(post.mock.calls[1][2].headers).toEqual({
			'X-Keepiq-Key-Proof': 'proof(share-register-batch|)',
		})
		expect(post.mock.calls[1][1].shares[0]).toMatchObject({
			sourceSecretId: 's-1',
			targetUserId: 'carol',
			encryptedKey: 'enc(hunter2,PEM)',
			groupShareId: null,
		})
		expect(post.mock.calls[2][1]).toEqual(REQUEST)
	})

	it('does not confirm a request when the copy was not registered', async () => {
		const post = stubServer({ status: 'not_owned' })

		const status = await useShareApprovalStore().approveShareRequest(REQUEST)

		expect(status).toBe('not_owned')
		expect(post.mock.calls.map(([url]) => url)).not.toContain(
			'/apps/keepiq/api/v1/share-requests/approve',
		)
	})

	it('shares nothing with a recipient who has no suite', async () => {
		const post = stubServer({ shareable: false })

		const status = await useShareApprovalStore().approveShareRequest(REQUEST)

		expect(status).toBe('no_suite')
		expect(post).toHaveBeenCalledTimes(1)
	})

	it('denies a share request', async () => {
		const post = stubServer()

		await useShareApprovalStore().denyShareRequest(REQUEST)

		expect(post).toHaveBeenCalledWith(
			'/apps/keepiq/api/v1/share-requests/deny',
			REQUEST,
		)
	})

	it('approves a new group member with a copy linked to the group share', async () => {
		const post = stubServer()

		const status = await useShareApprovalStore().approveGroupMember({
			groupShareId: 'gs-1',
			newMemberId: 'dave',
			secretId: 's-1',
		})

		expect(status).toBe('created')
		expect(post.mock.calls[1][1].shares[0]).toMatchObject({
			targetUserId: 'dave',
			groupShareId: 'gs-1',
		})
	})

	it('denies a new group member', async () => {
		const post = stubServer()

		await useShareApprovalStore().denyGroupMember({
			groupShareId: 'gs-1',
			newMemberId: 'dave',
		})

		expect(post).toHaveBeenCalledWith(
			'/apps/keepiq/api/v1/group-shares/gs-1/deny-new-member',
			{ newMemberId: 'dave' },
		)
	})
})
