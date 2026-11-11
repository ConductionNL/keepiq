/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Pins the offline store's write-through refresh, the failure it stores for
 * a replay error, the queueing of a change and the replay loop, branch by
 * branch, before they are split into named steps.
 *
 * @spec openspec/specs/offline-readonly-cache/spec.md#requirement-online-sessions-write-through-an-encrypted-local-snapshot
 * @spec openspec/changes/sharing-atomic-share-save/specs/offline-edit-queue/spec.md#requirement-failed-entries-are-kept-never-dropped-silently
 */
import axios from '@nextcloud/axios'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const cache = vi.hoisted(() => ({
	isCacheAvailable: () => true,
	readQueue: vi.fn(async () => []),
	putQueueEntry: vi.fn(async () => {}),
	deleteQueueEntry: vi.fn(async () => {}),
	clearQueue: vi.fn(async () => {}),
	readSnapshot: vi.fn(async () => null),
	writeSnapshot: vi.fn(async () => true),
	purge: vi.fn(async () => {}),
}))
vi.mock('../../src/offline/cache.js', () => cache)
vi.mock('../../src/offline/snapshot.js', () => ({
	encryptSnapshot: vi.fn(async (key, data) => ({ sealed: data })),
	decryptSnapshot: vi.fn(),
}))
vi.mock('../../src/offline/queue.js', async (importOriginal) => {
	const actual = await importOriginal()
	let n = 0
	return {
		...actual,
		sealEntry: vi.fn(async (entry) => ({
			...entry,
			entryId: entry.entryId ?? `e${++n}`,
			queuedAt: '2026-10-01T10:00:00Z',
		})),
	}
})

const { useOfflineStore } = await import('../../src/store/modules/offline.js')
const { useSessionStore } = await import('../../src/store/modules/session.js')
const { ShareSaveError } = await import('../../src/services/shareSave.js')

function httpError(status, data, extra = {}) {
	return Object.assign(new Error(`HTTP ${status}`), {
		response: { status, data },
		...extra,
	})
}

beforeEach(() => {
	setActivePinia(createPinia())
	vi.clearAllMocks()
})

afterEach(() => {
	vi.restoreAllMocks()
})

describe('syncNow', () => {
	function ready() {
		const offline = useOfflineStore()
		offline.available = true
		useSessionStore().aesKey = { key: 1 }
		vi.spyOn(offline, 'replayQueue').mockResolvedValue({ synced: 0, stopped: false })
		return offline
	}

	it('does nothing when the cache is unavailable or the vault has no key', async () => {
		const get = vi.spyOn(axios, 'get')
		const offline = useOfflineStore()
		offline.available = false
		useSessionStore().aesKey = { key: 1 }
		await expect(offline.syncNow()).resolves.toBe(false)
		offline.available = true
		useSessionStore().aesKey = null
		await expect(offline.syncNow()).resolves.toBe(false)
		expect(get).not.toHaveBeenCalled()
	})

	it('keeps the snapshot while entries of another suite wait', async () => {
		const offline = ready()
		offline.foreignEntries = [{ entryId: 'x' }]
		const get = vi.spyOn(axios, 'get')
		await expect(offline.syncNow()).resolves.toBe(false)
		expect(offline.replayQueue).toHaveBeenCalled()
		expect(get).not.toHaveBeenCalled()
	})

	it('still refreshes when the replay rejects', async () => {
		const offline = ready()
		offline.replayQueue.mockRejectedValue(new Error('down'))
		vi.spyOn(axios, 'get').mockResolvedValue({ data: { syncedAt: 'T1' } })
		await expect(offline.syncNow()).resolves.toBe(true)
	})

	it('writes the snapshot and records when it synced', async () => {
		const offline = ready()
		offline.servedFromCache = true
		vi.spyOn(axios, 'get').mockResolvedValue({ data: { syncedAt: 'T1', a: 1 } })
		await expect(offline.syncNow()).resolves.toBe(true)
		expect(axios.get).toHaveBeenCalledWith('/apps/keepiq/api/v1/offline/manifest')
		expect(cache.writeSnapshot).toHaveBeenCalledWith({
			sealed: { syncedAt: 'T1', a: 1 },
		})
		expect(offline.servedFromCache).toBe(false)
		expect(offline.syncedAt).toBe('T1')
	})

	it('reports an unwritten snapshot and leaves the state alone', async () => {
		const offline = ready()
		offline.servedFromCache = true
		offline.syncedAt = 'T0'
		cache.writeSnapshot.mockResolvedValueOnce(false)
		vi.spyOn(axios, 'get').mockResolvedValue({ data: { syncedAt: 'T1' } })
		await expect(offline.syncNow()).resolves.toBe(false)
		expect(offline.servedFromCache).toBe(true)
		expect(offline.syncedAt).toBe('T0')
	})

	it('purges and writes nothing when the manifest blocks unlocking', async () => {
		const offline = ready()
		vi.spyOn(axios, 'get').mockResolvedValue({ data: { unlockBlocked: 'x' } })
		await expect(offline.syncNow()).resolves.toBe(false)
		expect(cache.purge).toHaveBeenCalledTimes(1)
		expect(cache.writeSnapshot).not.toHaveBeenCalled()
	})

	it('on a refusal purges the snapshot, and the queue only when nothing is pending', async () => {
		const offline = ready()
		vi.spyOn(axios, 'get').mockRejectedValue(httpError(428, {}))
		await expect(offline.syncNow()).resolves.toBe(false)
		expect(cache.purge).toHaveBeenCalledTimes(1)
		expect(cache.clearQueue).toHaveBeenCalledTimes(1)

		offline.entries = [{ entryId: 'p', status: 'queued' }]
		axios.get.mockRejectedValue(httpError(403, {}))
		await expect(offline.syncNow()).resolves.toBe(false)
		expect(cache.purge).toHaveBeenCalledTimes(2)
		expect(cache.clearQueue).toHaveBeenCalledTimes(1)
	})

	it('swallows any other failure without purging', async () => {
		const offline = ready()
		vi.spyOn(axios, 'get').mockRejectedValue(httpError(500, {}))
		await expect(offline.syncNow()).resolves.toBe(false)
		expect(cache.purge).not.toHaveBeenCalled()
	})
})

