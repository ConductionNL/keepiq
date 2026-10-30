/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for `useShareStore` (`src/store/modules/share.js`).
 *
 * @spec openspec/changes/implement-user-sharing/tasks.md#16.1
 */

import axios from '@nextcloud/axios'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useShareStore } from '../../src/store/modules/share.js'

vi.mock('../../src/crypto/index.js', () => ({
	importPublicKey: vi.fn(async () => 'PUBKEY_HANDLE'),
	rsaEncrypt: vi.fn(async (value) => `ENC(${value})`),
}))

// keepiq#818: a share to a new recipient is retried with a vault-key proof.
vi.mock('../../src/crypto/keyProof.js', async (importOriginal) => ({
	...(await importOriginal()),
	sessionKeyProofHeaders: vi.fn(async ({ purpose, boundValues }) => ({
		headers: {
			'X-Keepiq-Key-Proof': `proof(${purpose}|${boundValues.join(',')})`,
		},
		masterPassword: 'pw',
	})),
}))

describe('useShareStore', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
	})

	describe('fetchShares', () => {
		it('hydrates the shares list', async () => {
			vi.spyOn(axios, 'get').mockResolvedValue({
				data: [
					{ id: 's1', target_user_id: 'alice' },
					{ id: 's2', target_user_id: 'bob', group_share_id: 'g1' },
				],
			})
			const store = useShareStore()
			await store.fetchShares('sec-1')

			expect(store.shares).toHaveLength(2)
			expect(store.recipientCount).toBe(2)
			expect(store.loading).toBe(false)
		})

		it('captures the error message and rethrows', async () => {
			vi.spyOn(axios, 'get').mockRejectedValue({
				response: { data: { message: 'nope' } },
			})
			const store = useShareStore()
			await expect(store.fetchShares('sec-1')).rejects.toBeTruthy()
			expect(store.error).toBe('nope')
		})
	})

	describe('encryptForRecipient', () => {
		it('produces a base64 envelope for each non-empty field', async () => {
			const store = useShareStore()
			const out = await store.encryptForRecipient(
				{ key: 'p4ss', login: 'alice', empty: '' },
				'PEM',
			)
			expect(out).toEqual({ key: 'ENC(p4ss)', login: 'ENC(alice)' })
		})

		it('errors when the recipient certificate is missing', async () => {
			const store = useShareStore()
			await expect(
				store.encryptForRecipient({ key: 'x' }, ''),
			).rejects.toThrow(/encryption suite/)
		})
	})

	describe('revokeShare', () => {
		it('DELETEs and removes the share from the list', async () => {
			vi.spyOn(axios, 'delete').mockResolvedValue({ data: {} })
			const store = useShareStore()
			store.shares = [{ id: 's1' }, { id: 's2' }]
			await store.revokeShare('s1')
			expect(store.shares).toEqual([{ id: 's2' }])
		})
	})

	describe('reset', () => {
		it('clears shares + error', () => {
			const store = useShareStore()
			store.shares = [{ id: 's1' }]
			store.error = 'x'
			store.reset()
			expect(store.shares).toEqual([])
			expect(store.error).toBeNull()
		})
	})
})
