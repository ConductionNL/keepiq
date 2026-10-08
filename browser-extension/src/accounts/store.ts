import type { AccountStatus } from '@/src/messages'
import type { CachedSuite } from '@/src/api/types'
import { broadcast } from '@/src/background/broadcast'
import { clearKey, hasKey, LAST_TAB_KEY, sessionValues, syncNeverLockKey } from '@/src/vault/key-store'
import { clearSnapshot } from '@/src/vault/store'
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
	/** Set when a sync found a new suite, so the unlock screen can say why. */
	keyChanged?: boolean
}

const ACCOUNTS = 'accounts'
const ACTIVE = 'activeAccountId'
export const suiteKey = (accountId: string) => `suite.${accountId}`

export class AccountLimitReached extends Error {}
export class DuplicateAccount extends Error {}

async function readAll(): Promise<Record<string, AccountRecord>> {
	return ((await browser.storage.local.get(ACCOUNTS))[ACCOUNTS] as Record<string, AccountRecord> | undefined) ?? {}
}

/** Every change to the account list queues here, so no write works on a stale read (a sync must not undo a logout). */
let queue: Promise<unknown> = Promise.resolve()

function mutateAccounts<T>(change: (accounts: Record<string, AccountRecord>) => T | Promise<T>): Promise<T> {
	const run = queue.then(async () => {
		const accounts = await readAll()
		const result = await change(accounts)
		if (Object.keys(accounts).length) await browser.storage.local.set({ [ACCOUNTS]: accounts })
		else await browser.storage.local.remove(ACCOUNTS)
		return result
	})
	queue = run.catch(() => {})
	return run
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
function assertCanAdd(accounts: Record<string, AccountRecord>, serverUrl: string, uid: string): void {
	const all = Object.values(accounts)
	if (all.some((a) => a.serverUrl === serverUrl && a.uid === uid)) throw new DuplicateAccount()
	if (all.length >= MAX_ACCOUNTS) throw new AccountLimitReached()
}

export async function addAccount(fields: Omit<AccountRecord, 'id'>, suite: CachedSuite): Promise<AccountRecord> {
	// Written inside the queued change, so a "log out all" queued after it purges all of it.
	const record = await mutateAccounts(async (accounts) => {
		assertCanAdd(accounts, fields.serverUrl, fields.uid)
		const added: AccountRecord = { id: crypto.randomUUID(), ...fields }
		await writeSettings(added.id, DEFAULT_SETTINGS)
		await browser.storage.local.set({ [suiteKey(added.id)]: suite })
		accounts[added.id] = added
		return added
	})
	// Only once the list holds it, or getActiveAccountId() would heal the pointer away.
	await browser.storage.local.set({ [ACTIVE]: record.id })
	return record
}

export async function updateAccount(accountId: string, patch: Partial<Omit<AccountRecord, 'id'>>): Promise<void> {
	await mutateAccounts((accounts) => {
		if (accounts[accountId]) accounts[accountId] = { ...accounts[accountId], ...patch }
	})
}

/** Changing settings moves the "Never" copy of the key on or off disk with them. */
export async function updateSettings(accountId: string, settings: AccountSettings): Promise<void> {
	await writeSettings(accountId, settings)
	await syncNeverLockKey(accountId)
}

/**
 * Call after a lock or logout has finished writing, so the popup re-reads a final state.
 * Only the active account's lock resets the popup's remembered tab.
 */
export async function announceLock(wasActive: boolean): Promise<void> {
	if (wasActive) await sessionValues.remove([LAST_TAB_KEY])
	broadcast({ kind: 'vault.locked' })
}

async function isActive(accountId: string): Promise<boolean> {
	return (await getActiveAccountId()) === accountId
}

/**
 * 401 or the timeout action "Log out": purge credentials and caches, keep identity and settings.
 * The account is marked first so a sync finishing meanwhile sees the logout (sync.ts).
 */
export async function markLoggedOut(accountId: string, revoked = false): Promise<void> {
	await updateAccount(accountId, { appPassword: null, revoked })
	await clearKey(accountId)
	await clearSnapshot(accountId)
	await browser.storage.local.remove(suiteKey(accountId))
	await announceLock(await isActive(accountId))
}

async function purge(accountId: string): Promise<void> {
	await clearKey(accountId)
	await clearSnapshot(accountId)
	await browser.storage.local.remove([suiteKey(accountId), settingsKey(accountId)])
}

/** Manual "Log out": the account is gone. The host permission is kept (another account may share it). */
export async function removeAccount(accountId: string): Promise<void> {
	const wasActive = await isActive(accountId)
	// Gone from the list first, like markLoggedOut, then purged.
	await mutateAccounts((accounts) => {
		delete accounts[accountId]
	})
	await purge(accountId)
	await getActiveAccountId()
	await announceLock(wasActive)
}

/** One queued change, so an account added meanwhile is either kept whole or removed whole. */
export async function removeAllAccounts(): Promise<void> {
	const removed = await mutateAccounts((accounts) => {
		const ids = Object.keys(accounts)
		for (const id of ids) delete accounts[id]
		return ids
	})
	for (const id of removed) await purge(id)
	await getActiveAccountId()
	await announceLock(true)
}

export async function accountStatus(account: AccountRecord): Promise<AccountStatus> {
	if (account.appPassword === null) return 'logged_out'
	return (await hasKey(account.id)) ? 'unlocked' : 'locked'
}
