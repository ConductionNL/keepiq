/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The share save (keepiq#1254): a value change of a shared secret reaches the
 * own row and every copy in one request, with real RSA keys, so each #1253 bug
 * is pinned by what a recipient can actually decrypt.
 *
 * @spec openspec/specs/user-sharing/spec.md#requirement-sync-on-update
 */
import axios from '@nextcloud/axios'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { importPrivateKey, rsaDecrypt } from '../../src/crypto/rsa.js'
import {
	encryptSparse,
	MAX_INCOMPLETE_ROUNDS,
	PARTNER_LIMIT_WAIT_CAP_MS,
	saveShared,
	ShareSaveError,
	shareSaveErrorText,
} from '../../src/services/shareSave.js'
import { useSecretStore } from '../../src/store/modules/secret.js'
import { useSecretVersionStore } from '../../src/store/modules/secretVersion.js'
import { useSessionStore } from '../../src/store/modules/session.js'
import {
	httpError,
	SAVED_AT,
	settleWithTimers,
	shareSaveServer,
} from '../fixtures/shareSaveServer.js'
import {
	RSA4096_PRIVATE_KEY_PKCS8_PEM,
	RSA4096_PUBLIC_KEY_SPKI_PEM,
	RSA4096_SECONDARY_PRIVATE_KEY_PKCS8_PEM,
	RSA4096_SECONDARY_PUBLIC_KEY_SPKI_PEM,
} from '../vitest/fixtures/rsa-fixtures.js'

const showInfo = vi.fn()
vi.mock('@nextcloud/dialogs', () => ({ showInfo: (...a) => showInfo(...a) }))

const BASE = '2026-10-01T10:00:00+00:00'
const OWNER_CERT = RSA4096_PUBLIC_KEY_SPKI_PEM
const BOB_CERT = RSA4096_SECONDARY_PUBLIC_KEY_SPKI_PEM

/**
 * A required local recipient copy, as the incomplete answer lists it.
 *
 * @param {string} secretId The copy.
 * @param {string} targetUserId Its holder.
 * @return {object}
 */
function recipient(secretId, targetUserId) {
	return { secretId, holder: 'recipient', targetUserId }
}

/**
 * Decrypt every value field of an entry with a private key.
 *
 * @param {object} entry A copy entry.
 * @param {string} pem The holder's private key.
 * @return {Promise<object>}
 */
async function open(entry, pem) {
	const key = await importPrivateKey(pem)
	const out = {}
	for (const field of ['key', 'login', 'additionalFields']) {
		if (field in entry) {
			out[field] =
				entry[field] === null ? null : await rsaDecrypt(entry[field], key)
		}
	}
	return out
}

beforeEach(async () => {
	setActivePinia(createPinia())
	vi.restoreAllMocks()
	showInfo.mockReset()
	const session = useSessionStore()
	session.cryptoKey = await importPrivateKey(RSA4096_PRIVATE_KEY_PKCS8_PEM)
	session.certificate = OWNER_CERT
})

describe('encryptSparse', () => {
	it('encrypts only the fields present and keeps a cleared field null', async () => {
		const out = await encryptSparse({ key: 'pw', login: null }, BOB_CERT)

		expect(Object.keys(out).sort()).toEqual(['key', 'login'])
		expect(await open(out, RSA4096_SECONDARY_PRIVATE_KEY_PKCS8_PEM)).toEqual({
			key: 'pw',
			login: null,
		})
	})
})

describe('saveShared on the owner path', () => {
	it('saves an unshared secret in one request with empty lists', async () => {
		const log = shareSaveServer({ id: 's1' })

		const result = await saveShared({
			id: 's1',
			plainDiff: { key: 'new' },
			patch: { key: 'OWN' },
			baseUpdatedAt: BASE,
		})

		expect(result.secret.updatedAt).toBe(SAVED_AT)
		expect(log.saves).toHaveLength(1)
		expect(log.saves[0]).toMatchObject({
			baseUpdatedAt: BASE,
			patch: { key: 'OWN' },
			copies: [],
			skipped: [],
			federated: [],
			suspend: [],
		})
		expect('sourceBaseUpdatedAt' in log.saves[0]).toBe(false)
		expect(log.lookups).toHaveLength(0)
	})

	it('encrypts for every local recipient with certificates looked up by user (#1253 bug 1)', async () => {
		const log = shareSaveServer({
			id: 's1',
			required: [recipient('copy-bob', 'bob')],
			suites: { bob: BOB_CERT },
		})

		await saveShared({
			id: 's1',
			plainDiff: { key: 'new-pw', login: 'alice' },
			patch: { key: 'OWN', login: 'OWN' },
			baseUpdatedAt: BASE,
		})

		expect(log.lookups).toEqual([['bob']])
		expect(log.saves).toHaveLength(2)
		const [copy] = log.saves[1].copies
		expect(copy.secretId).toBe('copy-bob')
		expect(await open(copy, RSA4096_SECONDARY_PRIVATE_KEY_PKCS8_PEM)).toEqual({
			key: 'new-pw',
			login: 'alice',
		})
	})

	it('sends the field names the server reads, never the encrypted* ones (#1253 bug 2)', async () => {
		const log = shareSaveServer({
			id: 's1',
			required: [recipient('copy-bob', 'bob')],
			suites: { bob: BOB_CERT },
		})

		await saveShared({
			id: 's1',
			plainDiff: { key: 'k', login: 'l', additionalFields: '{"a":"1"}' },
			patch: { key: 'K', login: 'L', additionalFields: 'A' },
			baseUpdatedAt: BASE,
		})

		expect(Object.keys(log.saves[1].copies[0]).sort()).toEqual([
			'additionalFields',
			'key',
			'login',
			'secretId',
		])
		expect(JSON.stringify(log.saves)).not.toContain('encrypted')
	})

	it('looks certificates up 100 users at a time and stages more than 100 copies, each for its own holder', async () => {
		const users = Array.from({ length: 230 }, (_, i) => `user-${i}`)
		const { generateKeyPair } = await import('../../src/crypto/rsa.js')
		const others = await generateKeyPair()
		// Two holders on either side of the 100 boundary have their own key.
		const suites = Object.fromEntries(users.map((u) => [u, others.publicKeyPem]))
		suites['user-0'] = OWNER_CERT
		suites['user-150'] = BOB_CERT
		const log = shareSaveServer({
			id: 's1',
			required: users.map((u) => recipient(`copy-${u}`, u)),
			suites,
		})
		// The lookup answers in another order than it was asked.
		const serve = axios.post.getMockImplementation()
		axios.post.mockImplementation(async (url, body) => {
			const answer = await serve(url, body)
			if (url.endsWith('/shares/recipient-certificates')) {
				answer.data.recipients.reverse()
			}
			return answer
		})

		await saveShared({
			id: 's1',
			plainDiff: { key: 'k' },
			patch: { key: 'K' },
			baseUpdatedAt: BASE,
		})

		expect(log.lookups.map((batch) => batch.length)).toEqual([100, 100, 30])
		const [, ...staged] = log.saves
		expect(staged.map((s) => s.stage)).toEqual([
			{ final: false },
			{ id: 'stage-1', final: false },
			{ id: 'stage-1', final: true },
		])
		expect(staged.map((s) => s.copies.length)).toEqual([100, 100, 30])
		expect('patch' in staged[0]).toBe(false)
		expect(staged[2].patch).toEqual({ key: 'K' })
		const copy = (u) =>
			staged.flatMap((st) => st.copies).find((c) => c.secretId === `copy-${u}`)
		expect(await open(copy('user-0'), RSA4096_PRIVATE_KEY_PKCS8_PEM)).toEqual({
			key: 'k',
		})
		expect(
			await open(copy('user-150'), RSA4096_SECONDARY_PRIVATE_KEY_PKCS8_PEM),
		).toEqual({ key: 'k' })
		expect(await rsaDecrypt(copy('user-99').key, others.privateKey)).toBe('k')
	}, 60000)

	describe('a staged save that has to go round again', () => {
		const users = Array.from({ length: 150 }, (_, i) => `user-${i}`)
		const holders = users.map((u) => recipient(`copy-${u}`, u))
		const suites = Object.fromEntries(
			[...users, 'late'].map((u) => [u, BOB_CERT]),
		)
		const save = () =>
			saveShared({
				id: 's1',
				plainDiff: { key: 'k' },
				patch: { key: 'K' },
				baseUpdatedAt: BASE,
			})

		it("resends the final call's own entries plus the missing ones under the same stage", async () => {
			const log = shareSaveServer({
				id: 's1',
				// A recipient arrives after the chunk was staged.
				required: (n) =>
					n >= 3 ? [...holders, recipient('copy-late', 'late')] : holders,
				suites,
			})

			await save()

			expect(log.saves.map((s) => s.stage)).toEqual([
				undefined,
				{ final: false },
				{ id: 'stage-1', final: true },
				{ id: 'stage-1', final: true },
			])
			const last = log.saves[3].copies.map((c) => c.secretId)
			expect(last).toHaveLength(51)
			expect(last).toContain('copy-late')
			expect(log.chunks).toBe(1)
		}, 60000)

		it('starts a new stage when a staged entry is no longer wanted', async () => {
			const log = shareSaveServer({
				id: 's1',
				// copy-user-0 went into the chunk, then its share was revoked.
				required: (n) => (n >= 3 ? holders.slice(1) : holders),
				suites,
			})

			await save()

			expect(log.saves[3].stage).toEqual({ final: false })
			const resent = log.saves
				.slice(3)
				.flatMap((s) => s.copies.map((c) => c.secretId))
			expect(resent).toHaveLength(149)
			expect(resent).not.toContain('copy-user-0')
		}, 60000)

		it('starts a new stage when the server lost the old one', async () => {
			const log = shareSaveServer({
				id: 's1',
				required: holders,
				suites,
				onSave: (_body, n) => {
					if (n === 3) throw httpError(404, { error: 'stage_unknown' })
				},
			})

			await save()

			expect(log.saves.slice(3).map((s) => s.stage)).toEqual([
				{ final: false },
				{ id: 'stage-1', final: true },
			])
			expect(log.saves.at(-1).copies).toHaveLength(50)
		}, 60000)
	})

	it('skips a recipient without a vault key and encrypts after all when the server refuses the skip', async () => {
		const suites = {}
		const log = shareSaveServer({
			id: 's1',
			required: [recipient('copy-carol', 'carol')],
			suites,
			onSave: (_body, n) => {
				// Carol finished setting up her vault between lookup and save.
				if (n === 2) suites.carol = BOB_CERT
			},
		})

		await saveShared({
			id: 's1',
			plainDiff: { key: 'k' },
			patch: { key: 'K' },
			baseUpdatedAt: BASE,
		})

		expect(log.saves[1].skipped).toEqual([
			{ secretId: 'copy-carol', reason: 'no_vault_key' },
		])
		expect(log.saves[2].skipped).toEqual([])
		expect(log.saves[2].copies.map((c) => c.secretId)).toEqual(['copy-carol'])
		expect(log.lookups).toEqual([['carol'], ['carol']])
	})

	it('drops a copy whose share was revoked between rounds', async () => {
		const log = shareSaveServer({
			id: 's1',
			required: (n) =>
				n <= 2
					? [recipient('copy-bob', 'bob'), recipient('copy-dave', 'dave')]
					: [recipient('copy-bob', 'bob')],
			suites: { bob: BOB_CERT, dave: BOB_CERT },
			onSave: (_body, n) => {
				if (n === 2)
					throw httpError(409, {
						error: 'incomplete',
						copies: {
							required: [recipient('copy-bob', 'bob')],
							missing: [],
							unexpected: ['copy-dave'],
							skipRefused: [],
						},
					})
			},
		})

		await saveShared({
			id: 's1',
			plainDiff: { key: 'k' },
			patch: { key: 'K' },
			baseUpdatedAt: BASE,
		})

		expect(log.saves[2].copies.map((c) => c.secretId)).toEqual(['copy-bob'])
	})

	it(`stops after ${MAX_INCOMPLETE_ROUNDS} incomplete answers and writes nothing`, async () => {
		const log = shareSaveServer({
			id: 's1',
			// A new recipient every round: the list never settles.
			required: (n) =>
				Array.from({ length: n }, (_, i) => recipient(`copy-${i}`, `u${i}`)),
			suites: new Proxy({}, { get: () => BOB_CERT }),
		})

		const error = await saveShared({
			id: 's1',
			plainDiff: { key: 'k' },
			patch: { key: 'K' },
			baseUpdatedAt: BASE,
		}).catch((e) => e)

		expect(error).toBeInstanceOf(ShareSaveError)
		expect(error.code).toBe('incomplete')
		expect(log.saves).toHaveLength(MAX_INCOMPLETE_ROUNDS)
		expect(shareSaveErrorText(error)).toContain('Nothing was saved')
	})

	it('passes a stale refusal on untouched, as an error with its own text', async () => {
		shareSaveServer({
			id: 's1',
			onSave: () => {
				throw httpError(409, { error: 'stale', current: { id: 's1' } })
			},
		})

		const error = await saveShared({
			id: 's1',
			plainDiff: { key: 'k' },
			patch: { key: 'K' },
			baseUpdatedAt: BASE,
		}).catch((e) => e)

		expect(error.response.status).toBe(409)
		expect(error.fromShareSave).toBe(true)
		expect(shareSaveErrorText(error)).toContain('changed elsewhere')
		expect(
			shareSaveErrorText(httpError(409, { error: 'stale_source' })),
		).toContain('original')
	})

	it("gives its own text only to the share save's own refusal", async () => {
		const shareSaveRefusal = (data) =>
			Object.assign(httpError(428, data), { fromShareSave: true })

		expect(
			shareSaveErrorText(shareSaveRefusal({ error: 'forbidden' })),
		).toContain('not allowed')
		expect(
			shareSaveErrorText(shareSaveRefusal({ error: 'not_allowed' })),
		).toContain('not allowed')
		// A vault policy refusal keeps the server's specific message.
		expect(
			shareSaveErrorText(
				shareSaveRefusal({
					error: 'org_ownership_required',
					code: 'org_ownership_required',
				}),
			),
		).toBeNull()
		// So does a refusal of the plain update.
		expect(shareSaveErrorText(httpError(428, { error: 'forbidden' }))).toBeNull()
	})

	it('aborts with an ordinary error when this server fails the partner lookup', async () => {
		for (const status of [500, 409]) {
			const log = shareSaveServer({
				id: 's1',
				partners: [{ shareId: 'fs-1', recipientCloudId: 'bob@partner' }],
				lookup: {
					'bob@partner': { reject: httpError(status, { message: 'x' }) },
				},
			})

			const error = await saveShared({
				id: 's1',
				plainDiff: { key: 'k' },
				patch: { key: 'K' },
				baseUpdatedAt: BASE,
			}).catch((e) => e)

			expect(error).not.toBeInstanceOf(ShareSaveError)
			expect(error.response.status).toBe(status)
			expect(log.saves).toHaveLength(1)
			vi.restoreAllMocks()
		}
	})

	describe('a partner lookup limit that does not lift', () => {
		/**
		 * Save against a lookup that always answers 429.
		 *
		 * @param {object} headers The 429's headers.
		 * @return {Promise<{log: object, error: Error, waits: Array<number>}>}
		 */
		async function saveLimited(headers) {
			const log = shareSaveServer({
				id: 's1',
				partners: [
					{ shareId: 'fs-1', recipientCloudId: 'bob@partner' },
					{ shareId: 'fs-2', recipientCloudId: 'carol@partner' },
				],
				lookup: {
					'bob@partner': {
						reject: httpError(
							429,
							{ message: 'Too Many Requests' },
							headers,
						),
					},
				},
			})
			const waits = []
			const error = await settleWithTimers(
				saveShared({
					id: 's1',
					plainDiff: { key: 'k' },
					patch: { key: 'K' },
					baseUpdatedAt: BASE,
					withoutUnreachable: true,
					onWait: (ms) => waits.push(ms),
				}),
			)
			return { log, error, waits }
		}

		beforeEach(() => {
			vi.useFakeTimers({ toFake: ['setTimeout', 'Date'] })
		})
		afterEach(() => {
			vi.useRealTimers()
		})

		it('waits a minute per 429 without Retry-After up to the cap, then stops with its own text, pausing nobody', async () => {
			const { log, error, waits } = await saveLimited({})

			expect(waits).toEqual(Array(5).fill(60000))
			expect(waits.reduce((a, b) => a + b)).toBe(PARTNER_LIMIT_WAIT_CAP_MS)
			expect(error).toBeInstanceOf(ShareSaveError)
			expect(error.code).toBe('partner_lookup_limit')
			expect(shareSaveErrorText(error)).toContain('try again in a minute')
			expect(log.partnerLookups).toEqual(Array(6).fill('bob@partner'))
			expect(log.saves).toHaveLength(1)
		})

		it('stops at once when the next Retry-After would pass the cap', async () => {
			const { log, error, waits } = await saveLimited({ 'retry-after': '200' })

			expect(waits).toEqual([200000])
			expect(error.code).toBe('partner_lookup_limit')
			expect(log.partnerLookups).toHaveLength(2)
			expect(log.saves).toHaveLength(1)
		})
	})
})

describe('saveShared on the copy-editor path', () => {
	const context = {
		sourceSecretId: 'src',
		effectiveGrade: 'write',
		ownerCertificate: OWNER_CERT,
		sourceUpdatedAt: '2026-09-30T08:00:00+00:00',
	}
	/** The source version the edit was made from, before the write-context's. */
	const EDIT_SOURCE = '2026-09-30T07:00:00+00:00'

	it('sends a password-only edit without login or fields, so nothing unchanged is cleared (#1253 bug 3)', async () => {
		const log = shareSaveServer({
			id: 'copy-me',
			context,
			required: [
				{ secretId: 'src', holder: 'owner', targetUserId: null },
				recipient('copy-bob', 'bob'),
			],
			suites: { bob: BOB_CERT },
		})

		await saveShared({
			id: 'copy-me',
			plainDiff: { key: 'only-the-password' },
			patch: { key: 'MINE' },
			baseUpdatedAt: BASE,
			sourceBaseUpdatedAt: EDIT_SOURCE,
		})

		const final = log.saves.at(-1)
		expect(final.sourceBaseUpdatedAt).toBe(EDIT_SOURCE)
		expect('federated' in final).toBe(false)
		expect('suspend' in final).toBe(false)
		for (const copy of final.copies) {
			expect(Object.keys(copy).sort()).toEqual(['key', 'secretId'])
		}
		const source = final.copies.find((c) => c.secretId === 'src')
		expect(await open(source, RSA4096_PRIVATE_KEY_PKCS8_PEM)).toEqual({
			key: 'only-the-password',
		})
		const bob = final.copies.find((c) => c.secretId === 'copy-bob')
		expect(await open(bob, RSA4096_SECONDARY_PRIVATE_KEY_PKCS8_PEM)).toEqual({
			key: 'only-the-password',
		})
	})

	it('sends the source version the edit was made from, so a source that moved since is refused as stale_source', async () => {
		const log = shareSaveServer({
			id: 'copy-me',
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

		const error = await useSecretStore()
			.updateSecret(
				'copy-me',
				{ key: 'x' },
				{ baseUpdatedAt: BASE, sourceBaseUpdatedAt: EDIT_SOURCE },
			)
			.catch((e) => e)

		expect(log.saves.map((s) => s.sourceBaseUpdatedAt)).toEqual([EDIT_SOURCE])
		expect(error.response.data.error).toBe('stale_source')
		expect(shareSaveErrorText(error)).toContain('original')
	})

	it('takes the source version of this moment only when the edit recorded none', async () => {
		const log = shareSaveServer({ id: 'copy-me', context })

		await saveShared({
			id: 'copy-me',
			plainDiff: { key: 'x' },
			patch: { key: 'X' },
			baseUpdatedAt: BASE,
		})

		expect(log.saves[0].sourceBaseUpdatedAt).toBe(context.sourceUpdatedAt)
	})

	it('moves the source version on past a merge write-back through the copy, so the edit is not refused against it', async () => {
		const { importPublicKey, rsaEncrypt } =
			await import('../../src/crypto/rsa.js')
		const own = await importPublicKey(OWNER_CERT)
		const row = {
			id: 'copy-me',
			key: await rsaEncrypt('pw', own),
			pendingAdditionalFields: [await rsaEncrypt('{"pin":"1234"}', own)],
			updatedAt: BASE,
		}
		const log = shareSaveServer({
			id: 'copy-me',
			context,
			rows: { 'copy-me': row },
			onSave: (body, n) => {
				if (n > 1 && body.sourceBaseUpdatedAt !== SAVED_AT) {
					throw httpError(409, { error: 'stale_source' })
				}
			},
		})
		const serve = axios.put.getMockImplementation()
		let release
		const held = new Promise((resolve) => (release = resolve))
		axios.put.mockImplementation(async (url, body) => {
			if (body.patch?.mergedPending) await held
			return serve(url, body)
		})
		const store = useSecretStore()
		await store.fetchSecret('copy-me')

		const saving = store.updateSecret(
			'copy-me',
			{ key: 'new' },
			{ baseUpdatedAt: BASE, sourceBaseUpdatedAt: context.sourceUpdatedAt },
		)
		release()
		await saving

		expect(log.saves.map((s) => s.sourceBaseUpdatedAt)).toEqual([
			context.sourceUpdatedAt,
			SAVED_AT,
		])
	})

	it('sends a cleared login as null to every copy', async () => {
		const log = shareSaveServer({
			id: 'copy-me',
			context,
			required: [{ secretId: 'src', holder: 'owner', targetUserId: null }],
		})

		await saveShared({
			id: 'copy-me',
			plainDiff: { login: null },
			patch: { login: null },
			baseUpdatedAt: BASE,
		})

		expect(log.saves.at(-1).copies).toEqual([{ secretId: 'src', login: null }])
	})

	it('saves only the own copy through the plain update without a write grade', async () => {
		const log = shareSaveServer({
			id: 'copy-me',
			context: { ...context, effectiveGrade: 'none' },
		})

		const result = await saveShared({
			id: 'copy-me',
			plainDiff: { key: 'k' },
			patch: { key: 'K' },
			baseUpdatedAt: BASE,
		})

		expect(log.saves).toHaveLength(0)
		expect(log.plainPuts[0][0]).toContain('/api/v1/secrets/copy-me')
		expect(log.plainPuts[0][1]).toEqual({ key: 'K', baseUpdatedAt: BASE })
		expect(result.secret.updatedAt).toBe(SAVED_AT)
	})

	it('restores the own copy without a write grade through the version restore, which records it', async () => {
		const log = shareSaveServer({
			id: 'copy-me',
			context: { ...context, effectiveGrade: 'read' },
			posts: {
				'/api/v1/versions/v1/restore': {
					id: 'copy-me',
					updatedAt: SAVED_AT,
				},
			},
		})
		const whole = { key: 'k', login: null, additionalFields: null }

		const result = await saveShared({
			id: 'copy-me',
			plainDiff: whole,
			patch: { ...whole, key: 'K' },
			baseUpdatedAt: BASE,
			restoredFrom: 'v1',
		})

		expect(log.saves).toHaveLength(0)
		expect(log.plainPuts).toHaveLength(0)
		expect(axios.post).toHaveBeenCalledWith(
			expect.stringContaining('/api/v1/versions/v1/restore'),
			{ baseUpdatedAt: BASE },
		)
		expect(result.secret.updatedAt).toBe(SAVED_AT)
	})

	it('shows a stale restore of the own copy like any stale save, and changes nothing', async () => {
		const moved = '2026-10-01T10:30:00+00:00'
		const restores = []
		shareSaveServer({
			id: 'copy-me',
			context: { ...context, effectiveGrade: 'read' },
			rows: { v1: { id: 'v1', name: 'Router', key: null, login: null } },
			posts: {
				'/api/v1/versions/v1/restore': (body) => {
					restores.push(body)
					if (body.baseUpdatedAt !== moved) {
						throw httpError(409, {
							error: 'stale',
							message: 'Secret changed',
							current: { id: 'copy-me', updatedAt: moved },
						})
					}
					return { id: 'copy-me', updatedAt: SAVED_AT }
				},
			},
		})
		useSecretStore().currentSecret = { id: 'copy-me', updatedAt: BASE }
		const store = useSecretVersionStore()

		await expect(store.restore('v1', 'copy-me')).rejects.toBeTruthy()

		expect(restores).toEqual([{ baseUpdatedAt: BASE }])
		expect(store.error).toContain('changed elsewhere')
	})
})

describe('updateSecret', () => {
	it('sends the base it was given and returns the saved row', async () => {
		const log = shareSaveServer({ id: 's1' })

		const saved = await useSecretStore().updateSecret(
			's1',
			{ key: 'new' },
			{ baseUpdatedAt: BASE },
		)

		expect(log.saves[0].baseUpdatedAt).toBe(BASE)
		expect(
			await open(log.saves[0].patch, RSA4096_PRIVATE_KEY_PKCS8_PEM),
		).toEqual({
			key: 'new',
		})
		expect(saved).toEqual({ id: 's1', updatedAt: SAVED_AT })
	})

	it('takes the base of the open secret and keeps it current after the save', async () => {
		const log = shareSaveServer({ id: 's1' })
		const store = useSecretStore()
		store.currentSecret = { id: 's1', updatedAt: BASE }

		await store.updateSecret('s1', { login: '' })

		expect(log.saves[0].baseUpdatedAt).toBe(BASE)
		expect(log.saves[0].patch).toEqual({ login: null })
		expect(store.currentSecret.updatedAt).toBe(SAVED_AT)
	})

	it('rejects when the share save fails instead of reporting it saved (#1253 bug 4)', async () => {
		shareSaveServer({
			id: 's1',
			required: [recipient('copy-bob', 'bob')],
			suites: { bob: BOB_CERT },
			onSave: (_body, n) => {
				if (n === 2) throw httpError(500, { message: 'boom' })
			},
		})

		await expect(
			useSecretStore().updateSecret(
				's1',
				{ key: 'x' },
				{ baseUpdatedAt: BASE },
			),
		).rejects.toMatchObject({ response: { status: 500 } })
	})

	it("tells a copy editor that partner shares get the change on the owner's next save", async () => {
		shareSaveServer({
			id: 'copy-me',
			context: {
				sourceSecretId: 'src',
				effectiveGrade: 'write',
				ownerCertificate: OWNER_CERT,
				sourceUpdatedAt: BASE,
				ownerOnly: true,
			},
			required: [{ secretId: 'src', holder: 'owner', targetUserId: null }],
		})

		await useSecretStore().updateSecret(
			'copy-me',
			{ key: 'x' },
			{ baseUpdatedAt: BASE },
		)

		expect(showInfo).toHaveBeenCalledTimes(1)
		expect(showInfo.mock.calls[0][0]).toContain('partner organisations')
	})

	it('refuses a value change without a known base instead of fetching one', async () => {
		const log = shareSaveServer({ id: 's1' })

		await expect(
			useSecretStore().updateSecret('s1', { key: 'x' }),
		).rejects.toThrow('No base version')
		expect(log.saves).toHaveLength(0)
	})

	it('opens a secret without waiting for the merge write-back', async () => {
		const { importPublicKey, rsaEncrypt } =
			await import('../../src/crypto/rsa.js')
		const own = await importPublicKey(OWNER_CERT)
		const row = {
			id: 's1',
			key: await rsaEncrypt('pw', own),
			pendingAdditionalFields: [await rsaEncrypt('{"pin":"1234"}', own)],
			updatedAt: BASE,
		}
		shareSaveServer({ id: 's1', rows: { s1: row } })
		const store = useSecretStore()
		let release
		const writeBack = vi
			.spyOn(store, 'updateSecret')
			.mockImplementation(() => new Promise((resolve) => (release = resolve)))

		const opened = await store.fetchSecret('s1')

		expect(opened.additionalFields).toEqual({ pin: '1234' })
		expect(writeBack).toHaveBeenCalledTimes(1)
		release({ id: 's1', updatedAt: SAVED_AT })
	})

	it('bases a save made during the write-back on the write-back, so it is not refused as stale', async () => {
		const { importPublicKey, rsaEncrypt } =
			await import('../../src/crypto/rsa.js')
		const own = await importPublicKey(OWNER_CERT)
		const row = {
			id: 's1',
			key: await rsaEncrypt('pw', own),
			pendingAdditionalFields: [await rsaEncrypt('{"pin":"1234"}', own)],
			updatedAt: BASE,
		}
		const log = shareSaveServer({
			id: 's1',
			rows: { s1: row },
			onSave: (body, n) => {
				if (n > 1 && body.baseUpdatedAt !== SAVED_AT) {
					throw httpError(409, { error: 'stale', current: { id: 's1' } })
				}
			},
		})
		const serve = axios.put.getMockImplementation()
		let release
		const held = new Promise((resolve) => (release = resolve))
		axios.put.mockImplementation(async (url, body) => {
			if (body.patch?.mergedPending) await held
			return serve(url, body)
		})
		const store = useSecretStore()

		const opened = await store.fetchSecret('s1')
		expect(opened.additionalFields).toEqual({ pin: '1234' })
		expect(log.saves).toHaveLength(0)

		const saving = store.updateSecret(
			's1',
			{ key: 'new' },
			{ baseUpdatedAt: BASE },
		)
		release()
		const saved = await saving

		expect(log.saves.map((s) => s.baseUpdatedAt)).toEqual([BASE, SAVED_AT])
		expect(log.saves[0].patch.mergedPending).toBe(1)
		expect(log.saves[1].patch.mergedPending).toBeUndefined()
		expect(saved.updatedAt).toBe(SAVED_AT)
	})

	it('bases a restore made during the write-back on the write-back, so it is not refused as stale', async () => {
		const { importPublicKey, rsaEncrypt } =
			await import('../../src/crypto/rsa.js')
		const own = await importPublicKey(OWNER_CERT)
		const rows = {
			s1: {
				id: 's1',
				key: await rsaEncrypt('pw', own),
				pendingAdditionalFields: [await rsaEncrypt('{"pin":"1234"}', own)],
				updatedAt: BASE,
			},
			v1: { id: 'v1', name: 'Router', key: await rsaEncrypt('old', own) },
			versions: [],
		}
		const log = shareSaveServer({
			id: 's1',
			rows,
			onSave: (body, n) => {
				if (n > 1 && body.baseUpdatedAt !== SAVED_AT) {
					throw httpError(409, { error: 'stale', current: { id: 's1' } })
				}
			},
		})
		const serve = axios.put.getMockImplementation()
		let release
		const held = new Promise((resolve) => (release = resolve))
		axios.put.mockImplementation(async (url, body) => {
			if (body.patch?.mergedPending) await held
			return serve(url, body)
		})
		const store = useSecretStore()
		await store.fetchSecret('s1')
		// Read after the restore, without pending members to merge again.
		rows.s1 = { id: 's1', updatedAt: SAVED_AT }

		const restoring = useSecretVersionStore().restore('v1', 's1')
		release()
		await restoring

		expect(log.saves.map((s) => s.baseUpdatedAt)).toEqual([BASE, SAVED_AT])
		expect(log.saves[1].restoredFrom).toBe('v1')
	})

	it('restores a version as one share save of its whole value', async () => {
		const { importPublicKey, rsaEncrypt } =
			await import('../../src/crypto/rsa.js')
		const own = await importPublicKey(OWNER_CERT)
		const version = {
			id: 'v1',
			name: 'Router (old)',
			url: 'https://router.example',
			key: await rsaEncrypt('restored-pw', own),
			login: await rsaEncrypt('alice', own),
			additionalFields: null,
		}
		const head = { id: 's1', updatedAt: BASE }
		const log = shareSaveServer({
			id: 's1',
			required: [recipient('copy-bob', 'bob')],
			suites: { bob: BOB_CERT },
			rows: { v1: version, s1: head, versions: [] },
		})
		useSecretStore().currentSecret = head

		await useSecretVersionStore().restore('v1', 's1')

		expect(axios.post.mock.calls.map(([url]) => url)).not.toContainEqual(
			expect.stringContaining('/restore'),
		)
		const final = log.saves.at(-1)
		expect(final.baseUpdatedAt).toBe(BASE)
		expect(final.restoredFrom).toBe('v1')
		expect(final.patch).toMatchObject({
			name: 'Router (old)',
			url: 'https://router.example',
			additionalFields: null,
		})
		expect(await open(final.patch, RSA4096_PRIVATE_KEY_PKCS8_PEM)).toMatchObject(
			{ key: 'restored-pw', login: 'alice' },
		)
		expect(
			await open(final.copies[0], RSA4096_SECONDARY_PRIVATE_KEY_PKCS8_PEM),
		).toEqual({ key: 'restored-pw', login: 'alice', additionalFields: null })
	})

	it('restores nothing when the share save fails', async () => {
		const version = { id: 'v1', name: 'Router', key: null, login: null }
		shareSaveServer({
			id: 's1',
			rows: { v1: version },
			onSave: () => {
				throw httpError(409, { error: 'stale', current: { id: 's1' } })
			},
		})
		useSecretStore().currentSecret = { id: 's1', updatedAt: BASE }
		const store = useSecretVersionStore()

		await expect(store.restore('v1', 's1')).rejects.toBeTruthy()

		expect(axios.post).not.toHaveBeenCalled()
		expect(store.error).toContain('changed elsewhere')
	})

	it('sends restoredFrom only with the patch, never on a chunk', async () => {
		const users = Array.from({ length: 120 }, (_, i) => `user-${i}`)
		const log = shareSaveServer({
			id: 's1',
			required: users.map((u) => recipient(`copy-${u}`, u)),
			suites: Object.fromEntries(users.map((u) => [u, BOB_CERT])),
		})

		await saveShared({
			id: 's1',
			plainDiff: { key: 'k', login: null, additionalFields: null },
			patch: { key: 'K', login: null, additionalFields: null },
			baseUpdatedAt: BASE,
			restoredFrom: 'v1',
		})

		expect(log.saves.map((s) => s.restoredFrom)).toEqual(['v1', undefined, 'v1'])
	}, 60000)

	it('names a refused version in its own words', () => {
		expect(
			shareSaveErrorText(httpError(400, { error: 'version_refused' })),
		).toContain('cannot be restored')
	})

	it('saves a metadata-only change through the plain update', async () => {
		const log = shareSaveServer({ id: 's1' })

		await useSecretStore().updateSecret('s1', { name: 'Renamed' })

		expect(log.saves).toHaveLength(0)
		expect(log.plainPuts[0][1]).toEqual({ name: 'Renamed' })
	})
})
