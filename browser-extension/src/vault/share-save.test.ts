import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createClient } from '@/src/api/client'
import { importPrivateKey, pemToPkcs8, rsaDecrypt, verifyFederatedCertificate } from '@/src/crypto'
import { decodePem, extractSpki } from '@/src/crypto/rsa'
import { encrypt, secretRow } from '@/src/testing/vault'
import { envelope, federationChain } from '@/src/testing/vectors'
import { clearKey } from './key-store'
import { shareSave, VALUE_FIELDS, type Opened, type ShareSaveInput, type Values } from './share-save'
import { forgetVerifiedPartners, VERIFIED_PARTNER_TTL_MS } from './verified-partners'
import { writeError } from './write-error'

vi.mock('@/src/crypto', async (importOriginal) => {
	const actual = await importOriginal<typeof import('@/src/crypto')>()
	return { ...actual, verifyFederatedCertificate: vi.fn(actual.verifyFederatedCertificate) }
})

const F = federationChain()
const CERT = envelope.certificatePem as string
const BASE = '2026-03-02T10:00:00+00:00'
const SOURCE_AT = '2026-03-01T09:00:00+00:00'
const BOB = { shareId: 'fs-bob', recipientCloudId: 'bob@cloud.partner.example' }
const CAROL = { shareId: 'fs-carol', recipientCloudId: 'carol@cloud.partner.example' }

const fetchMock = vi.fn<typeof fetch>()
const json = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status })
const client = createClient({ serverUrl: 'https://cloud.example.org', uid: 'alice', loginName: 'alice', appPassword: 'pw' })
let privateKey: CryptoKey
const decrypt = (ciphertext: unknown) => rsaDecrypt(ciphertext as string, privateKey)

type Entry = Record<string, unknown>
interface Body {
	baseUpdatedAt: string
	sourceBaseUpdatedAt?: string | null
	patch?: Record<string, unknown>
	copies: Array<Entry & { secretId: string }>
	skipped: Array<{ secretId: string; reason: string }>
	federated?: Array<Entry & { shareId: string }>
	suspend?: Array<{ shareId: string; reason: string }>
	stage?: { id?: string; final: boolean }
}
interface RequiredCopy { secretId: string; holder: 'owner' | 'recipient'; targetUserId: string | null }

/** The sharing graph the fake server judges a share-save by, as `ShareSaveScope` does. */
interface World {
	copyPath: boolean
	copies: RequiredCopy[]
	shares: Array<typeof BOB>
	/** Copies whose holder has a vault key, so the server refuses their skip. */
	keyed: Set<string>
	ownerOnly: boolean
	ownerCertificate: string | null
	effectiveGrade: string
	/** Per user id; absent or `null` is not shareable. */
	certificates: Record<string, string | null>
	/** Per cloud id; absent is Bob's own verified chain. */
	lookups: Record<string, () => Response | Promise<Response>>
	/** Runs before each final call is judged, by its number from 1; a response replaces the judgement. */
	onFinal?: (attempt: number) => Response | void
}

let world: World
/** Every share-save body, chunks included. */
let saves: Body[]
let stages: Map<string, Body[]>
let finals: number

const recipient = (secretId: string, targetUserId: string): RequiredCopy => ({ secretId, holder: 'recipient', targetUserId })
const finalCalls = () => saves.filter((body) => body.stage?.final !== false)
const lastFinal = () => finalCalls().at(-1)!
const sent = () => fetchMock.mock.calls.map(([input, init]) => `${init?.method ?? 'GET'} ${new URL(String(input)).pathname.replace('/index.php/apps/keepiq', '')}`)
const bodiesOf = (line: string) => fetchMock.mock.calls.filter((_, i) => sent()[i] === line).map(([, init]) => JSON.parse(String(init!.body)) as Record<string, unknown>)

