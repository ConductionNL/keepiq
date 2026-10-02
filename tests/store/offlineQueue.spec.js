/**
 * Offline edits through the REAL secret and offline stores (keepiq#785): an
 * offline edit lands in the sealed queue and shows at once; the replay goes
 * through the online paths, encrypts for the recipients returned AT REPLAY,
 * and handles 409, 403, 423 and a failed recipient sync as the design says.
 * IndexedDB is replaced by a map that records exactly what would be stored.
 *
 * @spec openspec/specs/offline-edit-queue/spec.md
 */
import axios from '@nextcloud/axios'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import {
	importPrivateKey,
	importPublicKey,
	rsaDecrypt,
	rsaEncrypt,
} from '../../src/crypto/rsa.js'
import {
	RSA4096_PRIVATE_KEY_PKCS8_PEM,
	RSA4096_PUBLIC_KEY_SPKI_PEM,
	RSA4096_SECONDARY_PRIVATE_KEY_PKCS8_PEM,
	RSA4096_SECONDARY_PUBLIC_KEY_SPKI_PEM,
} from '../vitest/fixtures/rsa-fixtures.js'

const stored = new Map()
let snapshot = null
vi.mock('../../src/offline/cache.js', () => ({
	isCacheAvailable: () => true,
	readQueue: async () => [...stored.values()].map((e) => structuredClone(e)),
	putQueueEntry: async (e) => {
		stored.set(e.entryId, structuredClone(e))
	},
	deleteQueueEntry: async (id) => {
		stored.delete(id)
	},
	clearQueue: async () => stored.clear(),
	readSnapshot: async () => snapshot,
	writeSnapshot: async () => true,
	purge: async () => {},
}))

const { useOfflineStore } = await import('../../src/store/modules/offline.js')
const { useSecretStore } = await import('../../src/store/modules/secret.js')
const { useSessionStore } = await import('../../src/store/modules/session.js')

const BASE = '2026-10-01T10:00:00+00:00'

let offline
let secrets
let session

async function goOffline() {
	session = useSessionStore()
	session.cryptoKey = await importPrivateKey(RSA4096_PRIVATE_KEY_PKCS8_PEM)
	session.certificate = RSA4096_PUBLIC_KEY_SPKI_PEM
	session.suiteId = 'suite-1'
	offline = useOfflineStore()
	secrets = useSecretStore()
	const pub = await importPublicKey(RSA4096_PUBLIC_KEY_SPKI_PEM)
	offline.vault = {
		secrets: [
			{
				id: 's1',
				name: 'Pump station router',
				url: 'https://router.pump.example',
				updatedAt: BASE,
				key: await rsaEncrypt('old', pub),
			},
		],
		folders: [],
	}
	offline.servedFromCache = true
	offline.editsEnabled = true
	offline.online = false
}

/** Back online, unlocked online: the replay may run. */
function goOnline() {
	offline.servedFromCache = false
	offline.online = true
}

beforeEach(() => {
	stored.clear()
	snapshot = null
	setActivePinia(createPinia())
	vi.restoreAllMocks()
})

describe('editing offline', () => {
	it('queues an edit sealed at rest and shows it at once, marked not synced', async () => {
		await goOffline()
		const put = vi.spyOn(axios, 'put')

		await secrets.updateSecret('s1', {
			key: 'new-router-password',
			name: 'Pump station router',
		})

		expect(put).not.toHaveBeenCalled()
		expect(stored.size).toBe(1)
		const atRest = JSON.stringify([...stored.values()])
		expect(atRest).not.toContain('new-router-password')
		expect(atRest).not.toContain('Pump station router')
		expect([...stored.values()][0].baseUpdatedAt).toBe(BASE)

		const shown = offline.vault.secrets.find((s) => s.id === 's1')
		expect(shown.pendingSync).toBe(true)
		expect(await rsaDecrypt(shown.key, session.cryptoKey)).toBe(
			'new-router-password',
		)
		expect(offline.pendingCount).toBe(1)
	})

	it('refuses with an explanation when offline edits are off, and queues nothing', async () => {
		await goOffline()
		offline.editsEnabled = false
		await expect(secrets.updateSecret('s1', { name: 'X' })).rejects.toThrow(
			'read-only offline',
		)
		await expect(secrets.deleteSecret('s1')).rejects.toThrow('read-only offline')
		expect(stored.size).toBe(0)
	})

	it('queues a create and a delete', async () => {
		await goOffline()
		const created = await secrets.createSecret({ name: 'New', key: 'v' })
		expect(created.pendingSync).toBe(true)
		await secrets.deleteSecret('s1')
		expect([...stored.values()].map((e) => e.op).sort()).toEqual([
			'create',
			'delete',
		])
		expect(offline.vault.secrets.map((s) => s.id)).toEqual([created.id])
	})
})

