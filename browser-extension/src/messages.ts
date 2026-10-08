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
	origin: string
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
}

export type ErrorCode =
	| 'insecure_url' | 'invalid_url' | 'permission_denied' | 'unreachable' | 'not_nextcloud'
	| 'unauthorized' | 'keepiq_missing' | 'no_active_suite' | 'unlock_blocked' | 'duplicate'
	| 'limit_reached' | 'invalid_master_password' | 'offline_no_cache' | 'session_revoked'
	| 'write_locked' | 'unknown'

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
	| { kind: 'vault.status' }

/** Name of the port the popup holds open; its disconnect means the popup closed. */
export const POPUP_PORT = 'popup'