function judge(body: Body): Response {
	saves.push(body)
	const local = body.copies.length + body.skipped.length
	const remote = (body.federated?.length ?? 0) + (body.suspend?.length ?? 0)
	if (local > 100 || remote > 100) return json({ error: 'too_many_entries' }, 400)
	if (body.stage?.id && !stages.has(body.stage.id)) return json({ error: 'stage_unknown', message: 'The stage does not exist or expired' }, 404)
	if (body.stage?.final === false) {
		if (local + remote === 0) return json({ error: 'empty_chunk' }, 400)
		const id = body.stage.id ?? crypto.randomUUID()
		if (!body.stage.id) stages.clear()
		stages.set(id, [...stages.get(id) ?? [], body])
		return json({ stageId: id }, 202)
	}
	const replaced = world.onFinal?.(++finals)
	if (replaced) return replaced
	const parts = [...(body.stage?.id ? stages.get(body.stage.id)! : []), body]
	const copies = parts.flatMap((part) => part.copies)
	const skipped = parts.flatMap((part) => part.skipped)
	const federated = parts.flatMap((part) => part.federated ?? [])
	const suspend = parts.flatMap((part) => part.suspend ?? [])
	const fields = VALUE_FIELDS.filter((field) => field in body.patch!)
	for (const copy of copies) {
		if (VALUE_FIELDS.filter((field) => field in copy).join() !== fields.join() || fields.some((field) => (copy[field] === null) !== (body.patch![field] === null))) {
			return json({ error: 'copy_fields_mismatch' }, 400)
		}
	}
	const named = [...copies, ...skipped].map((entry) => entry.secretId)
	const shareIds = [...federated, ...suspend].map((entry) => entry.shareId)
	if (new Set(named).size !== named.length || new Set(shareIds).size !== shareIds.length) return json({ error: 'duplicate_entry' }, 400)
	const skipRefused = skipped.filter((skip) => world.keyed.has(skip.secretId)).map((skip) => skip.secretId)
	const required = world.copies.map((copy) => copy.secretId)
	const shares = world.copyPath ? [] : world.shares
	const incomplete = required.some((id) => !named.includes(id)) || named.some((id) => !required.includes(id)) || skipRefused.length > 0
		|| shares.some((share) => !shareIds.includes(share.shareId)) || shareIds.some((id) => !shares.some((share) => share.shareId === id))
	if (incomplete) {
		return json({
			error: 'incomplete', path: world.copyPath ? 'copy' : 'owner', sourceUpdatedAt: SOURCE_AT,
			copies: { required: world.copies, missing: [], unexpected: [], skipRefused }, federated: { required: shares, missing: [], unexpected: [] },
		}, 409)
	}
	return json({
		secret: secretRow(), sourceUpdatedAt: '2026-03-02T10:00:01+00:00', copies: { written: copies.length, skipped: skipped.length },
		federated: { written: federated.length, suspended: suspend.length, notified: federated.length, queued: 0, ownerOnly: world.ownerOnly },
	})
}

function route(method: string, path: string, body: Record<string, unknown> | undefined): Response | Promise<Response> {
	if (method === 'GET' && path.endsWith('/write-context')) {
		return json({ sourceSecretId: world.copyPath ? 'src' : 's1', effectiveGrade: world.effectiveGrade, ownerCertificate: world.ownerCertificate, sourceUpdatedAt: SOURCE_AT })
	}
	if (method === 'PUT' && path.endsWith('/share-save')) return judge(body as unknown as Body)
	if (method === 'POST' && path === '/api/v1/shares/recipient-certificates') {
		return json({ recipients: (body!.userIds as string[]).map((userId) => (world.certificates[userId] ? { userId, shareable: true, certificate: world.certificates[userId] } : { userId, shareable: false, reason: 'no_active_suite' })) })
	}
	if (method === 'POST' && path === '/api/v1/federation/recipient-certificate') {
		const cloudId = body!.cloudId as string
		return world.lookups[cloudId]?.() ?? json({ cloudId, certificate: F.bob, chain: [F.intermediateA, F.rootA], partnerRootFingerprint: F.rootAFingerprint })
	}
	if (method === 'PUT' && path.startsWith('/api/v1/secrets/')) return json(secretRow())
	return json({ message: 'Not found' }, 404)
}

beforeEach(async () => {
	vi.stubGlobal('fetch', fetchMock)
	fetchMock.mockReset()
	fetchMock.mockImplementation(async (input, init) => {
		const url = new URL(String(input))
		return route(init?.method ?? 'GET', url.pathname.replace('/index.php/apps/keepiq', ''), init?.body ? JSON.parse(String(init.body)) : undefined)
	})
	vi.spyOn(console, 'error').mockImplementation(() => {})
	privateKey ??= await importPrivateKey(pemToPkcs8(envelope.privateKeyPem))
	world = {
		copyPath: false, copies: [], shares: [], keyed: new Set(), ownerOnly: false, ownerCertificate: CERT, effectiveGrade: 'owner',
		certificates: { bob: CERT, carol: CERT, dan: CERT, erin: CERT }, lookups: {},
	}
	saves = []
	stages = new Map()
	finals = 0
	forgetVerifiedPartners()
})

/** What the form showed of X: its password, username and fields, as ciphertext. */
async function openedX(overrides: Partial<Opened> = {}): Promise<Opened> {
	return {
		shown: { key: await encrypt('x-password'), login: await encrypt('alice'), additionalFields: await encrypt('{"notes":"X notes"}') },
		updatedAt: BASE, sourceUpdatedAt: SOURCE_AT, ...overrides,
	}
}

/** A password change to `new`, unless `plain` says otherwise; the patch's own ciphertext is the server's business. */
function save(overrides: Partial<ShareSaveInput> = {}) {
	const plain: Values = overrides.plain ?? { key: 'new' }
	const patch = Object.fromEntries(Object.entries(plain).map(([field, value]) => [field, value === null ? null : `own:${field}`]))
	return shareSave(client, privateKey, { accountId: 'a1', id: 's1', patch, plain, baseUpdatedAt: BASE, opened: undefined, withoutUnreachable: new Set(), ...overrides })
}

/** What the popup would be told. */
async function failure(pending: Promise<unknown>) {
	const error = await pending.then(() => new Error('saved'), (caught: unknown) => caught)
	return writeError(error)
}

