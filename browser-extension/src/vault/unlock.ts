import { createClient, KeepiqNotInstalled, Offline, SessionRevoked } from '@/src/api/client'
import { supportedKeepiq } from '@/src/api/keepiq-version'
import type { CachedSuite } from '@/src/api/types'
import { decryptPrivateKeyPem, decryptPrivateKeyPemWith, fromBase64, importPrivateKey, InvalidMasterPassword, pemToPkcs8, toBase64 } from '@/src/crypto'
import { activeSuite } from '@/src/accounts/verify'
import {
	accountStatus, announceLock, getAccount, getActiveAccountId, listAccounts, markLoggedOut, suiteKey, updateAccount, type AccountRecord,
} from '@/src/accounts/store'
import { Failure } from '@/src/failure'
import { createKeyedQueue } from '@/src/queue'
import { PIN_MIN_LENGTH, type PinSetReply, type UnlockMethod } from '@/src/messages'
import { proveCertificate, provenCertificateKey } from './certificate'
import { clearKey, putKey, sessionEpoch } from './key-store'
import {
	deleteSeal, forgetPin, openSeal, pinGeneration, readPin, readSeal, sealPin, storePin, storePinCopies, unwrapPin, wrapPin, WrongPin,
} from './pin'
import { clearSnapshot } from './store'

/** Wrong PINs in a row before the PIN is forgotten. */
const PIN_ATTEMPTS = 5

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

async function decrypt(suite: CachedSuite, masterPassword: string): Promise<string> {
	try {
		return await decryptPrivateKeyPem(suite.privateKey, masterPassword)
	} catch (error) {
		if (error instanceof InvalidMasterPassword) throw new Failure('invalid_master_password')
		throw error
	}
}

/** Reads the version again only when the stored one is missing or too old, since the server may have been updated; offline or on a server error, a stored one stands. */
async function checkServer(account: AccountRecord): Promise<void> {
	if (supportedKeepiq(account.keepiqVersion)) return
	let version: string | null
	try {
		version = await createClient(account).fetchVersion()
	} catch (error) {
		if (error instanceof SessionRevoked) throw new Failure('session_revoked')
		if (account.keepiqVersion === undefined) return
		throw new Failure('keepiq_outdated')
	}
	await updateAccount(account.id, { keepiqVersion: version })
	if (!supportedKeepiq(version)) throw new Failure('keepiq_outdated')
}

const sameSuite = (a: Pick<CachedSuite, 'id' | 'unlockKeyEpoch'>, b: Pick<CachedSuite, 'id' | 'unlockKeyEpoch'>) =>
	a.id === b.id && a.unlockKeyEpoch === b.unlockKeyEpoch

/** The one entry point for every unlock method. */
export function unlock(accountId: string, method: UnlockMethod): Promise<void> {
	return attempt(accountId, method, false)
}

/** The suite the master password opens, and its key as PEM. */
async function openWithMasterPassword(account: AccountRecord, masterPassword: string): Promise<{ suite: CachedSuite; key: string }> {
	// The suite is cached at add time; it is fetched only when missing or when it no longer opens.
	const cached = await cachedSuite(account.id)
	const suite = cached ?? (await fetchSuite(account)).suite
	try {
		return { suite, key: await decrypt(suite, masterPassword) }
	} catch (error) {
		if (!cached || !(error instanceof Failure) || error.code !== 'invalid_master_password') throw error
		// The master password may have changed in the web app, leaving the cached suite stale.
		// These name the real cause; any other failure leaves the wrong password as the likeliest one.
		const fresh = await fetchSuite(account).catch((failure: Failure) => {
			throw ['session_revoked', 'unlock_blocked', 'no_active_suite'].includes(failure.code) ? failure : error
		})
		if (!fresh.changed) throw error
		return { suite: fresh.suite, key: await decrypt(fresh.suite, masterPassword) }
	}
}

