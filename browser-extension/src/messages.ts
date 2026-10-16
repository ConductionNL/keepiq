/**
 * Every message envelope that crosses a runtime boundary, in one place. Payloads
 * arrive as `unknown` (WXT-AND-BROWSERS.md § 3), so each listener casts to one of
 * these unions once, at its boundary.
 */

/** Content script → background. Fire-and-forget. */
export type ContentToBackground =
	| { kind: 'page_ready'; url: string }

export type AccountStatus = 'unlocked' | 'locked' | 'logged_out'

/** Here rather than in the account store, so the popup can name it without importing storage code. */
export const MAX_ACCOUNTS = 5

export interface AccountSummary {
	id: string
	serverUrl: string
	host: string
	uid: string
	displayName: string
	avatarDataUrl: string | null
	status: AccountStatus
	active: boolean
}

export type PopupScreen = 'add_account' | 'reauthenticate' | 'unlock' | 'unlocked'

export interface PopupState {
	screen: PopupScreen
	accounts: AccountSummary[]
	active: AccountSummary | null
	/** One-shot banner. */
	notice: NoticeCode | null
	canAddAccount: boolean
	/** The tab to open on, so the first frame already shows it. */
	lastTab: PopupTab
}

export type ErrorCode =
	| 'insecure_url' | 'invalid_url' | 'permission_denied' | 'unreachable' | 'not_nextcloud'
	| 'unauthorized' | 'keepiq_missing' | 'no_active_suite' | 'unlock_blocked' | 'duplicate'
	| 'limit_reached' | 'invalid_master_password' | 'offline_no_cache' | 'session_revoked'
	| 'write_locked' | 'server_error' | 'not_responding' | 'unknown'

// Codes, never text: the popup words them in the browser's language (ext-i18n).
export type NoticeCode = 'session_revoked' | 'key_changed'

export type BlockReason = 'suite_missing' | 'suite_revoked' | 'suite_compromised' | 'migration_failed'

export type Result = { ok: true; state: PopupState } | { ok: false; code: ErrorCode }

export type UnlockMethod = { type: 'masterPassword'; masterPassword: string }

/** Popup → background. Request/response, every arm resolves to `Result`. */
export type PopupToBackground =
	| { kind: 'accounts.list' }
	| { kind: 'accounts.add'; serverUrl: string; username: string; appPassword: string }
	| { kind: 'accounts.reauthenticate'; accountId: string; appPassword: string }
	| { kind: 'accounts.remove'; accountId: string }
	| { kind: 'accounts.removeAll' }
	| { kind: 'accounts.switch'; accountId: string }
	| { kind: 'vault.unlock'; accountId: string; method: UnlockMethod }
	| { kind: 'vault.lock'; accountId: string }
	| { kind: 'vault.lockAll' }
	/** `passive`: the popup reacting to the background, which must not count as interaction. */
	| { kind: 'vault.status'; passive?: boolean }

export type PopupTab = 'vault' | 'generator' | 'send' | 'settings'

export const THEMES = ['system', 'light', 'dark'] as const
export type Theme = typeof THEMES[number]

/** Settings that apply before an account is chosen, so they are not per account. */
export interface GlobalSettings {
	theme: Theme
}

export interface SyncStatus {
	syncing: boolean
	/** Last time the server confirmed the snapshot, `null` before the first sync. */
	syncedAt: string | null
	offline: boolean
	lastError: 'network' | 'server' | 'busy' | null
}

/** The plaintext projection of a secret row; ciphertext never reaches the popup. */
export interface ItemMeta {
	id: string
	name: string
	url: string | null
	typeId: string | null
	folderId: string | null
	hasLogin: boolean
	blocked: boolean
	/** Absent when the stored reason is not a known code. */
	blockedReason?: BlockReason
	migrationError?: string | null
	createdAt: string
	updatedAt: string
	expiresAt: string | null
	/** A shared copy that is only filled: of its values, only the login decrypts for the popup. */
	useOnly: boolean
	/** A copy from another organisation, which may only be moved. */
	readOnly: boolean
	/** When access to this shared copy ends. */
	accessExpiresAt: string | null
}

export interface FolderMeta {
	id: string
	name: string
	parentId: string | null
}

export interface TypeMeta {
	id: string
	name: string
	label: string
}

