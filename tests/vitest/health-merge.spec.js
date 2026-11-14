/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for merging a duplicate group (src/health/merge.js): the kept
 * secret is saved with the others' fields folded in through the owner's
 * update path, the others go through the delete path, and the people who
 * lose access are the distinct recipients of the removed items' shares.
 *
 * @spec openspec/changes/vault-duplicate-finder/specs/vault-duplicates/spec.md#requirement-merge-a-duplicate-group
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

const calls = []
const secrets = {}
let failDelete = ''

vi.mock('@nextcloud/router', () => ({ generateUrl: (p) => p }))
vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn(async (url) => {
			calls.push(['get', url])
			const shares = {
				'/apps/keepiq/api/v1/secrets/b/shares': [{ targetUserId: 'ben' }, { targetUserId: 'cas' }],
				'/apps/keepiq/api/v1/secrets/c/shares': [{ targetUserId: 'ben' }, { groupShareId: 'g' }],
			}
			return { data: shares[url] ?? [] }
		}),
	},
}))
vi.mock('../../src/store/modules/secret.js', () => ({
	useSecretStore: () => ({
		fetchSecret: async (id) => secrets[id],
		writeBackSettled: async (id) => calls.push(['writeBackSettled', id]),
		updateSecret: async (id, data, options) => calls.push(['update', id, data, options]),
		deleteSecret: async (id) => {
			if (id === failDelete) {
				throw new Error('refused')
			}
			calls.push(['delete', id])
		},
	}),
}))

const { loadGroup, mergedFields, mergeGroup, peopleLosingAccess } = await import('../../src/health/merge.js')

describe('merging a duplicate group', () => {
	beforeEach(() => {
		calls.length = 0
		failDelete = ''
		secrets.a = { id: 'a', name: 'GitHub', updatedAt: 'U-a', additionalFields: { pin: '1234' } }
		secrets.b = { id: 'b', name: 'github.com', updatedAt: 'U-b', additionalFields: { 'recovery email': 'anna@home.example', pin: '9999' } }
		secrets.c = { id: 'c', name: 'GitHub (2)', updatedAt: 'U-c', additionalFields: null }
	})

	it('loads the group decrypted, in order', async () => {
		expect((await loadGroup(['b', 'a'])).map((s) => s.id)).toEqual(['b', 'a'])
	})

	it('saves the kept secret with the folded fields, then deletes the others', async () => {
		const result = await mergeGroup(secrets.a, [secrets.b, secrets.c])
		expect(result).toEqual({ removed: ['b', 'c'] })
		expect(calls).toEqual([
			['writeBackSettled', 'a'],
			['update', 'a', { additionalFields: { pin: '1234', 'recovery email': 'anna@home.example', 'pin (2)': '9999' } }, { baseUpdatedAt: 'U-a' }],
			['delete', 'b'],
			['delete', 'c'],
		])
	})

	it('does not re-save the kept secret when nothing folds in', async () => {
		await mergeGroup(secrets.a, [secrets.c])
		expect(calls).toEqual([['delete', 'c']])
	})

	it('carries the request-filled pending count with a field change', async () => {
		secrets.a.mergedPending = 2
		await mergeGroup(secrets.a, [secrets.b])
		expect(calls[1][2].mergedPending).toBe(2)
	})

	it('stops at the first refused delete', async () => {
		failDelete = 'b'
		await expect(mergeGroup(secrets.a, [secrets.b, secrets.c])).rejects.toThrow('refused')
		expect(calls.some(([op, id]) => op === 'delete' && id === 'c')).toBe(false)
	})

	it('previews the fields the kept secret will carry', () => {
		expect(Object.keys(mergedFields(secrets.c, [secrets.a, secrets.b]))).toEqual(['pin', 'recovery email', 'pin (2)'])
	})

	it('counts the distinct people who lose access through the removed items\' shares', async () => {
		expect(await peopleLosingAccess(['b', 'c'])).toBe(2)
		expect(await peopleLosingAccess([])).toBe(0)
	})
})