/** Every read-modify-write of an account's PIN record runs here; a forget is caught by the generation instead. */
const pinQueue = createKeyedQueue()

/** One PIN unlock: the account, the PIN typed, its stored record, and the PIN generation the try read first. */
interface PinTry {
	accountId: string
	pin: string
	record: NonNullable<Awaited<ReturnType<typeof readPin>>>
	generation: number
}

/** The cached suite and its key as PKCS#8. No stale-suite refetch: a failed unwrap is a wrong PIN, and a changed suite forgets the PIN. */
function openWithPin(accountId: string, pin: string): Promise<{ suite: CachedSuite; key: Uint8Array<ArrayBuffer> }> {
	return pinQueue(accountId, async () => {
		const generation = pinGeneration(accountId)
		const record = await readPin(accountId)
		if (!record) throw new Failure('pin_forgotten')
		const pinTry: PinTry = { accountId, pin, record, generation }
		const suite = await pinSuite(pinTry)
		await refuseSpentPin(pinTry)
		const failures = record.failures + 1
		// Counted before the unwrap, so a worker restart mid-attempt still counts it.
		if (!(await storePin(accountId, { ...record, failures }, generation))) throw new Failure('pin_forgotten')
		const key = await unwrapCounted(pinTry, failures)
		await storePin(accountId, { ...record, failures: 0 }, generation)
		return { suite, key }
	})
}

/** The cached suite, when it is still the one the PIN wraps a key of; otherwise the PIN is forgotten. */
async function pinSuite({ accountId, record }: PinTry): Promise<CachedSuite> {
	const suite = await cachedSuite(accountId)
	if (!suite || !sameSuite(suite, { id: record.suiteId, unlockKeyEpoch: record.unlockKeyEpoch })) {
		await forgetPin(accountId)
		throw new Failure('pin_key_changed')
	}
	return suite
}

/** A worker killed during the fifth attempt counted it but never forgot the PIN. */
async function refuseSpentPin({ accountId, record }: PinTry): Promise<void> {
	if (record.failures < PIN_ATTEMPTS) return
	await forgetPin(accountId)
	throw new Failure('pin_forgotten')
}

async function unwrapCounted({ accountId, record, pin }: PinTry, failures: number): Promise<Uint8Array<ArrayBuffer>> {
	try {
		return await unwrapPin(record, pin)
	} catch (error) {
		if (error instanceof WrongPin && failures < PIN_ATTEMPTS) throw new Failure('invalid_pin', 'Wrong PIN', PIN_ATTEMPTS - failures)
		// A record that does not open is as good as gone.
		await forgetPin(accountId)
		throw new Failure('pin_forgotten')
	}
}

/**
 * After a master password unlock: a session copy has its run of wrong PINs ended, and a seal
 * alone (after a restart) rebuilds the session copy. A seal that does not open, or is stale, is deleted.
 * `generation` is the one the unlock read: after a forget since, this writes and deletes nothing.
 */
function afterMasterPassword(accountId: string, pkcs8: Uint8Array<ArrayBuffer>, suite: CachedSuite, generation: number): Promise<void> {
	return pinQueue(accountId, async () => {
		const record = await readPin(accountId)
		if (record) {
			if (record.failures > 0) await storePin(accountId, { ...record, failures: 0 }, generation)
			return
		}
		const seal = await readSeal(accountId)
		if (seal === undefined) return
		const pin = await openSeal(accountId, seal, pkcs8, suite)
		if (pin === null) {
			// Checked in the same tick as the remove, so a seal written after a forget stays.
			if (pinGeneration(accountId) === generation) await deleteSeal(accountId)
			return
		}
		await storePin(accountId, await wrapPin(pin, pkcs8, suite), generation)
	})
}

/** One unlock try: the account, how it unlocks, and whether this is the one retry after a suite change. */
interface UnlockRun {
	accountId: string
	method: UnlockMethod
	retried: boolean
}

