/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The trash and the archive in the secret store (vault-trash-and-archive):
 * the list asks for one state, the everyday list stays live, an export
 * carries archived secrets but never trashed ones, and every state change
 * hits its own endpoint and drops the row from the view it left.
 *
 * @spec openspec/specs/vault-trash-and-archive/spec.md#requirement-archiving-a-secret
 * @spec openspec/specs/vault-trash-and-archive/spec.md#requirement-restoring-and-purging-trashed-secrets
 */

import axios from '@nextcloud/axios'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useSecretStore } from '../../src/store/modules/secret.js'

/**
 * The `params` of the most recent GET.
 *
 * @return {object} Query params axios was called with.
 */
function lastParams() {
	const calls = axios.get.mock.calls
	return calls[calls.length - 1][1].params
}

describe('secret store: trash and archive', () => {
	let store

	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
		store = useSecretStore()
		vi.spyOn(axios, 'get').mockResolvedValue({
			data: { items: [], total: 0, page: 1 },
		})
	})

	it('asks for the live list without a state parameter', async () => {
		await store.fetchSecrets()
		expect('state' in lastParams()).toBe(false)
	})

	it('asks the Trash view for trashed secrets, and keeps asking on a refresh', async () => {
		store.setListQuery({ state: 'trashed' })
		await store.fetchSecrets()
		expect(lastParams().state).toBe('trashed')
	})

	it('exports everything that is not in the trash', async () => {
		store.setListQuery({ state: 'archived' })
		await store.fetchAllSecrets()
		expect(lastParams().state).toBe('kept')
	})

	it('restores through POST and drops the row from the Trash view', async () => {
		const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: {} })
		store.secrets = [{ id: 'a' }, { id: 'b' }]
		store.totalCount = 2

		await store.changeSecretState('a', 'restore')

		expect(post.mock.calls[0][0]).toContain('/api/v1/secrets/a/restore')
		expect(store.secrets.map((s) => s.id)).toEqual(['b'])
		expect(store.totalCount).toBe(1)
	})

	it('deletes for good through DELETE on the purge endpoint', async () => {
		const del = vi.spyOn(axios, 'delete').mockResolvedValue({ data: {} })
		store.secrets = [{ id: 'a' }]

		await store.changeSecretState('a', 'purge')

		expect(del.mock.calls[0][0]).toContain('/api/v1/secrets/a/purge')
	})

	it('archives through POST on the archive endpoint', async () => {
		const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: {} })
		await store.changeSecretState('a', 'archive')
		expect(post.mock.calls[0][0]).toContain('/api/v1/secrets/a/archive')
	})

	it('hands back what the server said, so a restore can report a federated share', async () => {
		vi.spyOn(axios, 'post').mockResolvedValue({
			data: { id: 'a', federatedShare: 'ended' },
		})
		store.secrets = [{ id: 'a' }]

		const answer = await store.changeSecretState('a', 'restore')

		expect(answer.federatedShare).toBe('ended')
	})
})
