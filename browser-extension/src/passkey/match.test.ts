import { beforeAll, beforeEach, describe, expect, it } from 'vitest'
import { fakeBrowser } from 'wxt/testing/fake-browser'
import type { OpenSecretRow } from '@/src/api/types'
import { importPrivateKey, pemToPkcs8 } from '@/src/crypto'
import { blockedRow, encrypt, secretRow, typeRows } from '@/src/testing/vault'
import { envelope, suiteRow } from '@/src/testing/vectors'
import { clearSnapshot, writeSnapshot } from '@/src/vault/store'
import type { StoredSecretRow } from '@/src/vault/types'
import { credentialIdKey, findPasskeys, listPasskeys, matchPasskeys, offeredFrom } from './match'

const ACCOUNT = 'a1'
let key: CryptoKey

/** A canonical entry as the web app writes it; the private key is never read by matching. */
const passkey = (credentialId: string, overrides: Record<string, unknown> = {}) => ({
	credentialId, rpId: 'example.test', rpName: 'Example', userName: `${credentialId}-user`, userDisplayName: 'Alice', userHandle: 'dXNlcg',
	privateKey: 'cHJpdmF0ZQ', algorithm: -7, counter: 0, transports: ['internal'], createdAt: '2026-01-01T00:00:00.000Z', ...overrides,
})

async function login(id: string, passkeys: unknown[] | string | null, overrides: Partial<OpenSecretRow> = {}): Promise<OpenSecretRow> {
	const value = passkeys === null || passkeys === '' ? passkeys : await encrypt(typeof passkeys === 'string' ? passkeys : JSON.stringify(passkeys))
	return secretRow({ id, name: id[0]!.toUpperCase() + id.slice(1), typeId: 't-login', url: 'https://example.test/', passkeys: value, ...overrides })
}

async function vault(secrets: StoredSecretRow[]) {
	const syncedAt = new Date().toISOString()
	await writeSnapshot(ACCOUNT, {
		suite: { id: suiteRow.id, unlockKeyEpoch: 1 }, secrets, folders: [], types: typeRows, syncedAt, listsSyncedAt: syncedAt, newestUpdatedAt: null, total: secrets.length,
	})
}

const query = (allowCredentials: string[] = []) => ({ rpId: 'example.test', allowCredentials })

beforeAll(async () => {
	key = await importPrivateKey(pemToPkcs8(envelope.privateKeyPem))
})

beforeEach(async () => {
	fakeBrowser.reset()
	await clearSnapshot(ACCOUNT)
})

describe('matchPasskeys', () => {
	it('lists two passkeys on one login, and only the allowed one under an allow list', async () => {
		await vault([await login('work', [passkey('QQ'), passkey('Qg')])])
		expect(await matchPasskeys(ACCOUNT, key, query())).toEqual([
			{ itemId: 'work', credentialId: 'QQ', name: 'Work', userName: 'QQ-user' },
			{ itemId: 'work', credentialId: 'Qg', name: 'Work', userName: 'Qg-user' },
		])
		expect((await matchPasskeys(ACCOUNT, key, query(['Qg']))).map((row) => row.credentialId)).toEqual(['Qg'])
		expect(await matchPasskeys(ACCOUNT, key, query(['Qw']))).toEqual([])
	})

	it('lists the passkeys of two logins, each under its login’s name', async () => {
		await vault([await login('work', [passkey('QQ')]), await login('personal', [passkey('Qg', { userName: '', userDisplayName: 'Alice P' })])])
		expect(await matchPasskeys(ACCOUNT, key, query())).toEqual([
			{ itemId: 'work', credentialId: 'QQ', name: 'Work', userName: 'QQ-user' },
			{ itemId: 'personal', credentialId: 'Qg', name: 'Personal', userName: 'Alice P' },
		])
	})

	it('goes by the passkey’s rpId, not the login’s edited url', async () => {
		await vault([await login('work', [passkey('QQ'), passkey('Qg', { rpId: 'intranet.test' })], { url: 'https://intranet.test/' })])
		expect((await matchPasskeys(ACCOUNT, key, query())).map((row) => row.credentialId)).toEqual(['QQ'])
	})

	it('skips an unreadable entry and keeps the readable one beside it', async () => {
		await vault([await login('work', [{ rpId: 'example.test' }, 'junk', passkey('QQ'), passkey('Qg', { algorithm: -257 })])])
		expect((await matchPasskeys(ACCOUNT, key, query())).map((row) => row.credentialId)).toEqual(['QQ'])
	})

	it('offers nothing from passkeys null, empty, not decrypting or not an array', async () => {
		const other = await login('other', [passkey('QQ')])
		await vault([
			await login('none', null), await login('empty', ''), await login('object', JSON.stringify(passkey('Qg'))),
			{ ...other, id: 'garbled', passkeys: 'bm90IGNpcGhlcnRleHQ=' }, secretRow({ id: 'absent', typeId: 't-login' }),
		])
		expect(await matchPasskeys(ACCOUNT, key, query())).toEqual([])
	})

	it('lists a use-only copy, since signing is using it', async () => {
		await vault([await login('shared', [passkey('QQ')], { useOnly: true })])
		expect((await matchPasskeys(ACCOUNT, key, query())).map((row) => row.itemId)).toEqual(['shared'])
	})

	it('leaves out a blocked row, a copy whose access ended and a standalone passkey item', async () => {
		const standalone = await login('standalone', [passkey('Qg')], { typeId: 't-passkey', key: await encrypt(JSON.stringify(passkey('Qg'))) })
		await vault([
			blockedRow({ id: 'blocked', typeId: 't-login' }),
			await login('ended', [passkey('Qw')], { accessExpiresAt: '2020-01-01T00:00:00+00:00' }),
			await login('garbled-expiry', [passkey('RA')], { accessExpiresAt: 'soon' }),
			standalone,
			await login('current', [passkey('QQ')], { accessExpiresAt: '2999-01-01T00:00:00+00:00' }),
		])
		expect((await matchPasskeys(ACCOUNT, key, query())).map((row) => row.itemId)).toEqual(['current'])
	})

	it('hands out the login id, credential id, login name and user name only', async () => {
		await vault([await login('work', [passkey('QQ')])])
		const [row] = await matchPasskeys(ACCOUNT, key, query())
		expect(Object.keys(row!).sort()).toEqual(['credentialId', 'itemId', 'name', 'userName'])
		expect(JSON.stringify(row)).not.toContain('cHJpdmF0ZQ')
	})

	it('matches an id written with padding or the base64 alphabet', async () => {
		await vault([await login('work', [passkey('+/8=')])])
		expect(await matchPasskeys(ACCOUNT, key, query(['-_8']))).toEqual([{ itemId: 'work', credentialId: '-_8', name: 'Work', userName: '+/8=-user' }])
	})

	it('offers nothing without a cached vault, or for another RP ID', async () => {
		expect(await matchPasskeys(ACCOUNT, key, query())).toEqual([])
		await vault([await login('work', [passkey('QQ')])])
		expect(await matchPasskeys(ACCOUNT, key, { rpId: 'other.test', allowCredentials: [] })).toEqual([])
	})
})

