/**
 * Every message envelope that crosses a runtime boundary, in one place. Payloads
 * arrive as `unknown` (WXT-AND-BROWSERS.md § 3), so each listener casts to one of
 * these unions once, at its boundary.
 */

/** Content script → background. Fire-and-forget. */
export type ContentToBackground =
	| { kind: 'page_ready'; url: string }

export type AccountStatus = 'unlocked' | 'locked' | 'logged_out'

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
	/** One-shot banner, e.g. "Session revoked, please log in again". */
	notice: string | null
	canAddAccount: boolean
	/** The tab to open on, so the first frame already shows it. */
	lastTab: PopupTab
}

export type ErrorCode =
	| 'insecure_url' | 'invalid_url' | 'permission_denied' | 'unreachable' | 'not_nextcloud'
	| 'unauthorized' | 'keepiq_missing' | 'no_active_suite' | 'unlock_blocked' | 'duplicate'
	| 'limit_reached' | 'invalid_master_password' | 'offline_no_cache' | 'session_revoked'
	| 'write_locked' | 'server_error' | 'unknown'

export type Result = { ok: true; state: PopupState } | { ok: false; code: ErrorCode; message: string }

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
	blockedReason?: string
	migrationError?: string | null
	createdAt: string
	updatedAt: string
	expiresAt: string | null
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
	}

export type DecryptField = 'login' | 'key' | 'additionalFields'
export type DecryptedItem = Partial<Record<DecryptField, string>>

/** A batch leaves out rows that are blocked or do not decrypt; a single id gets the reason instead. */
export type DecryptReply =
	| { ok: true; items: Record<string, DecryptedItem> }
	| { ok: false; error: 'locked' | 'blocked' | 'decrypt_failed' }

/** Popup → background requests with their own reply shapes, listed in `PopupReplies`. */
export type PopupRequest =
	| { kind: 'vault.sync' }
	/** `opened` marks the popup's first read: the one that may start a stale sync and counts as interaction. */
	| { kind: 'vault.snapshot'; tabUrl?: string; opened?: boolean }
	| { kind: 'item.decrypt'; ids: string[]; fields: DecryptField[]; passive?: boolean }
	| { kind: 'clipboard.copied' }
	| { kind: 'popup.popout'; tabId?: number }
	| { kind: 'popup.lastTab.set'; tab: PopupTab }

export interface PopupReplies {
	'vault.sync': SyncStatus
	'vault.snapshot': VaultSnapshotReply
	'item.decrypt': DecryptReply
	'clipboard.copied': null
	'popup.popout': null
	'popup.lastTab.set': null
}

export const POPUP_REQUEST_KINDS: ReadonlySet<string> = new Set<keyof PopupReplies>([
	'vault.sync', 'vault.snapshot', 'item.decrypt', 'clipboard.copied', 'popup.popout', 'popup.lastTab.set',
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
