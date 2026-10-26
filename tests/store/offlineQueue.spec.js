/**
 * Offline edits through the REAL secret and offline stores (keepiq#785): an
 * offline edit lands in the sealed queue and shows at once; the replay goes
 * through the online paths, encrypts for the recipients returned AT REPLAY in
 * one share save, and handles 409, 428 (a refusal), 423 and a failed share
 * save as the design says.
 * IndexedDB is replaced by a map that records exactly what would be stored.
 *
 * @spec openspec/specs/offline-edit-queue/spec.md
 */
import axios from '@nextcloud/axios'
import { mount } from '@vue/test-utils'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import {
	importPrivateKey,
	importPublicKey,
	rsaDecrypt,
	rsaEncrypt,
} from '../../src/crypto/rsa.js'
import {
	httpError,
	settleWithTimers,
	shareSaveServer,
} from '../fixtures/shareSaveServer.js'
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

const { failureText, useOfflineStore } =
	await import('../../src/store/modules/offline.js')
const { useSecretStore } = await import('../../src/store/modules/secret.js')
const { useSessionStore } = await import('../../src/store/modules/session.js')
const { forgetVerifiedPartners } = await import('../../src/services/shareSave.js')
const { encryptSnapshot } = await import('../../src/offline/snapshot.js')
const { useSecretTypeStore } = await import('../../src/store/modules/secretType.js')
const { useShareStore } = await import('../../src/store/modules/share.js')
const { default: SecretEditDialog } =
	await import('../../src/dialogs/SecretEditDialog.vue')

const BASE = '2026-10-01T10:00:00+00:00'

const CHAIN = JSON.parse(
	readFileSync(resolve(__dirname, '../fixtures/federation-chain.json'), 'utf8'),
)
const BOB = 'bob@cloud.partner.example'
const BOB_VERIFIED = {
	cloudId: BOB,
	certificate: CHAIN.bob,
	chain: [CHAIN.intermediateA, CHAIN.rootA],
	partnerRootFingerprint: CHAIN.rootAFingerprint,
}

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

/** How many write-contexts were read since the fake server was wired. */
function writeContextReads() {
	return axios.get.mock.calls.filter(([url]) => url.endsWith('/write-context'))
		.length
}

