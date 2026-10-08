import { createClient, KeepiqNotInstalled, Offline, SessionRevoked } from '@/src/api/client'
import type { CachedSuite } from '@/src/api/types'
import { decryptPrivateKeyPem, importPrivateKey, InvalidMasterPassword, pemToPkcs8, toBase64 } from '@/src/crypto'
import { activeSuite } from '@/src/accounts/verify'
import { announceLock, getAccount, getActiveAccountId, listAccounts, markLoggedOut, suiteKey, updateAccount, type AccountRecord } from '@/src/accounts/store'
import { Failure } from '@/src/failure'
import type { UnlockMethod } from '@/src/messages'
import { clearKey, putKey } from './key-store'
import { clearSnapshot } from './store'

async function cachedSuite(accountId: string): Promise<CachedSuite | undefined> {
	return (await browser.storage.local.get(suiteKey(accountId)))[suiteKey(accountId)] as CachedSuite | undefined
}

async function fetchSuite(account: AccountRecord): Promise<{ suite: CachedSuite; changed: boolean }> {
	try {
		const suite = activeSuite(await createClient(account).listSuites())
		return { suite, changed: await checkSuite(account.id, suite) }
	} catch (error) {
		if (error instanceof Failure) throw error
		if (error instanceof Offline) throw new Failure('offline_no_cache')
		if (error instanceof SessionRevoked) throw new Failure('session_revoked')
		if (error instanceof KeepiqNotInstalled) throw new Failure('keepiq_missing')
		throw new Failure('unknown')
	}
}

async function decrypt(suite: CachedSuite, method: UnlockMethod): Promise<string> {
	try {
		return await decryptPrivateKeyPem(suite.privateKey, method.masterPassword)
	} catch (error) {
		if (error instanceof InvalidMasterPassword) throw new Failure('invalid_master_password')
		throw error
	}
}

const sameSuite = (a: Pick<CachedSuite, 'id' | 'unlockKeyEpoch'>, b: Pick<CachedSuite, 'id' | 'unlockKeyEpoch'>) =>
	a.id === b.id && a.unlockKeyEpoch === b.unlockKeyEpoch

/** The one entry point for every unlock method; PIN unlock joins `UnlockMethod` later. */
export function unlock(accountId: string, method: UnlockMethod): Promise<void> {
	return attempt(accountId, method, false)
}

async function attempt(accountId: string, method: UnlockMethod, retried: boolean): Promise<void> {
	const account = await getAccount(accountId)
	if (!account) throw new Failure('unknown', 'Unknown account')
	if (account.appPassword === null) throw new Failure('session_revoked')
	// The suite is cached at add time; it is fetched only when missing or when it no longer opens.
	const cached = await cachedSuite(accountId)
	let suite = cached ?? (await fetchSuite(account)).suite
	let pem: string
	try {
		pem = await decrypt(suite, method)
	} catch (error) {
		if (!cached || !(error instanceof Failure) || error.code !== 'invalid_master_password') throw error
		// The master password may have changed in the web app, leaving the cached suite stale.
		// These name the real cause; any other failure leaves the wrong password as the likeliest one.
		const fresh = await fetchSuite(account).catch((failure: Failure) => {
			throw ['session_revoked', 'unlock_blocked', 'no_active_suite'].includes(failure.code) ? failure : error
		})
		if (!fresh.changed) throw error
		suite = fresh.suite
		pem = await decrypt(suite, method)
	}
	const pkcs8 = pemToPkcs8(pem)
	// Imported once here so a corrupt key fails the unlock, not the first decrypt.
	await importPrivateKey(pkcs8)
	// Cleared before the key is stored, so a suite change landing after this keeps its notice.
	if ((await getAccount(accountId))?.keyChanged) await updateAccount(accountId, { keyChanged: false })
	await putKey(accountId, toBase64(pkcs8))
	// A logout during the unlock marked the account before purging; take back what this unlock wrote.
	const after = await getAccount(accountId)
	if (typeof after?.appPassword !== 'string') {
		await clearKey(accountId)
		await browser.storage.local.remove(suiteKey(accountId))
		throw new Failure('session_revoked')
	}
	// A sync stored a new suite while this unlock decrypted the old one, so this key opens nothing.
	// A missing suite means the two-factor block dropped it; the retry asks the server, which refuses.
	const current = await cachedSuite(accountId)
	if (!current || !sameSuite(current, suite)) {
		await clearKey(accountId)
		if (current) await updateAccount(accountId, { keyChanged: true })
		if (retried) throw new Failure('unknown')
		return attempt(accountId, method, true)
	}
}

export async function lock(accountId: string): Promise<void> {
	await clearKey(accountId)
	await announceLock((await getActiveAccountId()) === accountId)
}

export async function lockAll(): Promise<void> {
	for (const account of await listAccounts()) await clearKey(account.id)
	await announceLock(true)
}

/** The timeout action "Log out": the identity and settings stay, so only the app password is asked again. */
export async function logoutForTimeout(accountId: string): Promise<void> {
	await markLoggedOut(accountId)
}

/**
 * Caches a freshly fetched active suite. A different id or epoch means the master
 * password or key changed elsewhere, so the key goes and the account locks. Returns whether it changed.
 */
export async function checkSuite(accountId: string, suite: CachedSuite): Promise<boolean> {
	const cached = await cachedSuite(accountId)
	const changed = cached !== undefined && !sameSuite(cached, suite)
	// Stored before the key goes, so an unlock still running sees the new suite (attempt()).
	await browser.storage.local.set({ [suiteKey(accountId)]: suite })
	// The cached rows are encrypted to the old key, so they go with it.
	if (changed) {
		await clearKey(accountId)
		await clearSnapshot(accountId)
		await updateAccount(accountId, { keyChanged: true })
		await announceLock((await getActiveAccountId()) === accountId)
	}
	return changed
}

/**
 * The two-factor policy now withholds the key. What this device holds goes too, as the
 * web app drops its snapshot, so the next unlock asks the server and is refused.
 */
export async function blockUnlock(accountId: string): Promise<void> {
	await clearKey(accountId)
	await clearSnapshot(accountId)
	await browser.storage.local.remove(suiteKey(accountId))
	await announceLock((await getActiveAccountId()) === accountId)
}