export type VaultSnapshotReply =
	| { state: 'logged_out' }
	| { state: 'locked' }
	| {
		state: 'unlocked'
		/** `null` until the account's first sync stores a snapshot. */
		items: ItemMeta[] | null
		folders: FolderMeta[]
		types: TypeMeta[]
		suggestionIds: string[]
		sync: SyncStatus
		/** The account's Keepiq web app, for "open the web app" links. */
		webAppUrl: string
		/** The user's `default_secret_type` slug; `null` means `login`. */
		defaultSecretType: string | null
		folderNoticeDismissed: boolean
	}

export type DecryptField = 'login' | 'key' | 'additionalFields'
export type DecryptedItem = Partial<Record<DecryptField, string>>

/** All a use-only copy's holder may see; the background refuses the rest (use-only-shares). */
export const USE_ONLY_FIELDS: readonly DecryptField[] = ['login']

/** A batch leaves out rows that are blocked, use-only or do not decrypt; a single id gets the reason instead. */
export type DecryptReply =
	| { ok: true; items: Record<string, DecryptedItem> }
	| { ok: false; error: 'locked' | 'blocked' | 'use_only' | 'decrypt_failed' }

/** Plaintext draft. The background encrypts key, login and additionalFields; the popup never does. */
export interface ItemDraft {
	typeId: string
	name: string
	url: string | null
	folderId: string | null
	key: string
	login: string | null
	/** Name to value, `notes` included; `{}` when empty. */
	additionalFields: Record<string, string>
}

/** The fields the user changed. `null` clears; `additionalFields` is always whole. */
export type ItemPatch = Partial<ItemDraft>

/** A fresh fetch, decrypted for the edit form. */
export interface EditableItem extends ItemDraft {
	id: string
	updatedAt: string
	/**
	 * The type name the fetch vouched for the fields under; the edit form renders by it, not the popup's snapshot. Absent for a type the vault does not know.
	 * `passkey` when either the server or the cache says so, and `key` is then empty: a passkey's credential is never decrypted for editing.
	 */
	typeName?: string
	/** An edit fetch's record of what its form showed, for that form's save to name; absent for a clone. */
	recordId?: string
}

export interface FolderChildren {
	directSecretCount: number
	subfolders: Array<{ id: string; name: string; secretCount: number; subfolderCount: number }>
}

export type SubfolderAction = 'delete' | 'move' | 'keep'

export type FolderDeletePlan =
	| { mode: 'plain' }
	| { mode: 'cascade'; cascade: 'delete' | 'move' }
	| { mode: 'resolution'; directSecrets: 'delete' | 'move'; subfolders: Record<string, SubfolderAction> }

/** `conflict` is a duplicate folder name, a folder that is not empty, an item changed since `baseUpdatedAt`, or a fetched item whose ciphertext the cache does not hold. */
export type WriteErrorCode =
	| 'locked' | 'session_revoked' | 'write_locked' | 'forbidden' | 'policy' | 'invalid' | 'conflict'
	| 'not_found' | 'blocked' | 'unreadable' | 'offline' | 'server_error' | 'unknown'
	/** A use-only, read-only or (for a clone) expiring shared copy. */
	| 'restricted'
	/** The suite's certificate does not match the unlocked key, so nothing is encrypted to it. */
	| 'key_mismatch'
	/** The write was meant for an account that is no longer the active one. */
	| 'account_changed'
	/** A shared item's source was written after its copy's edit form opened. */
	| 'stale_source'
	/** The share list kept changing over three share-save answers. */
	| 'incomplete'
	/** A recipient's certificate did not import. */
	| 'bad_certificate'
	/** Partner shares get the whole value, and the edit form did not show every field of it in full. */
	| 'unverified'
	/** `partners` names the partner shares whose instance did not answer. */
	| 'partner_unreachable'
	/** The partner lookup's limit of 60 per minute. */
	| 'partner_lookup_limit'
	/** The server runs a Nextcloud without the OCM endpoint event partner sharing needs (below 33). */
	| 'federation_unavailable'

export interface VaultWriteError {
	code: WriteErrorCode
	/** With `blocked`, when the row's reason is a known code. */
	reason?: BlockReason
	/** With `partner_unreachable`. */
	partners?: Array<{ shareId: string; cloudId: string }>
}