describe('rounds', () => {
	it('saves an item shared with nobody in one share-save with empty lists, and looks up nothing', async () => {
		expect(await save()).toBeUndefined()
		expect(sent()).toEqual(['GET /api/v1/secrets/s1/write-context', 'PUT /api/v1/secrets/s1/share-save'])
		expect(saves).toEqual([{ baseUpdatedAt: BASE, patch: { key: 'own:key' }, copies: [], skipped: [], federated: [], suspend: [] }])
	})

	it('sends the patch with no copies first, then Bob\'s copy, encrypted to his certificate', async () => {
		world.copies = [recipient('c-bob', 'bob')]
		expect(await save()).toBeUndefined()
		expect(saves.map((body) => body.copies.map((copy) => copy.secretId))).toEqual([[], ['c-bob']])
		expect(await decrypt(saves[1]!.copies[0]!.key)).toBe('new')
		expect(saves[1]!.patch).toEqual({ key: 'own:key' })
		expect(sent()).not.toContain('PUT /api/v1/secrets/s1')
	})

	it('fails with incomplete after the third incomplete answer, sending no fourth share-save', async () => {
		world.copies = [recipient('c-bob', 'bob')]
		world.onFinal = (attempt) => {
			world.copies = [...world.copies, recipient(`c-new${attempt}`, 'carol')]
		}
		expect(await failure(save())).toEqual({ code: 'incomplete' })
		expect(finalCalls()).toHaveLength(3)
	})

	it('drops a copy the answer no longer requires and fills the one it newly requires', async () => {
		world.copies = [recipient('c-bob', 'bob')]
		world.onFinal = (attempt) => {
			if (attempt === 2) world.copies = [recipient('c-carol', 'carol')]
		}
		expect(await save()).toBeUndefined()
		expect(lastFinal().copies.map((copy) => copy.secretId)).toEqual(['c-carol'])
		// Bob's certificate is not looked up again.
		expect(bodiesOf('POST /api/v1/shares/recipient-certificates').map((body) => body.userIds)).toEqual([['bob'], ['carol']])
	})
})

