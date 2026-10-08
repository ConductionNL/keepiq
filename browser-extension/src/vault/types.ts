import type { FolderRow, SecretRow, SecretTypeRow } from '@/src/api/types'

/** A blocked row as the fallback list returns it; the manifest never does. */
export type StoredSecretRow = SecretRow & { migrationError?: string | null }

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