export type WriteResult<T> = { ok: true; value: T } | { ok: false; error: VaultWriteError }

/** Shown on the item's detail after a save; absent when the save reached everyone. */
export interface ShareSaveNote {
	/** `source`: the editor owns the item; `copy`: a team editor or delegate; `own_copy`: only the editor's copy changed. */
	scope: 'source' | 'copy' | 'own_copy'
	/** Copy-editor path: partner shares exist that only the owner's next save reaches. */
	ownerOnly?: true
	/** Partner shares this save suspended on a verdict about the recipient, by cloud id. */
	suspended?: string[]
	/** Partner shares the user suspended through Save without them, by cloud id. */
	paused?: string[]
}

/** Popup → background requests with their own reply shapes, listed in `PopupReplies`. */
export type PopupRequest =
	| { kind: 'vault.sync' }
	/** `opened` marks the popup's first read: the one that may start a stale sync and counts as interaction. */
	| { kind: 'vault.snapshot'; tabUrl?: string; opened?: boolean }
	| { kind: 'item.decrypt'; ids: string[]; fields: DecryptField[]; passive?: boolean }
	| { kind: 'clipboard.copied' }
	| { kind: 'popup.popout'; tabId?: number }
	| { kind: 'popup.lastTab.set'; tab: PopupTab }
	| { kind: 'settings.get' }
	| { kind: 'settings.set'; patch: Partial<GlobalSettings> }
	| VaultWrite

/**
 * Each names the account the popup showed, so a write never lands in another account's vault
 * after a switch in another window.
 */
export type VaultWrite = { accountId: string } & (
	/** `purpose`: a clone is refused for more restricted copies than an edit. */
	| { kind: 'item.fetch'; id: string; purpose: 'edit' | 'clone' }
	| { kind: 'item.create'; draft: ItemDraft }
	/**
	 * `baseUpdatedAt`: the fetched version, so a save over a newer one is refused. `recordId`: the fetch's, without which a save that needs it for partners fails with `unverified`.
	 * `withoutUnreachable`: partner share ids the user chose to suspend after a `partner_unreachable` failure.
	 */
	| { kind: 'item.update'; id: string; patch: ItemPatch; baseUpdatedAt?: string; recordId?: string; withoutUnreachable?: string[] }
	| { kind: 'item.delete'; id: string }
	| { kind: 'folder.create'; name: string; parentId: string | null }
	| { kind: 'folder.rename'; id: string; name: string }
	| { kind: 'folder.children'; id: string }
	| { kind: 'folder.delete'; id: string; plan: FolderDeletePlan }
	| { kind: 'folder.dismissNotice' }
)

export interface PopupReplies {
	'vault.sync': SyncStatus
	'vault.snapshot': VaultSnapshotReply
	'item.decrypt': DecryptReply
	'clipboard.copied': null
	'popup.popout': null
	'popup.lastTab.set': null
	'settings.get': GlobalSettings
	'settings.set': GlobalSettings
	'item.fetch': WriteResult<EditableItem>
	'item.create': WriteResult<{ id: string }>
	'item.update': WriteResult<{ id: string; shareSave?: ShareSaveNote }>
	'item.delete': WriteResult<null>
	'folder.create': WriteResult<{ id: string }>
	'folder.rename': WriteResult<null>
	'folder.children': WriteResult<FolderChildren>
	'folder.delete': WriteResult<null>
	'folder.dismissNotice': null
}

export const POPUP_REQUEST_KINDS: ReadonlySet<string> = new Set<keyof PopupReplies>([
	'vault.sync', 'vault.snapshot', 'item.decrypt', 'clipboard.copied', 'popup.popout', 'popup.lastTab.set',
	'settings.get', 'settings.set', 'item.fetch', 'item.create', 'item.update', 'item.delete',
	'folder.create', 'folder.rename', 'folder.children', 'folder.delete', 'folder.dismissNotice',
])

/** Background → popup broadcasts; nobody listens while the popup is closed. */
export type BackgroundToPopup =
	| { kind: 'vault.changed'; sync: SyncStatus }
	| { kind: 'vault.locked' }

/** Background → the Chrome offscreen document. */
export type BackgroundToOffscreen =
	| { kind: 'offscreen.clearClipboard' }

/** Name of the port the popup holds open; its disconnect means the popup closed. */
export const POPUP_PORT = 'popup'