describe('local copies', () => {
	it('fills a direct, two group and a team-folder copy from one certificate request', async () => {
		world.copies = [recipient('c-bob', 'bob'), recipient('c-carol', 'carol'), recipient('c-dan', 'dan'), recipient('c-erin', 'erin')]
		await save()
		expect(lastFinal().copies.map((copy) => copy.secretId)).toEqual(['c-bob', 'c-carol', 'c-dan', 'c-erin'])
		expect(bodiesOf('POST /api/v1/shares/recipient-certificates')).toEqual([{ userIds: ['bob', 'carol', 'dan', 'erin'] }])
		for (const copy of lastFinal().copies) expect(await decrypt(copy.key)).toBe('new')
	})

	it('asks once for a holder of two copies', async () => {
		world.copies = [recipient('c-bob-direct', 'bob'), recipient('c-bob-group', 'bob')]
		await save()
		expect(bodiesOf('POST /api/v1/shares/recipient-certificates')).toEqual([{ userIds: ['bob'] }])
		expect(lastFinal().copies).toHaveLength(2)
	})

	it('sends only the changed username, and nothing of the password or fields', async () => {
		world.copies = [recipient('c-bob', 'bob')]
		await save({ plain: { login: 'bob' } })
		expect(Object.keys(lastFinal().copies[0]!).sort()).toEqual(['login', 'secretId'])
		expect(await decrypt(lastFinal().copies[0]!.login)).toBe('bob')
	})

	it('clears a username with null in the patch and in each copy', async () => {
		world.copies = [recipient('c-bob', 'bob')]
		await save({ plain: { login: null } })
		expect(lastFinal().patch).toEqual({ login: null })
		expect(lastFinal().copies).toEqual([{ secretId: 'c-bob', login: null }])
	})

	it('skips a holder without a vault key, and saves for everyone else', async () => {
		world.copies = [recipient('c-bob', 'bob'), recipient('c-carol', 'carol')]
		world.certificates.bob = null
		expect(await save()).toBeUndefined()
		expect(lastFinal().skipped).toEqual([{ secretId: 'c-bob', reason: 'no_vault_key' }])
		expect(lastFinal().copies.map((copy) => copy.secretId)).toEqual(['c-carol'])
	})

	it('reads a certificate again once when the server refuses its skip', async () => {
		world.copies = [recipient('c-bob', 'bob')]
		world.certificates.bob = null
		world.keyed.add('c-bob')
		world.onFinal = (attempt) => {
			if (attempt === 2) world.certificates.bob = CERT
		}
		expect(await save()).toBeUndefined()
		expect(lastFinal().copies.map((copy) => copy.secretId)).toEqual(['c-bob'])
		expect(lastFinal().skipped).toEqual([])
	})

	it('does not read it a third time when the skip is refused again', async () => {
		world.copies = [recipient('c-bob', 'bob')]
		world.certificates.bob = null
		world.keyed.add('c-bob')
		expect(await failure(save())).toEqual({ code: 'incomplete' })
		expect(bodiesOf('POST /api/v1/shares/recipient-certificates')).toHaveLength(2)
	})

	it.each([
		['a garbled certificate', '-----BEGIN CERTIFICATE-----\nAAAA\n-----END CERTIFICATE-----'],
		['text that is no PEM', 'not a certificate'],
	])('fails with bad_certificate for %s, sending nothing more', async (_, certificate) => {
		world.copies = [recipient('c-bob', 'bob'), recipient('c-carol', 'carol')]
		world.certificates.carol = certificate
		expect(await failure(save())).toEqual({ code: 'bad_certificate' })
		expect(saves).toHaveLength(1)
	})

	it('reads 230 members\' certificates in three requests and stages two chunks of 100 before a final call with the last 30', async () => {
		world.copies = Array.from({ length: 230 }, (_, i) => recipient(`c${i}`, `user${i}`))
		world.certificates = Object.fromEntries(world.copies.map((copy) => [copy.targetUserId, CERT]))
		await save()
		expect(bodiesOf('POST /api/v1/shares/recipient-certificates').map((body) => (body.userIds as string[]).length)).toEqual([100, 100, 30])
		const [first, chunk1, chunk2, final] = saves
		expect(first!.copies).toEqual([])
		expect([chunk1, chunk2].map((chunk) => [chunk!.copies.length, chunk!.stage, 'patch' in chunk!])).toEqual([[100, { final: false }, false], [100, { id: expect.any(String), final: false }, false]])
		expect(chunk2!.stage!.id).toBe(stages.keys().next().value)
		expect(final!.copies).toHaveLength(30)
		expect(final!.patch).toEqual({ key: 'own:key' })
		expect(final!.stage).toEqual({ id: chunk2!.stage!.id, final: true })
		expect(await decrypt(final!.copies[29]!.key)).toBe('new')
	})

	it.each([
		[100, [], 100],
		[101, [100], 1],
	])('sends %i copies in chunks of %j and a final call with %i', async (count, chunks, last) => {
		world.copies = Array.from({ length: count }, (_, i) => recipient(`c${i}`, `user${i}`))
		world.certificates = Object.fromEntries(world.copies.map((copy) => [copy.targetUserId, CERT]))
		await save()
		expect(saves.filter((body) => body.stage?.final === false).map((body) => body.copies.length)).toEqual(chunks)
		expect(lastFinal().copies).toHaveLength(last)
	})

	it('starts a new stage when the answer drops a copy already staged', async () => {
		world.copies = Array.from({ length: 102 }, (_, i) => recipient(`c${i}`, `user${i}`))
		world.certificates = Object.fromEntries(world.copies.map((copy) => [copy.targetUserId, CERT]))
		world.onFinal = (attempt) => {
			if (attempt === 2) world.copies = world.copies.slice(1)
		}
		await save()
		const chunks = saves.filter((body) => body.stage?.final === false)
		expect(chunks.map((chunk) => [chunk.copies.length, chunk.stage?.id])).toEqual([[100, undefined], [100, undefined]])
		expect(chunks[1]!.copies.map((copy) => copy.secretId)).not.toContain('c0')
		const [first, second] = saves.filter((body) => body.stage?.final === true)
		expect(second!.stage!.id).not.toBe(first!.stage!.id)
		expect(second!.copies).toHaveLength(1)
	})

	/** Every final call with a stage finds it expired, until `expiries` ran out. */
	function expireStages(expiries: number) {
		fetchMock.mockImplementation(async (input, init) => {
			const path = new URL(String(input)).pathname.replace('/index.php/apps/keepiq', '')
			const body = init?.body ? JSON.parse(String(init.body)) as Body : undefined
			if (body?.stage?.final === true && expiries-- > 0) stages.clear()
			return route(init?.method ?? 'GET', path, body as never)
		})
	}

	it('restarts an expired stage, counted as a round', async () => {
		world.copies = Array.from({ length: 101 }, (_, i) => recipient(`c${i}`, `user${i}`))
		world.certificates = Object.fromEntries(world.copies.map((copy) => [copy.targetUserId, CERT]))
		expireStages(1)
		expect(await save()).toBeUndefined()
		expect(saves.filter((body) => body.stage?.final === false).map((chunk) => chunk.stage)).toEqual([{ final: false }, { final: false }])
		expect(saves.filter((body) => body.stage?.final === true)).toHaveLength(2)
	})

	it('fails as unknown when a staged chunk is answered without a stage id, sending no final call', async () => {
		world.copies = Array.from({ length: 101 }, (_, i) => recipient(`c${i}`, `user${i}`))
		world.certificates = Object.fromEntries(world.copies.map((copy) => [copy.targetUserId, CERT]))
		fetchMock.mockImplementation(async (input, init) => {
			const body = init?.body ? JSON.parse(String(init.body)) as Body : undefined
			if (body?.stage?.final === false) return json({}, 202)
			return route(init?.method ?? 'GET', new URL(String(input)).pathname.replace('/index.php/apps/keepiq', ''), body as never)
		})
		expect(await failure(save())).toEqual({ code: 'unknown' })
		expect(saves).toHaveLength(1)
	})

	it('fails with incomplete when an expired stage is the third round', async () => {
		world.copies = Array.from({ length: 101 }, (_, i) => recipient(`c${i}`, `user${i}`))
		world.certificates = Object.fromEntries(world.copies.map((copy) => [copy.targetUserId, CERT]))
		expireStages(Infinity)
		expect(await failure(save())).toEqual({ code: 'incomplete' })
		expect(saves.filter((body) => body.stage?.final === true)).toHaveLength(2)
	})
})