describe('replay', () => {
	it('updates through PUT with the base, then encrypts for the recipients returned at replay', async () => {
		await goOffline()
		await secrets.updateSecret('s1', { key: 'new-router-password' })
		goOnline()

		const put = vi.spyOn(axios, 'put').mockImplementation(async (url) => {
			if (url.endsWith('/sync')) return { data: { updated: 1 } }
			return { data: { id: 's1', updatedAt: '2026-10-02T12:00:00+00:00' } }
		})
		vi.spyOn(axios, 'get').mockImplementation(async (url) => {
			if (url.endsWith('/s1/shares')) {
				return {
					data: [
						{
							secretId: 'copy-bob',
							recipientCertificate:
								RSA4096_SECONDARY_PUBLIC_KEY_SPKI_PEM,
						},
					],
				}
			}
			if (url.endsWith('/write-context'))
				return { data: { effectiveGrade: 'owner', sourceSecretId: 's1' } }
			return { data: [] }
		})

		const result = await offline.replayQueue()

		expect(result).toEqual({ synced: 1, stopped: false })
		const ownerPut = put.mock.calls.find(([url]) => url.endsWith('/secrets/s1'))
		expect(ownerPut[1].baseUpdatedAt).toBe(BASE)
		const sync = put.mock.calls.find(([url]) => url.endsWith('/s1/sync'))
		const bobKey = await importPrivateKey(
			RSA4096_SECONDARY_PRIVATE_KEY_PKCS8_PEM,
		)
		expect(await rsaDecrypt(sync[1].updates[0].encryptedKey, bobKey)).toBe(
			'new-router-password',
		)
		expect(stored.size).toBe(0)
	})

	it('a 409 keeps the entry for the user, and "keep mine" replays on the server version', async () => {
		await goOffline()
		await secrets.updateSecret('s1', { name: 'Offline name' })
		goOnline()
		const current = {
			id: 's1',
			name: 'Server name',
			updatedAt: '2026-10-02T09:00:00+00:00',
		}
		const put = vi
			.spyOn(axios, 'put')
			.mockRejectedValueOnce({ response: { status: 409, data: { current } } })

		await offline.replayQueue()

		expect(offline.conflictEntries).toHaveLength(1)
		expect(offline.conflicts[offline.conflictEntries[0].entryId]).toEqual(
			current,
		)
		expect(stored.size).toBe(1)

		put.mockResolvedValueOnce({ data: { id: 's1' } })
		await offline.resolveConflict(offline.conflictEntries[0].entryId, 'mine')
		expect(put.mock.calls.at(-1)[1].baseUpdatedAt).toBe(
			'2026-10-02T09:00:00+00:00',
		)
		expect(stored.size).toBe(0)
	})

	it('"keep the server version" drops the entry without writing', async () => {
		await goOffline()
		await secrets.updateSecret('s1', { name: 'Offline name' })
		goOnline()
		const put = vi.spyOn(axios, 'put').mockRejectedValueOnce({
			response: { status: 409, data: { current: { updatedAt: 'x' } } },
		})
		await offline.replayQueue()
		await offline.resolveConflict(offline.conflictEntries[0].entryId, 'server')
		expect(put).toHaveBeenCalledTimes(1)
		expect(stored.size).toBe(0)
	})

	it('a 403 moves the entry to the failed list; a 423 keeps the rest queued', async () => {
		await goOffline()
		await secrets.updateSecret('s1', { name: 'A' })
		await secrets.createSecret({ name: 'B', key: 'v' })
		goOnline()
		vi.spyOn(axios, 'put').mockRejectedValueOnce({ response: { status: 403 } })
		const post = vi
			.spyOn(axios, 'post')
			.mockRejectedValueOnce({ response: { status: 423 } })

		const result = await offline.replayQueue()

		expect(result.stopped).toBe(true)
		expect(offline.failedEntries).toHaveLength(1)
		expect(offline.failedEntries[0].secretId).toBe('s1')
		expect(post).toHaveBeenCalledTimes(1)
		expect(offline.pendingCount).toBe(1)
		expect(stored.size).toBe(2)

		await offline.discardEntry(offline.failedEntries[0].entryId)
		expect(stored.size).toBe(1)
	})

	it('a failed recipient sync retries only the sync step', async () => {
		await goOffline()
		await secrets.updateSecret('s1', { key: 'new' })
		goOnline()
		vi.spyOn(axios, 'get').mockImplementation(async (url) => {
			if (url.endsWith('/shares'))
				return {
					data: [
						{
							secretId: 'c',
							recipientCertificate:
								RSA4096_SECONDARY_PUBLIC_KEY_SPKI_PEM,
						},
					],
				}
			return { data: { effectiveGrade: 'owner', sourceSecretId: 's1' } }
		})
		const put = vi.spyOn(axios, 'put').mockImplementation(async (url) => {
			if (url.endsWith('/sync')) throw new Error('Network Error')
			return { data: { id: 's1' } }
		})

		await offline.replayQueue()
		expect([...stored.values()][0].status).toBe('sync')
		const ownerPuts = () =>
			put.mock.calls.filter(([url]) => url.endsWith('/secrets/s1')).length
		expect(ownerPuts()).toBe(1)

		put.mockImplementation(async () => ({ data: { updated: 1 } }))
		await offline.replayQueue()
		expect(ownerPuts()).toBe(1)
		expect(stored.size).toBe(0)
	})

	it('refuses a compromise recovery rotation while changes are pending', async () => {
		await goOffline()
		await secrets.updateSecret('s1', { name: 'A' })
		await secrets.updateSecret('s1', { key: 'B' })
		goOnline()
		const { useEncryptionSuiteStore } =
			await import('../../src/store/modules/encryptionSuite.js')
		const post = vi.spyOn(axios, 'post')

		await expect(
			useEncryptionSuiteStore().initiateCompromiseRecovery('old', 'new'),
		).rejects.toThrow('before you rotate')
		expect(post).not.toHaveBeenCalled()
	})

	it('reopens changes made under keys rotated elsewhere with the previous password', async () => {
		const { encryptPrivateKey } = await import('../../src/crypto/aes.js')
		const { sealEntry, openEntry } = await import('../../src/offline/queue.js')
		const oldPub = await importPublicKey(RSA4096_PUBLIC_KEY_SPKI_PEM)
		snapshot = {
			suite: {
				id: 'old-suite',
				privateKey: await encryptPrivateKey(
					RSA4096_PRIVATE_KEY_PKCS8_PEM,
					'old-pw',
				),
			},
		}
		const old = await sealEntry(
			{
				op: 'update',
				secretId: 's1',
				suiteId: 'old-suite',
				body: { key: await rsaEncrypt('offline-value', oldPub) },
			},
			RSA4096_PUBLIC_KEY_SPKI_PEM,
		)
		stored.set(old.entryId, old)

		// The session now runs on the new suite (the secondary key pair).
		session = useSessionStore()
		session.cryptoKey = await importPrivateKey(
			RSA4096_SECONDARY_PRIVATE_KEY_PKCS8_PEM,
		)
		session.certificate = RSA4096_SECONDARY_PUBLIC_KEY_SPKI_PEM
		session.suiteId = 'new-suite'
		offline = useOfflineStore()
		await offline.loadQueue()
		expect(offline.foreignEntries).toHaveLength(1)
		expect(offline.pendingCount).toBe(1)

		await expect(
			offline.reopenWithPreviousPassword('wrong'),
		).rejects.toBeTruthy()
		expect(await offline.reopenWithPreviousPassword('old-pw')).toBe(1)

		expect(offline.foreignEntries).toHaveLength(0)
		const reopened = await openEntry([...stored.values()][0], session.cryptoKey)
		expect(reopened.suiteId).toBe('new-suite')
		expect(await rsaDecrypt(reopened.body.key, session.cryptoKey)).toBe(
			'offline-value',
		)
	})
})
