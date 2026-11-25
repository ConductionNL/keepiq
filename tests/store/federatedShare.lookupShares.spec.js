/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The batch partner certificate lookup (keepiq#1256): one request for the
 * owner's own partner shares, every answer verified in the browser against
 * the pinned partner root, and each refusal handed to share-save as the
 * single lookup's error would have been.
 *
 * @spec openspec/specs/federated-sharing/spec.md#requirement-the-owner-looks-up-their-partner-shares-certificates-in-one-request
 */

import axios from '@nextcloud/axios'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { FederatedCertificateError } from '../../src/crypto/federatedCertificate.js'
import { forgetVerifiedPartners, saveShared } from '../../src/services/shareSave.js'
import {
	CloudIdMismatchError,
	PartnerLookupRefusal,
	useFederatedShareStore,
} from '../../src/store/modules/federatedShare.js'
import { echoedCloudId, shareSaveServer } from '../fixtures/shareSaveServer.js'

const F = JSON.parse(
	readFileSync(resolve(__dirname, '../fixtures/federation-chain.json'), 'utf8'),
)
const BOB = 'bob@cloud.partner.example'
const BASE = '2026-10-01T10:00:00+00:00'

/** Bob's certificate answer with the partner A chain and pin. */
const BOB_ANSWER = {
	cloudId: BOB,
	certificate: F.bob,
	chain: [F.intermediateA, F.rootA],
	partnerRootFingerprint: F.rootAFingerprint,
	valueFields: ['totp', 'bogus'],
}

describe('lookupShares', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})
	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('sends the share ids once and verifies each answer', async () => {
		const post = vi.spyOn(axios, 'post').mockResolvedValue({
			data: { certificates: { 'fs-1': BOB_ANSWER }, refused: {} },
		})

		const results = await useFederatedShareStore().lookupShares([
			{ shareId: 'fs-1', recipientCloudId: BOB },
		])

		expect(post).toHaveBeenCalledTimes(1)
		expect(post.mock.calls[0][0]).toContain('/api/v1/federation/recipient-certificates')
		expect(post.mock.calls[0][1]).toEqual({ shareIds: ['fs-1'] })
		const { recipient } = results.get('fs-1')
		expect(recipient.cloudId).toBe(BOB)
		expect(recipient.spki).toBeInstanceOf(Uint8Array)
		// Only the value fields this app knows are kept.
		expect(recipient.valueFields).toEqual(['totp'])
		expect(recipient).not.toHaveProperty('certificate')
	})

	it('gives an answer for another cloud id, or one that does not chain, as that share\'s error', async () => {
		vi.spyOn(axios, 'post').mockResolvedValue({
			data: {
				certificates: {
					'fs-1': { ...BOB_ANSWER, cloudId: 'mallory@cloud.partner.example' },
					'fs-2': { ...BOB_ANSWER, chain: [F.intermediateA, F.intermediateA] },
				},
				refused: {},
			},
		})

		const results = await useFederatedShareStore().lookupShares([
			{ shareId: 'fs-1', recipientCloudId: BOB },
			{ shareId: 'fs-2', recipientCloudId: BOB },
		])

		expect(results.get('fs-1').error).toBeInstanceOf(CloudIdMismatchError)
		expect(results.get('fs-2').error).toBeInstanceOf(FederatedCertificateError)
	})

	it('hands a refusal on with its status and reason, and a share left out as unknown_share', async () => {
		vi.spyOn(axios, 'post').mockResolvedValue({
			data: {
				certificates: {},
				refused: { 'fs-1': { status: 403, message: 'not_a_partner' } },
			},
		})

		const results = await useFederatedShareStore().lookupShares([
			{ shareId: 'fs-1', recipientCloudId: BOB },
			{ shareId: 'fs-2', recipientCloudId: BOB },
		])

		const refused = results.get('fs-1').error
		expect(refused).toBeInstanceOf(PartnerLookupRefusal)
		expect(refused.response).toEqual({ status: 403, data: { message: 'not_a_partner' } })
		expect(results.get('fs-2').error.response).toEqual({
			status: 404,
			data: { message: 'unknown_share' },
		})
	})

	it('rejects as the request does, so share-save can wait out a 429', async () => {
		const limited = Object.assign(new Error('HTTP 429'), { response: { status: 429 } })
		vi.spyOn(axios, 'post').mockRejectedValue(limited)

		await expect(
			useFederatedShareStore().lookupShares([{ shareId: 'fs-1', recipientCloudId: BOB }]),
		).rejects.toBe(limited)
	})
})

describe('share-save over the batch lookup', () => {
	const CAROL = 'carol@cloud.partner.example'
	const DAVE = 'dave@cloud.partner.example'
	const SAVE = {
		id: 'src',
		plainDiff: { key: 'new' },
		patch: { key: 'OWN' },
		baseUpdatedAt: BASE,
		loadFull: async () => ({ key: 'old', updatedAt: BASE }),
	}

	beforeEach(() => {
		setActivePinia(createPinia())
		forgetVerifiedPartners()
	})
	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('suspends a partner refused as gone, saves the verified one and stops on none', async () => {
		const log = shareSaveServer({
			id: 'src',
			partners: [
				{ shareId: 'fs-1', recipientCloudId: BOB },
				{ shareId: 'fs-2', recipientCloudId: CAROL },
			],
			lookup: {
				[BOB]: { ...BOB_ANSWER, cloudId: echoedCloudId(BOB) },
				[CAROL]: { reject: Object.assign(new Error('x'), { response: { status: 403, data: { message: 'not_a_partner' } } }) },
			},
		})

		await saveShared(SAVE)

		expect(log.partnerBatches).toEqual([['fs-1', 'fs-2']])
		const last = log.saves.at(-1)
		expect(last.federated.map((entry) => entry.shareId)).toEqual(['fs-1'])
		expect(last.suspend).toEqual([{ shareId: 'fs-2', reason: 'not_a_partner' }])
	})

	it('does not ask again for a certificate a failed save verified', async () => {
		const partners = [
			{ shareId: 'fs-1', recipientCloudId: BOB },
			{ shareId: 'fs-3', recipientCloudId: DAVE },
		]
		const first = shareSaveServer({
			id: 'src',
			partners,
			lookup: { [BOB]: { ...BOB_ANSWER, cloudId: echoedCloudId(BOB) } },
		})
		const error = await saveShared(SAVE).catch((e) => e)
		expect(error.code).toBe('partner_unreachable')
		expect(first.partnerBatches).toEqual([['fs-1', 'fs-3']])
		vi.restoreAllMocks()

		const second = shareSaveServer({
			id: 'src',
			partners,
			lookup: { [BOB]: { ...BOB_ANSWER, cloudId: echoedCloudId(BOB) } },
		})
		await saveShared({ ...SAVE, withoutUnreachable: ['fs-3'] })

		// Bob's certificate was kept; only Dave is asked again.
		expect(second.partnerBatches).toEqual([['fs-3']])
		expect(second.saves.at(-1).suspend).toEqual([
			{ shareId: 'fs-3', reason: 'partner_unreachable' },
		])
	})
})