describe('the copy-editor path', () => {
	beforeEach(() => {
		world.copyPath = true
		world.effectiveGrade = 'write'
		world.copies = [{ secretId: 'src', holder: 'owner', targetUserId: null }, recipient('c-carol', 'carol')]
	})

	it('saves through the editor\'s copy with the source version the form read, for the source and every other copy', async () => {
		await save({ opened: await openedX({ sourceUpdatedAt: '2026-02-01T00:00:00+00:00' }) })
		const body = lastFinal()
		expect(body.sourceBaseUpdatedAt).toBe('2026-02-01T00:00:00+00:00')
		expect(body.copies.map((copy) => copy.secretId)).toEqual(['src', 'c-carol'])
		expect(await decrypt(body.copies[0]!.key)).toBe('new')
		expect(body).not.toHaveProperty('federated')
		expect(body).not.toHaveProperty('suspend')
		expect(bodiesOf('POST /api/v1/shares/recipient-certificates')).toEqual([{ userIds: ['carol'] }])
	})

	it.each([
		['manage', 'a team manager'],
		['owner', 'a delegate'],
	])('saves for the source and every other copy on the %s grade, as %s', async (grade) => {
		world.effectiveGrade = grade
		expect(await save()).toBeUndefined()
		expect(lastFinal().copies.map((copy) => copy.secretId)).toEqual(['src', 'c-carol'])
		expect(lastFinal().sourceBaseUpdatedAt).toBe(SOURCE_AT)
		expect(sent()).not.toContain('PUT /api/v1/secrets/s1')
	})

	it('takes the source version of its own write-context read without the form\'s record', async () => {
		await save()
		expect(lastFinal().sourceBaseUpdatedAt).toBe(SOURCE_AT)
	})

	it('says when partner shares stay with the owner\'s next save, and looks no partner up', async () => {
		world.ownerOnly = true
		expect(await save()).toEqual({ scope: 'copy', ownerOnly: true })
		expect(sent()).not.toContain('POST /api/v1/federation/recipient-certificate')
	})

	it('skips the source when its owner has no vault key, and reads write-context again when that skip is refused', async () => {
		world.ownerCertificate = null
		world.keyed.add('src')
		world.onFinal = (attempt) => {
			if (attempt === 2) world.ownerCertificate = CERT
		}
		await save()
		expect(saves[1]!.skipped).toEqual([{ secretId: 'src', reason: 'no_vault_key' }])
		expect(lastFinal().copies.map((copy) => copy.secretId).sort()).toEqual(['c-carol', 'src'])
		expect(sent().filter((line) => line.endsWith('/write-context'))).toHaveLength(2)
	})

	it('reports a source written since the form opened as stale_source', async () => {
		world.onFinal = () => json({ error: 'stale_source', message: 'The source changed since the edit was made', sourceUpdatedAt: '2026-03-03T00:00:00+00:00' }, 409)
		expect(await failure(save())).toEqual({ code: 'stale_source' })
	})

	it.each(['read', 'none'])('saves only the editor\'s copy with a plain PUT on a %s grade', async (grade) => {
		world.effectiveGrade = grade
		expect(await save()).toEqual({ scope: 'own_copy' })
		expect(sent()).toEqual(['GET /api/v1/secrets/s1/write-context', 'PUT /api/v1/secrets/s1'])
		expect(bodiesOf('PUT /api/v1/secrets/s1')).toEqual([{ key: 'own:key', baseUpdatedAt: BASE }])
	})
})