/** The private key an unlock opened, imported, with the suite it belongs to. */
interface OpenedKey {
	pkcs8: Uint8Array<ArrayBuffer>
	privateKey: CryptoKey
	suite: CachedSuite
}

async function attempt(accountId: string, method: UnlockMethod, retried: boolean): Promise<void> {
	const run: UnlockRun = { accountId, method, retried }
	const account = await unlockableAccount(accountId, retried)
	const { suite, key } = await openVault(run, account)
	const opened: OpenedKey = { ...await importedKey(key), suite }
	await storeKey(accountId, opened)
	await refuseAfterLogout(accountId)
	// A sync stored a new suite while this unlock decrypted the old one, so this key opens nothing.
	// A missing suite means the two-factor block dropped it, and the certificate this unlock proved goes too; the retry asks the server, which refuses.
	const current = await cachedSuite(accountId)
	if (!current || !sameSuite(current, suite)) return retryAfterSuiteChange(run, current)
	rebuildPinLater(run, opened)
}

async function unlockableAccount(accountId: string, retried: boolean): Promise<AccountRecord> {
	const account = await getAccount(accountId)
	if (!account) throw new Failure('unknown', 'Unknown account')
	if (account.appPassword === null) throw new Failure('session_revoked')
	if (!retried) await checkServer(account)
	return account
}

function openVault({ accountId, method }: UnlockRun, account: AccountRecord): Promise<{ suite: CachedSuite; key: string | Uint8Array<ArrayBuffer> }> {
	if (method.type === 'pin') return openWithPin(accountId, method.pin)
	if (method.type === 'unlockKey') return openWithUnlockKey(account, method.unlockKey)
	return openWithMasterPassword(account, method.masterPassword)
}

/** The raw key a passkey released opens the current suite; the server offers passkeys of the current epoch only. */
async function openWithUnlockKey(account: AccountRecord, unlockKey: string): Promise<{ suite: CachedSuite; key: string }> {
	const raw = fromBase64(unlockKey)
	try {
		const key = await crypto.subtle.importKey('raw', raw, 'AES-GCM', false, ['decrypt'])
		const suite = (await cachedSuite(account.id)) ?? (await fetchSuite(account)).suite
		return { suite, key: await decryptPrivateKeyPemWith(suite.privateKey, key) }
	} catch (error) {
		if (error instanceof Failure && error.code !== 'invalid_master_password') throw error
		throw new Failure('passkey_failed')
	} finally {
		raw.fill(0)
	}
}

/** Imported once here so a corrupt key fails the unlock, not the first decrypt. */
async function importedKey(key: string | Uint8Array<ArrayBuffer>): Promise<Omit<OpenedKey, 'suite'>> {
	try {
		const pkcs8 = typeof key === 'string' ? pemToPkcs8(key) : key
		return { pkcs8, privateKey: await importPrivateKey(pkcs8) }
	} catch {
		throw new Failure('key_unreadable')
	}
}

async function storeKey(accountId: string, { pkcs8, privateKey, suite }: OpenedKey): Promise<void> {
	// Cleared before the key is stored, so a suite change landing after this keeps its notice.
	const record = await getAccount(accountId)
	if (record?.keyChanged || record?.fileAccessNote) await updateAccount(accountId, { keyChanged: false, fileAccessNote: false })
	await putKey(accountId, toBase64(pkcs8))
	await proveCertificate(accountId, suite.certificate, privateKey)
}

/** A logout during the unlock marked the account before purging; take back what this unlock wrote. */
async function refuseAfterLogout(accountId: string): Promise<void> {
	const after = await getAccount(accountId)
	if (typeof after?.appPassword === 'string') return
	await clearKey(accountId, { sealed: 'drop' })
	await browser.storage.local.remove([suiteKey(accountId), provenCertificateKey(accountId)])
	throw new Failure('session_revoked')
}

