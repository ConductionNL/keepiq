import { createContext, useContext } from 'react'
import type { ItemMeta } from '@/src/messages'

/** Shell runs these, so the list's More menu and item detail open the same form and dialogs. */
export interface ItemActions {
	add: () => void
	edit: (id: string) => void
	clone: (id: string) => void
	move: (item: ItemMeta) => void
	remove: (item: ItemMeta) => void
	/** Opens New send with the login's username and password filled in. */
	send: (item: ItemMeta) => void
}

export interface ShellContextValue {
	/** The account this popup shows, which every write names. */
	accountId: string
	/** Re-reads the popup state, e.g. after the background reported the vault locked. */
	refreshState: () => void
	toast: (message: string) => void
	/** Opens a launchable url in a new tab. */
	launch: (url: string) => void
	/** Where a page opened over a tab puts its actions; `null` while a tab shows. */
	footer: HTMLElement | null
	/** The last request found the server unreachable, so writes wait. */
	offline: boolean
	syncNow: () => void
	items: ItemActions
	/** An open form's dirty check; Shell asks before Back, Pop out or the account switcher drops a dirty form. */
	setLeaveGuard: (isDirty: (() => boolean) | null) => void
	/** Opens the generator over the form in pick mode; "Use" hands the value to `apply`, and focus returns to `opener`. */
	generate: (target: GenerateTarget, apply: (value: string) => void, opener: HTMLElement | null) => void
}

export type GenerateTarget = 'password' | 'username'

const noop = () => {}

export const DEFAULT_SHELL: ShellContextValue = {
	accountId: 'a1', refreshState: noop, toast: noop, launch: noop, footer: null, offline: false, syncNow: noop,
	items: { add: noop, edit: noop, clone: noop, move: noop, remove: noop, send: noop }, setLeaveGuard: noop, generate: noop,
}

export const ShellContext = createContext<ShellContextValue>(DEFAULT_SHELL)

export const useShell = () => useContext(ShellContext)