describe('partner shares', () => {
	const fingerprint = async (pem: string) => Array.from(new Uint8Array(await crypto.subtle.digest('SHA-256', decodePem(pem).der)), (byte) => byte.toString(16).padStart(2, '0')).join('')

	beforeEach(() => {
		world.shares = [BOB]
	})

	it('sends Bob the whole value with the fingerprint of the certificate it verified, looked up by his share\'s cloud id', async () => {
		expect(await save({ opened: await openedX() })).toBeUndefined()
		expect(bodiesOf('POST /api/v1/federation/recipient-certificate')).toEqual([{ cloudId: BOB.recipientCloudId }])
		const [entry] = lastFinal().federated!
		expect(Object.keys(entry!).sort()).toEqual(['additionalFields', 'certFingerprint', 'key', 'login', 'shareId'])
		expect(entry!.certFingerprint).toBe(await fingerprint(F.bob))
		expect(lastFinal().suspend).toEqual([])
	})

	it('builds the whole value from what was typed and what the form opened, never from the row', async () => {
		vi.mocked(verifyFederatedCertificate).mockResolvedValueOnce({ fingerprint: 'ab'.repeat(32), spki: extractSpki(decodePem(CERT).der) })
		await save({ opened: await openedX() })
		const [entry] = lastFinal().federated!
		expect([await decrypt(entry!.key), await decrypt(entry!.login), await decrypt(entry!.additionalFields)]).toEqual(['new', 'alice', '{"notes":"X notes"}'])
		expect(sent()).not.toContain('GET /api/v1/secrets/s1')
	})

	it('sends an empty username or fields as null', async () => {
		await save({ opened: await openedX({ shown: { key: await encrypt('x-password'), login: null, additionalFields: null } }) })
		expect(lastFinal().federated![0]).toMatchObject({ login: null, additionalFields: null })
	})

	it('needs no record when every field was typed', async () => {
		expect(await save({ plain: { key: 'new', login: 'bob', additionalFields: '{}' } })).toBeUndefined()
		expect(lastFinal().federated).toHaveLength(1)
	})

	it.each([
		['the form\'s record is gone', async () => undefined],
		['the form did not show the fields in full', async () => openedX({ shown: { key: await encrypt('x-password'), login: null } })],
		['the record is of another version', async () => openedX({ updatedAt: '2026-01-01T00:00:00+00:00' })],
	])('fails with unverified before any lookup when %s', async (_, opened) => {
		expect(await failure(save({ opened: await opened() }))).toEqual({ code: 'unverified' })
		expect(sent()).not.toContain('POST /api/v1/federation/recipient-certificate')
		expect(saves).toHaveLength(1)
	})

	it('needs no record for an item without partner shares', async () => {
		world.shares = []
		world.copies = [recipient('c-bob', 'bob')]
		expect(await save()).toBeUndefined()
	})

	it.each([
		['a chain to another root', () => json({ cloudId: BOB.recipientCloudId, certificate: F.bobOtherRoot, chain: [F.intermediateB, F.rootB], partnerRootFingerprint: F.rootAFingerprint }), 'untrusted_root'],
		['someone else\'s certificate', () => json({ cloudId: 'mallory@cloud.partner.example', certificate: F.mallory, chain: [F.intermediateA, F.rootA], partnerRootFingerprint: F.rootAFingerprint }), 'name_mismatch'],
		['garbage', () => json({ cloudId: BOB.recipientCloudId, certificate: 'garbage', chain: [F.intermediateA, F.rootA], partnerRootFingerprint: F.rootAFingerprint }), 'malformed'],
		['a recipient the partner does not know', () => json({ message: 'unknown_recipient' }, 404), 'unknown_recipient'],
		['an instance that is no partner any more', () => json({ message: 'not_a_partner' }, 403), 'not_a_partner'],
		['a partner that served this server the wrong chain', () => json({ message: 'bad_chain' }, 502), 'bad_chain'],
	])('suspends Bob\'s share for %s, sends him nothing, and names him on the note', async (_, answer, reason) => {
		world.lookups[BOB.recipientCloudId] = answer
		expect(await save({ opened: await openedX() })).toEqual({ scope: 'source', suspended: [BOB.recipientCloudId] })
		expect(lastFinal().federated).toEqual([])
		expect(lastFinal().suspend).toEqual([{ shareId: BOB.shareId, reason }])
	})

	it('verifies against the share\'s cloud id, not the one the lookup echoes back', async () => {
		world.lookups[BOB.recipientCloudId] = () => json({ cloudId: 'mallory@cloud.partner.example', certificate: F.bob, chain: [F.intermediateA, F.rootA], partnerRootFingerprint: F.rootAFingerprint })
		expect(await save({ opened: await openedX() })).toBeUndefined()
		expect(lastFinal().federated).toHaveLength(1)
	})

	it('stops on an unreachable partner, naming it, and sends nothing more', async () => {
		world.lookups[BOB.recipientCloudId] = () => json({ message: 'partner_unreachable' }, 502)
		expect(await failure(save({ opened: await openedX() }))).toEqual({ code: 'partner_unreachable', partners: [{ shareId: BOB.shareId, cloudId: BOB.recipientCloudId }] })
		expect(saves).toHaveLength(1)
	})

	it('suspends an unreachable partner the user saves without, and names it on the note as paused', async () => {
		world.lookups[BOB.recipientCloudId] = () => json({ message: 'partner_unreachable' }, 502)
		expect(await save({ opened: await openedX(), withoutUnreachable: new Set([BOB.shareId]) })).toEqual({ scope: 'source', paused: [BOB.recipientCloudId] })
		expect(lastFinal().suspend).toEqual([{ shareId: BOB.shareId, reason: 'partner_unreachable' }])
	})

	it('names a paused and a suspended partner apart', async () => {
		world.shares = [BOB, CAROL]
		world.lookups[BOB.recipientCloudId] = () => json({ message: 'partner_unreachable' }, 502)
		world.lookups[CAROL.recipientCloudId] = () => json({ message: 'unknown_recipient' }, 404)
		expect(await save({ opened: await openedX(), withoutUnreachable: new Set([BOB.shareId]) })).toEqual({ scope: 'source', suspended: [CAROL.recipientCloudId], paused: [BOB.recipientCloudId] })
	})

	it('stops again for a partner that fails only on Save without them, naming every partner it could not reach', async () => {
		world.shares = [BOB, CAROL]
		world.lookups[BOB.recipientCloudId] = () => json({ message: 'partner_unreachable' }, 502)
		world.lookups[CAROL.recipientCloudId] = () => json({ message: 'partner_unreachable' }, 502)
		expect(await failure(save({ opened: await openedX(), withoutUnreachable: new Set([BOB.shareId]) }))).toEqual({
			code: 'partner_unreachable', partners: [{ shareId: BOB.shareId, cloudId: BOB.recipientCloudId }, { shareId: CAROL.shareId, cloudId: CAROL.recipientCloudId }],
		})
		expect(saves).toHaveLength(1)
	})

	it('stops at the lookup limit without waiting or looking up further', async () => {
		world.shares = Array.from({ length: 70 }, (_, i) => ({ shareId: `fs${i}`, recipientCloudId: `user${i}@cloud.partner.example` }))
		let lookups = 0
		for (const share of world.shares) {
			world.lookups[share.recipientCloudId] = () => (++lookups > 60 ? json({ message: 'Too many requests' }, 429) : json({ message: 'unknown_recipient' }, 404))
		}
		expect(await failure(save({ opened: await openedX() }))).toEqual({ code: 'partner_lookup_limit' })
		expect(lookups).toBe(61)
		expect(saves).toHaveLength(1)
	})

	it.each([
		['a 500', () => json({ message: 'Internal error' }, 500), 'server_error'],
		['a Nextcloud too old for partner sharing', () => json({ message: 'federation_unavailable' }, 409), 'federation_unavailable'],
		['another 409', () => json({ message: 'Conflict' }, 409), 'server_error'],
		['a verdict reason with another status than its own', () => json({ message: 'not_a_partner' }, 404), 'not_found'],
		['bad_chain with a 500', () => json({ message: 'bad_chain' }, 500), 'server_error'],
		['no answer', () => Promise.reject(new TypeError('Failed to fetch')), 'offline'],
		['a 200 without a certificate', () => json({ cloudId: BOB.recipientCloudId, chain: [F.intermediateA, F.rootA], partnerRootFingerprint: F.rootAFingerprint }), 'server_error'],
		['a 200 with a chain of one', () => json({ cloudId: BOB.recipientCloudId, certificate: F.bob, chain: [F.intermediateA], partnerRootFingerprint: F.rootAFingerprint }), 'server_error'],
		['a 200 with a chain of other than text', () => json({ cloudId: BOB.recipientCloudId, certificate: F.bob, chain: [F.intermediateA, 42], partnerRootFingerprint: F.rootAFingerprint }), 'server_error'],
		['a 200 without a pin', () => json({ cloudId: BOB.recipientCloudId, certificate: F.bob, chain: [F.intermediateA, F.rootA] }), 'server_error'],
		['a 200 with a pin of other than 64 hex digits', () => json({ cloudId: BOB.recipientCloudId, certificate: F.bob, chain: [F.intermediateA, F.rootA], partnerRootFingerprint: `${F.rootAFingerprint}0` }), 'server_error'],
	])('fails on %s from this server and suspends nothing', async (_, answer, code) => {
		world.lookups[BOB.recipientCloudId] = answer
		expect(await failure(save({ opened: await openedX() }))).toEqual({ code })
		expect(saves).toHaveLength(1)
	})

	it.each([
		['is no key', [0x30, 0x03, 0x02, 0x01, 0x01]],
		['is no SEQUENCE', [0x04, 0x00]],
	])('fails with bad_certificate when the verified leaf\'s key %s, sending nothing more', async (_, spki) => {
		vi.mocked(verifyFederatedCertificate).mockResolvedValueOnce({ fingerprint: 'ab'.repeat(32), spki: new Uint8Array(spki) })
		expect(await failure(save({ opened: await openedX() }))).toEqual({ code: 'bad_certificate' })
		expect(saves).toHaveLength(1)
	})

	it('fails with unreadable when what the form opened does not decrypt, before any lookup', async () => {
		expect(await failure(save({ opened: await openedX({ shown: { key: await encrypt('x-password'), login: 'not-ciphertext', additionalFields: null } }) }))).toEqual({ code: 'unreadable' })
		expect(sent()).not.toContain('POST /api/v1/federation/recipient-certificate')
	})

	it('drops a partner share and a suspend the answer no longer requires', async () => {
		world.shares = [BOB, CAROL]
		world.lookups[CAROL.recipientCloudId] = () => json({ message: 'unknown_recipient' }, 404)
		world.onFinal = (attempt) => {
			if (attempt === 2) world.shares = []
		}
		expect(await save({ opened: await openedX() })).toBeUndefined()
		expect(finalCalls().map((body) => [body.federated!.map((entry) => entry.shareId), body.suspend!.map((entry) => entry.shareId)])).toEqual([[[], []], [[BOB.shareId], [CAROL.shareId]], [[], []]])
	})

	it('starts a new stage when the answer drops a partner share already staged', async () => {
		world.shares = Array.from({ length: 102 }, (_, i) => ({ shareId: `fs${i}`, recipientCloudId: `user${i}@cloud.partner.example` }))
		for (const share of world.shares) world.lookups[share.recipientCloudId] = () => json({ message: 'unknown_recipient' }, 404)
		world.onFinal = (attempt) => {
			if (attempt === 2) world.shares = world.shares.slice(1)
		}
		await save({ opened: await openedX() })
		const chunks = saves.filter((body) => body.stage?.final === false)
		expect(chunks.map((chunk) => [chunk.suspend!.length, chunk.stage?.id])).toEqual([[100, undefined], [100, undefined]])
		expect(chunks[1]!.suspend!.map((entry) => entry.shareId)).not.toContain('fs0')
		const [first, second] = saves.filter((body) => body.stage?.final === true)
		expect(second!.stage!.id).not.toBe(first!.stage!.id)
		expect(second!.suspend).toHaveLength(1)
	})

	it('stages more than 100 partner shares like copies', async () => {
		world.shares = Array.from({ length: 101 }, (_, i) => ({ shareId: `fs${i}`, recipientCloudId: `user${i}@cloud.partner.example` }))
		for (const share of world.shares) world.lookups[share.recipientCloudId] = () => json({ message: 'unknown_recipient' }, 404)
		await save({ opened: await openedX() })
		expect(saves.filter((body) => body.stage?.final === false).map((body) => body.suspend!.length)).toEqual([100])
		expect(lastFinal().suspend).toHaveLength(1)
	})

	it('ignores ownerOnly on the owner path', async () => {
		world.shares = []
		world.ownerOnly = true
		expect(await save()).toBeUndefined()
	})
})

