/** Response shapes from ADR-003. */

export interface SuiteRow {
	id: string
	status: 'active' | 'revoked' | string
	certificate: string
	/** Absent when the server's two-factor policy withholds it. */
	privateKey?: string
	unlockBlocked?: string
	unlockKeyEpoch: number
}

/** What the extension caches of a suite under `suite.<accountId>`. */
export type CachedSuite = Pick<SuiteRow, 'id' | 'status' | 'certificate' | 'unlockKeyEpoch'> & { privateKey: string }

interface SecretBase {
	id: string
	name: string
	url: string | null
	typeId: string | null
	folderId: string | null
	encryptionSuiteId: string
	ownerType: string
	ownerId: string
	createdAt: string
	updatedAt: string
	keyUpdatedAt: string | null
	expiresAt: string | null
	possiblyCompromisedAt: string | null
	tombstonedAt: string | null
	tombstoneReason: string | null
}

export interface OpenSecretRow extends SecretBase {
	blocked: false
	key: string
	login: string | null
	additionalFields: string | null
}

export interface BlockedSecretRow extends SecretBase {
	blocked: true
	blockedReason: string
}

export type SecretRow = OpenSecretRow | BlockedSecretRow

export interface FolderRow {
	id: string
	name: string
	parentId: string | null
	ownerType: string
	ownerId: string
	customIcon: string | null
	customColor: string | null
	createdAt: string
	updatedAt: string
}

export interface SecretTypeRow {
	id: string
	name: string
	[field: string]: unknown
}

export interface Page<T> {
	items: T[]
	total: number
	page: number
	limit: number
}

export interface OfflineManifest {
	suite: SuiteRow
	secrets: OpenSecretRow[]
	folders: FolderRow[]
	types: SecretTypeRow[]
	syncedAt: string
}

export interface UserSettings {
	session_timeout: 'session' | '10min' | '30min' | ''
	default_secret_type: string
	offline_cache_optin: boolean
	[toggle: string]: unknown
}

/** `ocs.data` of `GET /ocs/v2.php/cloud/user`. */
export interface NextcloudUser {
	id: string
	displayname: string | null
	email: string | null
}