beforeEach(() => {
	forgetVerifiedPartners()
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
	it('saves a value edit with every copy in one share save, encrypted for the recipients of replay time', async () => {
		await goOffline()
		await secrets.updateSecret('s1', { key: 'new-router-password' })
		goOnline()
		const log = shareSaveServer({
			id: 's1',
			required: [
				{ secretId: 'copy-bob', holder: 'recipient', targetUserId: 'bob' },
			],
			suites: { bob: RSA4096_SECONDARY_PUBLIC_KEY_SPKI_PEM },
		})

		const result = await offline.replayQueue()

		expect(result).toEqual({ synced: 1, stopped: false })
		expect(log.plainPuts).toHaveLength(0)
		const final = log.saves.at(-1)
		expect(final.baseUpdatedAt).toBe(BASE)
		expect(await rsaDecrypt(final.patch.key, session.cryptoKey)).toBe(
			'new-router-password',
		)
		const bobKey = await importPrivateKey(
			RSA4096_SECONDARY_PRIVATE_KEY_PKCS8_PEM,
		)
		expect(await rsaDecrypt(final.copies[0].key, bobKey)).toBe(
			'new-router-password',
		)
		expect(stored.size).toBe(0)
	})

	it('a stale share save keeps the entry as a conflict and writes nothing', async () => {
		await goOffline()
		await secrets.updateSecret('s1', { key: 'new' })
		goOnline()
		const current = { id: 's1', updatedAt: '2026-10-02T09:00:00+00:00' }
		shareSaveServer({
			id: 's1',
			onSave: () => {
				throw httpError(409, { error: 'stale', current })
			},
		})

		await offline.replayQueue()

		expect(offline.conflictEntries).toHaveLength(1)
		expect(offline.conflicts[offline.conflictEntries[0].entryId]).toEqual(
			current,
		)
		// An own secret's 409 names no source version, and none is read.
		expect(writeContextReads()).toBe(1)
		expect([...stored.values()][0]).toMatchObject({
			conflictUpdatedAt: current.updatedAt,
			conflictSourceUpdatedAt: null,
		})
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

	describe('a change made through a copy', () => {
		const EDIT_SOURCE = '2026-09-30T07:00:00+00:00'
		const context = {
			sourceSecretId: 'src',
			effectiveGrade: 'write',
			ownerCertificate: RSA4096_PUBLIC_KEY_SPKI_PEM,
			sourceUpdatedAt: '2026-10-02T09:00:00+00:00',
		}

		it('keeps the source version it was made from and replays with it, so a source that moved meanwhile is a conflict', async () => {
			await goOffline()
			await secrets.updateSecret(
				's1',
				{ key: 'new' },
				{ sourceBaseUpdatedAt: EDIT_SOURCE },
			)
			expect([...stored.values()][0].sourceBaseUpdatedAt).toBe(EDIT_SOURCE)
			goOnline()
			const server = { source: context.sourceUpdatedAt }
			const log = shareSaveServer({
				id: 's1',
				context: {
					...context,
					get sourceUpdatedAt() {
						return server.source
					},
				},
				onSave: (body) => {
					if (body.sourceBaseUpdatedAt !== server.source) {
						throw httpError(409, {
							error: 'stale_source',
							sourceUpdatedAt: server.source,
						})
					}
				},
			})

			await offline.replayQueue()

			expect(log.saves.map((s) => s.sourceBaseUpdatedAt)).toEqual([
				EDIT_SOURCE,
			])
			expect(offline.conflictEntries).toHaveLength(1)

			// Keeping mine overrides the source version the conflict named,
			// not one the owner saved after it.
			const named = server.source
			server.source = '2026-10-02T11:00:00+00:00'
			await offline.resolveConflict(offline.conflictEntries[0].entryId, 'mine')
			expect(log.saves.at(-1).sourceBaseUpdatedAt).toBe(named)
			expect(offline.conflictEntries).toHaveLength(1)

			await offline.resolveConflict(offline.conflictEntries[0].entryId, 'mine')
			expect(log.saves.at(-1).sourceBaseUpdatedAt).toBe(server.source)
			expect(stored.size).toBe(0)
		})

		it('after a reload, "keep mine" on a stale source replays with the source version that 409 named, and saves', async () => {
			await goOffline()
			await secrets.updateSecret(
				's1',
				{ key: 'new' },
				{ sourceBaseUpdatedAt: EDIT_SOURCE },
			)
			goOnline()
			const log = shareSaveServer({
				id: 's1',
				context,
				onSave: (body) => {
					if (body.sourceBaseUpdatedAt !== context.sourceUpdatedAt) {
						throw httpError(409, {
							error: 'stale_source',
							sourceUpdatedAt: context.sourceUpdatedAt,
						})
					}
				},
			})
			await offline.replayQueue()
			expect(offline.conflictEntries).toHaveLength(1)
			expect([...stored.values()][0]).toMatchObject({
				sourceBaseUpdatedAt: EDIT_SOURCE,
				conflictSourceUpdatedAt: context.sourceUpdatedAt,
			})

			// A reload: the 409 itself is gone, the stored entry is read again.
			setActivePinia(createPinia())
			await goOffline()
			goOnline()
			await offline.loadQueue()
			expect(offline.conflicts).toEqual({})
			await offline.resolveConflict(offline.conflictEntries[0].entryId, 'mine')

			expect(log.saves.map((s) => s.sourceBaseUpdatedAt)).toEqual([
				EDIT_SOURCE,
				context.sourceUpdatedAt,
			])
			expect(offline.conflictEntries).toHaveLength(0)
			expect(stored.size).toBe(0)
		})

		it.each([
			['before a reload', false],
			['after a reload', true],
		])(
			'"keep mine" on a stale copy replays on the copy and source versions that 409 named, %s',
			async (when, reload) => {
				await goOffline()
				await secrets.updateSecret(
					's1',
					{ key: 'new' },
					{ sourceBaseUpdatedAt: EDIT_SOURCE },
				)
				goOnline()
				// The owner's share-save moved the source and the copy alike.
				const server = {
					copy: context.sourceUpdatedAt,
					source: context.sourceUpdatedAt,
				}
				const log = shareSaveServer({
					id: 's1',
					context: {
						...context,
						get sourceUpdatedAt() {
							return server.source
						},
					},
					onSave: (body) => {
						if (body.baseUpdatedAt !== server.copy) {
							throw httpError(409, {
								error: 'stale',
								current: { id: 's1', updatedAt: server.copy },
								sourceUpdatedAt: server.source,
							})
						}
						if (body.sourceBaseUpdatedAt !== server.source) {
							throw httpError(409, {
								error: 'stale_source',
								sourceUpdatedAt: server.source,
							})
						}
					},
				})
				await offline.replayQueue()
				expect(offline.conflictEntries).toHaveLength(1)
				// Only the share save's own read: the 409 named the source.
				expect(writeContextReads()).toBe(1)
				expect([...stored.values()][0]).toMatchObject({
					baseUpdatedAt: BASE,
					sourceBaseUpdatedAt: EDIT_SOURCE,
					conflictUpdatedAt: server.copy,
					conflictSourceUpdatedAt: server.source,
				})

				if (reload) {
					setActivePinia(createPinia())
					await goOffline()
					goOnline()
					await offline.loadQueue()
					expect(offline.conflicts).toEqual({})
				}
				await offline.resolveConflict(
					offline.conflictEntries[0].entryId,
					'mine',
				)

				expect(
					log.saves.map((s) => [s.baseUpdatedAt, s.sourceBaseUpdatedAt]),
				).toEqual([
					[BASE, EDIT_SOURCE],
					[server.copy, server.source],
				])
				expect(offline.conflictEntries).toHaveLength(0)
				expect(stored.size).toBe(0)
			},
		)

		it('"keep mine" on a stale copy keeps the source version that 409 named, so a source that moved right after it is refused again, not saved over', async () => {
			await goOffline()
			await secrets.updateSecret(
				's1',
				{ key: 'new' },
				{ sourceBaseUpdatedAt: EDIT_SOURCE },
			)
			goOnline()
			const named = context.sourceUpdatedAt
			const server = { copy: named, source: named }
			const log = shareSaveServer({
				id: 's1',
				context: {
					...context,
					get sourceUpdatedAt() {
						return server.source
					},
				},
				onSave: (body) => {
					if (body.baseUpdatedAt !== server.copy) {
						const refusal = httpError(409, {
							error: 'stale',
							current: { id: 's1', updatedAt: server.copy },
							sourceUpdatedAt: server.source,
						})
						// The owner saves again as soon as this 409 is answered.
						server.source = '2026-10-02T11:00:00+00:00'
						throw refusal
					}
					if (body.sourceBaseUpdatedAt !== server.source) {
						throw httpError(409, {
							error: 'stale_source',
							sourceUpdatedAt: server.source,
						})
					}
				},
			})
			await offline.replayQueue()
			expect([...stored.values()][0].conflictSourceUpdatedAt).toBe(named)

			await offline.resolveConflict(offline.conflictEntries[0].entryId, 'mine')

			expect(log.saves.at(-1).sourceBaseUpdatedAt).toBe(named)
			expect(offline.conflictEntries).toHaveLength(1)
			expect(stored.size).toBe(1)
		})

		it('"keep mine" on a stale copy saved without a write grade replays its plain update on the row version that 409 returned', async () => {
			await goOffline()
			await secrets.updateSecret(
				's1',
				{ key: 'new' },
				{ sourceBaseUpdatedAt: EDIT_SOURCE },
			)
			goOnline()
			const log = shareSaveServer({
				id: 's1',
				context: { ...context, effectiveGrade: 'read' },
			})
			const current = { id: 's1', updatedAt: '2026-10-02T09:00:00+00:00' }
			// The plain update names no source version: it sends none either.
			axios.put.mockRejectedValueOnce(
				httpError(409, { message: 'This secret changed', current }),
			)
			await offline.replayQueue()
			expect(writeContextReads()).toBe(1)
			expect([...stored.values()][0]).toMatchObject({
				conflictUpdatedAt: current.updatedAt,
				conflictSourceUpdatedAt: null,
			})

			await offline.resolveConflict(offline.conflictEntries[0].entryId, 'mine')

			expect(log.plainPuts.at(-1)[1].baseUpdatedAt).toBe(current.updatedAt)
			expect(log.saves).toHaveLength(0)
			expect(stored.size).toBe(0)
		})

		it('an edit in the dialog of a copy opened from the cached snapshot replays on the source version the manifest carried', async () => {
			const pub = await importPublicKey(RSA4096_PUBLIC_KEY_SPKI_PEM)
			const aesKey = await crypto.subtle.generateKey(
				{ name: 'AES-GCM', length: 256 },
				false,
				['encrypt', 'decrypt'],
			)
			snapshot = await encryptSnapshot(aesKey, {
				suite: {
					id: 'suite-1',
					certificate: RSA4096_PUBLIC_KEY_SPKI_PEM,
					privateKey: 'ENVELOPE',
				},
				secrets: [
					{
						id: 's1',
						name: 'Pump station router',
						typeId: 'login',
						key: await rsaEncrypt('old', pub),
						updatedAt: BASE,
						sourceUpdatedAt: EDIT_SOURCE,
					},
				],
				folders: [],
				offlineEditsEnabled: true,
				syncedAt: BASE,
			})
			session = useSessionStore()
			// The master-password unlock itself is covered elsewhere.
			session.unlockFromBlob = vi.fn(async ({ certificate, suiteId }) => {
				session.aesKey = aesKey
				session.cryptoKey = await importPrivateKey(
					RSA4096_PRIVATE_KEY_PKCS8_PEM,
				)
				session.certificate = certificate
				session.suiteId = suiteId
			})
			offline = useOfflineStore()
			offline.online = false
			await offline.unlockOffline('master password')
			const typeStore = useSecretTypeStore()
			typeStore.types = [{ id: 'login', name: 'login', label: 'Login' }]
			typeStore.fetchTypes = vi.fn().mockResolvedValue()
			const writeContext = vi.spyOn(useShareStore(), 'fetchWriteContext')

			const wrapper = mount(SecretEditDialog, {
				propsData: { secretId: 's1' },
				global: {
					stubs: {
						NcDialog: { template: '<div><slot /></div>' },
						NcButton: true,
						NcTextField: true,
						NcPasswordField: true,
						NcNoteCard: true,
						NcLoadingIcon: true,
						Dice5: true,
						KeyGeneratorModal: true,
					},
				},
			})
			await vi.waitFor(() => expect(wrapper.vm.original?.key).toBe('old'))
			expect(await wrapper.vm.sourceVersion()).toBe(EDIT_SOURCE)
			wrapper.vm.value = 'Xk9#mQ2$vL7@pR4!zT6&'
			await wrapper.vm.submit()

			expect(writeContext).not.toHaveBeenCalled()
			expect([...stored.values()]).toHaveLength(1)
			expect([...stored.values()][0].sourceBaseUpdatedAt).toBe(EDIT_SOURCE)

			goOnline()
			const log = shareSaveServer({
				id: 's1',
				context: { ...context, sourceUpdatedAt: EDIT_SOURCE },
			})
			await offline.replayQueue()

			expect(log.saves.map((s) => s.sourceBaseUpdatedAt)).toEqual([
				EDIT_SOURCE,
			])
			expect(stored.size).toBe(0)
		})

		it('replays an entry that recorded no source version with the source version of replay time', async () => {
			await goOffline()
			await secrets.updateSecret('s1', { key: 'new' })
			expect([...stored.values()][0].sourceBaseUpdatedAt).toBeNull()
			goOnline()
			const log = shareSaveServer({ id: 's1', context })

			await offline.replayQueue()

			expect(log.saves[0].sourceBaseUpdatedAt).toBe(context.sourceUpdatedAt)
			expect(stored.size).toBe(0)
		})
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

	it('a 428 refusal moves the entry to the failed list; a 423 keeps the rest queued', async () => {
		await goOffline()
		await secrets.updateSecret('s1', { name: 'A' })
		await secrets.createSecret({ name: 'B', key: 'v' })
		goOnline()
		vi.spyOn(axios, 'put').mockRejectedValueOnce({ response: { status: 428 } })
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

	it('a write the share quarantine scan holds back fails with the server\'s words, and the queue goes on', async () => {
		await goOffline()
		await secrets.updateSecret('s1', { name: 'A' })
		await secrets.createSecret({ name: 'B', key: 'v' })
		goOnline()
		const message = 'This share waits for the share quarantine scan and cannot be changed yet; ask an administrator to check that background jobs run'
		vi.spyOn(axios, 'put').mockRejectedValueOnce(
			httpError(409, { error: 'share_scan_pending', message }),
		)
		const post = vi.spyOn(axios, 'post').mockResolvedValueOnce({
			data: { id: 'created' },
		})

		const result = await offline.replayQueue()

		expect(result).toEqual({ synced: 1, stopped: false })
		expect(post).toHaveBeenCalledTimes(1)
		expect(offline.failedEntries.map((e) => e.secretId)).toEqual(['s1'])
		const failed = offline.failedEntries[0]
		expect(failed.failure).toEqual({ code: 'share_scan_pending', message })
		expect(failureText(failed.failure)).toBe(message)
		expect(stored.size).toBe(1)
	})

	it('a failed share save writes nothing and keeps the whole entry queued', async () => {
		await goOffline()
		await secrets.updateSecret('s1', { key: 'new' })
		goOnline()
		const log = shareSaveServer({
			id: 's1',
			onSave: () => {
				throw new Error('Network Error')
			},
		})

		const result = await offline.replayQueue()

		expect(result.stopped).toBe(true)
		expect(log.plainPuts).toHaveLength(0)
		expect([...stored.values()][0].status).toBe('queued')
	})

	it('an unreachable partner fails the entry with its reason, and the queue goes on', async () => {
		await goOffline()
		await secrets.updateSecret('s1', { key: 'new' })
		await secrets.createSecret({ name: 'B', key: 'v' })
		goOnline()
		const log = shareSaveServer({
			id: 's1',
			partners: [{ shareId: 'fs-1', recipientCloudId: 'bob@partner' }],
			rows: { s1: { id: 's1', updatedAt: BASE } },
			posts: { '/api/v1/secrets': { id: 'created' } },
		})

		const result = await offline.replayQueue()

		expect(result).toEqual({ synced: 1, stopped: false })
		expect(log.saves).toHaveLength(1)
		expect(offline.failedEntries.map((e) => e.secretId)).toEqual(['s1'])
		expect(failureText(offline.failedEntries[0].failure)).toContain(
			'bob@partner',
		)
	})

	it('a partner lookup answered for another account suspends that share, and the queue goes on', async () => {
		await goOffline()
		await secrets.updateSecret('s1', { key: 'new' })
		await secrets.createSecret({ name: 'B', key: 'v' })
		goOnline()
		const log = shareSaveServer({
			id: 's1',
			partners: [{ shareId: 'fs-1', recipientCloudId: BOB }],
			rows: { s1: { id: 's1', updatedAt: BASE } },
			posts: { '/api/v1/secrets': { id: 'created' } },
			lookup: {
				[BOB]: {
					...BOB_VERIFIED,
					cloudId: 'mallory@cloud.partner.example',
					certificate: CHAIN.mallory,
				},
			},
		})

		const result = await offline.replayQueue()

		expect(result).toEqual({ synced: 2, stopped: false })
		expect(log.saves.at(-1).federated).toEqual([])
		expect(log.saves.at(-1).suspend).toEqual([
			{ shareId: 'fs-1', reason: 'cloud_id_mismatch' },
		])
		expect(offline.failedEntries).toEqual([])
		expect(stored.size).toBe(0)
	})

	it('keeps the reason sealed with the entry, so it is still shown after a reload', async () => {
		await goOffline()
		await secrets.updateSecret('s1', { key: 'new' })
		goOnline()
		shareSaveServer({
			id: 's1',
			partners: [{ shareId: 'fs-1', recipientCloudId: 'bob@partner' }],
			rows: { s1: { id: 's1', updatedAt: BASE } },
		})
		await offline.replayQueue()

		const atRest = JSON.stringify([...stored.values()])
		expect(atRest).not.toContain('bob@partner')
		expect(atRest).not.toContain('partner_unreachable')

		setActivePinia(createPinia())
		const reloaded = useOfflineStore()
		useSessionStore().cryptoKey = session.cryptoKey
		useSessionStore().suiteId = 'suite-1'
		await reloaded.loadQueue()

		const [failed] = reloaded.failedEntries
		expect(failed.failure).toEqual({
			code: 'partner_unreachable',
			partners: ['bob@partner'],
		})
		expect(failureText(failed.failure)).toContain('bob@partner')
	})

	it('"Try again" replays a partner failure: it fails again cleanly while the partner is down, and saves once it answers', async () => {
		await goOffline()
		await secrets.updateSecret('s1', { key: 'new' })
		goOnline()
		const partners = [{ shareId: 'fs-1', recipientCloudId: BOB }]
		const rows = { s1: { id: 's1', updatedAt: BASE } }
		const down = shareSaveServer({ id: 's1', partners, rows })
		await offline.replayQueue()
		const entryId = offline.failedEntries[0].entryId

		await offline.retryEntry(entryId)

		expect(down.saves).toHaveLength(2)
		expect(down.plainPuts).toHaveLength(0)
		expect(offline.failedEntries.map((e) => e.entryId)).toEqual([entryId])
		expect(failureText(offline.failedEntries[0].failure)).toContain(BOB)
		expect([...stored.values()][0].status).toBe('failed')

		vi.restoreAllMocks()
		const up = shareSaveServer({
			id: 's1',
			partners,
			rows,
			lookup: { [BOB]: BOB_VERIFIED },
		})

		await offline.retryEntry(entryId)

		expect(up.saves.at(-1).federated.map((f) => f.shareId)).toEqual(['fs-1'])
		expect(offline.entries).toHaveLength(0)
		expect(stored.size).toBe(0)
	})

	it('a partner lookup limit that outlasts the wait fails the entry with its reason, and the queue goes on', async () => {
		await goOffline()
		await secrets.updateSecret('s1', { key: 'new' })
		await secrets.createSecret({ name: 'B', key: 'v' })
		goOnline()
		shareSaveServer({
			id: 's1',
			partners: [{ shareId: 'fs-1', recipientCloudId: BOB }],
			rows: { s1: { id: 's1', updatedAt: BASE } },
			posts: { '/api/v1/secrets': { id: 'created' } },
			lookup: {
				[BOB]: { reject: httpError(429, { message: 'Too Many Requests' }) },
			},
		})
		vi.useFakeTimers({ toFake: ['setTimeout', 'Date'] })
		let result
		try {
			result = await settleWithTimers(offline.replayQueue())
		} finally {
			vi.useRealTimers()
		}

		expect(result).toEqual({ synced: 1, stopped: false })
		const [failed] = offline.failedEntries
		expect(failed.secretId).toBe('s1')
		expect(failed.failure).toEqual({ code: 'partner_lookup_limit' })
		expect(failureText(failed.failure)).toContain('try again in a minute')
	})

	it('a failed entry left at "sync" keeps that status, so "Try again" finishes it from the saved row', async () => {
		await goOffline()
		const kept = await secrets.updateSecret('s1', { key: 'queued-value' })
		goOnline()
		await offline.setStatus(offline.entries[0], 'sync')
		const rowAt = '2026-10-02T12:00:00+00:00'
		const options = {
			id: 's1',
			partners: [{ shareId: 'fs-1', recipientCloudId: BOB }],
			rows: { s1: { id: 's1', key: kept.key, updatedAt: rowAt } },
			onSave: (body) => {
				if (body.baseUpdatedAt !== rowAt) {
					throw httpError(409, { error: 'stale', current: { id: 's1' } })
				}
			},
		}
		shareSaveServer(options)
		await offline.replayQueue()
		const [failed] = offline.failedEntries
		expect(failed.failure.resumeAs).toBe('sync')
		expect(failureText(failed.failure)).toContain(BOB)

		vi.restoreAllMocks()
		const up = shareSaveServer({ ...options, lookup: { [BOB]: BOB_VERIFIED } })
		await offline.retryEntry(failed.entryId)

		expect(up.saves.at(-1).baseUpdatedAt).toBe(rowAt)
		expect(up.saves.at(-1).federated.map((f) => f.shareId)).toEqual(['fs-1'])
		expect(offline.conflicts).toEqual({})
		expect(offline.entries).toHaveLength(0)
	})

	it('a share save the server refuses as malformed fails the entry with its reason', async () => {
		await goOffline()
		await secrets.updateSecret('s1', { key: 'new' })
		goOnline()
		shareSaveServer({
			id: 's1',
			onSave: () => {
				throw httpError(400, { error: 'invalid_key' })
			},
		})

		const result = await offline.replayQueue()

		expect(result.stopped).toBe(false)
		const [failed] = offline.failedEntries
		// The code stays stored with the entry; the list shows words.
		expect(failed.failure).toEqual({ code: 'refused', reason: 'invalid_key' })
		expect(failureText(failed.failure)).toBe('Failed to save secret')
		expect(failureText(failed.failure)).not.toContain('invalid_key')
	})

	it('an entry left at "sync" is saved with its copies while the row still holds it, without the merge count', async () => {
		await goOffline()
		const kept = await secrets.updateSecret('s1', {
			key: 'queued-value',
			additionalFields: { pin: '1' },
			mergedPending: 2,
		})
		goOnline()
		const entry = offline.entries[0]
		expect(entry.body.mergedPending).toBe(2)
		await offline.setStatus(entry, 'sync')
		const rowAt = '2026-10-02T12:00:00+00:00'
		const log = shareSaveServer({
			id: 's1',
			rows: {
				s1: {
					id: 's1',
					key: kept.key,
					additionalFields: kept.additionalFields,
					updatedAt: rowAt,
				},
			},
		})

		await offline.replayQueue()

		expect(log.saves).toHaveLength(1)
		expect(log.saves[0].baseUpdatedAt).toBe(rowAt)
		expect(log.saves[0].patch.key).toBe(kept.key)
		expect('mergedPending' in log.saves[0].patch).toBe(false)
		expect(stored.size).toBe(0)
	})

	it('an entry left at "sync" is dropped when the row moved on', async () => {
		await goOffline()
		await secrets.updateSecret('s1', { key: 'queued-value' })
		goOnline()
		await offline.setStatus(offline.entries[0], 'sync')
		const log = shareSaveServer({
			id: 's1',
			rows: { s1: { id: 's1', key: 'NEWER', updatedAt: BASE } },
		})

		await offline.replayQueue()

		expect(log.saves).toHaveLength(0)
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

describe('the text of a refused change', () => {
	it("shows the share save's own words for a code it knows, and never a code", () => {
		expect(
			failureText({ code: 'refused', reason: 'version_refused' }),
		).toContain('cannot be restored')
		for (const reason of ['invalid_key', 'too_many_entries', '']) {
			const text = failureText({ code: 'refused', reason })
			expect(text).toBe('Failed to save secret')
		}
	})
})
