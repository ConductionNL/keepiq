/**
 * What a paired, synced and unlocked account leaves in storage (ADR-002), faked: the account points at
 * `keepiq.invalid`, which is never contacted, and its one login is encrypted to the web app's test-vector key.
 */
import type { AccountRecord } from '@/src/accounts/store'
import { pemToPkcs8, toBase64 } from '@/src/crypto'
import type { PasskeyCredential } from '@/src/passkey/credential'
import { encrypt, folderRow, secretRow, typeRows } from '@/src/testing/vault'
import { envelope, suiteRow } from '@/src/testing/vectors'
import type { VaultSnapshot } from '@/src/vault/types'

export const LOGIN = {
	username: 'test-user',
	password:
		'test-password',
}
/** Every seeded card's `key`; the PIN is there to show it is never filled. */
export const CARD = { number: '4111 1111 1111 1111', expiry: '03/27', cvv: '123', pin: '0000', cardholder: 'Anna de Vries' }
/** Every seeded identity's `key`; the BSN is there to show it is never filled. */
export const IDENTITY = {
	firstName: 'Anna', lastName: 'de Vries', address: 'Kerkstraat 1\n1234 AB Utrecht', phone: '0600000000', email: 'anna@example.test', bsn: '111222333',
}
/** The key `test-site/totp.js` checks codes against. */
export const TOTP_SECRET =
	'JBSWY3DPEHPK3PXP'

export interface FakeStorage {
	local: Record<string, unknown>
	session: Record<string, string>
}

/** Three nested folders, so the bar's folder choice shows the long path "Werk / Klanten / Gemeente Amsterdam Stadsdeel Nieuw-West". */
export const LONG_FOLDER = ['Werk', 'Klanten', 'Gemeente Amsterdam Stadsdeel Nieuw-West']

/**
 * The seeded login's name and username, for the update card; a `null` username saves the login without one. `https`:
 * its URL on `https:`, so the http test site asks before filling it.
 */
export interface LoginItem {
	name?: string
	username?: string | null
	https?: boolean
}

/** `count` items of the system `type`, named `names` in turn and then numbered, all with the same `key`. */
async function walletRows(type: 'card' | 'identity', count: number, names: string[], payload: object, base: Parameters<typeof secretRow>[0]) {
	const key = await encrypt(JSON.stringify(payload))
	return Array.from({ length: count }, (_, i) => secretRow({ ...base, id: `${type}${i + 1}`, name: names[i] ?? `${names[0]} ${i + 1}`, url: null, typeId: `t-${type}`, key }))
}

/**
 * The login matches `site`; `matches` adds more matching logins after it; `totp` gives the first one `TOTP_SECRET` as
 * its code and `passkeys` as its passkeys; `cards` and `identities` add that many of `CARD` and `IDENTITY`. Locked leaves out the private key, as a lock does.
 */
export async function fakeStorage(
	site: string, unlocked: boolean,
	{ longFolder = false, matches = 1, loginItem = {} as LoginItem, totp = false, passkeys = [] as PasskeyCredential[], cards = 0, identities = 0 } = {},
): Promise<FakeStorage> {
	const { name = 'Test site', username = LOGIN.username, https = false } = loginItem
	const now = new Date().toISOString()
	const account: AccountRecord = {
		id: 'e2e', serverUrl: 'https://keepiq.invalid', uid: 'tester', loginName: 'tester', displayName: 'Tester',
		email: null, avatarDataUrl: null, keepiqVersion: null,
		appPassword:
			'not-an-app-password',
	}
	const owned = { ownerId: account.uid, createdAt: now, updatedAt: now }
	const secrets = [
		secretRow({
			name, url: https ? site.replace(/^http:/, 'https:') : site, ...owned,
			login: username === null ? null : await encrypt(username), key: await encrypt(LOGIN.password),
			...totp && { totp: await encrypt(TOTP_SECRET) },
			...passkeys.length > 0 && { passkeys: await encrypt(JSON.stringify(passkeys)) },
		}),
		...await Promise.all(Array.from({ length: matches - 1 }, async (_, i) => secretRow({
			id: `s${i + 2}`, name: `Test site ${i + 2}`, url: site, ...owned,
			login: await encrypt(`user-${i + 2}`), key: await encrypt(LOGIN.password),
		}))),
		...await walletRows('card', cards, ['Personal', 'Work Visa'], CARD, owned),
		...await walletRows('identity', identities, ['Anna'], IDENTITY, owned),
	]
	const snapshot: VaultSnapshot = {
		suite: { id: suiteRow.id, unlockKeyEpoch: suiteRow.unlockKeyEpoch },
		secrets,
		folders: longFolder
			? LONG_FOLDER.map((name, i) => folderRow({ id: `f${i}`, name, parentId: i === 0 ? null : `f${i - 1}`, ownerId: account.uid, createdAt: now, updatedAt: now }))
			: [],
		types: typeRows, syncedAt: now, listsSyncedAt: now, newestUpdatedAt: now, total: secrets.length,
	}
	// Keys as `src/accounts/store.ts`, `src/vault/store.ts` and `src/vault/key-store.ts` write them.
	return {
		local: {
			accounts: { [account.id]: account }, activeAccountId: account.id, [`suite.${account.id}`]: suiteRow,
			[`vaultCache.${account.id}`]: snapshot, [`vaultCheckedAt.${account.id}`]: now,
		},
		session: unlocked
			? { [`privateKeyPkcs8.${account.id}`]: toBase64(pemToPkcs8(envelope.privateKeyPem)), [`unlockedAt.${account.id}`]: String(Date.now()) }
			: {},
	}
}
