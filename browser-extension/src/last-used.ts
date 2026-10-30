/** When each item was last filled, per account, for ordering and the shortcut. Local only, it never roams. */
import { createKeyedQueue } from '@/src/queue'
import { hasKey } from '@/src/vault/key-store'
import { readSnapshot } from '@/src/vault/store'

/** Item id to epoch ms. */
export type LastUsed = Record<string, number>

export const lastUsedKey = (accountId: string) => `lastUsed.${accountId}`

const queued = createKeyedQueue()

export async function readLastUsed(accountId: string): Promise<LastUsed> {
	const stored: unknown = (await browser.storage.local.get(lastUsedKey(accountId)))[lastUsedKey(accountId)]
	if (typeof stored !== 'object' || stored === null || Array.isArray(stored)) return {}
	return Object.fromEntries(Object.entries(stored).filter(([, at]) => typeof at === 'number' && Number.isFinite(at) && at >= 0))
}

/**
 * Only while the account is unlocked. A logout clears the key before `forgetLastUsed`, which waits
 * on the same queue, so a fill finishing during the logout is forgotten with the rest.
 * Items the cached vault no longer holds are dropped.
 */
export function recordLastUsed(accountId: string, itemId: string, now = Date.now()): Promise<void> {
	return queued(accountId, async () => {
		if (!(await hasKey(accountId))) return
		const snapshot = await readSnapshot(accountId)
		const ids = snapshot && new Set(snapshot.secrets.map((row) => row.id))
		const kept = Object.entries(await readLastUsed(accountId)).filter(([id]) => !ids || ids.has(id))
		await browser.storage.local.set({ [lastUsedKey(accountId)]: { ...Object.fromEntries(kept), [itemId]: now } })
	})
}

/** Call after the key is cleared, on logout and account removal. */
export function forgetLastUsed(accountId: string): Promise<void> {
	return queued(accountId, () => browser.storage.local.remove(lastUsedKey(accountId)))
}
