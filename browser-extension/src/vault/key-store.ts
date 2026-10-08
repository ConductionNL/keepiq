/**
 * Where an unlocked private key lives (ADR-002): PKCS#8 bytes in `storage.session`,
 * or in this page's memory where `storage.session` is missing (Firefox < 115, whose
 * MV2 background page is persistent). Only the "Never" timeout also writes them to
 * `storage.local`.
 */
import { importPrivateKey } from '@/src/crypto'
import { fromBase64 } from '@/src/crypto/base64'
import { readSettings } from '@/src/accounts/settings'

const sessionKey = (accountId: string) => `privateKeyPkcs8.${accountId}`
export const unlockedAtKey = (accountId: string) => `unlockedAt.${accountId}`
export const neverLockKey = (accountId: string) => `neverLockKey.${accountId}`

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
	const bytes = await session.get(sessionKey(accountId))
	if (bytes !== undefined) return bytes
	if ((await readSettings(accountId)).vaultTimeout !== 'never') return undefined
	const stored = (await browser.storage.local.get(neverLockKey(accountId)))[neverLockKey(accountId)] as string | undefined
	if (stored !== undefined) await session.set({ [sessionKey(accountId)]: stored, [unlockedAtKey(accountId)]: String(Date.now()) })
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

export async function clearKey(accountId: string): Promise<void> {
	imported.delete(accountId)
	await session.remove([sessionKey(accountId), unlockedAtKey(accountId)])
	await browser.storage.local.remove(neverLockKey(accountId))
}

/** Writes or deletes the on-disk copy to match the account's timeout. */
export async function syncNeverLockKey(accountId: string): Promise<void> {
	const bytes = await session.get(sessionKey(accountId))
	if (bytes !== undefined && (await readSettings(accountId)).vaultTimeout === 'never') {
		await browser.storage.local.set({ [neverLockKey(accountId)]: bytes })
	} else {
		await browser.storage.local.remove(neverLockKey(accountId))
	}
}

/** Session values that are not keys, through the same backend. */
export const sessionValues = session