describe('verified partner certificates', () => {
	const unreachable = () => json({ message: 'partner_unreachable' }, 502)
	const lookups = () => bodiesOf('POST /api/v1/federation/recipient-certificate').map((body) => body.cloudId)
	const [bob, carol] = [BOB.recipientCloudId, CAROL.recipientCloudId]

	beforeEach(() => {
		world.shares = [BOB, CAROL]
		world.lookups[carol] = unreachable
	})

	/** Bob verifies and Carol doesn't answer, so nothing is saved. */
	async function failOnCarol(accountId = 'a1') {
		expect(await failure(save({ accountId, opened: await openedX() }))).toMatchObject({ code: 'partner_unreachable' })
	}
	const saveWithoutCarol = async () => save({ opened: await openedX(), withoutUnreachable: new Set([CAROL.shareId]) })

	it('uses Bob\'s for Save without them, and looks him up again after the save that used it went through', async () => {
		await failOnCarol()
		expect(await saveWithoutCarol()).toEqual({ scope: 'source', paused: [carol] })
		expect(lookups()).toEqual([bob, carol, carol])
		expect(lastFinal().federated!.map((entry) => entry.shareId)).toEqual([BOB.shareId])
		await saveWithoutCarol()
		expect(lookups()).toEqual([bob, carol, carol, bob, carol])
	})

	it('keeps it for 5 minutes from when it was verified', async () => {
		const start = Date.now()
		const clock = vi.spyOn(Date, 'now').mockReturnValue(start)
		await failOnCarol()
		clock.mockReturnValue(start + VERIFIED_PARTNER_TTL_MS - 1)
		await failOnCarol()
		expect(lookups()).toEqual([bob, carol, carol])
		clock.mockReturnValue(start + VERIFIED_PARTNER_TTL_MS)
		await failOnCarol()
		expect(lookups()).toEqual([bob, carol, carol, bob, carol])
	})

	it('looks Bob up again when his share now names another cloud id', async () => {
		await failOnCarol()
		const moved = { ...BOB, recipientCloudId: 'bob@cloud.elsewhere.example' }
		world.shares = [moved, CAROL]
		expect(await saveWithoutCarol()).toEqual({ scope: 'source', suspended: [moved.recipientCloudId], paused: [carol] })
		expect(lookups()).toEqual([bob, carol, moved.recipientCloudId, carol])
	})

	it('keeps them per account', async () => {
		await failOnCarol('a2')
		await saveWithoutCarol()
		expect(lookups()).toEqual([bob, carol, bob, carol])
	})

	it('forgets them all on a lock', async () => {
		await failOnCarol()
		await clearKey('a1')
		await saveWithoutCarol()
		expect(lookups()).toEqual([bob, carol, bob, carol])
	})

	it('keeps nothing from a save a lock ran across', async () => {
		world.lookups[carol] = async () => {
			await clearKey('a1')
			return unreachable()
		}
		await failOnCarol()
		world.lookups[carol] = unreachable
		await saveWithoutCarol()
		expect(lookups()).toEqual([bob, carol, bob, carol])
	})
})

