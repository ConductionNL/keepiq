import { createContext, useContext } from 'react'

export interface ShellContextValue {
	/** Re-reads the popup state, e.g. after the background reported the vault locked. */
	refreshState: () => void
	toast: (message: string) => void
	/** Opens a launchable url in a new tab. */
	launch: (url: string) => void
	/** Where a page opened over a tab puts its actions; `null` while a tab shows. */
	footer: HTMLElement | null
}

export const ShellContext = createContext<ShellContextValue>({ refreshState: () => {}, toast: () => {}, launch: () => {}, footer: null })

export const useShell = () => useContext(ShellContext)
