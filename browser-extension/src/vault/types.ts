import type { BlockedSecretRow, FolderRow, OpenSecretRow, SecretTypeRow } from '@/src/api/types'
import type { BlockReason } from '@/src/messages'

/** The server's reason is English text, so a stored blocked row holds a code instead. */
export type StoredBlockedRow = Omit<BlockedSecretRow, 'blockedReason'> & { blockedReason?: BlockReason; migrationError?: string | null }

export type StoredSecretRow = OpenSecretRow | StoredBlockedRow

/** Everything one account's vault needs offline, written in one `storage.local.set`. */
export interface VaultSnapshot {
	suite: { id: string; unlockKeyEpoch: number }
	secrets: StoredSecretRow[]
	folders: FolderRow[]
	types: SecretTypeRow[]
	/** When the rows were last fetched in full. */
	syncedAt: string
	/** When folders, types and the suite were last fetched. */
	listsSyncedAt: string
	/** What the change probe compares against. */
	newestUpdatedAt: string | null
	total: number
}

export type { ItemMeta, SyncStatus } from '@/src/messages'
