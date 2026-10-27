/**
 * What a paired, synced and unlocked account leaves in storage (ADR-002), faked: the account points at
 * `keepiq.invalid`, which is never contacted, and its one login is encrypted to the web app's test-vector key.
 */
import type { AccountRecord } from '@/src/accounts/store'
import { pemToPkcs8, toBase64 } from '@/src/crypto'
import { encrypt, folderRow, secretRow, typeRows } from '@/src/testing/vault'
import { envelope, suiteRow } from '@/src/testing/vectors'
import type { VaultSnapshot } from '@/src/vault/types'

export const LOGIN = { username: 'test-user', password: 'test-password' }

export interface FakeStorage {
	local: Record<string, unknown>
	session: Record<string, string>
}

/** Three nested folders, so the bar's folder choice shows the long path "Werk / Klanten / Gemeente Amsterdam Stadsdeel Nieuw-West". */
export const LONG_FOLDER = ['Werk', 'Klanten', 'Gemeente Amsterdam Stadsdeel Nieuw-West']

/** The login matches `site`; `matches` adds more matching logins after it. Locked leaves out the private key, as a lock does. */
export async function fakeStorage(site: string, unlocked: boolean, { longFolder = false, matches = 1 } = {}): Promise<FakeStorage> {
	const now = new Date().toISOString()
	const account: AccountRecord = {
		id: 'e2e', serverUrl: 'https://keepiq.invalid', uid: 'tester', loginName: 'tester', displayName: 'Tester',
		email: null, avatarDataUrl: null, appPassword: 'not-an-app-password', keepiqVersion: null,
	}
	const snapshot: VaultSnapshot = {
		suite: { id: suiteRow.id, unlockKeyEpoch: suiteRow.unlockKeyEpoch },
		secrets: [
			secretRow({
				name: 'Test site', url: site, ownerId: account.uid, createdAt: now, updatedAt: now,
				login: await encrypt(LOGIN.username), key: await encrypt(LOGIN.password),
			}),
			...await Promise.all(Array.from({ length: matches - 1 }, async (_, i) => secretRow({
				id: `s${i + 2}`, name: `Test site ${i + 2}`, url: site, ownerId: account.uid, createdAt: now, updatedAt: now,
				login: await encrypt(`user-${i + 2}`), key: await encrypt(LOGIN.password),
			}))),
		],
		folders: longFolder
			? LONG_FOLDER.map((name, i) => folderRow({ id: `f${i}`, name, parentId: i === 0 ? null : `f${i - 1}`, ownerId: account.uid, createdAt: now, updatedAt: now }))
			: [],
		types: typeRows, syncedAt: now, listsSyncedAt: now, newestUpdatedAt: now, total: matches,
	}
	// Keys as `src/accounts/store.ts`, `src/vault/store.ts` and `src/vault/key-store.ts` write them.
	return {
		local: { accounts: { [account.id]: account }, activeAccountId: account.id, [`vaultCache.${account.id}`]: snapshot, [`vaultCheckedAt.${account.id}`]: now },
		session: unlocked
			? { [`privateKeyPkcs8.${account.id}`]: toBase64(pemToPkcs8(envelope.privateKeyPem)), [`unlockedAt.${account.id}`]: String(Date.now()) }
			: {},
	}
}
