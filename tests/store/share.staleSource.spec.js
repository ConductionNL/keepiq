/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A share row names the source version it was encrypted from; a row the
 * server answers `stale_source` is encrypted again from the current version
 * and sent once more (#1255).
 *
 * @spec openspec/specs/user-sharing/spec.md#requirement-a-share-is-made-from-the-current-version
 */

import axios from '@nextcloud/axios'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { sourceVersion } from '../../src/services/recipientCopy.js'
import { encryptAgain } from '../../src/store/modules/groupShare.js'
import {
	MAX_STALE_SOURCE_ROUNDS,
	useShareStore,
} from '../../src/store/modules/share.js'

vi.mock('../../src/crypto/keyProof.js', async (importOriginal) => ({
	...(await importOriginal()),
	sessionKeyProofHeaders: vi.fn(async ({ masterPassword }) => ({
		headers: { 'X-Keepiq-Key-Proof': 'proof' },
		masterPassword: masterPassword || 'from-prompt',
	})),
}))

const OLD = '2026-10-01T10:00:00+00:00'
const NEW = '2026-10-01T10:00:05+00:00'

/**
 * A register-batch row.
 *
 * @param {string} targetUserId The recipient.
 * @param {string} version The source version it was encrypted from.
 * @return {object}
 */
function row(targetUserId, version) {
	return {
		sourceSecretId: 's1',
		targetUserId,
		encryptedKey: `key-from-${version}`,
		sourceUpdatedAt: version,
	}
}

/**
 * A server holding `s1` at `current`: a row from another version is stale.
 *
 * @param {{current: string}} state The source's version.
 * @return {Array<Array<object>>} The rows of each request.
 */
function server(state) {
	const requests = []
	vi.spyOn(axios, 'post').mockImplementation(async (url, body) => {
		requests.push(body.shares)
		return {
			data: {
				items: body.shares.map((r) =>
					r.sourceUpdatedAt === state.current
						? {
								sourceSecretId: r.sourceSecretId,
								targetUserId: r.targetUserId,
								status: 'created',
							}
						: {
								sourceSecretId: r.sourceSecretId,
								targetUserId: r.targetUserId,
								status: 'stale_source',
								sourceUpdatedAt: state.current,
							},
				),
			},
		}
	})
	return requests
}

describe('registerBatch with a stale source', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})
	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('encrypts the stale rows again and sends only those, keeping row order and the password', async () => {
		const requests = server({ current: NEW })
		const rebuild = vi.fn(async (stale) =>
			stale.map((r) => row(r.targetUserId, NEW)),
		)

		const { items, masterPassword } = await useShareStore().registerBatch(
			[row('bob', OLD), row('carol', NEW), row('dan', OLD)],
			{ masterPassword: 'pw', rebuild },
		)

		expect(rebuild).toHaveBeenCalledTimes(1)
		expect(rebuild.mock.calls[0][0].map((r) => r.targetUserId)).toEqual([
			'bob',
			'dan',
		])
		expect(requests.map((rows) => rows.map((r) => r.targetUserId))).toEqual([
			['bob', 'carol', 'dan'],
			['bob', 'dan'],
		])
		expect(items.map((i) => [i.targetUserId, i.status])).toEqual([
			['bob', 'created'],
			['carol', 'created'],
			['dan', 'created'],
		])
		expect(masterPassword).toBe('pw')
	})

	it('gives up after three more rounds and reports the row stale', async () => {
		const requests = server({ current: NEW })
		// The source keeps moving: every rebuild is one version behind.
		const rebuild = vi.fn(async (stale) =>
			stale.map((r) => row(r.targetUserId, OLD)),
		)

		const { items } = await useShareStore().registerBatch([row('bob', OLD)], {
			rebuild,
		})

		expect(rebuild).toHaveBeenCalledTimes(MAX_STALE_SOURCE_ROUNDS)
		expect(requests).toHaveLength(MAX_STALE_SOURCE_ROUNDS + 1)
		expect(items).toEqual([
			{
				sourceSecretId: 's1',
				targetUserId: 'bob',
				status: 'stale_source',
				sourceUpdatedAt: NEW,
			},
		])
	})

	it('without a rebuild reports the stale row as the server answered it', async () => {
		const requests = server({ current: NEW })

		const { items } = await useShareStore().registerBatch([row('bob', OLD)])

		expect(requests).toHaveLength(1)
		expect(items[0].status).toBe('stale_source')
	})
})

describe('the copy rows of the share paths', () => {
	it('name the version of the decrypted source, and nothing when it names none', () => {
		expect(sourceVersion({ updatedAt: NEW })).toEqual({ sourceUpdatedAt: NEW })
		expect(sourceVersion({})).toEqual({})
		expect(sourceVersion(null)).toEqual({})
	})

	it('encrypt a group share copy again only for the members whose row was stale', async () => {
		const store = { encryptForMembers: vi.fn(async () => ['rows']) }
		const members = [
			{ userId: 'bob', certificate: 'B' },
			{ userId: 'carol', certificate: 'C' },
		]

		await expect(
			encryptAgain(store, { secretId: 's1', groupShareId: 'g1', members }, [
				row('carol', OLD),
			]),
		).resolves.toEqual(['rows'])
		expect(store.encryptForMembers).toHaveBeenCalledWith('s1', 'g1', [
			{ userId: 'carol', certificate: 'C' },
		])
	})
})
