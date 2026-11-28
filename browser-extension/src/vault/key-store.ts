/**
 * Where an unlocked private key lives (ADR-002): PKCS#8 bytes in `storage.session`,
 * or in this page's memory where `storage.session` is missing (Firefox < 115, whose
 * MV2 background page is persistent). Only the "Never" timeout also writes them to
 * `storage.local`.
 */
import { importPrivateKey } from '@/src/crypto'
import { fromBase64 } from '@/src/crypto/base64'
import { effectiveSettings } from '@/src/accounts/settings'
import { createKeyedQueue } from '@/src/queue'
import { forgetVerifiedPartners } from './verified-partners'

const sessionKey = (accountId: string) => `privateKeyPkcs8.${accountId}`
export const unlockedAtKey = (accountId: string) => `unlockedAt.${accountId}`
export const neverLockKey = (accountId: string) => `neverLockKey.${accountId}`
export const LAST_TAB_KEY = 'popup:lastTab'
/** The ciphertext and source version open edit forms hold (`write.ts`), cleared with the key. */
export const editOpenedKey = (accountId: string) => `editOpened.${accountId}`
/** Generated values in plaintext (`generator/history.ts`): session-only and gone with the key (ADR-002). */
export const generatorHistoryKey = (accountId: string) => `generatorHistory.${accountId}`
/** Values generated while locked, each encrypted to the certificate the last unlock proved, until the next unlock opens them. */
export const generatorSealedKey = (accountId: string) => `generatorSealed.${accountId}`

const memory = new Map<string, string>()
/** Imported once per worker generation; the bytes are the source of truth. */
const imported = new Map<string, Promise<CryptoKey>>()

/** Either `storage.session` or a Map behind the same async shape. */
const session = {
	async get(key: string): Promise<string | undefined> {
		if (!browser.storage.session) return memory.get(key)
		return (await browser.storage.session.get(key))[key] as string | undefined
	},
	async set(values: Record<string, string>): Promise<void> {
		if (!browser.storage.session) {
			for (const [key, value] of Object.entries(values)) memory.set(key, value)
			return
		}
		await browser.storage.session.set(values)
	},
	async remove(keys: string[]): Promise<void> {
		if (!browser.storage.session) {
			for (const key of keys) memory.delete(key)
			return
		}
		await browser.storage.session.remove(keys)
	},
}

export async function putKey(accountId: string, pkcs8Base64: string): Promise<void> {
	await session.set({ [sessionKey(accountId)]: pkcs8Base64, [unlockedAtKey(accountId)]: String(Date.now()) })
	imported.delete(accountId)
	await syncNeverLockKey(accountId)
}

/** The PKCS#8 bytes, restoring them from `storage.local` after a restart under "Never". */
async function getBytes(accountId: string): Promise<string | undefined> {
	const epoch = sessionEpoch(accountId)
	const bytes = await session.get(sessionKey(accountId))
	if (bytes !== undefined) return bytes
	if ((await effectiveSettings(accountId)).vaultTimeout !== 'never') return undefined
	const stored = (await browser.storage.local.get(neverLockKey(accountId)))[neverLockKey(accountId)] as string | undefined
	// A lock since the read removed both copies; restoring would undo it.
	if (stored === undefined || sessionEpoch(accountId) !== epoch) return undefined
	await session.set({ [sessionKey(accountId)]: stored, [unlockedAtKey(accountId)]: String(Date.now()) })
	return stored
}

export async function hasKey(accountId: string): Promise<boolean> {
	return (await getBytes(accountId)) !== undefined
}

export async function getKey(accountId: string): Promise<CryptoKey | null> {
	const bytes = await getBytes(accountId)
	if (bytes === undefined) {
		imported.delete(accountId)
		return null
	}
	let key = imported.get(accountId)
	if (!key) {
		key = importPrivateKey(fromBase64(bytes))
		imported.set(accountId, key)
	}
	return key
}

const epochs = new Map<string, number>()

/** Changes on every `clearKey`, so a session write that started before one can tell it must not land. */
export function sessionEpoch(accountId: string): number {
	return epochs.get(accountId) ?? 0
}

/**
 * Every lock and logout goes through here. The sealed generator values go with a held key; without
 * one, as on Lock all while locked, they stay for the coming unlock. `sealed` overrides that.
 */
export async function clearKey(accountId: string, { sealed }: { sealed?: 'drop' | 'keep' } = {}): Promise<void> {
	epochs.set(accountId, sessionEpoch(accountId) + 1)
	imported.delete(accountId)
	forgetVerifiedPartners()
	// Issued in the bump's step, no await between: a request that sees the new epoch finds no key in session or on disk, and a history write from before it is removed.
	const [held] = await Promise.all([
		Promise.all([session.get(sessionKey(accountId)), browser.storage.local.get(neverLockKey(accountId))])
			.then(([bytes, onDisk]) => bytes !== undefined || onDisk[neverLockKey(accountId)] !== undefined),
		session.remove([sessionKey(accountId), unlockedAtKey(accountId), editOpenedKey(accountId), generatorHistoryKey(accountId)]),
		browser.storage.local.remove(neverLockKey(accountId)),
	])
	if ((sealed ?? (held ? 'drop' : 'keep')) === 'drop') await session.remove([generatorSealedKey(accountId)])
}

const neverLockQueue = createKeyedQueue()

/** Writes or deletes the on-disk copy to match the account's timeout, one at a time per account. */
export function syncNeverLockKey(accountId: string): Promise<void> {
	return neverLockQueue(accountId, async () => {
		const epoch = sessionEpoch(accountId)
		const bytes = await session.get(sessionKey(accountId))
		const never = bytes !== undefined && (await effectiveSettings(accountId)).vaultTimeout === 'never'
		// A lock meanwhile already took the copy off disk; writing these bytes would put it back.
		if (never && sessionEpoch(accountId) === epoch) await browser.storage.local.set({ [neverLockKey(accountId)]: bytes })
		else await browser.storage.local.remove(neverLockKey(accountId))
	})
}

/** Session values that are not keys, through the same backend. */
export const sessionValues = session