describe('the failure a replay error stores', () => {
	const PARTNERS = [{ recipientCloudId: 'a@x' }, { recipientCloudId: 'b@y' }]
	const CASES = {
		'partner unreachable': [
			new ShareSaveError('partner_unreachable', PARTNERS),
			{ code: 'partner_unreachable', partners: ['a@x', 'b@y'] },
		],
		'partner unreachable without partners': [
			new ShareSaveError('partner_unreachable'),
			{ code: 'partner_unreachable', partners: [] },
		],
		'partner lookup limit': [
			new ShareSaveError('partner_lookup_limit'),
			{ code: 'partner_lookup_limit' },
		],
		'view only': [new ShareSaveError('view_only'), { code: 'view_only' }],
		'upgrade required as a share save error': [
			new ShareSaveError('partner_upgrade_required', PARTNERS),
			{
				code: 'partner_upgrade_required',
				partners: ['a@x', 'b@y'],
				requiredVersion: null,
			},
		],
		'upgrade required as a share save answer': [
			httpError(
				409,
				{ error: 'partner_upgrade_required', requiredVersion: '3.1' },
				{ fromShareSave: true },
			),
			{ code: 'partner_upgrade_required', partners: [], requiredVersion: '3.1' },
		],
		'refused share save': [
			httpError(400, { error: 'bad_patch' }, { fromShareSave: true }),
			{ code: 'refused', reason: 'bad_patch' },
		],
		'refused share save without a code': [
			httpError(400, null, { fromShareSave: true }),
			{ code: 'refused', reason: '' },
		],
		'scan pending': [
			httpError(409, { error: 'share_scan_pending', message: 'wait' }),
			{ code: 'share_scan_pending', message: 'wait' },
		],
		'scan pending without words': [
			httpError(409, { error: 'share_scan_pending' }),
			{ code: 'share_scan_pending', message: null },
		],
		'a share save code the list has words for': [
			httpError(403, { error: 'version_refused', message: 'ignored' }),
			{ code: 'not_allowed' },
		],
		'a refusal': [httpError(428, { message: 'no' }), null],
		'not found': [httpError(404, { message: 'gone' }), null],
	}

	for (const [name, [error, expected]] of Object.entries(CASES)) {
		it(name, async () => {
			const offline = useOfflineStore()
			const setStatus = vi.spyOn(offline, 'setStatus').mockResolvedValue()
			const kind = await offline.replayFailure({ entryId: 'e' }, error)
			if (expected === null) {
				expect({ kind, call: setStatus.mock.calls[0] }).toMatchSnapshot()
				return
			}
			expect(kind).toBe('failed')
			expect(setStatus).toHaveBeenCalledWith({ entryId: 'e' }, 'failed', expected)
		})
	}
})