describe('findPasskeys', () => {
	it('decrypts only the named login and keeps the entry’s stored position', async () => {
		await vault([await login('work', ['junk', passkey('QQ')]), await login('personal', [passkey('Qg')])])
		const [entry] = await findPasskeys(ACCOUNT, key, { ...query(), itemId: 'work' })
		expect(entry).toMatchObject({ row: { id: 'work' }, index: 1, credential: { credentialId: 'QQ', privateKey: 'cHJpdmF0ZQ' } })
		expect(await findPasskeys(ACCOUNT, key, { ...query(), itemId: 'missing' })).toEqual([])
	})
})

describe('listPasskeys and offeredFrom', () => {
	it('offer what matchPasskeys finds, for any RP ID and allow list', async () => {
		await vault([
			await login('work', [passkey('QQ'), passkey('+/8='), passkey('Qg', { rpId: 'intranet.test' }), passkey('RA', { algorithm: -257 })]),
			await login('ended', [passkey('Qw')], { accessExpiresAt: '2020-01-01T00:00:00+00:00' }),
			blockedRow({ id: 'blocked', typeId: 't-login' }),
		])
		const listing = await listPasskeys(ACCOUNT, key)
		for (const asked of [query(), query(['-_8']), query(['Qg']), { rpId: 'intranet.test', allowCredentials: [] }, { rpId: 'other.test', allowCredentials: [] }]) {
			expect(offeredFrom(listing, asked)).toEqual(await matchPasskeys(ACCOUNT, key, asked))
		}
	})

	it('keeps ids, names and the RP ID, never a key', async () => {
		await vault([await login('work', [passkey('QQ')])])
		const listing = await listPasskeys(ACCOUNT, key)
		expect(listing).toEqual([{ itemId: 'work', credentialId: 'QQ', name: 'Work', userName: 'QQ-user', rpId: 'example.test', accessExpiresAt: null }])
		expect(JSON.stringify(listing)).not.toContain('cHJpdmF0ZQ')
	})

	it('drops a copy whose access ended after it was listed', async () => {
		await vault([await login('shared', [passkey('QQ')], { accessExpiresAt: '2030-01-01T00:00:00+00:00' })])
		const listing = await listPasskeys(ACCOUNT, key, Date.parse('2029-01-01T00:00:00Z'))
		expect(offeredFrom(listing, query(), Date.parse('2029-06-01T00:00:00Z'))).toHaveLength(1)
		expect(offeredFrom(listing, query(), Date.parse('2030-06-01T00:00:00Z'))).toEqual([])
	})
})

describe('credentialIdKey', () => {
	it('reads base64url and base64 alike, and refuses what is neither', () => {
		expect(credentialIdKey('-_8')).toBe('-_8')
		expect(credentialIdKey('+/8=')).toBe('-_8')
		expect(credentialIdKey('not base64!')).toBeNull()
	})
})
