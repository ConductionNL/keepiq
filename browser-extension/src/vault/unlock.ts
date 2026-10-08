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

async function fetchSuite(account: AccountRecord): Promise<{ suite: CachedSuite; changed: boolean }> {
	try {
		const suite = activeSuite(await createClient(account).listSuites())
		return { suite, changed: await checkSuite(account.id, suite) }
	} catch (error) {
		if (error instanceof Failure) throw error
		if (error instanceof Offline) throw new Failure('offline_no_cache', 'You are offline and this vault has not been synced yet')
		if (error instanceof SessionRevoked) throw new Failure('session_revoked', error.message)
		if (error instanceof KeepiqNotInstalled) throw new Failure('keepiq_missing')
		throw new Failure('unknown')
	}
}

async function decrypt(suite: CachedSuite, method: UnlockMethod): Promise<string> {
	try {
		return await decryptPrivateKeyPem(suite.privateKey, method.masterPassword)
	} catch (error) {
		if (error instanceof InvalidMasterPassword) throw new Failure('invalid_master_password', 'Invalid master password')
		throw error
	}
}

/** The one entry point for every unlock method; PIN unlock joins `UnlockMethod` later. */
export async function unlock(accountId: string, method: UnlockMethod): Promise<void> {
	const account = await getAccount(accountId)
	if (!account) throw new Failure('unknown', 'Unknown account')
	if (account.appPassword === null) throw new Failure('session_revoked', 'Log in again first')
	// The suite is cached at add time; it is fetched only when missing or when it no longer opens.
	const cached = await cachedSuite(accountId)
	let pem: string
	try {
		pem = await decrypt(cached ?? (await fetchSuite(account)).suite, method)
	} catch (error) {
		if (!cached || !(error instanceof Failure) || error.code !== 'invalid_master_password') throw error
		// The master password may have changed in the web app, leaving the cached suite stale.
		// These name the real cause; any other failure leaves the wrong password as the likeliest one.
		const fresh = await fetchSuite(account).catch((failure: Failure) => {
			throw ['session_revoked', 'unlock_blocked', 'no_active_suite'].includes(failure.code) ? failure : error
		})
		if (!fresh.changed) throw error
		pem = await decrypt(fresh.suite, method)
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