describe('enqueue', () => {
	function editing() {
		const offline = useOfflineStore()
		offline.servedFromCache = true
		offline.editsEnabled = true
		const session = useSessionStore()
		session.suiteId = 'suite-1'
		session.certificate = 'CERT'
		return offline
	}

	it('refuses while edits are not queued', async () => {
		const offline = useOfflineStore()
		await expect(offline.enqueue({ op: 'update', secretId: 's' })).rejects.toThrow(
			'Keepiq is read-only offline. Your administrator has not turned on offline edits.',
		)
		expect(cache.putQueueEntry).not.toHaveBeenCalled()
	})

	it('stores a first change with its defaults and shows it in the vault', async () => {
		const offline = editing()
		offline.vault = { secrets: [{ id: 'other' }, { id: 's', name: 'old' }] }
		const kept = await offline.enqueue({
			op: 'update',
			secretId: 's',
			body: { name: 'new' },
		})
		expect(kept).toMatchObject({
			op: 'update',
			secretId: 's',
			baseUpdatedAt: null,
			sourceBaseUpdatedAt: null,
			suiteId: 'suite-1',
			status: 'queued',
			body: { name: 'new' },
		})
		expect(cache.putQueueEntry).toHaveBeenCalledTimes(1)
		expect(cache.deleteQueueEntry).not.toHaveBeenCalled()
		expect(offline.entries).toHaveLength(1)
		expect(offline.entries[0].queuedAt).toBe('2026-10-01T10:00:00Z')
		expect(offline.vault.secrets.map((s) => s.id)).toEqual(['other', 's'])
		expect(offline.vault.secrets[1].name).toBe('new')
	})

	it('a create then a delete cancel out: the stored entry goes and so does the row', async () => {
		const offline = editing()
		offline.vault = { secrets: [] }
		await offline.enqueue({ op: 'create', secretId: 'n', body: { name: 'x' } })
		const firstId = offline.entries[0].entryId
		const kept = await offline.enqueue({ op: 'delete', secretId: 'n' })
		expect(kept).toBeNull()
		expect(cache.deleteQueueEntry).toHaveBeenCalledWith(firstId)
		expect(offline.entries).toEqual([])
		expect(offline.vault.secrets).toEqual([])
	})

	it('a second update is merged into the first entry, and a failed one is left alone', async () => {
		const offline = editing()
		offline.entries = [{ entryId: 'f', secretId: 's', status: 'failed', op: 'update' }]
		await offline.enqueue({ op: 'update', secretId: 's', body: { name: 'a' } })
		await offline.enqueue({
			op: 'update',
			secretId: 's',
			body: { url: 'u' },
			baseUpdatedAt: 'B',
		})
		expect(offline.entries.map((e) => e.entryId)).toMatchSnapshot()
		expect(cache.deleteQueueEntry.mock.calls).toMatchSnapshot()
		expect(offline.entries.at(-1).body).toMatchSnapshot()
	})
})

describe('replayQueue', () => {
	function replaying(outcomes) {
		const offline = useOfflineStore()
		offline.online = true
		useSessionStore().cryptoKey = { key: 1 }
		vi.spyOn(offline, 'loadQueue').mockResolvedValue()
		const replayOne = vi
			.spyOn(offline, 'replayOne')
			.mockImplementation(async (entry) => outcomes[entry.entryId])
		return { offline, replayOne }
	}

	it('does not start while one runs, offline, from the cache or locked', async () => {
		const { offline, replayOne } = replaying({})
		offline.replaying = true
		await expect(offline.replayQueue()).resolves.toEqual({ synced: 0, stopped: true })
		offline.replaying = false
		offline.online = false
		await expect(offline.replayQueue()).resolves.toEqual({ synced: 0, stopped: true })
		offline.online = true
		offline.servedFromCache = true
		await expect(offline.replayQueue()).resolves.toEqual({ synced: 0, stopped: true })
		offline.servedFromCache = false
		useSessionStore().cryptoKey = null
		await expect(offline.replayQueue()).resolves.toEqual({ synced: 0, stopped: true })
		expect(offline.loadQueue).not.toHaveBeenCalled()
		expect(replayOne).not.toHaveBeenCalled()
	})

	it('counts what went through, passes over failed and conflicting entries, and stops at a retry', async () => {
		const { offline, replayOne } = replaying({
			a: 'done',
			b: 'conflict',
			c: 'done',
			d: 'retry',
			e: 'done',
		})
		offline.entries = [
			{ entryId: 'a', status: 'queued', queuedAt: '1' },
			{ entryId: 'x', status: 'failed', queuedAt: '2' },
			{ entryId: 'b', status: 'queued', queuedAt: '3' },
			{ entryId: 'y', status: 'conflict', queuedAt: '4' },
			{ entryId: 'c', status: 'queued', queuedAt: '5' },
			{ entryId: 'd', status: 'queued', queuedAt: '6' },
			{ entryId: 'e', status: 'queued', queuedAt: '7' },
		]
		await expect(offline.replayQueue()).resolves.toEqual({ synced: 2, stopped: true })
		expect(replayOne.mock.calls.map(([e]) => e.entryId)).toEqual(['a', 'b', 'c', 'd'])
		expect(offline.replaying).toBe(false)
	})

	it('reports a full run, and clears the flag when the load throws', async () => {
		const { offline } = replaying({ a: 'done', b: 'failed' })
		offline.entries = [
			{ entryId: 'a', status: 'queued', queuedAt: '1' },
			{ entryId: 'b', status: 'queued', queuedAt: '2' },
		]
		await expect(offline.replayQueue()).resolves.toEqual({ synced: 1, stopped: false })
		offline.loadQueue.mockRejectedValue(new Error('boom'))
		await expect(offline.replayQueue()).rejects.toThrow('boom')
		expect(offline.replaying).toBe(false)
	})
})
