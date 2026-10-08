import type { AccountStatus } from '@/src/messages'
import type { CachedSuite } from '@/src/api/types'
import { clearKey, hasKey, syncNeverLockKey } from '@/src/vault/key-store'
import { DEFAULT_SETTINGS, settingsKey, writeSettings, type AccountSettings } from './settings'

export const MAX_ACCOUNTS = 5

export interface AccountRecord {
	id: string
	serverUrl: string
	uid: string
	loginName: string
	displayName: string
	email: string | null
	avatarDataUrl: string | null
	/** `null` once logged out by a 401 or the timeout action; the record stays for re-login. */
	appPassword: string | null
	/** Set by a 401 so the re-login screen can say why. */
	revoked?: boolean
}

const ACCOUNTS = 'accounts'
const ACTIVE = 'activeAccountId'
export const suiteKey = (accountId: string) => `suite.${accountId}`
export const vaultCacheKey = (accountId: string) => `vaultCache.${accountId}`

export class AccountLimitReached extends Error {}
export class DuplicateAccount extends Error {}

async function readAll(): Promise<Record<string, AccountRecord>> {
	return ((await browser.storage.local.get(ACCOUNTS))[ACCOUNTS] as Record<string, AccountRecord> | undefined) ?? {}
}

async function writeAll(accounts: Record<string, AccountRecord>): Promise<void> {
	await browser.storage.local.set({ [ACCOUNTS]: accounts })
}

/** In insertion order, which is the order they were added. */
export async function listAccounts(): Promise<AccountRecord[]> {
	return Object.values(await readAll())
}

export async function getAccount(accountId: string): Promise<AccountRecord | undefined> {
	return (await readAll())[accountId]
}

export async function getActiveAccountId(): Promise<string | null> {
	const accounts = await readAll()
	const active = (await browser.storage.local.get(ACTIVE))[ACTIVE] as string | undefined
	if (active && accounts[active]) return active
	// Self-heal a dangling pointer so there is always exactly one active account.
	const fallback = Object.keys(accounts)[0] ?? null
	if (fallback) await browser.storage.local.set({ [ACTIVE]: fallback })
	else if (active !== undefined) await browser.storage.local.remove(ACTIVE)
	return fallback
}

export async function setActive(accountId: string): Promise<void> {
	if (!(await getAccount(accountId))) throw new Error(`Unknown account ${accountId}`)
	await browser.storage.local.set({ [ACTIVE]: accountId })
}

/** Throws `AccountLimitReached` or `DuplicateAccount` before anything is written. */
async function assertCanAdd(serverUrl: string, uid: string): Promise<void> {
	const accounts = Object.values(await readAll())
	if (accounts.some((a) => a.serverUrl === serverUrl && a.uid === uid)) throw new DuplicateAccount()
	if (accounts.length >= MAX_ACCOUNTS) throw new AccountLimitReached()
}

export async function addAccount(fields: Omit<AccountRecord, 'id'>, suite: CachedSuite): Promise<AccountRecord> {
	await assertCanAdd(fields.serverUrl, fields.uid)
	const record: AccountRecord = { id: crypto.randomUUID(), ...fields }
	await writeAll({ ...(await readAll()), [record.id]: record })
	await writeSettings(record.id, DEFAULT_SETTINGS)
	await browser.storage.local.set({ [suiteKey(record.id)]: suite, [ACTIVE]: record.id })
	return record
}

export async function updateAccount(accountId: string, patch: Partial<Omit<AccountRecord, 'id'>>): Promise<void> {
	const accounts = await readAll()
	if (!accounts[accountId]) return
	accounts[accountId] = { ...accounts[accountId], ...patch }
	await writeAll(accounts)
}

/** Changing settings moves the "Never" copy of the key on or off disk with them. */
export async function updateSettings(accountId: string, settings: AccountSettings): Promise<void> {
	await writeSettings(accountId, settings)
	await syncNeverLockKey(accountId)
}

/** 401 or the timeout action "Log out": purge credentials and caches, keep identity and settings. */
export async function markLoggedOut(accountId: string, revoked = false): Promise<void> {
	await clearKey(accountId)
	await browser.storage.local.remove([suiteKey(accountId), vaultCacheKey(accountId)])
	await updateAccount(accountId, { appPassword: null, revoked })
}

/** Manual "Log out": the account is gone. The host permission is kept (another account may share it). */
export async function removeAccount(accountId: string): Promise<void> {
	await clearKey(accountId)
	await browser.storage.local.remove([suiteKey(accountId), vaultCacheKey(accountId), settingsKey(accountId)])
	const accounts = await readAll()
	delete accounts[accountId]
	await writeAll(accounts)
	await getActiveAccountId()
}

export async function removeAllAccounts(): Promise<void> {
	for (const account of await listAccounts()) await removeAccount(account.id)
	await browser.storage.local.remove([ACCOUNTS, ACTIVE])
}

export async function accountStatus(account: AccountRecord): Promise<AccountStatus> {
	if (account.appPassword === null) return 'logged_out'
	return (await hasKey(account.id)) ? 'unlocked' : 'locked'
}
