/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Pins the rotation & expiry store: the exact endpoints and payloads it sends
 * (flagging sends secret IDs only), the flag/policy state each action leaves
 * behind, the error message precedence on failed loads, and that a flag is
 * dropped on mark-rotated only when the server proves the rotation.
 *
 * @spec openspec/specs/rotation-expiry-policies/spec.md#requirement-rotate-after-breach-and-rotate-after-compromise-flagging
 * @spec openspec/specs/rotation-expiry-policies/spec.md#requirement-proven-mark-rotated-flow
 */

import axios from '@nextcloud/axios'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useRotationStore } from '../../src/store/modules/rotation.js'

const FLAGS_URL = '/apps/keepiq/api/v1/rotation-flags'
const POLICIES_URL = '/apps/keepiq/api/v1/expiry-policies'

describe('useRotationStore', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
	})

	describe('flagsBySecretId', () => {
		it('keys every open flag by its secret id', () => {
			const store = useRotationStore()
			store.flags = [
				{ id: 'f1', secretId: 's1' },
				{ id: 'f2', secretId: 's2' },
			]
			expect(store.flagsBySecretId).toEqual({
				s1: { id: 'f1', secretId: 's1' },
				s2: { id: 'f2', secretId: 's2' },
			})
			expect(store.flagsBySecretId.missing).toBeUndefined()
		})
	})

	describe('fetchFlags', () => {
		it('loads the flags, clears a prior error and resets loading', async () => {
			const get = vi
				.spyOn(axios, 'get')
				.mockResolvedValue({ data: [{ id: 'f1', secretId: 's1' }] })
			const store = useRotationStore()
			store.error = 'stale'

			const pending = store.fetchFlags()
			expect(store.loading).toBe(true)
			await pending

			expect(get).toHaveBeenCalledWith(FLAGS_URL)
			expect(store.flags).toEqual([{ id: 'f1', secretId: 's1' }])
			expect(store.error).toBeNull()
			expect(store.loading).toBe(false)
		})

		it('treats an empty body as no flags', async () => {
			vi.spyOn(axios, 'get').mockResolvedValue({ data: null })
			const store = useRotationStore()
			store.flags = [{ id: 'old' }]
			await store.fetchFlags()
			expect(store.flags).toEqual([])
		})

		it('surfaces the server message and rethrows', async () => {
			const err = Object.assign(new Error('Request failed'), {
				response: { data: { message: 'Not allowed' } },
			})
			vi.spyOn(axios, 'get').mockRejectedValue(err)
			const store = useRotationStore()

			await expect(store.fetchFlags()).rejects.toBe(err)
			expect(store.error).toBe('Not allowed')
			expect(store.loading).toBe(false)
		})

		it('falls back to the error message, then to a fixed text', async () => {
			const store = useRotationStore()
			vi.spyOn(axios, 'get').mockRejectedValueOnce(new Error('boom'))
			await expect(store.fetchFlags()).rejects.toThrow('boom')
			expect(store.error).toBe('boom')

			axios.get.mockRejectedValueOnce({})
			await expect(store.fetchFlags()).rejects.toEqual({})
			expect(store.error).toBe('Failed to load rotation flags')
		})
	})

	describe('fetchPolicies', () => {
		it('loads the policies', async () => {
			const get = vi
				.spyOn(axios, 'get')
				.mockResolvedValue({ data: [{ id: 'p1' }] })
			const store = useRotationStore()
			await store.fetchPolicies()
			expect(get).toHaveBeenCalledWith(POLICIES_URL)
			expect(store.policies).toEqual([{ id: 'p1' }])
		})

		it('treats an empty body as no policies', async () => {
			vi.spyOn(axios, 'get').mockResolvedValue({ data: undefined })
			const store = useRotationStore()
			store.policies = [{ id: 'old' }]
			await store.fetchPolicies()
			expect(store.policies).toEqual([])
		})

		it('records the server message, the error message or a fixed text', async () => {
			const store = useRotationStore()
			const get = vi.spyOn(axios, 'get')

			get.mockRejectedValueOnce({ response: { data: { message: 'Denied' } } })
			await expect(store.fetchPolicies()).rejects.toBeTruthy()
			expect(store.error).toBe('Denied')

			get.mockRejectedValueOnce(new Error('offline'))
			await expect(store.fetchPolicies()).rejects.toThrow('offline')
			expect(store.error).toBe('offline')

			get.mockRejectedValueOnce({})
			await expect(store.fetchPolicies()).rejects.toEqual({})
			expect(store.error).toBe('Failed to load policies')
		})
	})

	describe('upsertPolicy', () => {
		it('posts exactly the four policy fields, reloads and returns the saved policy', async () => {
			const post = vi
				.spyOn(axios, 'post')
				.mockResolvedValue({ data: { id: 'p9', scope: 'type' } })
			const get = vi
				.spyOn(axios, 'get')
				.mockResolvedValue({ data: [{ id: 'p9' }] })
			const store = useRotationStore()

			const saved = await store.upsertPolicy({
				scope: 'type',
				scopeId: 'login',
				maxAgeDays: 90,
				reminderDays: [14, 7],
				extra: 'dropped',
			})

			expect(post).toHaveBeenCalledWith(POLICIES_URL, {
				scope: 'type',
				scopeId: 'login',
				maxAgeDays: 90,
				reminderDays: [14, 7],
			})
			expect(get).toHaveBeenCalledWith(POLICIES_URL)
			expect(store.policies).toEqual([{ id: 'p9' }])
			expect(saved).toEqual({ id: 'p9', scope: 'type' })
		})
	})

	describe('deletePolicy', () => {
		it('deletes by id and removes only that policy', async () => {
			const del = vi.spyOn(axios, 'delete').mockResolvedValue({ data: {} })
			const store = useRotationStore()
			store.policies = [{ id: 'p1' }, { id: 'p2' }]

			await store.deletePolicy('p1')

			expect(del).toHaveBeenCalledWith(`${POLICIES_URL}/p1`)
			expect(store.policies).toEqual([{ id: 'p2' }])
		})

		it('keeps the policy when the delete fails', async () => {
			vi.spyOn(axios, 'delete').mockRejectedValue(new Error('403'))
			const store = useRotationStore()
			store.policies = [{ id: 'p1' }]
			await expect(store.deletePolicy('p1')).rejects.toThrow('403')
			expect(store.policies).toEqual([{ id: 'p1' }])
		})
	})

	describe('expiry', () => {
		it('reads a secret expiry', async () => {
			const body = { expiresAt: '2027-01-01', effectiveExpiry: '2027-01-01' }
			const get = vi.spyOn(axios, 'get').mockResolvedValue({ data: body })
			const store = useRotationStore()
			await expect(store.getExpiry('s1')).resolves.toEqual(body)
			expect(get).toHaveBeenCalledWith('/apps/keepiq/api/v1/secrets/s1/expiry')
		})

		it('puts the expiry, or null to clear it', async () => {
			const put = vi
				.spyOn(axios, 'put')
				.mockResolvedValue({ data: { secret: { id: 's1' } } })
			const store = useRotationStore()

			await expect(store.setExpiry('s1', '2027-02-02')).resolves.toEqual({
				secret: { id: 's1' },
			})
			expect(put).toHaveBeenLastCalledWith(
				'/apps/keepiq/api/v1/secrets/s1/expiry',
				{ expiresAt: '2027-02-02' },
			)

			await store.setExpiry('s1', null)
			expect(put).toHaveBeenLastCalledWith(
				'/apps/keepiq/api/v1/secrets/s1/expiry',
				{ expiresAt: null },
			)
		})
	})

	describe('flagSecrets', () => {
		it('sends only the ids, reloads the flags and returns the flagged count', async () => {
			const post = vi
				.spyOn(axios, 'post')
				.mockResolvedValue({ data: { flagged: 2 } })
			vi.spyOn(axios, 'get').mockResolvedValue({
				data: [{ id: 'f1', secretId: 'a' }, { id: 'f2', secretId: 'b' }],
			})
			const store = useRotationStore()

			const flagged = await store.flagSecrets(['a', 'b'])

			expect(post).toHaveBeenCalledWith(FLAGS_URL, { secretIds: ['a', 'b'] })
			expect(flagged).toBe(2)
			expect(Object.keys(store.flagsBySecretId)).toEqual(['a', 'b'])
		})

		it('returns 0 when the server reports no count', async () => {
			vi.spyOn(axios, 'post').mockResolvedValue({ data: null })
			vi.spyOn(axios, 'get').mockResolvedValue({ data: [] })
			const store = useRotationStore()
			await expect(store.flagSecrets(['a'])).resolves.toBe(0)
		})
	})

	describe('markRotated', () => {
		it('drops the flag when the server proves the rotation', async () => {
			const post = vi.spyOn(axios, 'post').mockResolvedValue({
				data: { resolved: true, requiresRotation: false },
			})
			const store = useRotationStore()
			store.flags = [{ id: 'f1' }, { id: 'f2' }]

			const result = await store.markRotated('f1')

			expect(post).toHaveBeenCalledWith(`${FLAGS_URL}/f1/rotated`)
			expect(result).toEqual({ resolved: true, requiresRotation: false })
			expect(store.flags).toEqual([{ id: 'f2' }])
		})

		it('keeps the flag when rotation is still required', async () => {
			vi.spyOn(axios, 'post').mockResolvedValue({
				data: { resolved: false, requiresRotation: true },
			})
			const store = useRotationStore()
			store.flags = [{ id: 'f1' }]

			const result = await store.markRotated('f1')

			expect(result.requiresRotation).toBe(true)
			expect(store.flags).toEqual([{ id: 'f1' }])
		})

		it('keeps the flag on a truthy but non-true resolved', async () => {
			vi.spyOn(axios, 'post').mockResolvedValue({ data: { resolved: 'yes' } })
			const store = useRotationStore()
			store.flags = [{ id: 'f1' }]
			await store.markRotated('f1')
			expect(store.flags).toEqual([{ id: 'f1' }])
		})
	})

	describe('dismissFlag', () => {
		it('posts the dismissal and removes the flag', async () => {
			const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: {} })
			const store = useRotationStore()
			store.flags = [{ id: 'f1' }, { id: 'f2' }]

			await store.dismissFlag('f2')

			expect(post).toHaveBeenCalledWith(`${FLAGS_URL}/f2/dismiss`)
			expect(store.flags).toEqual([{ id: 'f1' }])
		})
	})
})