describe('failures', () => {
	it.each([
		['a newer version', json({ error: 'stale', message: 'Stale', current: secretRow() }, 409), { code: 'conflict' }],
		['its own refusal', json({ error: 'not_allowed', message: 'Only the owner…' }, 428), { code: 'forbidden' }],
		['a bare refusal', json({ error: 'forbidden', message: 'A use-only copy cannot be changed' }, 428), { code: 'forbidden' }],
		['a policy', json({ error: 'org_ownership_required', code: 'org_ownership_required', message: 'Policy' }, 428), { code: 'policy' }],
		['a write lock', json({ error: 'write_locked', message: 'Locked' }, 423), { code: 'write_locked' }],
		['a 400', json({ error: 'copy_fields_mismatch' }, 400), { code: 'invalid' }],
	])('maps %s through writeError', async (_, answer, error) => {
		world.onFinal = () => answer
		expect(await failure(save())).toEqual(error)
	})

	it('fails as offline when the server stops answering after the first incomplete answer', async () => {
		world.copies = [recipient('c-bob', 'bob')]
		let answered = 0
		fetchMock.mockImplementation(async (input, init) => {
			const path = new URL(String(input)).pathname.replace('/index.php/apps/keepiq', '')
			if (path.endsWith('/share-save') && ++answered > 1) throw new TypeError('Failed to fetch')
			return route(init?.method ?? 'GET', path, init?.body ? JSON.parse(String(init.body)) : undefined)
		})
		expect(await failure(save())).toEqual({ code: 'offline' })
		expect(answered).toBe(2)
	})
})
