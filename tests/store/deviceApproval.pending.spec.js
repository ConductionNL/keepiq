/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The approving device's side of new device approval: the open requests
 * listed with the phrase each request key yields, a denial dropping its row,
 * and a cancelled request forgetting its one-time key.
 *
 * @spec openspec/specs/new-device-approval/spec.md#requirement-both-devices-show-the-same-verification-phrase
 * @spec openspec/specs/new-device-approval/spec.md#requirement-deny-expiry-audit-and-administrator-switch
 */

import axios from '@nextcloud/axios'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { toBase64 } from '../../src/crypto/deviceApproval.js'
import { verificationPhrase } from '../../src/crypto/verificationPhrase.js'
import { useDeviceApprovalStore } from '../../src/store/modules/deviceApproval.js'

const KEY_A = new Uint8Array(32).fill(1)
const KEY_B = new Uint8Array(32).fill(2)

beforeEach(() => {
	setActivePinia(createPinia())
})

afterEach(() => {
	vi.restoreAllMocks()
})

describe('fetchPending', () => {
	it('lists each open request with the phrase of its request key', async () => {
		const get = vi.spyOn(axios, 'get').mockResolvedValue({
			data: [
				{
					id: 'r1',
					requestPublicKey: toBase64(KEY_A),
					deviceLabel: 'Laptop',
				},
				{
					id: 'r2',
					requestPublicKey: toBase64(KEY_B),
					deviceLabel: 'Phone',
				},
			],
		})
		const store = useDeviceApprovalStore()

		const pending = await store.fetchPending()

		expect(get).toHaveBeenCalledWith(
			'/apps/keepiq/api/v1/device-approvals/pending',
		)
		expect(pending).toEqual([
			{
				id: 'r1',
				requestPublicKey: toBase64(KEY_A),
				deviceLabel: 'Laptop',
				phrase: await verificationPhrase(KEY_A),
			},
			{
				id: 'r2',
				requestPublicKey: toBase64(KEY_B),
				deviceLabel: 'Phone',
				phrase: await verificationPhrase(KEY_B),
			},
		])
		expect(pending[0].phrase).not.toBe(pending[1].phrase)
		expect(store.pending).toEqual(pending)
	})

	it('treats an answer that is not a list as no requests', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: { error: 'nope' } })
		const store = useDeviceApprovalStore()
		store.pending = [{ id: 'stale' }]

		expect(await store.fetchPending()).toEqual([])
		expect(store.pending).toEqual([])
	})
})

describe('deny', () => {
	it('posts the denial and drops only that request', async () => {
		const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: {} })
		const store = useDeviceApprovalStore()
		store.pending = [{ id: 'r1' }, { id: 'r2' }]

		await store.deny('r1')

		expect(post).toHaveBeenCalledWith(
			'/apps/keepiq/api/v1/device-approvals/r1/deny',
		)
		expect(store.pending).toEqual([{ id: 'r2' }])
	})

	it('keeps the request listed when the denial fails', async () => {
		vi.spyOn(axios, 'post').mockRejectedValue(new Error('gone'))
		const store = useDeviceApprovalStore()
		store.pending = [{ id: 'r1' }]

		await expect(store.deny('r1')).rejects.toThrow('gone')
		expect(store.pending).toEqual([{ id: 'r1' }])
	})
})

describe('cancelRequest', () => {
	it('forgets the request so a later poll asks nothing', async () => {
		const get = vi.spyOn(axios, 'get')
		const store = useDeviceApprovalStore()
		store.request = { id: 'r1', status: 'pending' }
		store._oneTime = { secret: 's', privateKey: {}, publicKeyRaw: KEY_A }

		store.cancelRequest()

		expect(store.request).toBeNull()
		expect(store._oneTime).toBeNull()
		expect(await store.pollOnce()).toBe('none')
		expect(get).not.toHaveBeenCalled()
	})
})
