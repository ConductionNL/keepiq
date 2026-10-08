import { createClient, KeepiqNotInstalled, Offline, SessionRevoked } from '@/src/api/client'
import type { CachedSuite } from '@/src/api/types'
import { decryptPrivateKeyPem, importPrivateKey, InvalidMasterPassword, pemToPkcs8, toBase64 } from '@/src/crypto'
import { activeSuite } from '@/src/accounts/verify'
import { getAccount, listAccounts, markLoggedOut, suiteKey, type AccountRecord } from '@/src/accounts/store'
import { Failure } from '@/src/failure'
import type { UnlockMethod } from '@/src/messages'
import { clearKey, putKey } from './key-store'

async function cachedSuite(accountId: string): Promise<CachedSuite | undefined> {
	return (await browser.storage.local.get(suiteKey(accountId)))[suiteKey(accountId)] as CachedSuite | undefined
}

async function fetchSuite(account: AccountRecord): Promise<CachedSuite> {
	try {
		const suite = activeSuite(await createClient(account).listSuites())
		await checkSuite(account.id, suite)
		return suite
	} catch (error) {
		if (error instanceof Failure) throw error
		if (error instanceof Offline) throw new Failure('offline_no_cache', 'You are offline and this vault has not been synced yet')
		if (error instanceof SessionRevoked) throw new Failure('session_revoked', error.message)
		if (error instanceof KeepiqNotInstalled) throw new Failure('keepiq_missing')
		throw new Failure('unknown')
	}
}

/** The one entry point for every unlock method; PIN unlock joins `UnlockMethod` later. */
export async function unlock(accountId: string, method: UnlockMethod): Promise<void> {
	const account = await getAccount(accountId)
	if (!account) throw new Failure('unknown', 'Unknown account')
	if (account.appPassword === null) throw new Failure('session_revoked', 'Log in again first')
	// The suite is cached at add time, so this request only runs after a 401 or a suite change.
	const suite = await cachedSuite(accountId) ?? await fetchSuite(account)
	let pem: string
	try {
		pem = await decryptPrivateKeyPem(suite.privateKey, method.masterPassword)
	} catch (error) {
		if (error instanceof InvalidMasterPassword) throw new Failure('invalid_master_password', 'Invalid master password')
		throw error
	}
	const pkcs8 = pemToPkcs8(pem)
	// Imported once here so a corrupt key fails the unlock, not the first decrypt.
	await importPrivateKey(pkcs8)
	await putKey(accountId, toBase64(pkcs8))
}

export async function lock(accountId: string): Promise<void> {
	await clearKey(accountId)
}

export async function lockAll(): Promise<void> {
	for (const account of await listAccounts()) await clearKey(account.id)
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
	const changed = cached !== undefined && (cached.id !== suite.id || cached.unlockKeyEpoch !== suite.unlockKeyEpoch)
	if (changed) await clearKey(accountId)
	await browser.storage.local.set({ [suiteKey(accountId)]: suite })
	return changed
}