async function retryAfterSuiteChange(run: UnlockRun, current: CachedSuite | undefined): Promise<void> {
	// No one used this key, so it does not take what was sealed before this unlock.
	await clearKey(run.accountId, { sealed: 'keep' })
	if (current) await updateAccount(run.accountId, { keyChanged: true })
	else await browser.storage.local.remove(provenCertificateKey(run.accountId))
	// Either change also forgot the PIN, so only the master password can retry.
	if (run.method.type === 'pin') throw new Failure(current ? 'pin_key_changed' : 'unlock_blocked')
	if (run.retried) throw new Failure('unknown')
	return attempt(run.accountId, run.method, true)
}

/** Not awaited, so the 600 000-iteration PBKDF2 of a rebuild does not hold up the unlock reply. */
function rebuildPinLater({ accountId, method }: UnlockRun, { pkcs8, suite }: OpenedKey): void {
	const generation = pinGeneration(accountId)
	if (method.type === 'masterPassword') {
		void afterMasterPassword(accountId, pkcs8, suite, generation).catch((error: unknown) => console.error('[keepiq] PIN rebuild failed', error))
	}
}

/** Setting a PIN proves the master password, so whoever finds the vault unlocked cannot set one to outlast the timeout (ADR-002). */
export async function enablePin(accountId: string, pin: string, masterPassword: string): Promise<PinSetReply> {
	// Read before the status, so a lock landing while it is read still counts.
	const epoch = sessionEpoch(accountId)
	const generation = pinGeneration(accountId)
	const refusal = await pinRefusal(accountId, pin)
	if (refusal) return refusal
	const suite = await cachedSuite(accountId)
	if (!suite) return { ok: false, code: 'locked' }
	const pem = await masterPem(suite, masterPassword)
	if (pem === null) return { ok: false, code: 'invalid_master_password' }
	return storeNewPin({ accountId, pin, epoch, generation, suite }, pem)
}

/** A new PIN for an unlocked account: the session it was set in, the PIN generation then, and the suite it wraps a key of. */
interface NewPin {
	accountId: string
	pin: string
	epoch: number
	generation: number
	suite: CachedSuite
}

async function pinRefusal(accountId: string, pin: string): Promise<PinSetReply | null> {
	const account = await getAccount(accountId)
	if (!account || await accountStatus(account) !== 'unlocked') return { ok: false, code: 'locked' }
	if (typeof pin !== 'string' || [...pin].length < PIN_MIN_LENGTH) return { ok: false, code: 'pin_too_short' }
	return null
}

/** The key as PEM, or `null` for a wrong master password. */
async function masterPem(suite: CachedSuite, masterPassword: string): Promise<string | null> {
	try {
		return await decrypt(suite, masterPassword)
	} catch (error) {
		if (error instanceof Failure && error.code === 'invalid_master_password') return null
		throw error
	}
}

async function storeNewPin({ accountId, pin, epoch, generation, suite }: NewPin, pem: string): Promise<PinSetReply> {
	const pkcs8 = pemToPkcs8(pem)
	const [record, seal] = await Promise.all([wrapPin(pin, pkcs8, suite), sealPin(accountId, pin, pkcs8, suite)])
	// A lock or logout meanwhile ends what the user unlocked, so the PIN must not outlast it.
	const stored = await pinQueue(accountId, async () => sessionEpoch(accountId) === epoch && storePinCopies(accountId, record, seal, generation))
	return stored ? { ok: true } : { ok: false, code: 'locked' }
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
		// A new epoch can be the same key pair; values sealed to another key are dropped when they fail to open.
		await clearKey(accountId, { sealed: 'keep' })
		await forgetPin(accountId)
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
	await clearKey(accountId, { sealed: 'drop' })
	await forgetPin(accountId)
	await clearSnapshot(accountId)
	await browser.storage.local.remove([suiteKey(accountId), provenCertificateKey(accountId)])
	await announceLock((await getActiveAccountId()) === accountId)
}
