import { beforeAll, beforeEach, describe, expect, it } from 'vitest'
import { fakeBrowser } from 'wxt/testing/fake-browser'
import { addAccount } from '@/src/accounts/store'
import { importPrivateKey, pemToPkcs8 } from '@/src/crypto'
import { lastUsedKey } from '@/src/last-used'
import { blockedRow, encrypt, secretRow, typeRows } from '@/src/testing/vault'
import { envelope, suiteRow } from '@/src/testing/vectors'
import { writeSnapshot } from '@/src/vault/store'
import type { StoredSecretRow } from '@/src/vault/types'
import { currentCode, hasCode, inlineCode, matchingCodes } from './login-codes'

/** RFC 6238's SHA-1 key; at 59 s its six-digit code is `287082`. */
const SEED = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'
let key: CryptoKey
let accountId: string

beforeAll(async () => {
	key = await importPrivateKey(pemToPkcs8(envelope.privateKeyPem))
})
beforeEach(async () => {
	fakeBrowser.reset()
	accountId = (await addAccount({
		serverUrl: 'https://cloud.example.org', uid: 'alice', loginName: 'alice', displayName: 'Alice', email: null, avatarDataUrl: null, appPassword: 'pw', keepiqVersion: '0.3.12',
	}, { ...suiteRow })).id
})

async function vault(secrets: StoredSecretRow[]) {
	const syncedAt = new Date().toISOString()
	await writeSnapshot(accountId, { suite: { id: suiteRow.id, unlockKeyEpoch: 1 }, secrets, folders: [], types: typeRows, syncedAt, listsSyncedAt: syncedAt, newestUpdatedAt: null, total: secrets.length })
}

describe('matchingCodes', () => {
	it('lists the matching logins with a code, last used first, use-only copies included', async () => {
		await vault([
			secretRow({ id: 'a', name: 'A', url: 'https://example.test', totp: await encrypt(SEED) }),
			secretRow({ id: 'b', name: 'B', url: 'https://example.test', totp: await encrypt(SEED), useOnly: true }),
			secretRow({ id: 'none', name: 'None', url: 'https://example.test', totp: '' }),
			secretRow({ id: 'absent', name: 'Absent', url: 'https://example.test' }),
			secretRow({ id: 'other', name: 'Other', url: 'https://other.test', totp: await encrypt(SEED) }),
			secretRow({ id: 'standalone', name: 'Standalone', url: 'https://example.test', typeId: 't-totp', key: await encrypt(SEED), totp: await encrypt(SEED) }),
			blockedRow({ id: 'blocked', url: 'https://example.test' }),
		])
		await browser.storage.local.set({ [lastUsedKey(accountId)]: { b: 5 } })
		expect((await matchingCodes(accountId, 'https://example.test/2fa')).rows.map((row) => row.id)).toEqual(['b', 'a'])
	})

	it('lists nothing without a snapshot or a page', async () => {
		expect((await matchingCodes(accountId, 'https://example.test/')).rows).toEqual([])
		await vault([secretRow({ url: 'https://example.test', totp: await encrypt(SEED) })])
		expect((await matchingCodes(accountId, undefined)).rows).toEqual([])
	})
})

describe('hasCode', () => {
	it('reads null, an empty string and a missing field as no code', () => {
		expect([hasCode({ totp: 'ciphertext' }), hasCode({ totp: '' }), hasCode({ totp: null }), hasCode({})]).toEqual([true, false, false, false])
	})
})

describe('currentCode', () => {
	it('gives the code with its period and end', async () => {
		expect(await currentCode(SEED, 59_000)).toEqual({ digits: '287082', period: 30, validUntil: 60_000 })
		expect(await currentCode(`otpauth://totp/x?secret=${SEED}&digits=8&period=60`, 61_000)).toEqual({ digits: '94287082', period: 60, validUntil: 120_000 })
	})

	it('gives null for anything but a TOTP seed', async () => {
		expect(await currentCode('not-a-seed')).toBeNull()
		expect(await currentCode(`otpauth://hotp/x?secret=${SEED}`)).toBeNull()
	})
})

describe('inlineCode', () => {
	it('gives the row\'s code, and no digits for an invalid seed or ciphertext that does not open', async () => {
		expect(await inlineCode({ totp: await encrypt(SEED) }, key, 59_000)).toEqual({ digits: '287082', period: 30, validUntil: 60_000 })
		expect(await inlineCode({ totp: await encrypt('not-a-seed') }, key, 59_000)).toEqual({ digits: null, period: 30, validUntil: 60_000 })
		expect(await inlineCode({ totp: 'garbage' }, key, 59_000)).toEqual({ digits: null, period: 30, validUntil: 60_000 })
	})
})
