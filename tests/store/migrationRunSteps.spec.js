/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Pins the migration run's progress, its result and its halt error, and the
 * outcome of posting one page of results, record kind by record kind, before
 * they are split into named steps.
 *
 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-re-encrypted-ciphertext-is-verified-before-the-original-is-discarded
 */

import axios from '@nextcloud/axios'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useEncryptionSuiteStore } from '../../src/store/modules/encryptionSuite.js'

vi.mock('../../src/store/modules/offline.js', () => ({
	useOfflineStore: () => ({ evict: async () => {} }),
}))

const runs = []
let pages = []
vi.mock('../../src/migration/driver.js', () => ({
	createMigrationRunner: async (keys) => ({
		usesWorker: true,
		keys,
		run: async (jobs) => {
			runs.push(jobs.map((job) => job.id))
			return pages.shift()
		},
		dispose: () => runs.push('disposed'),
	}),
}))

vi.mock('../../src/crypto/index.js', async (importOriginal) => ({
	...(await importOriginal()),
	decryptPrivateKey: async () => 'old-pem',
	importPrivateKey: async () => 'imported-old-key',
	importPublicKey: async () => 'imported-new-key',
}))

const PARAMS = {
	migrationId: 'm1',
	oldEncryptedPrivateKey: 'wrapped',
	oldPassword: 'previous',
	newPublicKeyPem: 'PUB',
	newPrivateKey: 'new-key',
}

function work(records, totalRemaining, dropCandidates) {
	return {
		data: {
			secrets: { records: records.filter((r) => r.store === 'secrets') },
			versions: {
				records: records.filter((r) => r.store === 'versions'),
				dropCandidates,
			},
			attachmentGrants: {
				records: records.filter((r) => r.store === 'attachmentGrants'),
			},
			totalRemaining,
		},
	}
}

const ok = (store, id) => ({ store, id, ok: true, payload: { id, sealed: true } })

beforeEach(() => {
	setActivePinia(createPinia())
	vi.restoreAllMocks()
	runs.length = 0
	pages = []
})

describe('runMigration', () => {
	it('reports progress over the records it dispatches and returns the run', async () => {
		const progress = []
		const store = useEncryptionSuiteStore()
		vi.spyOn(axios, 'get')
			.mockResolvedValueOnce(
				work(
					[
						{ store: 'secrets', id: 's1', name: 'One' },
						{ store: 'versions', id: 'v1' },
						{ store: 'attachmentGrants', id: 'g1' },
					],
					5,
					2,
				),
			)
			.mockResolvedValueOnce(
				work([{ store: 'secrets', id: 's2', name: 'Two' }], 1, undefined),
			)
			.mockResolvedValueOnce(work([], 0, 0))
		vi.spyOn(axios, 'post').mockImplementation(async (url) => {
			progress.push({ url, ...store.migrationProgress })
			if (url.endsWith('/g1')) {
				throw Object.assign(new Error('x'), {
					response: { data: { message: 'gone' } },
				})
			}
			return { data: {} }
		})
		pages = [
			[
				ok('secrets', 's1'),
				{ store: 'versions', id: 'v1', ok: false, permanent: true, error: 'bad' },
				ok('attachmentGrants', 'g1'),
			],
			[ok('secrets', 's2')],
		]

		const outcome = await store.runMigration(PARAMS)

		expect({
			outcome,
			progress,
			runs,
			final: store.migrationProgress,
			failures: store.migrationFailures,
			dropped: store.migrationDroppedVersions,
			runner: store.migrationRunner,
		}).toMatchSnapshot()
	})

	it('throws the halt with what was migrated and failed, and marks the progress halted', async () => {
		const store = useEncryptionSuiteStore()
		vi.spyOn(axios, 'get').mockResolvedValue(
			work(
				[
					{ store: 'secrets', id: 's1', name: 'One' },
					{ store: 'secrets', id: 's2', name: 'Two' },
				],
				2,
				0,
			),
		)
		vi.spyOn(axios, 'post').mockResolvedValue({ data: {} })
		pages = [[ok('secrets', 's1'), { store: 'secrets', id: 's2', halt: true }]]

		const error = await store.runMigration(PARAMS).catch((e) => e)

		expect({
			name: error.name,
			message: error.message,
			migrated: error.migrated,
			failures: error.failures,
			progress: store.migrationProgress,
			runs,
		}).toMatchSnapshot()
	})

	it('halts with the offline message when a page commits and fails nothing', async () => {
		const store = useEncryptionSuiteStore()
		vi.spyOn(axios, 'get').mockResolvedValue(
			work([{ store: 'secrets', id: 's1', name: 'One' }], 1, 0),
		)
		pages = [[{ store: 'secrets', id: 's1', ok: false }]]
		const error = await store.runMigration(PARAMS).catch((e) => e)
		expect([error.message, error.migrated, store.migrationProgress]).toMatchSnapshot()
	})
})

describe('postResults', () => {
	it('settles every kind of record, window by window', async () => {
		const store = useEncryptionSuiteStore()
		const post = vi.spyOn(axios, 'post').mockImplementation(async (url) => {
			if (url.endsWith('/s5')) {
				throw new Error('network down')
			}
			if (url.endsWith('/s6')) {
				throw Object.assign(new Error('x'), {
					response: { data: { message: 'guard said no' } },
				})
			}
			if (url.endsWith('/s7')) {
				throw 'plain string'
			}
			return { data: {} }
		})
		const results = [
			ok('secrets', 's1'),
			ok('versions', 'v1'),
			ok('attachmentGrants', 'g1'),
			{ store: 'secrets', id: 's2', ok: false, permanent: true, error: 'old key' },
			{ store: 'secrets', id: 's3', ok: false, permanent: true },
			{ store: 'secrets', id: 's4', ok: false },
			ok('secrets', 's5'),
			ok('secrets', 's6'),
			ok('secrets', 's7'),
			ok('mystery', 'x1'),
			{ store: 'secrets', id: 's8', ok: false, error: 'blip' },
		]
		const jobs = [
			{ id: 's1', name: 'One' },
			{ id: 's2', name: 'Two' },
			{ id: 's4', name: null },
		]

		const outcome = await store.postResults('m1', results, jobs)

		expect({
			outcome,
			posts: post.mock.calls,
		}).toMatchSnapshot()
	})

	it('stops after the window that halted, keeping the first halt reason', async () => {
		const store = useEncryptionSuiteStore()
		const post = vi.spyOn(axios, 'post').mockImplementation(async (url) => {
			if (url.endsWith('/s3')) {
				throw Object.assign(new Error('x'), {
					response: {
						data: { error: 'fields_not_reencrypted', message: 'totp left' },
					},
				})
			}
			return { data: {} }
		})
		const results = [
			ok('secrets', 's1'),
			{ store: 'secrets', id: 's2', halt: true },
			ok('secrets', 's3'),
			{ store: 'secrets', id: 's4', halt: true, error: 'mismatch' },
			ok('secrets', 's5'),
		]

		const outcome = await store.postResults('m1', results, [])

		expect({ outcome, posts: post.mock.calls.map(([url]) => url) }).toMatchSnapshot()
	})

	it('keeps a halt with its own reason', async () => {
		const store = useEncryptionSuiteStore()
		vi.spyOn(axios, 'post').mockResolvedValue({ data: {} })
		const outcome = await store.postResults(
			'm1',
			[{ store: 'secrets', id: 's1', halt: true, error: 'mismatch' }],
			[],
		)
		expect(outcome).toEqual({
			committed: 0,
			failures: [],
			retryable: [],
			halt: 'mismatch',
		})
	})
})
