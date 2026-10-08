import type { BlockReason, ItemMeta } from '@/src/messages'
import type { StoredSecretRow, VaultSnapshot } from './types'

export const vaultCacheKey = (accountId: string) => `vaultCache.${accountId}`
const checkedKey = (accountId: string) => `vaultCheckedAt.${accountId}`

/**
 * Every decrypt batch and list read needs the snapshot, so the background keeps it in
 * memory. Only this module writes the key, so the copy cannot go stale; a worker restart
 * starts empty and reads storage again. Callers must not mutate what they get.
 */
const loaded = new Map<string, Promise<VaultSnapshot | undefined>>()
/** Bumped by every clear, so a write that started before it does not refill the cache. */
const generation = new Map<string, number>()

export function readSnapshot(accountId: string): Promise<VaultSnapshot | undefined> {
	let snapshot = loaded.get(accountId)
	if (!snapshot) {
		const key = vaultCacheKey(accountId)
		snapshot = browser.storage.local.get(key).then((values) => values[key] as VaultSnapshot | undefined)
		loaded.set(accountId, snapshot)
		snapshot.catch(() => loaded.delete(accountId))
	}
	return snapshot
}

/** One `set`, so a reader sees the old vault or the new one, never a mix. */
export async function writeSnapshot(accountId: string, snapshot: VaultSnapshot): Promise<void> {
	const before = generation.get(accountId)
	loaded.delete(accountId)
	await browser.storage.local.set({ [vaultCacheKey(accountId)]: snapshot, [checkedKey(accountId)]: snapshot.syncedAt })
	if (generation.get(accountId) === before) loaded.set(accountId, Promise.resolve(snapshot))
}

export async function clearSnapshot(accountId: string): Promise<void> {
	generation.set(accountId, (generation.get(accountId) ?? 0) + 1)
	loaded.delete(accountId)
	await browser.storage.local.remove([vaultCacheKey(accountId), checkedKey(accountId)])
}

/** A probe that found nothing new confirms the snapshot without rewriting it. */
export async function markChecked(accountId: string, at: string): Promise<void> {
	await browser.storage.local.set({ [checkedKey(accountId)]: at })
}

/** When the server last confirmed the snapshot. */
export async function checkedAt(accountId: string): Promise<string | null> {
	const key = checkedKey(accountId)
	return ((await browser.storage.local.get(key))[key] as string | undefined) ?? null
}

export function toItemMeta(row: StoredSecretRow): ItemMeta {
	const meta: ItemMeta = {
		id: row.id,
		name: row.name,
		url: row.url,
		typeId: row.typeId,
		folderId: row.folderId,
		hasLogin: !row.blocked && row.login !== null && row.login !== undefined,
		blocked: row.blocked === true,
		createdAt: row.createdAt,
		updatedAt: row.updatedAt,
		expiresAt: row.expiresAt,
	}
	if (row.blocked) {
		// A snapshot written by an older version holds English text here until the next sync.
		if (row.blockedReason && BLOCK_REASONS.includes(row.blockedReason)) meta.blockedReason = row.blockedReason
		meta.migrationError = row.migrationError ?? null
	}
	return meta
}

const BLOCK_REASONS: readonly string[] = ['suite_missing', 'suite_revoked', 'suite_compromised', 'migration_failed'] satisfies BlockReason[]
